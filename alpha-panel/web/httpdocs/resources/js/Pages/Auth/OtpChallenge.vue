<template>
    <Head :title="t('Session Verification')" />
    <ThemeProvider>
        <FullScreenLayout>
            <div class="z-1 relative flex min-h-screen items-center justify-center bg-white p-6 dark:bg-gray-900 sm:p-0">
                <div class="mx-auto flex w-full max-w-md flex-1 flex-col">
                    <div class="mb-4 flex justify-end">
                        <select
                            :value="locale"
                            class="h-9 min-w-40 rounded-lg border border-gray-300 bg-white px-3 text-xs font-medium text-gray-700 shadow-theme-xs focus:border-brand-300 focus:outline-hidden focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200"
                            @change="onLocaleChange"
                        >
                            <option
                                v-for="lang in availableLocales"
                                :key="lang"
                                :value="lang"
                            >
                                {{ localeName(lang) }}
                            </option>
                        </select>
                    </div>

                    <div class="mb-6">
                        <div class="mb-4 flex flex-col items-center">
                            <img
                                v-if="props.gravatar_url"
                                :src="props.gravatar_url"
                                :alt="props.name"
                                class="h-30 w-30 rounded-full border border-gray-200 object-cover dark:border-gray-700"
                            />
                            <p class="mt-3 text-2xl font-medium text-gray-700 dark:text-gray-300">
                                {{ props.name }}
                            </p>
                        </div>
                        <h1 class="mt-3 mb-2 text-lg font-semibold text-gray-800 dark:text-white/90">
                            {{ t('Verify Session') }}
                        </h1>
                        <p class="text-sm text-gray-500 dark:text-gray-400">
                            {{ t('Complete verification to continue using your session.') }}
                        </p>
                    </div>

                    <div class="space-y-5">
                        <!-- CAPTCHA Widget (v2/Turnstile visible, v3 invisible) -->
                        <div v-if="props.captcha">
                            <div v-if="!isRecaptchaV3" ref="captchaWidgetRef" class="flex justify-center"></div>
                        </div>

                        <div
                            v-if="totp"
                            class="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-white/3"
                        >
                            <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">
                                {{ t('Authentication Code') }}
                            </label>
                            <input
                                v-model="code"
                                type="text"
                                inputmode="numeric"
                                maxlength="8"
                                :placeholder="t('Enter 6-digit code')"
                                class="h-11 w-full rounded-lg border border-gray-300 bg-transparent px-4 py-2.5 text-sm text-gray-800 shadow-theme-xs placeholder:text-gray-400 focus:border-brand-300 focus:outline-hidden focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90"
                                @keydown.enter="verifyCode"
                            />
                            <button
                                type="button"
                                @click="verifyCode"
                                :disabled="loading || code.length < 6"
                                class="mt-3 inline-flex w-full items-center justify-center rounded-lg bg-brand-500 px-4 py-2.5 text-sm font-medium text-white hover:bg-brand-600 disabled:opacity-50"
                            >
                                {{ loading ? t('Verifying...') : t('Verify Code') }}
                            </button>
                        </div>

                        <button
                            v-if="webauthn"
                            type="button"
                            @click="verifyDevice"
                            :disabled="loading"
                            class="inline-flex w-full items-center justify-center gap-2 rounded-lg border border-blue-light-300 px-4 py-2.5 text-sm font-medium text-blue-light-700 hover:bg-blue-light-50 disabled:opacity-50 dark:border-blue-light-800 dark:text-blue-light-300 dark:hover:bg-blue-light-500/10"
                        >
                            <i class="fa-solid fa-fingerprint text-base"></i>
                            {{ loading ? t('Waiting for device...') : t('Verify With Device') }}
                        </button>

                        <p v-if="errorMessage" class="text-sm text-error-500">
                            {{ errorMessage }}
                        </p>

                        <button
                            type="button"
                            @click="logout"
                            class="inline-flex w-full items-center justify-center rounded-lg border border-gray-300 px-4 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-gray-800"
                        >
                            {{ t('Sign Out') }}
                        </button>
                    </div>
                </div>
            </div>
        </FullScreenLayout>
    </ThemeProvider>
</template>

<script setup lang="ts">
import { computed, nextTick, onMounted, ref, watch } from 'vue';
import axios from 'axios';
import { Head, router, usePage } from '@inertiajs/vue3';
import ThemeProvider from '@/Components/Layout/ThemeProvider.vue';
import FullScreenLayout from '@/Components/Layout/FullScreenLayout.vue';
import { useI18n } from '@/Composables/useI18n';
import type { SharedProps } from '@/types/inertia';

