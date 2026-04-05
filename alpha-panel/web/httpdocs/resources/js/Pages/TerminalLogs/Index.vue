<template>
    <Head :title="t('Terminal Logs')" />
    <ThemeProvider>
        <SidebarProvider>
            <AdminLayout>
                <PageBreadcrumb :pageTitle="t('Terminal Logs')" />

                <div class="space-y-4">
                    <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/3">
                        <div class="mb-4 flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                            <h3 class="text-lg font-semibold text-gray-800 dark:text-white/90">
                                <i class="bx bx-terminal mr-2"></i>
                                {{ t('Terminal Logs') }}
                            </h3>
                            <div class="flex w-full flex-col gap-2 sm:flex-row lg:w-auto">
                                <input
                                    v-model="searchInput"
                                    @input="table.setSearch(searchInput)"
                                    type="text"
                                    :placeholder="t('Search commands, containers...')"
                                    class="h-10 w-full rounded-lg border border-gray-200 bg-transparent px-4 text-sm text-gray-800 shadow-theme-xs placeholder:text-gray-400 focus:border-brand-300 focus:outline-hidden focus:ring-3 focus:ring-brand-500/10 sm:w-72 dark:border-gray-800 dark:bg-gray-900 dark:text-white/90"
                                />
                                <select
                                    v-model="sessionTypeFilter"
                                    class="h-10 rounded-lg border border-gray-200 bg-transparent px-3 text-sm text-gray-800 shadow-theme-xs focus:border-brand-300 focus:outline-hidden dark:border-gray-800 dark:bg-gray-900 dark:text-white/90"
                                >
                                    <option value="">{{ t('All Types') }}</option>
                                    <option value="ssh">SSH Host</option>
                                    <option value="portainer">Container</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div class="rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/3">
                        <div class="overflow-x-auto">
                            <table class="w-full">
                                <thead>
                                    <tr class="border-t border-gray-200 dark:border-gray-800">
                                        <th class="sortable-th">{{ t('Date') }}</th>
                                        <th class="sortable-th">{{ t('User') }}</th>
                                        <th class="sortable-th">{{ t('Type') }}</th>
                                        <th class="sortable-th">{{ t('Container') }}</th>
                                        <th class="sortable-th">{{ t('Command') }}</th>
                                        <th class="sortable-th">{{ t('Source') }}</th>
                                        <th class="w-10"></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-if="table.loading.value" class="border-t border-gray-200 dark:border-gray-800">
                                        <td colspan="7" class="px-5 py-8 text-center text-gray-500">{{ t('Loading...') }}</td>
                                    </tr>
                                    <tr v-else-if="table.data.value.length === 0" class="border-t border-gray-200 dark:border-gray-800">
                                        <td colspan="7" class="px-5 py-8 text-center text-gray-500 dark:text-gray-400">{{ t('No logs found.') }}</td>
                                    </tr>
                                    <template v-for="log in table.data.value" :key="String(log.id)">
                                        <tr
                                            class="border-t border-gray-200 hover:bg-gray-50 dark:border-gray-800 dark:hover:bg-white/2"
                                            :class="{ 'cursor-pointer': log.has_output }"
                                            @click="log.has_output ? toggleOutput(log) : null"
                                        >
                                            <td class="whitespace-nowrap px-5 py-3 text-sm text-gray-600 dark:text-gray-400">{{ log.created_at }}</td>
                                            <td class="whitespace-nowrap px-5 py-3 text-sm font-medium text-gray-800 dark:text-white/90">{{ log.user }}</td>
                                            <td class="px-5 py-3" v-html="String(log.session_type_badge ?? '-')"></td>
                                            <td class="whitespace-nowrap px-5 py-3 text-sm text-gray-600 dark:text-gray-400">
                                                <span class="inline-flex items-center gap-1.5 rounded-md bg-gray-100 px-2 py-0.5 text-xs font-medium dark:bg-gray-800">
                                                    <i class="bx bxl-docker text-sm"></i>
                                                    {{ log.container_name }}
                                                </span>
                                            </td>
                                            <td class="max-w-md px-5 py-3 text-sm">
                                                <code class="block truncate rounded bg-gray-900 px-2 py-1 text-xs text-green-400 dark:bg-black">{{ log.command }}</code>
                                            </td>
                                            <td class="whitespace-nowrap px-5 py-3 text-sm text-gray-500 dark:text-gray-400">
                                                <span class="font-mono text-xs">{{ log.source }}</span>
                                            </td>
                                            <td class="px-3 py-3 text-center">
                                                <button
                                                    v-if="log.has_output"
                                                    type="button"
                                                    class="inline-flex h-6 w-6 items-center justify-center rounded text-gray-400 hover:text-gray-700 dark:hover:text-gray-200"
                                                >
                                                    <i
                                                        class="bx text-base transition-transform"
                                                        :class="expandedId === Number(log.id) ? 'bx-chevron-up' : 'bx-chevron-down'"
                                                    ></i>
                                                </button>
                                            </td>
                                        </tr>
                                        <tr v-if="expandedId === Number(log.id)" class="border-t border-gray-100 dark:border-gray-800/50">
                                            <td colspan="7" class="px-5 py-0">
                                                <div class="my-3 rounded-lg border border-gray-200 bg-gray-950 dark:border-gray-700">
                                                    <div class="flex items-center justify-between border-b border-gray-800 px-4 py-2">
                                                        <span class="text-xs font-medium text-gray-400">{{ t('Output') }}</span>
                                                        <span class="text-xs text-gray-500">{{ log.container_name }} — {{ log.created_at }}</span>
                                                    </div>
                                                    <div class="max-h-80 overflow-auto p-4">
                                                        <div v-if="outputLoading" class="flex items-center gap-2 text-sm text-gray-400">
                                                            <i class="bx bx-loader-alt animate-spin"></i> {{ t('Loading...') }}
                                                        </div>
                                                        <pre v-else class="whitespace-pre-wrap font-mono text-xs leading-5 text-green-400">{{ outputContent }}</pre>
                                                    </div>
                                                </div>
                                            </td>
                                        </tr>
                                    </template>
                                </tbody>
                            </table>
                        </div>

                        <div v-if="table.totalPages.value > 1" class="flex items-center justify-between border-t border-gray-200 px-5 py-4 dark:border-gray-800">
                            <p class="text-sm text-gray-500">
                                {{ table.recordsFiltered.value }} {{ t('total') }}
                            </p>
                            <div class="flex gap-2">
                                <button
                                    v-for="pageNumber in table.totalPages.value"
                                    :key="pageNumber"
                                    @click="table.setPage(pageNumber)"
                                    :class="[
                                        'h-8 w-8 rounded-lg text-sm font-medium',
                                        pageNumber === table.currentPage.value ? 'bg-brand-500 text-white' : 'text-gray-700 hover:bg-gray-100 dark:text-gray-400',
                                    ]"
                                >
                                    {{ pageNumber }}
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
import { computed, ref, watch } from 'vue';
import axios from 'axios';
import { Head } from '@inertiajs/vue3';
import ThemeProvider from '@/Components/Layout/ThemeProvider.vue';
import SidebarProvider from '@/Components/Layout/SidebarProvider.vue';
import AdminLayout from '@/Components/Layout/AdminLayout.vue';
import PageBreadcrumb from '@/Components/Common/PageBreadcrumb.vue';
import { useDataTable } from '@/Composables/useDataTable';
import { useI18n } from '@/Composables/useI18n';

