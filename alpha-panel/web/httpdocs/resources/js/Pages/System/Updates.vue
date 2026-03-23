<template>
    <Head :title="t('System Updates')" />
    <ThemeProvider>
        <SidebarProvider>
            <AdminLayout>
                <PageBreadcrumb :pageTitle="t('System Updates')" />
                <Toast />

                <div class="grid grid-cols-1 gap-4 md:gap-6">
                    <!-- Agent Status Banner -->
                    <div
                        :class="[
                            'rounded-2xl border p-5',
                            agent_healthy
                                ? 'border-success-300 bg-success-50 dark:border-success-500/30 dark:bg-success-500/10'
                                : 'border-red-300 bg-red-50 dark:border-red-500/30 dark:bg-red-500/10',
                        ]"
                    >
                        <div class="flex items-center gap-3">
                            <div
                                :class="[
                                    'flex h-10 w-10 items-center justify-center rounded-xl',
                                    agent_healthy
                                        ? 'bg-success-100 text-success-600 dark:bg-success-500/20 dark:text-success-300'
                                        : 'bg-red-100 text-red-600 dark:bg-red-500/20 dark:text-red-300',
                                ]"
                            >
                                <i :class="agent_healthy ? 'fa-solid fa-plug-circle-check' : 'fa-solid fa-plug-circle-xmark'" class="text-lg"></i>
                            </div>
                            <div>
                                <h4 :class="agent_healthy ? 'font-semibold text-success-800 dark:text-success-200' : 'font-semibold text-red-800 dark:text-red-200'">
                                    {{ agent_healthy ? t('Update Agent: Connected') : t('Update Agent: Offline') }}
                                </h4>
                                <p v-if="!agent_healthy" class="mt-0.5 text-sm text-red-700 dark:text-red-300">
                                    {{ t('Update features are unavailable while the agent is offline.') }}
                                </p>
                            </div>
                        </div>
                    </div>

                    <!-- Current Version Card -->
                    <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
                        <div class="mb-4 flex items-center justify-between">
                            <h4 class="text-sm font-semibold text-gray-800 dark:text-white/90">
                                <i class="fa-solid fa-info-circle mr-1"></i>
                                {{ t('Current Version') }}
                            </h4>
                            <button
                                @click="checkForUpdates"
                                :disabled="checkLoading || !agent_healthy"
                                class="inline-flex items-center gap-2 rounded-lg bg-brand-500 px-4 py-2 text-sm font-medium text-white transition hover:bg-brand-600 disabled:opacity-50"
                            >
                                <i :class="checkLoading ? 'bx bx-loader-alt bx-spin' : 'bx bx-refresh'" class="text-base"></i>
                                {{ t('Check for Updates') }}
                            </button>
                        </div>

                        <div class="flex flex-wrap items-center gap-3">
                            <span class="text-2xl font-semibold text-gray-800 dark:text-white/90">
                                {{ current_version.version }}
                            </span>
                            <span
                                :class="[
                                    'inline-flex rounded-full px-2.5 py-0.5 text-xs font-semibold',
                                    channelBadge(current_version.channel),
                                ]"
                            >
                                {{ current_version.channel }}
                            </span>
                            <span class="text-xs text-gray-500 dark:text-gray-400">
                                PHP {{ current_version.min_php }}+
                            </span>
                        </div>

                        <!-- Service Versions -->
                        <div v-if="serviceEntries.length > 0" class="mt-5 border-t border-gray-200 pt-4 dark:border-gray-700">
                            <h5 class="mb-3 text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                {{ t('Service Versions') }}
                            </h5>
                            <div class="grid grid-cols-2 gap-2 sm:grid-cols-3 md:grid-cols-4">
                                <div
                                    v-for="[name, version] in serviceEntries"
                                    :key="name"
                                    class="flex items-center gap-2 rounded-lg bg-gray-50 px-3 py-2 dark:bg-gray-800/50"
                                >
                                    <i :class="serviceIcon(name)" class="text-sm text-gray-500 dark:text-gray-400"></i>
                                    <div class="min-w-0">
                                        <p class="truncate text-xs font-medium text-gray-700 dark:text-gray-300">{{ name }}</p>
                                        <p class="text-xs text-gray-500 dark:text-gray-400">{{ version }}</p>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Check Results -->
                        <div v-if="checkResult" class="mt-5 border-t border-gray-200 pt-4 dark:border-gray-700">
                            <div v-if="!checkResult.panel_update && !checkResult.mysql_update" class="flex items-center gap-2 text-success-600 dark:text-success-400">
                                <i class="bx bx-check-circle text-lg"></i>
                                <span class="text-sm font-medium">{{ t('Everything is up to date.') }}</span>
                            </div>
                            <div v-else class="space-y-2">
                                <div v-if="checkResult.panel_update" class="flex items-center gap-2 text-brand-600 dark:text-brand-400">
                                    <i class="bx bx-up-arrow-circle text-lg"></i>
                                    <span class="text-sm font-medium">{{ t('Panel update available') }}: {{ checkResult.panel_update.latest_version }}</span>
                                </div>
                                <div v-if="checkResult.mysql_update" class="flex items-center gap-2 text-warning-600 dark:text-warning-400">
                                    <i class="bx bx-up-arrow-circle text-lg"></i>
                                    <span class="text-sm font-medium">{{ t('MySQL upgrade available') }}: {{ checkResult.mysql_update.target_version }}</span>
                                </div>
                            </div>
                        </div>

                        <div v-if="checkError" class="mt-4 rounded-lg bg-red-50 p-3 text-sm text-red-700 dark:bg-red-500/10 dark:text-red-300">
                            <i class="bx bx-error-circle mr-1"></i>
                            {{ checkError }}
                        </div>
                    </div>

                    <!-- Panel Update Card -->
                    <div
                        v-if="checkResult?.panel_update"
                        class="rounded-2xl border border-brand-200 bg-white p-5 dark:border-brand-500/30 dark:bg-white/[0.03]"
                    >
                        <div class="mb-4 flex items-center justify-between">
                            <h4 class="text-sm font-semibold text-gray-800 dark:text-white/90">
                                <i class="fa-solid fa-arrow-up-from-bracket mr-1 text-brand-500"></i>
                                {{ t('Panel Update') }}
                            </h4>
                        </div>

                        <div class="flex flex-wrap items-center gap-3">
                            <span class="text-sm text-gray-700 dark:text-gray-300">
                                {{ current_version.version }}
                            </span>
                            <i class="bx bx-right-arrow-alt text-gray-400"></i>
                            <span class="text-sm font-semibold text-brand-600 dark:text-brand-400">
                                {{ checkResult.panel_update.latest_version }}
                            </span>
                        </div>

                        <!-- Release Notes -->
                        <div v-if="checkResult.panel_update.release_notes" class="mt-4 max-h-48 overflow-y-auto rounded-lg bg-gray-50 p-4 text-sm text-gray-700 dark:bg-gray-800/50 dark:text-gray-300">
                            <h5 class="mb-2 text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                {{ t('Release Notes') }}
                            </h5>
                            <div class="whitespace-pre-wrap">{{ checkResult.panel_update.release_notes }}</div>
                        </div>

                        <!-- Update Progress -->
                        <div v-if="panelUpdateProgress.active" class="mt-4">
                            <div class="flex items-center justify-between">
                                <p class="text-sm font-medium text-brand-800 dark:text-brand-200">
                                    {{ panelUpdateProgress.message || t('Updating panel...') }}
                                </p>
                                <span class="text-sm font-semibold text-brand-700 dark:text-brand-300">
                                    {{ panelUpdateProgress.percent }}%
                                </span>
                            </div>
                            <div class="mt-2 h-2 overflow-hidden rounded-full bg-brand-200 dark:bg-brand-800">
                                <div
                                    class="h-full rounded-full bg-brand-500 transition-all duration-300"
                                    :style="{ width: panelUpdateProgress.percent + '%' }"
                                ></div>
                            </div>
                        </div>

                        <div v-else class="mt-4">
                            <button
                                @click="showPanelUpdateConfirm = true"
                                :disabled="!agent_healthy"
                                class="inline-flex items-center gap-2 rounded-lg bg-red-600 px-4 py-2 text-sm font-medium text-white transition hover:bg-red-700 disabled:opacity-50"
                            >
                                <i class="fa-solid fa-arrow-up-from-bracket"></i>
                                {{ t('Update Panel') }}
                            </button>
                        </div>
                    </div>

                    <!-- MySQL Upgrade Card -->
                    <div
                        v-if="checkResult?.mysql_update"
                        class="rounded-2xl border border-warning-200 bg-white p-5 dark:border-warning-500/30 dark:bg-white/[0.03]"
                    >
                        <div class="mb-4 flex items-center gap-3">
                            <h4 class="text-sm font-semibold text-gray-800 dark:text-white/90">
                                <i class="lni lni-mysql mr-1 text-warning-500"></i>
                                {{ t('MySQL Upgrade') }}
                            </h4>
                        </div>

                        <!-- Warning Banner -->
                        <div class="mb-4 rounded-xl border border-warning-300 bg-warning-50 p-4 dark:border-warning-500/30 dark:bg-warning-500/10">
                            <div class="flex items-start gap-3">
                                <i class="bx bx-error-circle mt-0.5 text-xl text-warning-600 dark:text-warning-400"></i>
                                <div>
                                    <h5 class="font-semibold text-warning-800 dark:text-warning-200">
                                        {{ checkResult.mysql_update.is_major ? t('Major MySQL upgrade requires downtime') : t('MySQL minor update available') }}
                                    </h5>
                                    <p class="mt-1 text-sm text-warning-700 dark:text-warning-300">
                                        {{ checkResult.mysql_update.is_major
                                            ? t('This is a major version upgrade. Your database will be unavailable during the migration process. Ensure you have a recent backup before proceeding.')
                                            : t('A minor MySQL version update is available. This update includes bug fixes and security patches. Your database will be briefly unavailable during the update.')
                                        }}
                                    </p>
                                </div>
                            </div>
                        </div>

                        <div class="flex flex-wrap items-center gap-3">
                            <span class="text-sm text-gray-700 dark:text-gray-300">
                                MySQL {{ checkResult.mysql_update.current_version }}
                            </span>
                            <i class="bx bx-right-arrow-alt text-gray-400"></i>
                            <span class="text-sm font-semibold text-warning-600 dark:text-warning-400">
                                MySQL {{ checkResult.mysql_update.target_version }}
                            </span>
                        </div>

                        <!-- MySQL Upgrade Progress -->
                        <div v-if="mysqlUpgradeProgress.active" class="mt-4">
                            <div class="flex items-center justify-between">
                                <p class="text-sm font-medium text-warning-800 dark:text-warning-200">
                                    {{ mysqlUpgradeProgress.message || t('Processing MySQL upgrade...') }}
                                </p>
                                <span class="text-sm font-semibold text-warning-700 dark:text-warning-300">
                                    {{ mysqlUpgradeProgress.percent }}%
                                </span>
                            </div>
                            <div class="mt-2 h-2 overflow-hidden rounded-full bg-warning-200 dark:bg-warning-800">
                                <div
                                    class="h-full rounded-full bg-warning-500 transition-all duration-300"
                                    :style="{ width: mysqlUpgradeProgress.percent + '%' }"
                                ></div>
                            </div>
                        </div>

                        <!-- MySQL Upgrade Steps -->
                        <div v-else class="mt-4 flex flex-wrap items-center gap-3">
                            <template v-if="mysqlStage === 'idle'">
                                <button
                                    @click="prepareMysqlUpgrade"
                                    :disabled="!agent_healthy || mysqlPrepareProcessing"
                                    class="inline-flex items-center gap-2 rounded-lg bg-warning-500 px-4 py-2 text-sm font-medium text-white transition hover:bg-warning-600 disabled:opacity-50"
                                >
                                    <i :class="mysqlPrepareProcessing ? 'bx bx-loader-alt bx-spin' : 'bx bx-wrench'" class="text-base"></i>
                                    {{ t('Prepare Upgrade') }}
                                </button>
                            </template>

                            <template v-else-if="mysqlStage === 'prepared'">
                                <div class="w-full rounded-lg bg-success-50 p-3 text-sm text-success-700 dark:bg-success-500/10 dark:text-success-300">
                                    <i class="bx bx-check-circle mr-1"></i>
                                    {{ t('Test environment ready. Verify your data via phpMyAdmin before applying.') }}
                                </div>
                                <a
                                    v-if="pmaUrl"
                                    :href="pmaUrl"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    class="inline-flex items-center gap-2 rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 transition hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-gray-800"
                                >
                                    <i class="lni lni-mysql"></i>
                                    {{ t('Test via phpMyAdmin') }}
                                </a>
                                <button
                                    @click="showMysqlApplyConfirm = true"
                                    :disabled="!agent_healthy"
                                    class="inline-flex items-center gap-2 rounded-lg bg-red-600 px-4 py-2 text-sm font-medium text-white transition hover:bg-red-700 disabled:opacity-50"
                                >
                                    <i class="fa-solid fa-database"></i>
                                    {{ t('Apply Upgrade') }}
                                </button>
                            </template>

                            <template v-else-if="mysqlStage === 'applied'">
                                <button
                                    @click="showMysqlRollbackConfirm = true"
                                    :disabled="!agent_healthy"
                                    class="inline-flex items-center gap-2 rounded-lg border border-red-300 px-4 py-2 text-sm font-medium text-red-600 transition hover:bg-red-50 dark:border-red-500/30 dark:text-red-400 dark:hover:bg-red-500/10"
                                >
                                    <i class="bx bx-undo"></i>
                                    {{ t('Rollback') }}
                                </button>
                                <button
                                    @click="showMysqlDeleteBackupConfirm = true"
                                    :disabled="!agent_healthy"
                                    class="inline-flex items-center gap-2 rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 transition hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-gray-800"
                                >
                                    <i class="bx bx-trash"></i>
                                    {{ t('Delete Backup') }}
                                </button>
                            </template>
                        </div>
                    </div>

                    <!-- Update History Table -->
                    <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
                        <div class="mb-4 flex items-center justify-between">
                            <h4 class="text-sm font-semibold text-gray-800 dark:text-white/90">
                                <i class="bx bx-history mr-1"></i>
                                {{ t('Update History') }}
                            </h4>
                            <button
                                @click="refreshPage"
                                class="inline-flex items-center gap-1 text-xs text-gray-500 transition hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200"
                            >
                                <i class="bx bx-refresh"></i>
                                {{ t('Refresh') }}
                            </button>
                        </div>

                        <div v-if="recent_updates.length === 0" class="py-8 text-center text-gray-500 dark:text-gray-400">
                            <i class="bx bx-package text-4xl"></i>
                            <p class="mt-2 text-sm">{{ t('No updates yet') }}</p>
                        </div>

                        <div v-else class="overflow-x-auto">
                            <table class="w-full min-w-[700px] text-sm">
                                <thead>
                                    <tr class="border-b border-gray-200 text-left text-xs uppercase text-gray-500 dark:border-gray-800 dark:text-gray-400">
                                        <th class="pb-3 pr-3">{{ t('Type') }}</th>
                                        <th class="pb-3 pr-3">{{ t('Status') }}</th>
                                        <th class="pb-3 pr-3">{{ t('Version') }}</th>
                                        <th class="pb-3 pr-3">{{ t('Message') }}</th>
                                        <th class="pb-3 pr-3">{{ t('Triggered By') }}</th>
                                        <th class="pb-3 pr-3">{{ t('Started') }}</th>
                                        <th class="pb-3 pr-3">{{ t('Finished') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr
                                        v-for="update in recent_updates"
                                        :key="update.id"
                                        class="border-b border-gray-100 last:border-0 dark:border-gray-800"
                                    >
                                        <td class="py-3 pr-3">
                                            <span
                                                :class="[
                                                    'inline-flex rounded-full px-2 py-0.5 text-xs font-medium',
                                                    typeBadge(update.type.value),
                                                ]"
                                            >
                                                {{ update.type.label || update.type.value }}
                                            </span>
                                        </td>
                                        <td class="py-3 pr-3">
                                            <span
                                                :class="[
                                                    'inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium',
                                                    statusBadge(update.status.value),
                                                ]"
                                            >
                                                <i :class="statusIcon(update.status.value)" class="text-[10px]"></i>
                                                {{ update.status.label || update.status.value }}
                                            </span>
                                        </td>
                                        <td class="py-3 pr-3 text-gray-700 dark:text-gray-300">
                                            <template v-if="update.from_version || update.to_version">
                                                {{ update.from_version || '?' }}
                                                <i class="bx bx-right-arrow-alt mx-1 text-gray-400"></i>
                                                {{ update.to_version || '?' }}
                                            </template>
                                            <span v-else class="text-gray-400">-</span>
                                        </td>
                                        <td class="max-w-[200px] truncate py-3 pr-3 text-gray-500 dark:text-gray-400" :title="update.message || ''">
                                            {{ update.message || '-' }}
                                        </td>
                                        <td class="py-3 pr-3 text-gray-500 dark:text-gray-400">
                                            {{ update.triggered_by || t('System') }}
                                        </td>
                                        <td class="py-3 pr-3 text-gray-500 dark:text-gray-400">
                                            {{ formatDate(update.started_at) }}
                                        </td>
                                        <td class="py-3 pr-3 text-gray-500 dark:text-gray-400">
                                            {{ formatDate(update.finished_at) }}
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Panel Update Confirmation Modal -->
                <Teleport to="body">
                    <div v-if="showPanelUpdateConfirm" class="fixed inset-0 z-[1200000] flex items-center justify-center bg-black/60 p-4 backdrop-blur-sm">
                        <div class="mx-4 w-full max-w-md rounded-2xl bg-white p-6 shadow-xl dark:bg-gray-900">
                            <h3 class="text-lg font-semibold text-gray-800 dark:text-white">{{ t('Update Panel?') }}</h3>
                            <p class="mt-2 text-sm text-gray-600 dark:text-gray-400">
                                {{ t('The panel will be updated from :from to :to. The panel may be briefly unavailable during the update.', { from: current_version.version, to: checkResult?.panel_update?.latest_version || '' }) }}
                            </p>
                            <div class="mt-4 flex justify-end gap-3">
                                <button
                                    @click="showPanelUpdateConfirm = false"
                                    class="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 transition hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-gray-800"
                                >
                                    {{ t('Cancel') }}
                                </button>
                                <button
                                    @click="startPanelUpdate"
                                    :disabled="panelUpdateProcessing"
                                    class="rounded-lg bg-red-600 px-4 py-2 text-sm font-medium text-white transition hover:bg-red-700 disabled:opacity-50"
                                >
                                    <i :class="panelUpdateProcessing ? 'bx bx-loader-alt bx-spin mr-1' : 'fa-solid fa-arrow-up-from-bracket mr-1'"></i>
                                    {{ t('Update Now') }}
                                </button>
                            </div>
                        </div>
                    </div>
                </Teleport>

                <!-- MySQL Apply Confirmation Modal -->
                <Teleport to="body">
                    <div v-if="showMysqlApplyConfirm" class="fixed inset-0 z-[1200000] flex items-center justify-center bg-black/60 p-4 backdrop-blur-sm">
                        <div class="mx-4 w-full max-w-md rounded-2xl bg-white p-6 shadow-xl dark:bg-gray-900">
                            <h3 class="text-lg font-semibold text-red-600 dark:text-red-400">
                                <i class="bx bx-error mr-1"></i>
                                {{ t('Apply MySQL Upgrade?') }}
                            </h3>
                            <p class="mt-2 text-sm text-gray-600 dark:text-gray-400">
                                {{ t('This will upgrade MySQL from :from to :to. The database will be unavailable during this process. Make sure you have verified the test environment and have a current backup.', { from: checkResult?.mysql_update?.current_version || '', to: checkResult?.mysql_update?.target_version || '' }) }}
                            </p>
                            <div class="mt-4 flex justify-end gap-3">
                                <button
                                    @click="showMysqlApplyConfirm = false"
                                    class="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 transition hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-gray-800"
                                >
                                    {{ t('Cancel') }}
                                </button>
                                <button
                                    @click="applyMysqlUpgrade"
                                    :disabled="mysqlApplyProcessing"
                                    class="rounded-lg bg-red-600 px-4 py-2 text-sm font-medium text-white transition hover:bg-red-700 disabled:opacity-50"
                                >
                                    <i :class="mysqlApplyProcessing ? 'bx bx-loader-alt bx-spin mr-1' : 'fa-solid fa-database mr-1'"></i>
                                    {{ t('Apply Upgrade') }}
                                </button>
                            </div>
                        </div>
                    </div>
                </Teleport>

                <!-- MySQL Rollback Confirmation Modal -->
                <Teleport to="body">
                    <div v-if="showMysqlRollbackConfirm" class="fixed inset-0 z-[1200000] flex items-center justify-center bg-black/60 p-4 backdrop-blur-sm">
                        <div class="mx-4 w-full max-w-md rounded-2xl bg-white p-6 shadow-xl dark:bg-gray-900">
                            <h3 class="text-lg font-semibold text-gray-800 dark:text-white">{{ t('Rollback MySQL Upgrade?') }}</h3>
                            <p class="mt-2 text-sm text-gray-600 dark:text-gray-400">
                                {{ t('This will restore the previous MySQL version and data from the backup. The database will be unavailable during the rollback.') }}
                            </p>
                            <div class="mt-4 flex justify-end gap-3">
                                <button
                                    @click="showMysqlRollbackConfirm = false"
                                    class="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 transition hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-gray-800"
                                >
                                    {{ t('Cancel') }}
                                </button>
                                <button
                                    @click="rollbackMysqlUpgrade"
                                    :disabled="mysqlRollbackProcessing"
                                    class="rounded-lg bg-red-600 px-4 py-2 text-sm font-medium text-white transition hover:bg-red-700 disabled:opacity-50"
                                >
                                    <i :class="mysqlRollbackProcessing ? 'bx bx-loader-alt bx-spin mr-1' : 'bx bx-undo mr-1'"></i>
                                    {{ t('Rollback') }}
                                </button>
                            </div>
                        </div>
                    </div>
                </Teleport>

                <!-- MySQL Delete Backup Confirmation Modal -->
                <Teleport to="body">
                    <div v-if="showMysqlDeleteBackupConfirm" class="fixed inset-0 z-[1200000] flex items-center justify-center bg-black/60 p-4 backdrop-blur-sm">
                        <div class="mx-4 w-full max-w-md rounded-2xl bg-white p-6 shadow-xl dark:bg-gray-900">
                            <h3 class="text-lg font-semibold text-gray-800 dark:text-white">{{ t('Delete MySQL Backup?') }}</h3>
                            <p class="mt-2 text-sm text-gray-600 dark:text-gray-400">
                                {{ t('This will permanently delete the pre-upgrade MySQL backup. You will no longer be able to rollback. Only do this if the upgrade is confirmed stable.') }}
                            </p>
                            <div class="mt-4 flex justify-end gap-3">
                                <button
                                    @click="showMysqlDeleteBackupConfirm = false"
                                    class="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 transition hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-gray-800"
                                >
                                    {{ t('Cancel') }}
                                </button>
                                <button
                                    @click="deleteMysqlBackup"
                                    :disabled="mysqlDeleteBackupProcessing"
                                    class="rounded-lg bg-red-600 px-4 py-2 text-sm font-medium text-white transition hover:bg-red-700 disabled:opacity-50"
                                >
                                    <i :class="mysqlDeleteBackupProcessing ? 'bx bx-loader-alt bx-spin mr-1' : 'bx bx-trash mr-1'"></i>
                                    {{ t('Delete Backup') }}
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
import { Head, router } from '@inertiajs/vue3';
import { computed, onMounted, onUnmounted, ref } from 'vue';
import { useI18n } from '@/Composables/useI18n';
import AdminLayout from '@/Components/Layout/AdminLayout.vue';
import PageBreadcrumb from '@/Components/Common/PageBreadcrumb.vue';
import SidebarProvider from '@/Components/Layout/SidebarProvider.vue';
import ThemeProvider from '@/Components/Layout/ThemeProvider.vue';
import Toast from '@/Components/UI/Toast.vue';

