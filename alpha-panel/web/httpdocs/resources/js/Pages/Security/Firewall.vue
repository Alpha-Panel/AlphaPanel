<template>
    <Head :title="t('Firewall')" />
    <ThemeProvider>
        <SidebarProvider>
            <AdminLayout>
                <PageBreadcrumb :pageTitle="t('Firewall')" />
                <Toast />

                <div class="space-y-4">
                    <!-- Warning Banners -->
                    <div
                        v-for="(warning, index) in warnings"
                        :key="index"
                        :class="[
                            'flex items-center gap-3 rounded-2xl border p-4 text-sm',
                            warning.toLowerCase().includes('critical') || warning.toLowerCase().includes('danger')
                                ? 'border-error-300 bg-error-100 text-error-900 dark:border-error-800 dark:bg-error-950 dark:text-error-100'
                                : 'border-warning-300 bg-warning-100 text-warning-900 dark:border-warning-800 dark:bg-warning-950 dark:text-warning-100',
                        ]"
                    >
                        <i class="fa-solid fa-triangle-exclamation text-base"></i>
                        {{ warning }}
                    </div>

                    <!-- UFW Command Preview Panel -->
                    <div
                        v-if="showPreview"
                        class="rounded-2xl border border-gray-200 bg-gray-950 p-5 dark:border-gray-700"
                    >
                        <div class="mb-3 flex items-center justify-between">
                            <h3 class="text-sm font-semibold text-green-400">
                                <i class="fa-solid fa-terminal mr-1.5"></i>
                                {{ t('UFW Command Set') }}
                            </h3>
                            <div class="flex items-center gap-2">
                                <button
                                    type="button"
                                    class="inline-flex h-7 items-center justify-center rounded-md border border-gray-600 px-3 text-xs font-medium text-gray-300 hover:bg-gray-800"
                                    @click="copyPreview"
                                >
                                    <i :class="copySuccess ? 'fa-solid fa-check' : 'fa-regular fa-copy'" class="mr-1.5"></i>
                                    {{ copySuccess ? t('Copied!') : t('Copy') }}
                                </button>
                                <button
                                    type="button"
                                    class="inline-flex h-7 items-center justify-center rounded-md border border-gray-600 px-3 text-xs font-medium text-gray-300 hover:bg-gray-800"
                                    @click="showPreview = false; previewScript = ''"
                                >
                                    <i class="fa-solid fa-xmark mr-1.5"></i>
                                    {{ t('Close') }}
                                </button>
                            </div>
                        </div>
                        <pre class="max-h-96 overflow-auto rounded-lg bg-black/50 p-4 font-mono text-xs leading-6 text-green-400"><template v-for="(line, i) in previewScript.split('\n')" :key="i"><span class="select-none text-gray-600">{{ String(i + 1).padStart(2, ' ') }}  </span>{{ line }}
