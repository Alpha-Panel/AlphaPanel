<template>
    <Head :title="t('Backups')" />
    <ThemeProvider>
        <SidebarProvider>
            <AdminLayout>
                <PageBreadcrumb :pageTitle="t('Backups')" />
                <Toast />

                <div class="grid grid-cols-1 gap-4 md:gap-6">
                    <!-- Credentials Warning -->
                    <div
                        v-if="!settings.has_credentials"
                        class="rounded-2xl border border-warning-300 bg-warning-50 p-5 dark:border-warning-500/30 dark:bg-warning-500/10"
                    >
                        <div class="flex items-start gap-3">
                            <i class="bx bx-error-circle mt-0.5 text-xl text-warning-600 dark:text-warning-400"></i>
                            <div>
                                <h4 class="font-semibold text-warning-800 dark:text-warning-200">
                                    {{ t('Google API Credentials Required') }}
                                </h4>
                                <p class="mt-1 text-sm text-warning-700 dark:text-warning-300">
                                    {{ t('Set GOOGLE_CLIENT_ID and GOOGLE_CLIENT_SECRET in your .env file. Create OAuth 2.0 credentials (Web application type) in Google Cloud Console and enable the Google Drive API.') }}
                                </p>
                            </div>
                        </div>
                    </div>

                    <!-- Connection Status Card -->
                    <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
                        <div class="flex items-center justify-between">
                            <div class="flex items-center gap-4">
                                <div
                                    :class="[
                                        'flex h-12 w-12 items-center justify-center rounded-xl',
                                        settings.is_connected
                                            ? 'bg-success-50 text-success-600 dark:bg-success-500/20 dark:text-success-300'
                                            : 'bg-gray-100 text-gray-500 dark:bg-gray-800 dark:text-gray-400',
                                    ]"
                                >
                                    <i class="fa-brands fa-google-drive text-2xl"></i>
                                </div>
                                <div>
                                    <h4 class="text-sm font-semibold text-gray-800 dark:text-white/90">
                                        Google Drive
                                    </h4>
                                    <p v-if="settings.is_connected" class="text-sm text-success-600 dark:text-success-400">
                                        <i class="bx bx-check-circle mr-1"></i>
                                        {{ settings.connected_email }}
                                    </p>
                                    <p v-else class="text-sm text-gray-500 dark:text-gray-400">
                                        {{ t('Not connected') }}
                                    </p>
                                </div>
                            </div>
                            <div>
                                <a
                                    v-if="!settings.is_connected && settings.has_credentials"
                                    :href="route('backups.connect')"
                                    class="inline-flex items-center gap-2 rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white transition hover:bg-blue-700"
                                >
                                    <i class="fa-brands fa-google text-base"></i>
                                    {{ t('Connect Google Drive') }}
                                </a>
                                <button
                                    v-if="settings.is_connected"
                                    @click="showDisconnectConfirm = true"
                                    class="inline-flex items-center gap-2 rounded-lg border border-red-300 px-4 py-2 text-sm font-medium text-red-600 transition hover:bg-red-50 dark:border-red-500/30 dark:text-red-400 dark:hover:bg-red-500/10"
                                >
                                    <i class="bx bx-unlink"></i>
                                    {{ t('Disconnect') }}
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Folder Selection + Settings (shown when connected) -->
                    <div v-if="settings.is_connected" class="grid grid-cols-1 gap-4 md:grid-cols-2 md:gap-6">
                        <!-- Folder Selection Card -->
                        <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
                            <h4 class="mb-4 text-sm font-semibold text-gray-800 dark:text-white/90">
                                <i class="bx bx-folder mr-1"></i>
                                {{ t('Backup Folder') }}
                            </h4>

                            <div v-if="settings.drive_folder_name" class="mb-4 flex items-center gap-2">
                                <span class="inline-flex items-center gap-1.5 rounded-lg bg-brand-50 px-3 py-1.5 text-sm font-medium text-brand-700 dark:bg-brand-500/20 dark:text-brand-300">
                                    <i class="bx bx-folder-open"></i>
                                    {{ settings.drive_folder_name }}
                                </span>
                            </div>

                            <button
                                @click="showFolderBrowser = true"
                                class="inline-flex items-center gap-2 rounded-lg border border-gray-300 px-3 py-2 text-sm font-medium text-gray-700 transition hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-gray-800"
                            >
                                <i class="bx bx-folder-plus"></i>
                                {{ settings.drive_folder_id ? t('Change Folder') : t('Select Folder') }}
                            </button>
                        </div>

                        <!-- Settings Card -->
                        <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
                            <h4 class="mb-4 text-sm font-semibold text-gray-800 dark:text-white/90">
                                <i class="bx bx-cog mr-1"></i>
                                {{ t('Backup Settings') }}
                            </h4>

                            <form @submit.prevent="saveSettings">
                                <div class="space-y-4">
                                    <div class="flex items-center justify-between">
                                        <label class="text-sm text-gray-700 dark:text-gray-300">{{ t('Enable Backups') }}</label>
                                        <label class="relative inline-flex cursor-pointer items-center">
                                            <input
                                                v-model="settingsForm.is_enabled"
                                                type="checkbox"
                                                class="peer sr-only"
                                            />
                                            <div class="peer h-6 w-11 rounded-full bg-gray-200 after:absolute after:left-[2px] after:top-[2px] after:h-5 after:w-5 after:rounded-full after:border after:border-gray-300 after:bg-white after:transition-all after:content-[''] peer-checked:bg-brand-500 peer-checked:after:translate-x-full peer-checked:after:border-white dark:bg-gray-700"></div>
                                        </label>
                                    </div>

                                    <div>
                                        <label class="mb-1 block text-sm text-gray-700 dark:text-gray-300">
                                            {{ t('Retention (days)') }}
                                        </label>
                                        <input
                                            v-model.number="settingsForm.backup_retention_days"
                                            type="number"
                                            min="1"
                                            max="365"
                                            class="h-9 w-24 rounded-lg border border-gray-300 bg-transparent px-3 text-sm text-gray-700 focus:border-brand-500 focus:outline-none dark:border-gray-700 dark:text-gray-300"
                                        />
                                    </div>

                                    <button
                                        type="submit"
                                        :disabled="settingsForm.processing"
                                        class="inline-flex items-center gap-2 rounded-lg bg-brand-500 px-4 py-2 text-sm font-medium text-white transition hover:bg-brand-600 disabled:opacity-50"
                                    >
                                        <i class="bx bx-save"></i>
                                        {{ t('Save') }}
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>

                    <!-- Active Backup Progress -->
                    <div
                        v-if="activeRun"
                        class="rounded-2xl border border-brand-200 bg-brand-50 p-5 dark:border-brand-500/30 dark:bg-brand-500/10"
                    >
                        <div class="flex items-center gap-3">
                            <div class="h-8 w-8 animate-spin rounded-full border-4 border-brand-200 border-t-brand-500"></div>
                            <div class="flex-1">
                                <div class="flex items-center justify-between">
                                    <p class="text-sm font-medium text-brand-800 dark:text-brand-200">
                                        {{ activeRunMessage || t('Uploading backup...') }}
                                    </p>
                                    <span class="text-sm font-semibold text-brand-700 dark:text-brand-300">
                                        {{ activeRun.progress_percent }}%
                                    </span>
                                </div>
                                <div class="mt-2 h-2 overflow-hidden rounded-full bg-brand-200 dark:bg-brand-800">
                                    <div
                                        class="h-full rounded-full bg-brand-500 transition-all duration-300"
                                        :style="{ width: activeRun.progress_percent + '%' }"
                                    ></div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Manual Backup + Last Backup -->
                    <div v-if="settings.is_connected && settings.drive_folder_id" class="flex flex-wrap items-center gap-3">
                        <button
                            @click="showRunConfirm = true"
                            :disabled="!!activeRun"
                            class="inline-flex items-center gap-2 rounded-lg bg-success-500 px-4 py-2 text-sm font-medium text-white transition hover:bg-success-600 disabled:opacity-50"
                        >
                            <i class="bx bx-cloud-upload"></i>
                            {{ t('Run Backup Now') }}
                        </button>
                        <span v-if="settings.last_backup_at" class="text-sm text-gray-500 dark:text-gray-400">
                            {{ t('Last backup') }}: {{ settings.last_backup_at }}
                        </span>
                    </div>

                    <!-- Backup History Table -->
                    <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
                        <div class="mb-4 flex items-center justify-between">
                            <h4 class="text-sm font-semibold text-gray-800 dark:text-white/90">
                                <i class="bx bx-history mr-1"></i>
                                {{ t('Backup History') }}
                            </h4>
                            <button
                                @click="refreshPage"
                                class="inline-flex items-center gap-1 text-xs text-gray-500 transition hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200"
                            >
                                <i class="bx bx-refresh"></i>
                                {{ t('Refresh') }}
                            </button>
                        </div>

                        <div v-if="recent_runs.length === 0" class="py-8 text-center text-gray-500 dark:text-gray-400">
                            <i class="bx bx-cloud text-4xl"></i>
                            <p class="mt-2 text-sm">{{ t('No backups yet') }}</p>
                        </div>

                        <div v-else class="overflow-x-auto">
                            <table class="w-full min-w-[600px] text-sm">
                                <thead>
                                    <tr class="border-b border-gray-200 text-left text-xs uppercase text-gray-500 dark:border-gray-800 dark:text-gray-400">
                                        <th class="pb-3 pr-3">{{ t('Type') }}</th>
                                        <th class="pb-3 pr-3">{{ t('Status') }}</th>
                                        <th class="pb-3 pr-3">{{ t('File') }}</th>
                                        <th class="pb-3 pr-3">{{ t('Size') }}</th>
                                        <th class="pb-3 pr-3">{{ t('Started') }}</th>
                                        <th class="pb-3 pr-3">{{ t('Triggered By') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr
                                        v-for="run in recent_runs"
                                        :key="run.id"
                                        class="border-b border-gray-100 last:border-0 dark:border-gray-800"
                                    >
                                        <td class="py-3 pr-3">
                                            <span
                                                :class="[
                                                    'inline-flex rounded-full px-2 py-0.5 text-xs font-medium',
                                                    typeBadge(run.type),
                                                ]"
                                            >
                                                {{ run.type }}
                                            </span>
                                        </td>
                                        <td class="py-3 pr-3">
                                            <span
                                                :class="[
                                                    'inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium',
                                                    statusBadge(run.status),
                                                ]"
                                            >
                                                <i :class="statusIcon(run.status)" class="text-[10px]"></i>
                                                {{ run.status }}
                                            </span>
                                        </td>
                                        <td class="py-3 pr-3 text-gray-700 dark:text-gray-300">
                                            {{ run.file_name || '-' }}
                                        </td>
                                        <td class="py-3 pr-3 text-gray-500 dark:text-gray-400">
                                            {{ run.file_size ? humanSize(run.file_size) : '-' }}
                                        </td>
                                        <td class="py-3 pr-3 text-gray-500 dark:text-gray-400">
                                            {{ run.started_at || '-' }}
                                        </td>
                                        <td class="py-3 pr-3 text-gray-500 dark:text-gray-400">
                                            {{ run.triggered_by || t('System') }}
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Disconnect Confirmation Modal -->
                <Teleport to="body">
                    <div v-if="showDisconnectConfirm" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50">
                        <div class="mx-4 w-full max-w-md rounded-2xl bg-white p-6 shadow-xl dark:bg-gray-900">
                            <h3 class="text-lg font-semibold text-gray-800 dark:text-white">{{ t('Disconnect Google Drive?') }}</h3>
                            <p class="mt-2 text-sm text-gray-600 dark:text-gray-400">
                                {{ t('This will remove the Google Drive connection. Existing backups on Drive will not be deleted.') }}
                            </p>
                            <div class="mt-4 flex justify-end gap-3">
                                <button
                                    @click="showDisconnectConfirm = false"
                                    class="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 transition hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-gray-800"
                                >
                                    {{ t('Cancel') }}
                                </button>
                                <button
                                    @click="disconnect"
                                    :disabled="disconnectForm.processing"
                                    class="rounded-lg bg-red-600 px-4 py-2 text-sm font-medium text-white transition hover:bg-red-700 disabled:opacity-50"
                                >
                                    {{ t('Disconnect') }}
                                </button>
                            </div>
                        </div>
                    </div>
                </Teleport>

                <!-- Run Backup Confirmation Modal -->
                <Teleport to="body">
                    <div v-if="showRunConfirm" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50">
                        <div class="mx-4 w-full max-w-md rounded-2xl bg-white p-6 shadow-xl dark:bg-gray-900">
                            <h3 class="text-lg font-semibold text-gray-800 dark:text-white">{{ t('Run Backup Now?') }}</h3>
                            <p class="mt-2 text-sm text-gray-600 dark:text-gray-400">
                                {{ t('This will start uploading existing backup files to Google Drive. The process runs in the background.') }}
                            </p>
                            <div class="mt-4 flex justify-end gap-3">
                                <button
                                    @click="showRunConfirm = false"
                                    class="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 transition hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-gray-800"
                                >
                                    {{ t('Cancel') }}
                                </button>
                                <button
                                    @click="runBackup"
                                    :disabled="runForm.processing"
                                    class="rounded-lg bg-success-500 px-4 py-2 text-sm font-medium text-white transition hover:bg-success-600 disabled:opacity-50"
                                >
                                    <i class="bx bx-cloud-upload mr-1"></i>
                                    {{ t('Start Backup') }}
                                </button>
                            </div>
                        </div>
                    </div>
                </Teleport>

                <!-- Folder Browser Modal -->
                <Teleport to="body">
                    <div v-if="showFolderBrowser" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50">
                        <div class="mx-4 w-full max-w-lg rounded-2xl bg-white p-6 shadow-xl dark:bg-gray-900">
                            <div class="mb-4 flex items-center justify-between">
                                <h3 class="text-lg font-semibold text-gray-800 dark:text-white">
                                    {{ t('Select Backup Folder') }}
                                </h3>
                                <button @click="showFolderBrowser = false" class="text-gray-400 hover:text-gray-600">
                                    <i class="bx bx-x text-xl"></i>
                                </button>
                            </div>

                            <!-- Breadcrumb Navigation -->
                            <div class="mb-3 flex flex-wrap items-center gap-1 text-sm">
                                <button
                                    @click="navigateToFolder(null, 'My Drive')"
                                    class="text-brand-500 hover:underline"
                                >
                                    My Drive
                                </button>
                                <template v-for="(crumb, i) in folderBreadcrumbs" :key="i">
                                    <i class="bx bx-chevron-right text-gray-400"></i>
                                    <button
                                        @click="navigateToFolder(crumb.id, crumb.name)"
                                        class="text-brand-500 hover:underline"
                                    >
                                        {{ crumb.name }}
                                    </button>
                                </template>
                            </div>

                            <!-- Folder List -->
                            <div class="mb-4 max-h-64 overflow-y-auto rounded-lg border border-gray-200 dark:border-gray-700">
                                <div v-if="foldersLoading" class="flex items-center justify-center py-8">
                                    <div class="h-6 w-6 animate-spin rounded-full border-2 border-gray-300 border-t-brand-500"></div>
                                </div>
                                <div v-else-if="folderList.length === 0" class="py-6 text-center text-sm text-gray-500 dark:text-gray-400">
                                    {{ t('No folders found') }}
                                </div>
                                <div v-else>
                                    <button
                                        v-for="folder in folderList"
                                        :key="folder.id"
                                        @click="navigateToFolder(folder.id, folder.name)"
                                        @dblclick="selectFolder(folder)"
                                        :class="[
                                            'flex w-full items-center gap-3 border-b border-gray-100 px-4 py-3 text-left text-sm transition last:border-0 hover:bg-gray-50 dark:border-gray-800 dark:hover:bg-gray-800',
                                            selectedFolderId === folder.id ? 'bg-brand-50 dark:bg-brand-500/10' : '',
                                        ]"
                                    >
                                        <i class="bx bx-folder text-lg text-yellow-500"></i>
                                        <span class="text-gray-700 dark:text-gray-300">{{ folder.name }}</span>
                                        <i class="bx bx-chevron-right ml-auto text-gray-400"></i>
                                    </button>
                                </div>
                            </div>

                            <!-- Create New Folder -->
                            <div class="mb-4 flex items-center gap-2">
                                <input
                                    v-model="newFolderName"
                                    type="text"
                                    :placeholder="t('New folder name...')"
                                    class="h-9 flex-1 rounded-lg border border-gray-300 bg-transparent px-3 text-sm text-gray-700 focus:border-brand-500 focus:outline-none dark:border-gray-700 dark:text-gray-300"
                                    @keyup.enter="createNewFolder"
                                />
                                <button
                                    @click="createNewFolder"
                                    :disabled="!newFolderName || creatingFolder"
                                    class="inline-flex h-9 items-center gap-1 rounded-lg bg-gray-100 px-3 text-sm font-medium text-gray-700 transition hover:bg-gray-200 disabled:opacity-50 dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-gray-700"
                                >
                                    <i class="bx bx-plus"></i>
                                    {{ t('Create') }}
                                </button>
                            </div>

                            <!-- Actions -->
                            <div class="flex justify-end gap-3">
                                <button
                                    @click="showFolderBrowser = false"
                                    class="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 transition hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-gray-800"
                                >
                                    {{ t('Cancel') }}
                                </button>
                                <button
                                    @click="confirmFolderSelection"
                                    class="rounded-lg bg-brand-500 px-4 py-2 text-sm font-medium text-white transition hover:bg-brand-600"
                                >
                                    {{ t('Select This Folder') }}
                                </button>
                            </div>
                        </div>
                    </div>
                </Teleport>
            </AdminLayout>
        </SidebarProvider>
    </ThemeProvider>
