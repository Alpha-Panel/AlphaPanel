import { computed, readonly, ref, watch } from 'vue';

export type TerminalMode = 'floating' | 'docked';

const MODE_KEY = 'terminalMode';
const HEIGHT_KEY = 'terminalDockHeight';
const DEFAULT_DOCK_HEIGHT = 280;

function read(key: string): string | null {
    try {
        return localStorage.getItem(key);
    } catch {
        return null;
    }
}

// Floating windows are dragged/resized with a mouse; on phones and touch tablets they can't be moved.
const MOBILE_QUERY = '(max-width: 1023px), (pointer: coarse)';

// Module-level: the layout (and the dock inside it) remounts on every Inertia visit.
const preferred = ref<TerminalMode>(read(MODE_KEY) === 'docked' ? 'docked' : 'floating');
const isMobile = ref(false);

if (typeof window !== 'undefined' && window.matchMedia) {
    const query = window.matchMedia(MOBILE_QUERY);
    isMobile.value = query.matches;
    query.addEventListener('change', (event) => (isMobile.value = event.matches));
}

/** Effective mode: mobile always docks; the stored desktop preference is left untouched. */
const mode = computed<TerminalMode>(() => (isMobile.value ? 'docked' : preferred.value));
const dockHeight = ref<number>(Number(read(HEIGHT_KEY)) || DEFAULT_DOCK_HEIGHT);
const dockCollapsed = ref(false);
const dockMaximized = ref(false);

watch(preferred, (value) => {
    try {
        localStorage.setItem(MODE_KEY, value);
    } catch {
        /* storage unavailable — mode lasts for this page load only */
    }
});

watch(dockHeight, (value) => {
    try {
        localStorage.setItem(HEIGHT_KEY, String(Math.round(value)));
    } catch {
        /* storage unavailable */
    }
});

export function useTerminalMode() {
    const setMode = (value: TerminalMode): void => {
        preferred.value = value;
    };

    return { mode, isMobile: readonly(isMobile), setMode, dockHeight, dockCollapsed, dockMaximized };
}
