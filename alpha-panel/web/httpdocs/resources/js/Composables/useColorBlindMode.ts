import { ref, readonly, type Ref } from 'vue';

export type ColorBlindMode = 'off' | 'protanopia' | 'deuteranopia' | 'tritanopia';

const STORAGE_KEY = 'colorBlindMode';
const VALID_MODES: ColorBlindMode[] = ['off', 'protanopia', 'deuteranopia', 'tritanopia'];
const CLASS_NAMES: Record<Exclude<ColorBlindMode, 'off'>, string> = {
    protanopia: 'cb-protanopia',
    deuteranopia: 'cb-deuteranopia',
    tritanopia: 'cb-tritanopia',
};

const mode = ref<ColorBlindMode>('off');
let initialized = false;

function applyMode(next: ColorBlindMode): void {
    const root = document.documentElement;
    (Object.values(CLASS_NAMES) as string[]).forEach((cls) => root.classList.remove(cls));
    if (next !== 'off') {
        root.classList.add(CLASS_NAMES[next]);
    }
}

function readFromStorage(): ColorBlindMode {
    try {
        const stored = localStorage.getItem(STORAGE_KEY) as ColorBlindMode | null;
        return stored && VALID_MODES.includes(stored) ? stored : 'off';
    } catch {
        return 'off';
    }
}

/**
 * Initialize color blind mode from localStorage and apply the class to <html>.
 * Call once from app.ts before createInertiaApp() to prevent flash-of-wrong-state.
 */
export function initializeColorBlindMode(): void {
    if (initialized) return;
    const initial = readFromStorage();
    mode.value = initial;
    applyMode(initial);
    initialized = true;
}

export function useColorBlindMode() {
    const setMode = (next: ColorBlindMode): void => {
        if (!VALID_MODES.includes(next)) return;
        mode.value = next;
        try {
            if (next === 'off') {
                localStorage.removeItem(STORAGE_KEY);
            } else {
                localStorage.setItem(STORAGE_KEY, next);
            }
        } catch {
            /* storage unavailable — apply to DOM only */
        }
        applyMode(next);
    };

    return {
        mode: readonly(mode) as Readonly<Ref<ColorBlindMode>>,
        setMode,
    };
}
