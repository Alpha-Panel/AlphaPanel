<template>
    <div
        ref="windowElement"
        v-show="!win.isMinimized"
        :style="windowStyle"
        :class="['terminal-window fixed flex flex-col overflow-hidden rounded-lg border border-gray-700 bg-[#1e1e1e] shadow-2xl', { 'is-active': win.isActive, 'maximized': win.isMaximized }]"
        @mousedown="activateWindow"
        @keydown="onKeydown"
        tabindex="-1"
    >
        <!-- Title Bar -->
        <div
            class="flex cursor-move items-center justify-between bg-gray-800 px-3 py-1.5"
            @mousedown="startDrag"
            @dblclick="$emit('maximize')"
        >
            <div class="flex items-center gap-2 text-xs text-gray-300">
                <span :class="['inline-block h-2 w-2 rounded-full transition-colors', activeTabConnected ? 'bg-green-500' : 'bg-yellow-500 animate-pulse']"></span>
                <svg class="h-3.5 w-3.5 text-green-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 9l3 3-3 3m5 0h3M5 20h14a2 2 0 002-2V6a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                </svg>
                {{ win.title }}
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

        <!-- Tab Bar -->
        <div class="flex items-center gap-0.5 overflow-x-auto bg-gray-900 px-2 py-0.5 scrollbar-none">
            <div
                v-for="tab in win.tabs"
                :key="tab.sessionId"
                :class="[
                    'group relative flex shrink-0 items-center gap-1 rounded-t px-2.5 py-1 text-xs transition-colors',
                    tab.sessionId === win.activeTabId
                        ? 'bg-[#1e1e1e] text-green-400'
                        : 'text-gray-400 hover:bg-gray-800 hover:text-gray-200',
                ]"
                @click.stop="terminal.activateTab(win.windowId, tab.sessionId)"
                @dblclick.stop="startRename(tab.sessionId, tab.label)"
                @contextmenu.prevent.stop="openContextMenu($event, tab.sessionId)"
            >
                <!-- Inline rename input -->
                <input
                    v-if="editingTabId === tab.sessionId"
                    :ref="(el) => { if (el) (el as HTMLInputElement).focus(); }"
                    v-model="editingLabel"
                    class="w-28 rounded bg-gray-700 px-1 py-0 text-xs text-white outline-none ring-1 ring-green-500"
                    @keydown.enter.prevent="commitRename"
                    @keydown.escape.prevent="cancelRename"
                    @blur="commitRename"
                    @click.stop
                    @mousedown.stop
                />
                <span v-else class="max-w-28 truncate">{{ tab.label }}</span>

                <span
                    v-if="win.tabs.length > 1 && editingTabId !== tab.sessionId"
                    class="ml-0.5 rounded p-0.5 opacity-0 hover:bg-gray-700 hover:text-red-400 group-hover:opacity-100"
                    @click.stop="terminal.closeTab(win.windowId, tab.sessionId)"
                    :title="t('Close tab')"
                >&times;</span>
            </div>

            <!-- New tab button -->
            <button
                v-if="win.tabs.length < 10"
                class="ml-0.5 shrink-0 rounded px-1.5 py-1 text-xs text-gray-500 hover:bg-gray-800 hover:text-gray-200"
                @click.stop="openNewTab"
                :title="t('New tab')"
            >+</button>
        </div>

        <!-- Context menu -->
        <Teleport to="body">
            <div
                v-if="contextMenu.visible"
                :style="{ top: contextMenu.y + 'px', left: contextMenu.x + 'px' }"
                class="fixed z-[9999999] min-w-36 overflow-hidden rounded-md border border-gray-700 bg-gray-900 py-1 shadow-xl text-xs"
                @mousedown.stop
                @mouseleave="closeContextMenu"
            >
                <button
                    class="flex w-full items-center gap-2 px-3 py-1.5 text-gray-200 hover:bg-gray-700"
                    @click="contextMenuRename"
                >
                    <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536M9 11l6.536-6.536a2 2 0 112.828 2.828L11.828 13.828A2 2 0 0110 14H8v-2a2 2 0 01.586-1.414z"/></svg>
                    {{ t('Rename tab') }}
                </button>
                <button
                    class="flex w-full items-center gap-2 px-3 py-1.5 text-gray-200 hover:bg-gray-700"
                    @click="openNewTab(); closeContextMenu()"
                >
                    <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                    {{ t('New tab') }}
                </button>
                <template v-if="win.tabs.length > 1">
                    <div class="my-1 border-t border-gray-700"></div>
                    <button
                        class="flex w-full items-center gap-2 px-3 py-1.5 text-red-400 hover:bg-gray-700"
                        @click="contextMenuClose"
                    >
                        <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                        {{ t('Close tab') }}
                    </button>
                </template>
            </div>
        </Teleport>

        <!-- Tab bodies — all mounted (v-show, not v-if) to preserve state -->
        <div class="relative flex-1 overflow-hidden">
            <TerminalTab
                v-for="tab in win.tabs"
                :key="tab.sessionId"
                :ref="(el) => setTabRef(tab.sessionId, el)"
                :tab="tab"
                :is-active="tab.sessionId === win.activeTabId"
                class="absolute inset-0"
            />
        </div>
    </div>
