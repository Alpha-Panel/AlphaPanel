import { Terminal } from '@xterm/xterm';
import { FitAddon } from '@xterm/addon-fit';
import { WebLinksAddon } from '@xterm/addon-web-links';
import '@xterm/xterm/css/xterm.css';
import '../css/terminal.css';
import { t } from './utils/i18n';

class TerminalManager {
    constructor() {
        /** @type {Map<string, TerminalSession>} */
        this.sessions = new Map();
        this.minimizedBar = null;
        this.dragState = null;
        this.baseZIndex = 10000;
        this.nextZIndex = this.baseZIndex + 1;

        this.init();
    }

    init() {
        // Create minimized bar
        this.minimizedBar = document.createElement('div');
        this.minimizedBar.className = 'terminal-minimized-bar';
        document.body.appendChild(this.minimizedBar);

        // Listen for open-terminal events from docker services component
        document.addEventListener('open-terminal', (e) => {
            this.openTerminal(e.detail.containerId, e.detail.containerName);
        });

        // Reconnect existing sessions from localStorage on page load
        this.reconnectSessions();
    }

    async openTerminal(containerId, containerName) {
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content;
        if (!csrfToken) {
            console.error('[Terminal] CSRF token not found');
            return;
        }

        try {
            const response = await this.fetchWithRetry('/terminal/start', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken,
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({
                    container_id: containerId,
                    container_name: containerName,
                }),
            });

            const data = await response.json();
            if (!data.session_id) {
                throw new Error(data.error || t('Failed to start terminal session'));
            }

            this.createSession(data.session_id, data.container_name);
            this.saveSessionsToStorage();
        } catch (err) {
            console.error('[Terminal] Failed to open terminal:', err);
            if (typeof toastr !== 'undefined') {
                toastr.error(t('Failed to open terminal: :error', { error: err.message }));
            }
        }
    }

    createSession(sessionId, containerName, buffer = null) {
        if (this.sessions.has(sessionId)) {
            this.restoreSession(sessionId);
            return;
        }

        const session = new TerminalSession(this, sessionId, containerName);
        this.sessions.set(sessionId, session);

        session.createWindow();
        session.initTerminal();
        session.subscribeChannel();

        if (buffer) {
            try {
                const decoded = atob(buffer);
                session.terminal.write(decoded);
            } catch (e) {
                // ignore invalid buffer
            }
        }

        session.startInputBatcher();
        this.activateSession(session);
    }

    async reconnectSessions() {
        const stored = this.getSessionsFromStorage();
        if (!stored || stored.length === 0) {
            return;
        }

        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content;
        if (!csrfToken) {
            return;
        }

        for (const entry of stored) {
            try {
                const response = await this.fetchWithRetry('/terminal/reconnect', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrfToken,
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: JSON.stringify({ session_id: entry.sessionId }),
                });

                if (!response.ok) {
                    this.removeSessionFromStorage(entry.sessionId);
                    continue;
                }

                const data = await response.json();
                this.createSession(data.session_id, data.container_name, data.buffer);

                // Restore minimized state
                if (entry.minimized) {
                    const session = this.sessions.get(data.session_id);
                    if (session) {
                        session.minimize();
                    }
                }
            } catch (err) {
                console.warn('[Terminal] Failed to reconnect session:', entry.sessionId, err);
                this.removeSessionFromStorage(entry.sessionId);
            }
        }
    }

    restoreSession(sessionId) {
        const session = this.sessions.get(sessionId);
        if (session) {
            session.restore();
        }
    }

    removeSession(sessionId) {
        const session = this.sessions.get(sessionId);
        if (session) {
            session.destroy();
        }
        this.sessions.delete(sessionId);
        this.removeSessionFromStorage(sessionId);
        this.updateMinimizedBar();
    }

    activateSession(session) {
        if (!session || !session.windowEl || session.isMinimized) {
            return;
        }

        for (const [, otherSession] of this.sessions) {
            if (otherSession.windowEl) {
                otherSession.windowEl.classList.remove('is-active');
            }
        }

        session.windowEl.style.zIndex = String(this.nextZIndex++);
        session.windowEl.classList.add('is-active');

        if (session.terminal) {
            session.terminal.focus();
        }
    }

    updateMinimizedBar() {
        this.minimizedBar.innerHTML = '';
        for (const [sessionId, session] of this.sessions) {
            if (session.isMinimized) {
                const tab = document.createElement('div');
                tab.className = 'terminal-minimized-tab';
                tab.innerHTML = `
                    <i class="bx bx-terminal"></i>
                    <span>${this.escapeHtml(session.containerName)}</span>
                    <span class="tab-close" data-session="${sessionId}">&times;</span>
                `;
                tab.addEventListener('click', (e) => {
                    if (e.target.classList.contains('tab-close')) {
                        this.stopAndRemove(sessionId);
                    } else {
                        session.restore();
                        this.updateMinimizedBar();
                    }
                });
                this.minimizedBar.appendChild(tab);
            }
        }
    }

    async stopAndRemove(sessionId) {
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content;
        try {
            await this.fetchWithRetry('/terminal/stop', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken,
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({ session_id: sessionId }),
            });
        } catch (err) {
            console.warn('[Terminal] Failed to stop session:', err);
        }
        this.removeSession(sessionId);
    }

    saveSessionsToStorage() {
        const entries = [];
        for (const [sessionId, session] of this.sessions) {
            entries.push({
                sessionId,
                containerName: session.containerName,
                minimized: session.isMinimized,
            });
        }
        localStorage.setItem('terminal_sessions', JSON.stringify(entries));
    }

    getSessionsFromStorage() {
        try {
            return JSON.parse(localStorage.getItem('terminal_sessions') || '[]');
        } catch {
            return [];
        }
    }

    removeSessionFromStorage(sessionId) {
        const stored = this.getSessionsFromStorage();
        const filtered = stored.filter(s => s.sessionId !== sessionId);
        localStorage.setItem('terminal_sessions', JSON.stringify(filtered));
    }

    async fetchWithRetry(url, options, retries = 1) {
        const response = await fetch(url, options);

        // Handle CSRF token mismatch (419)
        if (response.status === 419 && retries > 0) {
            // Refresh CSRF token
            const tokenMeta = document.querySelector('meta[name="csrf-token"]');
            if (tokenMeta) {
                try {
                    const tokenResponse = await fetch('/', { method: 'GET', credentials: 'same-origin' });
                    const html = await tokenResponse.text();
                    const match = html.match(/csrf-token.*?content="([^"]+)"/);
                    if (match) {
                        tokenMeta.content = match[1];
                        options.headers['X-CSRF-TOKEN'] = match[1];
                    }
                } catch {
                    // ignore
                }
                return this.fetchWithRetry(url, options, retries - 1);
            }
        }

        return response;
    }

    escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
}

