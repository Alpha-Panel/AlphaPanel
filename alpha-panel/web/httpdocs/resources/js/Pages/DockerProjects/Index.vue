<template>
    <Head :title="t('Docker Projects')" />
    <ThemeProvider>
        <SidebarProvider>
            <AdminLayout>
                <PageBreadcrumb
                    :pageTitle="t('Docker Projects')"
                    :items="[{ label: t('Docker Projects') }]"
                />
                <Toast />

                <div class="mb-5 flex items-center justify-between">
                    <p class="text-sm text-gray-500 dark:text-gray-400">
                        {{ t('Manage custom Docker Compose projects. Build and run your own containers, then proxy them to domains.') }}
                    </p>
                    <Link
                        v-if="can('panel.docker-services.manage')"
                        :href="route('docker-projects.create')"
                        class="btn-primary shrink-0"
                    >
                        <i class="bx bx-plus text-base"></i>
                        {{ t('New Project') }}
                    </Link>
                </div>

                <!-- Empty state -->
                <div
                    v-if="projects.length === 0"
                    class="flex flex-col items-center justify-center rounded-2xl border border-gray-200 bg-white py-20 dark:border-gray-800 dark:bg-white/3"
                >
                    <div class="mb-4 flex h-14 w-14 items-center justify-center rounded-full bg-gray-100 dark:bg-gray-800">
                        <i class="fa-brands fa-docker text-2xl text-gray-400 dark:text-gray-500"></i>
                    </div>
                    <p class="mb-1 text-sm font-medium text-gray-700 dark:text-white/70">{{ t('No Docker projects yet') }}</p>
                    <p class="mb-5 text-xs text-gray-400 dark:text-gray-500">{{ t('Create a project from a Compose YAML file.') }}</p>
                    <Link
                        v-if="can('panel.docker-services.manage')"
                        :href="route('docker-projects.create')"
                        class="btn-primary"
                    >
                        <i class="bx bx-plus text-base"></i>
                        {{ t('New Project') }}
                    </Link>
                </div>

                <!-- Projects grid -->
                <div v-else class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-3">
                    <div
                        v-for="project in projects"
                        :key="project.id"
                        class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/3"
                    >
                        <div class="mb-3 flex items-start justify-between gap-2">
                            <div class="flex items-center gap-3 min-w-0">
                                <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-purple-50 text-purple-600 dark:bg-purple-500/10 dark:text-purple-400">
                                    <i class="fa-brands fa-docker text-xl"></i>
                                </div>
                                <div class="min-w-0">
                                    <p class="truncate text-sm font-semibold text-gray-800 dark:text-white/90">
                                        {{ project.display_name || project.name }}
                                    </p>
                                    <code class="text-xs text-gray-400 dark:text-gray-500">{{ project.name }}</code>
                                </div>
                            </div>
                            <StatusBadge :status="project.status" />
                        </div>

                        <div class="mb-4 flex items-center gap-3 text-xs text-gray-400 dark:text-gray-500">
                            <span class="flex items-center gap-1">
                                <i class="bx bx-link"></i>
                                {{ t(':count bindings', { count: project.domain_bindings_count ?? 0 }) }}
                            </span>
                        </div>

                        <div class="flex items-center gap-2">
                            <Link
                                :href="route('docker-projects.show', project.id)"
                                class="btn-secondary flex-1 justify-center text-xs"
                            >
                                <i class="bx bx-show text-sm"></i>
                                {{ t('View') }}
                            </Link>
                            <Link
                                v-if="can('panel.docker-services.manage')"
                                :href="route('docker-projects.edit', project.id)"
                                class="btn-secondary justify-center text-xs"
                                style="padding-left: 0.75rem; padding-right: 0.75rem"
                            >
                                <i class="bx bx-edit text-sm"></i>
                            </Link>
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
import { useI18n } from '@/Composables/useI18n';
import { useCan } from '@/Composables/useCan';

interface Project {
    id: number;
    name: string;
    display_name: string | null;
    status: string;
    domain_bindings_count: number;
}

defineProps<{
    projects: Project[];
}>();

const { t } = useI18n();
const { can } = useCan();

const StatusBadge = {
    props: ['status'],
    template: `
        <span class="shrink-0 rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide"
            :class="{
                'bg-green-50 text-green-600 dark:bg-green-500/10 dark:text-green-400': status === 'running',
                'bg-gray-100 text-gray-500 dark:bg-gray-800 dark:text-gray-400': status === 'stopped',
                'bg-amber-50 text-amber-600 dark:bg-amber-500/10 dark:text-amber-400': status === 'building' || status === 'pending',
                'bg-red-50 text-red-600 dark:bg-red-500/10 dark:text-red-400': status === 'failed',
                'bg-orange-50 text-orange-600 dark:bg-orange-500/10 dark:text-orange-400': status === 'removing',
            }">{{ status }}</span>
    `,
};
</script>

<style scoped>
@reference "../../../css/app.css";

.btn-primary {
    @apply inline-flex items-center gap-2 rounded-lg bg-brand-500 px-4 py-2.5 text-sm font-medium text-white shadow-theme-xs transition hover:bg-brand-600;
}

.btn-secondary {
    @apply inline-flex items-center gap-2 rounded-lg border border-gray-300 bg-white px-4 py-2.5 text-sm font-medium text-gray-700 shadow-theme-xs transition hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-400 dark:hover:bg-white/5;
}
</style>
