<template>
    <footer class="flex h-6 shrink-0 items-center gap-3.5 overflow-hidden border-t border-line bg-surface px-3 font-mono text-2xs whitespace-nowrap text-fg-3">
        <span class="inline-flex shrink-0 items-center gap-1.5">
            <span class="size-2 rounded-full" :class="healthDot"></span>{{ host }}
        </span>
        <span v-if="stats && !stats.has_error" class="truncate">
            CPU {{ stats.cpu_percent }}% · RAM {{ stats.mem_percent }}% · {{ t('Disk') }} {{ stats.disk_percent }}% · {{ t('Load') }}
            {{ stats.load_1 }} {{ stats.load_5 }} {{ stats.load_15 }}
        </span>
        <span class="flex-1"></span>
        <span class="shrink-0 uppercase">{{ locale }}</span>
        <span v-if="version" class="shrink-0">v{{ version }}</span>
    </footer>
</template>

<script setup lang="ts">
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { useI18n } from '@/Composables/useI18n';
import { useServerStats } from '@/Composables/useServerStats';
import type { SharedProps } from '@/types/inertia';

const page = usePage<SharedProps>();
const { t } = useI18n();
const { stats, healthDot } = useServerStats();
const host = window.location.host;
const locale = computed(() => page.props.locale ?? 'en');
const version = computed(() => page.props.app?.version ?? null);
</script>
