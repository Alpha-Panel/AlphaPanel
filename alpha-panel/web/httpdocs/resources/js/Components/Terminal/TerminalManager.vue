<template>
    <!-- Terminal Windows -->
    <Teleport to="body">
        <TerminalWindow
            v-for="[sessionId, session] in terminal.sessions"
            :key="sessionId"
            :session="session"
            @activate="terminal.activateSession(sessionId)"
            @minimize="terminal.minimizeSession(sessionId)"
            @maximize="terminal.toggleMaximize(sessionId)"
            @close="terminal.stopAndRemove(sessionId)"
        />

        <!-- Minimized Bar -->
        <div
            v-if="terminal.minimizedSessions.value.length > 0"
            class="fixed bottom-2 right-2 z-[1300001] flex max-w-[calc(100vw-1rem)] gap-1 overflow-x-auto rounded-lg bg-gray-900/60 p-2 backdrop-blur-sm"
        >
            <div
                v-for="sessionId in terminal.minimizedSessions.value"
                :key="sessionId"
                class="flex shrink-0 cursor-pointer items-center gap-2 rounded-md bg-gray-800 px-3 py-1.5 text-xs text-green-400 hover:bg-gray-700"
                @click="terminal.restoreSession(sessionId)"
            >
                <span class="flex items-center gap-1.5">
                    <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 9l3 3-3 3m5 0h3M5 20h14a2 2 0 002-2V6a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" /></svg>
                    {{ terminal.sessions.get(sessionId)?.containerName }}
                </span>
                <button @click.stop="terminal.stopAndRemove(sessionId)" class="text-gray-400 hover:text-red-400">&times;</button>
            </div>
        </div>
    </Teleport>
</template>

<script setup lang="ts">
import { onBeforeUnmount, onMounted } from 'vue';
import TerminalWindow from './TerminalWindow.vue';
import { useTerminal } from '@/Composables/useTerminal';

const terminal = useTerminal();
const openTerminalHandler = ((event: CustomEvent) => {
    terminal.openTerminal(event.detail.containerId, event.detail.containerName);
}) as EventListener;

onMounted(() => {
    void Promise.all([
        import('@xterm/xterm'),
        import('@xterm/addon-fit'),
        import('@xterm/addon-web-links'),
        import('@xterm/xterm/css/xterm.css'),
    ]);

    document.addEventListener('open-terminal', openTerminalHandler);
});

onBeforeUnmount(() => {
    document.removeEventListener('open-terminal', openTerminalHandler);
});
</script>
