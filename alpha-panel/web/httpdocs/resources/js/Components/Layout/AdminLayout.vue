<template>
    <div class="min-h-screen bg-gray-50 dark:bg-gray-950 xl:flex" :class="{ 'pt-12': impersonationActive }">
        <ImpersonationBar />
        <AppSidebar />
        <Backdrop />
        <div
            class="min-h-screen flex-1 bg-gray-50 transition-all duration-300 ease-in-out dark:bg-gray-950"
            :class="[
                isExpanded || isHovered
                    ? (isRtl ? 'lg:mr-72.5' : 'lg:ml-72.5')
                    : (isRtl ? 'lg:mr-22.5' : 'lg:ml-22.5'),
            ]"
        >
            <AppHeader />
            <div class="p-4 mx-auto w-full max-w-450 md:p-6">
                <slot></slot>
            </div>
        </div>
        <TerminalManager />
        <ProvisionProgressToast />
        <DockerDeployProgressToast />
        <ColorBlindFilters />
        <div
            v-if="panelVersion"
            class="pointer-events-none fixed bottom-3 right-3 z-40 select-none rtl:left-3 rtl:right-auto"
        >
            <span
                class="rounded border border-gray-200 bg-white/70 px-2 py-0.5 text-xs font-mono text-gray-500 backdrop-blur dark:border-gray-800 dark:bg-gray-900/70 dark:text-gray-400"
            >
                v{{ panelVersion }}
            </span>
        </div>
    </div>
</template>

<script setup lang="ts">
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';
import AppSidebar from './AppSidebar.vue';
import AppHeader from './AppHeader.vue';
import Backdrop from './Backdrop.vue';
import ImpersonationBar from './ImpersonationBar.vue';
import TerminalManager from '@/Components/Terminal/TerminalManager.vue';
import ProvisionProgressToast from '@/Components/UI/ProvisionProgressToast.vue';
import DockerDeployProgressToast from '@/Components/UI/DockerDeployProgressToast.vue';
import ColorBlindFilters from './ColorBlindFilters.vue';
import { useSidebar } from '@/Composables/useSidebar';
import type { SharedProps } from '@/types/inertia';

const { isExpanded, isHovered } = useSidebar();
const page = usePage<SharedProps>();
const isRtl = computed(() => page.props.text_direction === 'rtl');
const impersonationActive = computed(
    () => !!(page.props as Record<string, unknown> & { impersonation?: { active?: boolean } }).impersonation?.active
);
const panelVersion = computed(() => page.props.app?.version ?? null);
</script>
