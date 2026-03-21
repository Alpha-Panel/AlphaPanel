<template>
    <Head :title="service.display_name || service.name" />
    <ThemeProvider>
        <SidebarProvider>
            <AdminLayout>
                <PageBreadcrumb
                    :pageTitle="service.display_name || service.name"
                    :items="breadcrumbs"
                />
                <Toast />

                <div class="space-y-4 md:space-y-6">
                    <!-- Service Info Card -->
                    <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03] md:p-6">
                        <div class="mb-5 flex flex-col gap-4 sm:flex-row sm:items-center">
                            <div class="min-w-0 flex-1">
                                <h3 class="text-lg font-semibold text-gray-800 dark:text-white/90">
                                    {{ service.display_name || service.name }}
                                </h3>
                                <p class="mt-0.5 text-sm text-gray-500 dark:text-gray-400">
                                    {{ service.name }}
                                </p>
                            </div>
                            <div class="flex items-center gap-2">
                                <span :class="statusBadgeClass" class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-medium">
                                    <span :class="statusDotClass" class="h-1.5 w-1.5 rounded-full"></span>
                                    {{ statusLabel }}
                                </span>
                                <span class="rounded-md bg-gray-100 px-2 py-1 text-xs font-mono text-gray-700 dark:bg-gray-800 dark:text-gray-300">
                                    {{ service.image }}:{{ service.tag }}
                                </span>
                            </div>
                        </div>

                        <dl class="grid grid-cols-1 gap-x-8 gap-y-2 text-sm sm:grid-cols-2">
                            <dt class="text-gray-500 dark:text-gray-400">{{ t('Container ID') }}</dt>
                            <dd class="text-gray-800 dark:text-white/90">
                                <template v-if="service.container_id">
                                    <span class="font-mono text-xs">{{ truncatedContainerId }}</span>
                                    <button
                                        type="button"
                                        class="ml-1.5 text-gray-400 hover:text-gray-600 dark:hover:text-gray-300"
                                        @click="copyToClipboard(service.container_id)"
                                        v-tooltip="containerIdCopied ? t('Copied!') : t('Copy')"
                                    >
                                        <i :class="containerIdCopied ? 'fa-solid fa-check text-success-500' : 'fa-solid fa-copy'" class="text-xs"></i>
                                    </button>
                                </template>
                                <span v-else class="text-gray-400">-</span>
                            </dd>

                            <dt class="text-gray-500 dark:text-gray-400">{{ t('Restart Policy') }}</dt>
                            <dd class="text-gray-800 dark:text-white/90">{{ service.restart_policy ?? '-' }}</dd>

                            <dt class="text-gray-500 dark:text-gray-400">{{ t('Created By') }}</dt>
                            <dd class="text-gray-800 dark:text-white/90">{{ service.created_by?.name ?? '-' }}</dd>

                            <dt class="text-gray-500 dark:text-gray-400">{{ t('Created At') }}</dt>
                            <dd class="text-gray-800 dark:text-white/90">{{ formatDateTime(service.created_at) }}</dd>
                        </dl>
                    </div>

                    <!-- Action Buttons -->
                    <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03] md:p-6">
                        <h4 class="mb-4 text-sm font-semibold text-gray-800 dark:text-white/90">{{ t('Actions') }}</h4>
                        <div class="flex flex-wrap gap-2">
                            <button
                                type="button"
                                :disabled="actionLoading || currentStatus === 'running'"
                                @click="executeAction('start')"
                                class="inline-flex items-center gap-2 rounded-lg bg-success-500 px-4 py-2 text-sm font-medium text-white shadow-theme-xs hover:bg-success-600 disabled:cursor-not-allowed disabled:opacity-50"
                            >
                                <i class="fa-solid fa-play text-xs"></i>
                                {{ t('Start') }}
                            </button>
                            <button
                                type="button"
                                :disabled="actionLoading || currentStatus === 'stopped'"
                                @click="executeAction('stop')"
                                class="inline-flex items-center gap-2 rounded-lg bg-warning-500 px-4 py-2 text-sm font-medium text-white shadow-theme-xs hover:bg-warning-600 disabled:cursor-not-allowed disabled:opacity-50"
                            >
                                <i class="fa-solid fa-stop text-xs"></i>
                                {{ t('Stop') }}
                            </button>
                            <button
                                type="button"
                                :disabled="actionLoading"
                                @click="executeAction('restart')"
                                class="inline-flex items-center gap-2 rounded-lg bg-blue-light-500 px-4 py-2 text-sm font-medium text-white shadow-theme-xs hover:bg-blue-light-600 disabled:cursor-not-allowed disabled:opacity-50"
                            >
                                <i class="fa-solid fa-rotate text-xs"></i>
                                {{ t('Restart') }}
                            </button>
                            <Link
                                :href="route('docker-services.edit', service.id)"
                                class="inline-flex items-center gap-2 rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 shadow-theme-xs hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-white/[0.03]"
                            >
                                <i class="fa-solid fa-pen-to-square text-xs"></i>
                                {{ t('Edit') }}
                            </Link>
                            <button
                                type="button"
                                :disabled="actionLoading"
                                @click="confirmRemove"
                                class="inline-flex items-center gap-2 rounded-lg border border-error-500/40 px-4 py-2 text-sm font-medium text-error-600 hover:bg-error-500/10 disabled:cursor-not-allowed disabled:opacity-50 dark:text-error-300"
                            >
                                <i class="fa-solid fa-trash text-xs"></i>
                                {{ t('Remove') }}
                            </button>
                        </div>
                    </div>

                    <!-- Resource Stats -->
                    <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03] md:p-6">
                        <div class="mb-4 flex items-center justify-between">
                            <h4 class="text-sm font-semibold text-gray-800 dark:text-white/90">{{ t('Resource Usage') }}</h4>
                            <span v-if="currentStatus === 'running'" class="flex items-center gap-1.5 text-xs text-gray-500 dark:text-gray-400">
                                <span class="h-1.5 w-1.5 animate-pulse rounded-full bg-success-500"></span>
                                {{ t('Live') }}
                            </span>
                        </div>

                        <template v-if="currentStatus === 'running'">
                            <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                                <!-- CPU -->
                                <div>
                                    <div class="mb-1.5 flex items-center justify-between text-sm">
                                        <span class="text-gray-600 dark:text-gray-400">{{ t('CPU Usage') }}</span>
                                        <span class="font-medium text-gray-800 dark:text-white/90">{{ stats.cpu_percent != null ? Number(stats.cpu_percent).toFixed(1) + '%' : t('N/A') }}</span>
                                    </div>
                                    <div class="h-2 w-full overflow-hidden rounded-full bg-gray-200 dark:bg-gray-700">
                                        <div
                                            class="h-full rounded-full bg-brand-500 transition-all duration-500"
                                            :style="{ width: (stats.cpu_percent ?? 0) + '%' }"
                                        ></div>
                                    </div>
                                </div>

                                <!-- Memory -->
                                <div>
                                    <div class="mb-1.5 flex items-center justify-between text-sm">
                                        <span class="text-gray-600 dark:text-gray-400">{{ t('Memory') }}</span>
                                        <span class="font-medium text-gray-800 dark:text-white/90">
                                            {{ stats.mem_usage_mb != null ? Number(stats.mem_usage_mb).toFixed(1) + ' MB' : t('N/A') }}
                                            <span v-if="stats.mem_limit_mb" class="text-gray-400"> / {{ Number(stats.mem_limit_mb).toFixed(0) }} MB</span>
                                        </span>
                                    </div>
                                    <div class="h-2 w-full overflow-hidden rounded-full bg-gray-200 dark:bg-gray-700">
                                        <div
                                            class="h-full rounded-full bg-blue-light-500 transition-all duration-500"
                                            :style="{ width: memoryPercent + '%' }"
                                        ></div>
                                    </div>
                                </div>

                                <!-- Network RX -->
                                <div>
                                    <div class="flex items-center justify-between text-sm">
                                        <span class="text-gray-600 dark:text-gray-400">{{ t('Network RX') }}</span>
                                        <span class="font-medium text-gray-800 dark:text-white/90">{{ stats.net_rx ?? t('N/A') }}</span>
                                    </div>
                                </div>

                                <!-- Network TX -->
                                <div>
                                    <div class="flex items-center justify-between text-sm">
                                        <span class="text-gray-600 dark:text-gray-400">{{ t('Network TX') }}</span>
                                        <span class="font-medium text-gray-800 dark:text-white/90">{{ stats.net_tx ?? t('N/A') }}</span>
                                    </div>
                                </div>
                            </div>
                        </template>

                        <template v-else>
                            <p class="text-sm text-gray-500 dark:text-gray-400">{{ t('Resource stats are only available when the service is running.') }}</p>
                        </template>
                    </div>

                    <!-- Logs Viewer -->
                    <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03] md:p-6">
                        <div class="mb-4 flex items-center justify-between">
                            <h4 class="text-sm font-semibold text-gray-800 dark:text-white/90">{{ t('Container Logs') }}</h4>
                            <div class="flex items-center gap-2">
                                <label class="flex items-center gap-1.5 text-xs text-gray-500 dark:text-gray-400">
                                    <input v-model="autoScrollLogs" type="checkbox" class="form-checkbox" />
                                    {{ t('Auto-scroll') }}
                                </label>
                                <button
                                    type="button"
                                    :disabled="logsLoading"
                                    @click="loadMoreLogs"
                                    class="inline-flex items-center gap-1.5 rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-xs font-medium text-gray-700 shadow-theme-xs hover:bg-gray-50 disabled:opacity-50 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-white/[0.03]"
                                >
                                    <i class="fa-solid fa-arrow-down text-[10px]"></i>
                                    {{ t('Load More') }}
                                </button>
                                <button
                                    type="button"
                                    :disabled="logsLoading"
                                    @click="fetchLogs"
                                    class="inline-flex items-center gap-1.5 rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-xs font-medium text-gray-700 shadow-theme-xs hover:bg-gray-50 disabled:opacity-50 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-white/[0.03]"
                                >
                                    <i class="fa-solid fa-rotate text-[10px]"></i>
                                    {{ t('Refresh') }}
                                </button>
                            </div>
                        </div>

                        <div
                            ref="logsContainer"
                            class="h-80 overflow-auto rounded-lg bg-gray-900 p-4 font-mono text-xs leading-5 text-green-400"
                        >
                            <template v-if="logsContent">
                                <pre class="whitespace-pre-wrap break-all">{{ logsContent }}</pre>
                            </template>
                            <template v-else-if="logsLoading">
                                <span class="text-gray-500">{{ t('Loading logs...') }}</span>
                            </template>
                            <template v-else>
                                <span class="text-gray-500">{{ t('No logs available.') }}</span>
                            </template>
                        </div>
                    </div>

                    <!-- Domain Bindings -->
                    <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03] md:p-6">
                        <h4 class="mb-4 text-sm font-semibold text-gray-800 dark:text-white/90">{{ t('Domain Bindings') }}</h4>

                        <template v-if="service.domain_bindings && service.domain_bindings.length > 0">
                            <div class="overflow-x-auto">
                                <table class="w-full text-sm">
                                    <thead>
                                        <tr class="border-b border-gray-200 dark:border-gray-700">
                                            <th class="pb-2 text-left font-medium text-gray-500 dark:text-gray-400">{{ t('Domain') }}</th>
                                            <th class="pb-2 text-left font-medium text-gray-500 dark:text-gray-400">{{ t('Container Port') }}</th>
                                            <th class="pb-2 text-left font-medium text-gray-500 dark:text-gray-400">{{ t('Path Prefix') }}</th>
                                            <th class="pb-2 text-right font-medium text-gray-500 dark:text-gray-400">{{ t('Actions') }}</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr
                                            v-for="binding in service.domain_bindings"
                                            :key="binding.id"
                                            class="border-b border-gray-100 dark:border-gray-800"
                                        >
                                            <td class="py-2.5 text-gray-800 dark:text-white/90">{{ binding.domain?.fqdn ?? '-' }}</td>
                                            <td class="py-2.5 text-gray-800 dark:text-white/90">{{ binding.container_port }}</td>
                                            <td class="py-2.5 text-gray-800 dark:text-white/90">{{ binding.path_prefix || '/' }}</td>
                                            <td class="py-2.5 text-right">
                                                <button
                                                    type="button"
                                                    :disabled="unbindLoading === binding.id"
                                                    @click="unbindDomain(binding)"
                                                    class="text-xs text-error-500 hover:text-error-700 disabled:opacity-50 dark:text-error-400 dark:hover:text-error-300"
                                                >
                                                    {{ t('Unbind') }}
                                                </button>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </template>

                        <template v-else>
                            <p class="text-sm text-gray-500 dark:text-gray-400">{{ t('No domains bound to this service.') }}</p>
                        </template>
                    </div>
                </div>
            </AdminLayout>
        </SidebarProvider>
    </ThemeProvider>
