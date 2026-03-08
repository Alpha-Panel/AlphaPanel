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
const streamSocket = ref<WebSocket | null>(null);
const streamConnected = ref(false);
const streamBuffer = ref('');
const streamManualClose = ref(false);
const filters = reactive({
    q: '',
    ip: '',
});
let streamReconnectTimer: ReturnType<typeof setTimeout> | null = null;
let searchTimer: ReturnType<typeof setTimeout> | null = null;
const PAGE_LIMIT = 100;
const MAX_STREAM_ENTRIES = 2000;
const textDecoder = new TextDecoder();

const entryKey = (entry: DomainLogEntry): string => `${entry.ts}-${entry.source}-${entry.ip}-${entry.request}-${entry.status}-${entry.message}`;

const normalizeDateInput = (value: unknown): string | null => {
    if (typeof value !== 'string' && typeof value !== 'number') {
        return null;
    }

    const date = new Date(String(value));
    if (Number.isNaN(date.getTime())) {
        return null;
    }

    return date.toISOString();
};

const stringOrNull = (value: unknown): string | null => {
    if (typeof value === 'string') {
        const trimmed = value.trim();
        return trimmed !== '' ? trimmed : null;
    }

    if (typeof value === 'number') {
        return String(value);
    }

    return null;
};

const extractHeaderValue = (headers: unknown, headerName: string, useFirstCsvToken = false): string | null => {
    if (typeof headers !== 'object' || headers === null) {
        return null;
    }

    const map = headers as Record<string, unknown>;
    const key = Object.keys(map).find((item) => item.toLowerCase() === headerName.toLowerCase());
    if (!key) {
        return null;
    }

    let value: string | null = null;
    const raw = map[key];

    if (Array.isArray(raw)) {
        const firstString = raw.find((item): item is string => typeof item === 'string' && item.trim() !== '');
        value = firstString ? firstString.trim() : null;
    } else {
        value = stringOrNull(raw);
    }

    if (!value) {
        return null;
    }

    if (!useFirstCsvToken) {
        return value;
    }

    const firstToken = value.split(',')[0]?.trim() ?? '';
    return firstToken !== '' ? firstToken : null;
};

const parseJsonLogLine = (line: string): DomainLogEntry | null => {
    let decoded: Record<string, unknown>;

    try {
        decoded = JSON.parse(line) as Record<string, unknown>;
    } catch {
        return null;
    }

    if (typeof decoded !== 'object' || decoded === null) {
        return null;
    }

    const request = (typeof decoded.request === 'object' && decoded.request !== null)
        ? decoded.request as Record<string, unknown>
        : {};
    const requestHeaders = request.headers;

    const ip = stringOrNull(request.client_ip)
        ?? stringOrNull(request.remote_ip)
        ?? stringOrNull(decoded.remote_ip)
        ?? extractHeaderValue(requestHeaders, 'CF-Connecting-IP')
        ?? extractHeaderValue(requestHeaders, 'X-Forwarded-For', true)
        ?? '-';

    const method = stringOrNull(request.method) ?? '-';
    const uri = stringOrNull(request.uri) ?? '-';
    const status = stringOrNull(decoded.status ?? decoded.status_code) ?? '-';
    const level = (stringOrNull(decoded.level) ?? 'info').toLowerCase();
    const message = stringOrNull(decoded.msg ?? decoded.message) ?? '-';
    const ts = normalizeDateInput(decoded.ts ?? decoded.time ?? decoded.timestamp);

    return {
        ts,
        type: level.includes('error') ? 'error' : 'access',
        level,
        ip,
        request: `${method} ${uri}`.trim(),
        status,
        message,
        source: '/stream',
    };
};

const parseApacheAccessLine = (line: string): DomainLogEntry | null => {
    const match = line.match(/^(?<ip>\S+) \S+ \S+ \[(?<time>[^\]]+)\] "(?<method>[A-Z]+) (?<uri>[^"]+?) (?<proto>[^"]+)" (?<status>\d{3}|-) (?<bytes>\S+)/);
    if (!match?.groups) {
        return null;
    }

    return {
        ts: normalizeDateInput(match.groups.time),
        type: 'access',
        level: 'info',
        ip: match.groups.ip ?? '-',
        request: `${match.groups.method ?? '-'} ${match.groups.uri ?? '-'}`.trim(),
        status: match.groups.status ?? '-',
        message: line,
        source: '/stream',
    };
};

const parseApacheErrorLine = (line: string): DomainLogEntry | null => {
    const match = line.match(/^\[(?<time>[^\]]+)\]\s+\[(?<module>[^\]]+)\]\s+\[pid\s+\d+(?::tid\s+\d+)?\](?:\s+\[client\s+(?<client>[^\]]+)\])?\s*(?<message>.*)$/);
    if (!match?.groups) {
        return null;
    }

    const client = (match.groups.client ?? '').trim();
    const ip = client !== '' ? (client.split(':')[0] ?? '-') : '-';

    return {
        ts: normalizeDateInput(match.groups.time),
        type: 'error',
        level: (match.groups.module ?? 'error').toLowerCase(),
        ip,
        request: '-',
        status: '-',
        message: (match.groups.message ?? line).trim(),
        source: '/stream',
    };
};

const parseStreamLine = (line: string): DomainLogEntry | null => {
    return parseJsonLogLine(line)
        ?? parseApacheAccessLine(line)
        ?? parseApacheErrorLine(line)
        ?? {
            ts: normalizeDateInput(new Date().toISOString()),
            type: 'access',
            level: 'info',
            ip: '-',
            request: '-',
            status: '-',
            message: line,
            source: '/stream',
        };
};

