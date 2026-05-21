<template>
    <Head :title="`${t('File Manager')} - ${domain.fqdn}`" />
    <ThemeProvider>
        <SidebarProvider>
            <AdminLayout>
                <PageBreadcrumb
                    :pageTitle="t('File Manager')"
                    :items="breadcrumbs"
                    :backHref="route('domains.show', domain.id)"
                />
                <Toast />

                <div
                    ref="containerRef"
                    :class="['fm-app rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-900', { 'fm-fullscreen': fm.isFullscreen.value }]"
                    @click="fm.hideContextMenu()"
                    @dragover.prevent="onExternalDragOver"
                    @dragleave="onExternalDragLeave"
                    @drop.prevent="onExternalDrop"
                >
                    <!-- Toolbar -->
                    <div class="flex flex-wrap items-center gap-1 border-b border-gray-200 px-3 py-2 dark:border-gray-800">
                        <button @click="promptNewFile" class="fm-btn" :title="t('New File')">
                            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 13h6m-3-3v6m5 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" /></svg>
                        </button>
                        <button @click="promptNewFolder" class="fm-btn" :title="t('New Folder')">
                            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 13h6m-3-3v6m-9 1V7a2 2 0 012-2h6l2 2h6a2 2 0 012 2v8a2 2 0 01-2 2H5a2 2 0 01-2-2z" /></svg>
                        </button>
                        <button @click="triggerUpload" class="fm-btn" :title="t('Upload')">
                            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12" /></svg>
                        </button>
                        <div class="mx-1 h-5 w-px bg-gray-300 dark:bg-gray-700"></div>
                        <button @click="promptRename" :disabled="!fm.singleSelection.value" class="fm-btn hidden sm:inline-flex" :title="t('Rename')">
                            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" /></svg>
                        </button>
                        <button @click="confirmDelete" :disabled="!fm.hasSelection.value" class="fm-btn text-error-500" :title="t('Delete')">
                            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" /></svg>
                        </button>
                        <button @click="downloadSelected" :disabled="!fm.hasSelection.value" class="fm-btn hidden sm:inline-flex" :title="t('Download')">
                            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" /></svg>
                        </button>
                        <div class="mx-1 hidden h-5 w-px bg-gray-300 sm:block dark:bg-gray-700"></div>
                        <button @click="promptCompress" :disabled="!fm.hasSelection.value" class="fm-btn hidden sm:inline-flex" :title="t('Compress')">
                            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8m-9 4h4" /></svg>
                        </button>
                        <button @click="extractSelected" :disabled="!fm.hasZipSelected.value" class="fm-btn hidden sm:inline-flex" :title="t('Extract')">
                            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8m-9 4l3 3m0 0l3-3m-3 3V8" /></svg>
                        </button>
                        <div class="flex-1"></div>
                        <button @click="fm.refresh()" class="fm-btn" :title="t('Refresh')">
                            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" /></svg>
                        </button>
                        <button @click="fm.isFullscreen.value = !fm.isFullscreen.value" class="fm-btn hidden sm:inline-flex" :title="t('Fullscreen')">
                            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 8V4m0 0h4M4 4l5 5m11-1V4m0 0h-4m4 0l-5 5M4 16v4m0 0h4m-4 0l5-5m11 5l-5-5m5 5v-4m0 4h-4" /></svg>
                        </button>
                    </div>

                    <!-- Mobile Tab Switcher -->
                    <div class="flex sm:hidden border-b border-gray-200 dark:border-gray-800">
                        <button
                            @click="mobileTab = 'tree'"
                            :class="mobileTab === 'tree'
                                ? 'border-b-2 border-brand-500 text-brand-600 dark:text-brand-400'
                                : 'text-gray-500 dark:text-gray-400'"
                            class="flex flex-1 items-center justify-center gap-1.5 px-3 py-2 text-xs font-medium transition-colors"
                        >
                            <i class="bx bx-folder-open text-sm"></i>
                            {{ t('Files') }}
                        </button>
                        <button
                            @click="mobileTab = 'editor'"
                            :class="mobileTab === 'editor'
                                ? 'border-b-2 border-brand-500 text-brand-600 dark:text-brand-400'
                                : 'text-gray-500 dark:text-gray-400'"
                            class="flex flex-1 items-center justify-center gap-1.5 px-3 py-2 text-xs font-medium transition-colors"
                        >
                            <i class="bx bx-code-alt text-sm"></i>
                            {{ t('Editor') }}
                        </button>
                    </div>

                    <div class="flex flex-1 overflow-hidden">
                        <!-- Sidebar / Tree -->
                        <div
                            ref="sidebarRef"
                            :class="[mobileTab !== 'tree' ? 'hidden sm:block' : '', 'shrink-0 overflow-y-auto border-r border-gray-200 bg-gray-50 dark:border-gray-800 dark:bg-gray-900/50']"
                            :style="isMobile ? {} : { width: fm.sidebarWidth.value + 'px' }"
                        >
                            <TreeView
                                :tree-cache="fm.treeCache"
                                :selected-items="fm.selectedItems"
                                :current-path="fm.currentPath.value"
                                :is-mobile="isMobile"
                                @navigate="fm.loadDirectory($event)"
                                @toggle="fm.toggleTreeDir($event)"
                                @open-file="onOpenFile($event)"
                                @select="onTreeSelect"
                                @context-menu="onTreeContextMenu"
                                @drop-item="onDropItem"
                            />
                        </div>

                        <!-- Resize Handle -->
                        <div
                            class="hidden w-1 cursor-col-resize bg-gray-200 hover:bg-brand-400 sm:block dark:bg-gray-800 dark:hover:bg-brand-600"
                            @mousedown="startResize"
                        ></div>

                        <!-- Main Area -->
                        <div :class="[mobileTab !== 'editor' ? 'hidden sm:flex' : 'flex', 'flex-1 flex-col overflow-hidden']">
                            <!-- Breadcrumb -->
                            <div class="flex items-center gap-1 border-b border-gray-200 px-3 py-1.5 text-xs dark:border-gray-800">
                                <template v-for="(crumb, i) in fm.breadcrumbs.value" :key="crumb.path">
                                    <span v-if="i > 0" class="text-gray-400">/</span>
                                    <button
                                        @click="fm.loadDirectory(crumb.path)"
                                        :class="['hover:text-brand-500', i === fm.breadcrumbs.value.length - 1 ? 'font-medium text-gray-800 dark:text-white/90' : 'text-gray-500 dark:text-gray-400']"
                                    >
                                        {{ crumb.name }}
                                    </button>
                                </template>
                            </div>

                            <!-- Tab Bar -->
                            <div v-if="fm.openTabs.size > 0" class="flex items-center gap-0 overflow-x-auto border-b border-gray-200 bg-gray-50 dark:border-gray-800 dark:bg-gray-900/50">
                                <div
                                    v-for="[path, tab] in fm.openTabs"
                                    :key="path"
                                    @click="fm.activateTab(path)"
                                    :class="['group flex cursor-pointer items-center gap-1.5 border-r border-gray-200 px-3 py-1.5 text-xs dark:border-gray-800', path === fm.activeTab.value ? 'bg-white text-gray-800 dark:bg-gray-900 dark:text-white/90' : 'text-gray-500 hover:bg-gray-100 dark:text-gray-400 dark:hover:bg-gray-800']"
                                >
                                    <span :class="{ 'italic': tab.modified }">
                                        {{ path.split('/').pop() }}
                                        <span v-if="tab.modified" class="text-brand-500">*</span>
                                    </span>
                                    <button
                                        @click.stop="fm.closeTab(path)"
                                        class="ml-1 inline-flex items-center justify-center rounded text-gray-400 hover:bg-gray-200 hover:text-gray-700 dark:hover:bg-gray-700 dark:hover:text-gray-200 min-h-7 min-w-7 sm:hidden sm:min-h-0 sm:min-w-0 sm:p-0.5 sm:group-hover:inline-flex"
                                    >&times;</button>
                                </div>
                            </div>

                            <!-- Content Area -->
                            <div class="flex-1 overflow-hidden">
                                <!-- File List (shown when no active tab) -->
                                <div v-if="!fm.activeTab.value" class="relative h-full overflow-auto">
                                    <div
                                        v-if="fm.loading.value"
                                        class="absolute inset-0 z-20 flex items-center justify-center bg-white/70 text-sm font-medium text-gray-600 backdrop-blur-[1px] dark:bg-gray-900/70 dark:text-gray-300"
                                    >
                                        {{ t('Loading...') }}
                                    </div>
                                    <table :class="['w-full text-sm', { 'opacity-60': fm.loading.value }]">
                                        <thead class="sticky top-0 z-10 bg-gray-50 dark:bg-gray-900/80">
                                            <tr class="border-b border-gray-200 dark:border-gray-800">
                                                <th class="w-8 px-2 py-2">
                                                    <input
                                                        type="checkbox"
                                                        :checked="fm.fileList.value.length > 0 && fm.fileList.value.every(i => fm.selectedItems.has(i.path))"
                                                        @change="($event.target as HTMLInputElement).checked ? fm.selectAll() : fm.deselectAll()"
                                                        class="h-3.5 w-3.5 rounded border-gray-300 text-brand-500"
                                                    />
                                                </th>
                                                <th class="px-3 py-2 text-left text-xs font-medium uppercase text-gray-500">{{ t('Name') }}</th>
                                                <th class="hidden w-24 px-3 py-2 text-left text-xs font-medium uppercase text-gray-500 sm:table-cell">{{ t('Perms') }}</th>
                                                <th class="w-24 px-3 py-2 text-left text-xs font-medium uppercase text-gray-500">{{ t('Size') }}</th>
                                                <th class="hidden w-40 px-3 py-2 text-left text-xs font-medium uppercase text-gray-500 sm:table-cell">{{ t('Modified') }}</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <!-- Go up row -->
                                            <tr
                                                v-if="fm.currentPath.value"
                                                @click="isMobile && fm.loadDirectory(fm.getParentPath(fm.currentPath.value))"
                                                @dblclick="fm.loadDirectory(fm.getParentPath(fm.currentPath.value))"
                                                class="cursor-pointer border-b border-gray-100 hover:bg-gray-50 dark:border-gray-800 dark:hover:bg-white/2"
                                            >
                                                <td></td>
                                                <td class="px-3 py-1.5 text-gray-500">
                                                    <span class="inline-flex items-center gap-2">
                                                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 10l7-7m0 0l7 7m-7-7v18" /></svg>
                                                        ..
                                                    </span>
                                                </td>
                                                <td class="hidden sm:table-cell"></td><td></td><td class="hidden sm:table-cell"></td>
                                            </tr>
                                            <!-- File rows -->
                                            <tr
                                                v-for="(item, index) in fm.fileList.value"
                                                :key="item.path"
                                                :draggable="true"
                                                @click="onRowClick(item, $event, index)"
                                                @dblclick="onDblClick(item)"
                                                @contextmenu.prevent="onFileContextMenu($event, item)"
                                                @dragstart="onDragStart($event, item)"
                                                @dragend="onDragEnd"
                                                @dragover.prevent="onDragOver($event, item)"
                                                @dragleave="onDragLeave($event)"
                                                @drop.prevent.stop="onDrop($event, item)"
                                                :class="['cursor-pointer border-b border-gray-100 dark:border-gray-800', fm.selectedItems.has(item.path) ? 'bg-brand-50 dark:bg-brand-500/10' : 'hover:bg-gray-50 dark:hover:bg-white/2']"
                                            >
                                                <td class="px-2 py-1.5">
                                                    <input
                                                        type="checkbox"
                                                        :checked="fm.selectedItems.has(item.path)"
                                                        @click.stop
                                                        @change="toggleCheckbox(item.path, index)"
                                                        class="h-3.5 w-3.5 rounded border-gray-300 text-brand-500"
                                                    />
                                                </td>
                                                <td class="px-3 py-1.5">
                                                    <span class="inline-flex items-center gap-2">
                                                        <FileIcon :type="fm.getFileIcon(item)" />
                                                        <span class="text-gray-800 dark:text-white/90">{{ item.name }}</span>
                                                    </span>
                                                </td>
                                                <td class="hidden px-3 py-1.5 font-mono text-xs text-gray-500 sm:table-cell">{{ item.permissions }}</td>
                                                <td class="px-3 py-1.5 text-xs text-gray-500">{{ fm.formatSize(item.size) }}</td>
                                                <td class="hidden px-3 py-1.5 text-xs text-gray-500 sm:table-cell">{{ fm.formatDate(item.lastModified) }}</td>
                                            </tr>
                                            <tr v-if="!fm.loading.value && fm.fileList.value.length === 0">
                                                <td colspan="5" class="px-3 py-8 text-center text-gray-400">{{ t('Empty directory') }}</td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>

                                <!-- Monaco Editor (shown when tab is active) -->
                                <div v-show="fm.activeTab.value" ref="editorRef" class="h-full w-full"></div>
                            </div>

                            <!-- Status Bar -->
                            <div class="flex items-center justify-between border-t border-gray-200 px-3 py-1 text-xs text-gray-500 dark:border-gray-800 dark:text-gray-400">
                                <span>{{ fm.statusText.value }}</span>
                                <span v-if="fm.selectedItems.size > 0">{{ fm.selectedItems.size }} {{ t('selected') }}</span>
                            </div>
                        </div>
                    </div>

                    <!-- Upload Drop Zone Overlay -->
                    <div
                        v-if="showDropZone"
                        class="absolute inset-0 z-50 flex items-center justify-center rounded-2xl bg-brand-500/10 border-2 border-dashed border-brand-400"
                    >
                        <p class="text-lg font-medium text-brand-600 dark:text-brand-400">{{ t('Drop files here to upload') }}</p>
                    </div>
                </div>

                <!-- Context Menu -->
                <div
                    v-if="fm.contextMenu.visible"
                    ref="contextMenuRef"
                    :style="contextMenuStyle"
                    class="fixed z-99999 min-w-45 rounded-lg border border-gray-200 bg-white py-1 shadow-lg dark:border-gray-700 dark:bg-gray-800"
                    @click.stop
                >
                    <template v-if="fm.contextMenu.item?.type === 'file' && fm.singleSelection.value">
                        <button @click="ctxAction('open')" class="ctx-item">{{ t('Open in Editor') }}</button>
                        <button @click="ctxAction('download')" class="ctx-item">{{ t('Download') }}</button>
                        <template v-if="fm.contextMenu.item.name.toLowerCase().endsWith('.zip')">
                            <button @click="ctxAction('extract')" class="ctx-item">{{ t('Extract Here') }}</button>
                        </template>
                        <div class="my-1 border-t border-gray-200 dark:border-gray-700"></div>
                    </template>
                    <template v-if="fm.contextMenu.item?.type === 'directory' && fm.singleSelection.value">
                        <button @click="ctxAction('open-dir')" class="ctx-item">{{ t('Open') }}</button>
                        <div class="my-1 border-t border-gray-200 dark:border-gray-700"></div>
                    </template>
                    <button @click="ctxAction('new-file')" class="ctx-item">{{ t('New File') }}</button>
                    <button @click="ctxAction('new-folder')" class="ctx-item">{{ t('New Folder') }}</button>
                    <button @click="ctxAction('upload')" class="ctx-item">{{ t('Upload') }}</button>
                    <template v-if="fm.hasSelection.value">
                        <div class="my-1 border-t border-gray-200 dark:border-gray-700"></div>
                        <button @click="ctxAction('compress')" class="ctx-item">{{ t('Compress') }}</button>
                        <template v-if="fm.singleSelection.value">
                            <button @click="ctxAction('rename')" class="ctx-item">{{ t('Rename') }}</button>
                            <button @click="ctxAction('chmod')" class="ctx-item">{{ t('Permissions') }}</button>
                        </template>
                        <button @click="ctxAction('delete')" class="ctx-item text-error-500">{{ t('Delete') }}</button>
                    </template>
                </div>

                <!-- Prompt Modal -->
                <div v-if="fm.modal.visible" class="fixed inset-0 z-99999 flex items-center justify-center bg-gray-900/50">
                    <div class="w-full max-w-sm rounded-2xl border border-gray-200 bg-white p-5 shadow-xl dark:border-gray-800 dark:bg-gray-900">
                        <h4 class="mb-3 text-base font-semibold text-gray-800 dark:text-white/90">{{ fm.modal.title }}</h4>
                        <label class="mb-1.5 block text-sm text-gray-600 dark:text-gray-400">{{ fm.modal.label }}</label>
                        <input
                            ref="modalInputRef"
                            v-model="fm.modal.value"
                            type="text"
                            class="form-input mb-4"
                            @keydown.enter="fm.confirmModal()"
                        />
                        <div class="flex gap-2">
                            <button @click="fm.confirmModal()" class="rounded-lg bg-brand-500 px-4 py-2 text-sm font-medium text-white hover:bg-brand-600">{{ t('OK') }}</button>
                            <button @click="fm.cancelModal()" class="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-400">{{ t('Cancel') }}</button>
                        </div>
                    </div>
                </div>

                <!-- Hidden upload input -->
                <input ref="uploadInputRef" type="file" multiple class="hidden" @change="handleUpload" />
            </AdminLayout>
        </SidebarProvider>
    </ThemeProvider>

    <!-- Upload Progress Toast -->
    <Transition name="fm-upload-toast">
        <div
            v-if="fm.uploadTasks.value.length > 0"
            class="fixed bottom-5 right-5 z-99999 w-80 rounded-xl border border-gray-200 bg-white shadow-xl dark:border-gray-700 dark:bg-gray-800"
        >
            <div class="border-b border-gray-100 px-4 py-2.5 dark:border-gray-700">
                <p class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ t('Uploading...') }} ({{ fm.uploadTasks.value.length }})</p>
            </div>
            <div class="max-h-60 overflow-y-auto divide-y divide-gray-100 dark:divide-gray-700">
                <div v-for="task in fm.uploadTasks.value" :key="task.id" class="flex items-center gap-3 px-4 py-3">
                    <svg class="h-4 w-4 shrink-0 text-brand-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12" />
                    </svg>
                    <div class="min-w-0 flex-1">
                        <p class="truncate text-xs text-gray-700 dark:text-gray-300">{{ task.filename }}</p>
                        <div class="mt-1 h-1.5 w-full overflow-hidden rounded-full bg-gray-200 dark:bg-gray-700">
                            <div
                                class="h-full rounded-full bg-brand-500 transition-[width] duration-150"
                                :style="{ width: task.percent + '%' }"
                            />
                        </div>
                    </div>
                    <div class="flex shrink-0 flex-col items-end gap-1">
                        <span class="text-xs font-semibold tabular-nums text-gray-600 dark:text-gray-400">{{ task.percent }}%</span>
                        <button
                            @click="fm.cancelUpload(task.id)"
                            class="text-xs text-red-500 hover:text-red-700 dark:hover:text-red-400"
                        >{{ t('Cancel') }}</button>
                    </div>
                </div>
            </div>
        </div>
    </Transition>
