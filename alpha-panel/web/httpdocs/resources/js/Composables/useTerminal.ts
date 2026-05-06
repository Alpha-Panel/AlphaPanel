import { ref, reactive } from 'vue';
import axios from 'axios';
import { useI18n } from '@/Composables/useI18n';

export type TerminalOrigin =
    | { type: 'container'; containerId: string; containerName: string }
    | { type: 'domain'; domainId: number }
    | { type: 'ssh' };

export interface TerminalTab {
    sessionId: string;
    wsToken: string;
    label: string;
    origin: TerminalOrigin;
}

export interface TerminalWindowState {
    windowId: string;
    targetKey: string;
    title: string;
    origin: TerminalOrigin;
    tabs: TerminalTab[];
    activeTabId: string;
    isMinimized: boolean;
    isMaximized: boolean;
    preMaximizeState: { top: string; left: string; width: string; height: string } | null;
    position: { top: string; left: string };
    size: { width: string; height: string };
    zIndex: number;
    isActive: boolean;
}

export interface PersistentTerminal {
    terminal: any;
    fitAddon: any;
    ws: WebSocket | null;
}

const MAX_TABS_PER_WINDOW = 10;

const windows = reactive(new Map<string, TerminalWindowState>());
const minimizedWindows = ref<string[]>([]);
const pendingOpenRequests = reactive(new Set<string>());
let pendingHostRequest = false;
let nextZIndex = 200000;

// Persistent terminal instances survive Inertia navigations — keyed by sessionId
const persistentTerminals = new Map<string, PersistentTerminal>();

let hiddenPark: HTMLElement | null = null;

function getHiddenPark(): HTMLElement {
    if (!hiddenPark) {
        hiddenPark = document.createElement('div');
        hiddenPark.style.cssText =
            'position:fixed;left:-9999px;top:-9999px;width:1px;height:1px;overflow:hidden;pointer-events:none';
        document.body.appendChild(hiddenPark);
    }
    return hiddenPark;
}

