<template>
    <Head :title="t('Docker Services')" />
    <ThemeProvider>
        <SidebarProvider>
            <AdminLayout>
                <PageBreadcrumb :pageTitle="t('Docker Services')" />
                <Toast />

                <div class="space-y-4 md:space-y-6">
                    <!-- Header -->
                    <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/3 md:p-6">
                        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                            <div>
                                <h3 class="flex items-center gap-2 text-lg font-semibold text-gray-800 dark:text-white/90">
                                    <i class="bx bxl-docker text-xl text-brand-500"></i>
                                    {{ t('Docker Services') }}
                                </h3>
                                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                                    {{ t(':count service(s) configured', { count: services.length }) }}
                                </p>
                            </div>
                            <div class="flex items-center gap-3">
                                <button
                                    v-if="can('panel.docker-services.manage')"
                                    type="button"
                                    @click="syncAllStatuses"
                                    :disabled="syncingAll"
                                    class="inline-flex items-center gap-2 rounded-lg border border-gray-300 px-4 py-2.5 text-sm font-medium text-gray-700 shadow-theme-xs hover:bg-gray-50 disabled:opacity-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-gray-800"
                                >
                                    <i :class="['bx bx-refresh text-base', syncingAll && 'animate-spin']"></i>
                                    {{ t('Sync Status') }}
                                </button>
                                <Link
                                    v-if="can('panel.docker-services.manage')"
                                    :href="route('docker-services.create')"
                                    class="inline-flex items-center gap-2 rounded-lg bg-brand-500 px-4 py-2.5 text-sm font-medium text-white shadow-theme-xs hover:bg-brand-600"
                                >
                                    <i class="bx bx-plus text-base"></i>
                                    {{ t('Add Service') }}
                                </Link>
                            </div>
                        </div>
                    </div>

                    <!-- Empty State -->
                    <div
                        v-if="services.length === 0"
                        class="rounded-2xl border border-gray-200 bg-white p-12 text-center dark:border-gray-800 dark:bg-white/3"
                    >
                        <div class="mx-auto mb-4 flex h-16 w-16 items-center justify-center rounded-2xl bg-gray-100 dark:bg-gray-800">
                            <svg class="h-8 w-8 text-gray-400 dark:text-gray-500" viewBox="0 0 24 24" fill="currentColor">
                                <path d="M13.983 11.078h2.119a.186.186 0 00.186-.185V9.006a.186.186 0 00-.186-.186h-2.119a.186.186 0 00-.185.186v1.887c0 .102.083.185.185.185m-2.954-5.43h2.118a.186.186 0 00.186-.185V3.576a.186.186 0 00-.186-.186h-2.118a.186.186 0 00-.185.186v1.887c0 .102.082.185.185.185m0 2.716h2.118a.187.187 0 00.186-.186V6.29a.186.186 0 00-.186-.185h-2.118a.185.185 0 00-.185.185v1.888c0 .102.082.185.185.186m-2.93 0h2.12a.186.186 0 00.184-.186V6.29a.185.185 0 00-.185-.185H8.1a.186.186 0 00-.185.185v1.888c0 .102.083.185.185.186m-2.964 0h2.119a.186.186 0 00.185-.186V6.29a.186.186 0 00-.185-.185H5.136a.186.186 0 00-.186.185v1.888c0 .102.084.185.186.186m5.893 2.715h2.118a.186.186 0 00.186-.185V9.006a.186.186 0 00-.186-.186h-2.118a.185.185 0 00-.185.186v1.887c0 .102.082.185.185.185m-2.93 0h2.12a.185.185 0 00.184-.185V9.006a.185.185 0 00-.184-.186h-2.12a.186.186 0 00-.186.186v1.887c0 .102.084.185.186.185m-2.964 0h2.119a.185.185 0 00.185-.185V9.006a.186.186 0 00-.185-.186H5.136a.186.186 0 00-.186.186v1.887c0 .102.084.185.186.185m-2.92 0h2.12a.185.185 0 00.184-.185V9.006a.186.186 0 00-.184-.186h-2.12a.185.185 0 00-.185.186v1.887c0 .102.083.185.185.185M23.763 9.89c-.065-.051-.672-.51-1.954-.51-.338.001-.676.03-1.01.087-.248-1.7-1.653-2.53-1.716-2.566l-.344-.199-.226.327c-.284.438-.49.922-.612 1.43-.23.97-.09 1.882.403 2.661-.595.332-1.55.413-1.744.42H.751a.751.751 0 00-.75.748 11.687 11.687 0 00.692 4.062c.545 1.428 1.355 2.48 2.41 3.124 1.18.723 3.1 1.137 5.275 1.137.983.003 1.963-.086 2.93-.266a12.228 12.228 0 003.823-1.389c.98-.567 1.86-1.288 2.61-2.136 1.252-1.418 1.998-2.997 2.553-4.4h.221c1.372 0 2.215-.549 2.68-1.009.309-.293.55-.65.707-1.046l.098-.288Z"/>
                            </svg>
                        </div>
                        <h4 class="text-base font-semibold text-gray-800 dark:text-white/90">
                            {{ t('No services yet') }}
                        </h4>
                        <p class="mx-auto mt-2 max-w-sm text-sm text-gray-500 dark:text-gray-400">
                            {{ t('Deploy your first Docker service to get started. Configure images, ports, volumes, and more.') }}
                        </p>
                        <Link
                            v-if="can('panel.docker-services.manage')"
                            :href="route('docker-services.create')"
                            class="mt-5 inline-flex items-center gap-2 rounded-lg bg-brand-500 px-5 py-2.5 text-sm font-medium text-white shadow-theme-xs hover:bg-brand-600"
                        >
                            <i class="bx bx-plus text-base"></i>
                            {{ t('Add Service') }}
                        </Link>
                    </div>

                    <!-- Service Cards Grid -->
                    <div v-else class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
                        <div
                            v-for="service in services"
                            :key="service.id"
                            class="group rounded-2xl border border-gray-200 bg-white transition-all duration-200 hover:border-gray-300 hover:shadow-md dark:border-gray-800 dark:bg-white/3 dark:hover:border-gray-700"
                        >
                            <!-- Card Header -->
                            <div class="p-5">
                                <div class="mb-3 flex items-start justify-between gap-3">
                                    <div class="min-w-0 flex-1">
                                        <h4 class="truncate text-base font-semibold text-gray-800 dark:text-white/90">
                                            {{ service.display_name || service.name }}
                                        </h4>
                                        <p class="mt-1 truncate text-xs font-mono text-gray-500 dark:text-gray-400">
                                            {{ service.image }}:{{ service.tag || 'latest' }}
                                        </p>
                                    </div>
                                    <!-- Status Badge -->
                                    <span
                                        :class="[
                                            'inline-flex shrink-0 items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-medium',
                                            statusClasses(service.status),
                                        ]"
                                    >
                                        <span
                                            :class="[
                                                'h-1.5 w-1.5 rounded-full',
                                                statusDotClass(service.status),
                                            ]"
                                        ></span>
                                        {{ statusLabel(service.status) }}
                                    </span>
                                </div>

                                <!-- Meta Info -->
                                <div class="space-y-2 text-sm text-gray-500 dark:text-gray-400">
                                    <div v-if="service.hostname" class="flex items-center gap-2">
                                        <i class="bx bx-server text-sm text-gray-400 dark:text-gray-500"></i>
                                        <span class="truncate">{{ service.hostname }}</span>
                                    </div>
                                    <div v-if="service.ports && service.ports.length > 0" class="flex items-center gap-2">
                                        <i class="bx bx-link text-sm text-gray-400 dark:text-gray-500"></i>
                                        <span class="truncate">
                                            {{ formatPorts(service.ports) }}
                                        </span>
                                    </div>
                                    <div class="flex items-center gap-2">
                                        <i class="bx bx-calendar text-sm text-gray-400 dark:text-gray-500"></i>
                                        <span>{{ formatDate(service.created_at) }}</span>
                                    </div>
                                    <div v-if="service.created_by" class="flex items-center gap-2">
                                        <i class="bx bx-user text-sm text-gray-400 dark:text-gray-500"></i>
                                        <span>{{ service.created_by.name }}</span>
                                    </div>
                                    <div v-if="service.domain_bindings_count > 0" class="flex items-center gap-2">
                                        <i class="bx bx-globe text-sm text-gray-400 dark:text-gray-500"></i>
                                        <span>{{ service.domain_bindings_count }} {{ t('domain(s)') }}</span>
                                    </div>
                                </div>
                            </div>

                            <!-- Card Actions -->
                            <div
                                v-if="can('panel.docker-services.manage')"
                                class="flex items-center justify-between border-t border-gray-100 px-5 py-3 dark:border-gray-800"
                            >
                                <!-- Service Control Buttons -->
                                <div class="flex items-center gap-1.5">
                                    <button
                                        v-if="service.status === 'stopped' || service.status === 'failed'"
                                        type="button"
                                        :disabled="actionLoading[service.id] !== undefined"
                                        @click="performAction(service, 'start')"
                                        class="inline-flex h-8 w-8 items-center justify-center rounded-lg border border-success-500/40 text-success-600 transition hover:bg-success-500/10 disabled:opacity-50 dark:text-success-400"
                                        :title="t('Start')"
                                    >
                                        <i :class="['bx text-base', actionLoading[service.id] === 'start' ? 'bx-loader-alt animate-spin' : 'bx-play']"></i>
                                    </button>
                                    <button
                                        v-if="service.status === 'running'"
                                        type="button"
                                        :disabled="actionLoading[service.id] !== undefined"
                                        @click="performAction(service, 'stop')"
                                        class="inline-flex h-8 w-8 items-center justify-center rounded-lg border border-warning-500/40 text-warning-600 transition hover:bg-warning-500/10 disabled:opacity-50 dark:text-warning-400"
                                        :title="t('Stop')"
                                    >
                                        <i :class="['bx text-base', actionLoading[service.id] === 'stop' ? 'bx-loader-alt animate-spin' : 'bx-stop']"></i>
                                    </button>
                                    <button
                                        v-if="service.status === 'running' || service.status === 'stopped'"
                                        type="button"
                                        :disabled="actionLoading[service.id] !== undefined"
                                        @click="performAction(service, 'restart')"
                                        class="inline-flex h-8 w-8 items-center justify-center rounded-lg border border-blue-light-500/40 text-blue-light-600 transition hover:bg-blue-light-500/10 disabled:opacity-50 dark:text-blue-light-400"
                                        :title="t('Restart')"
                                    >
                                        <i :class="['bx text-base', actionLoading[service.id] === 'restart' ? 'bx-loader-alt animate-spin' : 'bx-revision']"></i>
                                    </button>
                                </div>

                                <!-- Navigation & Delete -->
                                <div class="flex items-center gap-1.5">
                                    <Link
                                        :href="route('docker-services.show', service.id)"
                                        class="inline-flex h-8 w-8 items-center justify-center rounded-lg bg-blue-light-500 text-white transition hover:bg-blue-light-600"
                                        :title="t('View Details')"
                                    >
                                        <i class="bx bx-show text-base"></i>
                                    </Link>
                                    <Link
                                        :href="route('docker-services.edit', service.id)"
                                        class="inline-flex h-8 w-8 items-center justify-center rounded-lg bg-warning-500 text-white transition hover:bg-warning-600"
                                        :title="t('Edit')"
                                    >
                                        <i class="bx bx-edit text-base"></i>
                                    </Link>
                                    <button
                                        type="button"
                                        @click="deleteService(service)"
                                        :disabled="actionLoading[service.id] !== undefined"
                                        class="inline-flex h-8 w-8 items-center justify-center rounded-lg bg-error-500 text-white transition hover:bg-error-600 disabled:opacity-50"
                                        :title="t('Delete')"
                                    >
                                        <i class="bx bx-trash text-base"></i>
                                    </button>
                                </div>
                            </div>

                            <!-- View-only footer for users without manage permission -->
                            <div
                                v-else
                                class="border-t border-gray-100 px-5 py-3 dark:border-gray-800"
                            >
                                <Link
                                    :href="route('docker-services.show', service.id)"
                                    class="inline-flex items-center gap-1.5 text-sm font-medium text-brand-500 hover:text-brand-600 dark:text-brand-400 dark:hover:text-brand-300"
                                >
                                    {{ t('View Details') }}
                                    <i class="bx bx-right-arrow-alt text-base"></i>
                                </Link>
                            </div>
                        </div>
                    </div>
                </div>
            </AdminLayout>
        </SidebarProvider>
    </ThemeProvider>