</template>

<script setup lang="ts">
import { computed, ref, onMounted, onBeforeUnmount, watch, nextTick } from 'vue';
import { Head } from '@inertiajs/vue3';
import ThemeProvider from '@/Components/Layout/ThemeProvider.vue';
import SidebarProvider from '@/Components/Layout/SidebarProvider.vue';
import AdminLayout from '@/Components/Layout/AdminLayout.vue';
import PageBreadcrumb from '@/Components/Common/PageBreadcrumb.vue';
import Toast from '@/Components/UI/Toast.vue';
import TreeView from '@/Components/FileManager/TreeView.vue';
import FileIcon from '@/Components/FileManager/FileIcon.vue';
import axios from 'axios';
import { useToast } from '@/Composables/useToast';
import { useFileManager, type FileItem } from '@/Composables/useFileManager';
import { useI18n } from '@/Composables/useI18n';

const props = defineProps<{
    domain: Record<string, any>;
    maxUploadBytes: number;
}>();
const { t } = useI18n();

const breadcrumbs = computed(() => [
    { label: t('Domains'), href: route('domains.index') },
    { label: props.domain.fqdn, href: route('domains.show', props.domain.id) },
    { label: t('File Manager') },
]);

const { addToast } = useToast();
const fm = useFileManager(
    { baseUrl: route('domains.files.index', props.domain.id), storageKey: `fm_state_${props.domain.id}` },
    props.maxUploadBytes,
);

