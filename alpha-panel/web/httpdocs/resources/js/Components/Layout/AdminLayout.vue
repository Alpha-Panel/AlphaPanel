<template>
    <div class="min-h-screen bg-gray-50 dark:bg-gray-950 xl:flex" :class="{ 'pt-12': impersonationActive && !isEmbedded }">
        <!-- Inside an in-app tab iframe only the page content renders; the parent window owns the chrome. -->
        <template v-if="!isEmbedded">
            <ImpersonationBar />
            <AppSidebar />
            <Backdrop />
        </template>
        <div
            class="min-h-screen min-w-0 flex-1 bg-gray-50 transition-all duration-300 ease-in-out dark:bg-gray-950"
            :class="[
                isEmbedded
                    ? ''
                    : isExpanded || isHovered
                        ? (isRtl ? 'lg:mr-72.5' : 'lg:ml-72.5')
                        : (isRtl ? 'lg:mr-22.5' : 'lg:ml-22.5'),
            ]"
        >
            <div v-if="!isEmbedded" class="sticky top-0 z-99999">
                <AppHeader />
                <AppTabsBar />
            </div>
            <!-- Zero-height marker: secondary tab iframes are pinned from here to the viewport bottom. -->
            <div v-if="!isEmbedded" ref="frameAnchor"></div>
            <div v-show="isEmbedded || activeId === MAIN_TAB_ID" class="p-4 mx-auto w-full max-w-450 md:p-6">
                <slot></slot>
            </div>
        </div>
        <TerminalManager />
        <ProvisionProgressToast />
        <DockerDeployProgressToast />
        <ColorBlindFilters />
        <div
            v-if="panelVersion && !isEmbedded"
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
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { usePage } from '@inertiajs/vue3';
import AppSidebar from './AppSidebar.vue';
import AppHeader from './AppHeader.vue';
import AppTabsBar from './AppTabsBar.vue';
import Backdrop from './Backdrop.vue';
import ImpersonationBar from './ImpersonationBar.vue';
import TerminalManager from '@/Components/Terminal/TerminalManager.vue';
import ProvisionProgressToast from '@/Components/UI/ProvisionProgressToast.vue';
import DockerDeployProgressToast from '@/Components/UI/DockerDeployProgressToast.vue';
import ColorBlindFilters from './ColorBlindFilters.vue';
import { useSidebar } from '@/Composables/useSidebar';
import { MAIN_TAB_ID, useAppTabs } from '@/Composables/useAppTabs';
import type { SharedProps } from '@/types/inertia';

const { isExpanded, isHovered } = useSidebar();
const { isEmbedded, activeId, setFrameAnchor, releaseFrameAnchor } = useAppTabs();
const page = usePage<SharedProps>();
const isRtl = computed(() => page.props.text_direction === 'rtl');
const impersonationActive = computed(
    () => !!(page.props as Record<string, unknown> & { impersonation?: { active?: boolean } }).impersonation?.active
);
const panelVersion = computed(() => page.props.app?.version ?? null);

const frameAnchor = ref<HTMLElement | null>(null);
let anchored: HTMLElement | null = null;

onMounted(() => {
    if (frameAnchor.value) {
        anchored = frameAnchor.value;
        setFrameAnchor(anchored);
    }
});

onBeforeUnmount(() => releaseFrameAnchor(anchored));
</script>
