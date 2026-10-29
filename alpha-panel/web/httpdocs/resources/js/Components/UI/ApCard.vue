<template>
    <section class="ap-card" :class="{ 'flex flex-col': $slots.default }">
        <header v-if="title || $slots.actions" class="ap-card-head">
            <i v-if="icon" :class="['bx', icon, 'text-[15px] text-fg-3']"></i>
            <span class="truncate">{{ title }}</span>
            <div v-if="$slots.actions" class="ms-auto flex items-center gap-1.5 font-normal"><slot name="actions" /></div>
        </header>
        <div :class="flush ? '' : 'px-3.5 py-3'"><slot /></div>
        <footer v-if="$slots.footer" class="flex items-center justify-between border-t border-line-2 px-3 py-2 text-[11px] text-fg-3">
            <slot name="footer" />
        </footer>
    </section>
</template>

<script setup lang="ts">
defineProps<{ title?: string; icon?: string; flush?: boolean }>();
</script>
