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

                <PhpIniEditorModal
                    v-model="showPhpIniModal"
                    :phpVersion="selectedVersion"
                    :frankenphp="isFrankenPhpMode"
                    @saved="onPhpIniSaved"
                />

                <!-- FrankenPHP -->
                <div class="mb-6 min-w-0 rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/3 md:p-6">
                    <div class="mb-4 flex items-center">
                        <h3 class="text-lg font-semibold text-gray-800 dark:text-white/90">
                            <i class="bx bx-server mr-2 text-brand-500"></i>
                            FrankenPHP
                        </h3>
                    </div>

                    <div class="rounded-xl border border-brand-500/30 bg-brand-500/5 p-4 dark:border-brand-500/20 dark:bg-brand-500/5">
                        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:gap-4">
                            <div class="flex min-w-0 items-center gap-3 sm:flex-1 sm:gap-4">
                                <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-brand-500/15 text-brand-600 dark:text-brand-400">
                                    <i class="fa-brands fa-php text-lg"></i>
                                </div>
                                <div class="min-w-0 flex-1">
                                    <h4 class="font-semibold text-gray-800 dark:text-white/90">FrankenPHP</h4>
                                    <p class="text-xs text-gray-500 dark:text-gray-400">{{ t('Main web server PHP runtime') }}</p>
                                </div>
                            </div>
                            <div class="flex shrink-0 items-center gap-2">
                                <button
                                    @click="openFrankenPhpIni"
                                    class="inline-flex h-9 items-center gap-2 rounded-lg border border-gray-300 px-3 text-sm font-medium text-gray-600 shadow-theme-xs transition-colors hover:bg-gray-100 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-white/5"
                                >
                                    <i class="bx bx-cog text-base"></i>
                                    {{ t('PHP Settings') }}
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- PHP-FPM Versions -->
                <div class="min-w-0 rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/3 md:p-6">
                    <div class="mb-5 flex items-center gap-3">
                        <h3 class="text-lg font-semibold text-gray-800 dark:text-white/90">
                            <i class="fa-brands fa-php mr-2 text-brand-500"></i>
                            {{ t('PHP Versions') }}
                        </h3>
                        <button
                            @click="refreshStatuses"
                            :disabled="refreshingStatuses"
                            class="ml-auto inline-flex h-8 items-center gap-1.5 rounded-lg border border-gray-300 px-3 text-xs font-medium text-gray-600 shadow-theme-xs transition-colors hover:bg-gray-100 disabled:opacity-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-white/5"
                        >
                            <i :class="refreshingStatuses ? 'bx bx-loader-alt animate-spin' : 'bx bx-refresh'" class="text-base"></i>
                            {{ t('Refresh Status') }}
                        </button>
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
                                        <div class="flex flex-wrap items-center gap-2">
                                            <h4 class="font-semibold text-gray-800 dark:text-white/90">PHP {{ version.slug }}</h4>
                                            <span
                                                class="inline-flex rounded-full px-2 py-0.5 text-[10px] font-semibold"
                                                :class="version.is_enabled
                                                    ? 'bg-success-500/20 text-success-600 dark:text-success-300'
                                                    : 'bg-gray-200 text-gray-600 dark:bg-gray-700 dark:text-gray-400'"
                                            >
                                                {{ version.is_enabled ? t('Active') : t('Inactive') }}
                                            </span>
                                            <!-- Supervisor status badge (enabled versions only) -->
                                            <template v-if="version.is_enabled">
                                                <span
                                                    v-if="supervisorStatuses === undefined"
                                                    class="inline-block h-3.5 w-14 animate-pulse rounded-full bg-gray-200 dark:bg-gray-700"
                                                ></span>
                                                <span
                                                    v-else-if="supervisorStatuses[version.slug]"
                                                    class="inline-flex rounded-full px-2 py-0.5 text-[10px] font-semibold"
                                                    :class="supervisorStatusClass(supervisorStatuses[version.slug].status)"
                                                >
                                                    {{ statusLabel(supervisorStatuses[version.slug].status) }}
                                                </span>
                                            </template>
                                        </div>
                                        <p class="text-xs text-gray-500 dark:text-gray-400">
                                            {{ version.domains_count > 0
                                                ? t(':count domain(s)', { count: version.domains_count })
                                                : t('No domains') }}
                                        </p>
                                    </div>
                                </div>

                                <div class="flex shrink-0 flex-wrap items-center gap-2">
                                    <!-- Supervisor action buttons (enabled versions only) -->
                                    <template v-if="version.is_enabled">
                                        <!-- Loading skeletons while statuses fetch -->
                                        <template v-if="supervisorStatuses === undefined">
                                            <div class="h-9 w-28 animate-pulse rounded-lg bg-gray-200 dark:bg-gray-700"></div>
                                            <div class="h-9 w-28 animate-pulse rounded-lg bg-gray-200 dark:bg-gray-700"></div>
                                        </template>
                                        <template v-else>
                                            <button
                                                @click="restartFpm(version)"
                                                :disabled="restartLoading === version.id"
                                                class="inline-flex h-9 items-center gap-2 rounded-lg border border-gray-300 px-3 text-sm font-medium text-gray-600 shadow-theme-xs transition-colors hover:bg-gray-100 disabled:opacity-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-white/5"
                                            >
                                                <i v-if="restartLoading === version.id" class="bx bx-loader-alt animate-spin text-base"></i>
                                                <i v-else class="bx bx-reset text-base"></i>
                                                {{ t('Restart FPM') }}
                                            </button>
                                            <button
                                                @click="recreateConf(version)"
                                                :disabled="recreateLoading === version.id"
                                                class="inline-flex h-9 items-center gap-2 rounded-lg border px-3 text-sm font-medium shadow-theme-xs transition-colors disabled:opacity-50"
                                                :class="supervisorStatuses[version.slug]?.status === 'CONF_MISSING'
                                                    ? 'border-error-500/40 text-error-600 hover:bg-error-500/10 dark:text-error-400'
                                                    : 'border-gray-300 text-gray-600 hover:bg-gray-100 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-white/5'"
                                            >
                                                <i v-if="recreateLoading === version.id" class="bx bx-loader-alt animate-spin text-base"></i>
                                                <i v-else class="bx bx-file text-base"></i>
                                                {{ t('Recreate Config') }}
                                            </button>
                                        </template>
                                    </template>

                                    <button
                                        @click="openPhpIni(version)"
                                        class="inline-flex h-9 items-center gap-2 rounded-lg border border-gray-300 px-3 text-sm font-medium text-gray-600 shadow-theme-xs transition-colors hover:bg-gray-100 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-white/5"
                                    >
                                        <i class="bx bx-cog text-base"></i>
                                        {{ t('PHP Settings') }}
                                    </button>

                                    <button
                                        v-if="version.is_enabled && version.domains_count > 0"
                                        type="button"
                                        class="inline-flex h-9 w-36 cursor-default items-center justify-center gap-2 rounded-lg border border-gray-300 bg-gray-100 text-sm font-medium text-gray-500 shadow-theme-xs pointer-events-none dark:border-gray-700 dark:bg-gray-800 dark:text-gray-400"
                                    >
                                        <i class="bx bx-lock-alt text-base"></i>
                                        {{ t('In Use') }}
                                    </button>
                                    <button
                                        v-else
                                        type="button"
                                        @click="toggleVersion(version)"
                                        :disabled="actionLoading === version.id"
                                        class="inline-flex h-9 w-36 items-center justify-center gap-2 rounded-lg text-sm font-medium shadow-theme-xs transition-colors disabled:opacity-50"
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
import { Head, router } from '@inertiajs/vue3';
import { ref, onMounted } from 'vue';
import axios from 'axios';
import ThemeProvider from '@/Components/Layout/ThemeProvider.vue';
import SidebarProvider from '@/Components/Layout/SidebarProvider.vue';
import AdminLayout from '@/Components/Layout/AdminLayout.vue';
import PageBreadcrumb from '@/Components/Common/PageBreadcrumb.vue';
import Toast from '@/Components/UI/Toast.vue';
import PhpIniEditorModal from '@/Components/PhpVersions/PhpIniEditorModal.vue';
import { useToast } from '@/Composables/useToast';
import { useI18n } from '@/Composables/useI18n';

