<template>
    <div>
        <div
            :style="{ paddingLeft: (depth * 16 + 8) + 'px' }"
            :class="[
                'flex cursor-pointer items-center gap-1 py-0.5 pr-2 text-xs',
                selectedItems.has(item.path) ? 'bg-brand-50 text-brand-700 dark:bg-brand-500/10 dark:text-brand-400' : 'text-gray-700 hover:bg-gray-100 dark:text-gray-400 dark:hover:bg-gray-800',
            ]"
            :draggable="true"
            @click="onClick"
            @dblclick="onDblClick"
            @contextmenu.prevent="onContextMenu"
            @dragstart="onDragStart"
            @dragend="onDragEnd"
            @dragover.prevent="onDragOver"
            @dragleave="onDragLeave"
            @drop.prevent.stop="onDrop"
        >
            <!-- Toggle arrow for directories -->
            <span v-if="item.type === 'directory'" class="inline-flex w-3 justify-center text-[10px] text-gray-400">
                <svg :class="['h-3 w-3 transition-transform', isOpen ? 'rotate-90' : '']" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M7.293 14.707a1 1 0 010-1.414L10.586 10 7.293 6.707a1 1 0 011.414-1.414l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0z" clip-rule="evenodd" />
                </svg>
            </span>
            <span v-else class="inline-block w-3"></span>

            <!-- Icon -->
            <svg v-if="item.type === 'directory'" :class="['h-3.5 w-3.5', isOpen ? 'text-yellow-500' : 'text-yellow-600']" fill="currentColor" viewBox="0 0 20 20">
                <path v-if="isOpen" d="M2 6a2 2 0 012-2h5l2 2h5a2 2 0 012 2v1H7a2 2 0 00-2 2l-1.5 6H4a2 2 0 01-2-2V6z" />
                <path v-else d="M2 6a2 2 0 012-2h5l2 2h5a2 2 0 012 2v6a2 2 0 01-2 2H4a2 2 0 01-2-2V6z" />
            </svg>
            <svg v-else class="h-3.5 w-3.5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z" />
            </svg>

            <!-- Name -->
            <span class="truncate">{{ item.name }}</span>
        </div>

        <!-- Children -->
        <div v-if="item.type === 'directory' && isOpen">
            <TreeNode
                v-for="child in children"
                :key="child.path"
                :item="child"
                :depth="depth + 1"
                :tree-cache="treeCache"
                :selected-items="selectedItems"
                :current-path="currentPath"
                @navigate="$emit('navigate', $event)"
                @toggle="$emit('toggle', $event)"
                @open-file="$emit('open-file', $event)"
                @select="$emit('select', $event)"
                @context-menu="$emit('context-menu', $event)"
                @drop-item="$emit('drop-item', $event)"
            />
        </div>
    </div>
</template>

<script setup lang="ts">
import { computed } from 'vue';
import type { FileItem } from '@/Composables/useFileManager';

const props = defineProps<{
    item: FileItem;
    depth: number;
    treeCache: Map<string, FileItem[]>;
    selectedItems: Set<string>;
    currentPath: string;
}>();

const emit = defineEmits<{
    navigate: [path: string];
    toggle: [item: FileItem];
    'open-file': [path: string];
    select: [item: FileItem];
    'context-menu': [event: { x: number; y: number; item: FileItem }];
    'drop-item': [event: { sourcePath: string; targetPath: string }];
}>();

const isOpen = computed(() => props.treeCache.has(props.item.path));
const children = computed(() => props.treeCache.get(props.item.path) || []);

let dragSourcePath: string | null = null;

function onClick() {
    emit('select', props.item);
    if (props.item.type === 'directory') {
        emit('toggle', props.item);
    }
}

function onDblClick() {
    if (props.item.type === 'file') {
        emit('open-file', props.item.path);
    } else {
        emit('navigate', props.item.path);
    }
}

function onContextMenu(event: MouseEvent) {
    emit('context-menu', { x: event.clientX, y: event.clientY, item: props.item });
}

function onDragStart(event: DragEvent) {
    dragSourcePath = props.item.path;
    event.dataTransfer!.effectAllowed = 'move';
    event.dataTransfer!.setData('text/plain', props.item.path);
}

function onDragEnd() {
    dragSourcePath = null;
}

function onDragOver(event: DragEvent) {
    if (props.item.type !== 'directory') return;
    const sourcePath = event.dataTransfer?.getData('text/plain');
    if (sourcePath === props.item.path) return;
    event.dataTransfer!.dropEffect = 'move';
    (event.currentTarget as HTMLElement).classList.add('bg-brand-100', 'dark:bg-brand-500/20');
}

function onDragLeave(event: DragEvent) {
    (event.currentTarget as HTMLElement).classList.remove('bg-brand-100', 'dark:bg-brand-500/20');
}

function onDrop(event: DragEvent) {
    (event.currentTarget as HTMLElement).classList.remove('bg-brand-100', 'dark:bg-brand-500/20');
    if (props.item.type !== 'directory') return;
    const sourcePath = event.dataTransfer?.getData('text/plain');
    if (sourcePath && sourcePath !== props.item.path) {
        emit('drop-item', { sourcePath, targetPath: props.item.path });
    }
}
</script>