const containerRef = ref<HTMLElement>();
const sidebarRef = ref<HTMLElement>();
const editorRef = ref<HTMLElement>();
const modalInputRef = ref<HTMLInputElement>();
const uploadInputRef = ref<HTMLInputElement>();
const contextMenuRef = ref<HTMLElement>();
const showDropZone = ref(false);
const mobileTab = ref<'tree' | 'editor'>('tree');
const isMobile = ref(typeof window !== 'undefined' && window.innerWidth < 640);
const mobileQuery = typeof window !== 'undefined' ? window.matchMedia('(max-width: 639px)') : null;
const onMobileChange = (e: MediaQueryListEvent) => {
    isMobile.value = e.matches;
    if (monacoEditor) {
        monacoEditor.updateOptions({
            fontSize: e.matches ? 12 : 14,
            minimap: { enabled: !e.matches },
        });
    }
};
mobileQuery?.addEventListener('change', onMobileChange);

let monacoEditor: any = null;
let monacoInstance: any = null;
const contextMenuStyle = computed(() => ({
    left: `${fm.contextMenu.x}px`,
    top: `${fm.contextMenu.y}px`,
}));

function adjustContextMenuPosition() {
    if (!fm.contextMenu.visible || !contextMenuRef.value) {
        return;
    }

    const margin = 8;
    const rect = contextMenuRef.value.getBoundingClientRect();
    const maxX = window.innerWidth - rect.width - margin;
    const maxY = window.innerHeight - rect.height - margin;

    fm.contextMenu.x = Math.max(margin, Math.min(fm.contextMenu.x, maxX));
    fm.contextMenu.y = Math.max(margin, Math.min(fm.contextMenu.y, maxY));
}

