<template>
    <div
        class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]"
    >
        <div class="flex items-center justify-between">
            <div>
                <span class="text-sm text-gray-500 dark:text-gray-400">{{ title }}</span>
                <h4 class="mt-2 text-2xl font-bold text-gray-800 dark:text-white/90">
                    {{ value }}
                </h4>
            </div>
            <div
                class="flex items-center justify-center w-12 h-12 rounded-xl bg-gray-100 dark:bg-gray-800"
            >
                <component :is="iconComponent" class="text-gray-500 dark:text-gray-400" />
            </div>
        </div>
    </div>
</template>

<script setup lang="ts">
import { computed } from 'vue';
import { GridIcon, TableIcon, FolderIcon, UserCircleIcon } from '@/Components/Icons';

const props = defineProps<{
    title: string;
    value: string;
    icon: string;
}>();

const iconMap: Record<string, object> = {
    domains: TableIcon,
    databases: FolderIcon,
    dns: GridIcon,
    users: UserCircleIcon,
};

const iconComponent = computed(() => iconMap[props.icon] ?? GridIcon);
</script>
