<template>
    <!-- Docked: VS Code style panel rendered in place (AdminLayout puts it above the status bar). -->
    <TerminalDock v-if="mode === 'docked'" />

    <!-- Floating: draggable windows -->
    <Teleport v-else to="body">
        <TerminalWindow
            v-for="[windowId, win] in terminal.windows"
            :key="windowId"
            :win="win"
            @activate="terminal.activateWindow(windowId)"
            @minimize="terminal.minimizeWindow(windowId)"
            @maximize="terminal.toggleMaximize(windowId)"
            @close="terminal.stopAndRemove(windowId)"
        />

        <!-- Minimized Bar -->
        <div
            v-if="terminal.minimizedWindows.value.length > 0"
            class="fixed end-2 bottom-8 z-1300001 flex max-w-[calc(100vw-1rem)] gap-1 overflow-x-auto rounded-lg border border-line bg-surface p-1 shadow-pop"
        >
            <div
                v-for="windowId in terminal.minimizedWindows.value"
                :key="windowId"
                class="flex shrink-0 cursor-pointer items-center gap-2 rounded-md bg-surface-2 py-1 ps-2.5 pe-1 font-mono text-xs text-fg-2 hover:text-fg"
                @click="terminal.restoreWindow(windowId)"
            >
                <i class="bx bx-terminal text-[13px] text-ok"></i>
                <span class="max-w-48 truncate">{{ terminal.windows.get(windowId)?.title }}</span>
                <span v-if="(terminal.windows.get(windowId)?.tabs.length ?? 0) > 1" class="rounded-sm bg-line px-1 text-2xs text-fg-2">
                    {{ terminal.windows.get(windowId)?.tabs.length }}
                </span>
                <button
                    type="button"
                    class="inline-flex size-4.5 items-center justify-center rounded text-fg-3 hover:bg-line hover:text-err"
                    :aria-label="t('Close')"
                    @click.stop="terminal.stopAndRemove(windowId)"
                >
                    <i class="bx bx-x text-sm"></i>
                </button>
            </div>
        </div>
    </Teleport>
</template>

<script setup lang="ts">
import { onBeforeUnmount, onMounted } from 'vue';
import TerminalWindow from './TerminalWindow.vue';
import TerminalDock from './TerminalDock.vue';
import { MSG_TERMINAL, useTerminal } from '@/Composables/useTerminal';
import { useTerminalMode } from '@/Composables/useTerminalMode';
import { useToast } from '@/Composables/useToast';
import { useI18n } from '@/Composables/useI18n';

const terminal = useTerminal();
const { mode } = useTerminalMode();
const { addToast } = useToast();
const { t } = useI18n();

const isEmbedded = window.self !== window.top;

const openTerminalHandler = ((event: CustomEvent) => {
    terminal.openTerminal(event.detail.containerId, event.detail.containerName);
}) as EventListener;

const openHostTerminalHandler = (() => {
    terminal.openHostTerminal();
}) as EventListener;

/** Open requests forwarded by in-app tab iframes (see forwardToParent in useTerminal). */
function onFrameMessage(event: MessageEvent): void {
    if (event.origin !== window.location.origin || event.data?.type !== MSG_TERMINAL) return;

    const { call, args } = event.data as { call: unknown; args: unknown[] };
    let request: Promise<unknown> | null = null;

    if (call === 'openHostTerminal') {
        request = terminal.openHostTerminal();
    } else if (call === 'openTerminal' && typeof args?.[0] === 'string' && typeof args?.[1] === 'string') {
        request = terminal.openTerminal(args[0], args[1]);
    } else if (call === 'openDomainTerminal' && Number.isInteger(args?.[0]) && typeof args?.[1] === 'string') {
        request = terminal.openDomainTerminal(args[0] as number, args[1]);
    }

    request?.catch((error: Error) => addToast('error', error.message || t('Failed to open terminal')));
}

onMounted(() => {
    void Promise.all([
        import('@xterm/xterm'),
        import('@xterm/addon-fit'),
        import('@xterm/addon-web-links'),
        import('@xterm/xterm/css/xterm.css'),
    ]);

    document.addEventListener('open-terminal', openTerminalHandler);
    document.addEventListener('open-host-terminal', openHostTerminalHandler);
    if (!isEmbedded) window.addEventListener('message', onFrameMessage);
});

onBeforeUnmount(() => {
    document.removeEventListener('open-terminal', openTerminalHandler);
    document.removeEventListener('open-host-terminal', openHostTerminalHandler);
    window.removeEventListener('message', onFrameMessage);
});
</script>