const { t } = useI18n();

interface CurrentVersion {
    version: string;
    channel: string;
    min_php: string;
    services: Record<string, string>;
}

interface UpdateItem {
    id: number;
    type: { value: string; label?: string };
    status: { value: string; label?: string };
    from_version: string | null;
    to_version: string | null;
    progress_percent: number;
    message: string | null;
    error_message: string | null;
    triggered_by: string | null;
    started_at: string | null;
    finished_at: string | null;
}

interface CheckResultData {
    panel_update: {
        latest_version: string;
        release_notes: string | null;
        release_url: string | null;
    } | null;
    mysql_update: {
        current_version: string;
        target_version: string;
        is_major: boolean;
    } | null;
}

const props = defineProps<{
    current_version: CurrentVersion;
    agent_healthy: boolean;
    cached_check: CheckResultData | null;
    recent_updates: UpdateItem[];
}>();

const serviceEntries = computed(() => Object.entries(props.current_version.services || {}));

// Check for updates state
const checkLoading = ref(false);
const checkResult = ref<CheckResultData | null>(props.cached_check ?? null);
const checkError = ref<string | null>(null);

// Panel update state
const showPanelUpdateConfirm = ref(false);
const panelUpdateProcessing = ref(false);
const panelUpdateProgress = ref({ active: false, percent: 0, message: '' });

