<template>
    <Head :title="`${t('Logs')} - ${domain.fqdn}`" />
    <ThemeProvider>
        <SidebarProvider>
            <AdminLayout>
                <PageBreadcrumb
                    :pageTitle="t('Logs')"
                    :items="breadcrumbs"
                    :backHref="route('domains.show', domain.parent_domain_id ?? domain.id)"
                />

                <div class="space-y-4 md:space-y-6">
                    <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/3 md:p-6">
                        <h3 class="text-lg font-semibold text-gray-800 dark:text-white/90">{{ t('Domain Logs') }}</h3>
                        <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">
                            {{ t('Access and error logs are listed together in a single stream.') }}
                        </p>
                    </div>

                    <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/3 md:p-6">
                        <div class="mb-3 grid grid-cols-1 gap-3 md:grid-cols-4">
                            <input
                                v-model="filters.q"
                                type="text"
                                class="form-input"
                                :placeholder="t('Search request, status, message...')"
                            />
                            <input
                                v-model="filters.ip"
                                type="text"
                                class="form-input"
                                :placeholder="t('IP Filter')"
                            />
                            <div class="flex items-center gap-2">
                                <button
                                    type="button"
                                    :disabled="loading || loadingMore"
                                    class="inline-flex h-11 flex-1 items-center justify-center gap-2 rounded-lg border border-gray-300 bg-white px-3 text-sm font-medium text-gray-700 hover:bg-gray-50 disabled:opacity-60 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-300"
                                    @click="refreshLogs"
                                >
                                    <i :class="loading ? 'bx bx-loader-alt animate-spin' : 'bx bx-refresh'" class="text-base"></i>
                                    {{ t('Refresh') }}
                                </button>
                                <label class="inline-flex h-11 items-center gap-2 rounded-lg border border-gray-300 px-3 text-sm text-gray-700 dark:border-gray-700 dark:text-gray-300">
                                    <input v-model="autoRefresh" type="checkbox" class="form-checkbox" />
                                    {{ t('Auto') }}
                                </label>
                            </div>
                            <div class="hidden md:block"></div>
                        </div>

                        <div v-if="errorMessage !== ''" class="mb-3 rounded-lg border border-error-500/40 bg-error-500/10 px-3 py-2 text-xs text-error-700 dark:text-error-300">
                            {{ errorMessage }}
                        </div>

                        <div v-if="entries.length === 0" class="rounded-lg border border-dashed border-gray-300 px-4 py-8 text-center text-sm text-gray-500 dark:border-gray-700 dark:text-gray-400">
                            {{ loading ? t('Loading logs...') : t('No logs found.') }}
                        </div>

                        <div
                            ref="tableContainerRef"
                            class="max-h-[620px] overflow-auto rounded-lg border border-gray-200 dark:border-gray-800"
                            @scroll.passive="handleScroll"
                        >
                            <table class="w-full min-w-[1080px] text-sm">
                                <thead>
                                    <tr class="border-b border-gray-200 text-left text-xs uppercase text-gray-500 dark:border-gray-800 dark:text-gray-400">
                                        <th class="sticky top-0 z-10 bg-white px-3 py-2 dark:bg-gray-900">{{ t('Date') }}</th>
                                        <th class="sticky top-0 z-10 bg-white px-3 py-2 dark:bg-gray-900">{{ t('Type') }}</th>
                                        <th class="sticky top-0 z-10 bg-white px-3 py-2 dark:bg-gray-900">{{ t('IP') }}</th>
                                        <th class="sticky top-0 z-10 bg-white px-3 py-2 dark:bg-gray-900">{{ t('Request') }}</th>
                                        <th class="sticky top-0 z-10 bg-white px-3 py-2 dark:bg-gray-900">{{ t('Status') }}</th>
                                        <th class="sticky top-0 z-10 bg-white px-3 py-2 dark:bg-gray-900">{{ t('Message') }}</th>
                                        <th class="sticky top-0 z-10 bg-white px-3 py-2 dark:bg-gray-900">{{ t('Source') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="entry in entries" :key="`${entry.ts}-${entry.source}-${entry.ip}-${entry.request}-${entry.status}-${entry.message}`" class="border-b border-gray-100 align-top last:border-0 dark:border-gray-800">
                                        <td class="px-3 py-2 text-xs text-gray-500 dark:text-gray-400">{{ formatDateTime(entry.ts) }}</td>
                                        <td class="px-3 py-2">
                                            <span
                                                :class="[
                                                    'inline-flex rounded-full px-2 py-0.5 text-xs font-semibold',
                                                    entry.type === 'error'
                                                        ? 'bg-error-500/15 text-error-700 dark:text-error-300'
                                                        : 'bg-success-500/15 text-success-700 dark:text-success-300',
                                                ]"
                                            >
                                                {{ entry.type }}
                                            </span>
                                        </td>
                                        <td class="px-3 py-2 font-mono text-xs text-gray-700 dark:text-gray-300">{{ entry.ip }}</td>
                                        <td class="px-3 py-2 text-xs text-gray-700 dark:text-gray-300">{{ entry.request }}</td>
                                        <td class="px-3 py-2 font-mono text-xs text-gray-700 dark:text-gray-300">{{ entry.status }}</td>
                                        <td class="px-3 py-2 text-xs text-gray-600 dark:text-gray-400">{{ entry.message }}</td>
                                        <td class="px-3 py-2 font-mono text-[11px] text-gray-500 dark:text-gray-400">{{ entry.source }}</td>
                                    </tr>
                                </tbody>
                            </table>

                            <div v-if="loadingMore" class="px-3 py-3 text-center text-xs text-gray-500 dark:text-gray-400">
                                {{ t('Loading more...') }}
                            </div>
                            <div v-else-if="!hasMore" class="px-3 py-3 text-center text-xs text-gray-500 dark:text-gray-400">
                                {{ t('No more logs.') }}
                            </div>
                        </div>

                        <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">{{ t('Auto refresh is disabled by default. Enable it when you need live monitoring.') }}</p>
                    </div>
                </div>
            </AdminLayout>
        </SidebarProvider>
    </ThemeProvider>
</template>

<script setup lang="ts">
import { computed, onMounted, onUnmounted, reactive, ref, watch } from 'vue';
import { Head } from '@inertiajs/vue3';
import axios from 'axios';
import ThemeProvider from '@/Components/Layout/ThemeProvider.vue';
import SidebarProvider from '@/Components/Layout/SidebarProvider.vue';
import AdminLayout from '@/Components/Layout/AdminLayout.vue';
import PageBreadcrumb from '@/Components/Common/PageBreadcrumb.vue';
import { useI18n } from '@/Composables/useI18n';
import { formatDateTime } from '@/utils/dateTime';

interface DomainLogEntry {
    ts: string | null;
    type: string;
    level: string;
    ip: string;
    request: string;
    status: string;
    message: string;
    source: string;
}

const props = defineProps<{
    domain: Record<string, any>;
}>();

const { t } = useI18n();
const domain = computed(() => props.domain);
const breadcrumbs = computed(() => [
    { label: t('Domains'), href: route('domains.index') },
    { label: domain.value.fqdn, href: route('domains.show', domain.value.parent_domain_id ?? domain.value.id) },
    { label: t('Logs') },
]);

const entries = ref<DomainLogEntry[]>([]);
const loading = ref(false);
const loadingMore = ref(false);
const errorMessage = ref('');
const autoRefresh = ref(false);
const hasMore = ref(true);
const beforeCursor = ref<string | null>(null);
const tableContainerRef = ref<HTMLElement | null>(null);
const filters = reactive({
    q: '',
    ip: '',
});
let timer: ReturnType<typeof setInterval> | null = null;
let searchTimer: ReturnType<typeof setTimeout> | null = null;
const PAGE_LIMIT = 100;

const refreshLogs = async (): Promise<void> => {
    await loadLogs(true);
};

const loadLogs = async (reset: boolean): Promise<void> => {
    try {
        if (reset) {
            loading.value = true;
            hasMore.value = true;
            beforeCursor.value = null;
        } else {
            if (!hasMore.value || loadingMore.value) {
                return;
            }
            loadingMore.value = true;
        }

        const response = await axios.get(route('domains.logs.entries', domain.value.id), {
            params: {
                q: filters.q,
                ip: filters.ip,
                limit: PAGE_LIMIT,
                before: reset ? '' : (beforeCursor.value ?? ''),
            },
        });

        const payload = Array.isArray(response.data?.entries) ? response.data.entries as DomainLogEntry[] : [];

        if (reset) {
            entries.value = payload;
        } else {
            const merged = [...entries.value, ...payload];
            const seen = new Set<string>();
            entries.value = merged.filter((entry) => {
                const key = `${entry.ts}-${entry.source}-${entry.ip}-${entry.request}-${entry.status}-${entry.message}`;
                if (seen.has(key)) {
                    return false;
                }
                seen.add(key);
                return true;
            });
        }

        const lastEntryWithTs = [...entries.value].reverse().find((entry) => typeof entry.ts === 'string' && entry.ts !== '');
        beforeCursor.value = lastEntryWithTs?.ts ?? null;
        hasMore.value = payload.length === PAGE_LIMIT && beforeCursor.value !== null;
        errorMessage.value = '';
    } catch {
        errorMessage.value = t('Logs could not be loaded.');
    } finally {
        loading.value = false;
        loadingMore.value = false;
    }
};

const handleScroll = (): void => {
    if (!tableContainerRef.value || loading.value || loadingMore.value || !hasMore.value) {
        return;
    }

    const { scrollTop, clientHeight, scrollHeight } = tableContainerRef.value;
    if (scrollTop + clientHeight >= scrollHeight - 120) {
        void loadLogs(false);
    }
};

watch(autoRefresh, (enabled) => {
    if (timer) {
        clearInterval(timer);
        timer = null;
    }

    if (enabled) {
        timer = setInterval(() => {
            void loadLogs(true);
        }, 3000);
    }
});

watch(() => [filters.q, filters.ip], () => {
    if (searchTimer) {
        clearTimeout(searchTimer);
        searchTimer = null;
    }

    searchTimer = setTimeout(() => {
        void loadLogs(true);
    }, 250);
});

onMounted(() => {
    void loadLogs(true);
});

onUnmounted(() => {
    if (timer) {
        clearInterval(timer);
        timer = null;
    }

    if (searchTimer) {
        clearTimeout(searchTimer);
        searchTimer = null;
    }
});
</script>

<style scoped>
@reference "../../../css/app.css";

.form-input {
    @apply h-11 w-full rounded-lg border border-gray-300 bg-transparent px-4 py-2.5 text-sm text-gray-800 shadow-theme-xs placeholder:text-gray-400 focus:border-brand-300 focus:outline-hidden focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90 dark:placeholder:text-white/30 dark:focus:border-brand-800;
}

.form-checkbox {
    @apply h-4 w-4 rounded border-gray-300 text-brand-500 focus:ring-brand-500;
}
</style>
