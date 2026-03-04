<template>
    <div>
        <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">
            {{ label }}
            <span v-if="required" class="text-error-500">*</span>
        </label>
        <slot></slot>
        <p v-if="error" class="mt-1 text-sm text-error-500">{{ error }}</p>
    </div>
</template>

<script setup lang="ts">
defineProps<{
    label: string;
    error?: string;
    required?: boolean;
}>();
</script>
