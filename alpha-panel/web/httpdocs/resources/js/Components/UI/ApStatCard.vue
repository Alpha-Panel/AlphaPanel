<template>
    <div class="ap-card flex items-start gap-3 px-3.5 py-3">
        <span
            v-if="!ring"
            :class="['inline-flex size-9 shrink-0 items-center justify-center rounded-lg text-[19px]', toneBox[tone]]"
        >
            <i :class="['bx', icon]"></i>
        </span>
        <span v-else class="relative inline-flex size-9 shrink-0 items-center justify-center">
            <svg viewBox="0 0 36 36" class="size-9 -rotate-90">
                <circle cx="18" cy="18" r="14" fill="none" class="stroke-line" stroke-width="3.5" />
                <circle
                    cx="18"
                    cy="18"
                    r="14"
                    fill="none"
                    :class="toneStroke[tone]"
                    stroke-width="3.5"
                    stroke-linecap="round"
                    :stroke-dasharray="C"
                    :stroke-dashoffset="C * (1 - ring / 100)"
                    style="transition: stroke-dashoffset 1s ease-out"
                />
            </svg>
            <span class="absolute font-mono text-[9px] font-bold">{{ ring }}%</span>
        </span>
        <div class="min-w-0 flex-1">
            <div class="text-[11px] text-fg-3">{{ label }}</div>
            <div class="font-mono text-xl font-semibold tracking-tight">{{ value }}</div>
            <div v-if="sub" class="mt-px truncate text-[11px] text-fg-2">{{ sub }}</div>
            <Link v-if="href && linkLabel" :href="href" class="mt-1 inline-flex items-center gap-0.5 text-[11px] font-medium text-accent hover:underline">
                {{ linkLabel }}<i class="bx bx-right-arrow-alt rtl:rotate-180"></i>
            </Link>
        </div>
    </div>
</template>

<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import type { Tone } from './ApBadge.vue';

withDefaults(
    defineProps<{
        label: string;
        value: string | number;
        sub?: string;
        icon?: string;
        tone?: Exclude<Tone, 'neutral'>;
        ring?: number;
        href?: string;
        linkLabel?: string;
    }>(),
    { tone: 'info', icon: 'bx-info-circle' },
);

const C = 2 * Math.PI * 14;
const toneBox: Record<string, string> = {
    ok: 'bg-ok/14 text-ok',
    warn: 'bg-warn/16 text-warn',
    err: 'bg-err/14 text-err',
    info: 'bg-info/14 text-info',
    violet: 'bg-violet/16 text-violet',
    accent: 'bg-accent-soft text-accent',
};
const toneStroke: Record<string, string> = {
    ok: 'stroke-ok',
    warn: 'stroke-warn',
    err: 'stroke-err',
    info: 'stroke-info',
    violet: 'stroke-violet',
    accent: 'stroke-accent',
};
</script>