// MySQL upgrade state
const mysqlStage = ref<'idle' | 'prepared' | 'applied'>('idle');
const mysqlPrepareProcessing = ref(false);
const mysqlApplyProcessing = ref(false);
const mysqlRollbackProcessing = ref(false);
const mysqlDeleteBackupProcessing = ref(false);
const mysqlUpgradeProgress = ref({ active: false, percent: 0, message: '' });
const showMysqlApplyConfirm = ref(false);
const showMysqlRollbackConfirm = ref(false);
const showMysqlDeleteBackupConfirm = ref(false);
const pmaUrl = ref<string | null>(null);

// CSRF helper
function getCsrfToken(): string {
    return document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content || '';
}

// Check for updates
async function checkForUpdates() {
    checkLoading.value = true;
    checkError.value = null;
    checkResult.value = null;

    try {
        const response = await fetch(route('system.updates.check'), {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': getCsrfToken(),
            },
        });

        if (!response.ok) {
            throw new Error(t('Failed to check for updates. Please try again.'));
        }

        const data = await response.json();
        checkResult.value = data;

        if (data.pma_url) {
            pmaUrl.value = data.pma_url;
        }
    } catch (err: unknown) {
        checkError.value = err instanceof Error ? err.message : t('An unexpected error occurred.');
    } finally {
        checkLoading.value = false;
    }
}

