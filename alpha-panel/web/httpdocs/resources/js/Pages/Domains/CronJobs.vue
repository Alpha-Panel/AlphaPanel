<template>
    <Head :title="`${t('Cron Jobs')} - ${domain.fqdn}`" />
    <ThemeProvider>
        <SidebarProvider>
            <AdminLayout>
                <PageBreadcrumb
                    :pageTitle="t('Cron Jobs')"
                    :items="breadcrumbs"
                    :backHref="route('domains.show', domain.id)"
                />
                <Toast />

                <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/3 md:p-6">
                    <div class="mb-5 flex items-center justify-between">
                        <div class="flex items-center">
                            <h3 class="text-lg font-semibold text-gray-800 dark:text-white/90">
                                <i class="fa-solid fa-clock mr-2 text-brand-500"></i>
                                {{ t('Cron Jobs') }}
                            </h3>
                            <span class="ml-3 text-sm text-gray-500 dark:text-gray-400">{{ domain.fqdn }}</span>
                        </div>
                        <button
                            type="button"
                            @click="showAddForm = !showAddForm"
                            class="inline-flex h-9 items-center gap-2 rounded-lg bg-brand-500 px-4 text-sm font-medium text-white shadow-theme-xs hover:bg-brand-600"
                        >
                            <i :class="showAddForm ? 'bx bx-x' : 'bx bx-plus'" class="text-base"></i>
                            {{ showAddForm ? t('Cancel') : t('Add Cron Job') }}
                        </button>
                    </div>

                    <p class="mb-6 text-sm text-gray-500 dark:text-gray-400">
                        {{ t('Schedule recurring commands for your domain. Commands run inside the FrankenPHP container in your domain\'s web root directory.') }}
                    </p>

                    <!-- Add Form -->
                    <div
                        v-if="showAddForm"
                        class="mb-6 rounded-xl border border-brand-500/30 bg-brand-500/5 p-5 dark:border-brand-500/20 dark:bg-brand-500/5"
                    >
                        <h4 class="mb-4 font-semibold text-gray-800 dark:text-white/90">{{ t('New Cron Job') }}</h4>
                        <div class="space-y-4">
                            <div>
                                <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-300">
                                    {{ t('Command') }} <span class="text-error-500">*</span>
                                </label>
                                <input
                                    v-model="addForm.command"
                                    type="text"
                                    :placeholder="t('e.g. php artisan schedule:run')"
                                    class="w-full rounded-lg border border-gray-300 bg-white px-3.5 py-2.5 font-mono text-sm text-gray-800 placeholder:text-gray-400 focus:border-brand-300 focus:outline-hidden focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white dark:placeholder:text-gray-500"
                                />
                                <p v-if="addErrors.command" class="mt-1 text-xs text-error-500">{{ addErrors.command }}</p>
                            </div>

                            <div>
                                <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-300">
                                    {{ t('Schedule') }} <span class="text-error-500">*</span>
                                </label>
                                <div class="flex flex-col gap-3 sm:flex-row sm:items-start">
                                    <div class="flex-1">
                                        <input
                                            v-model="addForm.schedule"
                                            type="text"
                                            placeholder="*/5 * * * *"
                                            class="w-full rounded-lg border border-gray-300 bg-white px-3.5 py-2.5 font-mono text-sm text-gray-800 placeholder:text-gray-400 focus:border-brand-300 focus:outline-hidden focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white dark:placeholder:text-gray-500"
                                        />
                                        <p v-if="addErrors.schedule" class="mt-1 text-xs text-error-500">{{ addErrors.schedule }}</p>
                                    </div>
                                    <div class="relative">
                                        <select
                                            @change="applyPreset($event, 'add')"
                                            class="h-[42px] w-full appearance-none rounded-lg border border-gray-300 bg-white py-2.5 pl-3.5 pr-8 text-sm text-gray-700 focus:border-brand-300 focus:outline-hidden focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200 sm:w-auto"
                                        >
                                            <option value="">{{ t('Presets') }}</option>
                                            <option v-for="preset in presets" :key="preset.value" :value="preset.value">
                                                {{ preset.label }}
                                            </option>
                                        </select>
                                        <i class="bx bx-chevron-down pointer-events-none absolute right-2.5 top-1/2 -translate-y-1/2 text-base text-gray-400 dark:text-gray-500"></i>
                                    </div>
                                </div>
                            </div>

                            <div>
                                <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-300">
                                    {{ t('Description') }}
                                </label>
                                <input
                                    v-model="addForm.description"
                                    type="text"
                                    :placeholder="t('Optional description')"
                                    class="w-full rounded-lg border border-gray-300 bg-white px-3.5 py-2.5 text-sm text-gray-800 placeholder:text-gray-400 focus:border-brand-300 focus:outline-hidden focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white dark:placeholder:text-gray-500"
                                />
                            </div>

                            <div class="flex justify-end gap-2">
                                <button
                                    type="button"
                                    @click="showAddForm = false"
                                    class="inline-flex h-9 items-center rounded-lg border border-gray-300 bg-white px-4 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-400"
                                >
                                    {{ t('Cancel') }}
                                </button>
                                <button
                                    type="button"
                                    @click="storeCronJob"
                                    :disabled="addLoading"
                                    class="inline-flex h-9 items-center gap-2 rounded-lg bg-brand-500 px-4 text-sm font-medium text-white hover:bg-brand-600 disabled:opacity-50"
                                >
                                    <i v-if="addLoading" class="bx bx-loader-alt animate-spin text-base"></i>
                                    {{ t('Create') }}
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Empty State -->
                    <div
                        v-if="localCronJobs.length === 0 && !showAddForm"
                        class="py-12 text-center"
                    >
                        <i class="fa-solid fa-clock mb-3 text-4xl text-gray-300 dark:text-gray-600"></i>
                        <p class="text-sm text-gray-500 dark:text-gray-400">{{ t('No cron jobs configured yet.') }}</p>
                    </div>

                    <!-- Cron Jobs List -->
                    <div v-else class="space-y-4">
                        <div
                            v-for="job in localCronJobs"
                            :key="job.id"
                            class="rounded-xl border p-4 transition-colors"
                            :class="job.enabled
                                ? 'border-success-500/30 bg-success-500/5 dark:border-success-500/20 dark:bg-success-500/5'
                                : 'border-gray-200 bg-gray-50 dark:border-gray-800 dark:bg-white/2'"
                        >
                            <!-- Edit Mode -->
                            <div v-if="editingId === job.id" class="space-y-3">
                                <div>
                                    <label class="mb-1 block text-xs font-medium text-gray-500 dark:text-gray-400">{{ t('Command') }}</label>
                                    <input
                                        v-model="editForm.command"
                                        type="text"
                                        class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 font-mono text-sm text-gray-800 focus:border-brand-300 focus:outline-hidden focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white"
                                    />
                                    <p v-if="editErrors.command" class="mt-1 text-xs text-error-500">{{ editErrors.command }}</p>
                                </div>
                                <div class="flex gap-3">
                                    <div class="flex-1">
                                        <label class="mb-1 block text-xs font-medium text-gray-500 dark:text-gray-400">{{ t('Schedule') }}</label>
                                        <input
                                            v-model="editForm.schedule"
                                            type="text"
                                            class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 font-mono text-sm text-gray-800 focus:border-brand-300 focus:outline-hidden focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white"
                                        />
                                        <p v-if="editErrors.schedule" class="mt-1 text-xs text-error-500">{{ editErrors.schedule }}</p>
                                    </div>
                                    <div>
                                        <label class="mb-1 block text-xs font-medium text-gray-500 dark:text-gray-400">{{ t('Preset') }}</label>
                                        <div class="relative">
                                            <select
                                                @change="applyPreset($event, 'edit')"
                                                class="h-[38px] appearance-none rounded-lg border border-gray-300 bg-white py-2 pl-3 pr-7 text-sm text-gray-700 focus:border-brand-300 focus:outline-hidden focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200"
                                            >
                                                <option value="">{{ t('Presets') }}</option>
                                                <option v-for="preset in presets" :key="preset.value" :value="preset.value">
                                                    {{ preset.label }}
                                                </option>
                                            </select>
                                            <i class="bx bx-chevron-down pointer-events-none absolute right-1.5 top-1/2 -translate-y-1/2 text-base text-gray-400"></i>
                                        </div>
                                    </div>
                                </div>
                                <div>
                                    <label class="mb-1 block text-xs font-medium text-gray-500 dark:text-gray-400">{{ t('Description') }}</label>
                                    <input
                                        v-model="editForm.description"
                                        type="text"
                                        class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-800 focus:border-brand-300 focus:outline-hidden focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white"
                                    />
                                </div>
                                <div class="flex justify-end gap-2">
                                    <button
                                        type="button"
                                        @click="cancelEdit"
                                        class="inline-flex h-8 items-center rounded-lg border border-gray-300 px-3 text-xs font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-400"
                                    >
                                        {{ t('Cancel') }}
                                    </button>
                                    <button
                                        type="button"
                                        @click="updateCronJob(job)"
                                        :disabled="editLoading"
                                        class="inline-flex h-8 items-center gap-1.5 rounded-lg bg-brand-500 px-3 text-xs font-medium text-white hover:bg-brand-600 disabled:opacity-50"
                                    >
                                        <i v-if="editLoading" class="bx bx-loader-alt animate-spin text-sm"></i>
                                        {{ t('Save') }}
                                    </button>
                                </div>
                            </div>

                            <!-- View Mode -->
                            <div v-else>
                                <div class="flex items-start gap-4">
                                    <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg"
                                        :class="job.enabled
                                            ? 'bg-success-500/15 text-success-600 dark:text-success-400'
                                            : 'bg-gray-200 text-gray-500 dark:bg-gray-800 dark:text-gray-400'"
                                    >
                                        <i class="fa-solid fa-clock text-lg"></i>
                                    </div>

                                    <div class="min-w-0 flex-1">
                                        <div class="flex items-center gap-2">
                                            <code class="max-w-full truncate rounded bg-gray-100 px-2 py-0.5 text-sm font-medium text-gray-800 dark:bg-gray-800 dark:text-white/90">
                                                {{ job.command }}
                                            </code>
                                            <span
                                                class="inline-flex shrink-0 rounded-full px-2 py-0.5 text-[10px] font-semibold"
                                                :class="job.enabled
                                                    ? 'bg-success-500/20 text-success-600 dark:text-success-300'
                                                    : 'bg-gray-200 text-gray-600 dark:bg-gray-700 dark:text-gray-400'"
                                            >
                                                {{ job.enabled ? t('Active') : t('Inactive') }}
                                            </span>
                                        </div>
                                        <div class="mt-1 flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-gray-500 dark:text-gray-400">
                                            <span>
                                                <i class="bx bx-time-five mr-0.5"></i>
                                                <code class="text-gray-600 dark:text-gray-300">{{ job.schedule }}</code>
                                                <span v-if="job.schedule_human !== job.schedule" class="ml-1 text-gray-400">
                                                    ({{ job.schedule_human }})
                                                </span>
                                            </span>
                                            <span v-if="job.description" class="text-gray-400 dark:text-gray-500">
                                                {{ job.description }}
                                            </span>
                                        </div>

                                        <!-- Latest Log Info -->
                                        <div v-if="job.latest_log" class="mt-2 flex items-center gap-3 text-xs">
                                            <span class="flex items-center gap-1"
                                                :class="{
                                                    'text-success-600 dark:text-success-400': job.latest_log.status === 'success',
                                                    'text-error-600 dark:text-error-400': job.latest_log.status === 'failed',
                                                    'text-warning-600 dark:text-warning-400': job.latest_log.status === 'running',
                                                }"
                                            >
                                                <i :class="{
                                                    'bx bx-check-circle': job.latest_log.status === 'success',
                                                    'bx bx-x-circle': job.latest_log.status === 'failed',
                                                    'bx bx-loader-alt animate-spin': job.latest_log.status === 'running',
                                                }"></i>
                                                {{ job.latest_log.status }}
                                            </span>
                                            <span class="text-gray-400 dark:text-gray-500">
                                                {{ job.latest_log.started_at }}
                                            </span>
                                            <span v-if="job.latest_log.duration_ms !== null" class="text-gray-400 dark:text-gray-500">
                                                {{ formatDuration(job.latest_log.duration_ms) }}
                                            </span>
                                            <button
                                                v-if="job.latest_log.output"
                                                type="button"
                                                @click="toggleOutput(job.id)"
                                                class="text-brand-500 hover:text-brand-600 dark:text-brand-400"
                                            >
                                                {{ expandedOutputs.has(job.id) ? t('Hide output') : t('Show output') }}
                                            </button>
                                        </div>

                                        <!-- Output Expand -->
                                        <div
                                            v-if="job.latest_log?.output && expandedOutputs.has(job.id)"
                                            class="mt-2 max-h-40 overflow-auto rounded-lg bg-gray-900 p-3 text-xs text-gray-200"
                                        >
                                            <pre class="whitespace-pre-wrap">{{ job.latest_log.output }}</pre>
                                        </div>
                                    </div>

                                    <div class="flex shrink-0 items-center gap-2">
                                        <button
                                            type="button"
                                            @click="fetchLogs(job)"
                                            :disabled="anyLoading"
                                            class="inline-flex h-9 items-center gap-1.5 rounded-lg border border-gray-300 px-3 text-sm font-medium text-gray-700 shadow-theme-xs hover:bg-gray-50 disabled:opacity-50 dark:border-gray-700 dark:text-gray-400 dark:hover:bg-white/3"
                                            v-tooltip="t('Execution History')"
                                        >
                                            <i v-if="logsLoadingId === job.id" class="bx bx-loader-alt animate-spin text-base"></i>
                                            <i v-else class="bx bx-history text-base"></i>
                                        </button>

                                        <button
                                            type="button"
                                            @click="startEdit(job)"
                                            :disabled="anyLoading"
                                            class="inline-flex h-9 items-center gap-1.5 rounded-lg border border-gray-300 px-3 text-sm font-medium text-gray-700 shadow-theme-xs hover:bg-gray-50 disabled:opacity-50 dark:border-gray-700 dark:text-gray-400 dark:hover:bg-white/3"
                                            v-tooltip="t('Edit')"
                                        >
                                            <i class="bx bx-edit text-base"></i>
                                        </button>

                                        <button
                                            @click="toggleCronJob(job)"
                                            :disabled="anyLoading"
                                            class="inline-flex h-9 items-center gap-2 rounded-lg px-3 text-sm font-medium shadow-theme-xs transition-colors disabled:opacity-50"
                                            :class="job.enabled
                                                ? 'border border-error-500/40 text-error-600 hover:bg-error-500/10 dark:text-error-400'
                                                : 'bg-brand-500 text-white hover:bg-brand-600'"
                                        >
                                            <i v-if="toggleLoadingId === job.id" class="bx bx-loader-alt animate-spin text-base"></i>
                                            <template v-else>
                                                <i :class="job.enabled ? 'bx bx-pause' : 'bx bx-play'" class="text-base"></i>
                                                {{ job.enabled ? t('Disable') : t('Enable') }}
                                            </template>
                                        </button>

                                        <button
                                            type="button"
                                            @click="deleteCronJob(job)"
                                            :disabled="anyLoading"
                                            class="inline-flex h-9 w-9 items-center justify-center rounded-lg border border-error-500/40 text-error-600 hover:bg-error-500/10 disabled:opacity-50 dark:text-error-400"
                                            v-tooltip="t('Delete')"
                                        >
                                            <i class="bx bx-trash text-base"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Logs Modal -->
                    <div
                        v-if="showLogsModal"
                        class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4"
                        @click.self="showLogsModal = false"
                    >
                        <div class="max-h-[80vh] w-full max-w-2xl overflow-hidden rounded-2xl bg-white shadow-2xl dark:bg-gray-900">
                            <div class="flex items-center justify-between border-b border-gray-200 px-6 py-4 dark:border-gray-800">
                                <h3 class="font-semibold text-gray-800 dark:text-white/90">
                                    {{ t('Execution History') }}
                                </h3>
                                <button
                                    type="button"
                                    @click="showLogsModal = false"
                                    class="inline-flex h-8 w-8 items-center justify-center rounded-lg text-gray-400 hover:bg-gray-100 hover:text-gray-600 dark:hover:bg-gray-800 dark:hover:text-gray-200"
                                >
                                    <i class="bx bx-x text-xl"></i>
                                </button>
                            </div>
                            <div class="max-h-[60vh] overflow-y-auto p-6">
                                <div v-if="logEntries.length === 0" class="py-8 text-center text-sm text-gray-500 dark:text-gray-400">
                                    {{ t('No execution logs yet.') }}
                                </div>
                                <div v-else class="space-y-3">
                                    <div
                                        v-for="log in logEntries"
                                        :key="log.id"
                                        class="rounded-lg border border-gray-200 p-3 dark:border-gray-800"
                                    >
                                        <div class="flex items-center gap-3 text-sm">
                                            <span
                                                class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-semibold"
                                                :class="{
                                                    'bg-success-500/20 text-success-600 dark:text-success-300': log.status === 'success',
                                                    'bg-error-500/20 text-error-600 dark:text-error-300': log.status === 'failed',
                                                    'bg-warning-500/20 text-warning-600 dark:text-warning-300': log.status === 'running',
                                                }"
                                            >
                                                <i :class="{
                                                    'bx bx-check': log.status === 'success',
                                                    'bx bx-x': log.status === 'failed',
                                                    'bx bx-loader-alt animate-spin': log.status === 'running',
                                                }"></i>
                                                {{ log.status }}
                                            </span>
                                            <span class="text-gray-500 dark:text-gray-400">{{ log.started_at }}</span>
                                            <span v-if="log.duration_ms !== null" class="text-gray-400 dark:text-gray-500">
                                                {{ formatDuration(log.duration_ms) }}
                                            </span>
                                            <span v-if="log.exit_code !== null" class="text-gray-400 dark:text-gray-500">
                                                exit: {{ log.exit_code }}
                                            </span>
                                        </div>
                                        <div
                                            v-if="log.output"
                                            class="mt-2 max-h-32 overflow-auto rounded-lg bg-gray-900 p-2.5 text-xs text-gray-200"
                                        >
                                            <pre class="whitespace-pre-wrap">{{ log.output }}</pre>
                                        </div>
                                    </div>
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
import { computed, reactive, ref } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import axios from 'axios';
import ThemeProvider from '@/Components/Layout/ThemeProvider.vue';
import SidebarProvider from '@/Components/Layout/SidebarProvider.vue';
import AdminLayout from '@/Components/Layout/AdminLayout.vue';
import PageBreadcrumb from '@/Components/Common/PageBreadcrumb.vue';
import Toast from '@/Components/UI/Toast.vue';
import { useToast } from '@/Composables/useToast';
import { useI18n } from '@/Composables/useI18n';