const matchesFilters = (entry: DomainLogEntry): boolean => {
    const query = filters.q.trim().toLowerCase();
    const ipFilter = filters.ip.trim().toLowerCase();

    if (ipFilter !== '' && !entry.ip.toLowerCase().includes(ipFilter)) {
        return false;
    }

    if (query === '') {
        return true;
    }

    const haystack = [
        entry.ip,
        entry.request,
        entry.status,
        entry.message,
        entry.source,
        entry.type,
        entry.level,
    ].join(' ').toLowerCase();

    return haystack.includes(query);
};

const refreshBeforeCursor = (): void => {
    const lastEntryWithTs = [...entries.value].reverse().find((entry) => typeof entry.ts === 'string' && entry.ts !== '');
    beforeCursor.value = lastEntryWithTs?.ts ?? null;
};

const mergeEntries = (incoming: DomainLogEntry[], prepend: boolean): void => {
    const merged = prepend
        ? [...incoming, ...entries.value]
        : [...entries.value, ...incoming];
    const seen = new Set<string>();
    const unique = merged.filter((entry) => {
        const key = entryKey(entry);
        if (seen.has(key)) {
            return false;
        }

        seen.add(key);
        return true;
    });

    entries.value = unique.slice(0, MAX_STREAM_ENTRIES);
    refreshBeforeCursor();
};

const decodeChunk = (data: string | ArrayBuffer): string => {
    if (typeof data === 'string') {
        return data;
    }

    return textDecoder.decode(new Uint8Array(data), { stream: true });
};

const consumeStreamChunk = (chunk: string): void => {
    streamBuffer.value += chunk;

    const lines = streamBuffer.value.split(/\r\n|\n|\r/);
    streamBuffer.value = lines.pop() ?? '';

    const parsed = lines
        .map((line) => line.trim())
        .filter((line) => line !== '')
        .map((line) => parseStreamLine(line))
        .filter((entry): entry is DomainLogEntry => entry !== null)
        .filter((entry) => matchesFilters(entry));

    if (parsed.length === 0) {
        return;
    }

    mergeEntries(parsed, true);
    errorMessage.value = '';
};

const clearReconnectTimer = (): void => {
    if (streamReconnectTimer) {
        clearTimeout(streamReconnectTimer);
        streamReconnectTimer = null;
    }
};

const scheduleReconnect = (): void => {
    clearReconnectTimer();

    streamReconnectTimer = setTimeout(() => {
        if (!autoRefresh.value) {
            return;
        }

        void startLogStream();
    }, 3000);
};

const stopLogStream = (): void => {
    clearReconnectTimer();
    streamConnected.value = false;
    streamBuffer.value = '';

    if (streamSocket.value) {
        const socket = streamSocket.value;
        streamManualClose.value = true;
        if (socket.readyState === WebSocket.CLOSED) {
            streamSocket.value = null;
            streamManualClose.value = false;
        } else {
            socket.close();
        }
    }
};

const startLogStream = async (): Promise<void> => {
    stopLogStream();
    streamManualClose.value = false;

    const newestTs = entries.value.find((entry) => typeof entry.ts === 'string' && entry.ts !== '')?.ts ?? '';

    try {
        const response = await axios.post(route('domains.logs.stream.start', domain.value.id), {
            since: newestTs,
        });
        const token = typeof response.data?.ws_token === 'string' ? response.data.ws_token : '';
        if (token === '') {
            throw new Error('Missing ws_token');
        }

        const protocol = window.location.protocol === 'https:' ? 'wss:' : 'ws:';
        const socket = new WebSocket(`${protocol}//${window.location.host}/terminal/ws?token=${token}`);
        socket.binaryType = 'arraybuffer';

        socket.onopen = () => {
            if (streamSocket.value !== socket) {
                return;
            }

            streamConnected.value = true;
            streamManualClose.value = false;
        };

        socket.onmessage = (event: MessageEvent<string | ArrayBuffer>) => {
            if (streamSocket.value !== socket) {
                return;
            }

            const chunk = decodeChunk(event.data);
            if (chunk !== '') {
                consumeStreamChunk(chunk);
            }
        };

        socket.onerror = () => {
            if (streamSocket.value !== socket) {
                return;
            }

            streamConnected.value = false;
        };

        socket.onclose = () => {
            if (streamSocket.value !== socket) {
                return;
            }

            const manual = streamManualClose.value;
            streamManualClose.value = false;
            streamConnected.value = false;
            streamSocket.value = null;

            if (autoRefresh.value && !manual) {
                scheduleReconnect();
            }
        };

        streamSocket.value = socket;
    } catch {
        streamConnected.value = false;
        errorMessage.value = t('Live stream could not be started. Falling back to manual refresh.');
        if (autoRefresh.value) {
            scheduleReconnect();
        }
    }
};

const restartLogStream = async (): Promise<void> => {
    if (!autoRefresh.value) {
        return;
    }

    await startLogStream();
};

const refreshLogs = async (): Promise<void> => {
    await loadLogs(true);
    if (autoRefresh.value) {
        await restartLogStream();
    }
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
            mergeEntries(payload, false);
        }

        if (reset) {
            refreshBeforeCursor();
        }

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
    if (enabled) {
        void startLogStream();
    } else {
        stopLogStream();
    }
});

watch(() => [filters.q, filters.ip], () => {
    if (searchTimer) {
        clearTimeout(searchTimer);
        searchTimer = null;
    }

    searchTimer = setTimeout(() => {
        void (async () => {
            await loadLogs(true);
            if (autoRefresh.value) {
                await restartLogStream();
            }
        })();
    }, 250);
});

onMounted(() => {
    void loadLogs(true);
});

onUnmounted(() => {
    stopLogStream();

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
