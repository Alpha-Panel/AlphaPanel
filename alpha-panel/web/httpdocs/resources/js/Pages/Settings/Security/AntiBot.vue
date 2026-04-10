<template>
    <Head :title="t('Anti-Bot Protection')" />
    <ThemeProvider>
        <SidebarProvider>
            <AdminLayout>
                <PageBreadcrumb
                    :pageTitle="t('Anti-Bot Protection')"
                    :items="breadcrumbs"
                />
                <Toast />

                <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/3 md:p-6">
                    <h3 class="mb-5 text-lg font-semibold text-gray-800 dark:text-white/90">
                        <i class="fa-solid fa-shield-virus mr-2 text-brand-500"></i>
                        {{ t('Anti-Bot Protection') }}
                    </h3>

                    <form @submit.prevent="submit" class="space-y-6">
                        <!-- CAPTCHA Provider -->
                        <div>
                            <h4 class="mb-3 flex items-center gap-2 text-sm font-semibold text-gray-800 dark:text-white/90">
                                <i class="fa-solid fa-robot text-base text-brand-500"></i>
                                {{ t('CAPTCHA Provider') }}
                            </h4>
                            <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                                <label
                                    v-for="option in captchaOptions"
                                    :key="option.value"
                                    class="relative flex cursor-pointer items-start gap-3 rounded-xl border-2 p-4 transition-all duration-200"
                                    :class="[
                                        form.captcha_provider === option.value
                                            ? 'border-brand-500 bg-brand-50/50 shadow-sm dark:border-brand-400 dark:bg-brand-500/5'
                                            : 'border-gray-200 hover:border-gray-300 dark:border-gray-700 dark:hover:border-gray-600',
                                    ]"
                                >
                                    <input
                                        v-model="form.captcha_provider"
                                        type="radio"
                                        name="captcha_provider"
                                        :value="option.value"
                                        class="mt-0.5 h-4 w-4 border-gray-300 text-brand-500 focus:ring-brand-500 dark:border-gray-600"
                                    />
                                    <div class="flex-1">
                                        <div class="flex items-center gap-2">
                                            <i :class="[option.icon, 'text-base', form.captcha_provider === option.value ? 'text-brand-500' : 'text-gray-400 dark:text-gray-500']"></i>
                                            <span class="text-sm font-medium text-gray-800 dark:text-white/90">{{ t(option.label) }}</span>
                                        </div>
                                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ t(option.description) }}</p>
                                    </div>
                                </label>
                            </div>
                            <p v-if="form.errors.captcha_provider" class="mt-1.5 text-sm text-error-500">{{ form.errors.captcha_provider }}</p>
                        </div>

                        <!-- Turnstile Keys -->
                        <div v-if="form.captcha_provider === 'turnstile'" class="border-t border-gray-200 pt-5 dark:border-gray-800">
                            <h4 class="mb-3 flex items-center gap-2 text-sm font-semibold text-gray-800 dark:text-white/90">
                                <i class="fa-solid fa-cloud text-base text-brand-500"></i>
                                {{ t('Cloudflare Turnstile Keys') }}
                            </h4>
                            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                <FormField :label="t('Site Key')" :error="form.errors.turnstile_site_key" required>
                                    <input
                                        v-model="form.turnstile_site_key"
                                        type="text"
                                        class="form-input"
                                        :placeholder="t('Enter Turnstile site key')"
                                    />
                                </FormField>

                                <FormField :label="t('Secret Key')" :error="form.errors.turnstile_secret_key">
                                    <input
                                        v-model="form.turnstile_secret_key"
                                        type="password"
                                        class="form-input"
                                        :placeholder="props.settings.has_turnstile_secret && !form.turnstile_secret_key ? '••••••••' : t('Enter Turnstile secret key')"
                                    />
                                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                        {{ t('Leave secret key blank to keep the existing key') }}
                                    </p>
                                </FormField>
                            </div>
                        </div>

                        <!-- reCAPTCHA Settings -->
                        <div v-if="form.captcha_provider === 'recaptcha'" class="border-t border-gray-200 pt-5 dark:border-gray-800">
                            <h4 class="mb-3 flex items-center gap-2 text-sm font-semibold text-gray-800 dark:text-white/90">
                                <i class="fa-solid fa-shield-halved text-base text-brand-500"></i>
                                {{ t('Google reCAPTCHA') }}
                            </h4>

                            <!-- Version Selection -->
                            <div class="mb-4">
                                <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">{{ t('reCAPTCHA Version') }}</label>
                                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                                    <label
                                        v-for="ver in recaptchaVersionOptions"
                                        :key="ver.value"
                                        class="relative flex cursor-pointer items-start gap-3 rounded-xl border-2 p-3 transition-all duration-200"
                                        :class="[
                                            form.recaptcha_version === ver.value
                                                ? 'border-brand-500 bg-brand-50/50 dark:border-brand-400 dark:bg-brand-500/5'
                                                : 'border-gray-200 hover:border-gray-300 dark:border-gray-700 dark:hover:border-gray-600',
                                        ]"
                                    >
                                        <input
                                            v-model="form.recaptcha_version"
                                            type="radio"
                                            name="recaptcha_version"
                                            :value="ver.value"
                                            class="mt-0.5 h-4 w-4 border-gray-300 text-brand-500 focus:ring-brand-500 dark:border-gray-600"
                                        />
                                        <div class="flex-1">
                                            <span class="text-sm font-medium text-gray-800 dark:text-white/90">{{ ver.label }}</span>
                                            <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">{{ t(ver.description) }}</p>
                                        </div>
                                    </label>
                                </div>
                                <p v-if="form.errors.recaptcha_version" class="mt-1.5 text-sm text-error-500">{{ form.errors.recaptcha_version }}</p>
                            </div>

                            <!-- Keys -->
                            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                <FormField :label="t('Site Key')" :error="form.errors.recaptcha_site_key" required>
                                    <input
                                        v-model="form.recaptcha_site_key"
                                        type="text"
                                        class="form-input"
                                        :placeholder="t('Enter reCAPTCHA site key')"
                                    />
                                </FormField>

                                <FormField :label="t('Secret Key')" :error="form.errors.recaptcha_secret_key">
                                    <input
                                        v-model="form.recaptcha_secret_key"
                                        type="password"
                                        class="form-input"
                                        :placeholder="props.settings.has_recaptcha_secret && !form.recaptcha_secret_key ? '••••••••' : t('Enter reCAPTCHA secret key')"
                                    />
                                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                        {{ t('Leave secret key blank to keep the existing key') }}
                                    </p>
                                </FormField>
                            </div>

                            <div v-if="form.recaptcha_version === 'v3'" class="mt-3 rounded-lg border border-blue-200 bg-blue-50/50 px-3 py-2 text-xs text-blue-700 dark:border-blue-800 dark:bg-blue-900/20 dark:text-blue-400">
                                <i class="fa-solid fa-circle-info mr-1"></i>
                                {{ t('reCAPTCHA v3 works invisibly in the background. Users will not see a checkbox.') }}
                            </div>
                        </div>

                        <!-- Honeypot -->
                        <div class="border-t border-gray-200 pt-5 dark:border-gray-800">
                            <h4 class="mb-3 flex items-center gap-2 text-sm font-semibold text-gray-800 dark:text-white/90">
                                <i class="fa-solid fa-jar text-base text-brand-500"></i>
                                {{ t('Honeypot') }}
                            </h4>
                            <div class="rounded-xl border-2 p-4 transition-all duration-200" :class="form.honeypot_enabled ? 'border-success-500/30 bg-success-50/50 dark:border-success-500/20 dark:bg-success-500/5' : 'border-gray-200 dark:border-gray-700'">
                                <label class="flex items-center justify-between cursor-pointer">
                                    <div class="flex items-center gap-3">
                                        <span class="relative">
                                            <input
                                                v-model="form.honeypot_enabled"
                                                type="checkbox"
                                                class="sr-only peer"
                                            />
                                            <span class="block h-6 w-11 rounded-full bg-gray-300 transition-colors peer-checked:bg-success-500 peer-focus:ring-3 peer-focus:ring-success-500/10 dark:bg-gray-600"></span>
                                            <span class="absolute left-0.5 top-0.5 h-5 w-5 rounded-full bg-white shadow transition-transform peer-checked:translate-x-5"></span>
                                        </span>
                                        <div>
                                            <span class="text-sm font-medium text-gray-800 dark:text-white/90">{{ t('Honeypot Protection') }}</span>
                                            <p class="text-xs text-gray-500 dark:text-gray-400">
                                                {{ t('Adds hidden form fields to detect and block automated bots') }}
                                            </p>
                                        </div>
                                    </div>
                                    <span
                                        class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium"
                                        :class="form.honeypot_enabled
                                            ? 'bg-success-100 text-success-700 dark:bg-success-900/30 dark:text-success-400'
                                            : 'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-400'"
                                    >
                                        {{ form.honeypot_enabled ? t('Active') : t('Inactive') }}
                                    </span>
                                </label>
                            </div>
                            <p v-if="form.errors.honeypot_enabled" class="mt-1.5 text-sm text-error-500">{{ form.errors.honeypot_enabled }}</p>
                        </div>

                        <div class="flex items-center gap-3 border-t border-gray-200 pt-5 dark:border-gray-800">
                            <button
                                type="submit"
                                :disabled="form.processing"
                                class="inline-flex items-center gap-2 rounded-lg bg-brand-500 px-5 py-2.5 text-sm font-medium text-white shadow-theme-xs hover:bg-brand-600 disabled:opacity-50"
                            >
                                <i v-if="form.processing" class="bx bx-loader-alt animate-spin text-base"></i>
                                {{ form.processing ? t('Saving...') : t('Save Settings') }}
                            </button>
                        </div>
                    </form>
                </div>
            </AdminLayout>
        </SidebarProvider>
    </ThemeProvider>
