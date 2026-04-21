<template>
    <div
        ref="windowElement"
        v-show="!session.isMinimized"
        :style="windowStyle"
        :class="['terminal-window fixed flex flex-col overflow-hidden rounded-lg border border-gray-700 bg-[#1e1e1e] shadow-2xl', { 'is-active': session.isActive, 'maximized': session.isMaximized }]"
        @mousedown="activateWindow"
    >
        <!-- Title Bar -->
        <div
            class="flex cursor-move items-center justify-between bg-gray-800 px-3 py-1.5"
            @mousedown="startDrag"
            @dblclick="$emit('maximize')"
        >
            <div class="flex items-center gap-2 text-xs text-gray-300">
                <span :class="['inline-block h-2 w-2 rounded-full transition-colors', connected ? 'bg-green-500' : 'bg-yellow-500 animate-pulse']"></span>
                <svg class="h-3.5 w-3.5 text-green-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 9l3 3-3 3m5 0h3M5 20h14a2 2 0 002-2V6a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                </svg>
                {{ session.containerName }}
            </div>
            <div class="flex items-center gap-1">
                <button @click.stop="reconnect" :disabled="reconnecting" class="rounded p-0.5 text-gray-400 hover:bg-gray-700 hover:text-white disabled:opacity-50" v-tooltip="t('Reconnect')">
                    <svg :class="['h-3.5 w-3.5', { 'animate-spin': reconnecting }]" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" /></svg>
                </button>
                <button @click.stop="$emit('minimize')" class="rounded p-0.5 text-gray-400 hover:bg-gray-700 hover:text-white" v-tooltip="t('Minimize')">
                    <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 12H4" /></svg>
                </button>
                <button @click.stop="$emit('maximize')" class="rounded p-0.5 text-gray-400 hover:bg-gray-700 hover:text-white" v-tooltip="t('Maximize')">
                    <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 8V4m0 0h4M4 4l5 5m11-1V4m0 0h-4m4 0l-5 5M4 16v4m0 0h4m-4 0l5-5m11 5l-5-5m5 5v-4m0 4h-4" /></svg>
                </button>
                <button @click.stop="$emit('close')" class="rounded p-0.5 text-gray-400 hover:bg-red-600 hover:text-white" v-tooltip="t('Close')">
                    <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
                </button>
            </div>
        </div>

        <!-- Terminal Body -->
        <div ref="terminalBody" class="flex-1 overflow-hidden"></div>
    </div>
</template>

<script setup lang="ts">
import { ref, onMounted, onBeforeUnmount, watch, nextTick } from 'vue';
import type { TerminalSession } from '@/Composables/useTerminal';
import { useTerminal } from '@/Composables/useTerminal';
import { useI18n } from '@/Composables/useI18n';

type TerminalRuntime = {
    Terminal: any;
    FitAddon: any;
    WebLinksAddon: any;
};

let terminalRuntimePromise: Promise<TerminalRuntime> | null = null;

const loadTerminalRuntime = async (): Promise<TerminalRuntime> => {
    if (!terminalRuntimePromise) {
        terminalRuntimePromise = Promise.all([
            import('@xterm/xterm'),
            import('@xterm/addon-fit'),
            import('@xterm/addon-web-links'),
            import('@xterm/xterm/css/xterm.css'),
        ]).then(([xterm, fit, links]) => ({
            Terminal: xterm.Terminal,
            FitAddon: fit.FitAddon,
            WebLinksAddon: links.WebLinksAddon,
        }));
    }
    return terminalRuntimePromise;
};

const props = defineProps<{ session: TerminalSession }>();
const { t } = useI18n();
const { getPersistentTerminal, setPersistentTerminal, parkTerminal, reconnectSession } = useTerminal();

const emit = defineEmits<{
    activate: [];
    minimize: [];
    maximize: [];
    close: [];
}>();

const windowElement = ref<HTMLElement>();
const terminalBody = ref<HTMLElement>();
const connected = ref(false);
const reconnecting = ref(false);

