<template>
    <div
        class="flex h-dvh flex-col bg-bg text-fg"
        :class="{ 'pt-12': impersonationActive && !isEmbedded }"
    >
        <template v-if="isEmbedded">
            <main scroll-region class="min-h-0 flex-1 overflow-auto px-4 pt-3.5 pb-6">
                <div :class="contentClass"><slot /></div>
            </main>
            <TerminalManager />
        </template>

        <div v-else class="flex min-h-0 flex-1">
            <ImpersonationBar />
            <AppSidebar />
            <Backdrop />

            <div class="relative flex min-w-0 flex-1 flex-col">
                <AppHeader />
                <AppTabsBar />
                <!-- Content box: secondary tab iframes are pinned exactly over it (between tabs and terminal dock). -->
                <div ref="frameAnchor" class="flex min-h-0 flex-1 flex-col">
                    <main
                        scroll-region
                        v-show="activeId === MAIN_TAB_ID"
                        class="animate-ap-in min-h-0 flex-1 overflow-auto px-4 pt-3.5 pb-6"
                    >
                        <div :class="contentClass"><slot /></div>
                    </main>
                </div>
                <TerminalManager />
                <AppStatusBar />
            </div>
        </div>

        <ProvisionProgressToast />
        <DockerDeployProgressToast />
        <ColorBlindFilters />
    </div>
</template>

<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { usePage } from '@inertiajs/vue3';
import AppSidebar from './AppSidebar.vue';
import AppHeader from './AppHeader.vue';
import AppTabsBar from './AppTabsBar.vue';
import AppStatusBar from './AppStatusBar.vue';
import Backdrop from './Backdrop.vue';
import ImpersonationBar from './ImpersonationBar.vue';
import ColorBlindFilters from './ColorBlindFilters.vue';
import TerminalManager from '@/Components/Terminal/TerminalManager.vue';
import ProvisionProgressToast from '@/Components/UI/ProvisionProgressToast.vue';
import DockerDeployProgressToast from '@/Components/UI/DockerDeployProgressToast.vue';
import { MAIN_TAB_ID, useAppTabs } from '@/Composables/useAppTabs';
import type { SharedProps } from '@/types/inertia';

const props = withDefaults(defineProps<{ wide?: boolean }>(), { wide: false });

const { isEmbedded, activeId, setFrameAnchor, releaseFrameAnchor } = useAppTabs();
const page = usePage<SharedProps>();
const impersonationActive = computed(
    () => !!(page.props as Record<string, unknown> & { impersonation?: { active?: boolean } }).impersonation?.active,
);

// Content column: 12px vertical rhythm between page blocks, capped width unless `wide`.
const contentClass = computed(() => [
    'mx-auto flex w-full flex-col gap-3',
    props.wide ? 'max-w-none' : 'max-w-[1600px]',
]);

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