</template>

<script setup lang="ts">
import { onBeforeUnmount, onMounted, reactive, ref } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import axios from 'axios';
import ThemeProvider from '@/Components/Layout/ThemeProvider.vue';
import SidebarProvider from '@/Components/Layout/SidebarProvider.vue';
import AdminLayout from '@/Components/Layout/AdminLayout.vue';
import PageBreadcrumb from '@/Components/Common/PageBreadcrumb.vue';
import Toast from '@/Components/UI/Toast.vue';
import { useI18n } from '@/Composables/useI18n';
import { useCan } from '@/Composables/useCan';
import { useToast } from '@/Composables/useToast';
import { formatDateTime } from '@/utils/dateTime';
import { loadSweetAlert } from '@/utils/sweetalert';
import type { SharedProps } from '@/types/inertia';

interface DockerServiceUser {
    id: number;
    name: string;
}

interface DockerService {
    id: number;
    name: string;
    display_name: string | null;
    image: string;
    tag: string | null;
    status: string;
    hostname: string | null;
    ports: Array<Record<string, any>> | null;
    container_id: string | null;
    domain_bindings_count: number;
    created_at: string;
    created_by: DockerServiceUser | null;
}

const props = defineProps<{
    services: DockerService[];
}>();

const { t } = useI18n();
const { can } = useCan();
const { addToast } = useToast();

