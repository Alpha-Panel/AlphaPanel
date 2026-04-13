<template>
    <Head :title="t('Mail Server')" />
    <ThemeProvider>
        <SidebarProvider>
            <AdminLayout>
                <PageBreadcrumb :pageTitle="t('Mail Server')" />
                <Toast />

                <div class="space-y-4 md:space-y-6">
                    <!-- Info alert when mail is disabled -->
                    <div
                        v-if="!mailcowEnabled"
                        class="flex items-start gap-3 rounded-2xl border border-blue-light-200 bg-blue-light-50 p-4 dark:border-blue-light-500/20 dark:bg-blue-light-500/5"
                    >
                        <i class="bx bx-info-circle mt-0.5 text-xl text-blue-light-500"></i>
                        <div>
                            <p class="text-sm font-medium text-blue-light-800 dark:text-blue-light-300">
                                {{ t('Mail server is not enabled') }}
                            </p>
                            <p class="mt-1 text-sm text-blue-light-700 dark:text-blue-light-400">
                                {{ t('Configure the Mailcow integration in the mail settings to start managing mailboxes.') }}
                            </p>
                        </div>
                    </div>

                    <!-- Header -->
                    <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/3 md:p-6">
                        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                            <h3 class="flex items-center gap-2 text-lg font-semibold text-gray-800 dark:text-white/90">
                                <i class="bx bx-envelope text-xl text-brand-500"></i>
                                {{ t('Mail Domains') }}
                            </h3>
                            <div class="flex items-center gap-2">
                                <a
                                    v-if="webmailUrl"
                                    :href="webmailUrl"
                                    target="_blank"
                                    rel="noopener"
                                    class="inline-flex items-center gap-2 rounded-lg border border-gray-300 px-4 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-gray-800"
                                >
                                    <i class="bx bx-link-external text-base"></i>
                                    {{ t('Open Webmail') }}
                                </a>
                                <Link
                                    :href="route('mail.settings')"
                                    class="inline-flex items-center gap-2 rounded-lg border border-gray-300 px-4 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-gray-800"
                                >
                                    <i class="bx bx-cog text-base"></i>
                                    {{ t('Settings') }}
                                </Link>
                            </div>
                        </div>
                    </div>

                    <!-- Empty state -->
                    <div
                        v-if="mailDomains.length === 0"
                        class="rounded-2xl border border-gray-200 bg-white p-8 text-center dark:border-gray-800 dark:bg-white/3"
                    >
                        <i class="bx bx-envelope text-3xl text-gray-400"></i>
                        <p class="mt-2 text-gray-500 dark:text-gray-400">
                            {{ t('No mail-enabled domains found.') }}
                        </p>
                    </div>

                    <!-- Mail domains table -->
                    <div
                        v-else
                        class="rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/3"
                    >
                        <div class="overflow-x-auto">
                            <table class="w-full">
                                <thead>
                                    <tr class="border-b border-gray-200 dark:border-gray-800">
                                        <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-500 dark:text-gray-400 md:px-6">
                                            {{ t('Domain') }}
                                        </th>
                                        <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-500 dark:text-gray-400">
                                            {{ t('Mailboxes') }}
                                        </th>
                                        <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-500 dark:text-gray-400">
                                            {{ t('Aliases') }}
                                        </th>
                                        <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-500 dark:text-gray-400">
                                            {{ t('Status') }}
                                        </th>
                                        <th class="px-5 py-3 text-right text-xs font-medium uppercase text-gray-500 dark:text-gray-400 md:px-6">
                                            {{ t('Actions') }}
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr
                                        v-for="mailDomain in mailDomains"
                                        :key="mailDomain.id"
                                        class="border-t border-gray-200 hover:bg-gray-50 dark:border-gray-800 dark:hover:bg-white/2"
                                    >
                                        <td class="px-5 py-4 md:px-6">
                                            <Link
                                                :href="route('domains.mail.index', mailDomain.domain_id)"
                                                class="font-medium text-gray-800 hover:text-brand-500 dark:text-white/90"
                                            >
                                                {{ mailDomain.domain_name }}
                                            </Link>
                                        </td>
                                        <td class="px-5 py-4 text-sm text-gray-600 dark:text-gray-400">
                                            {{ mailDomain.mailboxes_count ?? 0 }}
                                        </td>
                                        <td class="px-5 py-4 text-sm text-gray-600 dark:text-gray-400">
                                            {{ mailDomain.aliases_count ?? 0 }}
                                        </td>
                                        <td class="px-5 py-4">
                                            <MailStatusBadge :active="mailDomain.is_active ?? false" />
                                        </td>
                                        <td class="px-5 py-4 text-right md:px-6">
                                            <Link
                                                :href="route('domains.mail.index', mailDomain.domain_id)"
                                                class="inline-flex items-center gap-1.5 rounded-lg bg-brand-500 px-3 py-1.5 text-xs font-medium text-white hover:bg-brand-600"
                                            >
                                                <i class="bx bx-cog text-sm"></i>
                                                {{ t('Manage') }}
                                            </Link>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </AdminLayout>
        </SidebarProvider>
    </ThemeProvider>
</template>

<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import ThemeProvider from '@/Components/Layout/ThemeProvider.vue';
import SidebarProvider from '@/Components/Layout/SidebarProvider.vue';
import AdminLayout from '@/Components/Layout/AdminLayout.vue';
import PageBreadcrumb from '@/Components/Common/PageBreadcrumb.vue';
import Toast from '@/Components/UI/Toast.vue';
import MailStatusBadge from '@/Components/Mail/MailStatusBadge.vue';
import { useI18n } from '@/Composables/useI18n';

defineProps<{
    mailDomains: Array<Record<string, any>>;
    mailcowEnabled: boolean;
    webmailUrl: string | null;
}>();

const { t } = useI18n();
</script>