interface PhpVersion {
    id: number;
    slug: string;
    is_enabled: boolean;
    domains_count: number;
}

interface SupervisorStatus {
    conf_exists: boolean;
    status: string;
}

const props = defineProps<{
    versions: PhpVersion[];
    supervisorStatuses: Record<string, SupervisorStatus> | undefined;
}>();

const { t } = useI18n();
const { addToast } = useToast();

const localVersions = ref<PhpVersion[]>([]);
const actionLoading = ref<number | null>(null);
const restartLoading = ref<number | null>(null);
const recreateLoading = ref<number | null>(null);
const refreshingStatuses = ref(false);
const showPhpIniModal = ref(false);
const selectedVersion = ref<PhpVersion | null>(null);
const isFrankenPhpMode = ref(false);

onMounted(() => {
    localVersions.value = JSON.parse(JSON.stringify(props.versions));
});

const statusLabel = (status: string): string => {
    const key = status.replace(/_/g, ' ').toLowerCase().replace(/\b\w/g, c => c.toUpperCase());
    return t(key);
};

const supervisorStatusClass = (status: string): string => {
    const map: Record<string, string> = {
        RUNNING: 'bg-success-500/20 text-success-600 dark:text-success-300',
        STOPPED: 'bg-gray-200 text-gray-600 dark:bg-gray-700 dark:text-gray-400',
        STARTING: 'bg-warning-500/20 text-warning-600 dark:text-warning-300',
        FATAL: 'bg-error-500/20 text-error-600 dark:text-error-300',
        EXITED: 'bg-warning-500/20 text-warning-600 dark:text-warning-300',
        BACKOFF: 'bg-warning-500/20 text-warning-600 dark:text-warning-300',
        CONF_MISSING: 'bg-error-500/20 text-error-600 dark:text-error-300',
        UNREACHABLE: 'bg-gray-200 text-gray-600 dark:bg-gray-700 dark:text-gray-400',
        UNKNOWN: 'bg-gray-200 text-gray-600 dark:bg-gray-700 dark:text-gray-400',
    };
    return map[status] ?? 'bg-gray-200 text-gray-600 dark:bg-gray-700 dark:text-gray-400';
};