</template>

<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import axios from 'axios';
import ThemeProvider from '@/Components/Layout/ThemeProvider.vue';
import SidebarProvider from '@/Components/Layout/SidebarProvider.vue';
import AdminLayout from '@/Components/Layout/AdminLayout.vue';
import PageBreadcrumb from '@/Components/Common/PageBreadcrumb.vue';
import Toast from '@/Components/UI/Toast.vue';
import { useToast } from '@/Composables/useToast';
import { useI18n } from '@/Composables/useI18n';
import { formatDateTime } from '@/utils/dateTime';
import { loadSweetAlert } from '@/utils/sweetalert';

interface DomainBinding {
    id: number;
    domain_id: number;
    docker_service_id: number;
    container_port: number;
    path_prefix: string | null;
    domain?: { id: number; fqdn: string };
}

interface DockerService {
    id: number;
    name: string;
    display_name: string | null;
    image: string;
    tag: string;
    status: string;
    restart_policy: string | null;
    container_id: string | null;
    environment_variables: Record<string, string> | null;
    volumes: Array<Record<string, string>> | null;
    ports: Array<Record<string, string>> | null;
    resource_limits: Record<string, number> | null;
    networks: string[] | null;
    hostname: string | null;
    created_by: { id: number; name: string } | null;
    domain_bindings: DomainBinding[];
    created_at: string;
    updated_at: string;
}