interface LatestLog {
    status: string;
    started_at: string | null;
    duration_ms: number | null;
    exit_code: number | null;
    output: string | null;
}

interface CronJob {
    id: number;
    command: string;
    schedule: string;
    schedule_human: string;
    description: string | null;
    enabled: boolean;
    created_at: string | null;
    latest_log: LatestLog | null;
}

interface LogEntry {
    id: number;
    started_at: string | null;
    finished_at: string | null;
    duration_ms: number | null;
    status: string;
    output: string | null;
    exit_code: number | null;
}

interface Preset {
    label: string;
    value: string;
}

const props = defineProps<{
    domain: { id: number; fqdn: string };
    cronJobs: CronJob[];
    presets: Preset[];
}>();

const { t } = useI18n();
const { addToast } = useToast();

const localCronJobs = reactive<CronJob[]>(props.cronJobs.map((j) => ({ ...j })));

const showAddForm = ref(false);
const addLoading = ref(false);
const addErrors = reactive<Record<string, string>>({});
const addForm = reactive({ command: '', schedule: '', description: '' });

const editingId = ref<number | null>(null);
const editLoading = ref(false);
const editErrors = reactive<Record<string, string>>({});
const editForm = reactive({ command: '', schedule: '', description: '' });

const toggleLoadingId = ref<number | null>(null);
const deleteLoadingId = ref<number | null>(null);
const logsLoadingId = ref<number | null>(null);

