<template>
    <div class="flex flex-wrap items-center gap-2.5">
        <i v-if="icon" :class="['bx', icon, 'text-lg text-fg-3']"></i>
        <h1 class="m-0 text-lg font-semibold tracking-tight">{{ title }}</h1>
        <span v-if="meta" class="font-mono text-[11px] text-fg-3">{{ meta }}</span>
        <div class="flex-1"></div>
        <div class="flex flex-wrap items-center gap-2"><slot /></div>
    </div>
</template>

<script setup lang="ts">
defineProps<{ title: string; icon?: string; meta?: string }>();
</script>