export function useTerminal() {
    const { t } = useI18n();

    // -------------------------------------------------------------------------
    // Window helpers
    // -------------------------------------------------------------------------

    function createWindow(
        targetKey: string,
        title: string,
        origin: TerminalOrigin,
        firstTab: TerminalTab,
    ): TerminalWindowState {
        const offset = windows.size * 30;
        const win: TerminalWindowState = {
            windowId: targetKey,
            targetKey,
            title,
            origin,
            tabs: [firstTab],
            activeTabId: firstTab.sessionId,
            isMinimized: false,
            isMaximized: false,
            preMaximizeState: null,
            position: { top: 60 + offset + 'px', left: 100 + offset + 'px' },
            size: { width: '700px', height: '450px' },
            zIndex: nextZIndex++,
            isActive: true,
        };
        windows.set(targetKey, win);
        activateWindow(targetKey);
        updateMinimizedList();
        return win;
    }

    function activateWindow(windowId: string) {
        for (const [id, w] of windows) {
            w.isActive = id === windowId;
        }
        const win = windows.get(windowId);
        if (win) {
            win.zIndex = nextZIndex++;
        }
    }

    function minimizeWindow(windowId: string) {
        const win = windows.get(windowId);
        if (win) {
            win.isMinimized = true;
            win.isActive = false;
            updateMinimizedList();
        }
    }

    function restoreWindow(windowId: string) {
        const win = windows.get(windowId);
        if (win) {
            win.isMinimized = false;
            activateWindow(windowId);
            updateMinimizedList();
        }
    }

    function toggleMaximize(windowId: string) {
        const win = windows.get(windowId);
        if (!win) return;

        activateWindow(windowId);

        if (win.isMaximized) {
            if (win.preMaximizeState) {
                win.position = { top: win.preMaximizeState.top, left: win.preMaximizeState.left };
                win.size = { width: win.preMaximizeState.width, height: win.preMaximizeState.height };
            }
            win.isMaximized = false;
            win.preMaximizeState = null;
        } else {
            win.preMaximizeState = {
                top: win.position.top,
                left: win.position.left,
                width: win.size.width,
                height: win.size.height,
            };
            win.isMaximized = true;
        }
    }

    function updateMinimizedList() {
        minimizedWindows.value = Array.from(windows.entries())
            .filter(([, w]) => w.isMinimized)
            .map(([id]) => id);
    }

    // -------------------------------------------------------------------------
    // Tab helpers
    // -------------------------------------------------------------------------

    function makeTab(sessionId: string, wsToken: string, origin: TerminalOrigin, index: number): TerminalTab {
        return { sessionId, wsToken, label: t('Tab :n', { n: index }), origin };
    }

    async function addTab(windowId: string): Promise<string | null> {
        const win = windows.get(windowId);
        if (!win) return null;
        if (win.tabs.length >= MAX_TABS_PER_WINDOW) return null;

        let res;
        try {
            if (win.origin.type === 'container') {
                res = await axios.post(route('terminal.start'), {
                    container_id: win.origin.containerId,
                    container_name: win.origin.containerName,
                });
            } else if (win.origin.type === 'domain') {
                res = await axios.post(route('terminal.start-domain'), {
                    domain_id: win.origin.domainId,
                });
            } else {
                res = await axios.post(route('terminal.start-ssh'));
            }
        } catch (err: any) {
            throw new Error(err.response?.data?.message || err.message || t('Failed to open terminal'));
        }

        if (!res.data.session_id || !res.data.ws_token) {
            throw new Error(t('Failed to start terminal session'));
        }

        const tab = makeTab(res.data.session_id, res.data.ws_token, win.origin, win.tabs.length + 1);
        win.tabs.push(tab);
        win.activeTabId = tab.sessionId;
        activateWindow(windowId);
        return tab.sessionId;
    }

    function activateTab(windowId: string, sessionId: string) {
        const win = windows.get(windowId);
        if (win) {
            win.activeTabId = sessionId;
            activateWindow(windowId);
        }
    }

    function renameTab(windowId: string, sessionId: string, newLabel: string) {
        const win = windows.get(windowId);
        if (!win) return;
        const tab = win.tabs.find((t) => t.sessionId === sessionId);
        if (tab) {
            tab.label = newLabel.trim() || tab.label;
        }
    }

    function closeTab(windowId: string, sessionId: string) {
        const win = windows.get(windowId);
        if (!win) return;

        const idx = win.tabs.findIndex((t) => t.sessionId === sessionId);
        if (idx === -1) return;

        // Dispose persistent terminal
        const pt = persistentTerminals.get(sessionId);
        if (pt) {
            pt.ws?.close();
            pt.terminal?.dispose();
            persistentTerminals.delete(sessionId);
        }

        void axios.post(route('terminal.stop'), { session_id: sessionId }, { timeout: 2500 }).catch(() => {});

        win.tabs.splice(idx, 1);

        if (win.tabs.length === 0) {
            windows.delete(windowId);
            updateMinimizedList();
            return;
        }

        // Switch to adjacent tab if needed
        if (win.activeTabId === sessionId) {
            win.activeTabId = win.tabs[Math.min(idx, win.tabs.length - 1)].sessionId;
        }
    }

    function stopAndRemove(windowId: string) {
        const win = windows.get(windowId);
        if (!win) return;

        for (const tab of win.tabs) {
            const pt = persistentTerminals.get(tab.sessionId);
            if (pt) {
                pt.ws?.close();
                pt.terminal?.dispose();
                persistentTerminals.delete(tab.sessionId);
            }
            void axios
                .post(route('terminal.stop'), { session_id: tab.sessionId }, { timeout: 2500 })
                .catch(() => {});
        }

        windows.delete(windowId);
        updateMinimizedList();
    }

    // -------------------------------------------------------------------------
    // Public open functions (backward-compatible signatures)
    // -------------------------------------------------------------------------

    async function openTerminal(containerId: string, containerName: string): Promise<string | null> {
        const targetKey = `container:${containerId}`;

        const existing = windows.get(targetKey);
        if (existing) {
            restoreWindow(targetKey);
            return existing.activeTabId;
        }

        if (pendingOpenRequests.has(targetKey)) return null;
        pendingOpenRequests.add(targetKey);

        try {
            const res = await axios.post(route('terminal.start'), {
                container_id: containerId,
                container_name: containerName,
            });

            if (!res.data.session_id || !res.data.ws_token) {
                throw new Error(res.data.error || t('Failed to start terminal session'));
            }

            const origin: TerminalOrigin = { type: 'container', containerId, containerName };
            const tab = makeTab(res.data.session_id, res.data.ws_token, origin, 1);
            createWindow(targetKey, containerName, origin, tab);
            return tab.sessionId;
        } catch (err: any) {
            throw new Error(err.response?.data?.message || err.message || t('Failed to open terminal'));
        } finally {
            pendingOpenRequests.delete(targetKey);
        }
    }

    async function openDomainTerminal(domainId: number, domainFqdn: string): Promise<string | null> {
        const targetKey = `domain:${domainId}`;

        const existing = windows.get(targetKey);
        if (existing) {
            restoreWindow(targetKey);
            return existing.activeTabId;
        }

        if (pendingOpenRequests.has(targetKey)) return null;
        pendingOpenRequests.add(targetKey);

        try {
            const res = await axios.post(route('terminal.start-domain'), {
                domain_id: domainId,
            });

            if (!res.data.session_id || !res.data.ws_token) {
                throw new Error(res.data.error || t('Failed to start terminal session'));
            }

            const origin: TerminalOrigin = { type: 'domain', domainId };
            const tab = makeTab(res.data.session_id, res.data.ws_token, origin, 1);
            createWindow(targetKey, res.data.container_name, origin, tab);
            return tab.sessionId;
        } catch (err: any) {
            throw new Error(err.response?.data?.message || err.message || t('Failed to open terminal'));
        } finally {
            pendingOpenRequests.delete(targetKey);
        }
    }

    async function openHostTerminal(): Promise<string | null> {
        const targetKey = 'ssh';

        const existing = windows.get(targetKey);
        if (existing) {
            restoreWindow(targetKey);
            return existing.activeTabId;
        }

        if (pendingHostRequest) return null;
        pendingHostRequest = true;

        try {
            const res = await axios.post(route('terminal.start-ssh'));

            if (!res.data.session_id || !res.data.ws_token) {
                throw new Error(res.data.error || t('Failed to start terminal session'));
            }

            const origin: TerminalOrigin = { type: 'ssh' };
            const tab = makeTab(res.data.session_id, res.data.ws_token, origin, 1);
            createWindow(targetKey, res.data.container_name, origin, tab);
            return tab.sessionId;
        } catch (err: any) {
            throw new Error(err.response?.data?.message || err.message || t('Failed to open terminal'));
        } finally {
            pendingHostRequest = false;
        }
    }

    // -------------------------------------------------------------------------
    // Reconnect
    // -------------------------------------------------------------------------

    async function reconnectSession(sessionId: string): Promise<string | null> {
        // Find the window + tab for this session
        let foundOrigin: TerminalOrigin | null = null;
        for (const win of windows.values()) {
            const tab = win.tabs.find((t) => t.sessionId === sessionId);
            if (tab) {
                foundOrigin = tab.origin;
                break;
            }
        }
        if (!foundOrigin) return null;

        let res;
        try {
            if (foundOrigin.type === 'container') {
                res = await axios.post(route('terminal.start'), {
                    container_id: foundOrigin.containerId,
                    container_name: foundOrigin.containerName,
                });
            } else if (foundOrigin.type === 'domain') {
                res = await axios.post(route('terminal.start-domain'), {
                    domain_id: foundOrigin.domainId,
                });
            } else {
                res = await axios.post(route('terminal.start-ssh'));
            }
        } catch (err: any) {
            throw new Error(err.response?.data?.message || err.message || t('Failed to reconnect terminal'));
        }

        if (!res.data.ws_token) {
            throw new Error(res.data.error || t('Failed to reconnect terminal'));
        }

        const pt = persistentTerminals.get(sessionId);
        if (pt?.ws) {
            pt.ws.onopen = null;
            pt.ws.onclose = null;
            pt.ws.onerror = null;
            pt.ws.onmessage = null;
            try { pt.ws.close(); } catch { /* noop */ }
            pt.ws = null;
        }

        // Update ws_token on the tab
        for (const win of windows.values()) {
            const tab = win.tabs.find((t) => t.sessionId === sessionId);
            if (tab) {
                tab.wsToken = res.data.ws_token;
                break;
            }
        }

        return res.data.ws_token;
    }

    // -------------------------------------------------------------------------
    // Persistent terminal DOM helpers (unchanged API)
    // -------------------------------------------------------------------------

    function getPersistentTerminal(sessionId: string): PersistentTerminal | undefined {
        return persistentTerminals.get(sessionId);
    }

    function setPersistentTerminal(sessionId: string, pt: PersistentTerminal): void {
        persistentTerminals.set(sessionId, pt);
    }

    function parkTerminal(sessionId: string): void {
        const pt = persistentTerminals.get(sessionId);
        if (pt?.terminal?.element) {
            getHiddenPark().appendChild(pt.terminal.element);
        }
    }

    return {
        windows,
        minimizedWindows,
        openTerminal,
        openDomainTerminal,
        openHostTerminal,
        activateWindow,
        minimizeWindow,
        restoreWindow,
        toggleMaximize,
        addTab,
        activateTab,
        closeTab,
        renameTab,
        stopAndRemove,
        reconnectSession,
        getPersistentTerminal,
        setPersistentTerminal,
        parkTerminal,
    };
}
