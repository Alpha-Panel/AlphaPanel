<template>
    <div class="mb-3 flex flex-wrap items-center justify-between gap-2.5">
        <div class="flex min-w-0 items-center gap-2.5">
            <Link v-if="backHref" :href="backHref" class="ap-btn hover:no-underline">
                <i class="bx bx-arrow-back text-sm rtl:rotate-180"></i>
                {{ backLabel }}
            </Link>
            <button v-else-if="showBack" type="button" class="ap-btn" @click="goBack">
                <i class="bx bx-arrow-back text-sm rtl:rotate-180"></i>
                {{ backLabel }}
            </button>

            <h1 class="m-0 truncate text-lg font-semibold tracking-tight text-fg">
                {{ pageTitle }}
            </h1>
        </div>
        <nav>
            <ol class="flex flex-wrap items-center gap-1.5 text-xs">
                <li>
                    <Link :href="route('home')" class="inline-flex items-center gap-1 text-fg-3 hover:text-fg hover:no-underline">
                        <i class="bx bx-home-alt text-[13px]"></i>{{ t('Dashboard') }}
                    </Link>
                </li>
                <template v-for="(item, index) in normalizedItems" :key="`${item.label}-${index}`">
                    <li class="text-fg-3"><i class="bx bx-chevron-right text-[13px] rtl:rotate-180"></i></li>
                    <li>
                        <Link
                            v-if="item.href && index !== normalizedItems.length - 1"
                            :href="item.href"
                            class="text-fg-3 hover:text-accent hover:no-underline"
                        >
                            {{ item.label }}
                        </Link>
                        <span v-else class="font-medium text-fg-2">
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
