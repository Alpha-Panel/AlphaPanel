<template>
    <Head :title="t('ACME Settings')" />
    <ThemeProvider>
        <SidebarProvider>
            <AdminLayout>
                <PageBreadcrumb
                    :pageTitle="t('ACME Settings')"
                    :items="breadcrumbs"
                />
                <Toast />

                <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03] md:p-6">
                    <h3 class="mb-5 text-lg font-semibold text-gray-800 dark:text-white/90">
                        <i class="bx bx-lock-alt mr-2 text-brand-500"></i>
                        {{ t('ACME Settings') }}
                    </h3>

                    <form @submit.prevent="submit" class="space-y-6">
                        <!-- Staging Warning -->
                        <div v-if="form.staging" class="mb-4 rounded-lg border border-warning-200 bg-warning-50 p-3 text-sm text-warning-700 dark:border-warning-800 dark:bg-warning-900/20 dark:text-warning-400">
                            <i class="bx bx-error mr-1"></i>
                            {{ t('Staging mode is enabled. Certificates will be issued by the Let\'s Encrypt staging CA and will NOT be trusted by browsers.') }}
                        </div>

                        <!-- General Settings -->
                        <div>
                            <h4 class="mb-3 flex items-center gap-2 text-sm font-semibold text-gray-800 dark:text-white/90">
                                <i class="bx bx-cog text-base text-brand-500"></i>
                                {{ t('General Settings') }}
                            </h4>
                            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                <FormField :label="t('Email')" :error="form.errors.email" required>
                                    <input v-model="form.email" type="email" class="form-input" placeholder="admin@example.com" />
                                </FormField>

                                <FormField :label="t('Staging Mode')" :error="form.errors.staging">
                                    <label class="inline-flex items-center gap-2 cursor-pointer">
                                        <input v-model="form.staging" type="checkbox" class="h-5 w-5 rounded border-gray-300 text-brand-500 focus:ring-brand-500 dark:border-gray-700 dark:bg-gray-900" />
                                        <span class="text-sm text-gray-700 dark:text-gray-300">{{ t('Enable staging mode') }}</span>
                                        <span v-if="form.staging" class="inline-flex items-center rounded-full bg-warning-100 px-2 py-0.5 text-xs font-medium text-warning-700 dark:bg-warning-900/30 dark:text-warning-400">
                                            {{ t('Staging') }}
                                        </span>
                                    </label>
                                </FormField>
                            </div>
                        </div>

                        <!-- ACME Server -->
                        <div class="border-t border-gray-200 pt-5 dark:border-gray-800">
                            <h4 class="mb-3 flex items-center gap-2 text-sm font-semibold text-gray-800 dark:text-white/90">
                                <i class="bx bx-server text-base text-brand-500"></i>
                                {{ t('ACME Server') }}
                            </h4>
                            <div class="grid grid-cols-1 gap-4">
                                <FormField :label="t('Production Server URL')" :error="form.errors.server_url">
                                    <input v-model="form.server_url" type="url" class="form-input" placeholder="https://acme-v02.api.letsencrypt.org/directory" />
                                </FormField>

                                <FormField :label="t('Staging Server URL')" :error="form.errors.staging_server_url">
                                    <input v-model="form.staging_server_url" type="url" class="form-input" placeholder="https://acme-staging-v02.api.letsencrypt.org/directory" />
                                </FormField>
                            </div>
                        </div>

                        <!-- Key Configuration -->
                        <div class="border-t border-gray-200 pt-5 dark:border-gray-800">
                            <h4 class="mb-3 flex items-center gap-2 text-sm font-semibold text-gray-800 dark:text-white/90">
                                <i class="bx bx-key text-base text-brand-500"></i>
                                {{ t('Key Configuration') }}
                            </h4>
                            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                <FormField :label="t('Key Type')" :error="form.errors.key_type">
                                    <select v-model="form.key_type" class="form-input">
                                        <option value="EC">EC</option>
                                        <option value="RSA">RSA</option>
                                    </select>
                                </FormField>

                                <FormField :label="t('Key Length')" :error="form.errors.key_length">
                                    <select v-model="form.key_length" class="form-input">
                                        <option v-for="option in keyLengthOptions" :key="option.value" :value="option.value">
                                            {{ option.label }}
                                        </option>
                                    </select>
                                </FormField>
                            </div>
                        </div>

                        <!-- Timing -->
                        <div class="border-t border-gray-200 pt-5 dark:border-gray-800">
                            <h4 class="mb-3 flex items-center gap-2 text-sm font-semibold text-gray-800 dark:text-white/90">
                                <i class="bx bx-time-five text-base text-brand-500"></i>
                                {{ t('Timing') }}
                            </h4>
                            <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                                <FormField :label="t('DNS Propagation Wait (seconds)')" :error="form.errors.dns_propagation_wait">
                                    <input v-model.number="form.dns_propagation_wait" type="number" min="0" class="form-input" />
                                </FormField>

                                <FormField :label="t('Local DNS Wait (seconds)')" :error="form.errors.local_dns_wait">
                                    <input v-model.number="form.local_dns_wait" type="number" min="0" class="form-input" />
                                </FormField>

                                <FormField :label="t('ACME Validation Timeout (seconds)')" :error="form.errors.poll_timeout">
                                    <input v-model.number="form.poll_timeout" type="number" min="0" class="form-input" />
                                </FormField>
                            </div>
                        </div>

                        <!-- Renewal -->
                        <div class="border-t border-gray-200 pt-5 dark:border-gray-800">
                            <h4 class="mb-3 flex items-center gap-2 text-sm font-semibold text-gray-800 dark:text-white/90">
                                <i class="bx bx-refresh text-base text-brand-500"></i>
                                {{ t('Renewal') }}
                            </h4>
                            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                <FormField :label="t('Auto-Renew Days Before Expiry')" :error="form.errors.auto_renew_days">
                                    <input v-model.number="form.auto_renew_days" type="number" min="1" class="form-input" />
                                </FormField>

                                <FormField :label="t('Webroot Path')" :error="form.errors.webroot_path">
                                    <input v-model="form.webroot_path" type="text" class="form-input" placeholder="/var/www/html/.well-known/acme-challenge" />
                                </FormField>
                            </div>
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
import { computed, watch } from 'vue';
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
        email: string;
        staging: boolean;
        server_url: string;
        staging_server_url: string;
        key_type: string;
        key_length: string;
        dns_propagation_wait: number;
        local_dns_wait: number;
        poll_timeout: number;
        webroot_path: string;
        auto_renew_days: number;
    };
}

