<template>
    <Teleport to="body">
        <Transition
            enter-active-class="transition ease-out duration-150"
            enter-from-class="opacity-0"
            enter-to-class="opacity-100"
            leave-active-class="transition ease-in duration-100"
            leave-from-class="opacity-100"
            leave-to-class="opacity-0"
        >
            <div
                v-if="modelValue"
                class="fixed inset-0 z-[9999] flex items-center justify-center bg-black/50 px-4"
                @click.self="cancel"
            >
                <div
                    class="w-full max-w-md rounded-2xl border border-gray-200 bg-white p-6 shadow-2xl dark:border-gray-700 dark:bg-gray-900"
                    role="dialog"
                    aria-modal="true"
                >
                    <div class="flex items-start gap-4">
                        <div
                            class="flex h-12 w-12 shrink-0 items-center justify-center rounded-full"
                            :class="iconBgClass"
                        >
                            <i :class="iconClass" class="text-xl"></i>
                        </div>
                        <div class="flex-1">
                            <h3 class="text-lg font-semibold text-gray-900 dark:text-white">
                                {{ title }}
                            </h3>
                            <p v-if="message" class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                                {{ message }}
                            </p>
                        </div>
                    </div>

                    <div class="mt-6 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                        <button
                            type="button"
                            class="rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-gray-700"
                            @click="cancel"
                        >
                            {{ cancelLabel }}
                        </button>
                        <button
                            type="button"
                            class="rounded-lg px-4 py-2 text-sm font-medium text-white shadow-sm focus:outline-none focus:ring-2 focus:ring-offset-2 dark:focus:ring-offset-gray-900"
                            :class="confirmBtnClass"
                            @click="confirm"
                        >
                            {{ confirmLabel }}
                        </button>
                    </div>
                </div>
            </div>
        </Transition>
    </Teleport>
</template>

<script setup lang="ts">
import { computed } from 'vue';

const props = defineProps<{
    modelValue: boolean;
    title: string;
    message?: string;
    confirmLabel?: string;
    cancelLabel?: string;
    variant?: 'danger' | 'warning' | 'info';
}>();

const emit = defineEmits<{
    'update:modelValue': [value: boolean];
    confirm: [];
    cancel: [];
}>();

const variant = computed(() => props.variant ?? 'danger');

const iconBgClass = computed(() => ({
    danger: 'bg-red-100 dark:bg-red-900/30',
    warning: 'bg-amber-100 dark:bg-amber-900/30',
    info: 'bg-blue-100 dark:bg-blue-900/30',
}[variant.value]));

const iconClass = computed(() => ({
    danger: 'bx bx-trash text-red-600 dark:text-red-400',
    warning: 'bx bx-error-circle text-amber-600 dark:text-amber-400',
    info: 'bx bx-info-circle text-blue-600 dark:text-blue-400',
}[variant.value]));

const confirmBtnClass = computed(() => ({
    danger: 'bg-red-600 hover:bg-red-700 focus:ring-red-500',
    warning: 'bg-amber-600 hover:bg-amber-700 focus:ring-amber-500',
    info: 'bg-blue-600 hover:bg-blue-700 focus:ring-blue-500',
}[variant.value]));

function confirm() {
    emit('confirm');
    emit('update:modelValue', false);
}

function cancel() {
    emit('cancel');
    emit('update:modelValue', false);
}
</script>
