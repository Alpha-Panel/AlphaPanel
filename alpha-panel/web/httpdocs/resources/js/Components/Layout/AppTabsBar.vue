<template>
    <nav class="relative z-5 flex h-9.5 shrink-0 items-stretch border-b border-line bg-surface-2 ps-2" :aria-label="t('Tabs')">
        <div
            ref="stripRef"
            class="no-scrollbar flex min-w-0 flex-1 items-stretch overflow-x-auto overflow-y-hidden ps-1.5"
            role="tablist"
            @wheel.prevent="onWheel"
        >
            <button
                type="button"
                role="tab"
                :data-tab-id="MAIN_TAB_ID"
                :aria-selected="activeId === MAIN_TAB_ID"
                :class="tabClass(MAIN_TAB_ID)"
                :title="mainTitle"
                @click="activateTab(MAIN_TAB_ID)"
                @contextmenu.prevent="openMenu($event, MAIN_TAB_ID)"
            >
                <i class="bx bx-home-alt shrink-0 text-sm"></i>
                <span class="truncate">{{ mainTitle }}</span>
            </button>

            <div
                v-for="tab in tabs"
                :key="tab.id"
                role="tab"
                tabindex="0"
                :data-tab-id="tab.id"
                :aria-selected="activeId === tab.id"
                :class="tabClass(tab.id)"
                :title="tab.title"
                @click="activateTab(tab.id)"
                @keydown.enter="activateTab(tab.id)"
                @mousedown.middle.prevent
                @auxclick.middle.prevent="closeTab(tab.id)"
                @contextmenu.prevent="openMenu($event, tab.id)"
            >
                <span class="truncate">{{ tab.title }}</span>
                <button
                    type="button"
                    class="ms-auto inline-flex size-4.5 shrink-0 items-center justify-center rounded text-fg-3 hover:bg-line hover:text-fg"
                    :aria-label="t('Close tab')"
                    @click.stop="closeTab(tab.id)"
                >
                    <i class="bx bx-x text-sm"></i>
                </button>
            </div>

            <button
                type="button"
                class="mx-0.5 my-1.25 inline-flex size-7 shrink-0 items-center justify-center rounded-md text-fg-3 hover:bg-line hover:text-fg"
                :title="t('New tab')"
                @click="openTab(newTabUrl)"
            >
                <i class="bx bx-plus text-[17px]"></i>
            </button>
        </div>

        <div class="flex shrink-0 items-center gap-1 border-s border-line px-2">
            <button
                ref="listButtonRef"
                type="button"
                :title="t('Tabs')"
                aria-haspopup="menu"
                :aria-expanded="!!tabList"
                :class="[
                    'inline-flex h-6.5 items-center gap-1.25 rounded-md px-2 font-mono text-xs hover:bg-line hover:text-fg',
                    tabList ? 'bg-line text-fg' : 'text-fg-2',
                ]"
                @click.stop="toggleTabList"
            >
                <i class="bx bx-list-ul text-[15px]"></i>{{ tabs.length + 1 }}
            </button>
            <button
                v-if="can('panel.terminal.access')"
                type="button"
                :title="t('Terminal')"
                class="inline-flex size-6.5 items-center justify-center rounded-md text-fg-2 hover:bg-line hover:text-fg"
                @click="openTerminal"
            >
                <i class="bx bx-terminal text-[15px]"></i>
            </button>
        </div>

        <!-- Teleported: inside the nav (z-5) they would sit under the tab iframe layer (z-30). -->
        <Teleport to="body">
        <div
            v-if="menu"
            class="animate-ap-in fixed z-[1700000] w-55 rounded-[9px] border border-line bg-surface p-1 shadow-pop"
            :style="{ left: `${menu.x}px`, top: `${menu.y}px` }"
            role="menu"
            @click.stop
            @contextmenu.prevent
        >
            <button type="button" role="menuitem" class="ap-pop-item" @click="run(reloadTab)"><i class="bx bx-refresh text-[15px]"></i>{{ t('Reload tab') }}</button>
            <button type="button" role="menuitem" class="ap-pop-item" @click="run(duplicateTab)"><i class="bx bx-copy text-[15px]"></i>{{ t('Duplicate tab') }}</button>
            <div class="my-1 h-px bg-line-2"></div>
            <button v-if="menu.id !== MAIN_TAB_ID" type="button" role="menuitem" class="ap-pop-item" @click="run(closeTab)"><i class="bx bx-x text-[15px]"></i>{{ t('Close tab') }}</button>
            <button type="button" role="menuitem" class="ap-pop-item" @click="run(closeOthers)"><i class="bx bx-collapse-horizontal text-[15px]"></i>{{ t('Close other tabs') }}</button>
            <button type="button" role="menuitem" class="ap-pop-item" @click="run(closeRight)"><i class="bx bx-right-arrow-alt text-[15px]"></i>{{ t('Close tabs to the right') }}</button>
            <button type="button" role="menuitem" class="ap-pop-item !text-err" @click="run(closeAll)"><i class="bx bx-x-circle text-[15px]"></i>{{ t('Close all tabs') }}</button>
        </div>

        <!-- Tab list (switch / close from one place) -->
        <div
            v-if="tabList"
            class="animate-ap-in fixed z-[1700000] w-75 rounded-[9px] border border-line bg-surface p-1 shadow-pop"
            :style="{ top: `${tabList.y}px`, right: `${tabList.right}px` }"
            role="menu"
            @click.stop
        >
            <div class="max-h-80 overflow-y-auto">
                <div
                    v-for="item in listItems"
                    :key="item.id"
                    role="menuitem"
                    tabindex="0"
                    :class="[
                        'group flex h-8 cursor-pointer items-center gap-2.5 rounded-md px-2 text-sm',
                        activeId === item.id ? 'bg-surface-2 text-fg' : 'text-fg-2 hover:bg-surface-2 hover:text-fg',
                    ]"
                    @click="pickTab(item.id)"
                    @keydown.enter="pickTab(item.id)"
                >
                    <span
                        :class="['size-1.75 shrink-0 rounded-full', activeId === item.id ? 'bg-accent' : 'border border-fg-3']"
                    ></span>
                    <i :class="['bx shrink-0 text-[15px]', item.id === MAIN_TAB_ID ? 'bx-home-alt' : 'bx-globe']"></i>
                    <span class="min-w-0 flex-1 truncate">{{ item.title }}</span>
                    <button
                        v-if="item.id !== MAIN_TAB_ID"
                        type="button"
                        class="inline-flex size-5 shrink-0 items-center justify-center rounded text-fg-3 opacity-0 group-hover:opacity-100 hover:bg-line hover:text-fg"
                        :aria-label="t('Close tab')"
                        @click.stop="closeTab(item.id)"
                    >
                        <i class="bx bx-x text-sm"></i>
                    </button>
                </div>
            </div>
            <template v-if="tabs.length > 0">
                <div class="my-1 h-px bg-line-2"></div>
                <button type="button" role="menuitem" class="ap-pop-item !text-err" @click="closeAllFromList">
                    {{ t('Close all tabs') }}
                </button>
            </template>
        </div>
        </Teleport>
    </nav>