// ==================== Monaco Editor ====================

async function initMonaco() {
    if (monacoInstance) return;
    monacoInstance = await import('monaco-editor');
}

async function setupEditor() {
    await initMonaco();
    if (monacoEditor || !editorRef.value) return;

    monacoEditor = monacoInstance.editor.create(editorRef.value, {
        theme: 'vs-dark',
        fontSize: isMobile.value ? 12 : 14,
        minimap: { enabled: !isMobile.value },
        automaticLayout: true,
        scrollBeyondLastLine: false,
        wordWrap: isMobile.value ? 'on' : 'off',
        lineNumbers: 'on',
        renderWhitespace: 'selection',
        tabSize: 4,
        insertSpaces: true,
        formatOnPaste: true,
        bracketPairColorization: { enabled: true },
        padding: { top: 8 },
    });

    monacoEditor.addCommand(monacoInstance.KeyMod.CtrlCmd | monacoInstance.KeyCode.KeyS, () => {
        saveActiveFile();
    });
}

// Watch active tab changes to update editor model
watch(() => fm.activeTab.value, async (path) => {
    if (!path) return;
    await setupEditor();
    const tab = fm.openTabs.get(path);
    if (tab && monacoEditor && monacoInstance) {
        let model = monacoInstance.editor.getModels().find((m: any) => m.uri.path === '/' + path);
        if (!model) {
            model = monacoInstance.editor.createModel(
                tab.content,
                tab.language,
                monacoInstance.Uri.parse('file:///' + path)
            );
            model.onDidChangeContent(() => {
                if (tab.content !== model.getValue()) {
                    fm.markTabModified(path);
                }
            });
        }
        monacoEditor.setModel(model);
    }
});

