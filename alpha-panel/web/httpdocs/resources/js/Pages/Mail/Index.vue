<template>
    <Head :title="t('Mail')" />
    <ThemeProvider>
        <SidebarProvider>
            <AdminLayout>
                <PageBreadcrumb :pageTitle="t('Mail')" :items="breadcrumbs" />
                <Toast />

                <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/3 md:p-6">
                    <div class="mb-4 flex items-center justify-between">
                        <h3 class="text-lg font-semibold text-gray-800 dark:text-white/90">
                            {{ t('Domains with mail hosting') }}
                        </h3>
                        <Link
                            v-if="features.mail"
                            :href="route('mail.settings.edit')"
                            class="rounded-lg bg-brand-500 px-3 py-2 text-sm font-medium text-white hover:bg-brand-600"
                        >
                            {{ t('Mail Settings') }}
                        </Link>
                    </div>

                    <div v-if="domains.length === 0" class="rounded-lg bg-gray-50 p-6 text-center text-sm text-gray-500 dark:bg-gray-900/40">
                        {{ t('No domains have mail hosting enabled yet.') }}
                    </div>

                    <table v-else class="min-w-full divide-y divide-gray-200 dark:divide-gray-800">
                        <thead>
                            <tr>
                                <th class="px-4 py-2 text-left text-xs font-semibold uppercase text-gray-500">{{ t('Domain') }}</th>
                                <th class="px-4 py-2 text-left text-xs font-semibold uppercase text-gray-500">{{ t('Provider') }}</th>
                                <th class="px-4 py-2 text-left text-xs font-semibold uppercase text-gray-500">{{ t('MX') }}</th>
                                <th class="px-4 py-2"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 dark:divide-gray-800">
                            <tr v-for="domain in domains" :key="domain.id">
                                <td class="px-4 py-3 font-medium">{{ domain.fqdn }}</td>
                                <td class="px-4 py-3">
                                    <span
                                        class="rounded-full px-2 py-1 text-xs font-medium"
                                        :class="badgeClass(domain.mail_hosting)"
                                    >
                                        {{ domain.mail_hosting_label }}
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-sm text-gray-500">
                                    {{ domain.mail_remote_mx_host || '—' }}
                                </td>
                                <td class="px-4 py-3 text-right">
                                    <Link
                                        v-if="domain.mail_hosting === 'local' || domain.mail_hosting === 'zimbra'"
                                        :href="route('mail.mailboxes.index', domain.id)"
                                        class="text-brand-500 hover:underline"
                                    >
                                        {{ t('Manage mailboxes') }}
                                    </Link>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </AdminLayout>
        </SidebarProvider>
    </ThemeProvider>
</template>

<script setup>
import { computed } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import ThemeProvider from '@/Components/Layout/ThemeProvider.vue';
import SidebarProvider from '@/Components/Layout/SidebarProvider.vue';
import AdminLayout from '@/Components/Layout/AdminLayout.vue';
import PageBreadcrumb from '@/Components/Common/PageBreadcrumb.vue';
import Toast from '@/Components/UI/Toast.vue';
import { useI18n } from '@/Composables/useI18n';

const { t } = useI18n();
const props = defineProps({
    domains: { type: Array, required: true },
    features: { type: Object, required: true },
});

const page = usePage();
const breadcrumbs = computed(() => [
    { label: t('Mail') },
]);

function badgeClass(hosting) {
    return {
        local: 'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-300',
        zimbra: 'bg-purple-100 text-purple-700 dark:bg-purple-900/30 dark:text-purple-300',
        remote: 'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300',
        disabled: 'bg-gray-50 text-gray-400',
    }[hosting] || 'bg-gray-100 text-gray-700';
}
</script>
