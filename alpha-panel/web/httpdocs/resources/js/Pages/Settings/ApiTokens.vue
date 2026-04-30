<template>
    <Head :title="t('API Tokens')" />
    <ThemeProvider>
        <SidebarProvider>
            <AdminLayout>
                <PageBreadcrumb
                    :pageTitle="t('API Tokens')"
                    :items="breadcrumbs"
                    :backHref="route('settings.index')"
                />
                <Toast />

                <div class="space-y-4 md:space-y-6">
                    <!-- Header card -->
                    <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/3 md:p-6">
                        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                            <div>
                                <h3 class="flex items-center gap-2 text-lg font-semibold text-gray-800 dark:text-white/90">
                                    <i class="bx bx-key text-xl text-brand-500"></i>
                                    {{ t('API Tokens') }}
                                </h3>
                                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                                    {{ t('Manage bearer tokens for the AlphaPanel REST API.') }}
                                </p>
                            </div>
                            <button
                                type="button"
                                class="inline-flex items-center gap-2 rounded-lg bg-brand-500 px-4 py-2.5 text-sm font-medium text-white shadow-theme-xs hover:bg-brand-600"
                                @click="openCreateModal"
                            >
                                <i class="bx bx-plus text-base"></i>
                                {{ t('Create Token') }}
                            </button>
                        </div>
                    </div>

                    <!-- Loading skeleton -->
                    <div v-if="loading" class="space-y-3">
                        <div v-for="i in 3" :key="i" class="h-16 animate-pulse rounded-2xl bg-gray-100 dark:bg-gray-800"></div>
                    </div>

                    <!-- Empty state -->
                    <div
                        v-else-if="tokens.length === 0"
                        class="rounded-2xl border border-gray-200 bg-white p-8 text-center dark:border-gray-800 dark:bg-white/3"
                    >
                        <i class="bx bx-key text-3xl text-gray-400"></i>
                        <p class="mt-2 text-gray-500 dark:text-gray-400">{{ t('No API tokens yet.') }}</p>
                    </div>

                    <!-- Token list -->
                    <div v-else class="overflow-hidden rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/3">
                        <table class="w-full text-sm">
                            <thead class="bg-gray-50 dark:bg-gray-800/40">
                                <tr class="text-left text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                    <th class="px-4 py-3">{{ t('Name') }}</th>
                                    <th class="px-4 py-3">{{ t('Abilities') }}</th>
                                    <th class="px-4 py-3">{{ t('IP Rules') }}</th>
                                    <th class="px-4 py-3">{{ t('Last Used') }}</th>
                                    <th class="px-4 py-3">{{ t('Expires') }}</th>
                                    <th class="px-4 py-3">{{ t('Created') }}</th>
                                    <th class="px-4 py-3 text-right">{{ t('Actions') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="token in tokens"
                                    :key="token.id"
                                    class="border-t border-gray-100 dark:border-gray-800"
                                >
                                    <td class="px-4 py-3 font-medium text-gray-800 dark:text-white/90">{{ token.name }}</td>
                                    <td class="px-4 py-3">
                                        <div class="flex flex-wrap gap-1">
                                            <span
                                                v-for="ability in token.abilities"
                                                :key="ability"
                                                class="inline-block rounded bg-brand-50 px-1.5 py-0.5 text-xs text-brand-700 dark:bg-brand-500/10 dark:text-brand-300"
                                            >{{ ability }}</span>
                                        </div>
                                    </td>
                                    <td class="px-4 py-3 text-gray-600 dark:text-gray-400">
                                        <button
                                            type="button"
                                            class="inline-flex items-center gap-1 text-xs hover:text-brand-500"
                                            @click="openIpRulesModal(token)"
                                        >
                                            <i class="bx bx-shield text-sm"></i>
                                            {{ token.ip_rule_count }}
                                        </button>
                                    </td>
                                    <td class="px-4 py-3 text-xs text-gray-500 dark:text-gray-400">
                                        {{ token.last_used_at ? formatDate(token.last_used_at) : '—' }}
                                    </td>
                                    <td class="px-4 py-3 text-xs text-gray-500 dark:text-gray-400">
                                        {{ token.expires_at ? formatDate(token.expires_at) : t('Never') }}
                                    </td>
                                    <td class="px-4 py-3 text-xs text-gray-500 dark:text-gray-400">
                                        {{ formatDate(token.created_at) }}
                                    </td>
                                    <td class="px-4 py-3">
                                        <div class="flex justify-end gap-1.5">
                                            <button
                                                type="button"
                                                class="inline-flex h-7 w-7 items-center justify-center rounded border border-error-500/40 text-error-500 hover:bg-error-500/10"
                                                :title="t('Delete')"
                                                @click="deleteToken(token)"
                                            >
                                                <i class="bx bx-trash text-sm"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Create Token Modal -->
                <div
                    v-if="showCreateModal"
                    class="fixed inset-0 z-1200000 flex items-center justify-center bg-black/60 p-4 backdrop-blur-sm"
                >
                    <div class="w-full max-w-lg rounded-2xl border border-gray-200 bg-white shadow-theme-xl dark:border-gray-800 dark:bg-gray-900">
                        <div class="flex items-center justify-between border-b border-gray-200 px-5 py-4 dark:border-gray-800 md:px-6">
                            <h4 class="text-base font-semibold text-gray-800 dark:text-white/90">{{ t('Create API Token') }}</h4>
                            <button
                                type="button"
                                class="text-2xl leading-none text-gray-400 hover:text-gray-700 dark:hover:text-gray-200"
                                @click="showCreateModal = false"
                            >
                                &times;
                            </button>
                        </div>
                        <div class="p-5 md:p-6">
                            <form @submit.prevent="createToken" class="space-y-4">
                                <div>
                                    <label class="mb-1 block text-xs font-medium uppercase tracking-wide text-gray-500">{{ t('Token Name') }}</label>
                                    <input v-model="createForm.name" type="text" class="form-input" :placeholder="t('e.g. AlphaCenter')" />
                                </div>
                                <div>
                                    <label class="mb-1 block text-xs font-medium uppercase tracking-wide text-gray-500">{{ t('Abilities') }}</label>
                                    <div class="grid grid-cols-2 gap-1.5">
                                        <label
                                            v-for="ability in availableAbilities"
                                            :key="ability"
                                            class="flex cursor-pointer items-center gap-2 rounded-lg border border-gray-200 px-3 py-2 text-xs dark:border-gray-700"
                                            :class="createForm.abilities.includes(ability) ? 'border-brand-500/60 bg-brand-50 dark:bg-brand-500/10' : ''"
                                        >
                                            <input
                                                type="checkbox"
                                                :value="ability"
                                                v-model="createForm.abilities"
                                                class="accent-brand-500"
                                            />
                                            {{ ability }}
                                        </label>
                                    </div>
                                    <p class="mt-1 text-xs text-gray-400">{{ t('Select * to grant all permissions.') }}</p>
                                </div>
                                <div>
                                    <label class="mb-1 block text-xs font-medium uppercase tracking-wide text-gray-500">{{ t('Expires At') }} ({{ t('optional') }})</label>
                                    <input v-model="createForm.expires_at" type="datetime-local" class="form-input" />
                                </div>
                                <div class="flex items-center justify-end gap-2 pt-1">
                                    <button
                                        type="button"
                                        class="rounded-lg border border-gray-300 px-4 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-400 dark:hover:bg-gray-800"
                                        @click="showCreateModal = false"
                                    >
                                        {{ t('Cancel') }}
                                    </button>
                                    <button
                                        type="submit"
                                        :disabled="creating"
                                        class="inline-flex h-10 items-center gap-1.5 rounded-lg bg-brand-500 px-4 text-sm font-medium text-white hover:bg-brand-600 disabled:opacity-50"
                                    >
                                        <i class="bx bx-plus"></i>
                                        {{ creating ? '...' : t('Create') }}
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- Token Created Modal (shows plaintext token once) -->
                <div
                    v-if="newTokenPlaintext"
                    class="fixed inset-0 z-1200000 flex items-center justify-center bg-black/60 p-4 backdrop-blur-sm"
                >
                    <div class="w-full max-w-lg rounded-2xl border border-gray-200 bg-white shadow-theme-xl dark:border-gray-800 dark:bg-gray-900">
                        <div class="flex items-center justify-between border-b border-gray-200 px-5 py-4 dark:border-gray-800 md:px-6">
                            <h4 class="text-base font-semibold text-gray-800 dark:text-white/90">{{ t('Token Created') }}</h4>
                        </div>
                        <div class="p-5 md:p-6 space-y-3">
                            <p class="text-sm text-warning-600 dark:text-warning-400">
                                <i class="bx bx-error-circle mr-1"></i>
                                {{ t('Copy this token now. It will not be shown again.') }}
                            </p>
                            <div class="flex items-center gap-2 rounded-xl border border-gray-200 bg-gray-50 px-3 py-2.5 dark:border-gray-700 dark:bg-gray-800">
                                <code class="flex-1 break-all font-mono text-xs text-gray-800 dark:text-gray-200">{{ newTokenPlaintext }}</code>
                                <button
                                    type="button"
                                    class="shrink-0 text-brand-500 hover:text-brand-600"
                                    :title="t('Copy')"
                                    @click="copyToken"
                                >
                                    <i :class="tokenCopied ? 'bx bx-check' : 'bx bx-copy'"></i>
                                </button>
                            </div>
                            <div class="flex justify-end">
                                <button
                                    type="button"
                                    class="inline-flex h-10 items-center gap-1.5 rounded-lg bg-brand-500 px-4 text-sm font-medium text-white hover:bg-brand-600"
                                    @click="newTokenPlaintext = null"
                                >
                                    {{ t('Done') }}
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- IP Rules Modal -->
                <div
                    v-if="showIpRulesModal && activeToken"
                    class="fixed inset-0 z-1200000 flex items-center justify-center bg-black/60 p-4 backdrop-blur-sm"
                >
                    <div class="w-full max-w-lg rounded-2xl border border-gray-200 bg-white shadow-theme-xl dark:border-gray-800 dark:bg-gray-900">
                        <div class="flex items-center justify-between border-b border-gray-200 px-5 py-4 dark:border-gray-800 md:px-6">
                            <h4 class="text-base font-semibold text-gray-800 dark:text-white/90">
                                {{ t('IP Rules') }} — {{ activeToken.name }}
                            </h4>
                            <button
                                type="button"
                                class="text-2xl leading-none text-gray-400 hover:text-gray-700 dark:hover:text-gray-200"
                                @click="showIpRulesModal = false"
                            >
                                &times;
                            </button>
                        </div>
                        <div class="p-5 md:p-6 space-y-4">
                            <p class="text-xs text-gray-500 dark:text-gray-400">
                                {{ t('When IP rules are present, requests must originate from a matching CIDR range.') }}
                            </p>

                            <!-- Existing rules -->
                            <div v-if="ipRules.length > 0" class="space-y-2">
                                <div
                                    v-for="rule in ipRules"
                                    :key="rule.id"
                                    class="flex items-center justify-between rounded-xl border border-gray-200 px-3 py-2 dark:border-gray-700"
                                >
                                    <div>
                                        <span class="font-mono text-sm text-gray-800 dark:text-gray-200">{{ rule.ip_cidr }}</span>
                                        <span v-if="rule.description" class="ml-2 text-xs text-gray-500 dark:text-gray-400">{{ rule.description }}</span>
                                    </div>
                                    <button
                                        type="button"
                                        class="text-error-500 hover:text-error-600"
                                        @click="deleteIpRule(rule)"
                                    >
                                        <i class="bx bx-trash text-sm"></i>
                                    </button>
                                </div>
                            </div>
                            <p v-else class="text-center text-xs text-gray-400">{{ t('No IP rules. Token is not restricted by IP.') }}</p>

                            <!-- Add rule form -->
                            <form @submit.prevent="addIpRule" class="grid grid-cols-1 gap-2 md:grid-cols-3">
                                <div class="md:col-span-2">
                                    <input v-model="ipRuleForm.ip_cidr" type="text" class="form-input" :placeholder="t('e.g. 192.168.0.0/24')" />
                                </div>
                                <div>
                                    <input v-model="ipRuleForm.description" type="text" class="form-input" :placeholder="t('Description (optional)')" />
                                </div>
                                <div class="md:col-span-3 flex justify-end">
                                    <button
                                        type="submit"
                                        :disabled="addingRule"
                                        class="inline-flex h-9 items-center gap-1.5 rounded-lg bg-brand-500 px-4 text-sm font-medium text-white hover:bg-brand-600 disabled:opacity-50"
                                    >
                                        <i class="bx bx-plus"></i>
                                        {{ addingRule ? '...' : t('Add Rule') }}
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </AdminLayout>
        </SidebarProvider>
    </ThemeProvider>
</template>

<script setup lang="ts">
import { ref, onMounted } from 'vue';
import { Head } from '@inertiajs/vue3';
import axios from 'axios';
import ThemeProvider from '@/Components/Layout/ThemeProvider.vue';
import SidebarProvider from '@/Components/Layout/SidebarProvider.vue';
import AdminLayout from '@/Components/Layout/AdminLayout.vue';
import PageBreadcrumb from '@/Components/Common/PageBreadcrumb.vue';
import Toast from '@/Components/UI/Toast.vue';
import { useToast } from '@/Composables/useToast';
import { useI18n } from '@/Composables/useI18n';
import { formatDateTime } from '@/utils/dateTime';

const { t } = useI18n();
const { addToast } = useToast();

const breadcrumbs = [
    { label: t('Settings'), href: route('settings.index') },
    { label: t('API Tokens') },
];

const formatDate = formatDateTime;

const availableAbilities = [
    '*',
    'domains:read', 'domains:write',
    'ssl:read', 'ssl:write',
    'dns:read', 'dns:write',
    'databases:read', 'databases:write',
    'files:read', 'files:write',
    'php:read', 'php:write',
    'cron:read', 'cron:write',
    'docker:read', 'docker:write',
    'security:read', 'security:write',
    'backup:read', 'backup:write',
    'users:read', 'users:write',
    'settings:read', 'settings:write',
    'audit:read',
];

interface ApiToken {
    id: number;
    name: string;
    abilities: string[];
    ip_rule_count: number;
    last_used_at: string | null;
    expires_at: string | null;
    created_at: string;
}

interface IpRule {
    id: number;
    ip_cidr: string;
    description: string | null;
}

const loading = ref(true);
const tokens = ref<ApiToken[]>([]);

const showCreateModal = ref(false);
const creating = ref(false);
const createForm = ref({ name: '', abilities: [] as string[], expires_at: '' });

const newTokenPlaintext = ref<string | null>(null);
const tokenCopied = ref(false);

const showIpRulesModal = ref(false);
const activeToken = ref<ApiToken | null>(null);
const ipRules = ref<IpRule[]>([]);
const ipRuleForm = ref({ ip_cidr: '', description: '' });
const addingRule = ref(false);

async function fetchTokens(): Promise<void> {
    try {
        const res = await axios.get('/api/v1/api-tokens');
        tokens.value = res.data.data;
    } finally {
        loading.value = false;
    }
}

function openCreateModal(): void {
    createForm.value = { name: '', abilities: [], expires_at: '' };
    showCreateModal.value = true;
}

async function createToken(): Promise<void> {
    creating.value = true;
    try {
        const payload: Record<string, unknown> = {
            name: createForm.value.name,
            abilities: createForm.value.abilities,
            expires_at: createForm.value.expires_at || null,
        };
        const res = await axios.post('/api/v1/api-tokens', payload);
        tokens.value.unshift({ ...res.data.data, ip_rule_count: 0 });
        showCreateModal.value = false;
        newTokenPlaintext.value = res.data.data.token;
        tokenCopied.value = false;
        addToast({ type: 'success', message: t('Token created.') });
    } catch {
        addToast({ type: 'error', message: t('Failed to create token.') });
    } finally {
        creating.value = false;
    }
}

async function deleteToken(token: ApiToken): Promise<void> {
    if (!confirm(t('Delete token ":name"?', { name: token.name }))) return;
    try {
        await axios.delete(`/api/v1/api-tokens/${token.id}`);
        tokens.value = tokens.value.filter((t) => t.id !== token.id);
        addToast({ type: 'success', message: t('Token deleted.') });
    } catch {
        addToast({ type: 'error', message: t('Failed to delete token.') });
    }
}

function copyToken(): void {
    if (!newTokenPlaintext.value) return;
    navigator.clipboard.writeText(newTokenPlaintext.value);
    tokenCopied.value = true;
    setTimeout(() => { tokenCopied.value = false; }, 2000);
}

async function openIpRulesModal(token: ApiToken): Promise<void> {
    activeToken.value = token;
    ipRuleForm.value = { ip_cidr: '', description: '' };
    showIpRulesModal.value = true;
    const res = await axios.get(`/api/v1/api-tokens/${token.id}/ip-rules`);
    ipRules.value = res.data.data;
}

async function addIpRule(): Promise<void> {
    if (!activeToken.value) return;
    addingRule.value = true;
    try {
        const res = await axios.post(`/api/v1/api-tokens/${activeToken.value.id}/ip-rules`, ipRuleForm.value);
        ipRules.value.push(res.data.data);
        activeToken.value.ip_rule_count++;
        const idx = tokens.value.findIndex((t) => t.id === activeToken.value!.id);
        if (idx !== -1) tokens.value[idx].ip_rule_count++;
        ipRuleForm.value = { ip_cidr: '', description: '' };
        addToast({ type: 'success', message: t('IP rule added.') });
    } catch {
        addToast({ type: 'error', message: t('Failed to add IP rule.') });
    } finally {
        addingRule.value = false;
    }
}

async function deleteIpRule(rule: IpRule): Promise<void> {
    if (!activeToken.value) return;
    try {
        await axios.delete(`/api/v1/api-tokens/${activeToken.value.id}/ip-rules/${rule.id}`);
        ipRules.value = ipRules.value.filter((r) => r.id !== rule.id);
        activeToken.value.ip_rule_count--;
        const idx = tokens.value.findIndex((t) => t.id === activeToken.value!.id);
        if (idx !== -1) tokens.value[idx].ip_rule_count = Math.max(0, tokens.value[idx].ip_rule_count - 1);
        addToast({ type: 'success', message: t('IP rule deleted.') });
    } catch {
        addToast({ type: 'error', message: t('Failed to delete IP rule.') });
    }
}

onMounted(fetchTokens);
</script>