// Focus modal input when shown
watch(() => fm.modal.visible, (visible) => {
    if (visible) {
        nextTick(() => {
            modalInputRef.value?.focus();
            modalInputRef.value?.select();
        });
    }
});

async function saveActiveFile() {
    if (!fm.activeTab.value || !monacoEditor) return;
    const tab = fm.openTabs.get(fm.activeTab.value);
    if (!tab || !tab.modified) return;

    try {
        const content = monacoEditor.getModel()?.getValue() || '';
        await fm.saveFile(fm.activeTab.value, content);
        addToast('success', t('File saved'));
    } catch (err: any) {
        addToast('error', `${t('Save error')}: ${err.response?.data?.message || err.message}`);
    }
}

// ==================== File Operations ====================

async function onOpenFile(path: string) {
    try {
        await fm.openFile(path);
        mobileTab.value = 'editor';
    } catch (err: any) {
        addToast('error', err.message || t('Error opening file'));
    }
}

function onDblClick(item: FileItem) {
    if (item.type === 'directory') {
        fm.loadDirectory(item.path);
    } else {
        onOpenFile(item.path);
    }
}

function onRowClick(item: FileItem, event: MouseEvent, index: number) {
    fm.selectItem(item.path, event, index);
    if (isMobile.value && !event.shiftKey && !event.ctrlKey && !event.metaKey) {
        if (item.type === 'file') {
            onOpenFile(item.path);
        } else {
            fm.loadDirectory(item.path);
        }
    }
}

