<template>
    <Head :title="t('Sign In')" />
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

                    <div class="mb-6 flex items-center justify-center gap-3">
                        <img :src="logoUrl" :alt="appName" class="h-12 w-12 shrink-0 object-contain" />
                        <span class="text-2xl font-bold text-brand-500">{{ appName }}</span>
                    </div>

                    <div class="mb-5 sm:mb-8">
                        <h1 class="mb-2 text-2xl font-semibold text-gray-800 dark:text-white/90 sm:text-title-sm">
                            {{ t('Sign In') }}
                        </h1>
                        <p class="text-sm text-gray-500 dark:text-gray-400">
                            <template v-if="stage === 'identifier'">
                                {{ t('Enter your username or email to continue') }}
                            </template>
                            <template v-else>
                                {{ t('Enter your password for :login', { login: form.login }) }}
                            </template>
                        </p>
                    </div>

                    <form @submit.prevent="stage === 'identifier' ? continueWithIdentifier() : submitPassword()">
                        <div class="space-y-5">
                            <div>
                                <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">
                                    {{ t('Username or Email') }}
                                </label>
                                <input
                                    v-model="form.login"
                                    type="text"
                                    :readonly="stage === 'password'"
                                    :placeholder="t('Enter your username or email')"
                                    class="dark:bg-dark-900 h-11 w-full rounded-lg border border-gray-300 bg-transparent px-4 py-2.5 text-sm text-gray-800 shadow-theme-xs placeholder:text-gray-400 focus:border-brand-300 focus:outline-hidden focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90 dark:placeholder:text-white/30 dark:focus:border-brand-800"
                                />
                                <p v-if="form.errors.login" class="mt-1 text-sm text-error-500">
                                    {{ form.errors.login }}
                                </p>
                            </div>

                            <div v-if="stage === 'password'">
                                <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">
                                    {{ t('Password') }}
                                </label>
                                <div class="relative">
                                    <input
                                        ref="passwordInputRef"
                                        v-model="form.password"
                                        :type="showPassword ? 'text' : 'password'"
                                        :placeholder="t('Enter your password')"
                                        class="dark:bg-dark-900 h-11 w-full rounded-lg border border-gray-300 bg-transparent px-4 py-2.5 pr-11 text-sm text-gray-800 shadow-theme-xs placeholder:text-gray-400 focus:border-brand-300 focus:outline-hidden focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90 dark:placeholder:text-white/30 dark:focus:border-brand-800"
                                    />
                                    <button
                                        type="button"
                                        @click="showPassword = !showPassword"
                                        class="absolute right-4 top-1/2 -translate-y-1/2 text-gray-500"
                                    >
                                        <svg
                                            width="20"
                                            height="20"
                                            viewBox="0 0 20 20"
                                            fill="none"
                                            xmlns="http://www.w3.org/2000/svg"
                                        >
                                            <path
                                                v-if="!showPassword"
                                                d="M2.5 10C2.5 10 5 4.166 10 4.166S17.5 10 17.5 10 15 15.833 10 15.833 2.5 10 2.5 10Z"
                                                stroke="currentColor"
                                                stroke-width="1.5"
                                                stroke-linecap="round"
                                                stroke-linejoin="round"
                                            />
                                            <path
                                                v-if="!showPassword"
                                                d="M10 12.5a2.5 2.5 0 1 0 0-5 2.5 2.5 0 0 0 0 5Z"
                                                stroke="currentColor"
                                                stroke-width="1.5"
                                            />
                                            <path
                                                v-if="showPassword"
                                                d="M3 3l14 14M8.5 8.5a2.5 2.5 0 0 0 3 3M2.5 10S5 4.2 10 4.2c1 0 2 .2 2.8.6M17.5 10s-1 2.2-3.3 3.8"
                                                stroke="currentColor"
                                                stroke-width="1.5"
                                                stroke-linecap="round"
                                                stroke-linejoin="round"
                                            />
                                        </svg>
                                    </button>
                                </div>
                                <p v-if="form.errors.password" class="mt-1 text-sm text-error-500">
                                    {{ form.errors.password }}
                                </p>
                            </div>

                            <div v-if="stage === 'password'" class="flex items-center justify-between">
                                <label class="flex items-center gap-2">
                                    <input
                                        v-model="form.remember"
                                        type="checkbox"
                                        class="h-4 w-4 rounded border-gray-300 text-brand-500 focus:ring-brand-500"
                                    />
                                    <span class="text-sm text-gray-700 dark:text-gray-400">
                                        {{ t('Remember me') }}
                                    </span>
                                </label>
                            </div>

                            <div v-if="methods?.has_totp && stage === 'password'" class="rounded-lg border border-warning-500/30 bg-warning-500/10 px-3 py-2 text-xs text-warning-700 dark:text-warning-300">
                                {{ t('This account requires a 2FA verification code after password sign in.') }}
                            </div>

                            <button
                                type="submit"
                                :disabled="identifierLoading || webauthnLoading || form.processing"
                                class="flex w-full items-center justify-center rounded-lg bg-brand-500 px-4 py-3 text-sm font-medium text-white shadow-theme-xs transition hover:bg-brand-600 disabled:opacity-50"
                            >
                                <template v-if="stage === 'identifier'">
                                    <span v-if="identifierLoading">{{ t('Checking account...') }}</span>
                                    <span v-else-if="webauthnLoading">{{ t('Waiting for device...') }}</span>
                                    <span v-else>{{ t('Continue') }}</span>
                                </template>
                                <template v-else>
                                    <span v-if="form.processing">{{ t('Signing in...') }}</span>
                                    <span v-else>{{ t('Sign In') }}</span>
                                </template>
                            </button>

                            <button
                                v-if="stage === 'password'"
                                type="button"
                                @click="goBackToIdentifier"
                                class="w-full rounded-lg border border-gray-300 px-4 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-gray-800"
                            >
                                {{ t('Use Different Username') }}
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </FullScreenLayout>
    </ThemeProvider>
