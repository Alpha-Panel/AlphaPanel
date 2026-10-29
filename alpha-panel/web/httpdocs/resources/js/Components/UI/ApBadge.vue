<template>
    <span :class="['inline-flex items-center gap-1 rounded-full px-1.75 py-px text-2xs font-semibold whitespace-nowrap', toneClass]">
        <span v-if="dot" class="size-1.5 rounded-full bg-current" :class="{ 'animate-ap-pulse': pulse }"></span>
        <slot />
    </span>
</template>

<script setup lang="ts">
import { computed } from 'vue';

export type Tone = 'ok' | 'warn' | 'err' | 'info' | 'violet' | 'accent' | 'neutral';
const props = withDefaults(defineProps<{ tone?: Tone; dot?: boolean; pulse?: boolean }>(), { tone: 'neutral' });

const map: Record<Tone, string> = {
    ok: 'bg-ok/14 text-ok',
    warn: 'bg-warn/16 text-warn',
    err: 'bg-err/14 text-err',
    info: 'bg-info/14 text-info',
    violet: 'bg-violet/16 text-violet',
    accent: 'bg-accent-soft text-accent',
    neutral: 'border border-line bg-surface-2 text-fg-2',
};
const toneClass = computed(() => map[props.tone]);
</script>
