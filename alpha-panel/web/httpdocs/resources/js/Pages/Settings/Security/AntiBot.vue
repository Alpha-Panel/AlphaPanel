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

                        <!-- reCAPTCHA Keys -->
                        <div v-if="form.captcha_provider === 'recaptcha'" class="border-t border-gray-200 pt-5 dark:border-gray-800">
                            <h4 class="mb-3 flex items-center gap-2 text-sm font-semibold text-gray-800 dark:text-white/90">
                                <i class="fa-solid fa-shield-halved text-base text-brand-500"></i>
                                {{ t('Google reCAPTCHA Keys') }}
                            </h4>
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
                        </div>

                        <!-- Honeypot -->
                        <div class="border-t border-gray-200 pt-5 dark:border-gray-800">
                            <h4 class="mb-3 flex items-center gap-2 text-sm font-semibold text-gray-800 dark:text-white/90">
                                <i class="fa-solid fa-honey-pot text-base text-brand-500"></i>
                                {{ t('Honeypot') }}
                            </h4>
                            <FormField :label="t('Honeypot Protection')" :error="form.errors.honeypot_enabled">
                                <label class="inline-flex items-center gap-3 cursor-pointer">
                                    <span class="relative">
                                        <input
                                            v-model="form.honeypot_enabled"
                                            type="checkbox"
                                            class="sr-only peer"
                                        />
                                        <span class="block h-6 w-11 rounded-full bg-gray-300 transition-colors peer-checked:bg-brand-500 peer-focus:ring-3 peer-focus:ring-brand-500/10 dark:bg-gray-600"></span>
                                        <span class="absolute left-0.5 top-0.5 h-5 w-5 rounded-full bg-white shadow transition-transform peer-checked:translate-x-5"></span>
                                    </span>
                                    <span class="text-sm text-gray-700 dark:text-gray-300">{{ t('Enable Honeypot Protection') }}</span>
                                </label>
                                <p class="mt-1.5 text-xs text-gray-500 dark:text-gray-400">
                                    {{ t('Adds hidden form fields to detect and block automated bots') }}
                                </p>
                            </FormField>
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

const form = useForm({
    _method: 'PUT' as const,
    captcha_provider: props.settings.captcha_provider,
    turnstile_site_key: props.settings.turnstile_site_key,
    turnstile_secret_key: '',
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