class TerminalSession {
    constructor(manager, sessionId, containerName) {
        this.manager = manager;
        this.sessionId = sessionId;
        this.containerName = containerName;
        this.terminal = null;
        this.fitAddon = null;
        this.windowEl = null;
        this.isMinimized = false;
        this.isMaximized = false;
        this.inputBuffer = '';
        this.inputTimer = null;
        this.echoChannel = null;
        this.preMaximizeState = null;
    }

    createWindow() {
        const el = document.createElement('div');
        el.className = 'terminal-window';
        el.style.top = (60 + this.manager.sessions.size * 30) + 'px';
        el.style.left = (100 + this.manager.sessions.size * 30) + 'px';
        el.innerHTML = `
            <div class="terminal-titlebar">
                <div class="terminal-title">
                    <span class="terminal-status-dot connecting"></span>
                    <i class="bx bx-terminal"></i>
                    ${this.manager.escapeHtml(this.containerName)}
                </div>
                <div class="terminal-controls">
                    <button class="terminal-minimize" title="${this.manager.escapeHtml(t('Minimize'))}"><i class="bx bx-minus"></i></button>
                    <button class="terminal-maximize" title="${this.manager.escapeHtml(t('Maximize'))}"><i class="bx bx-expand"></i></button>
                    <button class="terminal-close" title="${this.manager.escapeHtml(t('Close'))}"><i class="bx bx-x"></i></button>
                </div>
            </div>
            <div class="terminal-body"></div>
        `;

        // Button events
        el.querySelector('.terminal-minimize').addEventListener('click', () => this.minimize());
        el.querySelector('.terminal-maximize').addEventListener('click', () => this.toggleMaximize());
        el.querySelector('.terminal-close').addEventListener('click', () => this.manager.stopAndRemove(this.sessionId));

        // Drag support
        const titlebar = el.querySelector('.terminal-titlebar');
        titlebar.addEventListener('mousedown', (e) => this.startDrag(e));
        titlebar.addEventListener('dblclick', () => this.toggleMaximize());
        el.addEventListener('mousedown', () => this.manager.activateSession(this));

        document.body.appendChild(el);
        this.windowEl = el;
    }