const actionLoading = reactive<Record<number, string | undefined>>({});
const syncingAll = ref(false);

const statusClasses = (status: string): string => {
    switch (status) {
        case 'running':
            return 'bg-success-50 text-success-700 dark:bg-success-500/15 dark:text-success-400';
        case 'stopped':
            return 'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-400';
        case 'pending':
            return 'bg-warning-50 text-warning-700 dark:bg-warning-500/15 dark:text-warning-400';
        case 'failed':
            return 'bg-error-50 text-error-700 dark:bg-error-500/15 dark:text-error-400';
        case 'removing':
            return 'bg-warning-50 text-warning-700 dark:bg-warning-500/15 dark:text-warning-400';
        default:
            return 'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-400';
    }
};

const statusDotClass = (status: string): string => {
    switch (status) {
        case 'running':
            return 'bg-success-500 animate-pulse';
        case 'stopped':
            return 'bg-gray-400 dark:bg-gray-500';
        case 'pending':
            return 'bg-warning-500 animate-pulse';
        case 'failed':
            return 'bg-error-500';
        case 'removing':
            return 'bg-warning-500 animate-pulse';
        default:
            return 'bg-gray-400';
    }
};

const statusLabel = (status: string): string => {
    switch (status) {
        case 'running':
            return t('Running');
        case 'stopped':
            return t('Stopped');
        case 'pending':
            return t('Pending');
        case 'failed':
            return t('Failed');
        case 'removing':
            return t('Removing');
        default:
            return status;
    }
};

