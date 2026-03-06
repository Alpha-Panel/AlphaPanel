<template>
    <Head :title="`${t('Laravel Processes')} - ${domain.fqdn}`" />
    <ThemeProvider>
        <SidebarProvider>
            <AdminLayout>
                <PageBreadcrumb
                    :pageTitle="t('Laravel Processes')"
                    :items="breadcrumbs"
                    :backHref="route('domains.show', domain.id)"
                />
                <Toast />

                <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03] md:p-6">
                    <div class="mb-5 flex items-center">
                        <h3 class="text-lg font-semibold text-gray-800 dark:text-white/90">
                            <i class="fa-brands fa-laravel mr-2 text-error-500"></i>
                            {{ t('Supervisor Processes') }}
                        </h3>
                        <span class="ml-3 text-sm text-gray-500 dark:text-gray-400">{{ domain.fqdn }}</span>
                    </div>

                    <p class="mb-6 text-sm text-gray-500 dark:text-gray-400">
                        {{ t('Manage background processes for your Laravel application. Changes are applied immediately to the server.') }}
                    </p>

                    <div class="space-y-4">
                        <div
                            v-for="process in localProcesses"
                            :key="process.type"
                            class="rounded-xl border p-4 transition-colors"
                            :class="process.enabled
                                ? 'border-success-500/30 bg-success-500/5 dark:border-success-500/20 dark:bg-success-500/5'
                                : 'border-gray-200 bg-gray-50 dark:border-gray-800 dark:bg-white/[0.02]'"
                        >
                            <div class="flex items-center gap-4">
                                <div class="flex h-10 w-10 items-center justify-center rounded-lg"
                                    :class="process.enabled
                                        ? 'bg-success-500/15 text-success-600 dark:text-success-400'
                                        : 'bg-gray-200 text-gray-500 dark:bg-gray-800 dark:text-gray-400'"
                                >
                                    <i :class="processIcon(process.type)" class="text-lg"></i>
                                </div>

                                <div class="flex-1">
                                    <div class="flex items-center gap-2">
                                        <h4 class="font-semibold text-gray-800 dark:text-white/90">{{ process.label }}</h4>
                                        <span
                                            class="inline-flex rounded-full px-2 py-0.5 text-[10px] font-semibold"
                                            :class="process.enabled
                                                ? 'bg-success-500/20 text-success-600 dark:text-success-300'
                                                : 'bg-gray-200 text-gray-600 dark:bg-gray-700 dark:text-gray-400'"
                                        >
                                            {{ process.enabled ? t('Active') : t('Inactive') }}
                                        </span>
                                    </div>
                                    <p class="text-xs text-gray-500 dark:text-gray-400">{{ processDescription(process.type) }}</p>
                                </div>

                                <div class="flex items-center gap-3">
                                    <div
                                        v-if="process.supports_num_procs && process.enabled"
                                        class="flex items-center gap-2 rounded-lg border border-gray-200 bg-white/80 px-2.5 py-1.5 shadow-theme-xs dark:border-gray-700 dark:bg-gray-900/70"
                                    >
                                        <label class="text-[11px] font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                            {{ t('Workers') }}
                                        </label>
                                        <div class="relative">
                                            <select
                                                v-model.number="process.num_procs"
                                                @change="updateProcess(process)"
                                                :disabled="actionLoading === process.type"
                                                class="h-8 w-[4.5rem] appearance-none rounded-md border border-gray-300 bg-white pl-2.5 pr-7 text-sm font-semibold text-gray-700 transition focus:border-brand-300 focus:outline-hidden focus:ring-3 focus:ring-brand-500/10 disabled:cursor-not-allowed disabled:opacity-60 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200"
                                            >
                                                <option v-for="n in 10" :key="n" :value="n">{{ n }}</option>
                                            </select>
                                            <i
                                                class="bx bx-chevron-down pointer-events-none absolute right-1.5 top-1/2 -translate-y-1/2 text-base text-gray-400 dark:text-gray-500"
                                            ></i>
                                        </div>
                                    </div>

                                    <button
                                        @click="toggleProcess(process)"
                                        :disabled="actionLoading === process.type"
                                        class="inline-flex h-9 items-center gap-2 rounded-lg px-4 text-sm font-medium shadow-theme-xs transition-colors disabled:opacity-50"
                                        :class="process.enabled
                                            ? 'border border-error-500/40 text-error-600 hover:bg-error-500/10 dark:text-error-400'
                                            : 'bg-brand-500 text-white hover:bg-brand-600'"
                                    >
                                        <i v-if="actionLoading === process.type" class="bx bx-loader-alt animate-spin text-base"></i>
                                        <template v-else>
                                            <i :class="process.enabled ? 'bx bx-stop' : 'bx bx-play'" class="text-base"></i>
                                            {{ process.enabled ? t('Disable') : t('Enable') }}
                                        </template>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="mt-6 flex">
                        <Link
                            :href="route('domains.show', domain.id)"
                            class="inline-flex items-center gap-2 rounded-lg border border-gray-300 bg-white px-4 py-2.5 text-sm font-medium text-gray-700 shadow-theme-xs hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-400"
                        >
                            <i class="bx bx-arrow-back text-base"></i>
                            {{ t('Back to Domain') }}
                        </Link>
                    </div>
                </div>
            </AdminLayout>
        </SidebarProvider>
    </ThemeProvider>
