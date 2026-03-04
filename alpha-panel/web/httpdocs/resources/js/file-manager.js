import * as monaco from 'monaco-editor';
import { t } from './utils/i18n';
import { formatDateTime } from './utils/dateTime';

/**
 * AlphaPanel File Manager
 */
class FileManager {
    constructor(config) {
        this.domainId = config.domainId;
        this.baseUrl = config.baseUrl;
        this.csrfToken = config.csrfToken;
        this.maxUploadBytes = parseInt(config.maxUploadBytes, 10) || (2 * 1024 * 1024);
        this.storageKey = `fm_state_${this.domainId}`;

        this.currentPath = '';
        this.selectedItems = new Set();
        this.lastClickedIndex = -1;
        this.currentFileList = []; // ordered items for shift-select range
        this.editor = null;
        this.openTabs = new Map();
        this.activeTab = null;
        this.treeCache = new Map();

        // Drag state
        this.dragSource = null; // { path, type, name }
        this.dragGhost = null;

        this.init();
    }

    init() {
        this.bindElements();
        this.bindEvents();
        this.initResizer();
        this.restoreState();
    }

    // ==================== State Persistence ====================

    saveState() {
        const state = {
            currentPath: this.currentPath,
            openTabs: Array.from(this.openTabs.keys()),
            activeTab: this.activeTab,
            sidebarWidth: this.sidebarEl?.style.width || null,
        };

        try {
            localStorage.setItem(this.storageKey, JSON.stringify(state));
        } catch {
            // Storage full or unavailable
        }
    }

    async restoreState() {
        let state = null;

        try {
            const saved = localStorage.getItem(this.storageKey);
            if (saved) {
                state = JSON.parse(saved);
            }
        } catch {
            // Invalid or unavailable
        }

        // Restore sidebar width
        if (state?.sidebarWidth && this.sidebarEl) {
            this.sidebarEl.style.width = state.sidebarWidth;
        }

        // Load root first, then expand ancestor directories in the tree
        const targetPath = state?.currentPath || '';
        await this.loadDirectory('');

        if (targetPath) {
            const segments = targetPath.split('/');
            let accumulated = '';

            for (const segment of segments) {
                accumulated = accumulated ? accumulated + '/' + segment : segment;

                try {
                    const data = await this.apiGet('list', { path: accumulated });
                    this.treeCache.set(accumulated, data.items);
                } catch {
                    break;
                }
            }

            this.currentPath = targetPath;
            this.updateBreadcrumb(targetPath);
            this.renderTree();

            const items = this.treeCache.get(targetPath);
            if (items) {
                this.renderFileList(items, targetPath);
            }
        }

        // Restore open tabs and active tab
        if (state?.openTabs?.length > 0) {
            for (const tabPath of state.openTabs) {
                try {
                    await this.openFile(tabPath);
                } catch {
                    // File may no longer exist
                }
            }

            // Activate the previously active tab
            if (state.activeTab && this.openTabs.has(state.activeTab)) {
                this.activateTab(state.activeTab);
            }
        }
    }

    bindElements() {
        this.sidebarEl = document.getElementById('fm-sidebar');
        this.treeEl = document.getElementById('fm-tree');
        this.mainEl = document.getElementById('fm-main');
        this.tabBarEl = document.getElementById('fm-tab-bar');
        this.editorContainer = document.getElementById('monaco-editor');
        this.fileListEl = document.getElementById('fm-file-list');
        this.welcomeEl = document.getElementById('fm-welcome');
        this.breadcrumbEl = document.getElementById('fm-breadcrumb');
        this.statusBarEl = document.getElementById('fm-statusbar-info');
        this.contextMenuEl = document.getElementById('fm-context-menu');
        this.dropZoneEl = document.getElementById('fm-drop-zone');
    }

    bindEvents() {
        // Toolbar buttons
        document.getElementById('btn-new-file')?.addEventListener('click', () => this.promptNewFile());
        document.getElementById('btn-new-folder')?.addEventListener('click', () => this.promptNewFolder());
        document.getElementById('btn-upload')?.addEventListener('click', () => this.triggerUpload());
        document.getElementById('btn-rename')?.addEventListener('click', () => this.promptRename());
        document.getElementById('btn-delete')?.addEventListener('click', () => this.deleteSelected());
        document.getElementById('btn-download')?.addEventListener('click', () => this.downloadSelected());
        document.getElementById('btn-compress')?.addEventListener('click', () => this.compressSelected());
        document.getElementById('btn-extract')?.addEventListener('click', () => this.extractSelected());
        document.getElementById('btn-refresh')?.addEventListener('click', () => this.refresh());
        document.getElementById('btn-fullscreen')?.addEventListener('click', () => this.toggleFullscreen());

        // Hidden file input
        document.getElementById('fm-upload-input')?.addEventListener('change', (e) => this.handleUpload(e));

        // Keyboard shortcuts
        document.addEventListener('keydown', (e) => {
            if ((e.ctrlKey || e.metaKey) && e.key === 's') {
                e.preventDefault();
                this.saveActiveFile();
            }
            if (e.key === 'Escape' && document.getElementById('fm-app')?.classList.contains('fm-fullscreen')) {
                this.toggleFullscreen();
            }
            if (e.key === 'F11') {
                e.preventDefault();
                this.toggleFullscreen();
            }
        });

        // Close context menu on click
        document.addEventListener('click', () => this.hideContextMenu());

        // External file upload via drag (from OS)
        const container = document.querySelector('.fm-container');
        if (container) {
            container.addEventListener('dragover', (e) => {
                // Only show upload drop zone for external files (not internal moves)
                if (this.dragSource) return;
                e.preventDefault();
                this.dropZoneEl?.classList.add('active');
            });
            container.addEventListener('dragleave', (e) => {
                if (this.dragSource) return;
                if (!container.contains(e.relatedTarget)) {
                    this.dropZoneEl?.classList.remove('active');
                }
            });
            container.addEventListener('drop', (e) => {
                if (this.dragSource) return;
                e.preventDefault();
                this.dropZoneEl?.classList.remove('active');
                if (e.dataTransfer.files.length > 0) {
                    this.uploadFiles(e.dataTransfer.files);
                }
            });
        }
    }