// JSON POST helper (avoids Inertia expecting an Inertia response from async endpoints)
async function jsonPost(url: string, body: Record<string, unknown> = {}): Promise<any> {
    const response = await fetch(url, {
        method: 'POST',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-CSRF-TOKEN': getCsrfToken(),
        },
        body: JSON.stringify(body),
    });

    const data = await response.json();

    if (!response.ok) {
        throw new Error(data.error || data.message || t('An unexpected error occurred.'));
    }

    return data;
}

// Panel update
async function startPanelUpdate() {
    panelUpdateProcessing.value = true;
    showPanelUpdateConfirm.value = false;

    try {
        await jsonPost(route('system.updates.panel'));
        router.reload();
    } catch (err: unknown) {
        checkError.value = err instanceof Error ? err.message : t('An unexpected error occurred.');
    } finally {
        panelUpdateProcessing.value = false;
    }
}

// MySQL upgrade actions
async function prepareMysqlUpgrade() {
    mysqlPrepareProcessing.value = true;

    try {
        await jsonPost(route('system.updates.mysql.prepare'), {
            target_version: checkResult.value?.mysql_update?.target_version ?? '',
        });
        mysqlStage.value = 'prepared';
        router.reload();
    } catch (err: unknown) {
        checkError.value = err instanceof Error ? err.message : t('An unexpected error occurred.');
    } finally {
        mysqlPrepareProcessing.value = false;
    }
}

