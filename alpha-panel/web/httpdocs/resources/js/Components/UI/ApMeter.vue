<template>
    <div class="flex items-center gap-2">
        <div class="h-1 overflow-hidden rounded-full bg-line-2" :class="width">
            <div class="h-full rounded-full transition-[width] duration-700" :class="toneBg(value)" :style="{ width: Math.min(100, value) + '%' }"></div>
        </div>
        <span v-if="label" class="font-mono text-[11px] whitespace-nowrap text-fg-2">{{ label }}</span>
    </div>
</template>

<script setup lang="ts">
import { toneBg } from '@/Composables/useServerStats';

withDefaults(defineProps<{ value: number; label?: string; width?: string }>(), { width: 'w-16' });
</script>