</template>

<script setup lang="ts">
import { computed } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import ThemeProvider from '@/Components/Layout/ThemeProvider.vue';
import SidebarProvider from '@/Components/Layout/SidebarProvider.vue';
import AdminLayout from '@/Components/Layout/AdminLayout.vue';
import PageBreadcrumb from '@/Components/Common/PageBreadcrumb.vue';
import FormField from '@/Components/UI/FormField.vue';
import Toast from '@/Components/UI/Toast.vue';
import { useI18n } from '@/Composables/useI18n';

interface Props {
    settings: {
        captcha_provider: 'none' | 'turnstile' | 'recaptcha';
        turnstile_site_key: string;
        recaptcha_version: 'v2' | 'v3';
        recaptcha_site_key: string;
        honeypot_enabled: boolean;
        has_turnstile_secret: boolean;
        has_recaptcha_secret: boolean;
    };
}

const props = defineProps<Props>();

const { t } = useI18n();

const breadcrumbs = computed(() => [
    { label: t('Settings') },
    { label: t('Security') },
    { label: t('Anti-Bot Protection') },
]);

const captchaOptions = [
    {
        value: 'none',
        label: 'None',
        description: 'No CAPTCHA verification',
        icon: 'fa-solid fa-ban',
    },
    {
        value: 'turnstile',
        label: 'Cloudflare Turnstile',
        description: 'Privacy-friendly verification',
        icon: 'fa-solid fa-cloud',
    },
    {
        value: 'recaptcha',
        label: 'Google reCAPTCHA',
        description: 'Checkbox verification',
        icon: 'fa-solid fa-shield-halved',
    },
] as const;

