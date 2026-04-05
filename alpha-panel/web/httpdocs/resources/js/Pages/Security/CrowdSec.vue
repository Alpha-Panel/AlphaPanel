<template>
    <Head :title="t('CrowdSec Security')" />
    <ThemeProvider>
        <SidebarProvider>
            <AdminLayout>
                <PageBreadcrumb :pageTitle="t('CrowdSec Security')" />
                <Toast />

                <div class="space-y-4">
                    <div class="grid grid-cols-1 gap-4 md:grid-cols-4">
                        <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/3">
                            <p class="text-xs uppercase text-gray-500 dark:text-gray-400">{{ t('LAPI') }}</p>
                            <p class="mt-1 text-2xl font-semibold text-gray-800 dark:text-white/90">
                                {{ summary.configured ? (summary.lapi_online ? t('Online') : t('Offline')) : t('Not configured') }}
                            </p>
                        </div>
                        <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/3">
                            <p class="text-xs uppercase text-gray-500 dark:text-gray-400">{{ t('Active Decisions') }}</p>
                            <p class="mt-1 text-2xl font-semibold text-gray-800 dark:text-white/90">{{ summary.active_decisions }}</p>
                        </div>
                        <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/3">
                            <p class="text-xs uppercase text-gray-500 dark:text-gray-400">{{ t('Alerts (24h)') }}</p>
                            <p class="mt-1 text-2xl font-semibold text-gray-800 dark:text-white/90">{{ summary.recent_alerts_24h }}</p>
                        </div>
                        <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/3">
                            <p class="text-xs uppercase text-gray-500 dark:text-gray-400">{{ t('Last Sync') }}</p>
                            <p class="mt-1 text-sm font-semibold text-gray-800 dark:text-white/90">{{ formatDate(summary.last_sync_at) }}</p>
                            <button
                                type="button"
                                class="mt-2 inline-flex h-8 items-center justify-center rounded-lg border border-gray-300 px-3 text-xs font-medium text-gray-700 hover:bg-gray-100 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-gray-800"
                                @click="refreshData"
                            >
                                {{ t('Refresh') }}
                            </button>
                        </div>
                    </div>

                    <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/3">
                        <h3 class="mb-3 text-sm font-semibold text-gray-800 dark:text-white/90">
                            <i class="bx bx-bar-chart-alt-2 mr-1"></i>
                            {{ t('Top Scenarios') }}
                        </h3>
                        <div v-if="summary.top_scenarios.length === 0" class="text-sm text-gray-500 dark:text-gray-400">
                            {{ t('No scenario data.') }}
                        </div>
                        <div v-else class="space-y-2">
                            <div
                                v-for="scenario in summary.top_scenarios"
                                :key="scenario.name"
                                class="flex items-center justify-between rounded-lg border border-gray-200 px-3 py-2 text-sm dark:border-gray-800"
                            >
                                <span class="font-medium text-gray-700 dark:text-gray-300">{{ scenario.name }}</span>
                                <span class="rounded-full bg-brand-500/15 px-2 py-0.5 text-xs font-semibold text-brand-600 dark:text-brand-300">
                                    {{ scenario.count }}
                                </span>
                            </div>
                        </div>
                    </div>

                    <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/3">
                        <h3 class="mb-3 text-sm font-semibold text-gray-800 dark:text-white/90">{{ t('Recent Alerts') }}</h3>
                        <div class="overflow-auto">
                            <table class="w-full text-sm">
                                <thead>
                                    <tr class="border-b border-gray-200 text-left text-xs uppercase text-gray-500 dark:border-gray-800 dark:text-gray-400">
                                        <th class="pb-2">{{ t('Value') }}</th>
                                        <th class="pb-2">{{ t('Scenario') }}</th>
                                        <th class="pb-2">{{ t('Created') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-if="recentAlerts.length === 0">
                                        <td colspan="3" class="py-4 text-center text-gray-500 dark:text-gray-400">{{ t('No alerts found.') }}</td>
                                    </tr>
                                    <tr
                                        v-for="alert in recentAlerts"
                                        :key="`${alert.value}-${alert.created_at}-${alert.scenario}`"
                                        class="border-b border-gray-100 align-top last:border-0 dark:border-gray-800"
                                    >
                                        <td class="py-2 font-mono text-xs text-gray-700 dark:text-gray-300">{{ alert.value }}</td>
                                        <td class="py-2 text-xs text-gray-700 dark:text-gray-300">{{ alert.scenario }}</td>
                                        <td class="py-2 text-xs text-gray-500 dark:text-gray-400">{{ formatDate(alert.created_at) }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/3">
                        <div class="mb-3 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                            <h3 class="text-sm font-semibold text-gray-800 dark:text-white/90">
                                {{ t('Active Decisions') }}
                                <span v-if="decisionsPagination.total > 0" class="ml-1 text-xs font-normal text-gray-500 dark:text-gray-400">
                                    ({{ decisionsPagination.total }})
                                </span>
                            </h3>
                            <div class="flex items-center gap-2">
                                <input
                                    v-model="decisionsSearch"
                                    type="text"
                                    :placeholder="t('Search IP, scenario...')"
                                    class="h-8 w-56 rounded-lg border border-gray-300 bg-transparent px-3 text-xs text-gray-700 placeholder-gray-400 outline-none focus:border-brand-500 focus:ring-1 focus:ring-brand-500 dark:border-gray-700 dark:text-gray-300 dark:placeholder-gray-500"
                                    @input="onSearchInput"
                                />
                            </div>
                        </div>

                        <div v-if="decisionsLoading && decisions.length === 0" class="py-8 text-center text-sm text-gray-500 dark:text-gray-400">
                            {{ t('Loading...') }}
                        </div>

                        <div v-else>
                            <div class="overflow-auto">
                                <table class="w-full text-sm">
                                    <thead>
                                        <tr class="border-b border-gray-200 text-left text-xs uppercase text-gray-500 dark:border-gray-800 dark:text-gray-400">
                                            <th class="pb-2">{{ t('Value') }}</th>
                                            <th class="pb-2">{{ t('Type') }}</th>
                                            <th class="pb-2">{{ t('Origin') }}</th>
                                            <th class="pb-2">{{ t('Scenario') }}</th>
                                            <th class="pb-2">{{ t('Until') }}</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr v-if="decisions.length === 0">
                                            <td colspan="5" class="py-4 text-center text-gray-500 dark:text-gray-400">{{ t('No active decisions.') }}</td>
                                        </tr>
                                        <tr
                                            v-for="decision in decisions"
                                            :key="`${decision.value}-${decision.type}-${decision.until}`"
                                            class="border-b border-gray-100 align-top last:border-0 dark:border-gray-800"
                                        >
                                            <td class="py-2 font-mono text-xs text-gray-700 dark:text-gray-300">{{ decision.value }}</td>
                                            <td class="py-2 text-xs text-gray-700 dark:text-gray-300">{{ decision.type }}</td>
                                            <td class="py-2 text-xs text-gray-700 dark:text-gray-300">{{ decision.origin }}</td>
                                            <td class="py-2 text-xs text-gray-700 dark:text-gray-300">{{ decision.scenario }}</td>
                                            <td class="py-2 text-xs text-gray-500 dark:text-gray-400">{{ formatDate(decision.until) }}</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>

                            <div v-if="decisionsPagination.last_page > 1" class="mt-4 flex items-center justify-between border-t border-gray-200 pt-3 dark:border-gray-800">
                                <p class="text-xs text-gray-500 dark:text-gray-400">
                                    {{ decisionsPagination.total }} {{ t('results') }}
                                </p>
                                <div class="flex flex-wrap gap-1">
                                    <button
                                        type="button"
                                        :disabled="decisionsPagination.current_page <= 1 || decisionsLoading"
                                        class="inline-flex h-8 min-w-8 items-center justify-center rounded-lg border border-gray-300 px-2 text-xs font-medium text-gray-700 hover:bg-gray-100 disabled:cursor-not-allowed disabled:opacity-40 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-gray-800"
                                        @click="loadDecisions(decisionsPagination.current_page - 1)"
                                    >
                                        &lsaquo;
                                    </button>
                                    <template v-for="page in paginationPages" :key="page">
                                        <span
                                            v-if="page === '...'"
                                            class="inline-flex h-8 min-w-8 items-center justify-center text-xs text-gray-400 dark:text-gray-500"
                                        >...</span>
                                        <button
                                            v-else
                                            type="button"
                                            :disabled="decisionsLoading"
                                            :class="[
                                                'inline-flex h-8 min-w-8 items-center justify-center rounded-lg border px-2 text-xs font-medium',
                                                page === decisionsPagination.current_page
                                                    ? 'border-brand-500 bg-brand-500 text-white'
                                                    : 'border-gray-300 text-gray-700 hover:bg-gray-100 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-gray-800',
                                                decisionsLoading ? 'cursor-not-allowed opacity-40' : '',
                                            ]"
                                            @click="loadDecisions(page as number)"
                                        >
                                            {{ page }}
                                        </button>
                                    </template>
                                    <button
                                        type="button"
                                        :disabled="decisionsPagination.current_page >= decisionsPagination.last_page || decisionsLoading"
                                        class="inline-flex h-8 min-w-8 items-center justify-center rounded-lg border border-gray-300 px-2 text-xs font-medium text-gray-700 hover:bg-gray-100 disabled:cursor-not-allowed disabled:opacity-40 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-gray-800"
                                        @click="loadDecisions(decisionsPagination.current_page + 1)"
                                    >
                                        &rsaquo;
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
import { computed, onMounted, onUnmounted, ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import axios from 'axios';
import ThemeProvider from '@/Components/Layout/ThemeProvider.vue';
import SidebarProvider from '@/Components/Layout/SidebarProvider.vue';
import AdminLayout from '@/Components/Layout/AdminLayout.vue';
import PageBreadcrumb from '@/Components/Common/PageBreadcrumb.vue';
import Toast from '@/Components/UI/Toast.vue';
import { useToast } from '@/Composables/useToast';
import { useI18n } from '@/Composables/useI18n';

interface CrowdSecSummary {
    configured: boolean;
    has_error: boolean;
    lapi_online: boolean;
    status_code: number | null;
    active_decisions: number;
    recent_alerts_24h: number;
    top_scenarios: Array<{ name: string; count: number }>;
    last_sync_at: string;
}

interface CrowdSecAlert {
    value: string;
    scenario: string;
    reason: string;
    created_at: string | null;
}

interface CrowdSecDecision {
    value: string;
    scope: string;
    type: string;
    origin: string;
    scenario: string;
    until: string | null;
}

interface CrowdSecPayload {
    summary: CrowdSecSummary;
    recent_alerts: CrowdSecAlert[];
}

interface DecisionsPagination {
    total: number;
    per_page: number;
    current_page: number;
    last_page: number;
}

const props = defineProps<{ crowdsec: CrowdSecPayload }>();
const { t } = useI18n();
const { addToast } = useToast();

const payload = ref<CrowdSecPayload>(props.crowdsec);
const summary = computed(() => payload.value.summary);
const recentAlerts = computed(() => payload.value.recent_alerts ?? []);

const decisions = ref<CrowdSecDecision[]>([]);
const decisionsLoading = ref(false);
const decisionsSearch = ref('');
const decisionsPagination = ref<DecisionsPagination>({
    total: 0,
    per_page: 50,
    current_page: 1,
    last_page: 1,
});

const paginationPages = computed((): Array<number | string> => {
    const current = decisionsPagination.value.current_page;
    const last = decisionsPagination.value.last_page;
    const delta = 2;
    const pages: Array<number | string> = [];
    let prev = 0;

    for (let i = 1; i <= last; i++) {
        if (i === 1 || i === last || (i >= current - delta && i <= current + delta)) {
            if (prev && i - prev > 1) {
                pages.push('...');
            }
            pages.push(i);
            prev = i;
        }
    }

    return pages;
});

let refreshTimer: ReturnType<typeof setInterval> | null = null;
let searchDebounce: ReturnType<typeof setTimeout> | null = null;

const formatDate = (value: string | null): string => {
    if (!value) return '-';
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return value;
    return date.toLocaleString();
};

const refreshData = async (): Promise<void> => {
    try {
        const response = await axios.get<CrowdSecPayload>(route('security.crowdsec.data'));
        payload.value = response.data;
    } catch {
        addToast('error', t('CrowdSec data could not be refreshed.'));
    }
};

const loadDecisions = async (page: number = 1): Promise<void> => {
    decisionsLoading.value = true;
    try {
        const params: Record<string, string | number> = { page, per_page: 50 };
        if (decisionsSearch.value.trim() !== '') {
            params.search = decisionsSearch.value.trim();
        }
        const response = await axios.get<{
            data: CrowdSecDecision[];
            total: number;
            per_page: number;
            current_page: number;
            last_page: number;
        }>(route('security.crowdsec.decisions'), { params });

        decisions.value = response.data.data;
        decisionsPagination.value = {
            total: response.data.total,
            per_page: response.data.per_page,
            current_page: response.data.current_page,
            last_page: response.data.last_page,
        };
    } catch {
        addToast('error', t('Decisions could not be loaded.'));
    } finally {
        decisionsLoading.value = false;
    }
};

const onSearchInput = (): void => {
    if (searchDebounce) {
        clearTimeout(searchDebounce);
    }
    searchDebounce = setTimeout(() => {
        void loadDecisions(1);
    }, 400);
};

onMounted(() => {
    void loadDecisions(1);

    refreshTimer = setInterval(() => {
        void refreshData();
    }, 30000);
});

onUnmounted(() => {
    if (refreshTimer) {
        clearInterval(refreshTimer);
        refreshTimer = null;
    }
    if (searchDebounce) {
        clearTimeout(searchDebounce);
        searchDebounce = null;
    }
});
</script>
