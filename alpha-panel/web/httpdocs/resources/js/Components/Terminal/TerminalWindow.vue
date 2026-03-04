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
                <span :class="['inline-block h-2 w-2 rounded-full', connected ? 'bg-green-500' : 'bg-yellow-500 animate-pulse']"></span>
                <svg class="h-3.5 w-3.5 text-green-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 9l3 3-3 3m5 0h3M5 20h14a2 2 0 002-2V6a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" /></svg>
                {{ session.containerName }}
            </div>
            <div class="flex items-center gap-1">
                <button @click.stop="$emit('minimize')" class="rounded p-0.5 text-gray-400 hover:bg-gray-700 hover:text-white" :title="t('Minimize')">
                    <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 12H4" /></svg>
                </button>
                <button @click.stop="$emit('maximize')" class="rounded p-0.5 text-gray-400 hover:bg-gray-700 hover:text-white" :title="t('Maximize')">
                    <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 8V4m0 0h4M4 4l5 5m11-1V4m0 0h-4m4 0l-5 5M4 16v4m0 0h4m-4 0l5-5m11 5l-5-5m5 5v-4m0 4h-4" /></svg>
                </button>
                <button @click.stop="$emit('close')" class="rounded p-0.5 text-gray-400 hover:bg-red-600 hover:text-white" :title="t('Close')">
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
import axios from 'axios';
import type { TerminalSession } from '@/Composables/useTerminal';
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
    session: TerminalSession;
}>();
const { t } = useI18n();

const emit = defineEmits<{
    activate: [];
    minimize: [];
    maximize: [];
    close: [];
    input: [data: string];
}>();

const windowElement = ref<HTMLElement>();
const terminalBody = ref<HTMLElement>();
const connected = ref(false);

let terminal: any = null;
let fitAddon: any = null;
let resizeObserver: ResizeObserver | null = null;
let echoChannel: any = null;
let connectRetryTimer: ReturnType<typeof setTimeout> | null = null;

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
    windowStyle.value = {
        ...windowStyle.value,
        zIndex: String(zIndex),
    };
});

watch(() => [props.session.isMaximized, props.session.position, props.session.size], () => {
    updateWindowStyle();
    nextTick(() => fitAddon?.fit());
}, { deep: true });

function activateWindow() {
    emit('activate');
    nextTick(() => terminal?.focus());
}

async function initTerminal() {
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

    terminal.onData((data: string) => {
        emit('input', data);
    });

    // Resize observer
    resizeObserver = new ResizeObserver(() => {
        const windowHost = windowElement.value;
        if (windowHost && !props.session.isMaximized && !props.session.isMinimized) {
            const width = `${Math.round(windowHost.getBoundingClientRect().width)}px`;
            const height = `${Math.round(windowHost.getBoundingClientRect().height)}px`;

            if (props.session.size.width !== width || props.session.size.height !== height) {
                props.session.size.width = width;
                props.session.size.height = height;
            }
        }

        if (fitAddon && !props.session.isMinimized) {
            fitAddon.fit();
        }
    });
    if (windowElement.value) {
        resizeObserver.observe(windowElement.value);
    }

    subscribeChannel();
    await hydrateBuffer();
}

function subscribeChannel() {
    if (typeof window.Echo === 'undefined') {
        connectRetryTimer = setTimeout(() => subscribeChannel(), 200);
        return;
    }

    echoChannel = window.Echo.private(`terminal.${props.session.sessionId}`);

    if (typeof echoChannel.subscribed === 'function') {
        echoChannel.subscribed(() => {
            connected.value = true;
        });
    } else {
        connected.value = true;
    }

    if (typeof echoChannel.error === 'function') {
        echoChannel.error(() => {
            connected.value = false;
        });
    }

    echoChannel.listen('.App\\Events\\TerminalOutput', (e: any) => {
        if (e.output && terminal) {
            try {
                const decoded = atob(e.output);
                terminal.write(decoded);
            } catch {
                // ignore decode errors
            }
        }
    });

    if (connectRetryTimer) {
        clearTimeout(connectRetryTimer);
        connectRetryTimer = null;
    }
}

async function hydrateBuffer() {
    try {
        const response = await axios.post(route('terminal.reconnect'), {
            session_id: props.session.sessionId,
        });

        if (response.data?.buffer && terminal) {
            const decoded = atob(response.data.buffer);
            if (decoded) {
                terminal.write(decoded);
            }
        }

        connected.value = true;
    } catch {
        // ignore; websocket stream may still connect shortly after
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
        props.session.position.left = (ev.clientX - offsetX) + 'px';
        props.session.position.top = (ev.clientY - offsetY) + 'px';
        updateWindowStyle();
    };

    const onUp = () => {
        document.removeEventListener('mousemove', onMove);
        document.removeEventListener('mouseup', onUp);
    };

    document.addEventListener('mousemove', onMove);
    document.addEventListener('mouseup', onUp);
}

// When restored from minimized, refit
watch(() => props.session.isMinimized, (minimized) => {
    if (!minimized) {
        nextTick(() => {
            fitAddon?.fit();
            terminal?.focus();
        });
    }
});

onMounted(() => {
    updateWindowStyle();
    initTerminal();
});

onBeforeUnmount(() => {
    if (connectRetryTimer) {
        clearTimeout(connectRetryTimer);
        connectRetryTimer = null;
    }
    if (echoChannel && window.Echo) {
        window.Echo.leave(`terminal.${props.session.sessionId}`);
    }
    if (resizeObserver) {
        resizeObserver.disconnect();
    }
    if (terminal) {
        terminal.dispose();
    }
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
