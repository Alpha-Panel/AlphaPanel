<template>
    <div class="flex items-center gap-2">
        <div class="h-2 flex-1 overflow-hidden rounded-full bg-gray-200 dark:bg-gray-700">
            <div
                class="h-full rounded-full transition-all duration-300"
                :class="barColorClass"
                :style="{ width: `${percentage}%` }"
            />
        </div>
        <span class="whitespace-nowrap text-xs text-gray-500 dark:text-gray-400">
            {{ formatMb(used) }} / {{ formatMb(total) }}
        </span>
    </div>
</template>

<script setup lang="ts">
import { computed } from 'vue';

const props = withDefaults(defineProps<{
    used: number;
    total: number;
}>(), {
    used: 0,
    total: 256,
});

const percentage = computed(() => {
    if (props.total <= 0) {
        return 0;
    }

    return Math.min(Math.round((props.used / props.total) * 100), 100);
});

const barColorClass = computed(() => {
    if (percentage.value >= 90) {
        return 'bg-error-500';
    }

    if (percentage.value >= 70) {
        return 'bg-warning-500';
    }

    return 'bg-success-500';
});

const formatMb = (value: number): string => {
    if (value >= 1024) {
        return `${(value / 1024).toFixed(1)} GB`;
    }

    return `${value} MB`;
};
</script>