</template>

<script setup lang="ts">
import { ref, computed, nextTick, watch, onMounted, onUnmounted } from 'vue';
import type { TerminalWindowState } from '@/Composables/useTerminal';
import { useTerminal } from '@/Composables/useTerminal';
import { useI18n } from '@/Composables/useI18n';
import TerminalTab from './TerminalTab.vue';

const props = defineProps<{ win: TerminalWindowState }>();
const { t } = useI18n();
const terminal = useTerminal();

const emit = defineEmits<{
    activate: [];
    minimize: [];
    maximize: [];
    close: [];
}>();

const windowElement = ref<HTMLElement>();
const reconnecting = ref(false);

// Inline rename state
const editingTabId = ref<string | null>(null);
const editingLabel = ref('');

function startRename(sessionId: string, currentLabel: string) {
    editingTabId.value = sessionId;
    editingLabel.value = currentLabel;
}

function commitRename() {
    if (editingTabId.value) {
        terminal.renameTab(props.win.windowId, editingTabId.value, editingLabel.value);
        editingTabId.value = null;
        editingLabel.value = '';
    }
}

function cancelRename() {
    editingTabId.value = null;
    editingLabel.value = '';
}

// Context menu state
const contextMenu = ref({ visible: false, x: 0, y: 0, sessionId: '' });

function openContextMenu(e: MouseEvent, sessionId: string) {
    terminal.activateTab(props.win.windowId, sessionId);
    contextMenu.value = { visible: true, x: e.clientX, y: e.clientY, sessionId };
    // Close on next outside click
    const close = () => { closeContextMenu(); document.removeEventListener('mousedown', close); };
    setTimeout(() => document.addEventListener('mousedown', close), 0);
}

function closeContextMenu() {
    contextMenu.value.visible = false;
}

function contextMenuRename() {
    const tab = props.win.tabs.find((t) => t.sessionId === contextMenu.value.sessionId);
    if (tab) startRename(tab.sessionId, tab.label);
    closeContextMenu();
}

function contextMenuClose() {
    terminal.closeTab(props.win.windowId, contextMenu.value.sessionId);
    closeContextMenu();
}

// Keep refs to TerminalTab components so we can read connected state
const tabRefs = new Map<string, any>();
function setTabRef(sessionId: string, el: any) {
    if (el) {
        tabRefs.set(sessionId, el);
    } else {
        tabRefs.delete(sessionId);
    }
}

const activeTabConnected = computed(() => {
    const ref = tabRefs.get(props.win.activeTabId);
    return ref?.connected?.value ?? false;
});

const windowStyle = ref<Record<string, string>>({});

function updateWindowStyle() {
    if (props.win.isMaximized) {
        windowStyle.value = {
            top: '0',
            left: '0',
            width: '100dvw',
            height: '100dvh',
            zIndex: String(props.win.zIndex),
            borderRadius: '0',
        };
    } else {
        windowStyle.value = {
            top: props.win.position.top,
            left: props.win.position.left,
            width: props.win.size.width,
            height: props.win.size.height,
            zIndex: String(props.win.zIndex),
        };
    }
}