function toggleCheckbox(path: string, index: number) {
    if (fm.selectedItems.has(path)) {
        fm.selectedItems.delete(path);
    } else {
        fm.selectedItems.add(path);
    }
}

function promptNewFile() {
    fm.showModal(t('New File'), t('Enter file name:'), '', async (name) => {
        if (!name) return;
        try {
            await fm.createFile(name);
            addToast('success', t('File created'));
        } catch (err: any) {
            addToast('error', err.response?.data?.message || err.message);
        }
    });
}

function promptNewFolder() {
    fm.showModal(t('New Folder'), t('Enter folder name:'), '', async (name) => {
        if (!name) return;
        try {
            await fm.createDirectory(name);
            addToast('success', t('Folder created'));
        } catch (err: any) {
            addToast('error', err.response?.data?.message || err.message);
        }
    });
}

function promptRename() {
    if (!fm.singleSelection.value) return;
    const selectedPath = fm.selectedPaths.value[0];
    const currentName = selectedPath.split('/').pop() || '';
    fm.showModal(t('Rename'), t('Enter new name:'), currentName, async (newName) => {
        if (!newName || newName === currentName) return;
        try {
            await fm.renameItem(selectedPath, newName);
            addToast('success', t('Renamed successfully'));
        } catch (err: any) {
            addToast('error', err.response?.data?.message || err.message);
        }
    });
}

async function confirmDelete() {
    const paths = fm.selectedPaths.value;
    if (paths.length === 0) return;
    const msg = paths.length === 1
        ? t('Delete ":path"?', { path: paths[0] })
        : t('Delete :count items?', { count: paths.length });
    if (!confirm(msg)) return;
    try {
        await fm.deleteSelected();
        addToast('success', t('Deleted successfully'));
    } catch (err: any) {
        addToast('error', err.response?.data?.message || err.message);
    }
}

function downloadSelected() {
    fm.downloadItems(fm.selectedPaths.value);
}

function promptCompress() {
    fm.showModal(t('Compress'), t('Enter archive name:'), 'archive.zip', async (name) => {
        if (!name) return;
        try {
            await fm.compressItems(fm.selectedPaths.value, name);
            addToast('success', t('Archive created'));
        } catch (err: any) {
            addToast('error', err.response?.data?.message || err.message);
        }
    });
}