const showLogsModal = ref(false);
const logEntries = ref<LogEntry[]>([]);

const expandedOutputs = reactive(new Set<number>());

const anyLoading = computed(() =>
    addLoading.value ||
    editLoading.value ||
    toggleLoadingId.value !== null ||
    deleteLoadingId.value !== null ||
    logsLoadingId.value !== null,
);

const breadcrumbs = computed(() => [
    { label: t('Domains'), href: route('domains.index') },
    { label: props.domain.fqdn, href: route('domains.show', props.domain.id) },
    { label: t('Cron Jobs') },
]);

const formatDuration = (ms: number): string => {
    if (ms < 1000) return `${ms}ms`;
    const seconds = Math.round(ms / 1000);
    if (seconds < 60) return `${seconds}s`;
    const minutes = Math.floor(seconds / 60);
    const remainingSeconds = seconds % 60;
    return `${minutes}m ${remainingSeconds}s`;
};

const toggleOutput = (jobId: number): void => {
    if (expandedOutputs.has(jobId)) {
        expandedOutputs.delete(jobId);
    } else {
        expandedOutputs.add(jobId);
    }
};

const applyPreset = (event: Event, target: 'add' | 'edit'): void => {
    const value = (event.target as HTMLSelectElement).value;
    if (value) {
        if (target === 'add') {
            addForm.schedule = value;
        } else {
            editForm.schedule = value;
        }
    }
    (event.target as HTMLSelectElement).value = '';
};

