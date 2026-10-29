<template>
    <div class="shrink-0 border-b border-line-2 px-3 pt-2.5 pb-2">
        <template v-if="wide">
            <div class="ap-eyebrow mb-1.5 flex justify-between">
                <span>{{ t('Server') }}</span>
                <span class="font-mono tracking-normal normal-case">{{ uptime }}</span>
            </div>

            <div v-if="stats && !stats.has_error" class="flex flex-col gap-1">
                <div v-for="bar in bars" :key="bar.label" class="flex items-center gap-2 text-[11px] text-fg-2">
                    <span class="w-7 shrink-0">{{ bar.label }}</span>
                    <div class="h-1 flex-1 overflow-hidden rounded-full bg-line-2">
                        <div class="h-full rounded-full transition-[width] duration-700" :class="toneBg(bar.pct)" :style="{ width: bar.pct + '%' }"></div>
                    </div>
                    <span class="w-15.5 truncate text-end font-mono text-2xs text-fg-3">{{ bar.text }}</span>
                </div>
            </div>
            <div v-else-if="stats?.has_error" class="flex items-center gap-1 text-2xs text-err">
                <i class="bx bx-error"></i>{{ t('Unavailable') }}
            </div>
            <div v-else class="flex animate-pulse flex-col gap-2 py-1">
                <div v-for="i in 3" :key="i" class="h-1 rounded-full bg-line-2"></div>
            </div>
        </template>
        <div v-else class="flex justify-center py-1">
            <span class="size-2 rounded-full" :class="healthDot" :title="t('Server')"></span>
        </div>
    </div>
</template>

<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from '@/Composables/useI18n';
import { useServerStats, toneBg } from '@/Composables/useServerStats';

defineProps<{ wide: boolean }>();
const { t } = useI18n();
const { stats, uptime, healthDot } = useServerStats();

const fmtMb = (mb: number) => (mb >= 1024 ? `${(mb / 1024).toFixed(1)}G` : `${mb}M`);

const bars = computed(() => {
    const s = stats.value;
    if (!s || s.has_error) return [];
    return [
        { label: 'CPU', pct: s.cpu_percent, text: `${s.cpu_percent}%` },
        { label: 'RAM', pct: s.mem_percent, text: `${fmtMb(s.mem_used_mb)}/${fmtMb(s.mem_total_mb)}` },
        { label: t('Disk'), pct: s.disk_percent, text: `${s.disk_used_gb}/${s.disk_total_gb}G` },
    ];
});
</script>