async function extractSelected() {
    const zipPath = fm.selectedPaths.value.find(p => p.toLowerCase().endsWith('.zip'));
    if (!zipPath) return;
    try {
        await fm.extractZip(zipPath);
        addToast('success', t('Archive extracted'));
    } catch (err: any) {
        addToast('error', err.response?.data?.message || err.message);
    }
}

function triggerUpload() {
    uploadInputRef.value?.click();
}

function handleUpload(event: Event) {
    const input = event.target as HTMLInputElement;
    if (!input.files?.length) return;
    const count = input.files.length;
    fm.uploadFiles(input.files).then(() => {
        addToast('success', t(':count file(s) uploaded', { count }));
    }).catch((err: any) => {
        if (!axios.isCancel(err)) {
            addToast('error', err.response?.data?.message || err.message);
        }
    });
    input.value = '';
}

// ==================== Context Menu ====================

function onFileContextMenu(event: MouseEvent, item: FileItem) {
    if (!fm.selectedItems.has(item.path)) {
        fm.selectedItems.clear();
        fm.selectedItems.add(item.path);
    }
    fm.showContextMenu(event.clientX, event.clientY, item);
    nextTick(() => {
        adjustContextMenuPosition();
    });
}

function onTreeSelect(item: FileItem) {
    fm.selectedItems.clear();
    fm.selectedItems.add(item.path);
}

function onTreeContextMenu(event: { x: number; y: number; item: FileItem }) {
    fm.selectedItems.clear();
    fm.selectedItems.add(event.item.path);
    fm.showContextMenu(event.x, event.y, event.item);
    nextTick(() => {
        adjustContextMenuPosition();
    });
}

function ctxAction(action: string) {
    const item = fm.contextMenu.item;
    fm.hideContextMenu();
    switch (action) {
        case 'open': if (item) onOpenFile(item.path); break;
        case 'download': downloadSelected(); break;
        case 'open-dir': if (item) fm.loadDirectory(item.path); break;
        case 'new-file': promptNewFile(); break;
        case 'new-folder': promptNewFolder(); break;
        case 'upload': triggerUpload(); break;
        case 'rename': promptRename(); break;
        case 'chmod': promptChmod(); break;
        case 'delete': confirmDelete(); break;
        case 'compress': promptCompress(); break;
        case 'extract': extractSelected(); break;
    }
}

function promptChmod() {
    const selectedPath = fm.selectedPaths.value[0];
    if (!selectedPath) return;
    const fullItem = fm.fileList.value.find(f => f.path === selectedPath);
    const currentPerms = fullItem?.permissionsOctal || '0644';
    fm.showModal(t('Permissions'), t('Enter chmod (e.g. 0755):'), currentPerms, async (mode) => {
        if (!mode) return;
        mode = mode.replace(/^0*/, '');
        if (!/^[0-7]{3,4}$/.test(mode)) {
            addToast('error', t('Invalid permissions format'));
            return;
        }
        try {
            await fm.chmodItem(selectedPath, mode);
            addToast('success', t('Permissions changed'));
        } catch (err: any) {
            addToast('error', err.response?.data?.message || err.message);
        }
    });
}

// ==================== Drag & Drop ====================

let dragSource: { path: string; type: string } | null = null;

function onDragStart(event: DragEvent, item: FileItem) {
    dragSource = { path: item.path, type: item.type };
    event.dataTransfer!.effectAllowed = 'move';
    event.dataTransfer!.setData('text/plain', item.path);
}

function onDragEnd() {
    dragSource = null;
}

function onDragOver(event: DragEvent, item: FileItem) {
    if (!dragSource || item.type !== 'directory' || dragSource.path === item.path) return;
    event.dataTransfer!.dropEffect = 'move';
    (event.currentTarget as HTMLElement).classList.add('bg-brand-100', 'dark:bg-brand-500/20');
}

function onDragLeave(event: DragEvent) {
    (event.currentTarget as HTMLElement).classList.remove('bg-brand-100', 'dark:bg-brand-500/20');
}

async function onDrop(event: DragEvent, item: FileItem) {
    (event.currentTarget as HTMLElement).classList.remove('bg-brand-100', 'dark:bg-brand-500/20');
    if (!dragSource || item.type !== 'directory' || dragSource.path === item.path) return;
    try {
        await fm.moveItem(dragSource.path, item.path);
        addToast('success', t('Moved to :name', { name: item.name }));
    } catch (err: any) {
        addToast('error', err.message);
    }
}

async function onDropItem(event: { sourcePath: string; targetPath: string }) {
    try {
        await fm.moveItem(event.sourcePath, event.targetPath);
        addToast('success', t('Moved'));
    } catch (err: any) {
        addToast('error', err.message);
    }
}

