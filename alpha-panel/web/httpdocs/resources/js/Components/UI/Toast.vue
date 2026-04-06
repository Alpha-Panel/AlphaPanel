<template>
    <div class="fixed top-4 right-4 z-[9999999] flex max-w-[calc(100vw-2rem)] flex-col gap-2">
        <TransitionGroup name="toast">
            <div
                v-for="toast in toasts"
                :key="toast.id"
                :class="[
                    'flex max-w-[min(92vw,540px)] items-center gap-3 rounded-lg px-4 py-3 text-sm font-medium opacity-100 shadow-theme-lg ring-1 ring-black/5 dark:ring-white/10',
                    typeClasses[toast.type],
                ]"
            >
                <span class="max-w-105 wrap-break-word leading-5">{{ toast.message }}</span>
                <button
                    @click="removeToast(toast.id)"
                    class="ml-2 opacity-80 hover:opacity-100"
                >
                    &times;
                </button>
            </div>
        </TransitionGroup>
    </div>
</template>

<script setup lang="ts">
import { useToast } from '@/Composables/useToast';

const { toasts, removeToast } = useToast();

const typeClasses: Record<string, string> = {
    success: 'border border-success-300 bg-success-100 text-success-900 dark:border-success-800 dark:bg-success-950 dark:text-success-100',
    error: 'border border-error-300 bg-error-100 text-error-900 dark:border-error-800 dark:bg-error-950 dark:text-error-100',
    warning: 'border border-warning-300 bg-warning-100 text-warning-900 dark:border-warning-800 dark:bg-warning-950 dark:text-warning-100',
    info: 'border border-blue-light-300 bg-blue-light-100 text-blue-light-900 dark:border-blue-light-800 dark:bg-blue-light-950 dark:text-blue-light-100',
};
</script>

<style scoped>
.toast-enter-active,
.toast-leave-active {
    transition: all 0.3s ease;
}
.toast-enter-from {
    opacity: 0;
    transform: translateX(30px);
}
.toast-leave-to {
    opacity: 0;
    transform: translateX(30px);
}
</style>
