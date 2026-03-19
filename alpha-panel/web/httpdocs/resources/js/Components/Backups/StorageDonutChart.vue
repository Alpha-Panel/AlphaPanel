<template>
    <div class="flex flex-col items-center">
        <div class="relative h-28 w-28">
            <svg viewBox="0 0 36 36" class="h-full w-full -rotate-90">
                <!-- Background circle -->
                <circle
                    cx="18"
                    cy="18"
                    r="14"
                    fill="none"
                    class="stroke-gray-200 dark:stroke-gray-700"
                    stroke-width="3.5"
                />
                <!-- Used arc -->
                <circle
                    cx="18"
                    cy="18"
                    r="14"
                    fill="none"
                    :class="arcColorClass"
                    stroke-width="3.5"
                    stroke-linecap="round"
                    :stroke-dasharray="circumference"
                    :stroke-dashoffset="animatedOffset"
                    class="transition-all duration-1000 ease-out"
                />
            </svg>
            <!-- Center text -->
            <div class="absolute inset-0 flex flex-col items-center justify-center">
                <span class="text-lg font-bold text-gray-800 dark:text-white">
                    {{ percentText }}
                </span>
                <span class="text-[10px] text-gray-500 dark:text-gray-400">
                    {{ t('used') }}
                </span>
            </div>
        </div>
        <div class="mt-2 text-center text-xs text-gray-600 dark:text-gray-400">
            {{ humanSize(used) }} / {{ total ? humanSize(total) : t('Unlimited') }}
        </div>
    </div>
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { useI18n } from '@/Composables/useI18n';

const { t } = useI18n();

const props = defineProps<{
    used: number;
    total: number | null;
}>();

const circumference = 2 * Math.PI * 14;
const mounted = ref(false);

const percent = computed(() => {
    if (!props.total || props.total === 0) return 0;
    return Math.min(100, (props.used / props.total) * 100);
});

const percentText = computed(() => `${Math.round(percent.value)}%`);

const dashOffset = computed(() => {
    return circumference - (percent.value / 100) * circumference;
});

const animatedOffset = computed(() => {
    return mounted.value ? dashOffset.value : circumference;
});

const arcColorClass = computed(() => {
    if (percent.value >= 90) return 'stroke-red-500';
    if (percent.value >= 70) return 'stroke-amber-500';
    return 'stroke-brand-500';
});

onMounted(() => {
    requestAnimationFrame(() => {
        mounted.value = true;
    });
});

function humanSize(bytes: number): string {
    if (bytes === 0) return '0 B';
    const units = ['B', 'KB', 'MB', 'GB', 'TB'];
    const i = Math.floor(Math.log(bytes) / Math.log(1024));
    return (bytes / Math.pow(1024, i)).toFixed(i > 1 ? 1 : 0) + ' ' + units[i];
}
</script>
