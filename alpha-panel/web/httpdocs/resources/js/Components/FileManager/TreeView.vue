<template>
    <div class="py-1 text-sm">
        <TreeNode
            v-for="item in rootItems"
            :key="item.path"
            :item="item"
            :depth="0"
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
        <div v-if="rootItems.length === 0" class="px-3 py-4 text-center text-xs text-gray-400">
            Loading...
        </div>
    </div>
</template>

<script setup lang="ts">
import { computed } from 'vue';
import TreeNode from './TreeNode.vue';
import type { FileItem } from '@/Composables/useFileManager';

const props = defineProps<{
    treeCache: Map<string, FileItem[]>;
    selectedItems: Set<string>;
    currentPath: string;
}>();

defineEmits<{
    navigate: [path: string];
    toggle: [item: FileItem];
    'open-file': [path: string];
    select: [item: FileItem];
    'context-menu': [event: { x: number; y: number; item: FileItem }];
    'drop-item': [event: { sourcePath: string; targetPath: string }];
}>();

const rootItems = computed(() => props.treeCache.get('') || []);
</script>