interface StatsPayload {
    cpu_percent: number | null;
    mem_usage_mb: number | null;
    mem_limit_mb: number | null;
    net_rx: string | null;
    net_tx: string | null;
}

const props = defineProps<{
    service: DockerService;
}>();

const { t } = useI18n();
const { addToast, watchFlash } = useToast();
watchFlash();

const breadcrumbs = computed(() => [
    { label: t('Docker Services'), href: route('docker-services.index') },
    { label: props.service.display_name || props.service.name },
]);

// Status
const currentStatus = ref(props.service.status);

const statusColors: Record<string, { badge: string; dot: string; label: string }> = {
    running: {
        badge: 'bg-success-100 text-success-700 dark:bg-success-900/30 dark:text-success-400',
        dot: 'bg-success-500',
        label: 'Running',
    },
    stopped: {
        badge: 'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-400',
        dot: 'bg-gray-400',
        label: 'Stopped',
    },
    pending: {
        badge: 'bg-warning-100 text-warning-700 dark:bg-warning-900/30 dark:text-warning-400',
        dot: 'bg-warning-500',
        label: 'Pending',
    },
    failed: {
        badge: 'bg-error-100 text-error-700 dark:bg-error-900/30 dark:text-error-400',
        dot: 'bg-error-500',
        label: 'Failed',
    },
    removing: {
        badge: 'bg-warning-100 text-warning-700 dark:bg-warning-900/30 dark:text-warning-400',
        dot: 'bg-warning-500',
        label: 'Removing',
    },
};

