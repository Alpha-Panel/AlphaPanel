<template>
    <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
        <div class="flex items-center gap-2">
            <Link
                v-if="backHref"
                :href="backHref"
                class="inline-flex h-9 items-center gap-2 rounded-lg border border-gray-300 bg-white px-3 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-white/[0.03]"
            >
                <i class="bx bx-arrow-back text-base"></i>
                {{ backLabel }}
            </Link>
            <button
                v-else-if="showBack"
                type="button"
                @click="goBack"
                class="inline-flex h-9 items-center gap-2 rounded-lg border border-gray-300 bg-white px-3 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-white/[0.03]"
            >
                <i class="bx bx-arrow-back text-base"></i>
                {{ backLabel }}
            </button>

            <h2 class="text-xl font-semibold text-gray-800 dark:text-white/90">
                {{ pageTitle }}
            </h2>
        </div>
        <nav>
            <ol class="flex flex-wrap items-center gap-1.5">
                <li>
                    <Link
                        :href="route('home')"
                        class="inline-flex items-center gap-1.5 text-sm text-gray-500 dark:text-gray-400"
                    >
                        {{ t('Dashboard') }}
                    </Link>
                </li>
                <template v-for="(item, index) in normalizedItems" :key="`${item.label}-${index}`">
                    <li class="text-sm text-gray-400">/</li>
                    <li>
                        <Link
                            v-if="item.href && index !== normalizedItems.length - 1"
                            :href="item.href"
                            class="text-sm text-gray-500 hover:text-brand-500 dark:text-gray-400"
                        >
                            {{ item.label }}
                        </Link>
                        <span v-else class="text-sm text-gray-800 dark:text-white/90">
                            {{ item.label }}
                        </span>
                    </li>
                </template>
            </ol>
        </nav>
    </div>
</template>

<script setup lang="ts">
import { computed } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import { useI18n } from '@/Composables/useI18n';

interface BreadcrumbItem {
    label: string;
    href?: string;
}

const props = withDefaults(defineProps<{
    pageTitle: string;
    items?: BreadcrumbItem[];
    showBack?: boolean;
    backHref?: string | null;
    backLabel?: string;
}>(), {
    items: () => [],
    showBack: false,
    backHref: null,
    backLabel: '',
});

const { t } = useI18n();
const backLabel = computed(() => props.backLabel || t('Back'));

const normalizedItems = computed<BreadcrumbItem[]>(() => {
    if (props.items.length > 0) {
        return props.items;
    }

    if (props.pageTitle === t('Dashboard')) {
        return [];
    }

    return [{ label: props.pageTitle }];
});

const goBack = (): void => {
    if (window.history.length > 1) {
        window.history.back();

        return;
    }

    router.visit(route('home'));
};
</script>
