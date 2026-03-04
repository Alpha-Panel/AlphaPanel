import { ref, reactive } from 'vue';
import axios from 'axios';
import { useI18n } from '@/Composables/useI18n';

export interface TerminalSession {
    sessionId: string;
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
const queuedInput = reactive(new Map<string, string>());
const flushingSessions = reactive(new Set<string>());
const inputTimers = reactive(new Map<string, ReturnType<typeof setTimeout>>());
let nextZIndex = 200000;
const INPUT_BATCH_FLUSH_MS = 300;
const INPUT_MAX_CHUNK_LENGTH = 96;

export function useTerminal() {
    const { t } = useI18n();
    async function openTerminal(containerId: string, containerName: string) {
        const existingSession = Array.from(sessions.values()).find((session) => session.containerName === containerName);
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

            if (!res.data.session_id) {
                throw new Error(res.data.error || t('Failed to start terminal session'));
            }

            createSession(res.data.session_id, res.data.container_name);
            saveSessionsToStorage();
            return res.data.session_id;
        } catch (err: any) {
            throw new Error(err.response?.data?.message || err.message || t('Failed to open terminal'));
        } finally {
            pendingOpenRequests.delete(containerId);
        }
    }

    function createSession(sessionId: string, containerName: string, minimized = false): TerminalSession {
        if (sessions.has(sessionId)) {
            restoreSession(sessionId);
            return sessions.get(sessionId)!;
        }

        const offset = sessions.size * 30;
        const session: TerminalSession = {
            sessionId,
            containerName,
            isMinimized: minimized,
            isMaximized: false,
            preMaximizeState: null,
            position: { top: (60 + offset) + 'px', left: (100 + offset) + 'px' },
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

    async function reconnectSessions() {
        const stored = getSessionsFromStorage();
        if (!stored || stored.length === 0) {
            return;
        }

        await Promise.all(stored.map(async (entry) => {
            try {
                const res = await axios.post(route('terminal.reconnect'), {
                    session_id: entry.sessionId,
                });

                const session = createSession(res.data.session_id, res.data.container_name, entry.minimized);
                if (entry.size?.width && entry.size?.height) {
                    session.size = {
                        width: entry.size.width,
                        height: entry.size.height,
                    };
                }
            } catch {
                removeSessionFromStorage(entry.sessionId);
            }
        }));
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
            saveSessionsToStorage();
        }
    }

    function restoreSession(sessionId: string) {
        const session = sessions.get(sessionId);
        if (session) {
            session.isMinimized = false;
            activateSession(sessionId);
            updateMinimizedList();
            saveSessionsToStorage();
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
        const timer = inputTimers.get(sessionId);
        if (timer) {
            clearTimeout(timer);
            inputTimers.delete(sessionId);
        }

        queuedInput.delete(sessionId);
        flushingSessions.delete(sessionId);

        sessions.delete(sessionId);
        removeSessionFromStorage(sessionId);
        updateMinimizedList();

        void axios
            .post(
                route('terminal.stop'),
                { session_id: sessionId },
                {
                    timeout: 2500,
                },
            )
            .catch(() => {
                // Session cleanup will happen via TTL even if stop request fails.
            });
    }

    function shouldFlushImmediately(input: string): boolean {
        return (
            input.includes('\r') ||
            input.includes('\n') ||
            input.includes('\u0003') ||
            input.includes('\u0004') ||
            input.includes('\u001b') ||
            input.length >= INPUT_MAX_CHUNK_LENGTH
        );
    }

    function queueInputFlush(sessionId: string, delay = INPUT_BATCH_FLUSH_MS): void {
        if (inputTimers.has(sessionId)) {
            return;
        }

        const timer = setTimeout(() => {
            inputTimers.delete(sessionId);
            void flushInput(sessionId);
        }, delay);

        inputTimers.set(sessionId, timer);
    }

    async function flushInput(sessionId: string): Promise<void> {
        if (flushingSessions.has(sessionId)) {
            queueInputFlush(sessionId, 40);
            return;
        }

        const data = queuedInput.get(sessionId);
        if (!data) {
            return;
        }

        flushingSessions.add(sessionId);
        queuedInput.delete(sessionId);

        try {
            await axios.post(route('terminal.input'), {
                session_id: sessionId,
                data: btoa(data),
            });
        } catch (err) {
            const buffered = queuedInput.get(sessionId) ?? '';
            queuedInput.set(sessionId, data + buffered);
            console.warn('[Terminal] Failed to send input:', err);
        } finally {
            flushingSessions.delete(sessionId);

            const buffered = queuedInput.get(sessionId) ?? '';
            if (!buffered) {
                return;
            }

            if (shouldFlushImmediately(buffered)) {
                void flushInput(sessionId);
            } else {
                queueInputFlush(sessionId);
            }
        }
    }

    function sendInput(sessionId: string, data: string) {
        if (!data) {
            return;
        }

        const current = queuedInput.get(sessionId) ?? '';
        const next = current + data;
        queuedInput.set(sessionId, next);

        if (shouldFlushImmediately(next)) {
            const timer = inputTimers.get(sessionId);
            if (timer) {
                clearTimeout(timer);
                inputTimers.delete(sessionId);
            }

            void flushInput(sessionId);
            return;
        }

        queueInputFlush(sessionId);
    }

    function updateMinimizedList() {
        minimizedSessions.value = Array.from(sessions.entries())
            .filter(([, s]) => s.isMinimized)
            .map(([id]) => id);
    }

    // Storage
    function saveSessionsToStorage() {
        const entries = Array.from(sessions.entries()).map(([sessionId, s]) => ({
            sessionId,
            containerName: s.containerName,
            minimized: s.isMinimized,
            size: s.size,
        }));
        localStorage.setItem('terminal_sessions', JSON.stringify(entries));
    }

    function getSessionsFromStorage(): Array<{
        sessionId: string;
        containerName: string;
        minimized: boolean;
        size?: { width: string; height: string };
    }> {
        try {
            return JSON.parse(localStorage.getItem('terminal_sessions') || '[]');
        } catch {
            return [];
        }
    }

    function removeSessionFromStorage(sessionId: string) {
        const stored = getSessionsFromStorage();
        localStorage.setItem('terminal_sessions', JSON.stringify(stored.filter(s => s.sessionId !== sessionId)));
    }

    return {
        sessions,
        minimizedSessions,
        openTerminal,
        createSession,
        reconnectSessions,
        activateSession,
        minimizeSession,
        restoreSession,
        toggleMaximize,
        stopAndRemove,
        sendInput,
        saveSessionsToStorage,
    };
}