declare global {
    interface Window {
        turnstile?: {
            render: (element: HTMLElement, options: Record<string, any>) => string;
            reset: (widgetId?: string) => void;
        };
        grecaptcha?: {
            render: (element: HTMLElement, options: Record<string, any>) => number;
            reset: (widgetId?: number) => void;
            ready: (callback: () => void) => void;
            execute: (siteKey: string, options: Record<string, any>) => Promise<string>;
        };
    }
}

interface CaptchaConfig {
    provider: 'turnstile' | 'recaptcha';
    site_key: string;
    recaptcha_version?: 'v2' | 'v3';
}

type PublicKeyCredentialRequestOptionsPayload = {
    [key: string]: any;
    challenge: string;
    allowCredentials?: Array<{
        [key: string]: any;
        id: string;
    }>;
};

const props = defineProps<{
    webauthn: boolean;
    totp: boolean;
    name: string;
    email: string;
    gravatar_url: string;
    captcha?: CaptchaConfig | null;
}>();

const code = ref('');
const loading = ref(false);
const errorMessage = ref('');
const captchaToken = ref<string>('');
const captchaWidgetRef = ref<HTMLDivElement | null>(null);
const captchaScriptLoaded = ref(false);

const isRecaptchaV3 = computed(() =>
    props.captcha?.provider === 'recaptcha' && props.captcha?.recaptcha_version === 'v3',
);

const loadCaptchaScript = (): void => {
    if (!props.captcha || captchaScriptLoaded.value) {
        return;
    }

    let src: string;

    if (props.captcha.provider === 'turnstile') {
        src = 'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit';
    } else if (isRecaptchaV3.value) {
        src = `https://www.google.com/recaptcha/api.js?render=${props.captcha.site_key}`;
    } else {
        src = 'https://www.google.com/recaptcha/api.js?render=explicit';
    }

    const script = document.createElement('script');
    script.src = src;
    script.async = true;
    script.defer = true;
    script.onload = () => {
        captchaScriptLoaded.value = true;
    };
    document.head.appendChild(script);
};

const renderCaptchaWidget = (): void => {
    if (!captchaWidgetRef.value || !props.captcha || isRecaptchaV3.value) {
        return;
    }

    captchaWidgetRef.value.innerHTML = '';

    if (props.captcha.provider === 'turnstile' && window.turnstile) {
        window.turnstile.render(captchaWidgetRef.value, {
            sitekey: props.captcha.site_key,
            callback: (token: string) => { captchaToken.value = token; },
            'expired-callback': () => { captchaToken.value = ''; },
        });
    } else if (props.captcha.provider === 'recaptcha' && window.grecaptcha) {
        window.grecaptcha.render(captchaWidgetRef.value, {
            sitekey: props.captcha.site_key,
            callback: (token: string) => { captchaToken.value = token; },
            'expired-callback': () => { captchaToken.value = ''; },
        });
    }
};

const executeRecaptchaV3 = async (): Promise<string> => {
    if (!window.grecaptcha || !props.captcha) {
        return '';
    }

    return new Promise((resolve) => {
        window.grecaptcha!.ready(() => {
            window.grecaptcha!.execute(props.captcha!.site_key, { action: 'login' })
                .then(resolve)
                .catch(() => resolve(''));
        });
    });
};

const ensureCaptchaToken = async (): Promise<boolean> => {
    if (!props.captcha) {
        return true;
    }

    if (isRecaptchaV3.value) {
        captchaToken.value = await executeRecaptchaV3();
    }

    if (!captchaToken.value) {
        errorMessage.value = t('Please complete the captcha verification first.');

        return false;
    }

    return true;
};

onMounted(() => {
    if (props.captcha) {
        loadCaptchaScript();
    }
});

watch(captchaScriptLoaded, async (loaded) => {
    if (loaded && !isRecaptchaV3.value) {
        await nextTick();
        renderCaptchaWidget();
    }
});
const { t } = useI18n();
const page = usePage<SharedProps>();
const locale = computed(() => page.props.locale ?? 'en');
const availableLocales = computed(() => page.props.available_locales ?? ['tr', 'tr-gokturk', 'gokturk-latin', 'az', 'en', 'de', 'es', 'fr', 'ru']);

const nativeLocaleNamesByLocale: Record<string, string> = {
    'tr-gokturk': 'Göktürkçe',
    'gokturk-latin': 'Göktürkçe (Latin)',
};

const nativeLocaleNamesByLanguage: Record<string, string> = {
    tr: 'Türkçe',
    az: 'Azərbaycanca',
    en: 'English',
    de: 'Deutsch',
    es: 'Español',
    fr: 'Français',
    ru: 'Русский',
};

const normalizeLocale = (code: string): string => {
    return code.toLowerCase().replace('_', '-');
};

