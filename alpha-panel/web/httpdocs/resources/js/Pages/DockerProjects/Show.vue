<template>
    <Head :title="`${project.display_name || project.name} — ${t('Docker Project')}`" />
    <ThemeProvider>
        <SidebarProvider>
            <AdminLayout>
                <PageBreadcrumb
                    :pageTitle="project.display_name || project.name"
                    :items="[
                        { label: t('Docker Projects'), href: route('docker-projects.index') },
                        { label: project.display_name || project.name },
                    ]"
                />
                <Toast />

                <div class="space-y-4 md:space-y-6">
                    <!-- Header card -->
                    <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/3 md:p-6">
                        <div class="flex flex-wrap items-start justify-between gap-4">
                            <div class="flex items-center gap-4">
                                <div class="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl bg-purple-50 text-purple-600 dark:bg-purple-500/10 dark:text-purple-400">
                                    <i class="fa-brands fa-docker text-2xl"></i>
                                </div>
                                <div>
                                    <div class="flex items-center gap-2">
                                        <h2 class="text-lg font-semibold text-gray-800 dark:text-white/90">
                                            {{ project.display_name || project.name }}
                                        </h2>
                                        <StatusBadge :status="currentStatus" />
                                    </div>
                                    <code class="text-xs text-gray-400 dark:text-gray-500">
                                        stack: alphapanel-{{ project.name }}
                                    </code>
                                </div>
                            </div>

                            <div class="flex flex-wrap items-center gap-2">
                                <button
                                    v-if="can('panel.docker-services.manage') && currentStatus === 'stopped'"
                                    type="button"
                                    :disabled="actionLoading"
                                    @click="doAction('start')"
                                    class="action-btn action-btn-green"
                                >
                                    <i v-if="actionLoading" class="bx bx-loader-alt animate-spin text-sm"></i>
                                    <i v-else class="bx bx-play text-sm"></i>
                                    {{ t('Start') }}
                                </button>
                                <button
                                    v-if="can('panel.docker-services.manage') && currentStatus === 'running'"
                                    type="button"
                                    :disabled="actionLoading"
                                    @click="doAction('stop')"
                                    class="action-btn action-btn-gray"
                                >
                                    <i v-if="actionLoading" class="bx bx-loader-alt animate-spin text-sm"></i>
                                    <i v-else class="bx bx-stop text-sm"></i>
                                    {{ t('Stop') }}
                                </button>
                                <button
                                    v-if="can('panel.docker-services.manage')"
                                    type="button"
                                    :disabled="actionLoading || currentStatus === 'building'"
                                    @click="doAction('build')"
                                    class="action-btn action-btn-amber"
                                >
                                    <i v-if="actionLoading" class="bx bx-loader-alt animate-spin text-sm"></i>
                                    <i v-else class="bx bx-wrench text-sm"></i>
                                    {{ t('Build') }}
                                </button>
                                <button
                                    v-if="can('panel.docker-services.manage')"
                                    type="button"
                                    :disabled="actionLoading || currentStatus === 'building'"
                                    @click="doAction('redeploy')"
                                    class="action-btn action-btn-blue"
                                >
                                    <i v-if="actionLoading" class="bx bx-loader-alt animate-spin text-sm"></i>
                                    <i v-else class="bx bx-play-circle text-sm"></i>
                                    {{ currentStatus === 'pending' ? t('Deploy') : t('Redeploy') }}
                                </button>
                                <Link
                                    v-if="can('panel.docker-services.manage')"
                                    :href="route('docker-projects.edit', project.id)"
                                    class="action-btn action-btn-gray"
                                >
                                    <i class="bx bx-cog text-sm"></i>
                                    {{ t('Settings') }}
                                </Link>
                                <button
                                    v-if="can('panel.docker-services.manage')"
                                    type="button"
                                    :disabled="actionLoading"
                                    @click="removeProject"
                                    class="action-btn action-btn-red"
                                >
                                    <i class="bx bx-trash text-sm"></i>
                                </button>
                            </div>
                        </div>

                        <!-- Deploy progress bar -->
                        <div v-if="deployProgress !== null" class="mt-4">
                            <div class="mb-1 flex items-center justify-between text-xs">
                                <span class="text-gray-600 dark:text-gray-400">{{ deployMessage }}</span>
                                <span class="font-medium text-gray-700 dark:text-gray-300">{{ deployProgress }}%</span>
                            </div>
                            <div class="h-1.5 w-full rounded-full bg-gray-200 dark:bg-gray-700">
                                <div
                                    class="h-1.5 rounded-full bg-brand-500 transition-all duration-500"
                                    :style="{ width: `${deployProgress}%` }"
                                ></div>
                            </div>
                        </div>
                    </div>

                    <!-- Domain Bindings -->
                    <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/3 md:p-6">
                        <div class="mb-4 flex items-center justify-between">
                            <h3 class="text-sm font-semibold text-gray-800 dark:text-white/90">
                                <i class="bx bx-link mr-1 text-brand-500"></i>
                                {{ t('Domain Proxy Bindings') }}
                            </h3>
                        </div>

                        <div v-if="localBindings.length === 0" class="py-6 text-center text-sm text-gray-500 dark:text-gray-400">
                            {{ t('No domain bindings configured.') }}
                        </div>
                        <div v-else class="overflow-x-auto">
                            <table class="w-full text-sm">
                                <thead>
                                    <tr class="border-b border-gray-200 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:border-gray-700 dark:text-gray-400">
                                        <th class="pb-3 pr-4">{{ t('Domain') }}</th>
                                        <th class="pb-3 pr-4">{{ t('Service') }}</th>
                                        <th class="pb-3 pr-4">{{ t('Port') }}</th>
                                        <th class="pb-3 pr-4">{{ t('Path') }}</th>
                                        <th class="pb-3 pr-4">{{ t('Container') }}</th>
                                        <th v-if="can('panel.docker-services.manage')" class="pb-3 text-right">{{ t('Actions') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr
                                        v-for="binding in localBindings"
                                        :key="binding.id"
                                        class="border-b border-gray-100 dark:border-gray-800"
                                    >
                                        <td class="py-3 pr-4 font-medium text-gray-800 dark:text-white/90">
                                            {{ binding.domain?.fqdn ?? '—' }}
                                        </td>
                                        <td class="py-3 pr-4">
                                            <code class="rounded bg-gray-100 px-1.5 py-0.5 text-xs dark:bg-gray-800">{{ binding.service_name }}</code>
                                        </td>
                                        <td class="py-3 pr-4 font-mono text-xs text-gray-700 dark:text-gray-300">{{ binding.container_port }}</td>
                                        <td class="py-3 pr-4 text-gray-700 dark:text-gray-300">{{ binding.path_prefix || '/' }}</td>
                                        <td class="py-3 pr-4">
                                            <code class="text-xs text-gray-400">alphapanel-{{ project.name }}-{{ binding.service_name }}-1</code>
                                        </td>
                                        <td v-if="can('panel.docker-services.manage')" class="py-3 text-right">
                                            <button
                                                type="button"
                                                :disabled="unbindLoading === binding.id"
                                                @click="unbind(binding)"
                                                class="inline-flex items-center gap-1.5 rounded-lg border border-error-500/40 px-3 py-1.5 text-xs font-medium text-error-600 hover:bg-error-500/10 disabled:opacity-50 dark:text-error-400"
                                            >
                                                <i v-if="unbindLoading === binding.id" class="bx bx-loader-alt animate-spin text-sm"></i>
                                                <i v-else class="bx bx-unlink text-sm"></i>
                                                {{ t('Unbind') }}
                                            </button>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <!-- Hint: manage bindings from the domain page -->
                        <div v-if="can('panel.docker-services.manage') && localBindings.length === 0" class="mt-4 rounded-lg bg-brand-50 px-4 py-3 text-xs text-brand-700 dark:bg-brand-500/10 dark:text-brand-300">
                            <i class="bx bx-info-circle mr-1"></i>
                            {{ t('To assign a domain proxy, go to a domain\'s Docker Projects page and add a binding there.') }}
                            <Link :href="route('domains.index')" class="ml-1 underline">{{ t('Browse Domains') }}</Link>
                        </div>
                    </div>

                    <!-- Project Files -->
                    <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/3 md:p-6">
                        <div class="flex items-center justify-between">
                            <div>
                                <h3 class="text-sm font-semibold text-gray-800 dark:text-white/90">
                                    <i class="bx bx-folder-open mr-1 text-brand-500"></i>
                                    {{ t('Project Files') }}
                                </h3>
                                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                    {{ t('Manage Dockerfile, compose YAML, and config files.') }}
                                </p>
                            </div>
                            <Link
                                v-if="can('panel.docker-services.manage')"
                                :href="route('docker-projects.files.index', project.id)"
                                class="action-btn action-btn-gray"
                            >
                                <i class="bx bx-folder-open text-sm"></i>
                                {{ t('Open Files') }}
                            </Link>
                        </div>
                    </div>

                    <!-- Logs -->
                    <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/3 md:p-6">
                        <div class="mb-3 flex items-center justify-between">
                            <h3 class="text-sm font-semibold text-gray-800 dark:text-white/90">
                                <i class="bx bx-terminal mr-1 text-brand-500"></i>
                                {{ t('Container Logs') }}
                            </h3>
                            <div class="flex items-center gap-2">
                                <input
                                    v-model="logService"
                                    type="text"
                                    :placeholder="t('service name')"
                                    class="form-input h-8 text-xs"
                                    style="width: 10rem"
                                />
                                <button type="button" @click="fetchLogs" class="btn-secondary h-8 px-3 text-xs" :disabled="logsLoading">
                                    <i v-if="logsLoading" class="bx bx-loader-alt animate-spin text-sm"></i>
                                    <i v-else class="bx bx-refresh text-sm"></i>
                                    {{ t('Fetch') }}
                                </button>
                            </div>
                        </div>
                        <pre
                            ref="logsPre"
                            class="max-h-96 overflow-y-auto rounded-lg bg-gray-900 p-4 text-xs leading-relaxed text-gray-200"
                        >{{ logs || t('No logs. Enter a service name and click Fetch.') }}</pre>
                    </div>
                </div>
            </AdminLayout>
        </SidebarProvider>
    </ThemeProvider>
</template>

<script setup lang="ts">
import { ref, reactive, onMounted, onBeforeUnmount } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import axios from 'axios';
import ThemeProvider from '@/Components/Layout/ThemeProvider.vue';
import SidebarProvider from '@/Components/Layout/SidebarProvider.vue';
import AdminLayout from '@/Components/Layout/AdminLayout.vue';
import PageBreadcrumb from '@/Components/Common/PageBreadcrumb.vue';
import Toast from '@/Components/UI/Toast.vue';
import { useToast } from '@/Composables/useToast';
import { useI18n } from '@/Composables/useI18n';
import { useCan } from '@/Composables/useCan';
import { loadSweetAlert } from '@/utils/sweetalert';

interface Binding {
    id: number;
    service_name: string;
    container_port: number;
    path_prefix: string | null;
    domain: { id: number; fqdn: string } | null;
}

const props = defineProps<{
    project: {
        id: number;
        name: string;
        display_name: string | null;
        status: string;
        portainer_stack_id: number | null;
        domain_bindings: Binding[];
    };
}>();

const { t } = useI18n();
const { addToast } = useToast();
const { can } = useCan();

const currentStatus = ref(props.project.status);
const actionLoading = ref(false);
const logsLoading = ref(false);
const logs = ref('');
const logService = ref('');
const logsPre = ref<HTMLPreElement | null>(null);
const deployProgress = ref<number | null>(null);
const deployMessage = ref('');

const localBindings = reactive<Binding[]>(props.project.domain_bindings.map((b) => ({ ...b })));
const unbindLoading = ref<number | null>(null);

const StatusBadge = {
    props: ['status'],
    template: `
        <span class="shrink-0 rounded-full px-2.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide"
            :class="{
                'bg-green-50 text-green-600 dark:bg-green-500/10 dark:text-green-400': status === 'running',
                'bg-gray-100 text-gray-500 dark:bg-gray-800 dark:text-gray-400': status === 'stopped',
                'bg-amber-50 text-amber-600 dark:bg-amber-500/10 dark:text-amber-400': status === 'building' || status === 'pending',
                'bg-red-50 text-red-600 dark:bg-red-500/10 dark:text-red-400': status === 'failed',
                'bg-orange-50 text-orange-600 dark:bg-orange-500/10 dark:text-orange-400': status === 'removing',
            }">{{ status }}</span>
    `,
};

let echoChannel: any = null;

const listenToEvents = (): void => {
    const win = window as any;
    if (!win.Echo) return;

    echoChannel = win.Echo.private(`user.${win.__page?.props?.auth?.user?.id ?? 0}`);

    echoChannel.listen('.DockerDeployProgress', (data: any) => {
        if (data.service_id !== props.project.id) return;
        deployProgress.value = data.percent;
        deployMessage.value = data.message;
        currentStatus.value = 'building';
    });

    echoChannel.listen('.DockerDeployCompleted', (data: any) => {
        if (data.service_id !== props.project.id) return;
        deployProgress.value = null;
        currentStatus.value = 'running';
        addToast('success', t('Project deployed successfully.'));
    });

    echoChannel.listen('.DockerDeployFailed', (data: any) => {
        if (data.service_id !== props.project.id) return;
        deployProgress.value = null;
        currentStatus.value = 'failed';
        addToast('error', data.error ?? t('Deployment failed.'));
    });
};

const doAction = async (action: string): Promise<void> => {
    actionLoading.value = true;
    try {
        const response = await axios.post(route('docker-projects.action', props.project.id), { action });
        currentStatus.value = response.data.status;
        addToast('success', response.data.message);

        if (action === 'redeploy') {
            deployProgress.value = 0;
            deployMessage.value = t('Starting...');
        }
    } catch (error: any) {
        addToast('error', error.response?.data?.message ?? t('Operation failed.'));
    } finally {
        actionLoading.value = false;
    }
};

const removeProject = async (): Promise<void> => {
    const swal = await loadSweetAlert();
    if (!swal) return;

    const result = await swal.fire({
        title: t('Remove Project?'),
        text: t('This will stop and delete all containers in this project via Portainer.'),
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: t('Remove'),
        cancelButtonText: t('Cancel'),
    });

    if (!result.isConfirmed) return;

    router.delete(route('docker-projects.destroy', props.project.id));
};

const fetchLogs = async (): Promise<void> => {
    if (!logService.value) return;
    logsLoading.value = true;
    try {
        const response = await axios.get(route('docker-projects.logs', props.project.id), {
            params: { service: logService.value, tail: 300 },
        });
        logs.value = response.data.logs || t('No logs available.');
    } catch {
        logs.value = t('Failed to fetch logs.');
    } finally {
        logsLoading.value = false;
    }
};

const unbind = async (binding: Binding): Promise<void> => {
    const swal = await loadSweetAlert();
    if (!swal) return;

    const result = await swal.fire({
        title: t('Unbind Service?'),
        text: t('This will remove the proxy binding. The domain config will be regenerated.'),
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: t('Unbind'),
        cancelButtonText: t('Cancel'),
    });

    if (!result.isConfirmed) return;

    unbindLoading.value = binding.id;
    try {
        const domainId = binding.domain?.id;
        if (!domainId) return;
        const response = await axios.delete(
            route('domains.docker-projects.destroy', [domainId, binding.id]),
        );
        const idx = localBindings.findIndex((b) => b.id === binding.id);
        if (idx !== -1) localBindings.splice(idx, 1);
        addToast('success', response.data.message);
    } catch (error: any) {
        addToast('error', error.response?.data?.message ?? t('Operation failed.'));
    } finally {
        unbindLoading.value = null;
    }
};

onMounted(() => {
    listenToEvents();

    if (props.project.status === 'building') {
        deployProgress.value = 0;
        deployMessage.value = t('Building...');
    }
});

onBeforeUnmount(() => {
    if (echoChannel) {
        echoChannel.stopListening('.DockerDeployProgress');
        echoChannel.stopListening('.DockerDeployCompleted');
        echoChannel.stopListening('.DockerDeployFailed');
    }
});
</script>

<style scoped>
@reference "../../../css/app.css";

.form-label {
    @apply mb-1 block text-xs font-medium text-gray-700 dark:text-gray-300;
}

.form-input {
    @apply h-11 w-full rounded-lg border border-gray-300 bg-transparent px-3 py-2 text-sm text-gray-800 placeholder:text-gray-400 focus:border-brand-300 focus:outline-hidden focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90 dark:placeholder:text-white/30 dark:focus:border-brand-800;
}

.action-btn {
    @apply inline-flex items-center gap-1.5 rounded-lg px-3 py-2 text-xs font-medium transition disabled:cursor-not-allowed disabled:opacity-50;
}

.action-btn-green {
    @apply bg-green-500 text-white hover:bg-green-600;
}

.action-btn-gray {
    @apply border border-gray-300 bg-white text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-white/5;
}

.action-btn-blue {
    @apply bg-blue-500 text-white hover:bg-blue-600;
}

.action-btn-amber {
    @apply bg-amber-500 text-white hover:bg-amber-600;
}

.action-btn-red {
    @apply border border-error-500/40 text-error-600 hover:bg-error-500/10 dark:text-error-400;
}

.btn-primary {
    @apply inline-flex items-center gap-2 rounded-lg bg-brand-500 px-4 py-2.5 text-sm font-medium text-white shadow-theme-xs transition hover:bg-brand-600 disabled:cursor-not-allowed disabled:opacity-50;
}

.btn-secondary {
    @apply inline-flex items-center gap-2 rounded-lg border border-gray-300 bg-white px-4 py-2.5 text-sm font-medium text-gray-700 shadow-theme-xs transition hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-400 dark:hover:bg-white/5;
}
</style>