    // ==================== API Calls ====================

    async apiGet(endpoint, params = {}) {
        const url = new URL(this.baseUrl + '/' + endpoint, window.location.origin);
        Object.entries(params).forEach(([k, v]) => url.searchParams.set(k, v));

        const response = await fetch(url, {
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        });

        if (!response.ok) {
            const errorData = await response.json().catch(() => ({}));
            throw new Error(errorData.message || `HTTP ${response.status}`);
        }

        return response.json();
    }

    async apiPost(endpoint, data = {}) {
        const response = await fetch(this.baseUrl + '/' + endpoint, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': this.csrfToken,
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify(data),
        });

        if (!response.ok) {
            const errorData = await response.json().catch(() => ({}));
            throw new Error(errorData.message || `HTTP ${response.status}`);
        }

        return response.json();
    }

    async apiPostFormData(endpoint, formData) {
        formData.append('_token', this.csrfToken);

        const response = await fetch(this.baseUrl + '/' + endpoint, {
            method: 'POST',
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: formData,
        });

        if (!response.ok) {
            const errorData = await response.json().catch(() => ({}));
            throw new Error(errorData.message || `HTTP ${response.status}`);
        }

        return response.json();
    }

    // ==================== Move (Drag & Drop) ====================

    async moveItem(sourcePath, targetDirPath) {
        const fileName = sourcePath.split('/').pop();
        const newPath = targetDirPath ? targetDirPath + '/' + fileName : fileName;

        if (sourcePath === newPath) return;

        // Don't allow moving into itself
        if (newPath.startsWith(sourcePath + '/')) {
            this.showToast(t('Cannot move a folder into itself'), 'error');
            return;
        }

        try {
            await this.apiPost('rename', { from: sourcePath, to: newPath });

            // Update open tab paths
            if (this.openTabs.has(sourcePath)) {
                const tab = this.openTabs.get(sourcePath);
                this.openTabs.delete(sourcePath);
                this.openTabs.set(newPath, tab);
                if (this.activeTab === sourcePath) {
                    this.activeTab = newPath;
                }
                this.renderTabs();
            }

            this.showToast(t('Moved to :name', { name: targetDirPath || t('root') }), 'success');
            this.invalidateTreeCache(this.getParentPath(sourcePath));
            this.invalidateTreeCache(targetDirPath);
            this.refresh();
        } catch (err) {
            this.showToast(t('Move error: :error', { error: err.message }), 'error');
        }
    }

    invalidateTreeCache(path) {
        this.treeCache.delete(path);
    }

    // ==================== Directory Listing ====================

    async loadDirectory(path) {
        this.currentPath = path;
        this.updateBreadcrumb(path);
        this.saveState();

        try {
            const data = await this.apiGet('list', { path });
            this.treeCache.set(path, data.items);
            this.renderTree();
            this.renderFileList(data.items, path);
        } catch (err) {
            this.showToast(t('Error loading directory: :error', { error: err.message }), 'error');
        }
    }

    // ==================== Tree Rendering ====================

    renderTree() {
        if (!this.treeEl) return;

        const rootItems = this.treeCache.get('') || [];
        this.treeEl.innerHTML = '';
        this.renderTreeLevel(this.treeEl, rootItems, '');
    }

    renderTreeLevel(container, items, parentPath) {
        items.forEach(item => {
            const itemEl = document.createElement('div');
            itemEl.dataset.treePath = item.path;
            itemEl.dataset.treeType = item.type;

            const row = document.createElement('div');
            row.className = 'fm-tree-item';
            if (this.selectedItems.has(item.path)) {
                row.classList.add('selected');
            }
            row.style.paddingLeft = (this.getDepth(item.path) * 16 + 8) + 'px';

            if (item.type === 'directory') {
                const toggle = document.createElement('span');
                toggle.className = 'fm-tree-toggle';
                const isOpen = this.treeCache.has(item.path);
                if (isOpen) toggle.classList.add('open');
                toggle.innerHTML = '<i class="fa-solid fa-chevron-right"></i>';
                row.appendChild(toggle);

                const icon = document.createElement('span');
                icon.className = 'fm-tree-icon folder';
                icon.innerHTML = isOpen
                    ? '<i class="fa-solid fa-folder-open"></i>'
                    : '<i class="fa-solid fa-folder"></i>';
                row.appendChild(icon);
            } else {
                const spacer = document.createElement('span');
                spacer.style.width = '16px';
                spacer.style.display = 'inline-block';
                row.appendChild(spacer);

                const icon = document.createElement('span');
                icon.className = 'fm-tree-icon file';
                icon.innerHTML = '<i class="fa-solid fa-file"></i>';
                row.appendChild(icon);
            }

            const name = document.createElement('span');
            name.textContent = item.name;
            row.appendChild(name);

            // Single click = select (for files), expand/collapse (for dirs)
            row.addEventListener('click', (e) => {
                e.stopPropagation();
                if (e.ctrlKey || e.metaKey) {
                    if (this.selectedItems.has(item.path)) {
                        this.selectedItems.delete(item.path);
                    } else {
                        this.selectedItems.add(item.path);
                    }
                } else {
                    this.selectedItems.clear();
                    this.selectedItems.add(item.path);
                }
                if (item.type === 'directory') {
                    this.onTreeDirClick(item);
                }
                this.renderTree();
                this.updateToolbarState();
            });

            // Double click = open file in editor / navigate into dir
            row.addEventListener('dblclick', (e) => {
                e.stopPropagation();
                if (item.type === 'file') {
                    this.openFile(item.path);
                } else {
                    this.currentPath = item.path;
                    this.updateBreadcrumb(item.path);
                    if (!this.activeTab) {
                        const cached = this.treeCache.get(item.path);
                        if (cached) this.renderFileList(cached, item.path);
                    }
                }
            });

            row.addEventListener('contextmenu', (e) => {
                e.preventDefault();
                e.stopPropagation();
                if (!this.selectedItems.has(item.path)) {
                    this.selectedItems.clear();
                    this.selectedItems.add(item.path);
                }
                this.renderTree();
                this.showContextMenu(e.clientX, e.clientY, item);
            });

            // --- Drag source ---
            row.draggable = true;
            row.addEventListener('dragstart', (e) => {
                this.dragSource = { path: item.path, type: item.type, name: item.name };
                e.dataTransfer.effectAllowed = 'move';
                e.dataTransfer.setData('text/plain', item.path);
                row.classList.add('fm-dragging');

                // Custom ghost
                this.dragGhost = document.createElement('div');
                this.dragGhost.className = 'fm-drag-ghost';
                this.dragGhost.textContent = item.name;
                document.body.appendChild(this.dragGhost);
                e.dataTransfer.setDragImage(this.dragGhost, 10, 10);
            });

            row.addEventListener('dragend', () => {
                this.dragSource = null;
                row.classList.remove('fm-dragging');
                if (this.dragGhost) {
                    this.dragGhost.remove();
                    this.dragGhost = null;
                }
                // Clean up all drop targets
                document.querySelectorAll('.fm-drop-target').forEach(el => el.classList.remove('fm-drop-target'));
            });

            // --- Drop target (directories only) ---
            if (item.type === 'directory') {
                row.addEventListener('dragover', (e) => {
                    if (!this.dragSource) return;
                    if (this.dragSource.path === item.path) return;
                    e.preventDefault();
                    e.dataTransfer.dropEffect = 'move';
                    row.classList.add('fm-drop-target');
                });

                row.addEventListener('dragleave', () => {
                    row.classList.remove('fm-drop-target');
                });

                row.addEventListener('drop', (e) => {
                    e.preventDefault();
                    e.stopPropagation();
                    row.classList.remove('fm-drop-target');
                    if (this.dragSource && this.dragSource.path !== item.path) {
                        this.moveItem(this.dragSource.path, item.path);
                    }
                });
            }

            itemEl.appendChild(row);

            // Children
            if (item.type === 'directory' && this.treeCache.has(item.path)) {
                const children = document.createElement('div');
                children.className = 'fm-tree-children';
                this.renderTreeLevel(children, this.treeCache.get(item.path), item.path);
                itemEl.appendChild(children);
            }

            container.appendChild(itemEl);
        });
    }

    getDepth(path) {
        if (!path) return 0;
        return path.split('/').length;
    }

    async onTreeDirClick(item) {
        if (this.treeCache.has(item.path)) {
            this.treeCache.delete(item.path);
        } else {
            try {
                const data = await this.apiGet('list', { path: item.path });
                this.treeCache.set(item.path, data.items);
                this.currentPath = item.path;
                this.updateBreadcrumb(item.path);
                if (!this.activeTab) {
                    this.renderFileList(data.items, item.path);
                }
            } catch (err) {
                this.showToast(t('Error: :error', { error: err.message }), 'error');
            }
        }
        this.renderTree();
    }

    // ==================== File List ====================

    renderFileList(items, path) {
        if (!this.fileListEl) return;
        if (this.activeTab) return;

        this.showFileList();
        this.currentFileList = items;

        const allChecked = items.length > 0 && items.every(i => this.selectedItems.has(i.path));

        let html = `<table>
            <thead><tr>
                <th style="width:32px"><input type="checkbox" class="fm-select-all" ${allChecked ? 'checked' : ''}></th>
                <th>${t('Name')}</th>
                <th style="width:80px">${t('Permissions')}</th>
                <th style="width:100px">${t('Size')}</th>
                <th style="width:160px">${t('Modified')}</th>
            </tr></thead>
            <tbody>`;

        if (path) {
            html += `<tr class="fm-file-row" data-path="${this.getParentPath(path)}" data-type="directory" data-action="up">
                <td></td>
                <td><span class="fm-file-name"><i class="fa-solid fa-arrow-up" style="color:rgba(255,255,255,0.3)"></i> ..</span></td>
                <td></td><td></td><td></td>
            </tr>`;
        }

        items.forEach((item, index) => {
            const iconClass = item.type === 'directory' ? 'fa-solid fa-folder folder' : 'fa-solid fa-file file';
            const perms = item.permissions ? item.permissions : '';
            const size = item.size !== null && item.size !== undefined ? this.formatSize(item.size) : '';
            const date = item.lastModified ? this.formatDate(item.lastModified) : '';
            const checked = this.selectedItems.has(item.path);

            html += `<tr class="fm-file-row${checked ? ' selected' : ''}"
                         draggable="true"
                         data-path="${this.escapeHtml(item.path)}"
                         data-type="${item.type}"
                         data-name="${this.escapeHtml(item.name)}"
                         data-index="${index}">
                <td class="fm-checkbox-cell"><input type="checkbox" class="fm-row-checkbox" ${checked ? 'checked' : ''}></td>
                <td><span class="fm-file-name"><i class="${iconClass}"></i> ${this.escapeHtml(item.name)}</span></td>
                <td class="fm-file-perms">${perms}</td>
                <td class="fm-file-size">${size}</td>
                <td class="fm-file-date">${date}</td>
            </tr>`;
        });

        html += '</tbody></table>';
        this.fileListEl.innerHTML = html;

        // Select all checkbox
        this.fileListEl.querySelector('.fm-select-all')?.addEventListener('change', (e) => {
            if (e.target.checked) {
                items.forEach(i => this.selectedItems.add(i.path));
            } else {
                items.forEach(i => this.selectedItems.delete(i.path));
            }
            this.renderFileList(items, path);
            this.updateToolbarState();
        });

        // Bind events on rows
        this.fileListEl.querySelectorAll('.fm-file-row').forEach(row => {
            const rowPath = row.dataset.path;
            const rowType = row.dataset.type;
            const rowName = row.dataset.name;
            const rowIndex = parseInt(row.dataset.index, 10);

            // Checkbox click
            const checkbox = row.querySelector('.fm-row-checkbox');
            if (checkbox) {
                checkbox.addEventListener('click', (e) => {
                    e.stopPropagation();
                    if (checkbox.checked) {
                        this.selectedItems.add(rowPath);
                    } else {
                        this.selectedItems.delete(rowPath);
                    }
                    row.classList.toggle('selected', checkbox.checked);
                    this.lastClickedIndex = rowIndex;
                    this.updateToolbarState();
                    this.updateSelectAllCheckbox(items);
                });
            }

            // Single click = select
            row.addEventListener('click', (e) => {
                if (e.target.classList.contains('fm-row-checkbox')) return;
                if (row.dataset.action === 'up') return;

                if (e.shiftKey && this.lastClickedIndex >= 0 && !isNaN(rowIndex)) {
                    // Shift+click: range select
                    const start = Math.min(this.lastClickedIndex, rowIndex);
                    const end = Math.max(this.lastClickedIndex, rowIndex);
                    if (!e.ctrlKey && !e.metaKey) {
                        this.selectedItems.clear();
                    }
                    for (let i = start; i <= end; i++) {
                        if (items[i]) this.selectedItems.add(items[i].path);
                    }
                } else if (e.ctrlKey || e.metaKey) {
                    // Ctrl+click: toggle
                    if (this.selectedItems.has(rowPath)) {
                        this.selectedItems.delete(rowPath);
                    } else {
                        this.selectedItems.add(rowPath);
                    }
                } else {
                    // Normal click: single select
                    this.selectedItems.clear();
                    this.selectedItems.add(rowPath);
                }

                this.lastClickedIndex = rowIndex;
                this.renderFileList(items, path);
                this.updateToolbarState();
            });

            // Double click = open
            row.addEventListener('dblclick', () => {
                if (rowType === 'directory') {
                    this.loadDirectory(rowPath);
                } else {
                    this.openFile(rowPath);
                }
            });

            // Context menu
            row.addEventListener('contextmenu', (e) => {
                e.preventDefault();
                if (!this.selectedItems.has(rowPath)) {
                    this.selectedItems.clear();
                    this.selectedItems.add(rowPath);
                    this.renderFileList(items, path);
                    this.updateToolbarState();
                }
                this.showContextMenu(e.clientX, e.clientY, {
                    path: rowPath,
                    type: rowType,
                    name: rowName,
                });
            });

            // --- Drag source ---
            row.addEventListener('dragstart', (e) => {
                if (row.dataset.action === 'up') { e.preventDefault(); return; }
                this.dragSource = { path: rowPath, type: rowType, name: rowName };
                e.dataTransfer.effectAllowed = 'move';
                e.dataTransfer.setData('text/plain', rowPath);
                row.classList.add('fm-dragging');

                const count = this.selectedItems.size;
                this.dragGhost = document.createElement('div');
                this.dragGhost.className = 'fm-drag-ghost';
                this.dragGhost.textContent = count > 1 ? t(':count items', { count }) : rowName;
                document.body.appendChild(this.dragGhost);
                e.dataTransfer.setDragImage(this.dragGhost, 10, 10);
            });

            row.addEventListener('dragend', () => {
                this.dragSource = null;
                row.classList.remove('fm-dragging');
                if (this.dragGhost) {
                    this.dragGhost.remove();
                    this.dragGhost = null;
                }
                document.querySelectorAll('.fm-drop-target').forEach(el => el.classList.remove('fm-drop-target'));
            });

            // --- Drop target (directories only in file list) ---
            if (rowType === 'directory') {
                row.addEventListener('dragover', (e) => {
                    if (!this.dragSource) return;
                    if (this.dragSource.path === rowPath) return;
                    e.preventDefault();
                    e.dataTransfer.dropEffect = 'move';
                    row.classList.add('fm-drop-target');
                });

                row.addEventListener('dragleave', () => {
                    row.classList.remove('fm-drop-target');
                });

                row.addEventListener('drop', (e) => {
                    e.preventDefault();
                    e.stopPropagation();
                    row.classList.remove('fm-drop-target');
                    if (this.dragSource && this.dragSource.path !== rowPath) {
                        this.moveItem(this.dragSource.path, rowPath);
                    }
                });
            }
        });
    }

    updateSelectAllCheckbox(items) {
        const selectAll = this.fileListEl?.querySelector('.fm-select-all');
        if (selectAll) {
            selectAll.checked = items.length > 0 && items.every(i => this.selectedItems.has(i.path));
        }
    }

    // ==================== File Opening / Editor ====================

    async openFile(path) {
        if (this.openTabs.has(path)) {
            this.activateTab(path);
            return;
        }

        try {
            const data = await this.apiGet('read', { path });

            if (data.binary) {
                this.showToast(data.message, 'error');
                return;
            }

            const model = monaco.editor.createModel(data.content, data.language);

            this.openTabs.set(path, {
                content: data.content,
                language: data.language,
                model: model,
                modified: false,
            });

            model.onDidChangeContent(() => {
                const tab = this.openTabs.get(path);
                if (tab && tab.content !== model.getValue()) {
                    tab.modified = true;
                    this.renderTabs();
                }
            });

            this.activateTab(path);
        } catch (err) {
            this.showToast(t('Error opening file: :error', { error: err.message }), 'error');
        }
    }

    activateTab(path) {
        this.activeTab = path;
        this.showEditor();

        if (!this.editor) {
            this.initEditor();
        }

        const tab = this.openTabs.get(path);
        if (tab) {
            this.editor.setModel(tab.model);
        }

        this.renderTabs();
        this.updateStatusBar(path);
        this.saveState();
    }

    initEditor() {
        this.editor = monaco.editor.create(this.editorContainer, {
            theme: 'vs-dark',
            fontSize: 14,
            minimap: { enabled: true },
            automaticLayout: true,
            scrollBeyondLastLine: false,
            wordWrap: 'off',
            lineNumbers: 'on',
            renderWhitespace: 'selection',
            tabSize: 4,
            insertSpaces: true,
            formatOnPaste: true,
            bracketPairColorization: { enabled: true },
            padding: { top: 8 },
        });

        this.editor.addCommand(monaco.KeyMod.CtrlCmd | monaco.KeyCode.KeyS, () => {
            this.saveActiveFile();
        });
    }

    showEditor() {
        if (this.fileListEl) this.fileListEl.style.display = 'none';
        if (this.welcomeEl) this.welcomeEl.style.display = 'none';
        if (this.editorContainer) this.editorContainer.style.display = 'block';
        if (this.tabBarEl) this.tabBarEl.style.display = 'flex';
    }

    showFileList() {
        if (this.fileListEl) this.fileListEl.style.display = 'block';
        if (this.welcomeEl) this.welcomeEl.style.display = 'none';
        if (this.editorContainer) this.editorContainer.style.display = 'none';
        if (this.tabBarEl) this.tabBarEl.style.display = 'none';
    }

    showWelcome() {
        if (this.fileListEl) this.fileListEl.style.display = 'none';
        if (this.welcomeEl) this.welcomeEl.style.display = 'flex';
        if (this.editorContainer) this.editorContainer.style.display = 'none';
        if (this.tabBarEl) this.tabBarEl.style.display = 'none';
    }

    // ==================== Tabs ====================

    renderTabs() {
        if (!this.tabBarEl) return;

        let html = '';
        this.openTabs.forEach((tab, path) => {
            const name = path.split('/').pop();
            const isActive = this.activeTab === path;
            const modClass = tab.modified ? ' modified' : '';
            html += `<div class="fm-tab${isActive ? ' active' : ''}${modClass}" data-path="${this.escapeHtml(path)}">
                <span class="fm-tab-name">${this.escapeHtml(name)}</span>
                <span class="fm-tab-close" data-close="${this.escapeHtml(path)}">&times;</span>
            </div>`;
        });

        this.tabBarEl.innerHTML = html;

        this.tabBarEl.querySelectorAll('.fm-tab').forEach(tabEl => {
            tabEl.addEventListener('click', (e) => {
                if (e.target.classList.contains('fm-tab-close')) return;
                this.activateTab(tabEl.dataset.path);
            });
        });

        this.tabBarEl.querySelectorAll('.fm-tab-close').forEach(btn => {
            btn.addEventListener('click', (e) => {
                e.stopPropagation();
                this.closeTab(btn.dataset.close);
            });
        });
    }

    closeTab(path) {
        const tab = this.openTabs.get(path);
        if (!tab) return;

        if (tab.modified) {
            if (!confirm(t('This file has unsaved changes. Close anyway?'))) {
                return;
            }
        }

        tab.model.dispose();
        this.openTabs.delete(path);

        if (this.activeTab === path) {
            const remaining = Array.from(this.openTabs.keys());
            if (remaining.length > 0) {
                this.activateTab(remaining[remaining.length - 1]);
            } else {
                this.activeTab = null;
                this.showFileList();
                const items = this.treeCache.get(this.currentPath);
                if (items) {
                    this.renderFileList(items, this.currentPath);
                } else {
                    this.showWelcome();
                }
            }
        }

        this.renderTabs();
        this.saveState();
    }

    // ==================== Save ====================

    async saveActiveFile() {
        if (!this.activeTab) return;

        const tab = this.openTabs.get(this.activeTab);
        if (!tab || !tab.modified) return;

        try {
            const content = tab.model.getValue();
            await this.apiPost('write', { path: this.activeTab, content });

            tab.content = content;
            tab.modified = false;
            this.renderTabs();
            this.showToast(t('File saved'), 'success');
        } catch (err) {
            this.showToast(t('Error saving: :error', { error: err.message }), 'error');
        }
    }

    // ==================== File Operations ====================

    promptNewFile() {
        this.showModal(t('New File'), t('Enter file name:'), '', (name) => {
            if (!name) return;
            const path = this.currentPath ? this.currentPath + '/' + name : name;
            this.apiPost('create-file', { path }).then(() => {
                this.showToast(t('File created'), 'success');
                this.refresh();
                this.openFile(path);
            }).catch(err => this.showToast(t('Error: :error', { error: err.message }), 'error'));
        });
    }

    promptNewFolder() {
        this.showModal(t('New Folder'), t('Enter folder name:'), '', (name) => {
            if (!name) return;
            const path = this.currentPath ? this.currentPath + '/' + name : name;
            this.apiPost('create-directory', { path }).then(() => {
                this.showToast(t('Folder created'), 'success');
                this.refresh();
            }).catch(err => this.showToast(t('Error: :error', { error: err.message }), 'error'));
        });
    }

    promptRename() {
        if (this.selectedItems.size === 0) return;
        const selectedPath = Array.from(this.selectedItems)[0];
        const currentName = selectedPath.split('/').pop();
        this.showModal(t('Rename'), t('Enter new name:'), currentName, (newName) => {
            if (!newName || newName === currentName) return;
            const parentPath = this.getParentPath(selectedPath);
            const newPath = parentPath ? parentPath + '/' + newName : newName;
            this.apiPost('rename', { from: selectedPath, to: newPath }).then(() => {
                if (this.openTabs.has(selectedPath)) {
                    const tab = this.openTabs.get(selectedPath);
                    this.openTabs.delete(selectedPath);
                    this.openTabs.set(newPath, tab);
                    if (this.activeTab === selectedPath) {
                        this.activeTab = newPath;
                    }
                    this.renderTabs();
                }
                this.selectedItems.delete(selectedPath);
                this.selectedItems.add(newPath);
                this.showToast(t('Renamed successfully'), 'success');
                this.refresh();
            }).catch(err => this.showToast(t('Error: :error', { error: err.message }), 'error'));
        });
    }

    promptChmod(item) {
        const selectedPath = item?.path || Array.from(this.selectedItems)[0];
        if (!selectedPath) return;

        // Always resolve full item from currentFileList to get permissionsOctal
        const fullItem = this.currentFileList?.find(f => f.path === selectedPath);
        if (!fullItem) return;

        const currentPerms = fullItem.permissionsOctal || '0644';
        this.showModal(t('Permissions'), t('Enter chmod (e.g. 0755):'), currentPerms, (mode) => {
            if (!mode) return;
            mode = mode.replace(/^0*/, '');
            if (!/^[0-7]{3,4}$/.test(mode)) {
                this.showToast(t('Invalid permissions format'), 'error');
                return;
            }
            this.apiPost('chmod', { path: fullItem.path, mode }).then(() => {
                this.showToast(t('Permissions changed'), 'success');
                this.refresh();
            }).catch(err => this.showToast(t('Error: :error', { error: err.message }), 'error'));
        });
    }

    async deleteSelected() {
        if (this.selectedItems.size === 0) return;
        const paths = Array.from(this.selectedItems);
        const msg = paths.length === 1
            ? t('Delete ":path"?', { path: paths[0] })
            : t('Delete :count items?', { count: paths.length });
        if (!confirm(msg)) return;

        try {
            await this.apiPost('delete', { paths });

            paths.forEach(p => {
                if (this.openTabs.has(p)) {
                    this.openTabs.get(p).model.dispose();
                    this.openTabs.delete(p);
                    if (this.activeTab === p) {
                        const remaining = Array.from(this.openTabs.keys());
                        this.activeTab = remaining.length > 0 ? remaining[remaining.length - 1] : null;
                    }
                }
                this.treeCache.delete(p);
            });

            this.renderTabs();
            this.selectedItems.clear();
            this.showToast(t('Deleted successfully'), 'success');
            this.refresh();
        } catch (err) {
            this.showToast(t('Error: :error', { error: err.message }), 'error');
        }
    }

    downloadSelected() {
        if (this.selectedItems.size === 0) return;
        this.selectedItems.forEach(p => {
            const url = this.baseUrl + '/download?path=' + encodeURIComponent(p);
            window.open(url, '_blank');
        });
    }

    triggerUpload() {
        document.getElementById('fm-upload-input')?.click();
    }

    async handleUpload(event) {
        const files = event.target.files;
        if (!files || files.length === 0) return;
        await this.uploadFiles(files);
        event.target.value = '';
    }

    async uploadFiles(files) {
        const maxBytes = this.maxUploadBytes;
        const maxMb = (maxBytes / (1024 * 1024)).toFixed(1);
        const tooLarge = Array.from(files).filter(f => f.size > maxBytes);

        if (tooLarge.length > 0) {
            const names = tooLarge.map(f => f.name).join(', ');
            this.showToast(t('File too large (max :maxMbMB): :files', { maxMb, files: names }), 'error');
            return;
        }

        const formData = new FormData();
        formData.append('directory', this.currentPath);
        Array.from(files).forEach(file => formData.append('files[]', file));

        try {
            await this.apiPostFormData('upload', formData);
            this.showToast(t(':count file(s) uploaded', { count: files.length }), 'success');
            this.refresh();
        } catch (err) {
            this.showToast(t('Upload error: :error', { error: err.message }), 'error');
        }
    }

    async refresh() {
        const path = this.currentPath;
        this.treeCache.delete(path);

        try {
            const data = await this.apiGet('list', { path });
            this.treeCache.set(path, data.items);
            this.renderTree();

            if (!this.activeTab) {
                this.renderFileList(data.items, path);
            }
        } catch (err) {
            this.showToast(t('Error refreshing: :error', { error: err.message }), 'error');
        }
    }

    // ==================== Breadcrumb ====================

    updateBreadcrumb(path) {
        if (!this.breadcrumbEl) return;

        let html = `<span class="fm-breadcrumb-item${!path ? ' active' : ''}" data-path="">
            <i class="fa-solid fa-home"></i> ${t('root')}
        </span>`;

        if (path) {
            const parts = path.split('/');
            let accumulated = '';
            parts.forEach((part, i) => {
                accumulated += (accumulated ? '/' : '') + part;
                html += `<span class="fm-breadcrumb-sep"><i class="fa-solid fa-chevron-right"></i></span>`;
                const isLast = i === parts.length - 1;
                html += `<span class="fm-breadcrumb-item${isLast ? ' active' : ''}" data-path="${this.escapeHtml(accumulated)}">${this.escapeHtml(part)}</span>`;
            });
        }

        this.breadcrumbEl.innerHTML = html;

        this.breadcrumbEl.querySelectorAll('.fm-breadcrumb-item:not(.active)').forEach(el => {
            el.addEventListener('click', () => {
                this.loadDirectory(el.dataset.path);
            });
        });
    }

    // ==================== Context Menu ====================

    showContextMenu(x, y, item) {
        if (!this.contextMenuEl) return;

        const selCount = this.selectedItems.size;
        let html = '';

        if (item && item.type === 'file' && selCount <= 1) {
            html += `<div class="fm-context-item" data-action="open"><i class="fa-solid fa-file-pen"></i> ${t('Open in Editor')}</div>`;
            html += `<div class="fm-context-item" data-action="download"><i class="fa-solid fa-download"></i> ${t('Download')}</div>`;
            if (item.name.toLowerCase().endsWith('.zip')) {
                html += `<div class="fm-context-item" data-action="extract"><i class="fa-solid fa-box-open"></i> ${t('Extract Here')}</div>`;
            }
            html += `<div class="fm-context-separator"></div>`;
        }

        if (item && item.type === 'directory' && selCount <= 1) {
            html += `<div class="fm-context-item" data-action="open-dir"><i class="fa-solid fa-folder-open"></i> ${t('Open')}</div>`;
            html += `<div class="fm-context-separator"></div>`;
        }

        html += `<div class="fm-context-item" data-action="new-file"><i class="fa-solid fa-file-circle-plus"></i> ${t('New File')}</div>`;
        html += `<div class="fm-context-item" data-action="new-folder"><i class="fa-solid fa-folder-plus"></i> ${t('New Folder')}</div>`;
        html += `<div class="fm-context-item" data-action="upload"><i class="fa-solid fa-upload"></i> ${t('Upload')}</div>`;

        if (selCount > 0) {
            html += `<div class="fm-context-separator"></div>`;
            html += `<div class="fm-context-item" data-action="compress"><i class="fa-solid fa-file-zipper"></i> ${t('Compress')}${selCount > 1 ? ` (${selCount})` : ''}</div>`;
            if (selCount <= 1) {
                html += `<div class="fm-context-item" data-action="rename"><i class="fa-solid fa-pen"></i> ${t('Rename')}</div>`;
                html += `<div class="fm-context-item" data-action="chmod"><i class="fa-solid fa-lock"></i> ${t('Permissions')}</div>`;
            }
            html += `<div class="fm-context-item danger" data-action="delete"><i class="fa-solid fa-trash"></i> ${t('Delete')}${selCount > 1 ? ` (${selCount})` : ''}</div>`;
        }

        this.contextMenuEl.innerHTML = html;
        this.contextMenuEl.classList.add('visible');

        // Position menu, flipping upward if it would overflow the viewport
        const menuRect = this.contextMenuEl.getBoundingClientRect();
        const viewportH = window.innerHeight;
        const viewportW = window.innerWidth;

        if (y + menuRect.height > viewportH) {
            y = Math.max(0, y - menuRect.height);
        }
        if (x + menuRect.width > viewportW) {
            x = Math.max(0, x - menuRect.width);
        }

        this.contextMenuEl.style.left = x + 'px';
        this.contextMenuEl.style.top = y + 'px';

        this.contextMenuEl.querySelectorAll('.fm-context-item').forEach(el => {
            el.addEventListener('click', (e) => {
                e.stopPropagation();
                this.hideContextMenu();
                const action = el.dataset.action;
                switch (action) {
                    case 'open': this.openFile(item.path); break;
                    case 'download': this.downloadSelected(); break;
                    case 'open-dir': this.loadDirectory(item.path); break;
                    case 'new-file': this.promptNewFile(); break;
                    case 'new-folder': this.promptNewFolder(); break;
                    case 'upload': this.triggerUpload(); break;
                    case 'rename': this.promptRename(); break;
                    case 'chmod': this.promptChmod(item); break;
                    case 'delete': this.deleteSelected(); break;
                    case 'compress': this.compressSelected(); break;
                    case 'extract': this.extractSelected(); break;
                }
            });
        });
    }

    hideContextMenu() {
        this.contextMenuEl?.classList.remove('visible');
    }

    // ==================== Modal ====================

    showModal(title, label, defaultValue, callback) {
        const overlay = document.getElementById('fm-modal-overlay');
        if (!overlay) return;

        overlay.querySelector('h5').textContent = title;
        overlay.querySelector('label').textContent = label;
        const input = overlay.querySelector('input');
        input.value = defaultValue;
        overlay.classList.add('active');

        setTimeout(() => {
            input.focus();
            input.select();
        }, 50);

        const confirmBtn = overlay.querySelector('[data-action="confirm"]');
        const cancelBtn = overlay.querySelector('[data-action="cancel"]');

        const cleanup = () => {
            overlay.classList.remove('active');
            confirmBtn.replaceWith(confirmBtn.cloneNode(true));
            cancelBtn.replaceWith(cancelBtn.cloneNode(true));
            input.removeEventListener('keydown', keyHandler);
        };

        const keyHandler = (e) => {
            if (e.key === 'Enter') {
                cleanup();
                callback(input.value.trim());
            } else if (e.key === 'Escape') {
                cleanup();
            }
        };

        input.addEventListener('keydown', keyHandler);

        overlay.querySelector('[data-action="confirm"]').addEventListener('click', () => {
            cleanup();
            callback(input.value.trim());
        });

        overlay.querySelector('[data-action="cancel"]').addEventListener('click', () => {
            cleanup();
        });
    }

    // ==================== Sidebar Resizer ====================

    initResizer() {
        const handle = document.getElementById('fm-resize-handle');
        if (!handle || !this.sidebarEl) return;

        let startX, startWidth;

        const onMouseMove = (e) => {
            const newWidth = startWidth + (e.clientX - startX);
            if (newWidth >= 150 && newWidth <= 500) {
                this.sidebarEl.style.width = newWidth + 'px';
            }
        };

        const onMouseUp = () => {
            handle.classList.remove('active');
            document.removeEventListener('mousemove', onMouseMove);
            document.removeEventListener('mouseup', onMouseUp);
            this.saveState();
        };

        handle.addEventListener('mousedown', (e) => {
            startX = e.clientX;
            startWidth = this.sidebarEl.offsetWidth;
            handle.classList.add('active');
            document.addEventListener('mousemove', onMouseMove);
            document.addEventListener('mouseup', onMouseUp);
        });
    }

    // ==================== Status Bar ====================

    updateStatusBar(path) {
        if (!this.statusBarEl) return;
        const tab = this.openTabs.get(path);
        const lang = tab ? tab.language : '';
        this.statusBarEl.textContent = path + (lang ? ` | ${lang}` : '');
    }

    updateToolbarState() {
        const hasSelection = this.selectedItems.size > 0;
        const singleSelection = this.selectedItems.size === 1;
        document.getElementById('btn-rename')?.toggleAttribute('disabled', !singleSelection);
        document.getElementById('btn-delete')?.toggleAttribute('disabled', !hasSelection);
        document.getElementById('btn-download')?.toggleAttribute('disabled', !hasSelection);
        document.getElementById('btn-compress')?.toggleAttribute('disabled', !hasSelection);

        // Extract: only enabled if exactly one .zip file is selected
        const selectedPaths = Array.from(this.selectedItems);
        const hasZip = singleSelection && selectedPaths[0].toLowerCase().endsWith('.zip');
        document.getElementById('btn-extract')?.toggleAttribute('disabled', !hasZip);
    }

    // ==================== Helpers ====================

    getParentPath(path) {
        const parts = path.split('/');
        parts.pop();
        return parts.join('/');
    }

    formatSize(bytes) {
        if (bytes === null || bytes === undefined) return '';
        if (bytes === 0) return '0 B';
        const units = ['B', 'KB', 'MB', 'GB'];
        const i = Math.floor(Math.log(bytes) / Math.log(1024));
        return (bytes / Math.pow(1024, i)).toFixed(i > 0 ? 1 : 0) + ' ' + units[i];
    }

    formatDate(timestamp) {
        if (!timestamp) return '';
        const formatted = formatDateTime(new Date(timestamp * 1000));
        return formatted === '-' ? '' : formatted;
    }

    escapeHtml(str) {
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

    // ==================== Compress / Extract ====================

    compressSelected() {
        if (this.selectedItems.size === 0) return;
        this.showModal(t('Compress'), t('Enter archive name:'), 'archive.zip', async (name) => {
            if (!name) return;
            const paths = Array.from(this.selectedItems);
            try {
                this.statusBarEl.textContent = t('Compressing...');
                await this.apiPost('compress', {
                    paths,
                    name,
                    directory: this.currentPath,
                });
                this.showToast(t('Archive created'), 'success');
                this.refresh();
            } catch (err) {
                this.showToast(t('Compress error: :error', { error: err.message }), 'error');
            } finally {
                this.statusBarEl.textContent = t('Ready');
            }
        });
    }

    async extractSelected() {
        const paths = Array.from(this.selectedItems);
        const zipPath = paths.find(p => p.toLowerCase().endsWith('.zip'));
        if (!zipPath) return;

        try {
            this.statusBarEl.textContent = t('Extracting...');
            await this.apiPost('decompress', {
                path: zipPath,
                directory: this.currentPath,
            });
            this.showToast(t('Archive extracted'), 'success');
            this.refresh();
        } catch (err) {
            this.showToast(t('Extract error: :error', { error: err.message }), 'error');
        } finally {
            this.statusBarEl.textContent = t('Ready');
        }
    }

    toggleFullscreen() {
        const app = document.getElementById('fm-app');
        const btn = document.getElementById('btn-fullscreen');
        const icon = btn?.querySelector('i');

        app.classList.toggle('fm-fullscreen');

        if (app.classList.contains('fm-fullscreen')) {
            icon?.classList.replace('fa-expand', 'fa-compress');
            btn.title = t('Exit Fullscreen');
        } else {
            icon?.classList.replace('fa-compress', 'fa-expand');
            btn.title = t('Fullscreen');
        }

        // Resize Monaco editor after layout change
        if (this.editor) {
            setTimeout(() => this.editor.layout(), 100);
        }
    }

    showToast(message, type = 'success') {
        const toast = document.createElement('div');
        toast.className = 'fm-toast ' + type;
        toast.textContent = message;
        document.body.appendChild(toast);
        setTimeout(() => toast.remove(), 3000);
    }
}

// Initialize when DOM is ready
document.addEventListener('DOMContentLoaded', () => {
    const container = document.getElementById('fm-app');
    if (!container) return;

    window.fileManager = new FileManager({
        domainId: container.dataset.domainId,
        baseUrl: container.dataset.baseUrl,
        csrfToken: document.querySelector('meta[name="csrf-token"]').content,
        maxUploadBytes: container.dataset.maxUpload,
    });
});
