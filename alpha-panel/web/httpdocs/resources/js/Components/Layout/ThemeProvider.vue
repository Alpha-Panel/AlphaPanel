<template>
    <slot></slot>
</template>

<script setup lang="ts">
import { computed, onMounted, provide, ref, watch } from 'vue';

export type Theme = 'light' | 'dark';
export type Density = 'compact' | 'comfortable';

const theme = ref<Theme>('dark');
const density = ref<Density>('compact');
const isDarkMode = computed(() => theme.value === 'dark');

const apply = (): void => {
    const root = document.documentElement;
    root.classList.toggle('dark', theme.value === 'dark');
    root.classList.toggle('density-comfortable', density.value === 'comfortable');
};

const setTheme = (v: Theme) => (theme.value = v);
const toggleTheme = () => setTheme(theme.value === 'light' ? 'dark' : 'light');
const setDensity = (v: Density) => (density.value = v);

onMounted(() => {
    const t = localStorage.getItem('theme');
    const d = localStorage.getItem('density');
    theme.value = t === 'light' || t === 'dark' ? t : 'dark';
    density.value = d === 'comfortable' ? 'comfortable' : 'compact';
    apply();
});

watch([theme, density], () => {
    localStorage.setItem('theme', theme.value);
    localStorage.setItem('density', density.value);
    apply();
});

provide('theme', { theme, isDarkMode, toggleTheme, setTheme, density, setDensity });
</script>

<script lang="ts">
import { inject, type Ref } from 'vue';

export interface ThemeContext {
    theme: Ref<'light' | 'dark'>;
    isDarkMode: Ref<boolean>;
    toggleTheme: () => void;
    setTheme: (v: 'light' | 'dark') => void;
    density: Ref<'compact' | 'comfortable'>;
    setDensity: (v: 'compact' | 'comfortable') => void;
}

export function useTheme(): ThemeContext {
    const ctx = inject<ThemeContext>('theme');
    if (!ctx) throw new Error('useTheme must be used within a ThemeProvider');
    return ctx;
}
</script>
