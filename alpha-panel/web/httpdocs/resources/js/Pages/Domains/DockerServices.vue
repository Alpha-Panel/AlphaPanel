<template>
    <Head :title="`${t('Docker Services')} - ${domain.fqdn}`" />
    <ThemeProvider>
        <SidebarProvider>
            <AdminLayout>
                <PageBreadcrumb
                    :pageTitle="t('Docker Services')"
                    :items="breadcrumbs"
                    :backHref="route('domains.show', domain.id)"
                />
                <Toast />

                <div class="space-y-4 md:space-y-6">
                    <!-- Existing Bindings -->
                    <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/3 md:p-6">
                        <div class="mb-5 flex items-center justify-between">
                            <div class="flex items-center">
                                <h3 class="text-lg font-semibold text-gray-800 dark:text-white/90">
                                    <i class="fa-brands fa-docker mr-2 text-brand-500"></i>
                                    {{ t('Docker Services') }}
                                </h3>
                                <span class="ml-3 text-sm text-gray-500 dark:text-gray-400">{{ domain.fqdn }}</span>
                            </div>
                        </div>

                        <p class="mb-6 text-sm text-gray-500 dark:text-gray-400">
                            {{ t('Bind Docker services to this domain so they are accessible via Caddy reverse proxy paths.') }}
                        </p>

                        <!-- Empty State -->
                        <div
                            v-if="localBindings.length === 0"
                            class="py-12 text-center"
                        >
                            <i class="fa-brands fa-docker mb-3 text-4xl text-gray-300 dark:text-gray-600"></i>
                            <p class="text-sm text-gray-500 dark:text-gray-400">{{ t('No Docker services bound to this domain.') }}</p>
                        </div>

                        <!-- Bindings Table -->
                        <div v-else class="overflow-x-auto">
                            <table class="w-full text-sm">
                                <thead>
                                    <tr class="border-b border-gray-200 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:border-gray-700 dark:text-gray-400">
                                        <th class="pb-3 pr-4">{{ t('Service Name') }}</th>
                                        <th class="pb-3 pr-4">{{ t('Image') }}</th>
                                        <th class="pb-3 pr-4">{{ t('Container Port') }}</th>
                                        <th class="pb-3 pr-4">{{ t('Path Prefix') }}</th>
                                        <th class="pb-3 text-right">{{ t('Actions') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr
                                        v-for="binding in localBindings"
                                        :key="binding.id"
                                        class="border-b border-gray-100 dark:border-gray-800"
                                    >
                                        <td class="py-3 pr-4">
                                            <span class="font-medium text-gray-800 dark:text-white/90">
                                                {{ binding.docker_service?.display_name || binding.docker_service?.name || '-' }}
                                            </span>
                                        </td>
                                        <td class="py-3 pr-4">
                                            <code class="rounded bg-gray-100 px-2 py-0.5 text-xs text-gray-700 dark:bg-gray-800 dark:text-gray-300">
                                                {{ binding.docker_service ? `${binding.docker_service.image}:${binding.docker_service.tag}` : '-' }}
                                            </code>
                                        </td>
                                        <td class="py-3 pr-4 text-gray-800 dark:text-white/90">{{ binding.container_port }}</td>
                                        <td class="py-3 pr-4 text-gray-800 dark:text-white/90">{{ binding.path_prefix || '/' }}</td>
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

                    <!-- Bind New Service -->
                    <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/3 md:p-6">
                        <h4 class="mb-4 text-sm font-semibold text-gray-800 dark:text-white/90">
                            <i class="bx bx-link mr-1 text-brand-500"></i>
                            {{ t('Bind Service') }}
                        </h4>

                        <div v-if="availableServices.length === 0" class="py-6 text-center text-sm text-gray-500 dark:text-gray-400">
                            {{ t('No Docker services available to bind. Create a Docker service first.') }}
                        </div>

                        <div v-else class="space-y-4">
                            <div>
                                <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-300">
                                    {{ t('Service') }} <span class="text-error-500">*</span>
                                </label>
                                <div class="relative">
                                    <select
                                        v-model="bindForm.docker_service_id"
                                        @change="onServiceSelected"
                                        class="w-full appearance-none rounded-lg border border-gray-300 bg-white px-3.5 py-2.5 pr-8 text-sm text-gray-800 focus:border-brand-300 focus:outline-hidden focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white"
                                    >
                                        <option value="">{{ t('Select a service...') }}</option>
                                        <option v-for="service in availableServices" :key="service.id" :value="service.id">
                                            {{ service.display_name || service.name }} ({{ service.image }}:{{ service.tag }})
                                        </option>
                                    </select>
                                    <i class="bx bx-chevron-down pointer-events-none absolute right-2.5 top-1/2 -translate-y-1/2 text-base text-gray-400 dark:text-gray-500"></i>
                                </div>
                                <p v-if="bindErrors.docker_service_id" class="mt-1 text-xs text-error-500">{{ bindErrors.docker_service_id }}</p>
                            </div>

                            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                <div>
                                    <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-300">
                                        {{ t('Container Port') }} <span class="text-error-500">*</span>
                                    </label>
                                    <input
                                        v-model.number="bindForm.container_port"
                                        type="number"
                                        min="1"
                                        max="65535"
                                        placeholder="8080"
                                        class="w-full rounded-lg border border-gray-300 bg-white px-3.5 py-2.5 text-sm text-gray-800 placeholder:text-gray-400 focus:border-brand-300 focus:outline-hidden focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white dark:placeholder:text-gray-500"
                                    />
                                    <p v-if="bindErrors.container_port" class="mt-1 text-xs text-error-500">{{ bindErrors.container_port }}</p>
                                </div>

                                <div>
                                    <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-300">
                                        {{ t('Path Prefix') }}
                                    </label>
                                    <input
                                        v-model="bindForm.path_prefix"
                                        type="text"
                                        placeholder="/api"
                                        class="w-full rounded-lg border border-gray-300 bg-white px-3.5 py-2.5 text-sm text-gray-800 placeholder:text-gray-400 focus:border-brand-300 focus:outline-hidden focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white dark:placeholder:text-gray-500"
                                    />
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
                                    :disabled="bindLoading || !bindForm.docker_service_id"
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

interface DockerServiceInfo {
    id: number;
    name: string;
    display_name: string | null;
    image: string;
    tag: string;
    status: string;
    ports: Array<{ container: number; host?: number; protocol?: string }> | null;
}

interface Binding {
    id: number;
    domain_id: number;
    docker_service_id: number;
    container_port: number;
    path_prefix: string | null;
    docker_service: DockerServiceInfo | null;
}

const props = defineProps<{
    domain: { id: number; fqdn: string };
    bindings: Binding[];
    availableServices: DockerServiceInfo[];
}>();

const { t } = useI18n();
const { addToast } = useToast();

const localBindings = reactive<Binding[]>(props.bindings.map((b) => ({ ...b })));

const bindLoading = ref(false);
const bindErrors = reactive<Record<string, string>>({});
const bindForm = reactive({
    docker_service_id: '' as string | number,
    container_port: null as number | null,
    path_prefix: '',
});

const unbindLoading = ref<number | null>(null);

const breadcrumbs = computed(() => [
    { label: t('Domains'), href: route('domains.index') },
    { label: props.domain.fqdn, href: route('domains.show', props.domain.id) },
    { label: t('Docker Services') },
]);

const onServiceSelected = (): void => {
    if (!bindForm.docker_service_id) {
        bindForm.container_port = null;
        return;
    }

    const selected = props.availableServices.find(
        (s) => s.id === Number(bindForm.docker_service_id),
    );

    if (selected?.ports && selected.ports.length > 0) {
        bindForm.container_port = selected.ports[0].container;
    }
};

const bindService = async (): Promise<void> => {
    bindLoading.value = true;
    Object.keys(bindErrors).forEach((key) => delete bindErrors[key]);

    try {
        const response = await axios.post(route('domains.docker-services.store', props.domain.id), {
            docker_service_id: Number(bindForm.docker_service_id),
            container_port: bindForm.container_port,
            path_prefix: bindForm.path_prefix || null,
        });

        localBindings.push(response.data.binding);
        bindForm.docker_service_id = '';
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
    if (!swal) {
        return;
    }

    const result = await swal.fire({
        title: t('Unbind Service?'),
        text: t('This will remove the Docker service binding. The domain config will be regenerated.'),
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: t('Unbind'),
        cancelButtonText: t('Cancel'),
    });

    if (!result.isConfirmed) {
        return;
    }

    unbindLoading.value = binding.id;

    try {
        const response = await axios.delete(
            route('domains.docker-services.destroy', [props.domain.id, binding.id]),
        );

        const index = localBindings.findIndex((b) => b.id === binding.id);
        if (index !== -1) {
            localBindings.splice(index, 1);
        }

        addToast('success', response.data.message);
    } catch (error: any) {
        addToast('error', error.response?.data?.message ?? t('Operation failed.'));
    } finally {
        unbindLoading.value = null;
    }
};
</script>