const storeCronJob = async (): Promise<void> => {
    addLoading.value = true;
    Object.keys(addErrors).forEach((key) => delete addErrors[key]);

    try {
        const response = await axios.post(route('domains.cron-jobs.store', props.domain.id), {
            command: addForm.command,
            schedule: addForm.schedule,
            description: addForm.description || null,
        });

        localCronJobs.unshift(response.data.cron_job);
        addForm.command = '';
        addForm.schedule = '';
        addForm.description = '';
        showAddForm.value = false;
        addToast('success', response.data.message);
    } catch (error: any) {
        if (error.response?.status === 422) {
            const errors = error.response.data.errors;
            for (const key in errors) {
                addErrors[key] = errors[key][0];
            }
        } else {
            addToast('error', error.response?.data?.message ?? t('Operation failed.'));
        }
    } finally {
        addLoading.value = false;
    }
};

const startEdit = (job: CronJob): void => {
    editingId.value = job.id;
    editForm.command = job.command;
    editForm.schedule = job.schedule;
    editForm.description = job.description ?? '';
    Object.keys(editErrors).forEach((key) => delete editErrors[key]);
};

const cancelEdit = (): void => {
    editingId.value = null;
};

const updateCronJob = async (job: CronJob): Promise<void> => {
    editLoading.value = true;
    Object.keys(editErrors).forEach((key) => delete editErrors[key]);

    try {
        const response = await axios.put(route('domains.cron-jobs.update', [props.domain.id, job.id]), {
            command: editForm.command,
            schedule: editForm.schedule,
            description: editForm.description || null,
        });

        job.command = response.data.cron_job.command;
        job.schedule = response.data.cron_job.schedule;
        job.schedule_human = response.data.cron_job.schedule_human;
        job.description = response.data.cron_job.description;
        editingId.value = null;
        addToast('success', response.data.message);
    } catch (error: any) {
        if (error.response?.status === 422) {
            const errors = error.response.data.errors;
            for (const key in errors) {
                editErrors[key] = errors[key][0];
            }
        } else {
            addToast('error', error.response?.data?.message ?? t('Operation failed.'));
        }
    } finally {
        editLoading.value = false;
    }
};

