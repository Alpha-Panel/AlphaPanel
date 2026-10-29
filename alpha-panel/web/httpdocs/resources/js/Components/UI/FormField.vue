<template>
    <div>
        <label class="ap-label">
            {{ label }}
            <span v-if="required" class="text-err">*</span>
        </label>
        <slot></slot>
        <p v-if="error" class="mt-1 text-xs text-err">{{ error }}</p>
    </div>
</template>

<script setup lang="ts">
defineProps<{
    label: string;
    error?: string;
    required?: boolean;
}>();
</script>
