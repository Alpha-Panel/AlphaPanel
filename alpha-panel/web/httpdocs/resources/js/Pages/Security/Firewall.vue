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

                    <!-- Policy & Status Cards -->
                    <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
                        <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
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
                                    @click="confirmPolicyChange"
                                >
                                    {{ t('Switch to :policy').replace(':policy', inputPolicy === 'ACCEPT' ? 'DROP' : 'ACCEPT') }}
                                </button>
                            </div>
                        </div>
                        <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
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
                        <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
                            <p class="text-xs uppercase text-gray-500 dark:text-gray-400">{{ t('Container Status') }}</p>
                            <p class="mt-1 text-2xl font-semibold">
                                <span :class="containerOnline ? 'text-success-600 dark:text-success-400' : 'text-error-600 dark:text-error-400'">
                                    {{ containerOnline ? t('Online') : t('Offline') }}
                                </span>
                            </p>
                        </div>
                    </div>

                    <!-- Add Rule Form -->
                    <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
                        <h3 class="mb-3 text-sm font-semibold text-gray-800 dark:text-white/90">
                            <i class="fa-solid fa-plus mr-1"></i>
                            {{ t('Add Rule') }}
                        </h3>
                        <div class="flex flex-wrap items-end gap-3">
                            <div class="flex flex-col gap-1">
                                <label class="text-xs text-gray-500 dark:text-gray-400">{{ t('Chain') }}</label>
                                <select
                                    v-model="ruleForm.chain"
                                    class="h-8 rounded-lg border border-gray-300 bg-transparent px-2 text-xs text-gray-700 outline-none focus:border-brand-500 focus:ring-1 focus:ring-brand-500 dark:border-gray-700 dark:text-gray-300"
                                >
                                    <option value="INPUT">INPUT</option>
                                    <option value="OUTPUT">OUTPUT</option>
                                </select>
                            </div>
                            <div class="flex flex-col gap-1">
                                <label class="text-xs text-gray-500 dark:text-gray-400">{{ t('Action') }}</label>
                                <select
                                    v-model="ruleForm.action"
                                    class="h-8 rounded-lg border border-gray-300 bg-transparent px-2 text-xs text-gray-700 outline-none focus:border-brand-500 focus:ring-1 focus:ring-brand-500 dark:border-gray-700 dark:text-gray-300"
                                >
                                    <option value="ACCEPT">ACCEPT</option>
                                    <option value="DROP">DROP</option>
                                    <option value="REJECT">REJECT</option>
                                </select>
                            </div>
                            <div class="flex flex-col gap-1">
                                <label class="text-xs text-gray-500 dark:text-gray-400">{{ t('Protocol') }}</label>
                                <select
                                    v-model="ruleForm.protocol"
                                    class="h-8 rounded-lg border border-gray-300 bg-transparent px-2 text-xs text-gray-700 outline-none focus:border-brand-500 focus:ring-1 focus:ring-brand-500 dark:border-gray-700 dark:text-gray-300"
                                >
                                    <option value="all">{{ t('all') }}</option>
                                    <option value="tcp">tcp</option>
                                    <option value="udp">udp</option>
                                    <option value="icmp">icmp</option>
                                </select>
                            </div>
                            <div class="flex flex-col gap-1">
                                <label class="text-xs text-gray-500 dark:text-gray-400">{{ t('Source IP') }}</label>
                                <input
                                    v-model="ruleForm.source"
                                    type="text"
                                    :placeholder="t('Leave empty for all IPs')"
                                    class="h-8 w-44 rounded-lg border border-gray-300 bg-transparent px-3 text-xs text-gray-700 placeholder-gray-400 outline-none focus:border-brand-500 focus:ring-1 focus:ring-brand-500 dark:border-gray-700 dark:text-gray-300 dark:placeholder-gray-500"
                                />
                            </div>
                            <div class="flex flex-col gap-1">
                                <label class="text-xs text-gray-500 dark:text-gray-400">{{ t('Port') }}</label>
                                <input
                                    v-model="ruleForm.port"
                                    type="number"
                                    :placeholder="t('Leave empty for all ports')"
                                    class="h-8 w-40 rounded-lg border border-gray-300 bg-transparent px-3 text-xs text-gray-700 placeholder-gray-400 outline-none focus:border-brand-500 focus:ring-1 focus:ring-brand-500 dark:border-gray-700 dark:text-gray-300 dark:placeholder-gray-500"
                                />
                            </div>
                            <div class="flex flex-col gap-1">
                                <label class="text-xs text-gray-500 dark:text-gray-400">{{ t('Comment') }}</label>
                                <input
                                    v-model="ruleForm.comment"
                                    type="text"
                                    :placeholder="t('Comment')"
                                    class="h-8 w-44 rounded-lg border border-gray-300 bg-transparent px-3 text-xs text-gray-700 placeholder-gray-400 outline-none focus:border-brand-500 focus:ring-1 focus:ring-brand-500 dark:border-gray-700 dark:text-gray-300 dark:placeholder-gray-500"
                                />
                            </div>
                            <div class="flex flex-col gap-1">
                                <label class="text-xs text-gray-500 dark:text-gray-400">{{ t('Position') }}</label>
                                <div class="flex items-center gap-2">
                                    <select
                                        v-model="positionMode"
                                        class="h-8 rounded-lg border border-gray-300 bg-transparent px-2 text-xs text-gray-700 outline-none focus:border-brand-500 focus:ring-1 focus:ring-brand-500 dark:border-gray-700 dark:text-gray-300"
                                    >
                                        <option value="append">{{ t('Append to end') }}</option>
                                        <option value="specific">{{ t('Specific position') }}</option>
                                    </select>
                                    <input
                                        v-if="positionMode === 'specific'"
                                        v-model="ruleForm.position"
                                        type="number"
                                        min="1"
                                        class="h-8 w-16 rounded-lg border border-gray-300 bg-transparent px-2 text-xs text-gray-700 outline-none focus:border-brand-500 focus:ring-1 focus:ring-brand-500 dark:border-gray-700 dark:text-gray-300"
                                    />
                                </div>
                            </div>
                            <button
                                type="button"
                                :disabled="addRuleLoading"
                                class="inline-flex h-8 items-center justify-center rounded-lg bg-brand-500 px-4 text-xs font-medium text-white hover:bg-brand-600 disabled:cursor-not-allowed disabled:opacity-40"
                                @click="addRule"
                            >
                                {{ t('Add Rule') }}
                            </button>
                        </div>
                    </div>

                    <!-- INPUT Rules Table -->
                    <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
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
                                        <th class="pb-2">#</th>
                                        <th class="pb-2">{{ t('Action') }}</th>
                                        <th class="pb-2">{{ t('Protocol') }}</th>
                                        <th class="pb-2">{{ t('Source') }}</th>
                                        <th class="pb-2">{{ t('Port') }}</th>
                                        <th class="pb-2">{{ t('Comment') }}</th>
                                        <th class="pb-2">{{ t('Actions') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-if="inputRules.length === 0">
                                        <td colspan="7" class="py-4 text-center text-gray-500 dark:text-gray-400">{{ t('No INPUT rules.') }}</td>
                                    </tr>
                                    <tr
                                        v-for="rule in inputRules"
                                        :key="`input-${rule.num}`"
                                        class="border-b border-gray-100 align-top last:border-0 dark:border-gray-800"
                                    >
                                        <td class="py-2 text-xs text-gray-700 dark:text-gray-300">{{ rule.num }}</td>
                                        <td class="py-2">
                                            <span :class="actionBadgeClass(rule.action)">{{ rule.action }}</span>
                                        </td>
                                        <td class="py-2 text-xs text-gray-700 dark:text-gray-300">{{ rule.protocol }}</td>
                                        <td class="py-2 font-mono text-xs text-gray-700 dark:text-gray-300">{{ rule.source }}</td>
                                        <td class="py-2 text-xs text-gray-700 dark:text-gray-300">{{ rule.port ?? t('all') }}</td>
                                        <td class="py-2 text-xs text-gray-500 dark:text-gray-400">{{ rule.comment ?? '-' }}</td>
                                        <td class="py-2">
                                            <button
                                                v-if="rule.deletable"
                                                type="button"
                                                :disabled="deleteRuleLoading === `INPUT-${rule.num}`"
                                                class="inline-flex h-7 w-7 items-center justify-center rounded-lg border border-error-300 text-error-600 hover:bg-error-50 disabled:cursor-not-allowed disabled:opacity-40 dark:border-error-700 dark:text-error-400 dark:hover:bg-error-950"
                                                @click="confirmDeleteRule('INPUT', rule.num)"
                                            >
                                                <i class="fa-solid fa-trash-can text-xs"></i>
                                            </button>
                                            <span v-else class="inline-flex h-7 w-7 items-center justify-center text-gray-400 dark:text-gray-600">
                                                <i class="fa-solid fa-lock text-xs"></i>
                                            </span>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- OUTPUT Rules Table -->
                    <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
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
                                        <th class="pb-2">#</th>
                                        <th class="pb-2">{{ t('Action') }}</th>
                                        <th class="pb-2">{{ t('Protocol') }}</th>
                                        <th class="pb-2">{{ t('Source') }}</th>
                                        <th class="pb-2">{{ t('Port') }}</th>
                                        <th class="pb-2">{{ t('Comment') }}</th>
                                        <th class="pb-2">{{ t('Actions') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-if="outputRules.length === 0">
                                        <td colspan="7" class="py-4 text-center text-gray-500 dark:text-gray-400">{{ t('No OUTPUT rules.') }}</td>
                                    </tr>
                                    <tr
                                        v-for="rule in outputRules"
                                        :key="`output-${rule.num}`"
                                        class="border-b border-gray-100 align-top last:border-0 dark:border-gray-800"
                                    >
                                        <td class="py-2 text-xs text-gray-700 dark:text-gray-300">{{ rule.num }}</td>
                                        <td class="py-2">
                                            <span :class="actionBadgeClass(rule.action)">{{ rule.action }}</span>
                                        </td>
                                        <td class="py-2 text-xs text-gray-700 dark:text-gray-300">{{ rule.protocol }}</td>
                                        <td class="py-2 font-mono text-xs text-gray-700 dark:text-gray-300">{{ rule.source }}</td>
                                        <td class="py-2 text-xs text-gray-700 dark:text-gray-300">{{ rule.port ?? t('all') }}</td>
                                        <td class="py-2 text-xs text-gray-500 dark:text-gray-400">{{ rule.comment ?? '-' }}</td>
                                        <td class="py-2">
                                            <button
                                                v-if="rule.deletable"
                                                type="button"
                                                :disabled="deleteRuleLoading === `OUTPUT-${rule.num}`"
                                                class="inline-flex h-7 w-7 items-center justify-center rounded-lg border border-error-300 text-error-600 hover:bg-error-50 disabled:cursor-not-allowed disabled:opacity-40 dark:border-error-700 dark:text-error-400 dark:hover:bg-error-950"
                                                @click="confirmDeleteRule('OUTPUT', rule.num)"
                                            >
                                                <i class="fa-solid fa-trash-can text-xs"></i>
                                            </button>
                                            <span v-else class="inline-flex h-7 w-7 items-center justify-center text-gray-400 dark:text-gray-600">
                                                <i class="fa-solid fa-lock text-xs"></i>
                                            </span>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

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
                                {{ t('Are you sure you want to change the INPUT default policy to :policy? This can affect all incoming connections.').replace(':policy', pendingPolicy) }}
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
                                {{ t('Are you sure you want to delete rule #:num from the :chain chain?').replace(':num', String(pendingDeleteRule?.num ?? '')).replace(':chain', pendingDeleteRule?.chain ?? '') }}
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
    num: number;
    action: string;
    protocol: string;
    source: string;
    port: number | null;
    comment: string | null;
    deletable: boolean;
}

interface FirewallChain {
    policy: string;
    rules: FirewallRule[];
}

interface FirewallPayload {
    input: FirewallChain;
    output: FirewallChain;
    warnings: string[];
    container_online: boolean;
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
const containerOnline = computed(() => firewallData.value.container_online);

const ruleForm = reactive({
    chain: 'INPUT',
    action: 'ACCEPT',
    protocol: 'tcp',
    source: '',
    port: '' as string | number,
    comment: '',
    position: '' as string | number,
});
const positionMode = ref('append');
const addRuleLoading = ref(false);
const deleteRuleLoading = ref<string | null>(null);
const policyLoading = ref(false);

const showPolicyConfirm = ref(false);
const pendingPolicy = ref('');

const showDeleteConfirm = ref(false);
const pendingDeleteRule = ref<{ chain: string; num: number } | null>(null);

let refreshTimer: ReturnType<typeof setInterval> | null = null;

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

const confirmPolicyChange = (): void => {
    pendingPolicy.value = inputPolicy.value === 'ACCEPT' ? 'DROP' : 'ACCEPT';
    showPolicyConfirm.value = true;
};

const changePolicy = async (): Promise<void> => {
    policyLoading.value = true;
    try {
        await axios.put(route('security.firewall.policy'), {
            chain: 'INPUT',
            policy: pendingPolicy.value,
        });
        addToast('success', t('INPUT policy changed to :policy.').replace(':policy', pendingPolicy.value));
        showPolicyConfirm.value = false;
        await refreshData();
    } catch {
        addToast('error', t('Failed to change INPUT policy.'));
    } finally {
        policyLoading.value = false;
    }
};

const addRule = async (): Promise<void> => {
    addRuleLoading.value = true;
    try {
        const payload: Record<string, string | number | null> = {
            chain: ruleForm.chain,
            action: ruleForm.action,
            protocol: ruleForm.protocol,
            source: ruleForm.source.trim() || null,
            port: ruleForm.port !== '' ? Number(ruleForm.port) : null,
            comment: ruleForm.comment.trim() || null,
            position: positionMode.value === 'specific' && ruleForm.position !== '' ? Number(ruleForm.position) : null,
        };

        await axios.post(route('security.firewall.store'), payload);
        addToast('success', t('Firewall rule added successfully.'));

        ruleForm.source = '';
        ruleForm.port = '';
        ruleForm.comment = '';
        ruleForm.position = '';
        positionMode.value = 'append';

        await refreshData();
    } catch {
        addToast('error', t('Failed to add firewall rule.'));
    } finally {
        addRuleLoading.value = false;
    }
};

const confirmDeleteRule = (chain: string, num: number): void => {
    pendingDeleteRule.value = { chain, num };
    showDeleteConfirm.value = true;
};

const deleteRule = async (): Promise<void> => {
    if (!pendingDeleteRule.value) return;

    const { chain, num } = pendingDeleteRule.value;
    deleteRuleLoading.value = `${chain}-${num}`;

    try {
        await axios.delete(route('security.firewall.destroy'), {
            data: { chain, rule_number: num },
        });
        addToast('success', t('Firewall rule #:num deleted.').replace(':num', String(num)));
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