let terminal: any = null;
let fitAddon: any = null;
let ws: WebSocket | null = null;
let resizeObserver: ResizeObserver | null = null;
let resizeTimer: ReturnType<typeof setTimeout> | null = null;
const encoder = new TextEncoder();

function sendResize(cols: number, rows: number) {
    if (ws && ws.readyState === WebSocket.OPEN && cols > 0 && rows > 0) {
        ws.send(JSON.stringify({ resize: [cols, rows] }));
    }
}

const windowStyle = ref<Record<string, string>>({});

function updateWindowStyle() {
    if (props.session.isMaximized) {
        windowStyle.value = {
            top: '0',
            left: '0',
            width: '100dvw',
            height: '100dvh',
            zIndex: String(props.session.zIndex),
            borderRadius: '0',
        };
    } else {
        windowStyle.value = {
            top: props.session.position.top,
            left: props.session.position.left,
            width: props.session.size.width,
            height: props.session.size.height,
            zIndex: String(props.session.zIndex),
        };
    }
}

watch(() => props.session.zIndex, (zIndex) => {
    windowStyle.value = { ...windowStyle.value, zIndex: String(zIndex) };
});

watch(() => [props.session.isMaximized, props.session.position, props.session.size], () => {
    updateWindowStyle();
    nextTick(() => fitAddon?.fit());
}, { deep: true });

watch(() => props.session.isMinimized, (minimized) => {
    if (!minimized) {
        nextTick(() => {
            fitAddon?.fit();
            terminal?.focus();
        });
    }
});

function activateWindow() {
    emit('activate');
    nextTick(() => terminal?.focus());
}

function setupResizeObserver() {
    resizeObserver?.disconnect();

    resizeObserver = new ResizeObserver(() => {
        const host = windowElement.value;
        if (host && !props.session.isMaximized && !props.session.isMinimized) {
            const w = `${Math.round(host.getBoundingClientRect().width)}px`;
            const h = `${Math.round(host.getBoundingClientRect().height)}px`;
            if (props.session.size.width !== w || props.session.size.height !== h) {
                props.session.size.width = w;
                props.session.size.height = h;
            }
        }
        if (fitAddon && !props.session.isMinimized) {
            fitAddon.fit();
        }
    });

    if (windowElement.value) {
        resizeObserver.observe(windowElement.value);
    }
}

function openWebSocket() {
    const proto = location.protocol === 'https:' ? 'wss:' : 'ws:';
    const url = `${proto}//${location.host}/terminal/ws?token=${props.session.wsToken}`;
    ws = new WebSocket(url);
    ws.binaryType = 'arraybuffer';

    // Update persistent reference
    const pt = getPersistentTerminal(props.session.sessionId);
    if (pt) {
        pt.ws = ws;
    }

    ws.onopen = () => {
        connected.value = true;
        // Send initial terminal dimensions so the PTY knows the correct size
        if (terminal) {
            sendResize(terminal.cols, terminal.rows);
        }
    };

    ws.onclose = () => {
        connected.value = false;
        if (terminal) {
            terminal.write('\r\n\x1b[33m[Connection closed]\x1b[0m\r\n');
        }
    };

    ws.onerror = () => {
        connected.value = false;
    };

    ws.onmessage = (event: MessageEvent) => {
        if (!terminal) return;
        if (event.data instanceof ArrayBuffer) {
            terminal.write(new Uint8Array(event.data));
        } else if (typeof event.data === 'string') {
            terminal.write(event.data);
        }
    };
}

