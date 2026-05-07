<template>
    <Head :title="t('Panel SSL Certificate')" />
    <ThemeProvider>
        <SidebarProvider>
            <AdminLayout>
                <PageBreadcrumb
                    :pageTitle="t('Panel SSL Certificate')"
                    :items="breadcrumbs"
                />
                <Toast />

                <!-- Current Certificate Status -->
                <div class="mb-6 rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/3 md:p-6">
                    <h3 class="mb-5 text-lg font-semibold text-gray-800 dark:text-white/90">
                        <i class="bx bx-shield-alt-2 mr-2 text-brand-500"></i>
                        {{ t('Current Certificate') }}
                    </h3>

                    <!-- No cert -->
                    <div v-if="!props.cert" class="flex items-center gap-3 rounded-lg border border-warning-200 bg-warning-50 p-4 text-sm text-warning-700 dark:border-warning-800 dark:bg-warning-900/20 dark:text-warning-400">
                        <i class="bx bx-error-circle text-xl"></i>
                        <span>{{ t('No certificate found. Issue a certificate below.') }}</span>
                    </div>

                    <template v-else>
                        <!-- Status banner -->
                        <div
                            v-if="props.cert.type === 'self_signed'"
                            class="mb-4 flex items-center gap-3 rounded-lg border border-warning-200 bg-warning-50 p-3 text-sm text-warning-700 dark:border-warning-800 dark:bg-warning-900/20 dark:text-warning-400"
                        >
                            <i class="bx bx-error text-base"></i>
                            {{ t('Self-signed certificate detected. Issue a Let\'s Encrypt certificate below.') }}
                        </div>

                        <div
                            v-else-if="props.cert.is_live_synced"
                            class="mb-4 flex items-center gap-3 rounded-lg border border-success-200 bg-success-50 p-3 text-sm text-success-700 dark:border-success-800 dark:bg-success-900/20 dark:text-success-400"
                        >
                            <i class="bx bx-check-circle text-base"></i>
                            {{ t('Let\'s Encrypt certificate is active and synced to panel.') }}
                        </div>

                        <div
                            v-else
                            class="mb-4 flex items-center gap-3 rounded-lg border border-orange-200 bg-orange-50 p-3 text-sm text-orange-700 dark:border-orange-800 dark:bg-orange-900/20 dark:text-orange-400"
                        >
                            <i class="bx bx-info-circle text-base"></i>
                            {{ t('Certificate exists but is not synced to panel. Click Sync to apply.') }}
                        </div>

                        <!-- Cert details -->
                        <dl class="grid grid-cols-1 gap-3 text-sm sm:grid-cols-2">
                            <div class="flex flex-col gap-1">
                                <dt class="font-medium text-gray-500 dark:text-gray-400">{{ t('Type') }}</dt>
                                <dd>
                                    <span
                                        class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium"
                                        :class="props.cert.type === 'lets_encrypt'
                                            ? 'bg-success-100 text-success-700 dark:bg-success-900/30 dark:text-success-400'
                                            : 'bg-warning-100 text-warning-700 dark:bg-warning-900/30 dark:text-warning-400'"
                                    >
                                        {{ props.cert.type_label }}
                                    </span>
                                </dd>
                            </div>
                            <div class="flex flex-col gap-1">
                                <dt class="font-medium text-gray-500 dark:text-gray-400">{{ t('Common Name') }}</dt>
                                <dd class="text-gray-800 dark:text-white/90">{{ props.cert.common_name ?? '—' }}</dd>
                            </div>
                            <div class="flex flex-col gap-1">
                                <dt class="font-medium text-gray-500 dark:text-gray-400">{{ t('Issuer') }}</dt>
                                <dd class="text-gray-800 dark:text-white/90">{{ props.cert.issuer ?? '—' }}</dd>
                            </div>
                            <div class="flex flex-col gap-1">
                                <dt class="font-medium text-gray-500 dark:text-gray-400">{{ t('Expires') }}</dt>
                                <dd class="text-gray-800 dark:text-white/90">{{ props.cert.not_after ?? '—' }}</dd>
                            </div>
                        </dl>

                        <!-- Sync button -->
                        <div v-if="!props.cert.is_live_synced" class="mt-4 border-t border-gray-200 pt-4 dark:border-gray-800">
                            <form @submit.prevent="sync">
                                <button
                                    type="submit"
                                    :disabled="syncForm.processing"
                                    class="inline-flex items-center gap-2 rounded-lg bg-brand-500 px-5 py-2.5 text-sm font-medium text-white shadow-theme-xs hover:bg-brand-600 disabled:opacity-50"
                                >
                                    <i v-if="syncForm.processing" class="bx bx-loader-alt animate-spin text-base"></i>
                                    <i v-else class="bx bx-sync text-base"></i>
                                    {{ syncForm.processing ? t('Syncing...') : t('Sync to Panel') }}
                                </button>
                            </form>
                        </div>
                    </template>
                </div>

                <!-- Issue Certificate -->
                <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/3 md:p-6">
                    <h3 class="mb-5 text-lg font-semibold text-gray-800 dark:text-white/90">
                        <i class="bx bx-plus-circle mr-2 text-brand-500"></i>
                        {{ t('Issue Let\'s Encrypt Certificate') }}
                    </h3>

                    <div v-if="!props.base_domain" class="rounded-lg border border-error-200 bg-error-50 p-4 text-sm text-error-700 dark:border-error-800 dark:bg-error-900/20 dark:text-error-400">
                        <i class="bx bx-error-circle mr-1"></i>
                        {{ t('BASE_DOMAIN is not set in the server environment.') }}
                    </div>

                    <template v-else>
                        <p class="mb-4 text-sm text-gray-600 dark:text-gray-400">
                            {{ t('Issue a Let\'s Encrypt certificate for :domain and all subdomains (wildcard).', { domain: props.base_domain }) }}
                        </p>

                        <form @submit.prevent="issue" class="space-y-4">
                            <FormField :label="t('Validation Method')" :error="issueForm.errors.ssl_method">
                                <select v-model="issueForm.ssl_method" class="form-input">
                                    <option v-for="method in props.ssl_methods" :key="method.value" :value="method.value">
                                        {{ t(method.label) }}
                                    </option>
                                </select>
                            </FormField>

                            <div>
                                <button
                                    type="submit"
                                    :disabled="issueForm.processing"
                                    class="inline-flex items-center gap-2 rounded-lg bg-brand-500 px-5 py-2.5 text-sm font-medium text-white shadow-theme-xs hover:bg-brand-600 disabled:opacity-50"
                                >
                                    <i v-if="issueForm.processing" class="bx bx-loader-alt animate-spin text-base"></i>
                                    <i v-else class="bx bx-lock-alt text-base"></i>
                                    {{ issueForm.processing ? t('Issuing...') : t('Issue Certificate') }}
                                </button>
                            </div>
                        </form>
                    </template>
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

interface CertInfo {
    id: number;
    type: string;
    type_label: string;
    common_name: string | null;
    issuer: string | null;
    not_after: string | null;
    is_wildcard: boolean;
    is_live_synced: boolean;
}

interface SslMethod {
    value: string;
    label: string;
}

interface Props {
    base_domain: string | null;
    cert: CertInfo | null;
    ssl_methods: SslMethod[];
}

const props = defineProps<Props>();
const { t } = useI18n();

const breadcrumbs = computed(() => [
    { label: t('Settings') },
    { label: t('Panel SSL Certificate') },
]);

const issueForm = useForm({
    ssl_method: 'cloudflare_dns',
});

const syncForm = useForm({});

const issue = (): void => {
    issueForm.post(route('settings.panel-ssl.issue'), {
        preserveScroll: true,
    });
};

const sync = (): void => {
    syncForm.post(route('settings.panel-ssl.sync'), {
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
