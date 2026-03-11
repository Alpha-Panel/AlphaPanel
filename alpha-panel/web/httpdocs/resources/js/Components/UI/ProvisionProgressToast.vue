<template>
    <div class="fixed bottom-4 left-4 right-4 z-[9999] flex flex-col-reverse gap-2 pointer-events-none sm:left-auto sm:max-w-sm">
        <TransitionGroup name="provision-toast">
            <div
                v-for="job in jobs"
                :key="job.domainId"
                class="pointer-events-auto rounded-lg border p-3 shadow-lg"
                :class="cardClass(job.status)"
            >
                <div class="mb-1.5 flex items-center justify-between">
                    <div class="flex min-w-0 items-center gap-2">
                        <i :class="iconClass(job.status)" class="text-sm"></i>
                        <span class="truncate text-xs font-semibold text-gray-800 dark:text-white/90">
                            {{ job.fqdn }}
                        </span>
                    </div>
                    <button
                        @click="dismissJob(job.domainId)"
                        class="ml-2 flex-shrink-0 text-xs leading-none text-gray-400 hover:text-gray-600 dark:hover:text-gray-200"
                    >&times;</button>
                </div>
                <div class="h-1.5 w-full overflow-hidden rounded-full bg-gray-200 dark:bg-gray-700">
                    <div
                        class="h-full rounded-full transition-all duration-500 ease-out"
                        :class="barClass(job.status)"
                        :style="{ width: `${job.percent}%` }"
                    ></div>
                </div>
                <div class="mt-1.5 flex items-center justify-between">
                    <p class="min-w-0 truncate text-[11px] text-gray-500 dark:text-gray-400">
                        {{ job.message }}
                    </p>
                    <span class="ml-2 flex-shrink-0 text-[11px] font-medium" :class="percentClass(job.status)">
                        {{ job.percent }}%
                    </span>
                </div>
            </div>
        </TransitionGroup>
    </div>
</template>

<script setup lang="ts">
import { onMounted, onUnmounted } from 'vue';
import { useProvisionProgress } from '@/Composables/useProvisionProgress';

const { jobs, subscribe, unsubscribe, dismissJob } = useProvisionProgress();

function cardClass(status: string) {
    if (status === 'success') return 'border-success-300 bg-white dark:border-success-800 dark:bg-gray-900';
    if (status === 'error') return 'border-error-300 bg-white dark:border-error-800 dark:bg-gray-900';
    return 'border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-900';
}

function barClass(status: string) {
    if (status === 'success') return 'bg-success-500';
    if (status === 'error') return 'bg-error-500';
    return 'bg-brand-500';
}

function percentClass(status: string) {
    if (status === 'success') return 'text-success-600 dark:text-success-400';
    if (status === 'error') return 'text-error-600 dark:text-error-400';
    return 'text-brand-600 dark:text-brand-400';
}

function iconClass(status: string) {
    if (status === 'success') return 'bx bx-check-circle text-success-500';
    if (status === 'error') return 'bx bx-error-circle text-error-500';
    return 'bx bx-loader-alt bx-spin text-brand-500';
}

onMounted(() => {
    subscribe();
});

onUnmounted(() => {
    unsubscribe();
});
</script>

<style scoped>
.provision-toast-enter-active,
.provision-toast-leave-active {
    transition: all 0.3s ease;
}
.provision-toast-enter-from {
    opacity: 0;
    transform: translateY(20px);
}
.provision-toast-leave-to {
    opacity: 0;
    transform: translateX(30px);
}
</style>
