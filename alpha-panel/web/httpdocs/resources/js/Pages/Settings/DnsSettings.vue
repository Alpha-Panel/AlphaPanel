<template>
    <Head :title="t('DNS Server Settings')" />
    <ThemeProvider>
        <SidebarProvider>
            <AdminLayout>
                <PageBreadcrumb
                    :pageTitle="t('DNS Server Settings')"
                    :items="breadcrumbs"
                />
                <Toast />

                <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/3 md:p-6">
                    <h3 class="mb-5 text-lg font-semibold text-gray-800 dark:text-white/90">
                        <i class="bx bx-server mr-2 text-brand-500"></i>
                        {{ t('DNS Server Settings') }}
                    </h3>

                    <form @submit.prevent="submit" class="space-y-6">
                        <!-- Nameservers -->
                        <div>
                            <h4 class="mb-3 flex items-center gap-2 text-sm font-semibold text-gray-800 dark:text-white/90">
                                <i class="bx bx-globe text-base text-brand-500"></i>
                                {{ t('Nameservers') }}
                            </h4>
                            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                <FormField :label="t('Primary Nameserver (NS1)')" :error="form.errors.ns1" required>
                                    <input v-model="form.ns1" type="text" class="form-input" placeholder="ns1.example.com" />
                                </FormField>

                                <FormField :label="t('Secondary Nameserver (NS2)')" :error="form.errors.ns2" required>
                                    <input v-model="form.ns2" type="text" class="form-input" placeholder="ns2.example.com" />
                                </FormField>

                                <FormField :label="t('Nameserver 3 (NS3)')" :error="form.errors.ns3">
                                    <input v-model="form.ns3" type="text" class="form-input" placeholder="ns3.example.com" />
                                </FormField>

                                <FormField :label="t('Nameserver 4 (NS4)')" :error="form.errors.ns4">
                                    <input v-model="form.ns4" type="text" class="form-input" placeholder="ns4.example.com" />
                                </FormField>
                            </div>
                        </div>

                        <!-- Default Settings -->
                        <div class="border-t border-gray-200 pt-5 dark:border-gray-800">
                            <h4 class="mb-3 flex items-center gap-2 text-sm font-semibold text-gray-800 dark:text-white/90">
                                <i class="bx bx-cog text-base text-brand-500"></i>
                                {{ t('Default Settings') }}
                            </h4>
                            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                                <FormField :label="t('Default IP Address')" :error="form.errors.default_ip">
                                    <input v-model="form.default_ip" type="text" class="form-input" placeholder="0.0.0.0" />
                                </FormField>

                                <FormField :label="t('Default TTL (seconds)')" :error="form.errors.default_ttl">
                                    <input v-model.number="form.default_ttl" type="number" min="60" class="form-input" />
                                </FormField>

                                <FormField :label="t('Default Template')" :error="form.errors.default_template_id">
                                    <select v-model="form.default_template_id" class="form-input">
                                        <option :value="null">{{ t('-- None --') }}</option>
                                        <option v-for="tpl in templates" :key="tpl.id" :value="tpl.id">
                                            {{ tpl.name }}
                                            <template v-if="tpl.is_default"> ({{ t('Default') }})</template>
                                        </option>
                                    </select>
                                </FormField>
                            </div>
                        </div>

                        <!-- SOA Configuration -->
                        <div class="border-t border-gray-200 pt-5 dark:border-gray-800">
                            <h4 class="mb-3 flex items-center gap-2 text-sm font-semibold text-gray-800 dark:text-white/90">
                                <i class="bx bx-detail text-base text-brand-500"></i>
                                {{ t('SOA Configuration') }}
                            </h4>
                            <div class="mb-4 rounded-lg border border-blue-200 bg-blue-50 p-3 dark:border-blue-800 dark:bg-blue-900/20">
                                <p class="text-xs text-blue-700 dark:text-blue-400">
                                    {{ t('Start of Authority (SOA) record settings control how DNS caching and zone transfers work. These values are applied to all new zones.') }}
                                </p>
                            </div>
                            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                                <FormField :label="t('Admin Email')" :error="form.errors.soa_admin_email">
                                    <input v-model="form.soa_admin_email" type="text" class="form-input" placeholder="admin.example.com" />
                                </FormField>

                                <FormField :label="t('Refresh (seconds)')" :error="form.errors.soa_refresh">
                                    <input v-model.number="form.soa_refresh" type="number" min="60" class="form-input" />
                                </FormField>

                                <FormField :label="t('Retry (seconds)')" :error="form.errors.soa_retry">
                                    <input v-model.number="form.soa_retry" type="number" min="60" class="form-input" />
                                </FormField>

                                <FormField :label="t('Expire (seconds)')" :error="form.errors.soa_expire">
                                    <input v-model.number="form.soa_expire" type="number" min="60" class="form-input" />
                                </FormField>

                                <FormField :label="t('Minimum TTL (seconds)')" :error="form.errors.soa_minimum_ttl">
                                    <input v-model.number="form.soa_minimum_ttl" type="number" min="0" class="form-input" />
                                </FormField>
                            </div>
                        </div>

                        <div class="flex items-center gap-3 border-t border-gray-200 pt-5 dark:border-gray-800">
                            <button
                                type="submit"
                                :disabled="form.processing"
                                class="inline-flex items-center gap-2 rounded-lg bg-brand-500 px-4 py-2.5 text-sm font-medium text-white shadow-theme-xs hover:bg-brand-600 disabled:opacity-50"
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

interface DnsSetting {
    ns1: string;
    ns2: string;
    ns3: string | null;
    ns4: string | null;
    default_ip: string | null;
    soa_admin_email: string | null;
    soa_refresh: number;
    soa_retry: number;
    soa_expire: number;
    soa_minimum_ttl: number;
    default_ttl: number;
    default_template_id: number | null;
}

interface Template {
    id: number;
    name: string;
    is_default: boolean;
}

const props = defineProps<{
    settings: DnsSetting;
    templates: Template[];
}>();

const { t } = useI18n();

const breadcrumbs = computed(() => [
    { label: t('Settings') },
    { label: t('DNS Server Settings') },
]);

const form = useForm({
    _method: 'PUT' as const,
    ns1: props.settings.ns1,
    ns2: props.settings.ns2,
    ns3: props.settings.ns3 ?? '',
    ns4: props.settings.ns4 ?? '',
    default_ip: props.settings.default_ip ?? '',
    default_ttl: props.settings.default_ttl,
    default_template_id: props.settings.default_template_id,
    soa_admin_email: props.settings.soa_admin_email ?? '',
    soa_refresh: props.settings.soa_refresh,
    soa_retry: props.settings.soa_retry,
    soa_expire: props.settings.soa_expire,
    soa_minimum_ttl: props.settings.soa_minimum_ttl,
});

const submit = (): void => {
    form.post(route('settings.dns.update'));
};
</script>

<style scoped>
@reference "../../../css/app.css";

.form-input {
    @apply h-11 w-full rounded-lg border border-gray-300 bg-transparent px-4 py-2.5 text-sm text-gray-800 shadow-theme-xs placeholder:text-gray-400 focus:border-brand-300 focus:outline-hidden focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90 dark:placeholder:text-white/30 dark:focus:border-brand-800;
}
</style>