const toggleCronJob = async (job: CronJob): Promise<void> => {
    toggleLoadingId.value = job.id;

    try {
        const response = await axios.post(route('domains.cron-jobs.toggle', [props.domain.id, job.id]));
        job.enabled = response.data.enabled;
        addToast('success', response.data.message);
    } catch (error: any) {
        addToast('error', error.response?.data?.message ?? t('Operation failed.'));
    } finally {
        toggleLoadingId.value = null;
    }
};

const deleteCronJob = async (job: CronJob): Promise<void> => {
    if (!confirm(t('Are you sure you want to delete this cron job?'))) return;

    deleteLoadingId.value = job.id;

    try {
        const response = await axios.delete(route('domains.cron-jobs.destroy', [props.domain.id, job.id]));
        const index = localCronJobs.findIndex((j) => j.id === job.id);
        if (index !== -1) {
            localCronJobs.splice(index, 1);
        }
        addToast('success', response.data.message);
    } catch (error: any) {
        addToast('error', error.response?.data?.message ?? t('Operation failed.'));
    } finally {
        deleteLoadingId.value = null;
    }
};

const fetchLogs = async (job: CronJob): Promise<void> => {
    logsLoadingId.value = job.id;

    try {
        const response = await axios.get(route('domains.cron-jobs.logs', [props.domain.id, job.id]));
        logEntries.value = response.data.logs;
        showLogsModal.value = true;
    } catch (error: any) {
        addToast('error', error.response?.data?.message ?? t('Failed to load logs.'));
    } finally {
        logsLoadingId.value = null;
    }
};
</script>
