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
                    <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/3">
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
                        <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/3">
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

                            <!-- Storage Quota -->
                            <div v-if="storageQuota" class="mt-5 border-t border-gray-200 pt-4 dark:border-gray-700">
                                <h5 class="mb-3 text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                    {{ t('Storage Usage') }}
                                </h5>
                                <StorageDonutChart :used="storageQuota.usage" :total="storageQuota.limit" />
                            </div>
                            <div v-else-if="storageQuotaLoading" class="mt-5 flex justify-center border-t border-gray-200 pt-4 dark:border-gray-700">
                                <div class="h-6 w-6 animate-spin rounded-full border-2 border-gray-300 border-t-brand-500"></div>
                            </div>
                        </div>

                        <!-- Settings Card -->
                        <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/3">
                            <h4 class="mb-4 text-sm font-semibold text-gray-800 dark:text-white/90">
                                <i class="bx bx-cog mr-1"></i>
                                {{ t('Backup Settings') }}
                            </h4>

                            <form @submit.prevent="saveSettings">
                                <div class="space-y-4">
                                    <div class="flex items-center justify-between">
                                        <label class="text-sm text-gray-700 dark:text-gray-300">{{ t('Enable Backups') }}</label>
                                        <div class="flex items-center gap-2.5">
                                            <span
                                                class="min-w-12 text-right text-xs font-semibold uppercase tracking-wide"
                                                :class="settingsForm.is_enabled ? 'text-success-600 dark:text-success-400' : 'text-gray-400 dark:text-gray-500'"
                                            >
                                                {{ settingsForm.is_enabled ? t('On') : t('Off') }}
                                            </span>
                                            <label class="relative inline-flex cursor-pointer items-center">
                                                <input
                                                    v-model="settingsForm.is_enabled"
                                                    type="checkbox"
                                                    class="peer sr-only"
                                                />
                                                <div class="peer h-6 w-11 rounded-full bg-gray-300 after:absolute after:left-0.5 after:top-0.5 after:h-5 after:w-5 after:rounded-full after:border after:border-gray-300 after:bg-white after:transition-all after:content-[''] peer-checked:bg-success-500 peer-checked:after:translate-x-full peer-checked:after:border-white dark:bg-gray-600 dark:after:border-gray-500"></div>
                                            </label>
                                        </div>
                                    </div>

                                    <div>
                                        <label class="mb-1 block text-sm text-gray-700 dark:text-gray-300">
                                            {{ t('Schedule') }}
                                        </label>
                                        <select
                                            v-model="settingsForm.backup_schedule"
                                            class="h-9 rounded-lg border border-gray-300 bg-transparent px-3 text-sm text-gray-700 focus:border-brand-500 focus:outline-none dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300"
                                        >
                                            <option value="daily">{{ t('Every day') }}</option>
                                            <option value="every_2_days">{{ t('Every 2 days') }}</option>
                                            <option value="every_3_days">{{ t('Every 3 days') }}</option>
                                            <option value="weekly">{{ t('Weekly (Monday)') }}</option>
                                            <option value="every_2_weeks">{{ t('Every 2 weeks') }}</option>
                                            <option value="monthly">{{ t('Monthly') }}</option>
                                        </select>
                                    </div>

                                    <div>
                                        <label class="mb-1 block text-sm text-gray-700 dark:text-gray-300">
                                            {{ t('Backup Time') }}
                                        </label>
                                        <input
                                            v-model="settingsForm.backup_time"
                                            type="time"
                                            class="h-9 rounded-lg border border-gray-300 bg-transparent px-3 text-sm text-gray-700 focus:border-brand-500 focus:outline-none dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300"
                                        />
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
                            <div class="h-8 w-8 shrink-0 animate-spin rounded-full border-4 border-brand-200 border-t-brand-500"></div>
                            <div class="flex-1 space-y-3">
                                <!-- Per-file progress -->
                                <div>
                                    <div class="flex items-center justify-between">
                                        <p class="truncate text-sm font-medium text-brand-800 dark:text-brand-200">
                                            {{ activeRunMessage || t('Uploading backup...') }}
                                        </p>
                                        <span v-if="currentFileName" class="ml-2 shrink-0 text-sm font-semibold text-brand-700 dark:text-brand-300">
                                            {{ currentFilePercent }}%
                                        </span>
                                    </div>
                                    <div class="mt-1.5 h-2 overflow-hidden rounded-full bg-brand-200 dark:bg-brand-800">
                                        <div
                                            class="h-full rounded-full bg-brand-500 transition-all duration-300"
                                            :style="{ width: (currentFileName ? currentFilePercent : activeRun.progress_percent) + '%' }"
                                        ></div>
                                    </div>
                                </div>

                                <!-- Overall progress -->
                                <div v-if="itemsTotal > 0">
                                    <div class="flex items-center justify-between">
                                        <p class="text-xs font-medium text-brand-600 dark:text-brand-400">
                                            {{ t(':done / :total items', { done: itemsDone, total: itemsTotal }) }}
                                        </p>
                                        <span class="text-xs font-semibold text-brand-600 dark:text-brand-400">
                                            {{ activeRun.progress_percent }}%
                                        </span>
                                    </div>
                                    <div class="mt-1 h-1.5 overflow-hidden rounded-full bg-brand-100 dark:bg-brand-900">
                                        <div
                                            class="h-full rounded-full bg-brand-400 transition-all duration-500"
                                            :style="{ width: activeRun.progress_percent + '%' }"
                                        ></div>
                                    </div>
                                </div>
                            </div>
                            <div class="flex shrink-0 items-center gap-2">
                                <button
                                    @click="cancelBackup"
                                    :disabled="cancelProcessing"
                                    class="inline-flex h-8 items-center gap-1 rounded-lg border border-red-300 px-2.5 text-xs font-medium text-red-600 transition hover:bg-red-50 disabled:opacity-50 dark:border-red-500/30 dark:text-red-400 dark:hover:bg-red-500/10"
                                    :title="t('Cancel')"
                                >
                                    <i class="bx bx-x text-sm"></i>
                                    {{ t('Cancel') }}
                                </button>
                                <button
                                    @click="restartBackup"
                                    :disabled="restartProcessing"
                                    class="inline-flex h-8 items-center gap-1 rounded-lg border border-brand-300 px-2.5 text-xs font-medium text-brand-600 transition hover:bg-brand-50 disabled:opacity-50 dark:border-brand-500/30 dark:text-brand-400 dark:hover:bg-brand-500/10"
                                    :title="t('Restart')"
                                >
                                    <i class="bx bx-revision text-sm"></i>
                                    {{ t('Restart') }}
                                </button>
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
                    <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/3">
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
                            <table class="w-full min-w-150 text-sm">
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
                                        @click="openBackupDetail(run)"
                                        class="cursor-pointer border-b border-gray-100 transition last:border-0 hover:bg-gray-50 dark:border-gray-800 dark:hover:bg-gray-800/50"
                                    >
                                        <td class="py-3 pr-3">
                                            <span
                                                :class="[
                                                    'inline-flex rounded-full px-2 py-0.5 text-xs font-medium',
                                                    typeBadge(run.type),
                                                ]"
                                            >
                                                {{ t(run.type) }}
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
                                                {{ t(run.status) }}
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

                                    <!-- Older runs separator -->
                                    <tr v-if="olderRuns.length > 0">
                                        <td colspan="6" class="py-2 text-center text-xs text-gray-400 dark:text-gray-500">
                                            <i class="bx bx-time-five mr-1"></i>
                                            {{ t('Older backups') }}
                                        </td>
                                    </tr>

                                    <!-- Older runs rows -->
                                    <tr
                                        v-for="run in olderRuns"
                                        :key="`older-${run.id}`"
                                        @click="openBackupDetail(run)"
                                        class="cursor-pointer border-b border-gray-100 opacity-60 transition last:border-0 hover:bg-gray-50 hover:opacity-100 dark:border-gray-800 dark:hover:bg-gray-800/50"
                                    >
                                        <td class="py-3 pr-3">
                                            <span
                                                :class="[
                                                    'inline-flex rounded-full px-2 py-0.5 text-xs font-medium',
                                                    typeBadge(run.type),
                                                ]"
                                            >
                                                {{ t(run.type) }}
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
                                                {{ t(run.status) }}
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

                            <!-- Load older button -->
                            <div
                                v-if="(expired_count > 0 && !showingOlder) || hasMoreOlder || olderLoading"
                                class="mt-4 flex justify-center"
                            >
                                <button
                                    v-if="!olderLoading"
                                    @click="loadOlderRuns"
                                    class="inline-flex items-center gap-1.5 rounded-lg border border-gray-300 px-3 py-1.5 text-xs font-medium text-gray-500 transition hover:bg-gray-50 dark:border-gray-700 dark:text-gray-400 dark:hover:bg-gray-800"
                                >
                                    <i class="bx bx-time-five"></i>
                                    <template v-if="!showingOlder">
                                        {{ t(':count older backups hidden', { count: expired_count }) }}
                                    </template>
                                    <template v-else>
                                        {{ t('Load More') }}
                                    </template>
                                </button>
                                <div v-else class="flex items-center gap-2 text-xs text-gray-400">
                                    <div class="h-4 w-4 animate-spin rounded-full border-2 border-gray-300 border-t-brand-500"></div>
                                    {{ t('Loading...') }}
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Disconnect Confirmation Modal -->
                <Teleport to="body">
                    <div v-if="showDisconnectConfirm" class="fixed inset-0 z-1200000 flex items-center justify-center bg-black/60 p-4 backdrop-blur-sm">
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
                    <div v-if="showRunConfirm" class="fixed inset-0 z-1200000 flex items-center justify-center bg-black/60 p-4 backdrop-blur-sm">
                        <div class="mx-4 w-full max-w-md rounded-2xl bg-white p-6 shadow-xl dark:bg-gray-900">
                            <h3 class="text-lg font-semibold text-gray-800 dark:text-white">{{ t('Start Backup?') }}</h3>
                            <p class="mt-2 text-sm text-gray-600 dark:text-gray-400">
                                {{ t('A new backup will be created and uploaded to Google Drive. Existing backups will not be overwritten. The process runs in the background.') }}
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
                    <div v-if="showFolderBrowser" class="fixed inset-0 z-1200000 flex items-center justify-center bg-black/60 p-4 backdrop-blur-sm">
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

                <!-- Backup Detail Modal -->
                <Teleport to="body">
                    <div v-if="showDetailModal && selectedRun" class="fixed inset-0 z-1200000 flex items-center justify-center bg-black/60 p-4 backdrop-blur-sm">
                        <div class="mx-4 flex w-full max-w-2xl flex-col rounded-2xl bg-white shadow-xl dark:bg-gray-900" style="max-height: 85vh">
                            <!-- Header -->
                            <div class="flex items-center justify-between border-b border-gray-200 px-6 py-4 dark:border-gray-800">
                                <h3 class="text-lg font-semibold text-gray-800 dark:text-white">
                                    {{ t('Backup Details') }}
                                </h3>
                                <button @click="showDetailModal = false" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-200">
                                    <i class="bx bx-x text-xl"></i>
                                </button>
                            </div>

                            <!-- Content (scrollable) -->
                            <div class="flex-1 overflow-y-auto px-6 py-4">
                                <!-- Metadata Grid -->
                                <div class="grid grid-cols-2 gap-3 text-sm">
                                    <div>
                                        <span class="text-gray-500 dark:text-gray-400">{{ t('Type') }}</span>
                                        <div class="mt-0.5">
                                            <span :class="['inline-flex rounded-full px-2 py-0.5 text-xs font-medium', typeBadge(selectedRun.type)]">
                                                {{ t(selectedRun.type) }}
                                            </span>
                                        </div>
                                    </div>
                                    <div>
                                        <span class="text-gray-500 dark:text-gray-400">{{ t('Status') }}</span>
                                        <div class="mt-0.5">
                                            <span :class="['inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium', statusBadge(selectedRun.status)]">
                                                <i :class="statusIcon(selectedRun.status)" class="text-[10px]"></i>
                                                {{ t(selectedRun.status) }}
                                            </span>
                                        </div>
                                    </div>
                                    <div>
                                        <span class="text-gray-500 dark:text-gray-400">{{ t('Started') }}</span>
                                        <p class="mt-0.5 text-gray-800 dark:text-gray-200">{{ selectedRun.started_at || '-' }}</p>
                                    </div>
                                    <div>
                                        <span class="text-gray-500 dark:text-gray-400">{{ t('Finished') }}</span>
                                        <p class="mt-0.5 text-gray-800 dark:text-gray-200">{{ selectedRun.finished_at || '-' }}</p>
                                    </div>
                                    <div>
                                        <span class="text-gray-500 dark:text-gray-400">{{ t('Triggered By') }}</span>
                                        <p class="mt-0.5 text-gray-800 dark:text-gray-200">{{ selectedRun.triggered_by || t('System') }}</p>
                                    </div>
                                    <div>
                                        <span class="text-gray-500 dark:text-gray-400">{{ t('Size') }}</span>
                                        <p class="mt-0.5 text-gray-800 dark:text-gray-200">{{ selectedRun.file_size ? humanSize(selectedRun.file_size) : '-' }}</p>
                                    </div>
                                </div>

                                <!-- Error Message -->
                                <div v-if="selectedRun.error_message" class="mt-3 rounded-lg bg-red-50 p-3 text-sm text-red-700 dark:bg-red-500/10 dark:text-red-300">
                                    <i class="bx bx-error-circle mr-1"></i>
                                    {{ selectedRun.error_message }}
                                </div>

                                <!-- Expired notice (retention deleted) -->
                                <div v-if="selectedRun.is_expired" class="mt-5 rounded-lg border border-orange-200 bg-orange-50 p-4 dark:border-orange-500/30 dark:bg-orange-500/10">
                                    <div class="flex items-start gap-3">
                                        <i class="bx bx-time-five mt-0.5 text-xl text-orange-500 dark:text-orange-400"></i>
                                        <div>
                                            <p class="text-sm font-semibold text-orange-800 dark:text-orange-200">
                                                {{ t('This backup no longer exists on Google Drive.') }}
                                            </p>
                                            <p class="mt-1 text-xs text-orange-700 dark:text-orange-300">
                                                {{ t('Deleted automatically after :days day retention period.', { days: settings.backup_retention_days }) }}
                                            </p>
                                        </div>
                                    </div>
                                </div>

                                <!-- File Browser Section -->
                                <div v-else-if="selectedRun.status === 'completed' && settings.drive_folder_id" class="mt-5 border-t border-gray-200 pt-4 dark:border-gray-800">
                                    <div class="mb-3 flex items-center justify-between">
                                        <h4 class="text-sm font-semibold text-gray-800 dark:text-white/90">
                                            <i class="bx bx-folder-open mr-1"></i>
                                            {{ t('Files on Drive') }}
                                        </h4>
                                    </div>

                                    <!-- File Breadcrumbs -->
                                    <div v-if="fileBreadcrumbs.length > 0" class="mb-3 flex flex-wrap items-center gap-1 text-sm">
                                        <template v-for="(crumb, i) in fileBreadcrumbs" :key="i">
                                            <i v-if="i > 0" class="bx bx-chevron-right text-gray-400"></i>
                                            <button
                                                @click="navigateDriveBreadcrumb(crumb, i)"
                                                class="text-brand-500 hover:underline"
                                            >
                                                {{ crumb.name }}
                                            </button>
                                        </template>
                                    </div>

                                    <!-- Search input -->
                                    <div class="mb-3 relative">
                                        <i class="bx bx-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400"></i>
                                        <input
                                            v-model="fileSearchQuery"
                                            type="text"
                                            :placeholder="t('Search files...')"
                                            class="h-8 w-full rounded-lg border border-gray-300 bg-transparent pl-8 pr-3 text-sm text-gray-700 focus:border-brand-500 focus:outline-none dark:border-gray-700 dark:bg-gray-800 dark:text-gray-300"
                                        />
                                    </div>

                                    <!-- File List -->
                                    <div class="max-h-64 overflow-y-auto rounded-lg border border-gray-200 dark:border-gray-700">
                                        <div v-if="driveFilesLoading" class="flex items-center justify-center py-8">
                                            <div class="h-6 w-6 animate-spin rounded-full border-2 border-gray-300 border-t-brand-500"></div>
                                        </div>
                                        <div v-else-if="filteredDriveFiles.length === 0" class="py-6 text-center text-sm text-gray-500 dark:text-gray-400">
                                            <i class="bx bx-folder-open text-2xl"></i>
                                            <p class="mt-1">{{ fileSearchQuery ? t('No matching records found.') : t('No files found') }}</p>
                                        </div>
                                        <table v-else class="w-full text-sm">
                                            <tbody>
                                                <tr
                                                    v-for="file in filteredDriveFiles"
                                                    :key="file.id"
                                                    :class="[
                                                        'border-b border-gray-100 last:border-0 dark:border-gray-800',
                                                        isFolder(file) ? 'cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-800/50' : '',
                                                    ]"
                                                    @click="isFolder(file) ? navigateDriveFolder(file) : undefined"
                                                >
                                                    <td class="w-8 py-2.5 pl-3">
                                                        <i :class="fileIcon(file)" class="text-lg"></i>
                                                    </td>
                                                    <td class="py-2.5 pl-2 text-gray-700 dark:text-gray-300">
                                                        {{ file.name }}
                                                    </td>
                                                    <td class="py-2.5 pr-3 text-right text-xs text-gray-500 dark:text-gray-400">
                                                        {{ !isFolder(file) && file.size ? humanSize(file.size) : '' }}
                                                    </td>
                                                    <td class="py-2.5 pr-3 text-right text-xs text-gray-500 dark:text-gray-400">
                                                        {{ formatDate(file.modifiedTime) }}
                                                    </td>
                                                    <td class="w-10 py-2.5 pr-3 text-right">
                                                        <a
                                                            v-if="!isFolder(file)"
                                                            :href="route('backups.drive-download', { fileId: file.id })"
                                                            class="inline-flex items-center text-brand-500 transition hover:text-brand-600"
                                                            :title="t('Download')"
                                                            @click.stop
                                                        >
                                                            <i class="bx bx-download text-lg"></i>
                                                        </a>
                                                        <i v-else class="bx bx-chevron-right text-gray-400"></i>
                                                    </td>
                                                </tr>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>

                            <!-- Footer -->
                            <div class="border-t border-gray-200 px-6 py-3 dark:border-gray-800">
                                <button
                                    @click="showDetailModal = false"
                                    class="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 transition hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-gray-800"
                                >
                                    {{ t('Close') }}
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
import { useI18n } from '@/Composables/useI18n';
import AdminLayout from '@/Components/Layout/AdminLayout.vue';
import PageBreadcrumb from '@/Components/Common/PageBreadcrumb.vue';
import SidebarProvider from '@/Components/Layout/SidebarProvider.vue';
import ThemeProvider from '@/Components/Layout/ThemeProvider.vue';
import Toast from '@/Components/UI/Toast.vue';
import StorageDonutChart from '@/Components/Backups/StorageDonutChart.vue';

