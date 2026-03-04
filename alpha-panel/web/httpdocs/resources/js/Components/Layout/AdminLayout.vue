<template>
    <div class="min-h-screen bg-gray-50 dark:bg-gray-950 xl:flex">
        <AppSidebar />
        <Backdrop />
        <div
            class="min-h-screen flex-1 bg-gray-50 transition-all duration-300 ease-in-out dark:bg-gray-950"
            :class="[
                isExpanded || isHovered
                    ? (isRtl ? 'lg:mr-[290px]' : 'lg:ml-[290px]')
                    : (isRtl ? 'lg:mr-[90px]' : 'lg:ml-[90px]'),
            ]"
        >
            <AppHeader />
            <div class="p-4 mx-auto w-full max-w-[1800px] md:p-6">
                <slot></slot>
            </div>
        </div>
        <TerminalManager />
    </div>
</template>

<script setup lang="ts">
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';
import AppSidebar from './AppSidebar.vue';
import AppHeader from './AppHeader.vue';
import Backdrop from './Backdrop.vue';
import TerminalManager from '@/Components/Terminal/TerminalManager.vue';
import { useSidebar } from '@/Composables/useSidebar';
import type { SharedProps } from '@/types/inertia';

const { isExpanded, isHovered } = useSidebar();
const page = usePage<SharedProps>();
const isRtl = computed(() => page.props.text_direction === 'rtl');
</script>
