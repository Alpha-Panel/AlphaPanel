<template>
    <Head :title="t('Mail Settings')" />
    <ThemeProvider>
        <SidebarProvider>
            <AdminLayout>
                <PageBreadcrumb
                    :pageTitle="t('Mail Settings')"
                    :items="breadcrumbs"
                    :backHref="route('mail.index')"
                />
                <Toast />

                <div class="space-y-4 md:space-y-6">
                    <!-- Connection status -->
                    <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/3 md:p-6">
                        <h3 class="mb-4 flex items-center gap-2 text-lg font-semibold text-gray-800 dark:text-white/90">
                            <i class="bx bx-server text-xl text-brand-500"></i>
                            {{ t('Mail Server Connection') }}
                        </h3>

                        <div class="space-y-4">
                            <!-- Status indicator -->
                            <div class="flex items-center gap-3">
                                <span
                                    class="inline-flex h-3 w-3 rounded-full"
                                    :class="connected ? 'bg-success-500' : 'bg-error-500'"
                                />
                                <span class="text-sm font-medium" :class="connected ? 'text-success-600 dark:text-success-400' : 'text-error-600 dark:text-error-400'">
                                    {{ connected ? t('Connected') : t('Not Connected') }}
                                </span>
                            </div>

                            <!-- Details -->
                            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                <div class="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
                                    <p class="mb-1 text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                        {{ t('Hostname') }}
                                    </p>
                                    <p class="text-sm font-medium text-gray-800 dark:text-white/90">
                                        {{ hostname || t('Not configured') }}
                                    </p>
                                </div>

                                <div class="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
                                    <p class="mb-1 text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                        {{ t('Webmail Domain') }}
                                    </p>
                                    <p class="text-sm font-medium text-gray-800 dark:text-white/90">
                                        {{ webmailDomain || t('Not configured') }}
                                    </p>
                                </div>
                            </div>

                            <!-- API status -->
                            <div class="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
                                <p class="mb-1 text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                    {{ t('API Status') }}
                                </p>
                                <div class="flex items-center gap-2">
                                    <i
                                        :class="connected
                                            ? 'bx bx-check-circle text-success-500'
                                            : 'bx bx-x-circle text-error-500'"
                                        class="text-lg"
                                    ></i>
                                    <span class="text-sm text-gray-700 dark:text-gray-300">
                                        {{ connected ? t('Mailcow API is reachable') : t('Mailcow API is not reachable') }}
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </AdminLayout>
        </SidebarProvider>
    </ThemeProvider>
</template>

<script setup lang="ts">
import { computed } from 'vue';
import { Head } from '@inertiajs/vue3';
import ThemeProvider from '@/Components/Layout/ThemeProvider.vue';
import SidebarProvider from '@/Components/Layout/SidebarProvider.vue';
import AdminLayout from '@/Components/Layout/AdminLayout.vue';
import PageBreadcrumb from '@/Components/Common/PageBreadcrumb.vue';
import Toast from '@/Components/UI/Toast.vue';
import { useI18n } from '@/Composables/useI18n';

defineProps<{
    connected: boolean;
    hostname: string | null;
    webmailDomain: string | null;
}>();

const { t } = useI18n();

const breadcrumbs = computed(() => [
    { label: t('Mail Server'), href: route('mail.index') },
    { label: t('Settings') },
]);
</script>