</template>

<script setup lang="ts">
import { computed, nextTick, ref } from 'vue';
import axios from 'axios';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import ThemeProvider from '@/Components/Layout/ThemeProvider.vue';
import FullScreenLayout from '@/Components/Layout/FullScreenLayout.vue';
import { useI18n } from '@/Composables/useI18n';
import type { SharedProps } from '@/types/inertia';

interface LoginMethodsResponse {
    has_webauthn: boolean;
    has_totp: boolean;
    email: string | null;
}

type PublicKeyCredentialRequestOptionsPayload = {
    [key: string]: any;
    challenge: string;
    allowCredentials?: Array<{
        [key: string]: any;
        id: string;
    }>;
};

const stage = ref<'identifier' | 'password'>('identifier');
const { t } = useI18n();
const page = usePage<SharedProps>();
const appName = computed(() => page.props.app?.name ?? 'AlphaPanel');
const logoUrl = computed(() => page.props.app?.logo_url ?? '/img/AlphaPanel-dark.svg');
const locale = computed(() => page.props.locale ?? 'en');
const availableLocales = computed(() => page.props.available_locales ?? ['tr', 'tr-gokturk', 'gokturk-latin', 'az', 'en', 'de', 'es', 'fr', 'ru']);
const showPassword = ref(false);
const identifierLoading = ref(false);
const webauthnLoading = ref(false);
const methods = ref<LoginMethodsResponse | null>(null);
const passwordInputRef = ref<HTMLInputElement | null>(null);

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

const form = useForm({
    login: '',
    password: '',
    remember: false,
});

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
    const normalized: PublicKeyCredentialRequestOptions = {
        ...options,
        challenge: base64UrlToArrayBuffer(options.challenge),
        allowCredentials: options.allowCredentials?.map((credential) => ({
            ...credential,
            id: base64UrlToArrayBuffer(credential.id),
        })),
    };

    return normalized;
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

const continueWithIdentifier = async () => {
    form.clearErrors();

    if (!form.login.trim()) {
        form.setError('login', t('Username or email is required.'));

        return;
    }

    identifierLoading.value = true;

    try {
        const response = await axios.post<LoginMethodsResponse>(route('login.methods'), {
            login: form.login.trim(),
        });

        methods.value = response.data;

        if (methods.value.has_webauthn && methods.value.email) {
            await loginWithDevice(methods.value.email);

            return;
        }

        stage.value = 'password';
        await nextTick();
        passwordInputRef.value?.focus();
    } catch (error: any) {
        const message = error?.response?.data?.message ?? t('Account could not be validated.');
        form.setError('login', message);
    } finally {
        identifierLoading.value = false;
    }
};

const loginWithDevice = async (email: string) => {
    if (!window.PublicKeyCredential) {
        stage.value = 'password';
        form.setError('login', t('This browser does not support passkey login.'));
        await nextTick();
        passwordInputRef.value?.focus();

        return;
    }

    webauthnLoading.value = true;

    try {
        const optionsResponse = await axios.post(route('webauthn.login.options'), {
            email,
        });

        const publicKey = normalizeRequestOptions(optionsResponse.data as PublicKeyCredentialRequestOptionsPayload);
        const credential = await navigator.credentials.get({
            publicKey,
        });

        if (!credential) {
            throw new Error(t('Authentication was cancelled.'));
        }

        const payload = serializeCredential(credential as PublicKeyCredential);
        await axios.post(route('webauthn.login'), payload);

        router.visit(route('home'));
    } catch (error: any) {
        if (error?.name === 'NotAllowedError') {
            form.setError('login', t('Device authentication was cancelled or timed out.'));
        } else {
            form.setError('login', t('Device authentication failed. Continue with password.'));
        }

        stage.value = 'password';
        await nextTick();
        passwordInputRef.value?.focus();
    } finally {
        webauthnLoading.value = false;
    }
};

const submitPassword = () => {
    form.post(route('login'), {
        onFinish: () => {
            form.reset('password');
        },
    });
};

const goBackToIdentifier = () => {
    stage.value = 'identifier';
    methods.value = null;
    form.reset('password');
    form.clearErrors('password');
};
</script>
