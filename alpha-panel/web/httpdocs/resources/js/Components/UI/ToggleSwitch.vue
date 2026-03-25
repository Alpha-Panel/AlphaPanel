<template>
    <button
        type="button"
        :disabled="disabled"
        class="relative inline-flex h-6 w-11 shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out focus:outline-none disabled:opacity-50 disabled:cursor-not-allowed"
        :class="modelValue ? 'bg-brand-500' : 'bg-gray-200 dark:bg-gray-700'"
        @click="$emit('update:modelValue', !modelValue)"
    >
        <span
            class="pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out"
            :class="modelValue ? 'translate-x-5' : 'translate-x-0'"
        />
    </button>
</template>

<script setup lang="ts">
defineProps<{
    modelValue: boolean;
    disabled?: boolean;
}>();

defineEmits<{
    'update:modelValue': [value: boolean];
}>();
</script>

<style scoped>
@reference "../../../css/app.css";
</style>
