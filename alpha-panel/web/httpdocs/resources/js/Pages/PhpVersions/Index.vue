<template>
    <Head :title="t('PHP Versions')" />
    <ThemeProvider>
        <SidebarProvider>
            <AdminLayout>
                <PageBreadcrumb
                    :pageTitle="t('PHP Versions')"
                    :items="[{ label: t('PHP Versions') }]"
                />
                <Toast />

                <div class="min-w-0 rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/3 md:p-6">
                    <div class="mb-5 flex items-center">
                        <h3 class="text-lg font-semibold text-gray-800 dark:text-white/90">
                            <i class="fa-brands fa-php mr-2 text-brand-500"></i>
                            {{ t('PHP Versions') }}
                        </h3>
                    </div>

                    <p class="mb-6 text-sm text-gray-500 dark:text-gray-400">
                        {{ t('Manage which PHP-FPM versions are available on the server.') }}
                    </p>

                    <div class="space-y-4">
                        <div
                            v-for="version in localVersions"
                            :key="version.id"
                            class="rounded-xl border p-4 transition-colors"
                            :class="version.is_enabled
                                ? 'border-success-500/30 bg-success-500/5 dark:border-success-500/20 dark:bg-success-500/5'
                                : 'border-gray-200 bg-gray-50 dark:border-gray-800 dark:bg-white/2'"
                        >
                            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:gap-4">
                                <div class="flex min-w-0 items-center gap-3 sm:flex-1 sm:gap-4">
                                    <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg"
                                        :class="version.is_enabled
                                            ? 'bg-success-500/15 text-success-600 dark:text-success-400'
                                            : 'bg-gray-200 text-gray-500 dark:bg-gray-800 dark:text-gray-400'"
                                    >
                                        <i class="fa-brands fa-php text-lg"></i>
                                    </div>

                                    <div class="min-w-0 flex-1">
                                        <div class="flex items-center gap-2">
                                            <h4 class="font-semibold text-gray-800 dark:text-white/90">PHP {{ version.slug }}</h4>
                                            <span
                                                class="inline-flex rounded-full px-2 py-0.5 text-[10px] font-semibold"
                                                :class="version.is_enabled
                                                    ? 'bg-success-500/20 text-success-600 dark:text-success-300'
                                                    : 'bg-gray-200 text-gray-600 dark:bg-gray-700 dark:text-gray-400'"
                                            >
                                                {{ version.is_enabled ? t('Active') : t('Inactive') }}
                                            </span>
                                        </div>
                                        <p class="text-xs text-gray-500 dark:text-gray-400">
                                            {{ version.domains_count > 0
                                                ? t(':count domain(s)', { count: version.domains_count })
                                                : t('No domains') }}
                                        </p>
                                    </div>
                                </div>

                                <div class="flex shrink-0 items-center gap-2">
                                    <span
                                        v-if="version.is_enabled && version.domains_count > 0"
                                        class="inline-flex h-9 items-center gap-2 rounded-lg border border-gray-300 bg-gray-100 px-4 text-sm font-medium text-gray-500 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-400"
                                    >
                                        <i class="bx bx-lock-alt text-base"></i>
                                        {{ t('In Use') }}
                                    </span>
                                    <button
                                        v-else
                                        @click="toggleVersion(version)"
                                        :disabled="actionLoading === version.id"
                                        class="inline-flex h-9 items-center gap-2 rounded-lg px-4 text-sm font-medium shadow-theme-xs transition-colors disabled:opacity-50"
                                        :class="version.is_enabled
                                            ? 'border border-error-500/40 text-error-600 hover:bg-error-500/10 dark:text-error-400'
                                            : 'bg-brand-500 text-white hover:bg-brand-600'"
                                    >
                                        <i v-if="actionLoading === version.id" class="bx bx-loader-alt animate-spin text-base"></i>
                                        <template v-else>
                                            <i :class="version.is_enabled ? 'bx bx-stop' : 'bx bx-play'" class="text-base"></i>
                                            {{ version.is_enabled ? t('Disable') : t('Enable') }}
                                        </template>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </AdminLayout>
        </SidebarProvider>
    </ThemeProvider>
</template>

<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { ref, onMounted } from 'vue';
import axios from 'axios';
import ThemeProvider from '@/Components/Layout/ThemeProvider.vue';
import SidebarProvider from '@/Components/Layout/SidebarProvider.vue';
import AdminLayout from '@/Components/Layout/AdminLayout.vue';
import PageBreadcrumb from '@/Components/Common/PageBreadcrumb.vue';
import Toast from '@/Components/UI/Toast.vue';
import { useToast } from '@/Composables/useToast';
import { useI18n } from '@/Composables/useI18n';

interface PhpVersion {
    id: number;
    slug: string;
    is_enabled: boolean;
    domains_count: number;
}

const props = defineProps<{
    versions: PhpVersion[];
}>();

const { t } = useI18n();
const { addToast } = useToast();

const localVersions = ref<PhpVersion[]>([]);
const actionLoading = ref<number | null>(null);

onMounted(() => {
    localVersions.value = JSON.parse(JSON.stringify(props.versions));
});

const toggleVersion = async (version: PhpVersion) => {
    actionLoading.value = version.id;

    try {
        const response = await axios.post(route('php-versions.toggle', version.id));

        version.is_enabled = response.data.is_enabled;
        addToast('success', response.data.message);
    } catch (error: any) {
        const message = error.response?.data?.message ?? t('Failed to update PHP version: :error', { error: 'Unknown error' });
        addToast('error', message);
    } finally {
        actionLoading.value = null;
    }
};
</script>
