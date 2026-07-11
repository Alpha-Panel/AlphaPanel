<template>
    <nav class="flex items-center gap-1">
        <button
            type="button"
            class="page-btn"
            :disabled="currentPage <= 1"
            :aria-label="t('Previous')"
            @click="goTo(currentPage - 1)"
        >
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7" />
            </svg>
        </button>

        <template v-for="(item, index) in pageItems" :key="`page-${index}-${item}`">
            <span v-if="item === 'ellipsis'" class="flex h-8 w-8 items-center justify-center text-sm text-gray-400 dark:text-gray-600">…</span>
            <button
                v-else
                type="button"
                :class="[
                    'h-8 min-w-8 rounded-lg px-1 text-sm font-medium',
                    item === currentPage ? 'bg-brand-500 text-white' : 'text-gray-700 hover:bg-gray-100 dark:text-gray-400 dark:hover:bg-gray-800',
                ]"
                @click="goTo(item)"
            >
                {{ item }}
            </button>
        </template>

        <button
            type="button"
            class="page-btn"
            :disabled="currentPage >= totalPages"
            :aria-label="t('Next')"
            @click="goTo(currentPage + 1)"
        >
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7" />
            </svg>
        </button>
    </nav>
</template>

<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from '@/Composables/useI18n';

const props = defineProps<{
    currentPage: number;
    totalPages: number;
}>();

const emit = defineEmits<{
    (e: 'change', page: number): void;
}>();

const { t } = useI18n();

const goTo = (page: number): void => {
    if (page < 1 || page > props.totalPages || page === props.currentPage) {
        return;
    }

    emit('change', page);
};

const pageItems = computed<(number | 'ellipsis')[]>(() => {
    const total = props.totalPages;
    const current = props.currentPage;

    if (total <= 7) {
        return Array.from({ length: total }, (_, index) => index + 1);
    }

    let windowStart = Math.max(2, current - 1);
    let windowEnd = Math.min(total - 1, current + 1);

    if (current <= 4) {
        windowStart = 2;
        windowEnd = 5;
    } else if (current >= total - 3) {
        windowStart = total - 4;
        windowEnd = total - 1;
    }

    const items: (number | 'ellipsis')[] = [1];

    if (windowStart > 2) {
        items.push('ellipsis');
    }

    for (let page = windowStart; page <= windowEnd; page++) {
        items.push(page);
    }

    if (windowEnd < total - 1) {
        items.push('ellipsis');
    }

    items.push(total);

    return items;
});
</script>

<style scoped>
@reference "../../../css/app.css";

.page-btn {
    @apply inline-flex h-8 w-8 items-center justify-center rounded-lg text-gray-700 hover:bg-gray-100 disabled:cursor-not-allowed disabled:opacity-40 disabled:hover:bg-transparent dark:text-gray-400 dark:hover:bg-gray-800;
}
</style>