const formatPorts = (ports: Array<Record<string, any>>): string => {
    if (!Array.isArray(ports)) {
        return '';
    }

    return ports
        .map((p) => {

            if (p.host && p.container) {
                return `${p.host}:${p.container}`;
            }

            return JSON.stringify(p);
        })
        .join(', ');
};

const formatDate = (value: string | null | undefined): string => {
    return formatDateTime(value ?? null);
};

const performAction = async (service: DockerService, action: string): Promise<void> => {
    if (actionLoading[service.id] !== undefined) {
        return;
    }

    actionLoading[service.id] = action;

    try {
        const response = await axios.post(route('docker-services.action', service.id), {
            action,
        });

        addToast('success', response.data.message ?? t('Action executed successfully.'));

        router.reload({ only: ['services'] });
    } catch (error: any) {
        const message = error?.response?.data?.message ?? t('Action failed.');
        addToast('error', String(message));
    } finally {
        delete actionLoading[service.id];
    }
};

const syncAllStatuses = async (): Promise<void> => {
    if (syncingAll.value) {
        return;
    }

    syncingAll.value = true;

    try {
        const promises = props.services.map((service) =>
            axios.post(route('docker-services.sync-status', service.id)).catch(() => null),
        );

        await Promise.allSettled(promises);
        addToast('success', t('All statuses synced.'));
        router.reload({ only: ['services'] });
    } catch {
        addToast('error', t('Failed to sync statuses.'));
    } finally {
        syncingAll.value = false;
    }
};

const deleteService = async (service: DockerService): Promise<void> => {
    const swal = await loadSweetAlert();
    if (!swal) return;

    const result = await swal.fire({
        title: t('Remove Service?'),
        text: t('This will stop and remove the container. This action cannot be undone.'),
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#f04438',
        confirmButtonText: t('Yes, remove it'),
        cancelButtonText: t('Cancel'),
    });

    if (!result.isConfirmed) return;

    router.delete(route('docker-services.destroy', service.id), {
        onSuccess: () => {
            addToast('success', t('Service removed successfully.'));
        },
        onError: () => {
            addToast('error', t('Failed to remove service.'));
        },
    });
};

// Real-time status updates via WebSocket
let echoChannel: string | null = null;

onMounted(() => {
    if (typeof window.Echo === 'undefined') return;

    const page = usePage<SharedProps>();
    const userId = page.props.auth?.user?.id;
    if (!userId) return;

    echoChannel = `user.${userId}`;
    window.Echo.private(echoChannel)
        .listen('.DockerDeployCompleted', () => {
            router.reload({ only: ['services'] });
        })
        .listen('.DockerDeployFailed', () => {
            router.reload({ only: ['services'] });
        });
});

onBeforeUnmount(() => {
    if (echoChannel && typeof window.Echo !== 'undefined') {
        window.Echo.private(echoChannel)
            .stopListening('.DockerDeployCompleted')
            .stopListening('.DockerDeployFailed');
    }
});
</script>