</template>

<script setup lang="ts">
import { computed, ref, reactive } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import axios from 'axios';
import ThemeProvider from '@/Components/Layout/ThemeProvider.vue';
import SidebarProvider from '@/Components/Layout/SidebarProvider.vue';
import AdminLayout from '@/Components/Layout/AdminLayout.vue';
import PageBreadcrumb from '@/Components/Common/PageBreadcrumb.vue';
import Toast from '@/Components/UI/Toast.vue';
import { useToast } from '@/Composables/useToast';
import { useI18n } from '@/Composables/useI18n';

interface Process {
    type: string;
    label: string;
    enabled: boolean;
    num_procs: number;
    supports_num_procs: boolean;
}

const props = defineProps<{
    domain: { id: number; fqdn: string };
    processes: Process[];
}>();

const { t } = useI18n();
const { addToast } = useToast();
const actionLoading = ref<string | null>(null);

const localProcesses = reactive<Process[]>(props.processes.map((p) => ({ ...p })));

const breadcrumbs = computed(() => [
    { label: t('Domains'), href: route('domains.index') },
    { label: props.domain.fqdn, href: route('domains.show', props.domain.id) },
    { label: t('Laravel Processes') },
]);

const processIcon = (type: string): string => {
    switch (type) {
        case 'queue': return 'bx bx-list-check';
        case 'reverb': return 'bx bx-broadcast';
        case 'pulse': return 'bx bx-pulse';
        case 'horizon': return 'bx bx-bar-chart-alt-2';
        default: return 'bx bx-cog';
    }
};

const processDescription = (type: string): string => {
    switch (type) {
        case 'queue': return t('Processes queued jobs in the background. Supports multiple worker processes.');
        case 'reverb': return t('WebSocket server for real-time broadcasting with Laravel Reverb.');
        case 'pulse': return t('Application performance monitoring and metrics dashboard.');
        case 'horizon': return t('Redis queue dashboard and manager with auto-balancing.');
        default: return '';
    }
};

const toggleProcess = async (process: Process): Promise<void> => {
    if (actionLoading.value !== null) return;
    actionLoading.value = process.type;

    try {
        const response = await axios.post(route('domains.supervisor.update', props.domain.id), {
            type: process.type,
            enabled: !process.enabled,
            num_procs: process.num_procs,
        });

        process.enabled = response.data.enabled;
        process.num_procs = response.data.num_procs;
        addToast('success', response.data.message);
    } catch (error: any) {
        addToast('error', error.response?.data?.message ?? t('Operation failed.'));
    } finally {
        actionLoading.value = null;
    }
};

const updateProcess = async (process: Process): Promise<void> => {
    if (actionLoading.value !== null) return;
    actionLoading.value = process.type;

    try {
        const response = await axios.post(route('domains.supervisor.update', props.domain.id), {
            type: process.type,
            enabled: process.enabled,
            num_procs: process.num_procs,
        });

        process.num_procs = response.data.num_procs;
        addToast('success', response.data.message);
    } catch (error: any) {
        addToast('error', error.response?.data?.message ?? t('Operation failed.'));
    } finally {
        actionLoading.value = null;
    }
};
</script>