async function initTerminal() {
    const existing = getPersistentTerminal(props.session.sessionId);

    if (existing?.terminal?.element) {
        // Reattach existing terminal — session survives navigation
        terminal = existing.terminal;
        fitAddon = existing.fitAddon;
        ws = existing.ws;

        if (terminalBody.value) {
            terminalBody.value.appendChild(terminal.element);
        }

        connected.value = ws !== null && ws.readyState === WebSocket.OPEN;

        // Re-bind ws handlers to use this component's connected ref
        if (ws) {
            ws.onopen = () => { connected.value = true; };
            ws.onclose = () => {
                connected.value = false;
                terminal?.write('\r\n\x1b[33m[Connection closed]\x1b[0m\r\n');
            };
            ws.onerror = () => { connected.value = false; };
        }

        setupResizeObserver();
        nextTick(() => {
            fitAddon?.fit();
            terminal?.focus();
        });
        return;
    }

    // Create new terminal
    const { Terminal, FitAddon, WebLinksAddon } = await loadTerminalRuntime();

    fitAddon = new FitAddon();

    terminal = new Terminal({
        cursorBlink: true,
        fontSize: 14,
        fontFamily: "'JetBrains Mono', 'Cascadia Code', 'Fira Code', Menlo, monospace",
        theme: {
            background: '#1e1e1e',
            foreground: '#00ff41',
            cursor: '#00ff41',
            selectionBackground: '#0b3d0b',
        },
        scrollback: 5000,
        allowProposedApi: true,
    });

    terminal.loadAddon(fitAddon);
    terminal.loadAddon(new WebLinksAddon());

    if (terminalBody.value) {
        terminal.open(terminalBody.value);
        requestAnimationFrame(() => fitAddon.fit());
    }

    terminal.write(`\x1b[90m${t('Connecting to container...')}\x1b[0m\r\n`);

    // Send input directly over WebSocket as binary
    terminal.onData((data: string) => {
        if (ws && ws.readyState === WebSocket.OPEN) {
            ws.send(encoder.encode(data).buffer);
        }
    });

    // Notify backend when terminal dimensions change (debounced)
    terminal.onResize(({ cols, rows }: { cols: number; rows: number }) => {
        if (resizeTimer) clearTimeout(resizeTimer);
        resizeTimer = setTimeout(() => sendResize(cols, rows), 150);
    });

    // Store persistent reference (ws is null here, openWebSocket updates it)
    setPersistentTerminal(props.session.sessionId, { terminal, fitAddon, ws: null });

    setupResizeObserver();
    openWebSocket();
}

async function reconnect() {
    if (reconnecting.value) return;
    reconnecting.value = true;
    try {
        terminal?.write(`\r\n\x1b[33m[${t('Reconnecting...')}]\x1b[0m\r\n`);
        await reconnectSession(props.session.sessionId);

        if (ws && ws.readyState !== WebSocket.CLOSED) {
            try { ws.close(); } catch { /* noop */ }
        }
        ws = null;
        connected.value = false;
        openWebSocket();
    } catch (e: any) {
        terminal?.write(`\r\n\x1b[31m[${e.message || t('Failed to reconnect terminal')}]\x1b[0m\r\n`);
    } finally {
        reconnecting.value = false;
    }
}

function startDrag(e: MouseEvent) {
    if (props.session.isMaximized) return;
    if ((e.target as HTMLElement).closest('button')) return;

    e.preventDefault();
    emit('activate');

    const el = (e.currentTarget as HTMLElement).parentElement!;
    const rect = el.getBoundingClientRect();
    const offsetX = e.clientX - rect.left;
    const offsetY = e.clientY - rect.top;

    const onMove = (ev: MouseEvent) => {
        props.session.position.left = ev.clientX - offsetX + 'px';
        props.session.position.top = ev.clientY - offsetY + 'px';
        updateWindowStyle();
    };

    const onUp = () => {
        document.removeEventListener('mousemove', onMove);
        document.removeEventListener('mouseup', onUp);
    };

    document.addEventListener('mousemove', onMove);
    document.addEventListener('mouseup', onUp);
}

onMounted(() => {
    updateWindowStyle();
    initTerminal();
});

onBeforeUnmount(() => {
    resizeObserver?.disconnect();
    // Park terminal element — DON'T close ws or dispose terminal
    parkTerminal(props.session.sessionId);
});
</script>

<style scoped>
.terminal-window {
    resize: both;
    min-width: 400px;
    min-height: 250px;
}

.terminal-window.maximized {
    resize: none;
}

.terminal-window.is-active {
    box-shadow: 0 0 0 1px rgba(0, 255, 65, 0.3), 0 20px 60px rgba(0, 0, 0, 0.5);
}
</style>