</template>

<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { MAIN_TAB_ID, useAppTabs } from '@/Composables/useAppTabs';
import { useI18n } from '@/Composables/useI18n';
import { useCan } from '@/Composables/useCan';

const { tabs, activeId, openTab, activateTab, closeTab, closeOthers, closeRight, closeAll, reloadTab, duplicateTab, normalizeTitle } =
    useAppTabs();
const { t } = useI18n();
const { can } = useCan();

const newTabUrl = '/';
const stripRef = ref<HTMLElement | null>(null);
const mainTitle = ref(normalizeTitle(document.title));
const menu = ref<{ id: string; x: number; y: number } | null>(null);

function tabClass(id: string): string[] {
    const active = activeId.value === id;
    return [
        'relative mt-1.25 flex h-8.25 max-w-55 min-w-30 shrink-0 cursor-pointer items-center gap-1.5 rounded-t-[7px] ps-2.5 pe-1.5 text-xs select-none outline-none transition-colors',
        active
            ? 'border border-b-0 border-line bg-surface font-medium text-fg after:absolute after:inset-x-0 after:-bottom-px after:h-px after:bg-surface before:absolute before:inset-x-2 before:top-0 before:h-0.5 before:rounded-full before:bg-accent'
            : 'text-fg-2 hover:bg-line-2 hover:text-fg',
    ];
}

const openTerminal = () => document.dispatchEvent(new CustomEvent('open-host-terminal'));

function onWheel(e: WheelEvent): void {
    if (stripRef.value) stripRef.value.scrollLeft += e.deltaY || e.deltaX;
}
function openMenu(e: MouseEvent, id: string): void {
    tabList.value = null;
    menu.value = { id, x: Math.min(e.clientX, window.innerWidth - 228), y: Math.min(e.clientY, window.innerHeight - 238) };
}
function closeMenu(): void {
    menu.value = null;
    tabList.value = null;
}

// Tab list dropdown (≡ counter): every tab incl. the main one, right-aligned under the button.
const listButtonRef = ref<HTMLElement | null>(null);
const tabList = ref<{ y: number; right: number } | null>(null);
const listItems = computed(() => [{ id: MAIN_TAB_ID, title: mainTitle.value }, ...tabs.value]);

function toggleTabList(): void {
    if (tabList.value) return closeMenu();
    menu.value = null;
    const rect = listButtonRef.value?.getBoundingClientRect();
    if (rect) tabList.value = { y: rect.bottom + 6, right: Math.max(8, window.innerWidth - rect.right) };
}
function pickTab(id: string): void {
    activateTab(id);
    closeMenu();
}
function closeAllFromList(): void {
    closeAll();
    closeMenu();
}

function run(action: (id: string) => void): void {
    if (menu.value) action(menu.value.id);
    closeMenu();
}
const onKeydown = (e: KeyboardEvent) => e.key === 'Escape' && closeMenu();

// Keep the active tab visible without scrollIntoView (avoids scrolling ancestor containers).
function revealActive(): void {
    const strip = stripRef.value;
    const el = strip?.querySelector<HTMLElement>(`[data-tab-id="${activeId.value}"]`);
    if (!strip || !el) return;
    const l = el.offsetLeft;
    const r = l + el.offsetWidth;
    if (l < strip.scrollLeft) strip.scrollLeft = l - 8;
    else if (r > strip.scrollLeft + strip.clientWidth) strip.scrollLeft = r - strip.clientWidth + 8;
}
watch(activeId, async () => {
    await nextTick();
    revealActive();
});

let titleObserver: MutationObserver | null = null;
onMounted(() => {
    revealActive();
    titleObserver = new MutationObserver(() => (mainTitle.value = normalizeTitle(document.title)));
    titleObserver.observe(document.head, { subtree: true, childList: true, characterData: true });
    document.addEventListener('click', closeMenu);
    document.addEventListener('keydown', onKeydown);
    window.addEventListener('blur', closeMenu);
    window.addEventListener('resize', closeMenu);
});
onBeforeUnmount(() => {
    titleObserver?.disconnect();
    document.removeEventListener('click', closeMenu);
    document.removeEventListener('keydown', onKeydown);
    window.removeEventListener('blur', closeMenu);
    window.removeEventListener('resize', closeMenu);
});
</script>
