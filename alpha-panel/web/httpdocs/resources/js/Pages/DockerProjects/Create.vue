<template>
    <Head :title="t('New Docker Project')" />
    <ThemeProvider>
        <SidebarProvider>
            <AdminLayout>
                <PageBreadcrumb
                    :pageTitle="t('New Docker Project')"
                    :items="[
                        { label: t('Docker Projects'), href: route('docker-projects.index') },
                        { label: t('New Project') },
                    ]"
                />
                <Toast />

                <div class="rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/3">
                    <div class="border-b border-gray-200 px-5 py-5 dark:border-gray-800 md:px-6">
                        <h3 class="text-lg font-semibold text-gray-800 dark:text-white/90">
                            {{ t('Create Docker Project') }}
                        </h3>
                        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                            {{ t('Define your project using a Docker Compose YAML file. Services are automatically connected to the proxy network.') }}
                        </p>
                    </div>

                    <form @submit.prevent="submit" class="space-y-5 p-5 md:p-6">
                        <!-- Basic Info -->
                        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <div>
                                <label class="form-label">{{ t('Project Name') }} <span class="text-error-500">*</span></label>
                                <input
                                    v-model="form.name"
                                    type="text"
                                    placeholder="my-project"
                                    class="form-input font-mono"
                                />
                                <p v-if="form.errors.name" class="mt-1 text-xs text-error-500">{{ form.errors.name }}</p>
                                <p class="mt-1 text-xs text-gray-400 dark:text-gray-500">
                                    {{ t('Lowercase letters, numbers, and hyphens. Used as the Portainer stack name prefix.') }}
                                </p>
                            </div>
                            <div>
                                <label class="form-label">{{ t('Display Name') }}</label>
                                <input
                                    v-model="form.display_name"
                                    type="text"
                                    :placeholder="t('My Project')"
                                    class="form-input"
                                />
                                <p v-if="form.errors.display_name" class="mt-1 text-xs text-error-500">{{ form.errors.display_name }}</p>
                            </div>
                        </div>

                        <!-- Compose YAML -->
                        <div>
                            <div class="mb-2 flex items-center justify-between">
                                <label class="form-label mb-0">{{ t('Docker Compose YAML') }} <span class="text-error-500">*</span></label>
                                <button type="button" @click="insertExample" class="text-xs text-brand-500 hover:text-brand-600 dark:text-brand-400">
                                    <i class="bx bx-code-alt mr-1"></i>{{ t('Insert Example') }}
                                </button>
                            </div>
                            <textarea
                                v-model="form.compose_yaml"
                                rows="20"
                                spellcheck="false"
                                placeholder="services:&#10;  web:&#10;    image: nginx:latest&#10;    ports:&#10;      - &quot;80&quot;"
                                class="form-input font-mono text-xs leading-relaxed"
                            ></textarea>
                            <p v-if="form.errors.compose_yaml" class="mt-1 text-xs text-error-500">{{ form.errors.compose_yaml }}</p>
                            <p class="mt-1 text-xs text-gray-400 dark:text-gray-500">
                                <i class="bx bx-info-circle mr-0.5 align-middle"></i>
                                {{ t('Use pre-built images (image: nginx:latest). Container hostname for Caddy proxy: alphapanel-{name}-{service}-1.') }}
                            </p>
                        </div>

                        <!-- Actions -->
                        <div class="flex items-center gap-3 border-t border-gray-200 pt-5 dark:border-gray-800">
                            <button
                                type="submit"
                                class="btn-primary"
                                :disabled="form.processing"
                            >
                                <i v-if="form.processing" class="bx bx-loader-alt animate-spin text-base"></i>
                                <i v-else class="bx bx-save text-base"></i>
                                {{ form.processing ? t('Saving...') : t('Create Project') }}
                            </button>
                            <Link :href="route('docker-projects.index')" class="btn-secondary">
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

const { t } = useI18n();

const form = useForm({
    name: '',
    display_name: '',
    compose_yaml: '',
});

const exampleYaml = `services:
  web:
    image: nginx:latest
    restart: unless-stopped
    networks:
      - default

networks:
  default:
    name: \${COMPOSE_PROJECT_NAME}-net
`;

const insertExample = (): void => {
    if (!form.compose_yaml) {
        form.compose_yaml = exampleYaml;
    }
};

const submit = (): void => {
    form.post(route('docker-projects.store'));
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