const localeName = (code: string): string => {
    const normalized = normalizeLocale(code);
    const [language] = normalized.split('-');

    if (nativeLocaleNamesByLocale[normalized]) {
        return nativeLocaleNamesByLocale[normalized];
    }

    return nativeLocaleNamesByLanguage[language] ?? normalized.toUpperCase();
};

const onLocaleChange = (event: Event): void => {
    const target = event.target as HTMLSelectElement | null;

    if (!target || target.value === locale.value) {
        return;
    }

    localStorage.setItem('locale', target.value);
    router.post(route('locale.set'), { locale: target.value }, {
        preserveScroll: true,
        preserveState: false,
        replace: true,
    });
};

const base64UrlToArrayBuffer = (value: string): ArrayBuffer => {
    const base64 = value.replace(/-/g, '+').replace(/_/g, '/');
    const padded = base64.padEnd(base64.length + ((4 - (base64.length % 4)) % 4), '=');
    const binary = atob(padded);
    const bytes = Uint8Array.from(binary, (char) => char.charCodeAt(0));

    return bytes.buffer;
};

const arrayBufferToBase64 = (value: ArrayBuffer | Uint8Array | null): string | null => {
    if (!value) {
        return null;
    }

    const bytes = value instanceof Uint8Array ? value : new Uint8Array(value);
    let binary = '';
    for (let index = 0; index < bytes.length; index += 1) {
        binary += String.fromCharCode(bytes[index]);
    }

    return btoa(binary);
};

const normalizeRequestOptions = (
    options: PublicKeyCredentialRequestOptionsPayload,
): PublicKeyCredentialRequestOptions => {
    return {
        ...options,
        challenge: base64UrlToArrayBuffer(options.challenge),
        allowCredentials: options.allowCredentials?.map((credential) => ({
            ...credential,
            id: base64UrlToArrayBuffer(credential.id),
        } as PublicKeyCredentialDescriptor)),
    };
};

const serializeCredential = (credential: PublicKeyCredential): Record<string, any> => {
    const response = credential.response as AuthenticatorAssertionResponse;

    return {
        id: credential.id,
        type: credential.type,
        rawId: arrayBufferToBase64(credential.rawId),
        authenticatorAttachment: credential.authenticatorAttachment,
        clientExtensionResults: credential.getClientExtensionResults(),
        response: {
            clientDataJSON: arrayBufferToBase64(response.clientDataJSON),
            authenticatorData: arrayBufferToBase64(response.authenticatorData),
            signature: arrayBufferToBase64(response.signature),
            userHandle: arrayBufferToBase64(response.userHandle),
        },
    };
};

const verifyCode = async () => {
    if (code.value.length < 6) {
        errorMessage.value = t('Please enter a valid authentication code.');

        return;
    }

    errorMessage.value = '';

    if (! await ensureCaptchaToken()) {
        return;
    }

    loading.value = true;

    try {
        const response = await axios.post(route('two-factor.verify'), {
            code: code.value,
            captcha_token: captchaToken.value,
        });

        if (response.data?.status === 'error') {
            errorMessage.value = response.data?.message ?? t('Invalid authentication code.');

            return;
        }

        router.visit(route('home'));
    } catch (error: any) {
        errorMessage.value = error?.response?.data?.message ?? t('Invalid authentication code.');

        // Reset captcha widget on error so user can retry
        if (props.captcha && !isRecaptchaV3.value) {
            captchaToken.value = '';
            renderCaptchaWidget();
        }
    } finally {
        loading.value = false;
    }
};

const verifyDevice = async () => {
    if (!window.PublicKeyCredential) {
        errorMessage.value = t('This browser does not support passkey verification.');

        return;
    }

    errorMessage.value = '';

    if (! await ensureCaptchaToken()) {
        return;
    }

    loading.value = true;

    try {
        const optionsResponse = await axios.post(route('webauthn.login.options'), {
            email: props.email,
            captcha_token: captchaToken.value,
        });

        const publicKey = normalizeRequestOptions(optionsResponse.data as PublicKeyCredentialRequestOptionsPayload);
        const credential = await navigator.credentials.get({
            publicKey,
        });

        if (!credential) {
            throw new Error(t('Authentication was cancelled.'));
        }

        const payload = {
            ...serializeCredential(credential as PublicKeyCredential),
            captcha_token: captchaToken.value,
        };
        await axios.post(route('webauthn.login'), payload);

        router.visit(route('home'));
    } catch (error: any) {
        if (error?.name === 'NotAllowedError') {
            errorMessage.value = t('Device verification was cancelled or timed out.');
        } else {
            errorMessage.value = t('Device verification failed.');
        }

        // Reset captcha widget on error so user can retry
        if (props.captcha && !isRecaptchaV3.value) {
            captchaToken.value = '';
            renderCaptchaWidget();
        }
    } finally {
        loading.value = false;
    }
};

const logout = () => {
    router.post(route('logout'));
};
</script>