// External file drop
function onExternalDragOver(event: DragEvent) {
    if (dragSource) return;
    showDropZone.value = true;
}

function onExternalDragLeave(event: DragEvent) {
    if (dragSource) return;
    if (!containerRef.value?.contains(event.relatedTarget as Node)) {
        showDropZone.value = false;
    }
}

function onExternalDrop(event: DragEvent) {
    if (dragSource) return;
    showDropZone.value = false;
    if (event.dataTransfer?.files.length) {
        const count = event.dataTransfer.files.length;
        fm.uploadFiles(event.dataTransfer.files).then(() => {
            addToast('success', t(':count file(s) uploaded', { count }));
        }).catch((err: any) => {
            if (!axios.isCancel(err)) {
                addToast('error', err.message);
            }
        });
    }
}

// ==================== Sidebar Resize ====================

function startResize(event: MouseEvent) {
    const startX = event.clientX;
    const startWidth = fm.sidebarWidth.value;

    const onMove = (e: MouseEvent) => {
        const newWidth = startWidth + (e.clientX - startX);
        if (newWidth >= 150 && newWidth <= 500) {
            fm.sidebarWidth.value = newWidth;
        }
    };
    const onUp = () => {
        document.removeEventListener('mousemove', onMove);
        document.removeEventListener('mouseup', onUp);
        fm.saveState();
    };
    document.addEventListener('mousemove', onMove);
    document.addEventListener('mouseup', onUp);
}

// ==================== Keyboard Shortcuts ====================

function onKeyDown(e: KeyboardEvent) {
    if ((e.ctrlKey || e.metaKey) && e.key === 's') {
        e.preventDefault();
        saveActiveFile();
    }
    if (e.key === 'Escape' && fm.isFullscreen.value) {
        fm.isFullscreen.value = false;
    }
    if (e.key === 'F11') {
        e.preventDefault();
        fm.isFullscreen.value = !fm.isFullscreen.value;
    }
}

// ==================== Lifecycle ====================

watch(() => fm.isFullscreen.value, (fullscreen) => {
    document.documentElement.style.overflow = fullscreen ? 'hidden' : '';
    document.body.style.overflow = fullscreen ? 'hidden' : '';
});

onMounted(async () => {
    document.addEventListener('keydown', onKeyDown);
    window.addEventListener('resize', adjustContextMenuPosition);
    try {
        await fm.restoreState();
    } catch (err: any) {
        addToast('error', `${t('Error loading file manager')}: ${err.message}`);
    }
});

onBeforeUnmount(() => {
    document.documentElement.style.overflow = '';
    document.body.style.overflow = '';
    document.removeEventListener('keydown', onKeyDown);
    window.removeEventListener('resize', adjustContextMenuPosition);
    mobileQuery?.removeEventListener('change', onMobileChange);
    if (monacoEditor) {
        monacoEditor.dispose();
        monacoEditor = null;
    }
    // Dispose all models
    if (monacoInstance) {
        monacoInstance.editor.getModels().forEach((m: any) => m.dispose());
    }
});

watch(() => fm.contextMenu.visible, (visible) => {
    if (!visible) {
        return;
    }

    nextTick(() => {
        adjustContextMenuPosition();
    });
});
</script>

<style scoped>
@reference "../../../css/app.css";

.fm-app {
    display: flex;
    flex-direction: column;
    height: calc(100vh - 180px);
    min-height: 500px;
    position: relative;
    overflow: hidden;
}

.fm-app.fm-fullscreen {
    position: fixed;
    inset: 0;
    z-index: 120000;
    width: 100vw;
    height: 100vh;
    border-radius: 0;
}

.fm-btn {
    @apply inline-flex items-center justify-center rounded-md p-1.5 text-gray-500 hover:bg-gray-100 hover:text-gray-700 disabled:opacity-30 disabled:cursor-not-allowed dark:text-gray-400 dark:hover:bg-gray-800 dark:hover:text-gray-200;
}

.ctx-item {
    @apply block w-full px-3 py-1.5 text-left text-sm text-gray-700 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-700;
}

.form-input {
    @apply h-10 w-full rounded-lg border border-gray-300 bg-transparent px-3 py-2 text-sm text-gray-800 shadow-theme-xs focus:border-brand-300 focus:outline-hidden focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90;
}

.fm-upload-toast-enter-active, .fm-upload-toast-leave-active {
    transition: opacity 0.2s ease, transform 0.2s ease;
}
.fm-upload-toast-enter-from, .fm-upload-toast-leave-to {
    opacity: 0;
    transform: translateY(8px);
}
</style>