const recaptchaVersionOptions = [
    {
        value: 'v2',
        label: 'reCAPTCHA v2',
        description: 'Checkbox verification - users click "I\'m not a robot"',
    },
    {
        value: 'v3',
        label: 'reCAPTCHA v3',
        description: 'Invisible score-based verification - no user interaction needed',
    },
] as const;

const form = useForm({
    _method: 'PUT' as const,
    captcha_provider: props.settings.captcha_provider,
    turnstile_site_key: props.settings.turnstile_site_key,
    turnstile_secret_key: '',
    recaptcha_version: props.settings.recaptcha_version ?? 'v2',
    recaptcha_site_key: props.settings.recaptcha_site_key,
    recaptcha_secret_key: '',
    honeypot_enabled: props.settings.honeypot_enabled,
});

const submit = (): void => {
    form.post(route('settings.security.anti-bot.update'), {
        preserveScroll: true,
    });
};
</script>

<style scoped>
@reference "../../../../css/app.css";

.form-input {
    @apply h-11 w-full rounded-lg border border-gray-300 bg-transparent px-4 py-2.5 text-sm text-gray-800 shadow-theme-xs placeholder:text-gray-400 focus:border-brand-300 focus:outline-hidden focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90 dark:placeholder:text-white/30 dark:focus:border-brand-800;
}
</style>
