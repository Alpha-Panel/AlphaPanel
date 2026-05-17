<template>
    <Head :title="`${t('Docker Projects')} - ${domain.fqdn}`" />
    <ThemeProvider>
        <SidebarProvider>
            <AdminLayout>
                <PageBreadcrumb
                    :pageTitle="t('Docker Projects')"
                    :items="breadcrumbs"
                    :backHref="route('domains.show', domain.id)"
                />
                <Toast />

                <div class="space-y-4 md:space-y-6">
                    <!-- Existing Bindings -->
                    <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/3 md:p-6">
                        <div class="mb-5 flex items-center">
                            <h3 class="text-lg font-semibold text-gray-800 dark:text-white/90">
                                <i class="fa-brands fa-docker mr-2 text-purple-500"></i>
                                {{ t('Docker Project Bindings') }}
                            </h3>
                            <span class="ml-3 text-sm text-gray-500 dark:text-gray-400">{{ domain.fqdn }}</span>
                        </div>

                        <p class="mb-6 text-sm text-gray-500 dark:text-gray-400">
                            {{ t('Bind Docker project services to this domain so they are accessible via Caddy reverse proxy.') }}
                        </p>

                        <div v-if="localBindings.length === 0" class="py-12 text-center">
                            <i class="fa-brands fa-docker mb-3 text-4xl text-gray-300 dark:text-gray-600"></i>
                            <p class="text-sm text-gray-500 dark:text-gray-400">{{ t('No Docker project services bound to this domain.') }}</p>
                        </div>

                        <div v-else class="overflow-x-auto">
                            <table class="w-full text-sm">
                                <thead>
                                    <tr class="border-b border-gray-200 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:border-gray-700 dark:text-gray-400">
                                        <th class="pb-3 pr-4">{{ t('Project') }}</th>
                                        <th class="pb-3 pr-4">{{ t('Service') }}</th>
                                        <th class="pb-3 pr-4">{{ t('Container') }}</th>
                                        <th class="pb-3 pr-4">{{ t('Port') }}</th>
                                        <th class="pb-3 pr-4">{{ t('Path') }}</th>
                                        <th class="pb-3 text-right">{{ t('Actions') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr
                                        v-for="binding in localBindings"
                                        :key="binding.id"
                                        class="border-b border-gray-100 dark:border-gray-800"
                                    >
                                        <td class="py-3 pr-4 font-medium text-gray-800 dark:text-white/90">
                                            {{ binding.docker_project?.display_name || binding.docker_project?.name || '—' }}
                                        </td>
                                        <td class="py-3 pr-4">
                                            <code class="rounded bg-gray-100 px-1.5 py-0.5 text-xs dark:bg-gray-800">{{ binding.service_name }}</code>
                                        </td>
                                        <td class="py-3 pr-4">
                                            <code class="text-xs text-gray-400">
                                                alphapanel-{{ binding.docker_project?.name }}-{{ binding.service_name }}-1
                                            </code>
                                        </td>
                                        <td class="py-3 pr-4 font-mono text-xs text-gray-700 dark:text-gray-300">{{ binding.container_port }}</td>
                                        <td class="py-3 pr-4 text-gray-700 dark:text-gray-300">{{ binding.path_prefix || '/' }}</td>
                                        <td class="py-3 text-right">
                                            <button
                                                type="button"
                                                :disabled="unbindLoading === binding.id"
                                                @click="unbindService(binding)"
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
                    </div>

                    <!-- Bind New -->
                    <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/3 md:p-6">
                        <h4 class="mb-4 text-sm font-semibold text-gray-800 dark:text-white/90">
                            <i class="bx bx-link mr-1 text-brand-500"></i>
                            {{ t('Bind Project Service') }}
                        </h4>

                        <div v-if="availableProjects.length === 0" class="py-6 text-center text-sm text-gray-500 dark:text-gray-400">
                            {{ t('No Docker projects available. Create one first.') }}
                            <Link :href="route('docker-projects.create')" class="ml-1 text-brand-500 hover:underline">
                                {{ t('Create Project') }}
                            </Link>
                        </div>

                        <div v-else class="space-y-4">
                            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                                <div>
                                    <label class="form-label">{{ t('Project') }} *</label>
                                    <select v-model="bindForm.docker_project_id" @change="onProjectSelected" class="form-select">
                                        <option value="">{{ t('Select a project...') }}</option>
                                        <option v-for="p in availableProjects" :key="p.id" :value="p.id">
                                            {{ p.display_name || p.name }}
                                        </option>
                                    </select>
                                    <p v-if="bindErrors.docker_project_id" class="mt-1 text-xs text-error-500">{{ bindErrors.docker_project_id }}</p>
                                </div>
                                <div>
                                    <label class="form-label">{{ t('Service Name') }} *</label>
                                    <input v-model="bindForm.service_name" type="text" placeholder="web" class="form-input font-mono" />
                                    <p v-if="bindErrors.service_name" class="mt-1 text-xs text-error-500">{{ bindErrors.service_name }}</p>
                                </div>
                                <div>
                                    <label class="form-label">{{ t('Container Port') }} *</label>
                                    <input v-model.number="bindForm.container_port" type="number" min="1" max="65535" placeholder="3000" class="form-input" />
                                    <p v-if="bindErrors.container_port" class="mt-1 text-xs text-error-500">{{ bindErrors.container_port }}</p>
                                </div>
                                <div>
                                    <label class="form-label">{{ t('Path Prefix') }}</label>
                                    <input v-model="bindForm.path_prefix" type="text" placeholder="/api" class="form-input" />
                                    <p v-if="bindErrors.path_prefix" class="mt-1 text-xs text-error-500">{{ bindErrors.path_prefix }}</p>
                                    <p class="mt-1 text-xs text-gray-400 dark:text-gray-500">
                                        {{ t('Optional. Must start with / (e.g. /api). Leave empty to proxy root.') }}
                                    </p>
                                </div>
                            </div>

                            <div class="flex justify-end">
                                <button
                                    type="button"
                                    @click="bindService"
                                    :disabled="bindLoading || !bindForm.docker_project_id"
                                    class="inline-flex h-9 items-center gap-2 rounded-lg bg-brand-500 px-4 text-sm font-medium text-white hover:bg-brand-600 disabled:opacity-50"
                                >
                                    <i v-if="bindLoading" class="bx bx-loader-alt animate-spin text-base"></i>
                                    <i v-else class="bx bx-link text-base"></i>
                                    {{ t('Bind Service') }}
                                </button>
                            </div>
                        </div>
                    </div>

                    <div class="flex">
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
import { Head, Link, router } from '@inertiajs/vue3';
import axios from 'axios';
import ThemeProvider from '@/Components/Layout/ThemeProvider.vue';
import SidebarProvider from '@/Components/Layout/SidebarProvider.vue';
import AdminLayout from '@/Components/Layout/AdminLayout.vue';
import PageBreadcrumb from '@/Components/Common/PageBreadcrumb.vue';
import Toast from '@/Components/UI/Toast.vue';
import { useToast } from '@/Composables/useToast';
import { useI18n } from '@/Composables/useI18n';
import { loadSweetAlert } from '@/utils/sweetalert';

interface ProjectInfo {
    id: number;
    name: string;
    display_name: string | null;
    status: string;
}

interface Binding {
    id: number;
    domain_id: number;
    docker_project_id: number;
    service_name: string;
    container_port: number;
    path_prefix: string | null;
    docker_project: ProjectInfo | null;
}

const props = defineProps<{
    domain: { id: number; fqdn: string };
    bindings: Binding[];
    availableProjects: ProjectInfo[];
}>();

const { t } = useI18n();
const { addToast } = useToast();

const localBindings = reactive<Binding[]>(props.bindings.map((b) => ({ ...b })));
const bindLoading = ref(false);
const unbindLoading = ref<number | null>(null);
const bindErrors = reactive<Record<string, string>>({});
const bindForm = reactive({
    docker_project_id: '' as string | number,
    service_name: '',
    container_port: null as number | null,
    path_prefix: '',
});

const breadcrumbs = computed(() => [
    { label: t('Domains'), href: route('domains.index') },
    { label: props.domain.fqdn, href: route('domains.show', props.domain.id) },
    { label: t('Docker Projects') },
]);

const onProjectSelected = (): void => {
    if (!bindForm.docker_project_id) {
        bindForm.service_name = '';
        bindForm.container_port = null;
    }
};

const bindService = async (): Promise<void> => {
    bindLoading.value = true;
    Object.keys(bindErrors).forEach((key) => delete bindErrors[key]);

    try {
        const response = await axios.post(route('domains.docker-projects.store', props.domain.id), {
            docker_project_id: Number(bindForm.docker_project_id),
            service_name: bindForm.service_name,
            container_port: bindForm.container_port,
            path_prefix: bindForm.path_prefix || null,
        });

        localBindings.push(response.data.binding);
        bindForm.docker_project_id = '';
        bindForm.service_name = '';
        bindForm.container_port = null;
        bindForm.path_prefix = '';
        addToast('success', response.data.message);
    } catch (error: any) {
        if (error.response?.status === 422) {
            const errors = error.response.data.errors;
            for (const key in errors) {
                bindErrors[key] = errors[key][0];
            }
        } else {
            addToast('error', error.response?.data?.message ?? t('Operation failed.'));
        }
    } finally {
        bindLoading.value = false;
    }
};

const unbindService = async (binding: Binding): Promise<void> => {
    const swal = await loadSweetAlert();
    if (!swal) return;

    const result = await swal.fire({
        title: t('Unbind Service?'),
        text: t('This will remove the Docker project binding. The domain config will be regenerated.'),
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: t('Unbind'),
        cancelButtonText: t('Cancel'),
    });

    if (!result.isConfirmed) return;

    unbindLoading.value = binding.id;

    try {
        const response = await axios.delete(
            route('domains.docker-projects.destroy', [props.domain.id, binding.id]),
        );

        const index = localBindings.findIndex((b) => b.id === binding.id);
        if (index !== -1) localBindings.splice(index, 1);

        addToast('success', response.data.message);
    } catch (error: any) {
        addToast('error', error.response?.data?.message ?? t('Operation failed.'));
    } finally {
        unbindLoading.value = null;
    }
};
</script>

<style scoped>
@reference "../../../css/app.css";

.form-label {
    @apply mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-300;
}

.form-input {
    @apply h-11 w-full rounded-lg border border-gray-300 bg-white px-3.5 py-2.5 text-sm text-gray-800 placeholder:text-gray-400 focus:border-brand-300 focus:outline-hidden focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white dark:placeholder:text-gray-500;
}

.form-select {
    @apply h-11 w-full appearance-none rounded-lg border border-gray-300 bg-white px-3.5 py-2.5 text-sm text-gray-800 focus:border-brand-300 focus:outline-hidden focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white;
}
</style>
