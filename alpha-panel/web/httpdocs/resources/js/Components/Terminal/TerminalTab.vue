<template>
    <div ref="terminalBody" v-show="isActive" class="h-full w-full overflow-hidden"></div>
</template>

<script setup lang="ts">
import { ref, onMounted, onBeforeUnmount, watch, nextTick } from 'vue';
import type { TerminalTab } from '@/Composables/useTerminal';
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

const props = defineProps<{
    tab: TerminalTab;
    isActive: boolean;
}>();

const emit = defineEmits<{
    connected: [value: boolean];
}>();

const { t } = useI18n();
const { getPersistentTerminal, setPersistentTerminal, parkTerminal } = useTerminal();

const terminalBody = ref<HTMLElement>();

let terminal: any = null;
let fitAddon: any = null;
let ws: WebSocket | null = null;
let resizeObserver: ResizeObserver | null = null;
let resizeTimer: ReturnType<typeof setTimeout> | null = null;
const encoder = new TextEncoder();

const connected = ref(false);

defineExpose({
    connected,
    fit() {
        nextTick(() => { fitAddon?.fit(); terminal?.focus(); });
    },
    writeToTerminal(text: string) {
        terminal?.write?.(text);
    },
});

function sendResize(cols: number, rows: number) {
    if (ws && ws.readyState === WebSocket.OPEN && cols > 0 && rows > 0) {
        ws.send(JSON.stringify({ resize: [cols, rows] }));
    }
}

function openWebSocket() {
    const proto = location.protocol === 'https:' ? 'wss:' : 'ws:';
    const url = `${proto}//${location.host}/terminal/ws?token=${props.tab.wsToken}`;
    ws = new WebSocket(url);
    ws.binaryType = 'arraybuffer';

    const pt = getPersistentTerminal(props.tab.sessionId);
    if (pt) {
        pt.ws = ws;
    }

    ws.onopen = () => {
        connected.value = true;
        emit('connected', true);
        if (terminal) {
            sendResize(terminal.cols, terminal.rows);
        }
    };

    ws.onclose = () => {
        connected.value = false;
        emit('connected', false);
        terminal?.write('\r\n\x1b[33m[Connection closed]\x1b[0m\r\n');
    };

    ws.onerror = () => {
        connected.value = false;
        emit('connected', false);
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

function setupResizeObserver() {
    resizeObserver?.disconnect();
    resizeObserver = new ResizeObserver(() => {
        if (fitAddon && props.isActive) {
            fitAddon.fit();
        }
    });
    if (terminalBody.value) {
        resizeObserver.observe(terminalBody.value);
    }
}

async function initTerminal() {
    const existing = getPersistentTerminal(props.tab.sessionId);

    if (existing?.terminal?.element) {
        terminal = existing.terminal;
        fitAddon = existing.fitAddon;
        ws = existing.ws;

        if (terminalBody.value) {
            terminalBody.value.appendChild(terminal.element);
        }

        connected.value = ws !== null && ws.readyState === WebSocket.OPEN;
        emit('connected', connected.value);

        if (ws) {
            ws.onopen = () => { connected.value = true; emit('connected', true); };
            ws.onclose = () => {
                connected.value = false;
                emit('connected', false);
                terminal?.write('\r\n\x1b[33m[Connection closed]\x1b[0m\r\n');
            };
            ws.onerror = () => { connected.value = false; emit('connected', false); };
        }

        setupResizeObserver();
        if (props.isActive) {
            nextTick(() => { fitAddon?.fit(); terminal?.focus(); });
        }
        return;
    }

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
        if (props.isActive) {
            requestAnimationFrame(() => fitAddon.fit());
        }
    }

    terminal.write(`\x1b[90m${t('Connecting to container...')}\x1b[0m\r\n`);

    terminal.onData((data: string) => {
        if (ws && ws.readyState === WebSocket.OPEN) {
            ws.send(encoder.encode(data).buffer);
        }
    });

    terminal.onResize(({ cols, rows }: { cols: number; rows: number }) => {
        if (resizeTimer) clearTimeout(resizeTimer);
        resizeTimer = setTimeout(() => sendResize(cols, rows), 150);
    });

    setPersistentTerminal(props.tab.sessionId, { terminal, fitAddon, ws: null });

    setupResizeObserver();
    openWebSocket();
}

// When this tab becomes active, fit and focus
watch(() => props.isActive, (active) => {
    if (active) {
        nextTick(() => {
            fitAddon?.fit();
            terminal?.focus();
        });
    }
});

// When wsToken changes (reconnect), re-open WebSocket
watch(() => props.tab.wsToken, () => {
    openWebSocket();
});

onMounted(() => {
    initTerminal();
});

onBeforeUnmount(() => {
    resizeObserver?.disconnect();
    parkTerminal(props.tab.sessionId);
});
</script>
