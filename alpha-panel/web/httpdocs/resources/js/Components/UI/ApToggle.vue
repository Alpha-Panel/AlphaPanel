<template>
    <button
        type="button"
        role="switch"
        :aria-checked="modelValue"
        :disabled="disabled"
        :class="[
            'relative inline-flex h-4.5 w-8 shrink-0 items-center rounded-full transition-colors disabled:opacity-50',
            modelValue ? 'bg-accent' : 'bg-line',
        ]"
        @click="emit('update:modelValue', !modelValue)"
    >
        <span
            :class="[
                'absolute top-0.5 size-3.5 rounded-full bg-white shadow-theme-xs transition-[inset-inline-start]',
                modelValue ? 'start-4' : 'start-0.5',
            ]"
        ></span>
    </button>
</template>

<script setup lang="ts">
defineProps<{ modelValue: boolean; disabled?: boolean }>();
const emit = defineEmits<{ 'update:modelValue': [value: boolean] }>();
</script>
