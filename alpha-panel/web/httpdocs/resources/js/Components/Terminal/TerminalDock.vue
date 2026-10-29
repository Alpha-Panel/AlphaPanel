<template>
    <section
        v-if="list.length > 0"
        :class="[
            'flex shrink-0 flex-col border-t border-line bg-surface',
            dockMaximized && !dockCollapsed ? 'fixed inset-0 z-1300000' : 'relative z-40',
        ]"
        :style="panelStyle"
        :aria-label="t('Terminal')"
    >
        <!-- Drag handle: resize the panel height (VS Code style). -->
        <div
            v-if="!dockCollapsed && !dockMaximized"
            class="absolute inset-x-0 -top-0.75 z-10 h-1.5 cursor-row-resize hover:bg-accent/40"
            @mousedown.prevent="startResize"
        ></div>

        <header class="flex h-8.5 shrink-0 items-center gap-1 border-b border-line-2 ps-2.5 pe-1.5">
            <span class="ap-eyebrow me-1.5 inline-flex shrink-0 items-center gap-1.5">
                <i class="bx bx-terminal text-[13px]"></i>{{ t('Terminal') }}
            </span>

            <div class="no-scrollbar flex min-w-0 flex-1 items-center gap-0.5 overflow-x-auto" role="tablist">
                <div
                    v-for="win in list"
                    :key="win.windowId"
                    role="tab"
                    tabindex="0"
                    :aria-selected="win.windowId === activeId"
                    :class="[
                        'group flex h-6.5 max-w-60 shrink-0 cursor-pointer items-center gap-1.5 rounded-md ps-2 pe-1 font-mono text-xs transition-colors',
                        win.windowId === activeId ? 'bg-surface-2 text-fg' : 'text-fg-3 hover:bg-surface-2 hover:text-fg',
                    ]"
                    @click="select(win.windowId)"
                    @keydown.enter="select(win.windowId)"
                    @auxclick.middle.prevent="terminal.stopAndRemove(win.windowId)"
                >
                    <i :class="['bx text-[13px]', win.origin.type === 'ssh' ? 'bx-server text-ok' : 'bx-terminal text-info']"></i>
                    <span class="truncate">{{ win.title }}</span>
                    <span v-if="win.tabs.length > 1" class="rounded-sm bg-line px-1 text-2xs text-fg-2">{{ win.tabs.length }}</span>
                    <button
                        type="button"
                        class="inline-flex size-4.5 shrink-0 items-center justify-center rounded text-fg-3 hover:bg-line hover:text-err"
                        :aria-label="t('Close')"
                        @click.stop="terminal.stopAndRemove(win.windowId)"
                    >
                        <i class="bx bx-x text-sm"></i>
                    </button>
                </div>
            </div>

            <button
                v-if="can('panel.terminal.access')"
                type="button"
                class="ap-dock-btn"
                :title="t('Host Terminal')"
                @click="openHost"
            >
                <i class="bx bx-plus"></i>
            </button>
            <button type="button" class="ap-dock-btn" :title="dockMaximized ? t('Restore') : t('Maximize')" @click="toggleMaximize">
                <i :class="['bx', dockMaximized ? 'bx-collapse-vertical' : 'bx-expand-vertical']"></i>
            </button>
            <button type="button" class="ap-dock-btn" :title="dockCollapsed ? t('Restore') : t('Minimize')" @click="toggleCollapse">
                <i :class="['bx', dockCollapsed ? 'bx-chevron-up' : 'bx-chevron-down']"></i>
            </button>
        </header>

        <div v-show="!dockCollapsed" class="relative min-h-0 flex-1">
            <TerminalWindow
                v-for="win in list"
                :key="win.windowId"
                :win="win"
                docked
                :visible="win.windowId === activeId"
                @activate="terminal.activateWindow(win.windowId)"
                @close="terminal.stopAndRemove(win.windowId)"
            />
        </div>
    </section>
</template>

<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import TerminalWindow from './TerminalWindow.vue';
import { useTerminal } from '@/Composables/useTerminal';
import { useTerminalMode } from '@/Composables/useTerminalMode';
import { useCan } from '@/Composables/useCan';
import { useI18n } from '@/Composables/useI18n';

const terminal = useTerminal();
const { dockHeight, dockCollapsed, dockMaximized } = useTerminalMode();
const { can } = useCan();
const { t } = useI18n();

const MIN_HEIGHT = 140;
const HEADER_HEIGHT = 34;

const list = computed(() => Array.from(terminal.windows.values()));
const lastSelected = ref<string | null>(null);

// The window the store marks active wins (a new terminal activates itself); otherwise keep the last pick.
const activeId = computed(
    () =>
        list.value.find((w) => w.isActive)?.windowId ??
        list.value.find((w) => w.windowId === lastSelected.value)?.windowId ??
        list.value[list.value.length - 1]?.windowId ??
        null,
);

// A newly opened terminal must be visible even if the panel was collapsed.
watch(
    () => list.value.length,
    (count, previous) => {
        if (count > (previous ?? 0)) dockCollapsed.value = false;
    },
);

const panelStyle = computed(() => {
    if (dockCollapsed.value) return { height: `${HEADER_HEIGHT}px` };
    if (dockMaximized.value) return undefined; // fixed inset-0: full viewport
    return { height: `${dockHeight.value}px` };
});

function select(windowId: string): void {
    lastSelected.value = windowId;
    terminal.restoreWindow(windowId);
    dockCollapsed.value = false;
}

function openHost(): void {
    dockCollapsed.value = false;
    void terminal.openHostTerminal();
}

function toggleCollapse(): void {
    dockCollapsed.value = !dockCollapsed.value;
    if (dockCollapsed.value) dockMaximized.value = false;
}

// Esc leaves fullscreen, like the floating window's double-click restore.
function onKeydown(event: KeyboardEvent): void {
    if (event.key === 'Escape' && dockMaximized.value && event.target === document.body) dockMaximized.value = false;
}
onMounted(() => document.addEventListener('keydown', onKeydown));
onBeforeUnmount(() => document.removeEventListener('keydown', onKeydown));

function toggleMaximize(): void {
    dockCollapsed.value = false;
    dockMaximized.value = !dockMaximized.value;
}

function startResize(event: MouseEvent): void {
    const startY = event.clientY;
    const startHeight = dockHeight.value;
    // ponytail: cap at 85% of the viewport so the header/tabs always stay reachable.
    const maxHeight = window.innerHeight * 0.85;

    const onMove = (e: MouseEvent) => {
        dockHeight.value = Math.min(maxHeight, Math.max(MIN_HEIGHT, startHeight + (startY - e.clientY)));
    };
    const onUp = () => {
        document.removeEventListener('mousemove', onMove);
        document.removeEventListener('mouseup', onUp);
        document.body.style.cursor = '';
        document.body.style.userSelect = '';
    };

    document.body.style.cursor = 'row-resize';
    document.body.style.userSelect = 'none';
    document.addEventListener('mousemove', onMove);
    document.addEventListener('mouseup', onUp);
}
</script>

<style scoped>
@reference "../../../css/app.css";

.ap-dock-btn {
    @apply inline-flex size-6.5 shrink-0 items-center justify-center rounded-md text-[15px] text-fg-2 hover:bg-surface-2 hover:text-fg;
}
</style>
