<template>
    <div>
        <dt class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">
            {{ label }}
        </dt>
        <dd class="mt-1 text-sm text-gray-800 dark:text-white/90">
            {{ value }}
        </dd>
    </div>
</template>

<script setup lang="ts">
defineProps<{
    label: string;
    value: string;
}>();
</script>