const props = defineProps<Props>();

const { t } = useI18n();

const breadcrumbs = computed(() => [
    { label: t('Settings') },
    { label: t('ACME Settings') },
]);

const form = useForm({
    _method: 'PUT' as const,
    email: props.settings.email,
    staging: props.settings.staging,
    server_url: props.settings.server_url,
    staging_server_url: props.settings.staging_server_url,
    key_type: props.settings.key_type,
    key_length: props.settings.key_length,
    dns_propagation_wait: props.settings.dns_propagation_wait,
    local_dns_wait: props.settings.local_dns_wait,
    poll_timeout: props.settings.poll_timeout,
    webroot_path: props.settings.webroot_path,
    auto_renew_days: props.settings.auto_renew_days,
});

const ecOptions = [
    { value: 'P-256', label: 'P-256' },
    { value: 'P-384', label: 'P-384' },
];

const rsaOptions = [
    { value: '2048', label: '2048' },
    { value: '4096', label: '4096' },
];

const keyLengthOptions = computed(() => {
    return form.key_type === 'RSA' ? rsaOptions : ecOptions;
});

watch(() => form.key_type, (newType, oldType) => {
    if (newType !== oldType) {
        form.key_length = newType === 'RSA' ? '2048' : 'P-256';
    }
});

const submit = (): void => {
    form.post(route('settings.acme.update'), {
        preserveScroll: true,
    });
};
</script>

<style scoped>
@reference "../../../css/app.css";

.form-input {
    @apply h-11 w-full rounded-lg border border-gray-300 bg-transparent px-4 py-2.5 text-sm text-gray-800 shadow-theme-xs placeholder:text-gray-400 focus:border-brand-300 focus:outline-hidden focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90 dark:placeholder:text-white/30 dark:focus:border-brand-800;
}
</style>
