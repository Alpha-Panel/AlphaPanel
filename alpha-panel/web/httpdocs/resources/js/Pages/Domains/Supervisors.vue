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

                <div class="min-w-0 rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/3 md:p-6">
                    <div class="mb-5 flex items-center">
                        <h3 class="text-lg font-semibold text-gray-800 dark:text-white/90">
                            <i class="fa-brands fa-laravel mr-2 text-error-500"></i>
                            {{ t('Laravel Processes') }}
                        </h3>
                        <span class="ml-3 text-sm text-gray-500 dark:text-gray-400">{{ domain.fqdn }}</span>
                    </div>

                    <div class="mb-6 flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                        <p class="text-sm text-gray-500 dark:text-gray-400">
                            {{ t('Manage background processes for your Laravel application. Changes are applied immediately to the server.') }}
                        </p>
                        <div class="flex shrink-0 flex-wrap items-center gap-2">
                            <button
                                type="button"
                                @click="runOptimize"
                                :disabled="optimizeLoading || workersRestartLoading || actionLoading !== null || processRestartLoading !== null"
                                class="inline-flex h-9 items-center gap-2 rounded-lg border border-success-500/40 bg-success-500/10 px-3 text-sm font-medium text-success-700 shadow-theme-xs transition-colors hover:bg-success-500/20 disabled:cursor-not-allowed disabled:opacity-60 dark:text-success-300"
                            >
                                <i v-if="optimizeLoading" class="bx bx-loader-alt animate-spin text-base"></i>
                                <template v-else>
                                    <i class="bx bx-rocket text-base"></i>
                                    {{ t('Optimize Laravel') }}
                                </template>
                            </button>
                            <button
                                type="button"
                                @click="restartFrankenphpWorkers"
                                :disabled="workersRestartLoading || optimizeLoading || actionLoading !== null || processRestartLoading !== null"
                                class="inline-flex h-9 items-center gap-2 rounded-lg border border-blue-light-500/40 bg-blue-light-500/10 px-3 text-sm font-medium text-blue-light-700 shadow-theme-xs transition-colors hover:bg-blue-light-500/20 disabled:cursor-not-allowed disabled:opacity-60 dark:text-blue-light-300"
                            >
                                <i v-if="workersRestartLoading" class="bx bx-loader-alt animate-spin text-base"></i>
                                <template v-else>
                                    <i class="bx bx-refresh text-base"></i>
                                    {{ t('Restart FrankenPHP Workers') }}
                                </template>
                            </button>
                        </div>
                    </div>

                    <div class="space-y-4">
                        <div
                            v-for="process in localProcesses"
                            :key="process.type"
                            class="rounded-xl border p-4 transition-colors"
                            :class="process.enabled
                                ? 'border-success-500/30 bg-success-500/5 dark:border-success-500/20 dark:bg-success-500/5'
                                : 'border-gray-200 bg-gray-50 dark:border-gray-800 dark:bg-white/2'"
                        >
                            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:gap-4">
                                <div class="flex min-w-0 items-center gap-3 sm:flex-1 sm:gap-4">
                                    <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg"
                                        :class="process.enabled
                                            ? 'bg-success-500/15 text-success-600 dark:text-success-400'
                                            : 'bg-gray-200 text-gray-500 dark:bg-gray-800 dark:text-gray-400'"
                                    >
                                        <i :class="processIcon(process.type)" class="text-lg"></i>
                                    </div>

                                    <div class="min-w-0 flex-1">
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
                                </div>

                                <div class="flex flex-wrap items-center gap-2 sm:shrink-0 sm:gap-3">
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
                                                :disabled="actionLoading === process.type || processRestartLoading === process.type || workersRestartLoading || optimizeLoading"
                                                class="h-8 w-18 appearance-none rounded-md border border-gray-300 bg-white pl-2.5 pr-7 text-sm font-semibold text-gray-700 transition focus:border-brand-300 focus:outline-hidden focus:ring-3 focus:ring-brand-500/10 disabled:cursor-not-allowed disabled:opacity-60 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200"
                                            >
                                                <option v-for="n in 10" :key="n" :value="n">{{ n }}</option>
                                            </select>
                                            <i
                                                class="bx bx-chevron-down pointer-events-none absolute right-1.5 top-1/2 -translate-y-1/2 text-base text-gray-400 dark:text-gray-500"
                                            ></i>
                                        </div>
                                    </div>

                                    <button
                                        v-if="process.enabled"
                                        type="button"
                                        @click="restartProcess(process)"
                                        :disabled="actionLoading !== null || processRestartLoading === process.type || workersRestartLoading || optimizeLoading"
                                        class="inline-flex h-9 items-center gap-2 rounded-lg border border-brand-500/35 bg-brand-500/10 px-3 text-sm font-medium text-brand-700 shadow-theme-xs transition-colors hover:bg-brand-500/20 disabled:cursor-not-allowed disabled:opacity-60 dark:text-brand-300"
                                    >
                                        <i v-if="processRestartLoading === process.type" class="bx bx-loader-alt animate-spin text-base"></i>
                                        <template v-else>
                                            <i class="bx bx-refresh text-base"></i>
                                            {{ t('Restart') }}
                                        </template>
                                    </button>

                                    <button
                                        @click="toggleProcess(process)"
                                        :disabled="actionLoading === process.type || processRestartLoading !== null || workersRestartLoading || optimizeLoading"
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

                    <!-- Artisan Command Runner -->
                    <div class="mt-6 rounded-xl border border-gray-200 bg-gray-50 p-5 dark:border-gray-800 dark:bg-white/2">
                        <h4 class="mb-3 flex items-center gap-2 text-sm font-semibold text-gray-800 dark:text-white/90">
                            <i class="bx bx-terminal text-base text-brand-500"></i>
                            {{ t('Run Artisan Command') }}
                        </h4>
                        <p class="mb-3 text-xs text-gray-500 dark:text-gray-400">
                            {{ t('Execute any artisan command for this domain. The command runs as the domain FTP user in the appropriate container.') }}
                        </p>
                        <div class="flex gap-2">
                            <input
                                v-model="artisanCommand"
                                type="text"
                                class="flex-1 rounded-lg border border-gray-300 bg-white px-3 py-2 font-mono text-sm text-gray-800 placeholder:text-gray-400 focus:border-brand-300 focus:outline-hidden focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200"
                                placeholder="php artisan ..."
                                :disabled="artisanLoading"
                                @keydown.enter="runArtisanCommand"
                            />
                            <button
                                type="button"
                                @click="runArtisanCommand"
                                :disabled="artisanLoading || !artisanCommand.trim()"
                                class="inline-flex h-10 items-center gap-2 rounded-lg bg-brand-500 px-4 text-sm font-medium text-white shadow-theme-xs hover:bg-brand-600 disabled:cursor-not-allowed disabled:opacity-60"
                            >
                                <i v-if="artisanLoading" class="bx bx-loader-alt animate-spin text-base"></i>
                                <template v-else>
                                    <i class="bx bx-play text-base"></i>
                                    {{ t('Run') }}
                                </template>
                            </button>
                        </div>
                    </div>

                    <!-- Artisan Output -->
                    <div
                        v-if="artisanOutput !== null"
                        class="mt-4 rounded-xl border border-gray-800 bg-gray-950 dark:border-gray-700"
                        style="max-width: 100%; overflow: hidden;"
                    >
                        <div class="flex items-center justify-between border-b border-gray-800 px-5 py-2.5">
                            <span class="text-xs font-medium text-gray-400">{{ t('Output') }}</span>
                            <span
                                class="rounded-full px-2.5 py-0.5 text-xs font-semibold"
                                :class="artisanExitCode === 0
                                    ? 'bg-success-500/15 text-success-400'
                                    : 'bg-error-500/15 text-error-400'"
                            >
                                {{ t('Exit Code') }}: {{ artisanExitCode }}
                            </span>
                        </div>
                        <div class="p-5" style="max-height: 70vh; overflow-x: auto; overflow-y: auto;">
                            <pre class="font-mono text-[13px] leading-6 text-gray-200" style="width: max-content; min-width: 100%;">{{ artisanOutput }}</pre>
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
const processRestartLoading = ref<string | null>(null);
const workersRestartLoading = ref(false);
const optimizeLoading = ref(false);

