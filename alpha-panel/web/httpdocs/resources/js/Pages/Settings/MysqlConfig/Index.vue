<template>
    <Head :title="t('MySQL Configuration')" />
    <ThemeProvider>
        <SidebarProvider>
            <AdminLayout>
                <PageBreadcrumb
                    :pageTitle="t('MySQL Configuration')"
                    :items="breadcrumbs"
                />
                <Toast />

                <div class="space-y-4">
                    <!-- Restart Required Banner -->
                    <div
                        v-if="restartRequired || showRestartBanner"
                        class="flex items-center gap-3 rounded-xl border border-amber-300 bg-amber-50 px-4 py-3 dark:border-amber-700 dark:bg-amber-900/20"
                    >
                        <i class="bx bx-error-circle text-lg text-amber-600 dark:text-amber-400"></i>
                        <div class="flex-1">
                            <p class="text-sm font-medium text-amber-800 dark:text-amber-300">
                                {{ t('MySQL restart required') }}
                            </p>
                            <p class="text-xs text-amber-700 dark:text-amber-400">
                                {{ t('Some settings require a MySQL service restart to take effect.') }}
                            </p>
                        </div>
                        <button
                            type="button"
                            class="inline-flex items-center gap-2 rounded-lg bg-amber-500 px-3 py-1.5 text-xs font-medium text-white hover:bg-amber-600 disabled:opacity-50"
                            :disabled="restarting"
                            @click="showRestartConfirm = true"
                        >
                            <i v-if="restarting" class="bx bx-loader-alt animate-spin text-sm"></i>
                            <i v-else class="bx bx-refresh text-sm"></i>
                            {{ t('Restart MySQL') }}
                        </button>
                    </div>

                    <!-- Tabs -->
                    <div class="rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/3">
                        <!-- Tab Headers -->
                        <div class="flex border-b border-gray-200 dark:border-gray-800">
                            <button
                                v-for="tab in tabs"
                                :key="tab.file"
                                type="button"
                                class="relative flex items-center gap-2 border-b-2 px-5 py-4 text-sm font-medium transition-colors"
                                :class="
                                    activeTab === tab.file
                                        ? 'border-brand-500 text-brand-600 dark:text-brand-400'
                                        : 'border-transparent text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200'
                                "
                                @click="activeTab = tab.file"
                            >
                                <i :class="tab.icon"></i>
                                {{ t(tab.label) }}
                            </button>
                        </div>

                        <!-- Tab Bodies -->
                        <div class="p-5 md:p-6">
                            <template v-for="tab in tabs" :key="tab.file">
                                <div v-show="activeTab === tab.file">
                                    <TabContent
                                        :file="tab.file"
                                        :schema="schema[tab.file] ?? []"
                                        :rawContent="fileContents[tab.file] ?? ''"
                                        :parsedValues="parsedValues[tab.file] ?? {}"
                                        @saved="onSaved"
                                    />

                                    <!-- Purge Binary Logs section (only on binlog tab when binlog is enabled) -->
                                    <div
                                        v-if="tab.file === 'disable_binlog.cnf' && !binlogDisabled"
                                        class="mt-6 border-t border-gray-200 pt-5 dark:border-gray-800"
                                    >
                                        <h4 class="mb-3 flex items-center gap-2 text-sm font-semibold text-gray-800 dark:text-white/90">
                                            <i class="bx bx-trash-alt text-base text-error-500"></i>
                                            {{ t('Purge Binary Logs') }}
                                        </h4>
                                        <div class="rounded-lg border border-warning-200 bg-warning-50 p-3 dark:border-warning-800 dark:bg-warning-900/20 mb-4">
                                            <p class="text-xs text-warning-700 dark:text-warning-400">
                                                {{ t('Purging binary logs will permanently delete all binary log files. This cannot be undone. Only do this if you are sure you do not need the logs for replication or point-in-time recovery.') }}
                                            </p>
                                        </div>
                                        <button
                                            type="button"
                                            class="inline-flex items-center gap-2 rounded-lg border border-error-500/40 px-4 py-2.5 text-sm font-medium text-error-700 hover:bg-error-500/10 dark:text-error-300 disabled:opacity-50"
                                            :disabled="purging"
                                            @click="showPurgeConfirm = true"
                                        >
                                            <i v-if="purging" class="bx bx-loader-alt animate-spin text-sm"></i>
                                            <i v-else class="bx bx-trash text-sm"></i>
                                            {{ purging ? t('Purging...') : t('Purge Binary Logs') }}
                                        </button>
                                    </div>
                                </div>
                            </template>
                        </div>
                    </div>
                </div>

                <!-- Restart Confirm Modal -->
                <div
                    v-if="showRestartConfirm"
                    class="fixed inset-0 z-1200000 flex items-center justify-center bg-black/60 p-4 backdrop-blur-sm"
                    @click.self="showRestartConfirm = false"
                >
                    <div class="w-full max-w-md rounded-2xl border border-gray-200 bg-white shadow-theme-xl dark:border-gray-800 dark:bg-gray-900">
                        <div class="flex items-center justify-between border-b border-gray-200 px-5 py-4 dark:border-gray-800">
                            <h4 class="flex items-center gap-2 text-base font-semibold text-gray-800 dark:text-white/90">
                                <i class="bx bx-refresh text-brand-500"></i>
                                {{ t('Restart MySQL') }}
                            </h4>
                            <button
                                type="button"
                                class="text-2xl leading-none text-gray-400 hover:text-gray-700 dark:hover:text-gray-200"
                                @click="showRestartConfirm = false"
                            >
                                &times;
                            </button>
                        </div>
                        <div class="p-5 md:p-6">
                            <p class="text-sm text-gray-600 dark:text-gray-400">
                                {{ t('Are you sure you want to restart the MySQL service? Active connections will be briefly interrupted.') }}
                            </p>
                            <div class="mt-5 flex items-center justify-end gap-2">
                                <button
                                    type="button"
                                    class="rounded-lg border border-gray-300 px-4 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-400 dark:hover:bg-gray-800"
                                    @click="showRestartConfirm = false"
                                >
                                    {{ t('Cancel') }}
                                </button>
                                <button
                                    type="button"
                                    :disabled="restarting"
                                    class="inline-flex items-center gap-2 rounded-lg bg-amber-500 px-4 py-2.5 text-sm font-medium text-white hover:bg-amber-600 disabled:opacity-50"
                                    @click="restartMysql"
                                >
                                    <i v-if="restarting" class="bx bx-loader-alt animate-spin text-sm"></i>
                                    {{ restarting ? t('Restarting...') : t('Restart') }}
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Purge Confirm Modal -->
                <div
                    v-if="showPurgeConfirm"
                    class="fixed inset-0 z-1200000 flex items-center justify-center bg-black/60 p-4 backdrop-blur-sm"
                    @click.self="showPurgeConfirm = false"
                >
                    <div class="w-full max-w-md rounded-2xl border border-gray-200 bg-white shadow-theme-xl dark:border-gray-800 dark:bg-gray-900">
                        <div class="flex items-center justify-between border-b border-gray-200 px-5 py-4 dark:border-gray-800">
                            <h4 class="flex items-center gap-2 text-base font-semibold text-gray-800 dark:text-white/90">
                                <i class="bx bx-trash text-error-500"></i>
                                {{ t('Purge Binary Logs') }}
                            </h4>
                            <button
                                type="button"
                                class="text-2xl leading-none text-gray-400 hover:text-gray-700 dark:hover:text-gray-200"
                                @click="showPurgeConfirm = false"
                            >
                                &times;
                            </button>
                        </div>
                        <div class="p-5 md:p-6">
                            <p class="text-sm text-gray-600 dark:text-gray-400">
                                {{ t('This will permanently delete all binary log files. This action cannot be undone. Are you sure?') }}
                            </p>
                            <div class="mt-5 flex items-center justify-end gap-2">
                                <button
                                    type="button"
                                    class="rounded-lg border border-gray-300 px-4 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-400 dark:hover:bg-gray-800"
                                    @click="showPurgeConfirm = false"
                                >
                                    {{ t('Cancel') }}
                                </button>
                                <button
                                    type="button"
                                    :disabled="purging"
                                    class="inline-flex items-center gap-2 rounded-lg bg-error-500 px-4 py-2.5 text-sm font-medium text-white hover:bg-error-600 disabled:opacity-50"
                                    @click="purgeBinlogs"
                                >
                                    <i v-if="purging" class="bx bx-loader-alt animate-spin text-sm"></i>
                                    {{ purging ? t('Purging...') : t('Purge') }}
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </AdminLayout>
        </SidebarProvider>
    </ThemeProvider>
