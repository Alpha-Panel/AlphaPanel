import { computed, ref, watch } from 'vue';
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
const MSG_VISIT = 'alphapanel:visit';

const isEmbedded = typeof window !== 'undefined' && window.self !== window.top;

const tabs = ref<AppTab[]>([]);
const activeId = ref<string>(MAIN_TAB_ID);
const frames = new Map<string, HTMLIFrameElement>();

/** Viewport box of the layout's content area; secondary tab iframes are pinned over it. */
const frameRect = ref<{ top: number; left: number; width: number; height: number } | null>(null);
let detachAnchor: (() => void) | null = null;
let anchorEl: HTMLElement | null = null;

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

/**
 * `title` is for tabs whose frame ends up on another origin (phpMyAdmin, pgAdmin via SSO) and so
 * can never report its own title; such a tab is reused instead of opened twice.
 */
function openTab(url: string, activate = true, title?: string): void {
    const href = sameOriginUrl(url);
    if (!href) return;

    if (isEmbedded) {
        window.parent.postMessage({ type: MSG_OPEN, url: href, title }, window.location.origin);
        return;
    }

    const existing = title ? tabs.value.find((t) => t.url === href && t.title === title) : undefined;
    if (existing) {
        activateTab(existing.id);
        return;
    }

    const tab: AppTab = { id: newId(), url: href, title: title ?? new URL(href).pathname };
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

/**
 * Track the content-area anchor rendered by AdminLayout. The layout remounts on every page,
 * so the iframe layer (which must never remount) follows this element instead of living in it.
 */
function setFrameAnchor(el: HTMLElement): void {
    detachAnchor?.();
    anchorEl = el;

    const update = () => {
        const rect = el.getBoundingClientRect();
        frameRect.value = { top: rect.top, left: rect.left, width: rect.width, height: rect.height };
    };
    // Width changes (sidebar collapse transition, resize) trigger the observer every frame.
    const observer = new ResizeObserver(update);
    observer.observe(el);
    observer.observe(document.documentElement);
    window.addEventListener('resize', update);
    window.addEventListener('scroll', update, { passive: true });
    document.addEventListener('transitionend', update, true);
    update();

    detachAnchor = () => {
        observer.disconnect();
        window.removeEventListener('resize', update);
        window.removeEventListener('scroll', update);
        document.removeEventListener('transitionend', update, true);
    };
}

/** Release only if `el` is still the current anchor (the next page's layout may already own it). */
function releaseFrameAnchor(el: HTMLElement | null): void {
    if (!el || el !== anchorEl) return;
    detachAnchor?.();
    detachAnchor = null;
    anchorEl = null;
    frameRect.value = null;
}

/**
 * While a secondary tab is active the main page is hidden, so a page visit started from the
 * fixed chrome (sidebar, header search) belongs to that tab, like a browser address bar.
 */
function routeChromeVisitsToActiveTab(event: Event): void {
    if (activeId.value === MAIN_TAB_ID) return;

    const visit = (event as CustomEvent).detail?.visit;
    if (!visit || visit.method !== 'get' || visit.prefetch) return;
    if (visit.only?.length || visit.except?.length) return;

    const url = new URL(String(visit.url), window.location.origin);
    if (url.origin !== window.location.origin) return;
    if (url.href.split('#')[0] === window.location.href.split('#')[0]) return; // reload of the hidden main page

    const target = frames.get(activeId.value)?.contentWindow;
    if (!target) return;

    event.preventDefault();
    target.postMessage({ type: MSG_VISIT, url: url.href }, window.location.origin);
}

function reportStateToParent(): void {
    window.parent.postMessage(
        { type: MSG_STATE, url: window.location.href, title: document.title },
        window.location.origin,
    );
}

/**
 * Ctrl/Cmd/middle click opens a link in an in-app tab. A link marked `data-app-tab="Title"`
 * always does (plain click too) — used for SSO links (phpMyAdmin, pgAdmin) whose frame
 * lands on another origin and so needs its title supplied up front.
 */
function handleLinkOpen(event: MouseEvent): void {
    const anchor = (event.target as Element | null)?.closest?.('a[href]') as HTMLAnchorElement | null;
    if (!anchor) return;

    const tabTitle = anchor.getAttribute('data-app-tab');
    const wantsNewTab =
        (event.type === 'click' && event.button === 0 && (event.ctrlKey || event.metaKey || tabTitle !== null)) ||
        (event.type === 'auxclick' && event.button === 1);
    if (!wantsNewTab) return;

    if (anchor.hasAttribute('download') || anchor.hasAttribute('data-no-tab')) return;
    if (anchor.target === '_blank') return;

    const raw = anchor.getAttribute('href') ?? '';
    if (raw === '' || raw.startsWith('#') || /^(mailto|tel|javascript):/i.test(raw)) return;

    const href = sameOriginUrl(anchor.href);
    if (!href) return;

    event.preventDefault();
    event.stopPropagation();
    openTab(href, true, tabTitle || undefined);
}

function handleMessage(event: MessageEvent): void {
    if (event.origin !== window.location.origin || !event.data || typeof event.data !== 'object') return;

    if (event.data.type === MSG_OPEN && typeof event.data.url === 'string') {
        openTab(event.data.url, true, typeof event.data.title === 'string' ? event.data.title.slice(0, 120) : undefined);
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
        // URL changes on navigate; Inertia's <Head> sets document.title later (not on a fixed tick),
        // so report on every title change too — otherwise the tab keeps the previous page's title.
        router.on('navigate', () => setTimeout(reportStateToParent, 0));
        new MutationObserver(reportStateToParent).observe(document.head, { subtree: true, childList: true, characterData: true });
        setTimeout(reportStateToParent, 0);
        window.addEventListener('message', (event) => {
            if (event.origin !== window.location.origin || event.source !== window.parent) return;
            if (event.data?.type === MSG_VISIT && typeof event.data.url === 'string' && sameOriginUrl(event.data.url)) {
                router.visit(event.data.url);
            }
        });
        return;
    }

    document.addEventListener('inertia:before', routeChromeVisitsToActiveTab);
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

/** URL shown in the active secondary tab (kept current by the frame's state reports); null on the main tab. */
const activeTabUrl = computed(() =>
    activeId.value === MAIN_TAB_ID ? null : (tabs.value.find((t) => t.id === activeId.value)?.url ?? null),
);

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
        frameRect,
        activeTabUrl,
        setFrameAnchor,
        releaseFrameAnchor,
    };
}