</template></pre>
                        <p class="mt-3 text-xs text-gray-500">
                            {{ t('Run these commands on your server via SSH or VM Console. Simply copy and paste them into the terminal.') }}
                        </p>
                        <div class="mt-3 rounded-lg border border-gray-700 bg-gray-900 p-3">
                            <p class="mb-2 text-xs font-semibold text-yellow-400">
                                <i class="fa-solid fa-circle-info mr-1"></i>
                                {{ t('Manual Application Instructions') }}
                            </p>
                            <ol class="list-inside list-decimal space-y-1 text-xs text-gray-400">
                                <li>{{ t('Copy the commands above using the Copy button') }}</li>
                                <li>{{ t('Connect to your server via SSH or VM Console') }}</li>
                                <li>{{ t('Paste and run the commands') }}</li>
                                <li>{{ t('Verify with: ufw status numbered') }}</li>
                            </ol>
                        </div>
                    </div>

                    <!-- Policy Cards -->
                    <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                        <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/3">
                            <p class="text-xs uppercase text-gray-500 dark:text-gray-400">{{ t('INPUT Default Policy') }}</p>
                            <div class="mt-2 flex items-center gap-3">
                                <span
                                    :class="[
                                        'rounded-full px-3 py-1 text-xs font-semibold',
                                        inputPolicy === 'ACCEPT'
                                            ? 'bg-success-100 text-success-700 dark:bg-success-900/30 dark:text-success-400'
                                            : 'bg-error-100 text-error-700 dark:bg-error-900/30 dark:text-error-400',
                                    ]"
                                >
                                    {{ inputPolicy }}
                                </span>
                                <button
                                    type="button"
                                    :disabled="policyLoading"
                                    class="inline-flex h-8 items-center justify-center rounded-lg border border-gray-300 px-3 text-xs font-medium text-gray-700 hover:bg-gray-100 disabled:cursor-not-allowed disabled:opacity-40 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-gray-800"
                                    @click="confirmPolicyChange('INPUT')"
                                >
                                    {{ t('Switch to :policy').replace(':policy', inputPolicy === 'ACCEPT' ? 'DROP' : 'ACCEPT') }}
                                </button>
                            </div>
                        </div>
                        <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/3">
                            <p class="text-xs uppercase text-gray-500 dark:text-gray-400">{{ t('OUTPUT Default Policy') }}</p>
                            <p class="mt-2">
                                <span
                                    :class="[
                                        'rounded-full px-3 py-1 text-xs font-semibold',
                                        outputPolicy === 'ACCEPT'
                                            ? 'bg-success-100 text-success-700 dark:bg-success-900/30 dark:text-success-400'
                                            : 'bg-error-100 text-error-700 dark:bg-error-900/30 dark:text-error-400',
                                    ]"
                                >
                                    {{ outputPolicy }}
                                </span>
                            </p>
                        </div>
                    </div>

                    <!-- Action Buttons -->
                    <div class="flex justify-end gap-2">
                        <button
                            type="button"
                            :disabled="previewLoading"
                            class="inline-flex h-9 items-center justify-center gap-2 rounded-lg border border-gray-300 px-4 text-sm font-medium text-gray-700 hover:bg-gray-100 disabled:cursor-not-allowed disabled:opacity-40 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-gray-800"
                            @click="loadPreview"
                        >
                            <i class="fa-solid fa-code"></i>
                            {{ t('Generate Script') }}
                        </button>
                        <button
                            type="button"
                            class="inline-flex h-9 items-center justify-center gap-2 rounded-lg bg-brand-500 px-4 text-sm font-medium text-white hover:bg-brand-600"
                            @click="openAddModal"
                        >
                            <i class="fa-solid fa-plus"></i>
                            {{ t('Add Rule') }}
                        </button>
                    </div>

                    <!-- INPUT Rules Table -->
                    <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/3">
                        <div class="mb-3 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                            <h3 class="text-sm font-semibold text-gray-800 dark:text-white/90">
                                <i class="fa-solid fa-arrow-right-to-bracket mr-1"></i>
                                {{ t('INPUT Rules') }}
                                <span v-if="inputRules.length > 0" class="ml-1 text-xs font-normal text-gray-500 dark:text-gray-400">
                                    ({{ inputRules.length }})
                                </span>
                            </h3>
                            <button
                                type="button"
                                class="inline-flex h-8 items-center justify-center rounded-lg border border-gray-300 px-3 text-xs font-medium text-gray-700 hover:bg-gray-100 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-gray-800"
                                @click="refreshData"
                            >
                                {{ t('Refresh') }}
                            </button>
                        </div>
                        <div class="overflow-auto">
                            <table class="w-full text-sm">
                                <thead>
                                    <tr class="border-b border-gray-200 text-left text-xs uppercase text-gray-500 dark:border-gray-800 dark:text-gray-400">
                                        <th class="w-6 pb-2"></th>
                                        <th class="pb-2">#</th>
                                        <th class="pb-2">{{ t('Action') }}</th>
                                        <th class="pb-2">{{ t('Protocol') }}</th>
                                        <th class="pb-2">{{ t('Source') }}</th>
                                        <th class="pb-2">{{ t('Port') }}</th>
                                        <th class="pb-2">{{ t('Comment') }}</th>
                                        <th class="pb-2">{{ t('Enabled') }}</th>
                                        <th class="pb-2">{{ t('Actions') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-if="inputRules.length === 0">
                                        <td colspan="9" class="py-4 text-center text-gray-500 dark:text-gray-400">{{ t('No INPUT rules.') }}</td>
                                    </tr>
                                    <tr
                                        v-for="(rule, index) in inputRules"
                                        :key="`input-${rule.id}`"
                                        draggable="true"
                                        :class="[
                                            'border-b border-gray-100 align-top last:border-0 dark:border-gray-800 transition-colors duration-150',
                                            !rule.enabled ? 'opacity-50' : '',
                                            dragOverId === `input-${rule.id}` ? 'bg-brand-50 dark:bg-brand-500/10' : '',
                                            dragSourceId === `input-${rule.id}` ? 'opacity-30' : '',
                                        ]"
                                        @dragstart="onDragStart($event, 'INPUT', index)"
                                        @dragover.prevent="onDragOver($event, 'INPUT', index, rule.id)"
                                        @dragleave="onDragLeave($event, rule.id, 'INPUT')"
                                        @drop.prevent="onDrop($event, 'INPUT', index)"
                                        @dragend="onDragEnd"
                                    >
                                        <td class="cursor-grab py-2 text-xs text-gray-400 active:cursor-grabbing dark:text-gray-500">
                                            <i class="fa-solid fa-grip-vertical"></i>
                                        </td>
                                        <td class="py-2 text-xs text-gray-700 dark:text-gray-300">{{ rule.position }}</td>
                                        <td class="py-2">
                                            <span :class="actionBadgeClass(rule.action)">{{ rule.action }}</span>
                                        </td>
                                        <td class="py-2 text-xs text-gray-700 dark:text-gray-300">{{ rule.protocol }}</td>
                                        <td class="py-2 font-mono text-xs text-gray-700 dark:text-gray-300">{{ formatSources(rule.sources) }}</td>
                                        <td class="py-2 text-xs text-gray-700 dark:text-gray-300">{{ formatPorts(rule.ports) }}</td>
                                        <td class="py-2 text-xs text-gray-500 dark:text-gray-400">{{ rule.comment ?? '-' }}</td>
                                        <td class="py-2">
                                            <button
                                                type="button"
                                                :disabled="toggleLoading === rule.id"
                                                class="relative inline-flex h-5 w-9 shrink-0 cursor-pointer items-center rounded-full transition-colors duration-200 disabled:cursor-not-allowed disabled:opacity-40"
                                                :class="rule.enabled ? 'bg-brand-500' : 'bg-gray-300 dark:bg-gray-600'"
                                                @click="toggleRule(rule)"
                                            >
                                                <span
                                                    class="inline-block h-3.5 w-3.5 rounded-full bg-white shadow transition-transform duration-200"
                                                    :class="rule.enabled ? 'translate-x-4.5' : 'translate-x-0.5'"
                                                ></span>
                                            </button>
                                        </td>
                                        <td class="py-2">
                                            <div class="flex items-center gap-1">
                                                <button
                                                    type="button"
                                                    :disabled="index === 0"
                                                    class="inline-flex h-7 w-7 items-center justify-center rounded-lg border border-gray-300 text-gray-600 hover:bg-gray-100 disabled:cursor-not-allowed disabled:opacity-40 dark:border-gray-700 dark:text-gray-400 dark:hover:bg-gray-800"
                                                    @click="moveRule('INPUT', index, 'up')"
                                                >
                                                    <i class="fa-solid fa-arrow-up text-xs"></i>
                                                </button>
                                                <button
                                                    type="button"
                                                    :disabled="index === inputRules.length - 1"
                                                    class="inline-flex h-7 w-7 items-center justify-center rounded-lg border border-gray-300 text-gray-600 hover:bg-gray-100 disabled:cursor-not-allowed disabled:opacity-40 dark:border-gray-700 dark:text-gray-400 dark:hover:bg-gray-800"
                                                    @click="moveRule('INPUT', index, 'down')"
                                                >
                                                    <i class="fa-solid fa-arrow-down text-xs"></i>
                                                </button>
                                                <button
                                                    type="button"
                                                    class="inline-flex h-7 w-7 items-center justify-center rounded-lg border border-brand-300 text-brand-600 hover:bg-brand-50 dark:border-brand-700 dark:text-brand-400 dark:hover:bg-brand-950"
                                                    @click="openEditModal(rule)"
                                                >
                                                    <i class="fa-solid fa-pen text-xs"></i>
                                                </button>
                                                <button
                                                    type="button"
                                                    :disabled="deleteRuleLoading === rule.id"
                                                    class="inline-flex h-7 w-7 items-center justify-center rounded-lg border border-error-300 text-error-600 hover:bg-error-50 disabled:cursor-not-allowed disabled:opacity-40 dark:border-error-700 dark:text-error-400 dark:hover:bg-error-950"
                                                    @click="confirmDeleteRule(rule)"
                                                >
                                                    <i class="fa-solid fa-trash-can text-xs"></i>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- OUTPUT Rules Table -->
                    <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/3">
                        <h3 class="mb-3 text-sm font-semibold text-gray-800 dark:text-white/90">
                            <i class="fa-solid fa-arrow-right-from-bracket mr-1"></i>
                            {{ t('OUTPUT Rules') }}
                            <span v-if="outputRules.length > 0" class="ml-1 text-xs font-normal text-gray-500 dark:text-gray-400">
                                ({{ outputRules.length }})
                            </span>
                        </h3>
                        <div class="overflow-auto">
                            <table class="w-full text-sm">
                                <thead>
                                    <tr class="border-b border-gray-200 text-left text-xs uppercase text-gray-500 dark:border-gray-800 dark:text-gray-400">
                                        <th class="w-6 pb-2"></th>
                                        <th class="pb-2">#</th>
                                        <th class="pb-2">{{ t('Action') }}</th>
                                        <th class="pb-2">{{ t('Protocol') }}</th>
                                        <th class="pb-2">{{ t('Source') }}</th>
                                        <th class="pb-2">{{ t('Port') }}</th>
                                        <th class="pb-2">{{ t('Comment') }}</th>
                                        <th class="pb-2">{{ t('Enabled') }}</th>
                                        <th class="pb-2">{{ t('Actions') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-if="outputRules.length === 0">
                                        <td colspan="9" class="py-4 text-center text-gray-500 dark:text-gray-400">{{ t('No OUTPUT rules.') }}</td>
                                    </tr>
                                    <tr
                                        v-for="(rule, index) in outputRules"
                                        :key="`output-${rule.id}`"
                                        draggable="true"
                                        :class="[
                                            'border-b border-gray-100 align-top last:border-0 dark:border-gray-800 transition-colors duration-150',
                                            !rule.enabled ? 'opacity-50' : '',
                                            dragOverId === `output-${rule.id}` ? 'bg-brand-50 dark:bg-brand-500/10' : '',
                                            dragSourceId === `output-${rule.id}` ? 'opacity-30' : '',
                                        ]"
                                        @dragstart="onDragStart($event, 'OUTPUT', index)"
                                        @dragover.prevent="onDragOver($event, 'OUTPUT', index, rule.id)"
                                        @dragleave="onDragLeave($event, rule.id, 'OUTPUT')"
                                        @drop.prevent="onDrop($event, 'OUTPUT', index)"
                                        @dragend="onDragEnd"
                                    >
                                        <td class="cursor-grab py-2 text-xs text-gray-400 active:cursor-grabbing dark:text-gray-500">
                                            <i class="fa-solid fa-grip-vertical"></i>
                                        </td>
                                        <td class="py-2 text-xs text-gray-700 dark:text-gray-300">{{ rule.position }}</td>
                                        <td class="py-2">
                                            <span :class="actionBadgeClass(rule.action)">{{ rule.action }}</span>
                                        </td>
                                        <td class="py-2 text-xs text-gray-700 dark:text-gray-300">{{ rule.protocol }}</td>
                                        <td class="py-2 font-mono text-xs text-gray-700 dark:text-gray-300">{{ formatSources(rule.sources) }}</td>
                                        <td class="py-2 text-xs text-gray-700 dark:text-gray-300">{{ formatPorts(rule.ports) }}</td>
                                        <td class="py-2 text-xs text-gray-500 dark:text-gray-400">{{ rule.comment ?? '-' }}</td>
                                        <td class="py-2">
                                            <button
                                                type="button"
                                                :disabled="toggleLoading === rule.id"
                                                class="relative inline-flex h-5 w-9 shrink-0 cursor-pointer items-center rounded-full transition-colors duration-200 disabled:cursor-not-allowed disabled:opacity-40"
                                                :class="rule.enabled ? 'bg-brand-500' : 'bg-gray-300 dark:bg-gray-600'"
                                                @click="toggleRule(rule)"
                                            >
                                                <span
                                                    class="inline-block h-3.5 w-3.5 rounded-full bg-white shadow transition-transform duration-200"
                                                    :class="rule.enabled ? 'translate-x-4.5' : 'translate-x-0.5'"
                                                ></span>
                                            </button>
                                        </td>
                                        <td class="py-2">
                                            <div class="flex items-center gap-1">
                                                <button
                                                    type="button"
                                                    :disabled="index === 0"
                                                    class="inline-flex h-7 w-7 items-center justify-center rounded-lg border border-gray-300 text-gray-600 hover:bg-gray-100 disabled:cursor-not-allowed disabled:opacity-40 dark:border-gray-700 dark:text-gray-400 dark:hover:bg-gray-800"
                                                    @click="moveRule('OUTPUT', index, 'up')"
                                                >
                                                    <i class="fa-solid fa-arrow-up text-xs"></i>
                                                </button>
                                                <button
                                                    type="button"
                                                    :disabled="index === outputRules.length - 1"
                                                    class="inline-flex h-7 w-7 items-center justify-center rounded-lg border border-gray-300 text-gray-600 hover:bg-gray-100 disabled:cursor-not-allowed disabled:opacity-40 dark:border-gray-700 dark:text-gray-400 dark:hover:bg-gray-800"
                                                    @click="moveRule('OUTPUT', index, 'down')"
                                                >
                                                    <i class="fa-solid fa-arrow-down text-xs"></i>
                                                </button>
                                                <button
                                                    type="button"
                                                    class="inline-flex h-7 w-7 items-center justify-center rounded-lg border border-brand-300 text-brand-600 hover:bg-brand-50 dark:border-brand-700 dark:text-brand-400 dark:hover:bg-brand-950"
                                                    @click="openEditModal(rule)"
                                                >
                                                    <i class="fa-solid fa-pen text-xs"></i>
                                                </button>
                                                <button
                                                    type="button"
                                                    :disabled="deleteRuleLoading === rule.id"
                                                    class="inline-flex h-7 w-7 items-center justify-center rounded-lg border border-error-300 text-error-600 hover:bg-error-50 disabled:cursor-not-allowed disabled:opacity-40 dark:border-error-700 dark:text-error-400 dark:hover:bg-error-950"
                                                    @click="confirmDeleteRule(rule)"
                                                >
                                                    <i class="fa-solid fa-trash-can text-xs"></i>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Rule Add/Edit Modal -->
                <Teleport to="body">
                    <div
                        v-if="showRuleModal"
                        class="fixed inset-0 z-99999 flex items-center justify-center bg-black/50"
                        @click.self="showRuleModal = false"
                    >
                        <div class="mx-4 w-full max-w-lg rounded-2xl border border-gray-200 bg-white p-6 shadow-lg dark:border-gray-800 dark:bg-gray-900">
                            <div class="flex items-center justify-between">
                                <h3 class="text-lg font-semibold text-gray-800 dark:text-white/90">
                                    {{ editingRule ? t('Edit Rule') : t('Add Rule') }}
                                </h3>
                                <button
                                    type="button"
                                    class="inline-flex h-8 w-8 items-center justify-center rounded-lg text-gray-500 hover:bg-gray-100 dark:text-gray-400 dark:hover:bg-gray-800"
                                    @click="showRuleModal = false"
                                >
                                    <i class="fa-solid fa-xmark"></i>
                                </button>
                            </div>

                            <div class="mt-4 space-y-4">
                                <!-- Row 1: Chain + Action -->
                                <div class="grid grid-cols-2 gap-3">
                                    <div class="flex flex-col gap-1">
                                        <label class="text-xs font-medium text-gray-500 dark:text-gray-400">{{ t('Chain') }}</label>
                                        <select
                                            v-model="modalForm.chain"
                                            class="h-10 rounded-lg border border-gray-300 bg-white px-3 text-sm text-gray-700 outline-none focus:border-brand-500 focus:ring-1 focus:ring-brand-500 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-300 dark:scheme-dark"
                                        >
                                            <option value="INPUT">INPUT</option>
                                            <option value="OUTPUT">OUTPUT</option>
                                        </select>
                                    </div>
                                    <div class="flex flex-col gap-1">
                                        <label class="text-xs font-medium text-gray-500 dark:text-gray-400">{{ t('Action') }}</label>
                                        <select
                                            v-model="modalForm.action"
                                            class="h-10 rounded-lg border border-gray-300 bg-white px-3 text-sm text-gray-700 outline-none focus:border-brand-500 focus:ring-1 focus:ring-brand-500 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-300 dark:scheme-dark"
                                        >
                                            <option value="ACCEPT">ACCEPT</option>
                                            <option value="DROP">DROP</option>
                                            <option value="REJECT">REJECT</option>
                                        </select>
                                    </div>
                                </div>

                                <!-- Row 2: Protocol -->
                                <div class="flex flex-col gap-1">
                                    <label class="text-xs font-medium text-gray-500 dark:text-gray-400">{{ t('Protocol') }}</label>
                                    <select
                                        v-model="modalForm.protocol"
                                        class="h-10 rounded-lg border border-gray-300 bg-white px-3 text-sm text-gray-700 outline-none focus:border-brand-500 focus:ring-1 focus:ring-brand-500 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-300 dark:scheme-dark"
                                    >
                                        <option value="all">{{ t('all') }}</option>
                                        <option value="tcp">tcp</option>
                                        <option value="udp">udp</option>
                                        <option value="icmp">icmp</option>
                                    </select>
                                </div>

                                <!-- Source IPs -->
                                <div class="flex flex-col gap-1">
                                    <label class="text-xs font-medium text-gray-500 dark:text-gray-400">
                                        {{ t('Source IPs (one per line)') }}
                                    </label>
                                    <textarea
                                        v-model="modalForm.sourcesText"
                                        rows="3"
                                        :placeholder="t('Leave empty for all IPs') + '\n192.168.1.1\n10.0.0.50'"
                                        class="rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-700 placeholder-gray-400 outline-none focus:border-brand-500 focus:ring-1 focus:ring-brand-500 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-300 dark:placeholder-gray-500"
                                    ></textarea>
                                </div>

                                <!-- Ports -->
                                <div class="flex flex-col gap-1">
                                    <label class="text-xs font-medium text-gray-500 dark:text-gray-400">
                                        {{ t('Ports (comma separated)') }}
                                    </label>
                                    <input
                                        v-model="modalForm.portsText"
                                        type="text"
                                        :placeholder="t('Leave empty for all ports') + ' — 80, 443, 8080'"
                                        class="h-10 rounded-lg border border-gray-300 bg-white px-3 text-sm text-gray-700 placeholder-gray-400 outline-none focus:border-brand-500 focus:ring-1 focus:ring-brand-500 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-300 dark:placeholder-gray-500"
                                    />
                                </div>

                                <!-- Comment -->
                                <div class="flex flex-col gap-1">
                                    <label class="text-xs font-medium text-gray-500 dark:text-gray-400">{{ t('Comment') }}</label>
                                    <input
                                        v-model="modalForm.comment"
                                        type="text"
                                        :placeholder="t('Comment')"
                                        class="h-10 rounded-lg border border-gray-300 bg-white px-3 text-sm text-gray-700 placeholder-gray-400 outline-none focus:border-brand-500 focus:ring-1 focus:ring-brand-500 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-300 dark:placeholder-gray-500"
                                    />
                                </div>

                            </div>

                            <div class="mt-6 flex justify-end gap-2">
                                <button
                                    type="button"
                                    class="inline-flex h-9 items-center justify-center rounded-lg border border-gray-300 px-4 text-sm font-medium text-gray-700 hover:bg-gray-100 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-gray-800"
                                    @click="showRuleModal = false"
                                >
                                    {{ t('Cancel') }}
                                </button>
                                <button
                                    type="button"
                                    :disabled="modalLoading"
                                    class="inline-flex h-9 items-center justify-center rounded-lg bg-brand-500 px-4 text-sm font-medium text-white hover:bg-brand-600 disabled:cursor-not-allowed disabled:opacity-40"
                                    @click="submitModal"
                                >
                                    {{ editingRule ? t('Save Changes') : t('Add Rule') }}
                                </button>
                            </div>
                        </div>
                    </div>
                </Teleport>

                <!-- Policy Change Confirmation Dialog -->
                <Teleport to="body">
                    <div
                        v-if="showPolicyConfirm"
                        class="fixed inset-0 z-99999 flex items-center justify-center bg-black/50"
                        @click.self="showPolicyConfirm = false"
                    >
                        <div class="mx-4 w-full max-w-md rounded-2xl border border-gray-200 bg-white p-6 shadow-lg dark:border-gray-800 dark:bg-gray-900">
                            <h3 class="text-lg font-semibold text-gray-800 dark:text-white/90">
                                {{ t('Change Default Policy') }}
                            </h3>
                            <p class="mt-2 text-sm text-gray-600 dark:text-gray-400">
                                {{ t('Are you sure you want to change the :chain default policy to :policy? This will be saved to the database. Generate the script and run it on the server to apply.').replace(':chain', pendingPolicyChain).replace(':policy', pendingPolicyValue) }}
                            </p>
                            <div class="mt-4 flex justify-end gap-2">
                                <button
                                    type="button"
                                    class="inline-flex h-8 items-center justify-center rounded-lg border border-gray-300 px-4 text-xs font-medium text-gray-700 hover:bg-gray-100 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-gray-800"
                                    @click="showPolicyConfirm = false"
                                >
                                    {{ t('Cancel') }}
                                </button>
                                <button
                                    type="button"
                                    :disabled="policyLoading"
                                    class="inline-flex h-8 items-center justify-center rounded-lg bg-error-500 px-4 text-xs font-medium text-white hover:bg-error-600 disabled:cursor-not-allowed disabled:opacity-40"
                                    @click="changePolicy"
                                >
                                    {{ t('Confirm') }}
                                </button>
                            </div>
                        </div>
                    </div>
                </Teleport>

                <!-- Delete Rule Confirmation Dialog -->
                <Teleport to="body">
                    <div
                        v-if="showDeleteConfirm"
                        class="fixed inset-0 z-99999 flex items-center justify-center bg-black/50"
                        @click.self="showDeleteConfirm = false"
                    >
                        <div class="mx-4 w-full max-w-md rounded-2xl border border-gray-200 bg-white p-6 shadow-lg dark:border-gray-800 dark:bg-gray-900">
                            <h3 class="text-lg font-semibold text-gray-800 dark:text-white/90">
                                {{ t('Delete Firewall Rule') }}
                            </h3>
                            <p class="mt-2 text-sm text-gray-600 dark:text-gray-400">
                                {{ t('Are you sure you want to delete this :chain rule?').replace(':chain', pendingDeleteRule?.chain ?? '') }}
                                <span v-if="pendingDeleteRule?.comment" class="block mt-1 font-medium">
                                    "{{ pendingDeleteRule.comment }}"
                                </span>
                            </p>
                            <div class="mt-4 flex justify-end gap-2">
                                <button
                                    type="button"
                                    class="inline-flex h-8 items-center justify-center rounded-lg border border-gray-300 px-4 text-xs font-medium text-gray-700 hover:bg-gray-100 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-gray-800"
                                    @click="showDeleteConfirm = false"
                                >
                                    {{ t('Cancel') }}
                                </button>
                                <button
                                    type="button"
                                    :disabled="!!deleteRuleLoading"
                                    class="inline-flex h-8 items-center justify-center rounded-lg bg-error-500 px-4 text-xs font-medium text-white hover:bg-error-600 disabled:cursor-not-allowed disabled:opacity-40"
                                    @click="deleteRule"
                                >
                                    {{ t('Delete') }}
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
import { computed, onMounted, onUnmounted, ref, reactive } from 'vue';
import { Head } from '@inertiajs/vue3';
import axios from 'axios';
import ThemeProvider from '@/Components/Layout/ThemeProvider.vue';
import SidebarProvider from '@/Components/Layout/SidebarProvider.vue';
import AdminLayout from '@/Components/Layout/AdminLayout.vue';
import PageBreadcrumb from '@/Components/Common/PageBreadcrumb.vue';
import Toast from '@/Components/UI/Toast.vue';
import { useToast } from '@/Composables/useToast';
import { useI18n } from '@/Composables/useI18n';

interface FirewallRule {
    id: number;
    chain: string;
    action: string;
    protocol: string;
    sources: string[] | null;
    ports: number[] | null;
    comment: string | null;
    position: number;
    enabled: boolean;
    created_by: number;
    creator?: { id: number; name: string };
    created_at: string;
    updated_at: string;
}

interface FirewallPayload {
    input: {
        policy: string;
        rules: FirewallRule[];
    };
    output: {
        policy: string;
        rules: FirewallRule[];
    };
    pending_changes: boolean;
    warnings: string[];
}

const props = defineProps<{ firewall: FirewallPayload }>();
const { t } = useI18n();
const { addToast } = useToast();

const firewallData = ref<FirewallPayload>(props.firewall);

const inputPolicy = computed(() => firewallData.value.input.policy);
const outputPolicy = computed(() => firewallData.value.output.policy);
const inputRules = computed(() => firewallData.value.input.rules);
const outputRules = computed(() => firewallData.value.output.rules);
const warnings = computed(() => firewallData.value.warnings);

const showRuleModal = ref(false);
const editingRule = ref<FirewallRule | null>(null);
const modalLoading = ref(false);
const modalForm = reactive({
    chain: 'INPUT',
    action: 'ACCEPT',
    protocol: 'tcp',
    sourcesText: '',
    portsText: '',
    comment: '',
});
const deleteRuleLoading = ref<number | null>(null);
const policyLoading = ref(false);
const toggleLoading = ref<number | null>(null);

// Drag & drop state
const dragSourceId = ref<string | null>(null);
const dragOverId = ref<string | null>(null);
const dragChain = ref<string | null>(null);
const dragFromIndex = ref<number | null>(null);

const showPreview = ref(false);
const previewScript = ref('');
const previewLoading = ref(false);
const copySuccess = ref(false);

const showPolicyConfirm = ref(false);
const pendingPolicyChain = ref('');
const pendingPolicyValue = ref('');

const showDeleteConfirm = ref(false);
const pendingDeleteRule = ref<FirewallRule | null>(null);

let refreshTimer: ReturnType<typeof setInterval> | null = null;

const toArray = (value: unknown): string[] | number[] | null => {
    if (value === null || value === undefined) return null;
    if (Array.isArray(value)) return value.length > 0 ? value : null;
    if (typeof value === 'string') {
        try {
            const parsed = JSON.parse(value);
            if (Array.isArray(parsed) && parsed.length > 0) return parsed;
        } catch { /* not JSON */ }
        return value.trim() ? [value.trim()] : null;
    }
    return null;
};

const formatSources = (sources: unknown): string => {
    const arr = toArray(sources);
    return arr ? arr.join(', ') : t('any');
};

const formatPorts = (ports: unknown): string => {
    const arr = toArray(ports);
    return arr ? arr.join(', ') : t('all');
};

const actionBadgeClass = (action: string): string => {
    const base = 'rounded-full px-2 py-0.5 text-xs font-semibold';
    switch (action) {
        case 'ACCEPT':
            return `${base} bg-success-100 text-success-700 dark:bg-success-900/30 dark:text-success-400`;
        case 'DROP':
            return `${base} bg-error-100 text-error-700 dark:bg-error-900/30 dark:text-error-400`;
        case 'REJECT':
            return `${base} bg-warning-100 text-warning-700 dark:bg-warning-900/30 dark:text-warning-400`;
        default:
            return `${base} bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300`;
    }
};

const refreshData = async (): Promise<void> => {
    try {
        const response = await axios.get<FirewallPayload>(route('security.firewall.data'));
        firewallData.value = response.data;
    } catch {
        addToast('error', t('Firewall data could not be refreshed.'));
    }
};

const loadPreview = async (): Promise<void> => {
    previewLoading.value = true;
    try {
        const response = await axios.get<{ script: string }>(route('security.firewall.preview'));
        previewScript.value = response.data.script;
        showPreview.value = true;
    } catch {
        addToast('error', t('Failed to load command preview.'));
    } finally {
        previewLoading.value = false;
    }
};

const copyPreview = async (): Promise<void> => {
    try {
        await navigator.clipboard.writeText(previewScript.value);
        copySuccess.value = true;
        setTimeout(() => { copySuccess.value = false; }, 2000);
    } catch {
        addToast('error', t('Failed to copy to clipboard.'));
    }
};

const confirmPolicyChange = (chain: string): void => {
    const currentPolicy = chain === 'INPUT' ? inputPolicy.value : outputPolicy.value;
    pendingPolicyChain.value = chain;
    pendingPolicyValue.value = currentPolicy === 'ACCEPT' ? 'DROP' : 'ACCEPT';
    showPolicyConfirm.value = true;
};

const changePolicy = async (): Promise<void> => {
    policyLoading.value = true;
    try {
        await axios.put(route('security.firewall.policy'), {
            chain: pendingPolicyChain.value,
            policy: pendingPolicyValue.value,
        });
        addToast('success', t(':chain policy changed to :policy.').replace(':chain', pendingPolicyChain.value).replace(':policy', pendingPolicyValue.value));
        showPolicyConfirm.value = false;
        await refreshData();
    } catch {
        addToast('error', t('Failed to change :chain policy.').replace(':chain', pendingPolicyChain.value));
    } finally {
        policyLoading.value = false;
    }
};

const openAddModal = (): void => {
    editingRule.value = null;
    modalForm.chain = 'INPUT';
    modalForm.action = 'ACCEPT';
    modalForm.protocol = 'tcp';
    modalForm.sourcesText = '';
    modalForm.portsText = '';
    modalForm.comment = '';
    showRuleModal.value = true;
};

const openEditModal = (rule: FirewallRule): void => {
    editingRule.value = rule;
    modalForm.chain = rule.chain;
    modalForm.action = rule.action;
    modalForm.protocol = rule.protocol;
    const srcArr = toArray(rule.sources);
    const prtArr = toArray(rule.ports);
    modalForm.sourcesText = srcArr ? srcArr.join('\n') : '';
    modalForm.portsText = prtArr ? prtArr.join(', ') : '';
    modalForm.comment = rule.comment ?? '';
    showRuleModal.value = true;
};

const parseSources = (text: string): string[] => {
    return text
        .split(/[\n,]+/)
        .map(s => s.trim())
        .filter(s => s.length > 0);
};

const parsePorts = (text: string): number[] => {
    return text
        .split(/[\n,]+/)
        .map(s => s.trim())
        .filter(s => s.length > 0)
        .map(s => parseInt(s, 10))
        .filter(n => !isNaN(n) && n >= 1 && n <= 65535);
};

const submitModal = async (): Promise<void> => {
    modalLoading.value = true;
    try {
        const sources = parseSources(modalForm.sourcesText);
        const ports = parsePorts(modalForm.portsText);

        const payload: Record<string, unknown> = {
            chain: modalForm.chain,
            action: modalForm.action,
            protocol: modalForm.protocol,
            comment: modalForm.comment.trim() || null,
            sources: sources.length > 0 ? sources : null,
            ports: ports.length > 0 ? ports : null,
        };

        if (editingRule.value) {
            await axios.put(route('security.firewall.update', { rule: editingRule.value.id }), payload);
            addToast('success', t('Rule updated successfully.'));
        } else {
            await axios.post(route('security.firewall.store'), payload);
            addToast('success', t('Rule created successfully.'));
        }
        showRuleModal.value = false;
        await refreshData();
    } catch (error: unknown) {
        if (editingRule.value) {
            addToast('error', t('Failed to update firewall rule.'));
        } else {
            addToast('error', t('Failed to add firewall rule.'));
        }
    } finally {
        modalLoading.value = false;
    }
};

const toggleRule = async (rule: FirewallRule): Promise<void> => {
    toggleLoading.value = rule.id;
    try {
        await axios.put(route('security.firewall.toggle', { rule: rule.id }), {
            enabled: !rule.enabled,
        });
        await refreshData();
    } catch {
        addToast('error', t('Failed to toggle rule.'));
    } finally {
        toggleLoading.value = null;
    }
};

const moveRule = async (chain: string, index: number, direction: 'up' | 'down'): Promise<void> => {
    const rules = chain === 'INPUT' ? inputRules.value : outputRules.value;
    const targetIndex = direction === 'up' ? index - 1 : index + 1;
    if (targetIndex < 0 || targetIndex >= rules.length) return;

    // Swap in local array
    const temp = rules[index];
    rules[index] = rules[targetIndex];
    rules[targetIndex] = temp;

    // Send new order (flat array of IDs — backend assigns positions by index)
    const orderedIds = rules.map(r => r.id);
    try {
        await axios.put(route('security.firewall.reorder'), {
            rules: orderedIds,
        });
        await refreshData();
    } catch {
        addToast('error', t('Failed to reorder rules.'));
        await refreshData();
    }
};

// ── Drag & Drop handlers ──

const onDragStart = (event: DragEvent, chain: string, index: number): void => {
    const rules = chain === 'INPUT' ? inputRules.value : outputRules.value;
    const rule = rules[index];
    dragSourceId.value = `${chain.toLowerCase()}-${rule.id}`;
    dragChain.value = chain;
    dragFromIndex.value = index;
    if (event.dataTransfer) {
        event.dataTransfer.effectAllowed = 'move';
        event.dataTransfer.setData('text/plain', String(rule.id));
    }
};

const onDragOver = (_event: DragEvent, chain: string, _index: number, ruleId: number): void => {
    if (dragChain.value !== chain) return; // Don't allow cross-chain drag
    dragOverId.value = `${chain.toLowerCase()}-${ruleId}`;
};

const onDragLeave = (event: DragEvent, ruleId: number, chain: string): void => {
    const related = event.relatedTarget as HTMLElement | null;
    const row = (event.currentTarget as HTMLElement);
    if (related && row.contains(related)) return; // Still inside the same row
    if (dragOverId.value === `${chain.toLowerCase()}-${ruleId}`) {
        dragOverId.value = null;
    }
};

const onDrop = async (_event: DragEvent, chain: string, toIndex: number): Promise<void> => {
    const fromIndex = dragFromIndex.value;
    if (dragChain.value !== chain || fromIndex === null || fromIndex === toIndex) {
        onDragEnd();
        return;
    }

    const rules = chain === 'INPUT' ? inputRules.value : outputRules.value;

    // Move the rule in local array
    const [moved] = rules.splice(fromIndex, 1);
    rules.splice(toIndex, 0, moved);

    onDragEnd();

    // Persist new order
    const orderedIds = rules.map(r => r.id);
    try {
        await axios.put(route('security.firewall.reorder'), { rules: orderedIds });
        await refreshData();
    } catch {
        addToast('error', t('Failed to reorder rules.'));
        await refreshData();
    }
};

const onDragEnd = (): void => {
    dragSourceId.value = null;
    dragOverId.value = null;
    dragChain.value = null;
    dragFromIndex.value = null;
};

const confirmDeleteRule = (rule: FirewallRule): void => {
    pendingDeleteRule.value = rule;
    showDeleteConfirm.value = true;
};

const deleteRule = async (): Promise<void> => {
    if (!pendingDeleteRule.value) return;

    const rule = pendingDeleteRule.value;
    deleteRuleLoading.value = rule.id;

    try {
        await axios.delete(route('security.firewall.destroy'), {
            data: { id: rule.id },
        });
        addToast('success', t('Firewall rule deleted.'));
        showDeleteConfirm.value = false;
        pendingDeleteRule.value = null;
        await refreshData();
    } catch {
        addToast('error', t('Failed to delete firewall rule.'));
    } finally {
        deleteRuleLoading.value = null;
    }
};

onMounted(() => {
    refreshTimer = setInterval(() => {
        void refreshData();
    }, 30000);
});

onUnmounted(() => {
    if (refreshTimer) {
        clearInterval(refreshTimer);
        refreshTimer = null;
    }
});
</script>
