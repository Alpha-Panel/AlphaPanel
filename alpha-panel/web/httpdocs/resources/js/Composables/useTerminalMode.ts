import { ref, watch } from 'vue';

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

// Module-level: the layout (and the dock inside it) remounts on every Inertia visit.
const mode = ref<TerminalMode>(read(MODE_KEY) === 'docked' ? 'docked' : 'floating');
const dockHeight = ref<number>(Number(read(HEIGHT_KEY)) || DEFAULT_DOCK_HEIGHT);
const dockCollapsed = ref(false);
const dockMaximized = ref(false);

watch(mode, (value) => {
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
        mode.value = value;
    };

    return { mode, setMode, dockHeight, dockCollapsed, dockMaximized };
}