watch(() => props.win.zIndex, (zIndex) => {
    windowStyle.value = { ...windowStyle.value, zIndex: String(zIndex) };
});

watch(() => [props.win.isMaximized, props.win.position, props.win.size], () => {
    updateWindowStyle();
}, { deep: true });

watch(() => props.win.isMinimized, (minimized) => {
    if (!minimized) {
        nextTick(() => updateWindowStyle());
    }
});

updateWindowStyle();

let sizeObserver: ResizeObserver | null = null;

onMounted(() => {
    if (!windowElement.value) return;
    sizeObserver = new ResizeObserver(() => {
        if (props.win.isMaximized || props.win.isMinimized) return;
        const el = windowElement.value;
        if (!el) return;
        const w = el.offsetWidth + 'px';
        const h = el.offsetHeight + 'px';
        if (w === '0px' || h === '0px') return;
        if (props.win.size.width !== w || props.win.size.height !== h) {
            props.win.size.width = w;
            props.win.size.height = h;
        }
    });
    sizeObserver.observe(windowElement.value);
});

onUnmounted(() => {
    sizeObserver?.disconnect();
    sizeObserver = null;
});

function activateWindow() {
    emit('activate');
}

function onKeydown(e: KeyboardEvent) {
    // Ctrl+Shift+T — new tab
    if (e.ctrlKey && e.shiftKey && e.key === 'T') {
        e.preventDefault();
        openNewTab();
        return;
    }
    // Ctrl+Tab / Ctrl+Shift+Tab — cycle tabs
    if (e.ctrlKey && e.key === 'Tab') {
        e.preventDefault();
        cycleTab(e.shiftKey ? -1 : 1);
    }
}

function cycleTab(direction: 1 | -1) {
    const tabs = props.win.tabs;
    if (tabs.length < 2) return;
    const current = tabs.findIndex((t) => t.sessionId === props.win.activeTabId);
    const next = (current + direction + tabs.length) % tabs.length;
    terminal.activateTab(props.win.windowId, tabs[next].sessionId);
}

async function openNewTab() {
    try {
        await terminal.addTab(props.win.windowId);
    } catch { /* noop */ }
}

async function reconnect() {
    if (reconnecting.value) return;
    reconnecting.value = true;
    const activeSessionId = props.win.activeTabId;
    const activeTabRef = tabRefs.get(activeSessionId);
    try {
        activeTabRef?.terminal?.write?.(`\r\n\x1b[33m[${t('Reconnecting...')}]\x1b[0m\r\n`);
        await terminal.reconnectSession(activeSessionId);
        // wsToken watcher in TerminalTab opens new WebSocket automatically
    } catch (e: any) {
        activeTabRef?.terminal?.write?.(`\r\n\x1b[31m[${e.message || t('Failed to reconnect terminal')}]\x1b[0m\r\n`);
    } finally {
        reconnecting.value = false;
    }
}

function startDrag(e: MouseEvent) {
    if (props.win.isMaximized) return;
    if ((e.target as HTMLElement).closest('button')) return;

    e.preventDefault();
    emit('activate');

    const el = (e.currentTarget as HTMLElement).parentElement!;
    const rect = el.getBoundingClientRect();
    const offsetX = e.clientX - rect.left;
    const offsetY = e.clientY - rect.top;

    const onMove = (ev: MouseEvent) => {
        props.win.position.left = ev.clientX - offsetX + 'px';
        props.win.position.top = ev.clientY - offsetY + 'px';
        updateWindowStyle();
    };

    const onUp = () => {
        document.removeEventListener('mousemove', onMove);
        document.removeEventListener('mouseup', onUp);
    };

    document.addEventListener('mousemove', onMove);
    document.addEventListener('mouseup', onUp);
}
</script>

<style scoped>
.terminal-window {
    resize: both;
    min-width: 400px;
    min-height: 300px;
}

.terminal-window.maximized {
    resize: none;
}

.terminal-window.is-active {
    box-shadow: 0 0 0 1px rgba(0, 255, 65, 0.3), 0 20px 60px rgba(0, 0, 0, 0.5);
}

.scrollbar-none {
    scrollbar-width: none;
}
.scrollbar-none::-webkit-scrollbar {
    display: none;
}
</style>