const { t } = useI18n();

interface BackupSettings {
    is_connected: boolean;
    connected_email: string | null;
    drive_folder_id: string | null;
    drive_folder_name: string | null;
    is_enabled: boolean;
    backup_retention_days: number;
    backup_schedule: string;
    backup_time: string;
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
    drive_file_id: string | null;
    started_at: string | null;
    finished_at: string | null;
    triggered_by: string | null;
    is_expired?: boolean;
}

interface DriveFolder {
    id: string;
    name: string;
}

interface DriveFileItem {
    id: string;
    name: string;
    mimeType: string;
    size: number | null;
    modifiedTime: string | null;
}

const props = defineProps<{
    settings: BackupSettings;
    recent_runs: BackupRunItem[];
    expired_count: number;
}>();

// Forms
const settingsForm = useForm({
    is_enabled: props.settings.is_enabled,
    backup_retention_days: props.settings.backup_retention_days,
    backup_schedule: props.settings.backup_schedule,
    backup_time: props.settings.backup_time,
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
const currentFileName = ref('');
const currentFilePercent = ref(0);
const itemsDone = ref(0);
const itemsTotal = ref(0);
const cancelProcessing = ref(false);
const restartProcessing = ref(false);

// Storage Quota State
const storageQuota = ref<{ usage: number; limit: number | null } | null>(null);
const storageQuotaLoading = ref(false);

// Detail Modal State
const showDetailModal = ref(false);
const selectedRun = ref<BackupRunItem | null>(null);
const driveFiles = ref<DriveFileItem[]>([]);
const driveFilesLoading = ref(false);
const fileBreadcrumbs = ref<{ id: string; name: string }[]>([]);
const currentDriveParentId = ref<string | null>(null);
const fileSearchQuery = ref('');

// Older runs (beyond retention)
const olderRuns = ref<BackupRunItem[]>([]);
const olderPage = ref(0);
const hasMoreOlder = ref(false);
const olderLoading = ref(false);
const showingOlder = ref(false);

// Computed
const activeRun = computed(() => {
    return props.recent_runs.find((r) => r.status === 'uploading' || r.status === 'running');
});

const filteredDriveFiles = computed(() => {
    if (!fileSearchQuery.value.trim()) return driveFiles.value;
    const q = fileSearchQuery.value.toLowerCase();
    return driveFiles.value.filter((f) => f.name.toLowerCase().includes(q));
});

// Echo/Reverb listener
let echoChannel: any = null;

onMounted(() => {
    if (typeof window.Echo !== 'undefined') {
        echoChannel = window.Echo.private('admin').listen('BackupProgress', (e: any) => {
            let run = props.recent_runs.find((r) => r.id === e.backup_run_id);
            if (!run) {
                run = {
                    id: e.backup_run_id,
                    type: 'manual',
                    status: e.status,
                    file_name: null,
                    file_size: null,
                    progress_percent: e.percent,
                    error_message: null,
                    drive_file_id: null,
                    started_at: new Date().toISOString(),
                    finished_at: null,
                    triggered_by: null,
                };
                props.recent_runs.unshift(run);
            }
            run.progress_percent = e.percent;
            run.status = e.status;
            activeRunMessage.value = e.message;
            currentFileName.value = e.current_file_name || '';
            currentFilePercent.value = e.current_file_percent || 0;
            itemsDone.value = e.items_done || 0;
            itemsTotal.value = e.items_total || 0;

            if (e.status === 'completed' || e.status === 'failed' || e.status === 'cancelled') {
                currentFileName.value = '';
                currentFilePercent.value = 0;
                itemsDone.value = 0;
                itemsTotal.value = 0;
                setTimeout(() => router.reload(), 1000);
            }
        });
    }

    // Load storage quota if connected
    if (props.settings.is_connected) {
        storageQuotaLoading.value = true;
        fetch(route('backups.drive-quota'), {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        })
            .then((r) => r.ok ? r.json() : null)
            .then((data) => {
                if (data && !data.error) storageQuota.value = data;
            })
            .catch(() => {})
            .finally(() => { storageQuotaLoading.value = false; });
    }
});

onUnmounted(() => {
    if (echoChannel) {
        echoChannel.stopListening('BackupProgress');
    }
});

// Backup control actions
function cancelBackup() {
    if (!activeRun.value) return;
    cancelProcessing.value = true;
    router.post(route('backups.cancel', activeRun.value.id), {}, {
        preserveScroll: true,
        onFinish: () => {
            cancelProcessing.value = false;
        },
    });
}

function restartBackup() {
    restartProcessing.value = true;
    router.post(route('backups.restart'), {}, {
        preserveScroll: true,
        onFinish: () => {
            restartProcessing.value = false;
        },
    });
}

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

// Older runs loader
async function loadOlderRuns() {
    if (olderLoading.value) return;
    olderLoading.value = true;
    showingOlder.value = true;
    try {
        const nextPage = olderPage.value + 1;
        const resp = await fetch(route('backups.history') + `?page=${nextPage}`, {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        });
        const data = await resp.json();
        olderRuns.value.push(...data.data);
        olderPage.value = data.current_page;
        hasMoreOlder.value = data.current_page < data.last_page;
    } catch {
        // silent
    } finally {
        olderLoading.value = false;
    }
}

// Detail Modal
async function openBackupDetail(run: BackupRunItem) {
    selectedRun.value = run;
    showDetailModal.value = true;
    driveFiles.value = [];
    fileBreadcrumbs.value = [];
    fileSearchQuery.value = '';

    if (run.is_expired) return;

    if (run.status !== 'completed' || !props.settings.drive_folder_id) return;

    driveFilesLoading.value = true;
    try {
        let targetFolderId: string | null = null;

        if (run.drive_file_id) {
            targetFolderId = run.drive_file_id;
        } else {
            const dateMatch = run.file_name?.match(/\(([^)]+)\)/);
            if (dateMatch) {
                const resp = await fetch(
                    route('backups.drive-files') + `?parent_id=${props.settings.drive_folder_id}`,
                    { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } },
                );
                const data = await resp.json();
                const dateFolder = (data.files || []).find(
                    (f: DriveFileItem) => f.name === dateMatch[1] && f.mimeType === 'application/vnd.google-apps.folder',
                );
                if (dateFolder) targetFolderId = dateFolder.id;
            }
        }

        if (targetFolderId) {
            fileBreadcrumbs.value = [
                { id: props.settings.drive_folder_id!, name: props.settings.drive_folder_name || 'Backups' },
            ];
            if (!run.drive_file_id) {
                const dateMatch = run.file_name?.match(/\(([^)]+)\)/);
                if (dateMatch) fileBreadcrumbs.value.push({ id: targetFolderId, name: dateMatch[1] });
            } else {
                const dateMatch = run.file_name?.match(/\(([^)]+)\)/);
                fileBreadcrumbs.value.push({ id: targetFolderId, name: dateMatch?.[1] || 'Backup' });
            }
            currentDriveParentId.value = targetFolderId;
            await loadDriveFiles(targetFolderId);
        }
    } catch {
        driveFiles.value = [];
    } finally {
        driveFilesLoading.value = false;
    }
}

