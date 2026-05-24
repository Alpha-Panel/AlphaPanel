<template>
    <Head :title="`${t('Mailboxes')} - ${domain.fqdn}`" />
    <ThemeProvider>
        <SidebarProvider>
            <AdminLayout>
                <PageBreadcrumb
                    :pageTitle="t('Mailboxes')"
                    :items="breadcrumbs"
                    :backHref="route('mail.index')"
                />
                <Toast />

                <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/3 md:p-6">
                    <div class="mb-4 flex items-center justify-between">
                        <div>
                            <h3 class="text-lg font-semibold text-gray-800 dark:text-white/90">{{ domain.fqdn }}</h3>
                            <p class="text-sm text-gray-500">{{ t('Provider') }}: <strong>{{ provider }}</strong></p>
                        </div>
                        <Link
                            v-if="!providerError"
                            :href="route('mail.mailboxes.create', domain.id)"
                            class="rounded-lg bg-brand-500 px-3 py-2 text-sm font-medium text-white hover:bg-brand-600"
                        >
                            {{ t('Create mailbox') }}
                        </Link>
                    </div>

                    <div
                        v-if="providerError"
                        class="mb-4 rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800 dark:border-amber-700 dark:bg-amber-900/20 dark:text-amber-200"
                    >
                        <p class="font-medium">{{ t('Mail provider unavailable') }}</p>
                        <p class="mt-1">{{ providerError }}</p>
                        <Link
                            :href="route('mail.settings.edit')"
                            class="mt-2 inline-block text-amber-700 hover:underline dark:text-amber-300"
                        >
                            {{ t('Open Mail Settings') }} →
                        </Link>
                    </div>

                    <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-800">
                        <thead>
                            <tr>
                                <th class="px-4 py-2 text-left text-xs font-semibold uppercase text-gray-500">{{ t('Address') }}</th>
                                <th class="px-4 py-2 text-left text-xs font-semibold uppercase text-gray-500">{{ t('Display name') }}</th>
                                <th class="px-4 py-2 text-left text-xs font-semibold uppercase text-gray-500">{{ t('Quota') }}</th>
                                <th class="px-4 py-2 text-left text-xs font-semibold uppercase text-gray-500">{{ t('Status') }}</th>
                                <th class="px-4 py-2"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 dark:divide-gray-800">
                            <tr v-for="mailbox in mailboxes" :key="mailbox.address">
                                <td class="px-4 py-3 font-medium">{{ mailbox.address }}</td>
                                <td class="px-4 py-3 text-sm text-gray-500">{{ mailbox.display_name || '—' }}</td>
                                <td class="px-4 py-3 text-sm text-gray-500">
                                    {{ mailbox.quota_used_bytes ? formatBytes(mailbox.quota_used_bytes) : '—' }} /
                                    {{ mailbox.quota_bytes ? formatBytes(mailbox.quota_bytes) : t('Unlimited') }}
                                </td>
                                <td class="px-4 py-3">
                                    <span
                                        class="rounded-full px-2 py-1 text-xs font-medium"
                                        :class="mailbox.active ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700'"
                                    >
                                        {{ mailbox.active ? t('Active') : t('Locked') }}
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-right space-x-3">
                                    <button class="text-brand-500 hover:underline" @click="openEdit(mailbox)">{{ t('Edit') }}</button>
                                    <button class="text-red-500 hover:underline" @click="confirmDelete(mailbox)">{{ t('Delete') }}</button>
                                </td>
                            </tr>
                            <tr v-if="mailboxes.length === 0">
                                <td colspan="5" class="px-4 py-6 text-center text-sm text-gray-500">
                                    {{ t('No mailboxes yet.') }}
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
import { Head, Link, router } from '@inertiajs/vue3';
import ThemeProvider from '@/Components/Layout/ThemeProvider.vue';
import SidebarProvider from '@/Components/Layout/SidebarProvider.vue';
import AdminLayout from '@/Components/Layout/AdminLayout.vue';
import PageBreadcrumb from '@/Components/Common/PageBreadcrumb.vue';
import Toast from '@/Components/UI/Toast.vue';
import { useI18n } from '@/Composables/useI18n';

const { t } = useI18n();
const props = defineProps({
    domain: { type: Object, required: true },
    provider: { type: String, required: true },
    mailboxes: { type: Array, required: true },
    provider_error: { type: String, default: null },
});
const providerError = computed(() => props.provider_error);

const breadcrumbs = computed(() => [
    { label: t('Domains'), href: route('domains.index') },
    { label: props.domain.fqdn, href: route('domains.show', props.domain.id) },
    { label: t('Mailboxes') },
]);

function formatBytes(bytes) {
    if (!bytes) return '0';
    const units = ['B', 'KB', 'MB', 'GB', 'TB'];
    let i = 0;
    let n = bytes;
    while (n >= 1024 && i < units.length - 1) {
        n /= 1024;
        i++;
    }
    return `${n.toFixed(1)} ${units[i]}`;
}

function openEdit(mailbox) {
    const localPart = mailbox.address.split('@')[0];
    router.get(route('mail.mailboxes.index', props.domain.id), {}, {
        preserveState: false,
        replace: true,
        onSuccess: () => router.visit(route('mail.mailboxes.index', props.domain.id) + `?edit=${localPart}`),
    });
}

function confirmDelete(mailbox) {
    if (!confirm(t('Delete mailbox?'))) return;
    const localPart = mailbox.address.split('@')[0];
    router.delete(route('mail.mailboxes.destroy', [props.domain.id, localPart]));
}
</script>