async function applyMysqlUpgrade() {
    mysqlApplyProcessing.value = true;
    showMysqlApplyConfirm.value = false;

    try {
        await jsonPost(route('system.updates.mysql.apply'));
        mysqlStage.value = 'applied';
        router.reload();
    } catch (err: unknown) {
        checkError.value = err instanceof Error ? err.message : t('An unexpected error occurred.');
    } finally {
        mysqlApplyProcessing.value = false;
    }
}

async function rollbackMysqlUpgrade() {
    mysqlRollbackProcessing.value = true;
    showMysqlRollbackConfirm.value = false;

    try {
        await jsonPost(route('system.updates.mysql.rollback'));
        mysqlStage.value = 'idle';
        router.reload();
    } catch (err: unknown) {
        checkError.value = err instanceof Error ? err.message : t('An unexpected error occurred.');
    } finally {
        mysqlRollbackProcessing.value = false;
    }
}

async function deleteMysqlBackup() {
    mysqlDeleteBackupProcessing.value = true;
    showMysqlDeleteBackupConfirm.value = false;

    try {
        await jsonPost(route('system.updates.mysql.cleanup'));
        mysqlStage.value = 'idle';
        router.reload();
    } catch (err: unknown) {
        checkError.value = err instanceof Error ? err.message : t('An unexpected error occurred.');
    } finally {
        mysqlDeleteBackupProcessing.value = false;
    }
}

