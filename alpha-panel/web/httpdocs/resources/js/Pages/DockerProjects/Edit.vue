<template>
    <Head :title="`${t('Edit')} — ${project.display_name || project.name}`" />
    <ThemeProvider>
        <SidebarProvider>
            <AdminLayout>
                <PageBreadcrumb
                    :pageTitle="t('Edit Project')"
                    :items="[
                        { label: t('Docker Projects'), href: route('docker-projects.index') },
                        { label: project.display_name || project.name, href: route('docker-projects.show', project.id) },
                        { label: t('Edit') },
                    ]"
                />
                <Toast />

                <div class="rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/3">
                    <div class="border-b border-gray-200 px-5 py-5 dark:border-gray-800 md:px-6">
                        <h3 class="text-lg font-semibold text-gray-800 dark:text-white/90">
                            {{ t('Edit Docker Project') }}
                        </h3>
                        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                            {{ t('Update the display name. Edit project files via the File Manager.') }}
                        </p>
                    </div>

                    <form @submit.prevent="submit" class="p-5 md:p-6 space-y-5">
                        <div>
                            <label class="form-label">{{ t('Display Name') }}</label>
                            <input
                                v-model="form.display_name"
                                type="text"
                                :placeholder="project.name"
                                class="form-input"
                            />
                            <p v-if="form.errors.display_name" class="mt-1 text-xs text-error-500">{{ form.errors.display_name }}</p>
                        </div>

                        <div class="flex items-center gap-3 border-t border-gray-200 pt-5 dark:border-gray-800">
                            <button type="submit" class="btn-primary" :disabled="form.processing">
                                <i v-if="form.processing" class="bx bx-loader-alt animate-spin text-base"></i>
                                <i v-else class="bx bx-save text-base"></i>
                                {{ form.processing ? t('Saving...') : t('Save') }}
                            </button>
                            <Link :href="route('docker-projects.show', project.id)" class="btn-secondary">
                                {{ t('Cancel') }}
                            </Link>
                        </div>
                    </form>
                </div>
            </AdminLayout>
        </SidebarProvider>
    </ThemeProvider>
</template>

<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import ThemeProvider from '@/Components/Layout/ThemeProvider.vue';
import SidebarProvider from '@/Components/Layout/SidebarProvider.vue';
import AdminLayout from '@/Components/Layout/AdminLayout.vue';
import PageBreadcrumb from '@/Components/Common/PageBreadcrumb.vue';
import Toast from '@/Components/UI/Toast.vue';
import { useI18n } from '@/Composables/useI18n';

const props = defineProps<{
    project: {
        id: number;
        name: string;
        display_name: string | null;
    };
}>();

const { t } = useI18n();

const form = useForm({
    display_name: props.project.display_name ?? '',
});

const submit = (): void => {
    form.put(route('docker-projects.update', props.project.id));
};
</script>

<style scoped>
@reference "../../../css/app.css";

.form-label {
    @apply mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-300;
}

.form-input {
    @apply h-auto min-h-11 w-full rounded-lg border border-gray-300 bg-transparent px-4 py-2.5 text-sm text-gray-800 shadow-theme-xs placeholder:text-gray-400 focus:border-brand-300 focus:outline-hidden focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90 dark:placeholder:text-white/30 dark:focus:border-brand-800;
}

.btn-primary {
    @apply inline-flex items-center gap-2 rounded-lg bg-brand-500 px-4 py-2.5 text-sm font-medium text-white shadow-theme-xs transition hover:bg-brand-600 disabled:cursor-not-allowed disabled:opacity-50;
}

.btn-secondary {
    @apply inline-flex items-center gap-2 rounded-lg border border-gray-300 bg-white px-4 py-2.5 text-sm font-medium text-gray-700 shadow-theme-xs transition hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-400 dark:hover:bg-white/5;
}
</style>