    initTerminal() {
        this.fitAddon = new FitAddon();

        this.terminal = new Terminal({
            cursorBlink: true,
            fontSize: 14,
            fontFamily: "'JetBrains Mono', 'Cascadia Code', 'Fira Code', Menlo, monospace",
            theme: {
                background: '#1e1e1e',
                foreground: '#00ff41',
                cursor: '#00ff41',
                selectionBackground: '#0b3d0b',
            },
            scrollback: 5000,
            allowProposedApi: true,
        });

        this.terminal.loadAddon(this.fitAddon);
        this.terminal.loadAddon(new WebLinksAddon());

        const container = this.windowEl.querySelector('.terminal-body');
        this.terminal.open(container);

        // Fit after a short delay to ensure layout is ready
        requestAnimationFrame(() => {
            this.fitAddon.fit();
        });

        // Handle input
        this.terminal.onData((data) => {
            this.inputBuffer += data;
        });

        // Resize observer for fit
        this.resizeObserver = new ResizeObserver(() => {
            if (this.fitAddon && !this.isMinimized) {
                this.fitAddon.fit();
            }
        });
        this.resizeObserver.observe(this.windowEl);
    }

    subscribeChannel() {
        if (typeof window.Echo === 'undefined') {
            setTimeout(() => this.subscribeChannel(), 200);
            return;
        }

        this.echoChannel = window.Echo.private(`terminal.${this.sessionId}`);
        this.echoChannel.listen('.App\\Events\\TerminalOutput', (e) => {
            if (e.output) {
                try {
                    const decoded = atob(e.output);
                    this.terminal.write(decoded);
                } catch (err) {
                    console.warn('[Terminal] Failed to decode output:', err);
                }
            }
        });

        // Mark as connected
        const dot = this.windowEl.querySelector('.terminal-status-dot');
        if (dot) {
            dot.classList.remove('connecting');
            dot.classList.add('connected');
        }
    }

    startInputBatcher() {
        // Batch input and send every 16ms
        this.inputTimer = setInterval(() => {
            if (this.inputBuffer.length === 0) {
                return;
            }

            const data = this.inputBuffer;
            this.inputBuffer = '';

            const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content;
            this.manager.fetchWithRetry('/terminal/input', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken,
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({
                    session_id: this.sessionId,
                    data: btoa(data),
                }),
            }).catch(err => {
                console.warn('[Terminal] Failed to send input:', err);
            });
        }, 16);
    }

    minimize() {
        this.isMinimized = true;
        this.windowEl.style.display = 'none';
        this.manager.updateMinimizedBar();
        this.manager.saveSessionsToStorage();
    }

    restore() {
        this.isMinimized = false;
        this.windowEl.style.display = '';
        this.manager.activateSession(this);
        requestAnimationFrame(() => {
            this.fitAddon.fit();
        });
        this.manager.updateMinimizedBar();
        this.manager.saveSessionsToStorage();
    }

    toggleMaximize() {
        this.manager.activateSession(this);

        if (this.isMaximized) {
            this.windowEl.classList.remove('maximized');
            if (this.preMaximizeState) {
                this.windowEl.style.top = this.preMaximizeState.top;
                this.windowEl.style.left = this.preMaximizeState.left;
                this.windowEl.style.width = this.preMaximizeState.width;
                this.windowEl.style.height = this.preMaximizeState.height;
            }
            this.isMaximized = false;
        } else {
            this.preMaximizeState = {
                top: this.windowEl.style.top,
                left: this.windowEl.style.left,
                width: this.windowEl.style.width,
                height: this.windowEl.style.height,
            };
            this.windowEl.classList.add('maximized');
            this.isMaximized = true;
        }
        requestAnimationFrame(() => {
            this.fitAddon.fit();
        });
    }

    startDrag(e) {
        if (this.isMaximized) {
            return;
        }
        if (e.target.closest('.terminal-controls')) {
            return;
        }

        e.preventDefault();
        this.manager.activateSession(this);
        const rect = this.windowEl.getBoundingClientRect();
        const offsetX = e.clientX - rect.left;
        const offsetY = e.clientY - rect.top;

        const onMove = (ev) => {
            this.windowEl.style.left = (ev.clientX - offsetX) + 'px';
            this.windowEl.style.top = (ev.clientY - offsetY) + 'px';
        };

        const onUp = () => {
            document.removeEventListener('mousemove', onMove);
            document.removeEventListener('mouseup', onUp);
        };

        document.addEventListener('mousemove', onMove);
        document.addEventListener('mouseup', onUp);
    }

    destroy() {
        if (this.inputTimer) {
            clearInterval(this.inputTimer);
        }
        if (this.echoChannel) {
            window.Echo.leave(`terminal.${this.sessionId}`);
        }
        if (this.resizeObserver) {
            this.resizeObserver.disconnect();
        }
        if (this.terminal) {
            this.terminal.dispose();
        }
        if (this.windowEl) {
            this.windowEl.remove();
        }
    }
}

// Initialize when DOM is ready
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => {
        window.terminalManager = new TerminalManager();
    });
} else {
    window.terminalManager = new TerminalManager();
}