function refreshPage() {
    router.reload();
}

// WebSocket listener
let echoChannel: any = null;

onMounted(() => {
    if (typeof window.Echo !== 'undefined') {
        echoChannel = window.Echo.private('admin').listen('.UpdateProgress', (e: any) => {
            // Update matching record in recent_updates
            const update = props.recent_updates.find((u) => u.id === e.update_id);
            if (update) {
                update.progress_percent = e.percent ?? update.progress_percent;
                update.status = e.status ? { value: e.status, label: e.status_label } : update.status;
                update.message = e.message ?? update.message;
            }

            // Update panel progress
            if (e.type === 'panel') {
                panelUpdateProgress.value = {
                    active: e.status === 'in_progress',
                    percent: e.percent ?? 0,
                    message: e.message ?? '',
                };
            }

            // Update mysql progress
            if (e.type === 'mysql') {
                mysqlUpgradeProgress.value = {
                    active: e.status === 'in_progress',
                    percent: e.percent ?? 0,
                    message: e.message ?? '',
                };

                if (e.stage) {
                    mysqlStage.value = e.stage;
                }
            }

            // Reload when completed or failed
            if (e.status === 'completed' || e.status === 'failed' || e.status === 'rolled_back') {
                panelUpdateProgress.value.active = false;
                mysqlUpgradeProgress.value.active = false;
                setTimeout(() => router.reload(), 1000);
            }
        });
    }
});

