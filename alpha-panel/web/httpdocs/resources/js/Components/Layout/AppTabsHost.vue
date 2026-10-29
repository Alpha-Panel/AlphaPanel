<template>
    <!-- Mounted beside the Inertia app (never remounts), so tab iframes keep their state across page visits. -->
    <div
        v-if="!isEmbedded && tabs.length > 0"
        v-show="activeId !== MAIN_TAB_ID && frameRect"
        class="fixed z-30 bg-bg"
        :style="
            frameRect
                ? { top: `${frameRect.top}px`, left: `${frameRect.left}px`, width: `${frameRect.width}px`, height: `${frameRect.height}px` }
                : undefined
        "
    >
        <iframe
            v-for="tab in tabs"
            v-show="tab.id === activeId"
            :key="tab.id"
            :ref="(el) => bindFrame(tab, el as HTMLIFrameElement | null)"
            :title="tab.title"
            class="h-full w-full border-0"
        ></iframe>
    </div>
</template>

<script setup lang="ts">
import { MAIN_TAB_ID, useAppTabs, type AppTab } from '@/Composables/useAppTabs';

const { isEmbedded, tabs, activeId, frameRect, registerFrame } = useAppTabs();

function bindFrame(tab: AppTab, el: HTMLIFrameElement | null): void {
    registerFrame(tab.id, el);
    // Set src only once: later in-frame navigation updates tab.url but must not reload the frame.
    if (el && !el.getAttribute('src')) el.src = tab.url;
}
</script>
