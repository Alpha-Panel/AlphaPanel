<template>
    <button
        type="button"
        :disabled="disabled"
        class="relative inline-flex h-4.5 w-8 shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out focus:outline-none disabled:opacity-50 disabled:cursor-not-allowed"
        :class="modelValue ? 'bg-accent' : 'bg-line'"
        @click="$emit('update:modelValue', !modelValue)"
    >
        <span
            class="pointer-events-none inline-block size-3.5 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out"
            :class="modelValue ? 'translate-x-3.5 rtl:-translate-x-3.5' : 'translate-x-0'"
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