const openPhpIni = (version: PhpVersion) => {
    selectedVersion.value = version;
    isFrankenPhpMode.value = false;
    showPhpIniModal.value = true;
};

const openFrankenPhpIni = () => {
    selectedVersion.value = null;
    isFrankenPhpMode.value = true;
    showPhpIniModal.value = true;
};

const onPhpIniSaved = () => {
    // Toast already shown by the modal on save success
};

const refreshStatuses = () => {
    refreshingStatuses.value = true;
    router.reload({
        only: ['supervisorStatuses'],
        onFinish: () => { refreshingStatuses.value = false; },
    });
};

const toggleVersion = async (version: PhpVersion) => {
    actionLoading.value = version.id;

    try {
        const response = await axios.post(route('php-versions.toggle', version.id));

        version.is_enabled = response.data.is_enabled;
        addToast('success', response.data.message);
        refreshStatuses();
    } catch (error: any) {
        const message = error.response?.data?.message ?? t('Failed to update PHP version: :error', { error: 'Unknown error' });
        addToast('error', message);
    } finally {
        actionLoading.value = null;
    }
};

const restartFpm = async (version: PhpVersion) => {
    restartLoading.value = version.id;

    try {
        const response = await axios.post(route('php-versions.restart', version.id));
        addToast('success', response.data.message);
        refreshStatuses();
    } catch (error: any) {
        const message = error.response?.data?.message ?? t('Failed to restart PHP-FPM: :error', { error: 'Unknown error' });
        addToast('error', message);
    } finally {
        restartLoading.value = null;
    }
};

const recreateConf = async (version: PhpVersion) => {
    recreateLoading.value = version.id;

    try {
        const response = await axios.post(route('php-versions.recreate-conf', version.id));
        addToast('success', response.data.message);
        refreshStatuses();
    } catch (error: any) {
        const message = error.response?.data?.message ?? t('Failed to recreate supervisor config: :error', { error: 'Unknown error' });
        addToast('error', message);
    } finally {
        recreateLoading.value = null;
    }
};
</script>
