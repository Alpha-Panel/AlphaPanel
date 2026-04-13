<template>
    <span
        class="inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-xs font-medium"
        :class="active
            ? 'bg-success-50 text-success-700 dark:bg-success-500/10 dark:text-success-400'
            : 'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-400'"
    >
        <span
            class="inline-block h-1.5 w-1.5 rounded-full"
            :class="active ? 'bg-success-500' : 'bg-gray-400 dark:bg-gray-500'"
        />
        {{ active ? t('Active') : t('Inactive') }}
    </span>
</template>

<script setup lang="ts">
import { useI18n } from '@/Composables/useI18n';

withDefaults(defineProps<{
    active: boolean;
}>(), {
    active: true,
});

const { t } = useI18n();
</script>