const { t } = useI18n();
const searchInput = ref('');
const sessionTypeFilter = ref('');

const expandedId = ref<number | null>(null);
const outputContent = ref('');
const outputLoading = ref(false);

const extraParams = computed(() => ({
    session_types: sessionTypeFilter.value ? [sessionTypeFilter.value] : [],
}));

const table = useDataTable({
    url: route('terminal-logs.json'),
    columns: ['created_at', 'user', 'session_type', 'container_name', 'command', 'source'],
    defaultOrderColumn: 0,
    defaultOrderDir: 'desc',
    extraParams,
});

watch(sessionTypeFilter, () => {
    table.setPage(1);
});

const toggleOutput = async (log: Record<string, unknown>): Promise<void> => {
    const logId = Number(log.id);

    if (expandedId.value === logId) {
        expandedId.value = null;
        return;
    }

    expandedId.value = logId;
    outputLoading.value = true;
    outputContent.value = '';

    try {
        const response = await axios.get(route('terminal-logs.show', logId));
        outputContent.value = response.data.output || t('No output captured.');
    } catch {
        outputContent.value = t('Failed to load output.');
    } finally {
        outputLoading.value = false;
    }
};
</script>

<style scoped>
@reference "../../../css/app.css";

.sortable-th {
    @apply px-5 py-3 text-left text-xs font-medium uppercase text-gray-500 dark:text-gray-400;
}
</style>
