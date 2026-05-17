import { ref, reactive, computed } from 'vue';
import axios from 'axios';
import { useI18n } from '@/Composables/useI18n';
import { formatDateTime } from '@/utils/dateTime';

export interface FileItem {
    name: string;
    path: string;
    type: 'file' | 'directory';
    size: number | null;
    permissions: string;
    permissionsOctal: string | null;
    lastModified: number | null;
}

export interface EditorTab {
    path: string;
    content: string;
    language: string;
    modified: boolean;
}

export function useFileManager(options: { baseUrl: string; storageKey: string }, maxUploadBytes: number) {
    const { t } = useI18n();
    const currentPath = ref('');
    const fileList = ref<FileItem[]>([]);
    const selectedItems = reactive(new Set<string>());
    const lastClickedIndex = ref(-1);
    const loading = ref(false);
    const treeCache = reactive(new Map<string, FileItem[]>());
    const openTabs = reactive(new Map<string, EditorTab>());
    const activeTab = ref<string | null>(null);
    const storageKey = options.storageKey;

    // Context menu
    const contextMenu = reactive({
        visible: false,
        x: 0,
        y: 0,
        item: null as FileItem | null,
    });

    // Modal
    const modal = reactive({
        visible: false,
        title: '',
        label: '',
        value: '',
        callback: null as ((val: string) => void) | null,
    });

    // Status
    const statusText = ref(t('Ready'));
    const isFullscreen = ref(false);
    const sidebarWidth = ref(250);

    // Computed
    const hasSelection = computed(() => selectedItems.size > 0);
    const singleSelection = computed(() => selectedItems.size === 1);
    const selectedPaths = computed(() => Array.from(selectedItems));
    const hasZipSelected = computed(() => {
        if (selectedItems.size !== 1) return false;
        return Array.from(selectedItems)[0].toLowerCase().endsWith('.zip');
    });

    const breadcrumbs = computed(() => {
        const parts: { name: string; path: string }[] = [{ name: t('root'), path: '' }];
        if (currentPath.value) {
            const segments = currentPath.value.split('/');
            let accumulated = '';
            for (const seg of segments) {
                accumulated = accumulated ? accumulated + '/' + seg : seg;
                parts.push({ name: seg, path: accumulated });
            }
        }
        return parts;
    });

    // ==================== State Persistence ====================

    function saveState() {
        try {
            localStorage.setItem(storageKey, JSON.stringify({
                currentPath: currentPath.value,
                openTabs: Array.from(openTabs.keys()),
                activeTab: activeTab.value,
                sidebarWidth: sidebarWidth.value,
            }));
        } catch { /* storage unavailable */ }
    }

    function loadSavedState(): { currentPath: string; openTabs: string[]; activeTab: string | null; sidebarWidth: number | null } | null {
        try {
            const saved = localStorage.getItem(storageKey);
            return saved ? JSON.parse(saved) : null;
        } catch {
            return null;
        }
    }

    // ==================== API ====================

    function baseUrl() {
        return options.baseUrl.replace(/\/$/, '');
    }

    async function apiGet(endpoint: string, params: Record<string, string> = {}) {
        const res = await axios.get(`${baseUrl()}/${endpoint}`, { params });
        return res.data;
    }

    async function apiPost(endpoint: string, data: Record<string, any> = {}) {
        const res = await axios.post(`${baseUrl()}/${endpoint}`, data);
        return res.data;
    }

    async function apiPostFormData(endpoint: string, formData: FormData) {
        const res = await axios.post(`${baseUrl()}/${endpoint}`, formData, {
            headers: { 'Content-Type': 'multipart/form-data' },
        });
        return res.data;
    }

    // ==================== Directory ====================

    async function loadDirectory(path: string) {
        currentPath.value = path;
        loading.value = true;

        try {
            const data = await apiGet('list', { path });
            treeCache.set(path, data.items);
            if (!activeTab.value) {
                fileList.value = data.items;
            }
        } catch (err: any) {
            throw new Error(err.response?.data?.message || err.message || t('Error loading directory'));
        } finally {
            loading.value = false;
            saveState();
        }
    }

    async function toggleTreeDir(item: FileItem) {
        if (treeCache.has(item.path)) {
            treeCache.delete(item.path);
        } else {
            const data = await apiGet('list', { path: item.path });
            treeCache.set(item.path, data.items);
            currentPath.value = item.path;
            if (!activeTab.value) {
                fileList.value = data.items;
            }
        }
    }

    async function refresh() {
        const path = currentPath.value;
        treeCache.delete(path);
        const data = await apiGet('list', { path });
        treeCache.set(path, data.items);
        if (!activeTab.value) {
            fileList.value = data.items;
        }
    }

    async function restoreState() {
        const state = loadSavedState();
        if (state?.sidebarWidth) {
            sidebarWidth.value = state.sidebarWidth;
        }

        const targetPath = state?.currentPath || '';
        await loadDirectory('');

        if (targetPath) {
            const segments = targetPath.split('/');
            let accumulated = '';
            for (const segment of segments) {
                accumulated = accumulated ? accumulated + '/' + segment : segment;
                try {
                    const data = await apiGet('list', { path: accumulated });
                    treeCache.set(accumulated, data.items);
                } catch { break; }
            }
            currentPath.value = targetPath;
            const items = treeCache.get(targetPath);
            if (items) fileList.value = items;
        }

        // Restore open tabs
        if (state?.openTabs?.length) {
            for (const tabPath of state.openTabs) {
                try {
                    await openFile(tabPath);
                } catch { /* file may be gone */ }
            }
            if (state.activeTab && openTabs.has(state.activeTab)) {
                activateTab(state.activeTab);
            }
        }
    }

    // ==================== Selection ====================

    function selectItem(path: string, event?: { ctrlKey?: boolean; metaKey?: boolean; shiftKey?: boolean }, index?: number) {
        if (event?.shiftKey && lastClickedIndex.value >= 0 && index !== undefined) {
            const start = Math.min(lastClickedIndex.value, index);
            const end = Math.max(lastClickedIndex.value, index);
            if (!event.ctrlKey && !event.metaKey) {
                selectedItems.clear();
            }
            for (let i = start; i <= end; i++) {
                if (fileList.value[i]) selectedItems.add(fileList.value[i].path);
            }
        } else if (event?.ctrlKey || event?.metaKey) {
            if (selectedItems.has(path)) {
                selectedItems.delete(path);
            } else {
                selectedItems.add(path);
            }
        } else {
            selectedItems.clear();
            selectedItems.add(path);
        }
        if (index !== undefined) lastClickedIndex.value = index;
    }

    function selectAll() {
        fileList.value.forEach(i => selectedItems.add(i.path));
    }

    function deselectAll() {
        fileList.value.forEach(i => selectedItems.delete(i.path));
    }

    // ==================== File Operations ====================

    async function openFile(path: string): Promise<EditorTab | null> {
        if (openTabs.has(path)) {
            activateTab(path);
            return openTabs.get(path)!;
        }

        const data = await apiGet('read', { path });
        if (data.binary) {
            throw new Error(data.message || t('Binary file cannot be edited'));
        }

        const tab: EditorTab = {
            path,
            content: data.content,
            language: data.language,
            modified: false,
        };
        openTabs.set(path, tab);
        activateTab(path);
        return tab;
    }

    function activateTab(path: string) {
        activeTab.value = path;
        statusText.value = path + (openTabs.get(path)?.language ? ` | ${openTabs.get(path)!.language}` : '');
        saveState();
    }

    function closeTab(path: string): boolean {
        const tab = openTabs.get(path);
        if (!tab) return false;

        if (tab.modified) {
            if (!confirm(t('This file has unsaved changes. Close anyway?'))) {
                return false;
            }
        }

        openTabs.delete(path);

        if (activeTab.value === path) {
            const remaining = Array.from(openTabs.keys());
            if (remaining.length > 0) {
                activateTab(remaining[remaining.length - 1]);
            } else {
                activeTab.value = null;
                const items = treeCache.get(currentPath.value);
                if (items) fileList.value = items;
            }
        }
        saveState();
        return true;
    }

    function markTabModified(path: string) {
        const tab = openTabs.get(path);
        if (tab) tab.modified = true;
    }

    async function saveFile(path: string, content: string) {
        await apiPost('write', { path, content });
        const tab = openTabs.get(path);
        if (tab) {
            tab.content = content;
            tab.modified = false;
        }
    }

    async function createFile(name: string) {
        const path = currentPath.value ? currentPath.value + '/' + name : name;
        await apiPost('create-file', { path });
        await refresh();
        await openFile(path);
    }

    async function createDirectory(name: string) {
        const path = currentPath.value ? currentPath.value + '/' + name : name;
        await apiPost('create-directory', { path });
        await refresh();
    }

    async function renameItem(fromPath: string, newName: string) {
        const parentPath = getParentPath(fromPath);
        const newPath = parentPath ? parentPath + '/' + newName : newName;

        await apiPost('rename', { from: fromPath, to: newPath });

        // Update tabs
        if (openTabs.has(fromPath)) {
            const tab = openTabs.get(fromPath)!;
            openTabs.delete(fromPath);
            openTabs.set(newPath, tab);
            if (activeTab.value === fromPath) {
                activeTab.value = newPath;
            }
        }

        selectedItems.delete(fromPath);
        selectedItems.add(newPath);
        await refresh();
    }

    async function deleteSelected() {
        const paths = Array.from(selectedItems);
        if (paths.length === 0) return;

        await apiPost('delete', { paths });

        paths.forEach(p => {
            openTabs.delete(p);
            treeCache.delete(p);
        });

        if (activeTab.value && !openTabs.has(activeTab.value)) {
            const remaining = Array.from(openTabs.keys());
            activeTab.value = remaining.length > 0 ? remaining[remaining.length - 1] : null;
        }

        selectedItems.clear();
        await refresh();
    }

    async function chmodItem(path: string, mode: string) {
        await apiPost('chmod', { path, mode });
        await refresh();
    }

    function downloadItems(paths: string[]) {
        paths.forEach(p => {
            const url = `${baseUrl()}/download?path=${encodeURIComponent(p)}`;
            window.open(url, '_blank');
        });
    }

    async function uploadFiles(files: FileList | File[]) {
        const maxMb = (maxUploadBytes / (1024 * 1024)).toFixed(1);
        const tooLarge = Array.from(files).filter(f => f.size > maxUploadBytes);
        if (tooLarge.length > 0) {
            throw new Error(t('File too large (max :maxMbMB): :files', {
                maxMb,
                files: tooLarge.map(f => f.name).join(', '),
            }));
        }

        const formData = new FormData();
        formData.append('directory', currentPath.value);
        Array.from(files).forEach(file => formData.append('files[]', file));

        await apiPostFormData('upload', formData);
        await refresh();
    }

    async function compressItems(paths: string[], name: string) {
        statusText.value = t('Compressing...');
        try {
            await apiPost('compress', { paths, name, directory: currentPath.value });
            await refresh();
        } finally {
            statusText.value = t('Ready');
        }
    }

    async function extractZip(zipPath: string) {
        statusText.value = t('Extracting...');
        try {
            await apiPost('decompress', { path: zipPath, directory: currentPath.value });
            await refresh();
        } finally {
            statusText.value = t('Ready');
        }
    }

    async function moveItem(sourcePath: string, targetDirPath: string) {
        const fileName = sourcePath.split('/').pop();
        const newPath = targetDirPath ? targetDirPath + '/' + fileName : fileName!;

        if (sourcePath === newPath) return;
        if (newPath.startsWith(sourcePath + '/')) {
            throw new Error(t('Cannot move a folder into itself'));
        }

        await apiPost('rename', { from: sourcePath, to: newPath });

        if (openTabs.has(sourcePath)) {
            const tab = openTabs.get(sourcePath)!;
            openTabs.delete(sourcePath);
            openTabs.set(newPath, tab);
            if (activeTab.value === sourcePath) {
                activeTab.value = newPath;
            }
        }

        treeCache.delete(getParentPath(sourcePath));
        treeCache.delete(targetDirPath);
        await refresh();
    }

    // ==================== Context Menu ====================

    function showContextMenu(x: number, y: number, item: FileItem | null) {
        contextMenu.visible = true;
        contextMenu.x = x;
        contextMenu.y = y;
        contextMenu.item = item;
    }

    function hideContextMenu() {
        contextMenu.visible = false;
    }

    // ==================== Modal ====================

    function showModal(title: string, label: string, defaultValue: string, callback: (val: string) => void) {
        modal.visible = true;
        modal.title = title;
        modal.label = label;
        modal.value = defaultValue;
        modal.callback = callback;
    }

    function confirmModal() {
        if (modal.callback) {
            modal.callback(modal.value.trim());
        }
        modal.visible = false;
    }

    function cancelModal() {
        modal.visible = false;
    }

    // ==================== Helpers ====================

    function getParentPath(path: string): string {
        const parts = path.split('/');
        parts.pop();
        return parts.join('/');
    }

    function formatSize(bytes: number | null): string {
        if (bytes === null || bytes === undefined) return '';
        if (bytes === 0) return '0 B';
        const units = ['B', 'KB', 'MB', 'GB'];
        const i = Math.floor(Math.log(bytes) / Math.log(1024));
        return (bytes / Math.pow(1024, i)).toFixed(i > 0 ? 1 : 0) + ' ' + units[i];
    }

    function formatDate(timestamp: number | null): string {
        if (timestamp === null) {
            return '';
        }

        const formatted = formatDateTime(new Date(timestamp * 1000));

        return formatted === '-' ? '' : formatted;
    }

    function getFileIcon(item: FileItem): string {
        if (item.type === 'directory') return 'folder';
        const ext = item.name.split('.').pop()?.toLowerCase() || '';
        const imageExts = ['jpg', 'jpeg', 'png', 'gif', 'svg', 'webp', 'ico'];
        const codeExts = ['js', 'ts', 'vue', 'jsx', 'tsx', 'php', 'py', 'rb', 'java', 'go', 'rs', 'c', 'cpp', 'h', 'css', 'scss', 'html', 'xml', 'json', 'yaml', 'yml', 'toml', 'sql', 'sh', 'bash'];
        const archiveExts = ['zip', 'tar', 'gz', 'bz2', 'rar', '7z'];
        if (imageExts.includes(ext)) return 'image';
        if (codeExts.includes(ext)) return 'code';
        if (archiveExts.includes(ext)) return 'archive';
        return 'file';
    }

    return {
        // State
        currentPath,
        fileList,
        selectedItems,
        loading,
        treeCache,
        openTabs,
        activeTab,
        contextMenu,
        modal,
        statusText,
        isFullscreen,
        sidebarWidth,
        breadcrumbs,
        // Computed
        hasSelection,
        singleSelection,
        selectedPaths,
        hasZipSelected,
        // Actions
        loadDirectory,
        toggleTreeDir,
        refresh,
        restoreState,
        selectItem,
        selectAll,
        deselectAll,
        openFile,
        activateTab,
        closeTab,
        markTabModified,
        saveFile,
        createFile,
        createDirectory,
        renameItem,
        deleteSelected,
        chmodItem,
        downloadItems,
        uploadFiles,
        compressItems,
        extractZip,
        moveItem,
        showContextMenu,
        hideContextMenu,
        showModal,
        confirmModal,
        cancelModal,
        saveState,
        // Helpers
        getParentPath,
        formatSize,
        formatDate,
        getFileIcon,
    };
}
