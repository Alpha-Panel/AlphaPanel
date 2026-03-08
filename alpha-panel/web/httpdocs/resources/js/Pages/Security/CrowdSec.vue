<template>
    <Head :title="t('CrowdSec Security')" />
    <ThemeProvider>
        <SidebarProvider>
            <AdminLayout>
                <PageBreadcrumb :pageTitle="t('CrowdSec Security')" />
                <Toast />

                <div class="space-y-4">
                    <div class="grid grid-cols-1 gap-4 md:grid-cols-4">
                        <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
                            <p class="text-xs uppercase text-gray-500 dark:text-gray-400">{{ t('LAPI') }}</p>
                            <p class="mt-1 text-2xl font-semibold text-gray-800 dark:text-white/90">
                                {{ summary.configured ? (summary.lapi_online ? t('Online') : t('Offline')) : t('Not configured') }}
                            </p>
                        </div>
                        <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
                            <p class="text-xs uppercase text-gray-500 dark:text-gray-400">{{ t('Active Decisions') }}</p>
                            <p class="mt-1 text-2xl font-semibold text-gray-800 dark:text-white/90">{{ summary.active_decisions }}</p>
                        </div>
                        <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
                            <p class="text-xs uppercase text-gray-500 dark:text-gray-400">{{ t('Alerts (24h)') }}</p>
                            <p class="mt-1 text-2xl font-semibold text-gray-800 dark:text-white/90">{{ summary.recent_alerts_24h }}</p>
                        </div>
                        <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
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

                    <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
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

                    <div class="grid grid-cols-1 gap-4 xl:grid-cols-2">
                        <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
                            <h3 class="mb-3 text-sm font-semibold text-gray-800 dark:text-white/90">{{ t('Recent Alerts') }}</h3>
                            <div class="max-h-[360px] overflow-auto">
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

                        <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
                            <h3 class="mb-3 text-sm font-semibold text-gray-800 dark:text-white/90">{{ t('Active Decisions') }}</h3>
                            <div class="max-h-[360px] overflow-auto">
                                <table class="w-full text-sm">
                                    <thead>
                                        <tr class="border-b border-gray-200 text-left text-xs uppercase text-gray-500 dark:border-gray-800 dark:text-gray-400">
                                            <th class="pb-2">{{ t('Value') }}</th>
                                            <th class="pb-2">{{ t('Type') }}</th>
                                            <th class="pb-2">{{ t('Until') }}</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr v-if="activeDecisions.length === 0">
                                            <td colspan="3" class="py-4 text-center text-gray-500 dark:text-gray-400">{{ t('No active decisions.') }}</td>
                                        </tr>
                                        <tr
                                            v-for="decision in activeDecisions"
                                            :key="`${decision.value}-${decision.type}-${decision.until}`"
                                            class="border-b border-gray-100 align-top last:border-0 dark:border-gray-800"
                                        >
                                            <td class="py-2 font-mono text-xs text-gray-700 dark:text-gray-300">{{ decision.value }}</td>
                                            <td class="py-2 text-xs text-gray-700 dark:text-gray-300">{{ decision.type }}</td>
                                            <td class="py-2 text-xs text-gray-500 dark:text-gray-400">{{ formatDate(decision.until) }}</td>
                                        </tr>
                                    </tbody>
                                </table>
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
    active_decisions: CrowdSecDecision[];
}

const props = defineProps<{ crowdsec: CrowdSecPayload }>();
const { t } = useI18n();
const { addToast } = useToast();

const payload = ref<CrowdSecPayload>(props.crowdsec);
const summary = computed(() => payload.value.summary);
const recentAlerts = computed(() => payload.value.recent_alerts ?? []);
const activeDecisions = computed(() => payload.value.active_decisions ?? []);

let refreshTimer: ReturnType<typeof setInterval> | null = null;

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

onMounted(() => {
    refreshTimer = setInterval(() => {
        void refreshData();
    }, 30000);
});

onUnmounted(() => {
    if (refreshTimer) {
        clearInterval(refreshTimer);
        refreshTimer = null;
    }
});
</script>