const statusBadgeClass = computed(() => statusColors[currentStatus.value]?.badge ?? statusColors.stopped.badge);
const statusDotClass = computed(() => statusColors[currentStatus.value]?.dot ?? statusColors.stopped.dot);
const statusLabel = computed(() => t(statusColors[currentStatus.value]?.label ?? 'Unknown'));

// Container ID
const containerIdCopied = ref(false);
const truncatedContainerId = computed(() => {
    if (!props.service.container_id) {
        return '';
    }

    return props.service.container_id.substring(0, 12);
});

const copyToClipboard = async (text: string): Promise<void> => {
    try {
        await navigator.clipboard.writeText(text);
        containerIdCopied.value = true;
        setTimeout(() => { containerIdCopied.value = false; }, 2000);
    } catch {
        addToast('error', t('Failed to copy to clipboard.'));
    }
};

// Actions
const actionLoading = ref(false);

const executeAction = async (action: 'start' | 'stop' | 'restart'): Promise<void> => {
    actionLoading.value = true;

    try {
        const response = await axios.post(route('docker-services.action', props.service.id), { action });
        currentStatus.value = response.data.status;
        addToast('success', response.data.message);
    } catch (error: any) {
        addToast('error', error.response?.data?.message ?? t('Action failed.'));
    } finally {
        actionLoading.value = false;
    }
};

