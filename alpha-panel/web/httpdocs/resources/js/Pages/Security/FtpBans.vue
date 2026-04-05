<template>
    <Head :title="t('FTP Bans')" />
    <ThemeProvider>
        <SidebarProvider>
            <AdminLayout>
                <PageBreadcrumb :pageTitle="t('FTP Bans')" />
                <Toast />

                <div class="space-y-4">
                    <!-- Info Banner -->
                    <div class="flex items-center gap-3 rounded-2xl border border-blue-light-300 bg-blue-light-100 p-4 text-sm text-blue-light-900 dark:border-blue-light-800 dark:bg-blue-light-950 dark:text-blue-light-100">
                        <i class="fa-solid fa-circle-info text-base"></i>
                        {{ t('Bans are temporary and will be lost on FTP service restart.') }}
                    </div>

                    <!-- Summary Cards -->
                    <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
                        <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/3">
                            <p class="text-xs uppercase text-gray-500 dark:text-gray-400">{{ t('Active Bans') }}</p>
                            <p class="mt-1 text-2xl font-semibold text-gray-800 dark:text-white/90">{{ activeBans.length }}</p>
                        </div>
                        <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/3">
                            <p class="text-xs uppercase text-gray-500 dark:text-gray-400">{{ t('Whitelist Entries') }}</p>
                            <p class="mt-1 text-2xl font-semibold text-gray-800 dark:text-white/90">{{ whitelistEntries.length }}</p>
                        </div>
                        <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/3">
                            <p class="text-xs uppercase text-gray-500 dark:text-gray-400">{{ t('FTP Container') }}</p>
                            <p class="mt-1 text-2xl font-semibold text-gray-800 dark:text-white/90">
                                <span :class="activeBans.length >= 0 ? 'text-success-600 dark:text-success-400' : 'text-error-600 dark:text-error-400'">
                                    {{ t('Online') }}
                                </span>
                            </p>
                        </div>
                    </div>

                    <!-- Section A: Active Bans -->
                    <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/3">
                        <div class="mb-3 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                            <h3 class="text-sm font-semibold text-gray-800 dark:text-white/90">
                                <i class="fa-solid fa-ban mr-1"></i>
                                {{ t('Active Bans') }}
                                <span v-if="activeBans.length > 0" class="ml-1 text-xs font-normal text-gray-500 dark:text-gray-400">
                                    ({{ activeBans.length }})
                                </span>
                            </h3>
                            <div class="flex items-center gap-2">
                                <button
                                    type="button"
                                    class="inline-flex h-8 items-center justify-center rounded-lg border border-gray-300 px-3 text-xs font-medium text-gray-700 hover:bg-gray-100 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-gray-800"
                                    @click="refreshData"
                                >
                                    {{ t('Refresh') }}
                                </button>
                            </div>
                        </div>

                        <!-- Manual Ban Form -->
                        <div class="mb-4 flex items-center gap-2">
                            <input
                                v-model="banIpInput"
                                type="text"
                                :placeholder="t('IP address to ban')"
                                class="h-8 w-56 rounded-lg border border-gray-300 bg-transparent px-3 text-xs text-gray-700 placeholder-gray-400 outline-none focus:border-brand-500 focus:ring-1 focus:ring-brand-500 dark:border-gray-700 dark:text-gray-300 dark:placeholder-gray-500"
                                @keyup.enter="banIp"
                            />
                            <button
                                type="button"
                                :disabled="banLoading || !banIpInput.trim()"
                                class="inline-flex h-8 items-center justify-center rounded-lg bg-error-500 px-4 text-xs font-medium text-white hover:bg-error-600 disabled:cursor-not-allowed disabled:opacity-40"
                                @click="banIp"
                            >
                                {{ t('Ban') }}
                            </button>
                        </div>

                        <div class="overflow-auto">
                            <table class="w-full text-sm">
                                <thead>
                                    <tr class="border-b border-gray-200 text-left text-xs uppercase text-gray-500 dark:border-gray-800 dark:text-gray-400">
                                        <th class="pb-2">{{ t('IP') }}</th>
                                        <th class="pb-2">{{ t('Reason') }}</th>
                                        <th class="pb-2">{{ t('Banned At') }}</th>
                                        <th class="pb-2">{{ t('Expires') }}</th>
                                        <th class="pb-2">{{ t('Actions') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-if="activeBans.length === 0">
                                        <td colspan="5" class="py-4 text-center text-gray-500 dark:text-gray-400">{{ t('No active bans.') }}</td>
                                    </tr>
                                    <tr
                                        v-for="ban in activeBans"
                                        :key="ban.ip"
                                        class="border-b border-gray-100 align-top last:border-0 dark:border-gray-800"
                                    >
                                        <td class="py-2 font-mono text-xs text-gray-700 dark:text-gray-300">{{ ban.ip }}</td>
                                        <td class="py-2 text-xs text-gray-700 dark:text-gray-300">{{ ban.reason }}</td>
                                        <td class="py-2 text-xs text-gray-500 dark:text-gray-400">{{ formatDate(ban.added) }}</td>
                                        <td class="py-2 text-xs text-gray-500 dark:text-gray-400">{{ formatDate(ban.expires) }}</td>
                                        <td class="py-2">
                                            <button
                                                type="button"
                                                :disabled="unbanLoading === ban.ip"
                                                class="inline-flex h-7 items-center justify-center rounded-lg border border-gray-300 px-3 text-xs font-medium text-gray-700 hover:bg-gray-100 disabled:cursor-not-allowed disabled:opacity-40 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-gray-800"
                                                @click="unbanIp(ban.ip)"
                                            >
                                                {{ t('Unban') }}
                                            </button>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Section B: Whitelist -->
                    <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/3">
                        <h3 class="mb-3 text-sm font-semibold text-gray-800 dark:text-white/90">
                            <i class="fa-solid fa-shield-halved mr-1"></i>
                            {{ t('Whitelist') }}
                            <span v-if="whitelistEntries.length > 0" class="ml-1 text-xs font-normal text-gray-500 dark:text-gray-400">
                                ({{ whitelistEntries.length }})
                            </span>
                        </h3>

                        <!-- Add Whitelist Form -->
                        <div class="mb-4 flex items-center gap-2">
                            <input
                                v-model="whitelistIpInput"
                                type="text"
                                :placeholder="t('IP address')"
                                class="h-8 w-44 rounded-lg border border-gray-300 bg-transparent px-3 text-xs text-gray-700 placeholder-gray-400 outline-none focus:border-brand-500 focus:ring-1 focus:ring-brand-500 dark:border-gray-700 dark:text-gray-300 dark:placeholder-gray-500"
                            />
                            <input
                                v-model="whitelistNoteInput"
                                type="text"
                                :placeholder="t('Note (optional)')"
                                class="h-8 w-56 rounded-lg border border-gray-300 bg-transparent px-3 text-xs text-gray-700 placeholder-gray-400 outline-none focus:border-brand-500 focus:ring-1 focus:ring-brand-500 dark:border-gray-700 dark:text-gray-300 dark:placeholder-gray-500"
                                @keyup.enter="addWhitelist"
                            />
                            <button
                                type="button"
                                :disabled="whitelistAddLoading || !whitelistIpInput.trim()"
                                class="inline-flex h-8 items-center justify-center rounded-lg bg-brand-500 px-4 text-xs font-medium text-white hover:bg-brand-600 disabled:cursor-not-allowed disabled:opacity-40"
                                @click="addWhitelist"
                            >
                                {{ t('Add to Whitelist') }}
                            </button>
                        </div>

                        <div class="overflow-auto">
                            <table class="w-full text-sm">
                                <thead>
                                    <tr class="border-b border-gray-200 text-left text-xs uppercase text-gray-500 dark:border-gray-800 dark:text-gray-400">
                                        <th class="pb-2">{{ t('IP Address') }}</th>
                                        <th class="pb-2">{{ t('Note') }}</th>
                                        <th class="pb-2">{{ t('Added By') }}</th>
                                        <th class="pb-2">{{ t('Created At') }}</th>
                                        <th class="pb-2">{{ t('Actions') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-if="whitelistEntries.length === 0">
                                        <td colspan="5" class="py-4 text-center text-gray-500 dark:text-gray-400">{{ t('No whitelist entries.') }}</td>
                                    </tr>
                                    <tr
                                        v-for="entry in whitelistEntries"
                                        :key="entry.id"
                                        class="border-b border-gray-100 align-top last:border-0 dark:border-gray-800"
                                    >
                                        <td class="py-2 font-mono text-xs text-gray-700 dark:text-gray-300">{{ entry.ip_address }}</td>
                                        <td class="py-2 text-xs text-gray-700 dark:text-gray-300">{{ entry.note ?? '-' }}</td>
                                        <td class="py-2 text-xs text-gray-700 dark:text-gray-300">{{ entry.creator.name }}</td>
                                        <td class="py-2 text-xs text-gray-500 dark:text-gray-400">{{ formatDate(entry.created_at) }}</td>
                                        <td class="py-2">
                                            <button
                                                type="button"
                                                :disabled="whitelistRemoveLoading === entry.ip_address"
                                                class="inline-flex h-7 items-center justify-center rounded-lg border border-error-300 px-3 text-xs font-medium text-error-600 hover:bg-error-50 disabled:cursor-not-allowed disabled:opacity-40 dark:border-error-700 dark:text-error-400 dark:hover:bg-error-950"
                                                @click="removeWhitelist(entry.ip_address)"
                                            >
                                                {{ t('Remove') }}
                                            </button>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Section C: Ban Log -->
                    <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/3">
                        <div class="mb-3 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                            <h3 class="text-sm font-semibold text-gray-800 dark:text-white/90">
                                <i class="fa-solid fa-scroll mr-1"></i>
                                {{ t('Ban Log') }}
                            </h3>
                            <div class="flex items-center gap-2">
                                <select
                                    v-model="logLines"
                                    class="h-8 rounded-lg border border-gray-300 bg-transparent px-2 text-xs text-gray-700 outline-none focus:border-brand-500 focus:ring-1 focus:ring-brand-500 dark:border-gray-700 dark:text-gray-300"
                                    @change="loadLog"
                                >
                                    <option :value="50">50 {{ t('lines') }}</option>
                                    <option :value="100">100 {{ t('lines') }}</option>
                                    <option :value="200">200 {{ t('lines') }}</option>
                                </select>
                                <button
                                    type="button"
                                    :disabled="logLoading"
                                    class="inline-flex h-8 items-center justify-center rounded-lg border border-gray-300 px-3 text-xs font-medium text-gray-700 hover:bg-gray-100 disabled:cursor-not-allowed disabled:opacity-40 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-gray-800"
                                    @click="loadLog"
                                >
                                    {{ t('Refresh') }}
                                </button>
                            </div>
                        </div>

                        <div v-if="logLoading && !logContent" class="py-8 text-center text-sm text-gray-500 dark:text-gray-400">
                            {{ t('Loading...') }}
                        </div>
                        <pre
                            v-else
                            class="max-h-96 overflow-auto rounded-lg bg-gray-900 p-4 font-mono text-xs leading-5 text-gray-200 dark:bg-gray-950"
                        >{{ logContent || t('No log entries.') }}</pre>
                    </div>
                </div>
            </AdminLayout>
        </SidebarProvider>
    </ThemeProvider>
</template>

<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import axios from 'axios';
import ThemeProvider from '@/Components/Layout/ThemeProvider.vue';
import SidebarProvider from '@/Components/Layout/SidebarProvider.vue';
import AdminLayout from '@/Components/Layout/AdminLayout.vue';
import PageBreadcrumb from '@/Components/Common/PageBreadcrumb.vue';
import Toast from '@/Components/UI/Toast.vue';
import { useToast } from '@/Composables/useToast';
import { useI18n } from '@/Composables/useI18n';

interface FtpBan {
    ip: string;
    reason: string;
    added: string | null;
    expires: string | null;
}

interface WhitelistEntry {
    id: number;
    ip_address: string;
    note: string | null;
    creator: { id: number; name: string };
    created_at: string;
}

interface FtpBansPayload {
    bans: {
        hosts: FtpBan[];
    };
    whitelist: WhitelistEntry[];
}

const props = defineProps<FtpBansPayload>();
const { t } = useI18n();
const { addToast } = useToast();

const activeBans = ref<FtpBan[]>(props.bans.hosts);
const whitelistEntries = ref<WhitelistEntry[]>(props.whitelist);

const banIpInput = ref('');
const banLoading = ref(false);
const unbanLoading = ref<string | null>(null);

const whitelistIpInput = ref('');
const whitelistNoteInput = ref('');
const whitelistAddLoading = ref(false);
const whitelistRemoveLoading = ref<string | null>(null);

const logLines = ref(100);
const logContent = ref('');
const logLoading = ref(false);

let refreshTimer: ReturnType<typeof setInterval> | null = null;

const formatDate = (value: string | null): string => {
    if (!value) return '-';
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return value;
    return date.toLocaleString();
};

const refreshData = async (): Promise<void> => {
    try {
        const response = await axios.get<FtpBansPayload>(route('security.ftp-bans.data'));
        activeBans.value = response.data.bans.hosts;
        whitelistEntries.value = response.data.whitelist;
    } catch {
        addToast('error', t('FTP ban data could not be refreshed.'));
    }
};

const banIp = async (): Promise<void> => {
    const ip = banIpInput.value.trim();
    if (!ip) return;

    banLoading.value = true;
    try {
        await axios.post(route('security.ftp-bans.store'), { ip });
        addToast('success', t('IP :ip has been banned.').replace(':ip', ip));
        banIpInput.value = '';
        await refreshData();
    } catch {
        addToast('error', t('Failed to ban IP :ip.').replace(':ip', ip));
    } finally {
        banLoading.value = false;
    }
};

const unbanIp = async (ip: string): Promise<void> => {
    unbanLoading.value = ip;
    try {
        await axios.delete(route('security.ftp-bans.destroy'), { data: { ip } });
        addToast('success', t('IP :ip has been unbanned.').replace(':ip', ip));
        await refreshData();
    } catch {
        addToast('error', t('Failed to unban IP :ip.').replace(':ip', ip));
    } finally {
        unbanLoading.value = null;
    }
};

const addWhitelist = async (): Promise<void> => {
    const ip = whitelistIpInput.value.trim();
    if (!ip) return;

    whitelistAddLoading.value = true;
    try {
        await axios.post(route('security.ftp-bans.whitelist.store'), {
            ip,
            note: whitelistNoteInput.value.trim() || null,
        });
        addToast('success', t('IP :ip has been added to the whitelist.').replace(':ip', ip));
        whitelistIpInput.value = '';
        whitelistNoteInput.value = '';
        await refreshData();
    } catch {
        addToast('error', t('Failed to add IP :ip to the whitelist.').replace(':ip', ip));
    } finally {
        whitelistAddLoading.value = false;
    }
};

const removeWhitelist = async (ip: string): Promise<void> => {
    whitelistRemoveLoading.value = ip;
    try {
        await axios.delete(route('security.ftp-bans.whitelist.destroy'), { data: { ip } });
        addToast('success', t('IP :ip has been removed from the whitelist.').replace(':ip', ip));
        await refreshData();
    } catch {
        addToast('error', t('Failed to remove IP :ip from the whitelist.').replace(':ip', ip));
    } finally {
        whitelistRemoveLoading.value = null;
    }
};

const loadLog = async (): Promise<void> => {
    logLoading.value = true;
    try {
        const response = await axios.get<{ content: string }>(route('security.ftp-bans.log'), {
            params: { lines: logLines.value },
        });
        logContent.value = response.data.content;
    } catch {
        addToast('error', t('Failed to load ban log.'));
    } finally {
        logLoading.value = false;
    }
};

onMounted(() => {
    void loadLog();

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
