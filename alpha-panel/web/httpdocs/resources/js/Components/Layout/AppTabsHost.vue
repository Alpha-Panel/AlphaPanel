<template>
    <div v-if="showStrip">
        <nav
            class="fixed inset-x-0 top-0 z-[1600000] flex h-(--app-tabs-h) border-b border-gray-200 bg-gray-100 dark:border-gray-800 dark:bg-gray-950"
            :aria-label="t('Tabs')"
        >
            <div
                ref="stripRef"
                class="flex min-w-0 flex-1 items-end gap-px overflow-x-auto overflow-y-hidden px-1 [scrollbar-width:none] [&::-webkit-scrollbar]:hidden"
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
                    <i class="bx bx-home-alt text-base"></i>
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
                        class="ms-auto flex h-5 w-5 shrink-0 items-center justify-center rounded text-gray-400 hover:bg-gray-200 hover:text-gray-700 dark:hover:bg-gray-700 dark:hover:text-gray-200"
                        :aria-label="t('Close tab')"
                        @click.stop="closeTab(tab.id)"
                    >
                        <i class="bx bx-x text-base"></i>
                    </button>
                </div>

                <button
                    type="button"
                    class="mb-1 ms-1 flex h-7 w-7 shrink-0 items-center justify-center self-center rounded-lg text-gray-500 hover:bg-white hover:text-gray-800 dark:text-gray-400 dark:hover:bg-gray-900 dark:hover:text-gray-200"
                    :title="t('New tab')"
                    :aria-label="t('New tab')"
                    @click="openTab(newTabUrl)"
                >
                    <i class="bx bx-plus text-lg"></i>
                </button>
            </div>
        </nav>

        <div
            v-show="activeId !== MAIN_TAB_ID"
            class="fixed inset-x-0 bottom-0 top-(--app-tabs-h) z-[1550000] bg-gray-50 dark:bg-gray-950"
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

        <div
            v-if="menu"
            class="fixed z-[1700000] min-w-52 rounded-xl border border-gray-200 bg-white p-1 text-sm shadow-theme-lg dark:border-gray-800 dark:bg-gray-900"
            :style="{ left: `${menu.x}px`, top: `${menu.y}px` }"
            role="menu"
            @click.stop
            @contextmenu.prevent
        >
            <button type="button" role="menuitem" :class="menuItemClass" @click="run(reloadTab)">
                <i class="bx bx-refresh text-base"></i>{{ t('Reload tab') }}
            </button>
            <button type="button" role="menuitem" :class="menuItemClass" @click="run(duplicateTab)">
                <i class="bx bx-copy text-base"></i>{{ t('Duplicate tab') }}
            </button>
            <div class="my-1 border-t border-gray-200 dark:border-gray-800"></div>
            <button v-if="menu.id !== MAIN_TAB_ID" type="button" role="menuitem" :class="menuItemClass" @click="run(closeTab)">
                <i class="bx bx-x text-base"></i>{{ t('Close tab') }}
            </button>
            <button type="button" role="menuitem" :class="menuItemClass" @click="run(closeOthers)">
                {{ t('Close other tabs') }}
            </button>
            <button type="button" role="menuitem" :class="menuItemClass" @click="run(closeRight)">
                {{ t('Close tabs to the right') }}
            </button>
            <button type="button" role="menuitem" :class="menuItemClass" @click="run(closeAll)">
                {{ t('Close all tabs') }}
            </button>
        </div>
    </div>
</template>

<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch, watchEffect } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { MAIN_TAB_ID, useAppTabs, type AppTab } from '@/Composables/useAppTabs';
import { useI18n } from '@/Composables/useI18n';
import type { SharedProps } from '@/types/inertia';

const {
    isEmbedded,
    tabs,
    activeId,
    openTab,
    activateTab,
    closeTab,
    closeOthers,
    closeRight,
    closeAll,
    reloadTab,
    duplicateTab,
    registerFrame,
    normalizeTitle,
} = useAppTabs();
const { t } = useI18n();
const page = usePage<SharedProps>();

// Strip is shown for any signed-in user; login/2FA screens stay clean (stored tabs return after sign-in).
const showStrip = computed(() => !isEmbedded && !!page.props.auth?.user);
const newTabUrl = '/';

const stripRef = ref<HTMLElement | null>(null);
const mainTitle = ref(normalizeTitle(document.title));
const menu = ref<{ id: string; x: number; y: number } | null>(null);

const menuItemClass =
    'flex w-full items-center gap-2 rounded-lg px-3 py-1.5 text-start text-gray-700 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-white/5';

function tabClass(id: string): string[] {
    const active = activeId.value === id;
    return [
        'group flex h-[calc(100%-4px)] max-w-56 min-w-28 shrink-0 cursor-pointer select-none items-center gap-1.5 rounded-t-lg border-b-2 px-3 text-xs font-medium outline-none transition-colors',
        active
            ? 'border-brand-500 bg-white text-gray-900 dark:bg-gray-900 dark:text-white'
            : 'border-transparent text-gray-500 hover:bg-white/60 hover:text-gray-800 dark:text-gray-400 dark:hover:bg-gray-900/60 dark:hover:text-gray-200',
    ];
}

function bindFrame(tab: AppTab, el: HTMLIFrameElement | null): void {
    registerFrame(tab.id, el);
    // Set src only once: later in-frame navigation updates tab.url but must not reload the frame.
    if (el && !el.getAttribute('src')) el.src = tab.url;
}

function onWheel(event: WheelEvent): void {
    if (stripRef.value) stripRef.value.scrollLeft += event.deltaY || event.deltaX;
}

function openMenu(event: MouseEvent, id: string): void {
    const width = 220;
    const height = 230;
    menu.value = {
        id,
        x: Math.min(event.clientX, window.innerWidth - width - 8),
        y: Math.min(event.clientY, window.innerHeight - height - 8),
    };
}

function closeMenu(): void {
    menu.value = null;
}

function run(action: (id: string) => void): void {
    if (menu.value) action(menu.value.id);
    closeMenu();
}

function onKeydown(event: KeyboardEvent): void {
    if (event.key === 'Escape') closeMenu();
}

watchEffect(() => {
    document.documentElement.classList.toggle('has-app-tabs', showStrip.value);
});

watch(activeId, async (id) => {
    await nextTick();
    stripRef.value?.querySelector(`[data-tab-id="${id}"]`)?.scrollIntoView({ block: 'nearest', inline: 'nearest' });
});

let titleObserver: MutationObserver | null = null;

onMounted(() => {
    titleObserver = new MutationObserver(() => {
        mainTitle.value = normalizeTitle(document.title);
    });
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
