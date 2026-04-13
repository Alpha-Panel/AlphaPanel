import { ref, reactive } from 'vue';
import axios from 'axios';
import { useI18n } from '@/Composables/useI18n';

export interface TerminalSession {
    sessionId: string;
    wsToken: string;
    containerName: string;
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

const sessions = reactive(new Map<string, TerminalSession>());
const minimizedSessions = ref<string[]>([]);
const pendingOpenRequests = reactive(new Set<string>());
let pendingHostRequest = false;
let nextZIndex = 200000;

// Persistent terminal instances survive Inertia navigations
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

    async function openTerminal(containerId: string, containerName: string) {
        const existingSession = Array.from(sessions.values()).find(
            (session) => session.containerName === containerName,
        );
        if (existingSession) {
            restoreSession(existingSession.sessionId);
            activateSession(existingSession.sessionId);
            return existingSession.sessionId;
        }

        if (pendingOpenRequests.has(containerId)) {
            return null;
        }

        pendingOpenRequests.add(containerId);

        try {
            const res = await axios.post(route('terminal.start'), {
                container_id: containerId,
                container_name: containerName,
            });

            if (!res.data.session_id || !res.data.ws_token) {
                throw new Error(res.data.error || t('Failed to start terminal session'));
            }

            createSession(res.data.session_id, res.data.ws_token, res.data.container_name);
            return res.data.session_id;
        } catch (err: any) {
            throw new Error(err.response?.data?.message || err.message || t('Failed to open terminal'));
        } finally {
            pendingOpenRequests.delete(containerId);
        }
    }

    async function openDomainTerminal(domainId: number, domainFqdn: string) {
        const key = `domain-${domainId}`;
        const existingSession = Array.from(sessions.values()).find(
            (session) => session.containerName.startsWith(domainFqdn + ' ('),
        );
        if (existingSession) {
            restoreSession(existingSession.sessionId);
            activateSession(existingSession.sessionId);
            return existingSession.sessionId;
        }

        if (pendingOpenRequests.has(key)) {
            return null;
        }

        pendingOpenRequests.add(key);

        try {
            const res = await axios.post(route('terminal.start-domain'), {
                domain_id: domainId,
            });

            if (!res.data.session_id || !res.data.ws_token) {
                throw new Error(res.data.error || t('Failed to start terminal session'));
            }

            createSession(res.data.session_id, res.data.ws_token, res.data.container_name);
            return res.data.session_id;
        } catch (err: any) {
            throw new Error(err.response?.data?.message || err.message || t('Failed to open terminal'));
        } finally {
            pendingOpenRequests.delete(key);
        }
    }

    async function openHostTerminal() {
        const existingSession = Array.from(sessions.values()).find(
            (session) => session.containerName === 'Host Terminal',
        );
        if (existingSession) {
            restoreSession(existingSession.sessionId);
            activateSession(existingSession.sessionId);
            return existingSession.sessionId;
        }

        if (pendingHostRequest) {
            return null;
        }

        pendingHostRequest = true;

        try {
            const res = await axios.post(route('terminal.start-ssh'));

            if (!res.data.session_id || !res.data.ws_token) {
                throw new Error(res.data.error || t('Failed to start terminal session'));
            }

            createSession(res.data.session_id, res.data.ws_token, res.data.container_name);
            return res.data.session_id;
        } catch (err: any) {
            throw new Error(err.response?.data?.message || err.message || t('Failed to open terminal'));
        } finally {
            pendingHostRequest = false;
        }
    }

    function createSession(
        sessionId: string,
        wsToken: string,
        containerName: string,
        minimized = false,
    ): TerminalSession {
        if (sessions.has(sessionId)) {
            restoreSession(sessionId);
            return sessions.get(sessionId)!;
        }

        const offset = sessions.size * 30;
        const session: TerminalSession = {
            sessionId,
            wsToken,
            containerName,
            isMinimized: minimized,
            isMaximized: false,
            preMaximizeState: null,
            position: { top: 60 + offset + 'px', left: 100 + offset + 'px' },
            size: { width: '700px', height: '450px' },
            zIndex: nextZIndex++,
            isActive: !minimized,
        };

        sessions.set(sessionId, session);
        if (!minimized) {
            activateSession(sessionId);
        }
        updateMinimizedList();
        return session;
    }

    function activateSession(sessionId: string) {
        for (const [id, s] of sessions) {
            s.isActive = id === sessionId;
        }
        const session = sessions.get(sessionId);
        if (session) {
            session.zIndex = nextZIndex++;
        }
    }

    function minimizeSession(sessionId: string) {
        const session = sessions.get(sessionId);
        if (session) {
            session.isMinimized = true;
            session.isActive = false;
            updateMinimizedList();
        }
    }

    function restoreSession(sessionId: string) {
        const session = sessions.get(sessionId);
        if (session) {
            session.isMinimized = false;
            activateSession(sessionId);
            updateMinimizedList();
        }
    }

    function toggleMaximize(sessionId: string) {
        const session = sessions.get(sessionId);
        if (!session) return;

        activateSession(sessionId);

        if (session.isMaximized) {
            if (session.preMaximizeState) {
                session.position = {
                    top: session.preMaximizeState.top,
                    left: session.preMaximizeState.left,
                };
                session.size = {
                    width: session.preMaximizeState.width,
                    height: session.preMaximizeState.height,
                };
            }
            session.isMaximized = false;
            session.preMaximizeState = null;
        } else {
            session.preMaximizeState = {
                top: session.position.top,
                left: session.position.left,
                width: session.size.width,
                height: session.size.height,
            };
            session.isMaximized = true;
        }
    }

    function stopAndRemove(sessionId: string) {
        sessions.delete(sessionId);
        updateMinimizedList();

        // Clean up persistent terminal instance
        const pt = persistentTerminals.get(sessionId);
        if (pt) {
            pt.ws?.close();
            pt.terminal?.dispose();
            persistentTerminals.delete(sessionId);
        }

        void axios
            .post(route('terminal.stop'), { session_id: sessionId }, { timeout: 2500 })
            .catch(() => {});
    }

    function updateMinimizedList() {
        minimizedSessions.value = Array.from(sessions.entries())
            .filter(([, s]) => s.isMinimized)
            .map(([id]) => id);
    }

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
        sessions,
        minimizedSessions,
        openTerminal,
        openDomainTerminal,
        openHostTerminal,
        createSession,
        activateSession,
        minimizeSession,
        restoreSession,
        toggleMaximize,
        stopAndRemove,
        getPersistentTerminal,
        setPersistentTerminal,
        parkTerminal,
    };
}
