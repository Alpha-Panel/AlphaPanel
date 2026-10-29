<template>
    <div ref="root" class="relative">
        <button type="button" :title="t('Theme')" class="ap-header-btn" @click="open = !open">
            <i :class="['bx', isDarkMode ? 'bx-moon' : 'bx-sun', 'text-base']"></i>
        </button>
        <div v-if="open" class="ap-pop end-0 top-[calc(100%+6px)] w-47.5">
            <button v-for="o in themes" :key="o.value" type="button" :class="optClass(theme === o.value)" @click="setTheme(o.value)">
                <i :class="['bx', o.icon, 'text-[15px]']"></i>{{ t(o.label) }}
                <i v-if="theme === o.value" class="bx bx-check ms-auto text-[15px]"></i>
            </button>

            <div class="ap-eyebrow mt-1 flex items-center gap-1.5 border-t border-line-2 px-2 pt-2 pb-1">{{ t('Density') }}</div>
            <button v-for="o in densities" :key="o" type="button" :class="optClass(density === o, true)" @click="setDensity(o)">
                {{ t(o === 'compact' ? 'Compact' : 'Comfortable') }}
                <i v-if="density === o" class="bx bx-check ms-auto text-[15px]"></i>
            </button>

            <div class="ap-eyebrow mt-1 flex items-center gap-1.5 border-t border-line-2 px-2 pt-2 pb-1">
                <i class="bx bx-terminal text-[13px]"></i>{{ t('Terminal') }}
            </div>
            <button v-for="o in terminalModes" :key="o.value" type="button" :class="optClass(terminalMode === o.value, true)" @click="setTerminalMode(o.value)">
                <i :class="['bx', o.icon, 'text-[13px]']"></i>{{ t(o.label) }}
                <i v-if="terminalMode === o.value" class="bx bx-check ms-auto text-[15px]"></i>
            </button>

            <div class="ap-eyebrow mt-1 flex items-center gap-1.5 border-t border-line-2 px-2 pt-2 pb-1">
                <i class="bx bx-low-vision text-[13px]"></i>{{ t('Color Blind Mode') }}
            </div>
            <button v-for="o in cbOptions" :key="o.value" type="button" :class="optClass(cbMode === o.value, true)" @click="setCbMode(o.value)">
                {{ t(o.label) }}
                <i v-if="cbMode === o.value" class="bx bx-check ms-auto text-[15px]"></i>
            </button>
        </div>
    </div>
</template>

<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from 'vue';
import { useTheme } from '@/Components/Layout/ThemeProvider.vue';
import { useColorBlindMode, type ColorBlindMode } from '@/Composables/useColorBlindMode';
import { useI18n } from '@/Composables/useI18n';
import { useTerminalMode, type TerminalMode } from '@/Composables/useTerminalMode';

const { t } = useI18n();
const { theme, isDarkMode, setTheme, density, setDensity } = useTheme();
const { mode: cbMode, setMode: setCbMode } = useColorBlindMode();
const { mode: terminalMode, setMode: setTerminalMode } = useTerminalMode();

const terminalModes: { value: TerminalMode; icon: string; label: string }[] = [
    { value: 'floating', icon: 'bx-window-alt', label: 'Floating windows' },
    { value: 'docked', icon: 'bx-dock-bottom', label: 'Bottom panel' },
];

const themes = [
    { value: 'light' as const, icon: 'bx-sun', label: 'Light' },
    { value: 'dark' as const, icon: 'bx-moon', label: 'Dark' },
];
const densities = ['compact', 'comfortable'] as const;
const cbOptions: { value: ColorBlindMode; label: string }[] = [
    { value: 'off', label: 'Off' },
    { value: 'protanopia', label: 'Protanopia' },
    { value: 'deuteranopia', label: 'Deuteranopia' },
    { value: 'tritanopia', label: 'Tritanopia' },
];

const optClass = (active: boolean, small = false) => [
    'flex w-full items-center gap-2.5 rounded-md px-2 text-start',
    small ? 'h-7 text-xs' : 'h-7.5 text-sm',
    active ? 'bg-accent-soft text-accent' : 'text-fg-2 hover:bg-surface-2',
];

const open = ref(false);
const root = ref<HTMLElement | null>(null);
const onDoc = (e: MouseEvent) => {
    if (root.value && !root.value.contains(e.target as Node)) open.value = false;
};
onMounted(() => document.addEventListener('click', onDoc));
onBeforeUnmount(() => document.removeEventListener('click', onDoc));
</script>
