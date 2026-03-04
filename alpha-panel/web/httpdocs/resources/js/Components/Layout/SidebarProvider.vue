<template>
    <slot></slot>
</template>

<script setup lang="ts">
import { useSidebarProvider } from '@/Composables/useSidebar';

useSidebarProvider();
</script>