onUnmounted(() => {
    if (echoChannel) {
        echoChannel.stopListening('.UpdateProgress');
    }
});

// Helpers
function formatDate(dateStr: string | null): string {
    if (!dateStr) return '-';
    try {
        return new Date(dateStr).toLocaleDateString(undefined, {
            year: 'numeric',
            month: 'short',
            day: 'numeric',
            hour: '2-digit',
            minute: '2-digit',
        });
    } catch {
        return dateStr;
    }
}

function channelBadge(channel: string): string {
    switch (channel) {
        case 'stable':
            return 'bg-success-50 text-success-700 dark:bg-success-500/20 dark:text-success-300';
        case 'beta':
            return 'bg-warning-50 text-warning-700 dark:bg-warning-500/20 dark:text-warning-300';
        case 'dev':
        case 'nightly':
            return 'bg-red-50 text-red-700 dark:bg-red-500/20 dark:text-red-300';
        default:
            return 'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300';
    }
}

function statusBadge(status: string): string {
    switch (status) {
        case 'completed':
            return 'bg-success-50 text-success-700 dark:bg-success-500/20 dark:text-success-300';
        case 'in_progress':
            return 'bg-brand-50 text-brand-700 dark:bg-brand-500/20 dark:text-brand-300';
        case 'pending':
            return 'bg-yellow-50 text-yellow-700 dark:bg-yellow-500/20 dark:text-yellow-300';
        case 'failed':
            return 'bg-red-50 text-red-700 dark:bg-red-500/20 dark:text-red-300';
        case 'rolled_back':
            return 'bg-orange-50 text-orange-700 dark:bg-orange-500/20 dark:text-orange-300';
        default:
            return 'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300';
    }
}

