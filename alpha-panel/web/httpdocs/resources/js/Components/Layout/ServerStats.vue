<template>
    <div
        v-if="isExpanded || isHovered || isMobileOpen"
        class="border-b border-gray-200 dark:border-gray-800 pb-3 mb-1"
    >
        <div class="flex items-center justify-between mb-2">
            <span class="text-[10px] font-semibold uppercase tracking-wider text-gray-400 dark:text-gray-500">
                {{ t('Server') }}
            </span>
            <span class="text-[10px] text-gray-400 dark:text-gray-500">
                {{ uptimeFormatted }}
            </span>
        </div>

        <div v-if="stats && !stats.has_error" class="flex flex-col gap-1.5">
            <div v-for="bar in bars" :key="bar.label">
                <div class="flex items-center justify-between mb-0.5">
                    <span class="text-[11px] text-gray-500 dark:text-gray-400 w-8 shrink-0">{{ bar.label }}</span>
                    <div class="flex-1 mx-2 h-1 rounded-full bg-gray-200 dark:bg-gray-700 overflow-hidden">
                        <div
                            :class="barColor(bar.pct)"
                            class="h-full rounded-full transition-all duration-700"
                            :style="{ width: bar.pct + '%' }"
                        ></div>
                    </div>
                    <span class="text-[11px] text-gray-500 dark:text-gray-400 text-right shrink-0 w-16 truncate">{{ bar.label2 }}</span>
                </div>
            </div>

            <div class="flex items-center justify-between mt-0.5">
                <span class="text-[10px] text-gray-400 dark:text-gray-500">
                    {{ t('Load') }}: {{ stats.load_1 }}
                </span>
                <span :class="['w-1.5 h-1.5 rounded-full shrink-0', dotColor]"></span>
            </div>
        </div>

        <div v-else-if="stats?.has_error" class="text-[10px] text-red-400 dark:text-red-500">
            <i class="fa-solid fa-triangle-exclamation mr-1"></i>{{ t('Unavailable') }}
        </div>

        <div v-else class="flex flex-col gap-1.5 animate-pulse">
            <div v-for="i in 3" :key="i" class="h-1 rounded-full bg-gray-200 dark:bg-gray-700"></div>
        </div>
    </div>

    <!-- Collapsed: health dot only -->
    <div v-else class="flex justify-center pb-3">
        <span :class="['w-2.5 h-2.5 rounded-full', dotColor]" :title="t('Server')"></span>
    </div>
</template>

<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref } from 'vue';
import axios from 'axios';
import { useSidebar } from '@/Composables/useSidebar';
import { useI18n } from '@/Composables/useI18n';

interface StatsData {
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

const { isExpanded, isHovered, isMobileOpen } = useSidebar();
const { t } = useI18n();

const stats = ref<StatsData | null>(null);
let pollInterval: ReturnType<typeof setInterval> | null = null;

const fetchStats = async (): Promise<void> => {
    try {
        const res = await axios.get<StatsData>(route('server-stats.index'));
        stats.value = res.data;
    } catch {
        // silently ignore — server may be unreachable
    }
};

onMounted(() => {
    void fetchStats();
    pollInterval = setInterval(() => void fetchStats(), 30_000);
});

onUnmounted(() => {
    if (pollInterval !== null) {
        clearInterval(pollInterval);
    }
});

const barColor = (pct: number): string => {
    if (pct >= 85) return 'bg-red-500';
    if (pct >= 60) return 'bg-yellow-500';
    return 'bg-green-500';
};

const dotColor = computed((): string => {
    if (!stats.value || stats.value.has_error) return 'bg-gray-400 dark:bg-gray-600';
    const max = Math.max(stats.value.cpu_percent, stats.value.mem_percent, stats.value.disk_percent);
    if (max >= 85) return 'bg-red-500';
    if (max >= 60) return 'bg-yellow-500';
    return 'bg-green-500';
});

const uptimeFormatted = computed((): string => {
    if (!stats.value || stats.value.has_error) return '—';
    const sec = stats.value.uptime_seconds;
    const d = Math.floor(sec / 86400);
    const h = Math.floor((sec % 86400) / 3600);
    const m = Math.floor((sec % 3600) / 60);
    const parts: string[] = [];
    if (d > 0) parts.push(`${d}d`);
    if (h > 0) parts.push(`${h}h`);
    if (m > 0 || !parts.length) parts.push(`${m}m`);
    return parts.join(' ');
});

const fmtMb = (mb: number): string => (mb >= 1024 ? `${(mb / 1024).toFixed(1)}G` : `${mb}M`);

const bars = computed(() => {
    if (!stats.value || stats.value.has_error) return [];
    return [
        {
            label: 'CPU',
            pct: stats.value.cpu_percent,
            label2: `${stats.value.cpu_percent}%`,
        },
        {
            label: 'RAM',
            pct: stats.value.mem_percent,
            label2: `${fmtMb(stats.value.mem_used_mb)}/${fmtMb(stats.value.mem_total_mb)}`,
        },
        {
            label: t('Disk'),
            pct: stats.value.disk_percent,
            label2: `${stats.value.disk_used_gb}/${stats.value.disk_total_gb}G`,
        },
    ];
});
</script>