const confirmRemove = async (): Promise<void> => {
    const swal = await loadSweetAlert();
    if (!swal) {
        return;
    }

    const result = await swal.fire({
        title: t('Remove Service?'),
        text: t('This will stop and remove the container. This action cannot be undone.'),
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#ef4444',
        confirmButtonText: t('Yes, remove it'),
        cancelButtonText: t('Cancel'),
    });

    if (result.isConfirmed) {
        actionLoading.value = true;

        try {
            await axios.delete(route('docker-services.destroy', props.service.id));
            addToast('success', t('Service removed successfully.'));
            router.visit(route('docker-services.index'));
        } catch (error: any) {
            addToast('error', error.response?.data?.message ?? t('Failed to remove service.'));
        } finally {
            actionLoading.value = false;
        }
    }
};

// Resource Stats
const stats = ref<StatsPayload>({
    cpu_percent: null,
    mem_usage_mb: null,
    mem_limit_mb: null,
    net_rx: null,
    net_tx: null,
});

const memoryPercent = computed(() => {
    if (stats.value.mem_usage_mb === null || !stats.value.mem_limit_mb) {
        return 0;
    }

    return Math.min((stats.value.mem_usage_mb / stats.value.mem_limit_mb) * 100, 100);
});

let statsInterval: ReturnType<typeof setInterval> | null = null;

const defaultStats: StatsPayload = {
    cpu_percent: null,
    mem_usage_mb: null,
    mem_limit_mb: null,
    net_rx: null,
    net_tx: null,
};

const fetchStats = async (): Promise<void> => {
    if (currentStatus.value !== 'running') {
        return;
    }

    try {
        const response = await axios.get(route('docker-services.stats', props.service.id));
        const data = response.data.stats;

        if (data && typeof data === 'object' && !Array.isArray(data)) {
            stats.value = { ...defaultStats, ...data };
        }
    } catch {
        // Silently fail — stats are non-critical
    }
};

// Logs
const logsContent = ref('');
const logsLoading = ref(false);
const logsContainer = ref<HTMLElement | null>(null);
const autoScrollLogs = ref(true);
const logsTail = ref(200);

const fetchLogs = async (): Promise<void> => {
    logsLoading.value = true;

    try {
        const response = await axios.get(route('docker-services.logs', props.service.id), {
            params: { tail: logsTail.value },
        });
        logsContent.value = response.data.logs ?? '';

        if (autoScrollLogs.value) {
            await nextTick();
            scrollLogsToBottom();
        }
    } catch {
        logsContent.value = '';
    } finally {
        logsLoading.value = false;
    }
};

const loadMoreLogs = (): void => {
    logsTail.value += 200;
    fetchLogs();
};

const scrollLogsToBottom = (): void => {
    if (logsContainer.value) {
        logsContainer.value.scrollTop = logsContainer.value.scrollHeight;
    }
};

// Domain Bindings
const unbindLoading = ref<number | null>(null);

const unbindDomain = async (binding: DomainBinding): Promise<void> => {
    const swal = await loadSweetAlert();
    if (!swal) {
        return;
    }

    const result = await swal.fire({
        title: t('Unbind Domain?'),
        text: t('This will remove the domain binding for :domain.', { domain: binding.domain?.fqdn ?? '' }),
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#ef4444',
        confirmButtonText: t('Yes, unbind'),
        cancelButtonText: t('Cancel'),
    });

    if (result.isConfirmed) {
        unbindLoading.value = binding.id;

        try {
            await axios.delete(route('domains.docker-services.destroy', [binding.domain_id, binding.id]));
            addToast('success', t('Domain binding removed.'));
            router.reload({ only: ['service'] });
        } catch (error: any) {
            addToast('error', error.response?.data?.message ?? t('Failed to unbind domain.'));
        } finally {
            unbindLoading.value = null;
        }
    }
};

// Lifecycle
onMounted(() => {
    fetchLogs();
    fetchStats();

    statsInterval = setInterval(fetchStats, 5000);
});

onBeforeUnmount(() => {
    if (statsInterval !== null) {
        clearInterval(statsInterval);
    }
});
</script>

<style scoped>
@reference "../../../css/app.css";

.form-checkbox {
    @apply w-3.5 h-3.5 rounded border-gray-300 text-brand-500 focus:ring-brand-500;
}
</style>
