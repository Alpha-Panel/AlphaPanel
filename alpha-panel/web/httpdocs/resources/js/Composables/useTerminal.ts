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

const sessions = reactive(new Map<string, TerminalSession>());
const minimizedSessions = ref<string[]>([]);
const pendingOpenRequests = reactive(new Set<string>());
let pendingHostRequest = false;
let nextZIndex = 200000;

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

        void axios
            .post(route('terminal.stop'), { session_id: sessionId }, { timeout: 2500 })
            .catch(() => {});
    }

    function updateMinimizedList() {
        minimizedSessions.value = Array.from(sessions.entries())
            .filter(([, s]) => s.isMinimized)
            .map(([id]) => id);
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

    return {
        sessions,
        minimizedSessions,
        openTerminal,
        openHostTerminal,
        createSession,
        activateSession,
        minimizeSession,
        restoreSession,
        toggleMaximize,
        stopAndRemove,
    };
}