async function loadDriveFiles(parentId: string) {
    driveFilesLoading.value = true;
    try {
        const resp = await fetch(
            route('backups.drive-files') + `?parent_id=${parentId}`,
            { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } },
        );
        const data = await resp.json();
        driveFiles.value = data.files || [];
    } catch {
        driveFiles.value = [];
    } finally {
        driveFilesLoading.value = false;
    }
}

function navigateDriveFolder(file: DriveFileItem) {
    const existingIndex = fileBreadcrumbs.value.findIndex((c) => c.id === file.id);
    if (existingIndex >= 0) {
        fileBreadcrumbs.value = fileBreadcrumbs.value.slice(0, existingIndex + 1);
    } else {
        fileBreadcrumbs.value.push({ id: file.id, name: file.name });
    }
    currentDriveParentId.value = file.id;
    loadDriveFiles(file.id);
}

function navigateDriveBreadcrumb(crumb: { id: string; name: string }, index: number) {
    fileBreadcrumbs.value = fileBreadcrumbs.value.slice(0, index + 1);
    currentDriveParentId.value = crumb.id;
    loadDriveFiles(crumb.id);
}

function isFolder(file: DriveFileItem): boolean {
    return file.mimeType === 'application/vnd.google-apps.folder';
}

function fileIcon(file: DriveFileItem): string {
    if (isFolder(file)) return 'bx bx-folder text-yellow-500';
    if (file.mimeType?.includes('gzip') || file.mimeType?.includes('tar') || file.mimeType?.includes('zip') || file.mimeType?.includes('compressed'))
        return 'bx bx-archive text-orange-500';
    if (file.mimeType?.includes('sql')) return 'bx bx-data text-blue-500';
    return 'bx bx-file text-gray-400';
}

function formatDate(dateStr: string | null): string {
    if (!dateStr) return '-';
    try {
        return new Date(dateStr).toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' });
    } catch {
        return dateStr;
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
        case 'cancelled':
            return 'bg-orange-50 text-orange-700 dark:bg-orange-500/20 dark:text-orange-300';
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
        case 'cancelled':
            return 'bx bx-block';
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