const localProcesses = reactive<Process[]>(props.processes.map((p) => ({ ...p })));

// Artisan command runner state
const artisanCommand = ref('php artisan ');
const artisanOutput = ref<string | null>(null);
const artisanExitCode = ref<number | null>(null);
const artisanLoading = ref(false);

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
    if (actionLoading.value !== null || processRestartLoading.value !== null || workersRestartLoading.value || optimizeLoading.value) return;
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
    if (actionLoading.value !== null || processRestartLoading.value !== null || workersRestartLoading.value || optimizeLoading.value) return;
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

const restartProcess = async (process: Process): Promise<void> => {
    if (actionLoading.value !== null || processRestartLoading.value !== null || workersRestartLoading.value || optimizeLoading.value) return;
    processRestartLoading.value = process.type;

    try {
        const response = await axios.post(route('domains.supervisor.restart', props.domain.id), {
            type: process.type,
        });

        addToast('success', response.data.message);
    } catch (error: any) {
        addToast('error', error.response?.data?.message ?? t('Operation failed.'));
    } finally {
        processRestartLoading.value = null;
    }
};

const restartFrankenphpWorkers = async (): Promise<void> => {
    if (actionLoading.value !== null || processRestartLoading.value !== null || workersRestartLoading.value || optimizeLoading.value) return;
    workersRestartLoading.value = true;

    try {
        const response = await axios.post(route('domains.supervisor.workers.restart', props.domain.id));
        addToast('success', response.data.message);
    } catch (error: any) {
        addToast('error', error.response?.data?.message ?? t('Operation failed.'));
    } finally {
        workersRestartLoading.value = false;
    }
};

const runOptimize = async (): Promise<void> => {
    if (actionLoading.value !== null || processRestartLoading.value !== null || workersRestartLoading.value || optimizeLoading.value) return;
    optimizeLoading.value = true;

    try {
        const response = await axios.post(route('domains.supervisor.optimize', props.domain.id));
        addToast('success', response.data.message);
    } catch (error: any) {
        addToast('error', error.response?.data?.message ?? t('Operation failed.'));
    } finally {
        optimizeLoading.value = false;
    }
};

const runArtisanCommand = async (): Promise<void> => {
    if (artisanLoading.value || !artisanCommand.value.trim()) return;
    artisanLoading.value = true;
    artisanOutput.value = null;
    artisanExitCode.value = null;

    try {
        const response = await axios.post(
            route('domains.supervisor.artisan', props.domain.id),
            { command: artisanCommand.value.trim() },
        );

        artisanOutput.value = response.data.output ?? '';
        artisanExitCode.value = response.data.exit_code ?? null;

        if (response.data.status === 'success') {
            addToast('success', response.data.message);
        } else {
            addToast('error', response.data.message);
        }
    } catch (error: any) {
        artisanOutput.value = error.response?.data?.output ?? error.response?.data?.message ?? t('Operation failed.');
        artisanExitCode.value = error.response?.data?.exit_code ?? -1;
        addToast('error', error.response?.data?.message ?? t('Operation failed.'));
    } finally {
        artisanLoading.value = false;
    }
};
</script>
