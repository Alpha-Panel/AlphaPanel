<template>
    <slot></slot>
</template>

<script setup lang="ts">
import { computed, onMounted, provide, ref, watch } from 'vue';

type Theme = 'light' | 'dark';

const theme = ref<Theme>('dark');

const isDarkMode = computed(() => theme.value === 'dark');

const applyTheme = (value: Theme): void => {
    if (value === 'dark') {
        document.documentElement.classList.add('dark');
    } else {
        document.documentElement.classList.remove('dark');
    }
};

const toggleTheme = () => {
    theme.value = theme.value === 'light' ? 'dark' : 'light';
};

onMounted(() => {
    const savedTheme = localStorage.getItem('theme') as Theme | null;
    const initialTheme = savedTheme === 'light' || savedTheme === 'dark' ? savedTheme : 'dark';
    theme.value = initialTheme;
    applyTheme(initialTheme);
});

watch(theme, (newTheme) => {
    localStorage.setItem('theme', newTheme);
    applyTheme(newTheme);
});

provide('theme', {
    isDarkMode,
    toggleTheme,
});
</script>

<script lang="ts">
import { inject } from 'vue';

export function useTheme() {
    const theme = inject('theme');
    if (!theme) {
        throw new Error('useTheme must be used within a ThemeProvider');
    }
    return theme as { isDarkMode: import('vue').Ref<boolean>; toggleTheme: () => void };
}
</script>