</template>

<script setup lang="ts">
import { computed, ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import ThemeProvider from '@/Components/Layout/ThemeProvider.vue';
import SidebarProvider from '@/Components/Layout/SidebarProvider.vue';
import AdminLayout from '@/Components/Layout/AdminLayout.vue';
import PageBreadcrumb from '@/Components/Common/PageBreadcrumb.vue';
import Toast from '@/Components/UI/Toast.vue';
import { useI18n } from '@/Composables/useI18n';
import TabContent from './TabContent.vue';

const props = defineProps<{
    schema: Record<string, any[]>;
    fileContents: Record<string, string>;
    parsedValues: Record<string, Record<string, string>>;
    binlogDisabled: boolean;
    restartRequired: boolean;
}>();

const { t } = useI18n();

const breadcrumbs = computed(() => [
    { label: t('Settings') },
    { label: t('MySQL Configuration') },
]);

const tabs = [
    { file: '10-security.cnf', label: 'Security', icon: 'bx bx-shield-alt-2 text-base' },
    { file: '99-tuning.cnf', label: 'Performance', icon: 'bx bx-tachometer text-base' },
    { file: 'disable_binlog.cnf', label: 'Binary Log', icon: 'bx bx-data text-base' },
];

const activeTab = ref<string>(tabs[0].file);
const showRestartBanner = ref(false);
const showRestartConfirm = ref(false);
const showPurgeConfirm = ref(false);
const restarting = ref(false);
const purging = ref(false);

const onSaved = (restartNeeded: boolean): void => {
    if (restartNeeded) {
        showRestartBanner.value = true;
    }
};

const restartMysql = (): void => {
    restarting.value = true;
    router.post(
        route('settings.mysql-config.restart'),
        {},
        {
            preserveScroll: true,
            onSuccess: () => {
                showRestartBanner.value = false;
                showRestartConfirm.value = false;
            },
            onFinish: () => {
                restarting.value = false;
            },
        },
    );
};

const purgeBinlogs = (): void => {
    purging.value = true;
    router.post(
        route('settings.mysql-config.purge-binlogs'),
        {},
        {
            preserveScroll: true,
            onSuccess: () => {
                showPurgeConfirm.value = false;
            },
            onFinish: () => {
                purging.value = false;
            },
        },
    );
};
</script>

<style scoped>
@reference "../../../../css/app.css";
</style>