function statusIcon(status: string): string {
    switch (status) {
        case 'completed':
            return 'bx bxs-check-circle';
        case 'in_progress':
            return 'bx bx-loader-alt bx-spin';
        case 'pending':
            return 'bx bx-time-five';
        case 'failed':
            return 'bx bxs-x-circle';
        case 'rolled_back':
            return 'bx bx-undo';
        default:
            return 'bx bx-circle';
    }
}

function typeBadge(type: string): string {
    switch (type) {
        case 'panel':
            return 'bg-brand-50 text-brand-700 dark:bg-brand-500/20 dark:text-brand-300';
        case 'mysql':
        case 'mysql_upgrade':
            return 'bg-orange-50 text-orange-700 dark:bg-orange-500/20 dark:text-orange-300';
        case 'service':
            return 'bg-blue-50 text-blue-700 dark:bg-blue-500/20 dark:text-blue-300';
        default:
            return 'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300';
    }
}

function serviceIcon(name: string): string {
    const lower = name.toLowerCase();
    if (lower.includes('mysql') || lower.includes('maria')) return 'lni lni-mysql';
    if (lower.includes('postgres')) return 'fa-solid fa-database';
    if (lower.includes('redis')) return 'fa-solid fa-bolt';
    if (lower.includes('php')) return 'fa-brands fa-php';
    if (lower.includes('caddy') || lower.includes('frankenphp')) return 'fa-solid fa-server';
    if (lower.includes('node')) return 'fa-brands fa-node-js';
    if (lower.includes('docker')) return 'fa-brands fa-docker';
    return 'fa-solid fa-cube';
}
</script>