</template>

<script setup lang="ts">
import { Head, Link, useForm, router } from '@inertiajs/vue3';
import { computed, onMounted, onUnmounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import AdminLayout from '@/Components/Layout/AdminLayout.vue';
import PageBreadcrumb from '@/Components/Common/PageBreadcrumb.vue';
import SidebarProvider from '@/Components/Layout/SidebarProvider.vue';
import ThemeProvider from '@/Components/Layout/ThemeProvider.vue';
import Toast from '@/Components/UI/Toast.vue';

const { t } = useI18n();

interface BackupSettings {
    is_connected: boolean;
    connected_email: string | null;
    drive_folder_id: string | null;
    drive_folder_name: string | null;
    is_enabled: boolean;
    backup_retention_days: number;
    last_backup_at: string | null;
    has_credentials: boolean;
}

interface BackupRunItem {
    id: number;
    type: string;
    status: string;
    file_name: string | null;
    file_size: number | null;
    progress_percent: number;
    error_message: string | null;
    started_at: string | null;
    finished_at: string | null;
    triggered_by: string | null;
}

interface DriveFolder {
    id: string;
    name: string;
}

const props = defineProps<{
    settings: BackupSettings;
    recent_runs: BackupRunItem[];
}>();

// Forms
const settingsForm = useForm({
    is_enabled: props.settings.is_enabled,
    backup_retention_days: props.settings.backup_retention_days,
});

const disconnectForm = useForm({});
const runForm = useForm({});
const folderForm = useForm({
    drive_folder_id: '',
    drive_folder_name: '',
});

// UI State
const showDisconnectConfirm = ref(false);
const showRunConfirm = ref(false);
const showFolderBrowser = ref(false);

// Folder Browser State
const folderList = ref<DriveFolder[]>([]);
const folderBreadcrumbs = ref<DriveFolder[]>([]);
const currentFolderParentId = ref<string | null>(null);
const selectedFolderId = ref<string | null>(null);
const selectedFolderName = ref('');
const foldersLoading = ref(false);
const newFolderName = ref('');
const creatingFolder = ref(false);

// Live Progress State
const activeRunMessage = ref('');

// Computed
const activeRun = computed(() => {
    return props.recent_runs.find((r) => r.status === 'uploading' || r.status === 'running');
});

// Echo/Reverb listener
let echoChannel: any = null;

onMounted(() => {
    if (typeof window.Echo !== 'undefined') {
        echoChannel = window.Echo.private('admin').listen('BackupProgress', (e: any) => {
            const run = props.recent_runs.find((r) => r.id === e.backup_run_id);
            if (run) {
                run.progress_percent = e.percent;
                run.status = e.status;
                activeRunMessage.value = e.message;
            }

            if (e.status === 'completed' || e.status === 'failed') {
                setTimeout(() => router.reload(), 1000);
            }
        });
    }
});

onUnmounted(() => {
    if (echoChannel) {
        echoChannel.stopListening('BackupProgress');
    }
});

// Actions
function saveSettings() {
    settingsForm.post(route('backups.settings'), {
        preserveScroll: true,
    });
}

function disconnect() {
    disconnectForm.post(route('backups.disconnect'), {
        onFinish: () => {
            showDisconnectConfirm.value = false;
        },
    });
}

function runBackup() {
    runForm.post(route('backups.run'), {
        onFinish: () => {
            showRunConfirm.value = false;
        },
    });
}

function refreshPage() {
    router.reload();
}

// Folder Browser
async function loadFolders(parentId: string | null) {
    foldersLoading.value = true;
    try {
        const params = parentId ? `?parent_id=${parentId}` : '';
        const response = await fetch(route('backups.folders') + params, {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        });
        const data = await response.json();
        folderList.value = data.folders || [];
    } catch {
        folderList.value = [];
    } finally {
        foldersLoading.value = false;
    }
}

function navigateToFolder(id: string | null, name: string) {
    if (id === null) {
        folderBreadcrumbs.value = [];
        currentFolderParentId.value = null;
    } else {
        const existingIndex = folderBreadcrumbs.value.findIndex((c) => c.id === id);
        if (existingIndex >= 0) {
            folderBreadcrumbs.value = folderBreadcrumbs.value.slice(0, existingIndex + 1);
        } else {
            folderBreadcrumbs.value.push({ id, name });
        }
        currentFolderParentId.value = id;
    }
    selectedFolderId.value = id;
    selectedFolderName.value = name;
    loadFolders(id);
}

function selectFolder(folder: DriveFolder) {
    selectedFolderId.value = folder.id;
    selectedFolderName.value = folder.name;
    confirmFolderSelection();
}

function confirmFolderSelection() {
    if (!selectedFolderId.value) return;

    folderForm.drive_folder_id = selectedFolderId.value;
    folderForm.drive_folder_name = selectedFolderName.value;
    folderForm.post(route('backups.folder'), {
        onSuccess: () => {
            showFolderBrowser.value = false;
        },
    });
}

async function createNewFolder() {
    if (!newFolderName.value) return;
    creatingFolder.value = true;

    try {
        const response = await fetch(route('backups.create-folder'), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content || '',
            },
            body: JSON.stringify({
                name: newFolderName.value,
                parent_id: currentFolderParentId.value,
            }),
        });
        const data = await response.json();
        if (data.id) {
            folderList.value.push(data);
            newFolderName.value = '';
        }
    } finally {
        creatingFolder.value = false;
    }
}

