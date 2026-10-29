import { computed, onMounted, onUnmounted, ref } from 'vue';
import axios from 'axios';

export interface ServerStatsData {
    has_error: boolean;
    cpu_percent: number;
    mem_used_mb: number;
    mem_total_mb: number;
    mem_percent: number;
    disk_used_gb: number;
    disk_total_gb: number;
    disk_percent: number;
    uptime_seconds: number;
    load_1: number;
    load_5: number;
    load_15: number;
}

/** 0–59 ok · 60–84 warn · 85+ err */
export const toneOf = (pct: number): 'ok' | 'warn' | 'err' => (pct >= 85 ? 'err' : pct >= 60 ? 'warn' : 'ok');
export const toneBg = (pct: number): string => ({ ok: 'bg-ok', warn: 'bg-warn', err: 'bg-err' })[toneOf(pct)];

// Module-level singleton: sidebar + status bar share one subscription.
const stats = ref<ServerStatsData | null>(null);
let users = 0;
let lastBroadcastAt = 0;
let fallbackTimer: ReturnType<typeof setInterval> | null = null;
let channel: { stopListening: (e: string) => void } | null = null;
const STALE_MS = 90_000;

async function fetchStats(): Promise<void> {
    try {
        stats.value = (await axios.get<ServerStatsData>(route('server-stats.index'))).data;
    } catch {
        /* server may be unreachable */
    }
}

function start(): void {
    void fetchStats();
    const echo = (window as unknown as { Echo?: { private: (c: string) => any } }).Echo;
    if (echo?.private) {
        channel = echo.private('admin').listen('.ServerStatsBroadcast', (e: ServerStatsData) => {
            stats.value = e;
            lastBroadcastAt = Date.now();
        });
    }
    fallbackTimer = setInterval(() => {
        if (lastBroadcastAt === 0 || Date.now() - lastBroadcastAt > STALE_MS) void fetchStats();
    }, 60_000);
}

function stop(): void {
    if (fallbackTimer) clearInterval(fallbackTimer);
    fallbackTimer = null;
    channel?.stopListening('.ServerStatsBroadcast');
    channel = null;
}

export function useServerStats() {
    onMounted(() => {
        if (users++ === 0) start();
    });
    onUnmounted(() => {
        if (--users === 0) stop();
    });

    const uptime = computed(() => {
        const s = stats.value;
        if (!s || s.has_error) return '—';
        const d = Math.floor(s.uptime_seconds / 86400);
        const h = Math.floor((s.uptime_seconds % 86400) / 3600);
        const m = Math.floor((s.uptime_seconds % 3600) / 60);
        return [d && `${d}d`, h && `${h}h`, (m || (!d && !h)) && `${m}m`].filter(Boolean).join(' ');
    });

    const maxPct = computed(() => {
        const s = stats.value;
        return s && !s.has_error ? Math.max(s.cpu_percent, s.mem_percent, s.disk_percent) : null;
    });

    const healthDot = computed(() => (maxPct.value === null ? 'bg-fg-3' : toneBg(maxPct.value)));

    return { stats, uptime, healthDot, maxPct };
}
