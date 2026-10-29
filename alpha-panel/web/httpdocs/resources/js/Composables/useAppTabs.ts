import { ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';

export interface AppTab {
    id: string;
    url: string;
    title: string;
}

export const MAIN_TAB_ID = 'main';

const STORAGE_KEY = 'alphapanel.tabs';
const MSG_OPEN = 'alphapanel:open-tab';
const MSG_STATE = 'alphapanel:tab-state';

const isEmbedded = typeof window !== 'undefined' && window.self !== window.top;

const tabs = ref<AppTab[]>([]);
const activeId = ref<string>(MAIN_TAB_ID);
const frames = new Map<string, HTMLIFrameElement>();

let installed = false;

function newId(): string {
    return `${Date.now().toString(36)}${Math.random().toString(36).slice(2, 8)}`;
}

function normalizeTitle(title: string): string {
    return title.replace(/\s+-\s+AlphaPanel$/, '').trim() || 'AlphaPanel';
}

function sameOriginUrl(url: string): string | null {
    try {
        const parsed = new URL(url, window.location.origin);
        return parsed.origin === window.location.origin ? parsed.href : null;
    } catch {
        return null;
    }
}

function openTab(url: string, activate = true): void {
    const href = sameOriginUrl(url);
    if (!href) return;

    if (isEmbedded) {
        window.parent.postMessage({ type: MSG_OPEN, url: href }, window.location.origin);
        return;
    }

    const tab: AppTab = { id: newId(), url: href, title: new URL(href).pathname };
    tabs.value.push(tab);
    if (activate) activeId.value = tab.id;
}

function activateTab(id: string): void {
    if (id === MAIN_TAB_ID || tabs.value.some((tab) => tab.id === id)) {
        activeId.value = id;
    }
}

function removeTabs(shouldRemove: (tab: AppTab, index: number) => boolean): void {
    const activeIndex = tabs.value.findIndex((tab) => tab.id === activeId.value);
    const remaining = tabs.value.filter((tab, index) => !shouldRemove(tab, index));

    if (activeId.value !== MAIN_TAB_ID && !remaining.some((tab) => tab.id === activeId.value)) {
        // Fall back to the nearest surviving tab on the left, else the main tab.
        const fallback = tabs.value
            .slice(0, activeIndex)
            .reverse()
            .find((tab) => remaining.includes(tab));
        activeId.value = fallback?.id ?? MAIN_TAB_ID;
    }

    tabs.value = remaining;
}

function closeTab(id: string): void {
    removeTabs((tab) => tab.id === id);
}

function closeOthers(id: string): void {
    removeTabs((tab) => tab.id !== id);
    activateTab(id);
}

function closeRight(id: string): void {
    const index = id === MAIN_TAB_ID ? -1 : tabs.value.findIndex((tab) => tab.id === id);
    removeTabs((_, i) => i > index);
}

function closeAll(): void {
    removeTabs(() => true);
}

function reloadTab(id: string): void {
    if (id === MAIN_TAB_ID) {
        router.reload();
        return;
    }
    frames.get(id)?.contentWindow?.location.reload();
}

function duplicateTab(id: string): void {
    const url = id === MAIN_TAB_ID ? window.location.href : tabs.value.find((tab) => tab.id === id)?.url;
    if (url) openTab(url);
}

function registerFrame(id: string, el: HTMLIFrameElement | null): void {
    if (el) frames.set(id, el);
    else frames.delete(id);
}

function reportStateToParent(): void {
    window.parent.postMessage(
        { type: MSG_STATE, url: window.location.href, title: document.title },
        window.location.origin,
    );
}

function handleLinkOpen(event: MouseEvent): void {
    const wantsNewTab =
        (event.type === 'click' && event.button === 0 && (event.ctrlKey || event.metaKey)) ||
        (event.type === 'auxclick' && event.button === 1);
    if (!wantsNewTab) return;

    const anchor = (event.target as Element | null)?.closest?.('a[href]') as HTMLAnchorElement | null;
    if (!anchor || anchor.hasAttribute('download') || anchor.hasAttribute('data-no-tab')) return;
    if (anchor.target === '_blank') return;

    const raw = anchor.getAttribute('href') ?? '';
    if (raw === '' || raw.startsWith('#') || /^(mailto|tel|javascript):/i.test(raw)) return;

    const href = sameOriginUrl(anchor.href);
    if (!href) return;

    event.preventDefault();
    event.stopPropagation();
    openTab(href);
}

function handleMessage(event: MessageEvent): void {
    if (event.origin !== window.location.origin || !event.data || typeof event.data !== 'object') return;

    if (event.data.type === MSG_OPEN && typeof event.data.url === 'string') {
        openTab(event.data.url);
        return;
    }

    if (event.data.type === MSG_STATE) {
        for (const [id, frame] of frames) {
            if (frame.contentWindow !== event.source) continue;
            const tab = tabs.value.find((t) => t.id === id);
            if (!tab) return;
            if (typeof event.data.url === 'string' && sameOriginUrl(event.data.url)) tab.url = event.data.url;
            if (typeof event.data.title === 'string') tab.title = normalizeTitle(event.data.title);
            return;
        }
    }
}

function restore(): void {
    try {
        const saved = JSON.parse(sessionStorage.getItem(STORAGE_KEY) ?? 'null');
        if (!saved || !Array.isArray(saved.tabs)) return;
        tabs.value = saved.tabs.filter(
            (tab: AppTab) => typeof tab?.id === 'string' && typeof tab?.url === 'string' && sameOriginUrl(tab.url),
        );
        activateTab(typeof saved.activeId === 'string' ? saved.activeId : MAIN_TAB_ID);
    } catch {
        // ponytail: corrupt/blocked storage just means no restored tabs.
    }
}

export function installAppTabs(): void {
    if (installed || typeof window === 'undefined') return;
    installed = true;

    document.addEventListener('click', handleLinkOpen, { capture: true });
    document.addEventListener('auxclick', handleLinkOpen, { capture: true });

    if (isEmbedded) {
        // Inertia sets document.title after the page component mounts; defer one tick.
        router.on('navigate', () => setTimeout(reportStateToParent, 0));
        setTimeout(reportStateToParent, 0);
        return;
    }

    window.addEventListener('message', handleMessage);
    restore();
    watch(
        [tabs, activeId],
        () => {
            try {
                sessionStorage.setItem(STORAGE_KEY, JSON.stringify({ tabs: tabs.value, activeId: activeId.value }));
            } catch {
                // Storage unavailable (private mode / quota): tabs simply won't survive reload.
            }
        },
        { deep: true },
    );
}

export function useAppTabs() {
    return {
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
    };
}