// Helpers
function humanSize(bytes: number): string {
    const units = ['B', 'KB', 'MB', 'GB', 'TB'];
    let i = 0;
    let size = bytes;
    while (size >= 1024 && i < units.length - 1) {
        size /= 1024;
        i++;
    }
    return `${size.toFixed(i > 0 ? 1 : 0)} ${units[i]}`;
}

function statusBadge(status: string): string {
    switch (status) {
        case 'completed':
            return 'bg-success-50 text-success-700 dark:bg-success-500/20 dark:text-success-300';
        case 'uploading':
        case 'running':
            return 'bg-brand-50 text-brand-700 dark:bg-brand-500/20 dark:text-brand-300';
        case 'failed':
            return 'bg-red-50 text-red-700 dark:bg-red-500/20 dark:text-red-300';
        default:
            return 'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300';
    }
}

function statusIcon(status: string): string {
    switch (status) {
        case 'completed':
            return 'bx bxs-check-circle';
        case 'uploading':
        case 'running':
            return 'bx bx-loader-alt bx-spin';
        case 'failed':
            return 'bx bxs-x-circle';
        default:
            return 'bx bx-circle';
    }
}

function typeBadge(type: string): string {
    switch (type) {
        case 'web':
            return 'bg-blue-50 text-blue-700 dark:bg-blue-500/20 dark:text-blue-300';
        case 'mysql':
            return 'bg-orange-50 text-orange-700 dark:bg-orange-500/20 dark:text-orange-300';
        default:
            return 'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300';
    }
}
</script>
