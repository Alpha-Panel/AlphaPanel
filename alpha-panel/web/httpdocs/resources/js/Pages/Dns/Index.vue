<template>
    <Head :title="`${t('DNS')} - ${domain.fqdn}`" />
    <ThemeProvider>
        <SidebarProvider>
            <AdminLayout>
                <PageBreadcrumb
                    :pageTitle="t('DNS Records')"
                    :items="breadcrumbs"
                    :backHref="route('domains.show', domain.id)"
                />
                <Toast />

                <!-- Provider Switch Bar -->
                <div class="mb-4 flex flex-col gap-3 rounded-2xl border border-gray-200 bg-white p-4 sm:flex-row sm:items-center sm:justify-between dark:border-gray-800 dark:bg-white/[0.03] md:p-5">
                    <div class="flex items-center gap-3">
                        <span class="text-sm font-medium text-gray-700 dark:text-gray-300">{{ t('DNS Provider') }}:</span>
                        <span
                            class="inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-semibold"
                            :class="currentProvider === 'cloudflare'
                                ? 'bg-orange-100 text-orange-700 dark:bg-orange-900/30 dark:text-orange-300'
                                : 'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-300'"
                        >
                            <i :class="currentProvider === 'cloudflare' ? 'fa-brands fa-cloudflare' : 'fa-solid fa-server'" class="text-[10px]"></i>
                            {{ currentProvider === 'cloudflare' ? 'Cloudflare' : t('Local DNS') }}
                        </span>
                    </div>
                    <div class="flex items-center gap-2">
                        <button
                            v-if="currentProvider === 'cloudflare'"
                            type="button"
                            :disabled="switchLoading"
                            @click="showSwitchModal = true"
                            class="inline-flex items-center gap-2 rounded-lg border border-blue-500/40 bg-blue-500/10 px-3 py-2 text-sm font-medium text-blue-700 transition-colors hover:bg-blue-500/20 disabled:opacity-60 dark:text-blue-300"
                        >
                            <i class="fa-solid fa-arrow-right-arrow-left text-xs"></i>
                            {{ t('Switch to Local DNS') }}
                        </button>
                        <button
                            v-else
                            type="button"
                            :disabled="switchLoading"
                            @click="switchToCloudflare"
                            class="inline-flex items-center gap-2 rounded-lg border border-orange-500/40 bg-orange-500/10 px-3 py-2 text-sm font-medium text-orange-700 transition-colors hover:bg-orange-500/20 disabled:opacity-60 dark:text-orange-300"
                        >
                            <i class="fa-solid fa-arrow-right-arrow-left text-xs"></i>
                            {{ t('Switch to Cloudflare') }}
                        </button>
                    </div>
                </div>

                <!-- Switch to Local DNS Modal (with import option) -->
                <div
                    v-if="showSwitchModal"
                    class="fixed inset-0 z-[1200000] flex items-center justify-center bg-black/60 p-4 backdrop-blur-sm"
                >
                    <div class="w-full max-w-md rounded-2xl border border-gray-200 bg-white p-6 shadow-theme-xl dark:border-gray-800 dark:bg-gray-900">
                        <h4 class="mb-4 text-base font-semibold text-gray-800 dark:text-white/90">{{ t('Switch to Local DNS') }}</h4>
                        <p class="mb-4 text-sm text-gray-600 dark:text-gray-400">
                            {{ t('Do you want to import your existing Cloudflare DNS records to the local DNS server?') }}
                        </p>
                        <label class="mb-5 flex items-center gap-2">
                            <input v-model="importRecordsOnSwitch" type="checkbox" class="h-4 w-4 rounded border-gray-300 text-brand-500 focus:ring-brand-500" />
                            <span class="text-sm text-gray-700 dark:text-gray-400">{{ t('Import Cloudflare records') }}</span>
                        </label>
                        <div class="flex items-center justify-end gap-3">
                            <button
                                type="button"
                                @click="showSwitchModal = false"
                                class="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-400"
                            >
                                {{ t('Cancel') }}
                            </button>
                            <button
                                type="button"
                                :disabled="switchLoading"
                                @click="switchToLocal"
                                class="inline-flex items-center gap-2 rounded-lg bg-brand-500 px-4 py-2 text-sm font-medium text-white hover:bg-brand-600 disabled:opacity-60"
                            >
                                <i v-if="switchLoading" class="bx bx-loader-alt animate-spin text-base"></i>
                                {{ switchLoading ? t('Switching...') : t('Switch') }}
                            </button>
                        </div>
                    </div>
                </div>

                <div class="rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
                    <div class="flex flex-col gap-4 border-b border-gray-200 px-5 py-4 sm:flex-row sm:items-center sm:justify-between dark:border-gray-800 md:px-6">
                        <h3 class="text-lg font-semibold text-gray-800 dark:text-white/90">{{ t('DNS Records') }}</h3>
                        <button
                            type="button"
                            @click="openAddModal"
                            class="inline-flex items-center gap-2 rounded-lg bg-brand-500 px-4 py-2.5 text-sm font-medium text-white shadow-theme-xs hover:bg-brand-600"
                        >
                            <i class="fa-solid fa-square-plus text-xs"></i>
                            {{ t('Add Record') }}
                        </button>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="w-full">
                            <thead>
                                <tr class="border-b border-gray-200 dark:border-gray-800">
                                    <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-500">{{ t('Type') }}</th>
                                    <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-500">{{ t('Name') }}</th>
                                    <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-500">{{ t('Value') }}</th>
                                    <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-500">{{ t('TTL') }}</th>
                                    <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-500">{{ t('Status') }}</th>
                                    <th class="px-5 py-3 text-right text-xs font-medium uppercase text-gray-500">
                                        <i class="fa-solid fa-gears"></i>
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-if="loading" class="border-t border-gray-200 dark:border-gray-800">
                                    <td colspan="6" class="px-5 py-10 text-center text-sm text-gray-500 dark:text-gray-400">
                                        {{ t('Loading records...') }}
                                    </td>
                                </tr>
                                <tr v-else-if="records.length === 0" class="border-t border-gray-200 dark:border-gray-800">
                                    <td colspan="6" class="px-5 py-10 text-center text-sm text-gray-500 dark:text-gray-400">
                                        {{ t('No DNS records found.') }}
                                    </td>
                                </tr>
                                <tr
                                    v-for="record in records"
                                    :key="record.id"
                                    class="border-t border-gray-200 hover:bg-gray-50 dark:border-gray-800 dark:hover:bg-white/[0.02]"
                                >
                                    <td class="px-5 py-3">
                                        <span class="inline-flex rounded bg-gray-100 px-2 py-0.5 text-xs font-semibold text-gray-700 dark:bg-gray-800 dark:text-gray-300">
                                            {{ record.type }}
                                        </span>
                                    </td>
                                    <td class="px-5 py-3 text-sm text-gray-800 dark:text-white/90">{{ record.name }}</td>
                                    <td class="wordbreak px-5 py-3 text-sm text-gray-600 dark:text-gray-400">{{ record.content }}</td>
                                    <td class="px-5 py-3 text-sm text-gray-600 dark:text-gray-400">{{ record.ttl }}</td>
                                    <td class="px-5 py-3 text-sm" v-html="record.status"></td>
                                    <td class="px-5 py-3 text-right">
                                        <div class="flex items-center justify-end gap-2">
                                            <button
                                                type="button"
                                                class="inline-flex h-8 w-8 items-center justify-center rounded-md border border-brand-500/30 text-brand-600 hover:bg-brand-500/10 dark:text-brand-300"
                                                :title="t('Edit')"
                                                @click="openEditModal(record)"
                                            >
                                                <i class="fa-solid fa-pen text-xs"></i>
                                            </button>
                                            <button
                                                type="button"
                                                class="inline-flex h-8 w-8 items-center justify-center rounded-md border border-error-500/30 text-error-600 hover:bg-error-500/10 dark:text-error-400"
                                                :title="t('Delete')"
                                                @click="openDeleteModal(record)"
                                            >
                                                <i class="fa-solid fa-trash text-xs"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div
                    v-if="showFormModal"
                    class="fixed inset-0 z-[1200000] flex items-center justify-center bg-black/60 p-4 backdrop-blur-sm"
                >
                    <div class="w-full max-w-3xl rounded-2xl border border-gray-200 bg-white shadow-theme-xl dark:border-gray-800 dark:bg-gray-900">
                        <div class="flex items-center justify-between border-b border-gray-200 px-5 py-4 dark:border-gray-800 md:px-6">
                            <h4 class="text-base font-semibold text-gray-800 dark:text-white/90">{{ formModalTitle }}</h4>
                            <button
                                type="button"
                                class="text-2xl leading-none text-gray-400 hover:text-gray-700 dark:hover:text-gray-200"
                                @click="closeFormModal"
                            >
                                &times;
                            </button>
                        </div>

                        <form class="grid grid-cols-1 gap-4 px-5 py-5 md:grid-cols-12 md:px-6" @submit.prevent="saveRecord">
                            <div class="md:col-span-12">
                                <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-gray-500">{{ t('Type') }}</label>
                                <select v-model="recordForm.record_type" class="form-input">
                                    <option v-for="type in dnsTypes" :key="type" :value="type">{{ type }}</option>
                                </select>
                                <p v-if="formErrors.record_type" class="mt-1 text-xs text-error-500">{{ formErrors.record_type }}</p>
                            </div>

                            <div class="md:col-span-12">
                                <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-gray-500">{{ t('Name') }}</label>
                                <input v-model="recordForm.name" type="text" class="form-input" :placeholder="t('@')" />
                                <p v-if="formErrors.name" class="mt-1 text-xs text-error-500">{{ formErrors.name }}</p>
                            </div>

                            <template v-if="isSimpleAddressType">
                                <div class="md:col-span-12">
                                    <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-gray-500">{{ t('Content') }}</label>
                                    <input v-model="recordForm.content" type="text" class="form-input" :placeholder="t('IP or host value')" />
                                    <p v-if="formErrors.content" class="mt-1 text-xs text-error-500">{{ formErrors.content }}</p>
                                </div>
                            </template>

                            <template v-else-if="isTxtType">
                                <div class="md:col-span-12">
                                    <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-gray-500">{{ t('Content') }}</label>
                                    <textarea v-model="recordForm.content" class="form-textarea" rows="4" :placeholder="t('TXT value')"></textarea>
                                    <p v-if="formErrors.content" class="mt-1 text-xs text-error-500">{{ formErrors.content }}</p>
                                </div>
                            </template>

                            <template v-else-if="isMxType">
                                <div class="md:col-span-6">
                                    <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-gray-500">{{ t('Priority') }}</label>
                                    <input v-model.number="recordForm.priority" type="number" min="0" class="form-input" placeholder="10" />
                                    <p v-if="formErrors.priority" class="mt-1 text-xs text-error-500">{{ formErrors.priority }}</p>
                                </div>
                                <div class="md:col-span-6">
                                    <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-gray-500">{{ t('Content') }}</label>
                                    <input v-model="recordForm.content" type="text" class="form-input" :placeholder="t('mail.example.com')" />
                                    <p v-if="formErrors.content" class="mt-1 text-xs text-error-500">{{ formErrors.content }}</p>
                                </div>
                            </template>

                            <template v-else-if="isCaaType">
                                <div class="md:col-span-6">
                                    <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-gray-500">{{ t('Flags') }}</label>
                                    <input v-model.number="recordForm.flags" type="number" min="0" class="form-input" placeholder="0" />
                                    <p v-if="formErrors.flags" class="mt-1 text-xs text-error-500">{{ formErrors.flags }}</p>
                                </div>
                                <div class="md:col-span-6">
                                    <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-gray-500">{{ t('Tag') }}</label>
                                    <input v-model="recordForm.tag" type="text" class="form-input" :placeholder="t('issue')" />
                                    <p v-if="formErrors.tag" class="mt-1 text-xs text-error-500">{{ formErrors.tag }}</p>
                                </div>
                                <div class="md:col-span-12">
                                    <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-gray-500">{{ t('Content') }}</label>
                                    <input v-model="recordForm.content" type="text" class="form-input" :placeholder="t('letsencrypt.org')" />
                                    <p v-if="formErrors.content" class="mt-1 text-xs text-error-500">{{ formErrors.content }}</p>
                                </div>
                            </template>

                            <template v-else-if="isSrvType">
                                <div class="md:col-span-4">
                                    <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-gray-500">{{ t('Priority') }}</label>
                                    <input v-model.number="recordForm.priority" type="number" min="0" class="form-input" placeholder="0" />
                                    <p v-if="formErrors.priority" class="mt-1 text-xs text-error-500">{{ formErrors.priority }}</p>
                                </div>
                                <div class="md:col-span-4">
                                    <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-gray-500">{{ t('Weight') }}</label>
                                    <input v-model.number="recordForm.weight" type="number" min="0" class="form-input" placeholder="0" />
                                    <p v-if="formErrors.weight" class="mt-1 text-xs text-error-500">{{ formErrors.weight }}</p>
                                </div>
                                <div class="md:col-span-4">
                                    <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-gray-500">{{ t('Port') }}</label>
                                    <input v-model.number="recordForm.port" type="number" min="0" class="form-input" placeholder="443" />
                                    <p v-if="formErrors.port" class="mt-1 text-xs text-error-500">{{ formErrors.port }}</p>
                                </div>
                                <div class="md:col-span-6">
                                    <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-gray-500">{{ t('Service') }}</label>
                                    <input v-model="recordForm.service" type="text" class="form-input" placeholder="_sip" />
                                    <p v-if="formErrors.service" class="mt-1 text-xs text-error-500">{{ formErrors.service }}</p>
                                </div>
                                <div class="md:col-span-6">
                                    <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-gray-500">{{ t('Protocol') }}</label>
                                    <select v-model="recordForm.protocol" class="form-input">
                                        <option value="_tls">{{ t('TLS') }}</option>
                                        <option value="_tcp">{{ t('TCP') }}</option>
                                        <option value="_udp">{{ t('UDP') }}</option>
                                    </select>
                                    <p v-if="formErrors.protocol" class="mt-1 text-xs text-error-500">{{ formErrors.protocol }}</p>
                                </div>
                                <div class="md:col-span-12">
                                    <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-gray-500">{{ t('Target') }}</label>
                                    <input v-model="recordForm.target" type="text" class="form-input" :placeholder="t('target.example.com')" />
                                    <p v-if="formErrors.target" class="mt-1 text-xs text-error-500">{{ formErrors.target }}</p>
                                </div>
                            </template>

                            <template v-else-if="isHttpsType">
                                <div class="md:col-span-6">
                                    <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-gray-500">{{ t('Priority') }}</label>
                                    <input v-model.number="recordForm.priority" type="number" min="0" class="form-input" placeholder="1" />
                                    <p v-if="formErrors.priority" class="mt-1 text-xs text-error-500">{{ formErrors.priority }}</p>
                                </div>
                                <div class="md:col-span-6">
                                    <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-gray-500">{{ t('Target') }}</label>
                                    <input v-model="recordForm.target" type="text" class="form-input" :placeholder="t('example.com')" />
                                    <p v-if="formErrors.target" class="mt-1 text-xs text-error-500">{{ formErrors.target }}</p>
                                </div>
                                <div class="md:col-span-12">
                                    <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-gray-500">{{ t('Content') }}</label>
                                    <input v-model="recordForm.content" type="text" class="form-input" :placeholder="t('alpn=h3,h2 ipv4hint=...')" />
                                    <p v-if="formErrors.content" class="mt-1 text-xs text-error-500">{{ formErrors.content }}</p>
                                </div>
                            </template>

                            <div class="md:col-span-6">
                                <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-gray-500">{{ t('TTL') }}</label>
                                <input v-model.number="recordForm.ttl" type="number" min="1" class="form-input" />
                                <p v-if="formErrors.ttl" class="mt-1 text-xs text-error-500">{{ formErrors.ttl }}</p>
                            </div>

                            <div v-if="isProxyType" class="md:col-span-6">
                                <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-gray-500">{{ t('Proxied') }}</label>
                                <select v-model="recordForm.status" class="form-input">
                                    <option value="1">{{ t('Active') }}</option>
                                    <option value="0">{{ t('Passive') }}</option>
                                </select>
                            </div>

                            <div class="md:col-span-12 flex items-center justify-end gap-2 pt-1">
                                <button
                                    type="button"
                                    class="rounded-lg border border-gray-300 px-4 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-400 dark:hover:bg-gray-800"
                                    @click="closeFormModal"
                                >
                                    {{ t('Close') }}
                                </button>
                                <button
                                    type="submit"
                                    :disabled="saving"
                                    class="inline-flex items-center gap-2 rounded-lg bg-brand-500 px-4 py-2.5 text-sm font-medium text-white hover:bg-brand-600 disabled:opacity-50"
                                >
                                    <i class="fa-solid fa-floppy-disk text-xs"></i>
                                    {{ submitButtonLabel }}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <div
                    v-if="showDeleteModal && selectedRecord"
                    class="fixed inset-0 z-[1200000] flex items-center justify-center bg-black/60 p-4 backdrop-blur-sm"
                >
                    <div class="w-full max-w-lg rounded-2xl border border-gray-200 bg-white shadow-theme-xl dark:border-gray-800 dark:bg-gray-900">
                        <div class="flex items-center justify-between border-b border-gray-200 px-5 py-4 dark:border-gray-800">
                            <h4 class="text-base font-semibold text-gray-800 dark:text-white/90">{{ t('DNS Delete') }}</h4>
                            <button
                                type="button"
                                class="text-2xl leading-none text-gray-400 hover:text-gray-700 dark:hover:text-gray-200"
                                @click="closeDeleteModal"
                            >
                                &times;
                            </button>
                        </div>
                        <div class="px-5 py-5">
                            <p class="text-sm text-gray-700 dark:text-gray-300">{{ t('Are you sure you want to delete this DNS record?') }}</p>
                            <p class="mt-2 break-all rounded-lg bg-gray-100 px-3 py-2 text-sm text-gray-700 dark:bg-gray-800 dark:text-gray-300">
                                {{ selectedRecord.name }} {{ selectedRecord.type }} {{ selectedRecord.content }}
                            </p>
                            <div class="mt-5 flex items-center justify-end gap-2">
                                <button
                                    type="button"
                                    class="rounded-lg border border-gray-300 px-4 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-400 dark:hover:bg-gray-800"
                                    @click="closeDeleteModal"
                                >
                                    {{ t('Close') }}
                                </button>
                                <button
                                    type="button"
                                    :disabled="deleting"
                                    class="inline-flex items-center gap-2 rounded-lg bg-error-500 px-4 py-2.5 text-sm font-medium text-white hover:bg-error-600 disabled:opacity-50"
                                    @click="deleteRecord"
                                >
                                    <i class="fa-solid fa-trash text-xs"></i>
                                    {{ deleting ? t('Deleting...') : t('Delete') }}
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </AdminLayout>
        </SidebarProvider>
    </ThemeProvider>
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import axios from 'axios';
import ThemeProvider from '@/Components/Layout/ThemeProvider.vue';
import SidebarProvider from '@/Components/Layout/SidebarProvider.vue';
import AdminLayout from '@/Components/Layout/AdminLayout.vue';
import PageBreadcrumb from '@/Components/Common/PageBreadcrumb.vue';
import Toast from '@/Components/UI/Toast.vue';
import { useToast } from '@/Composables/useToast';
import { useI18n } from '@/Composables/useI18n';

interface DnsRecord {
    id: string;
    type: string;
    name: string;
    content: string;
    ttl: number | string;
    proxied: boolean;
    status: string;
    all_data?: Record<string, any>;
}

interface DnsRecordForm {
    dns_id: string | null;
    record_type: string;
    name: string;
    content: string;
    ttl: number;
    status: '0' | '1';
    priority: number | null;
    service: string;
    protocol: string;
    weight: number | null;
    port: number | null;
    target: string;
    flags: number | null;
    tag: string;
}

const props = defineProps<{
    domain: Record<string, any>;
    dns_provider?: string;
}>();
const { t } = useI18n();

const breadcrumbs = computed(() => [
    { label: t('Domains'), href: route('domains.index') },
    { label: props.domain.fqdn, href: route('domains.show', props.domain.id) },
    { label: t('DNS Records') },
]);

const dnsTypes = ['A', 'AAAA', 'CNAME', 'MX', 'TXT', 'SRV', 'HTTPS', 'CAA'];
const proxyTypes = ['A', 'AAAA', 'CNAME'];

const { addToast } = useToast();
const currentProvider = ref(props.dns_provider ?? props.domain.dns_provider ?? 'local');
const switchLoading = ref(false);
const showSwitchModal = ref(false);
const importRecordsOnSwitch = ref(true);

const records = ref<DnsRecord[]>([]);
const loading = ref(false);
const saving = ref(false);
const deleting = ref(false);
const showFormModal = ref(false);
const showDeleteModal = ref(false);
const selectedRecord = ref<DnsRecord | null>(null);
const formErrors = ref<Record<string, string>>({});

const defaultRecordForm = (): DnsRecordForm => ({
    dns_id: null,
    record_type: 'A',
    name: '',
    content: '',
    ttl: 1,
    status: '1',
    priority: null,
    service: '',
    protocol: '_tls',
    weight: null,
    port: null,
    target: '',
    flags: 0,
    tag: 'issue',
});

const recordForm = ref<DnsRecordForm>(defaultRecordForm());

const isSimpleAddressType = computed(() => proxyTypes.includes(recordForm.value.record_type));
const isTxtType = computed(() => recordForm.value.record_type === 'TXT');
const isMxType = computed(() => recordForm.value.record_type === 'MX');
const isCaaType = computed(() => recordForm.value.record_type === 'CAA');
const isSrvType = computed(() => recordForm.value.record_type === 'SRV');
const isHttpsType = computed(() => recordForm.value.record_type === 'HTTPS');
const isProxyType = computed(() => proxyTypes.includes(recordForm.value.record_type));

const formModalTitle = computed(() => (recordForm.value.dns_id ? t('DNS Edit') : t('DNS Add')));
const submitButtonLabel = computed(() => {
    if (saving.value) {
        return t('Saving...');
    }

    return recordForm.value.dns_id ? t('Save') : t('Add');
});

const getFirstError = (errors: Record<string, string[] | string>): Record<string, string> => {
    const mapped: Record<string, string> = {};

    Object.entries(errors).forEach(([field, messages]) => {
        if (Array.isArray(messages)) {
            mapped[field] = messages[0] ?? t('Invalid field.');
            return;
        }

        mapped[field] = messages;
    });

    return mapped;
};

const normalizeTtl = (value: number | string): number => {
    if (value === 'Auto') {
        return 1;
    }

    const ttl = Number(value);

    if (!Number.isFinite(ttl) || ttl < 1) {
        return 1;
    }

    return ttl;
};

const toInteger = (value: number | null, fallback: number): number => {
    if (value === null || Number.isNaN(value)) {
        return fallback;
    }

    return Number(value);
};

const resetForm = (): void => {
    recordForm.value = defaultRecordForm();
    formErrors.value = {};
};

const fetchRecords = async (): Promise<void> => {
    loading.value = true;

    try {
        const response = await axios.get(route('domains.dns.json', props.domain.id));
        records.value = response.data.data as DnsRecord[];
    } catch (error: any) {
        addToast('error', error.response?.data?.error ?? t('Failed to load DNS records.'));
    } finally {
        loading.value = false;
    }
};

const openAddModal = (): void => {
    resetForm();
    showFormModal.value = true;
};

const openEditModal = (record: DnsRecord): void => {
    const allData = record.all_data ?? {};
    const rawData = allData.data ?? {};
    const form = defaultRecordForm();

    form.dns_id = record.id;
    form.record_type = record.type;
    form.ttl = normalizeTtl(record.ttl);
    form.status = record.proxied ? '1' : '0';

    if (record.type === 'SRV') {
        form.name = rawData.name ?? record.name;
        form.target = rawData.target ?? '';
        form.priority = rawData.priority ?? allData.priority ?? 0;
        form.weight = rawData.weight ?? 0;
        form.port = rawData.port ?? 0;
        form.service = rawData.service ?? '';
        form.protocol = rawData.proto ?? '_tls';
        form.content = allData.content ?? '';
    } else if (record.type === 'CAA') {
        form.name = record.name;
        form.content = rawData.value ?? allData.content ?? '';
        form.flags = rawData.flags ?? 0;
        form.tag = rawData.tag ?? 'issue';
    } else if (record.type === 'HTTPS') {
        form.name = record.name;
        form.priority = rawData.priority ?? allData.priority ?? 1;
        form.target = rawData.target ?? '';
        form.content = rawData.value ?? allData.content ?? '';
    } else if (record.type === 'MX') {
        form.name = record.name;
        form.content = allData.content ?? record.content;
        form.priority = allData.priority ?? 10;
    } else {
        form.name = record.name;
        form.content = allData.content ?? record.content;
    }

    recordForm.value = form;
    formErrors.value = {};
    showFormModal.value = true;
};

const closeFormModal = (): void => {
    showFormModal.value = false;
    resetForm();
};

const openDeleteModal = (record: DnsRecord): void => {
    selectedRecord.value = record;
    showDeleteModal.value = true;
};

const closeDeleteModal = (): void => {
    showDeleteModal.value = false;
    selectedRecord.value = null;
};

const buildPayload = (): Record<string, any> => {
    const form = recordForm.value;
    const payload: Record<string, any> = {
        dns_id: form.dns_id,
        record_type: form.record_type,
        name: form.name,
        ttl: normalizeTtl(form.ttl),
    };

    if (isProxyType.value) {
        payload.proxied = form.status === '1';
    }

    if (form.record_type === 'MX') {
        payload.content = form.content;
        payload.priority = toInteger(form.priority, 10);
        return payload;
    }

    if (form.record_type === 'CAA') {
        payload.content = form.content;
        payload.flags = toInteger(form.flags, 0);
        payload.tag = form.tag || 'issue';
        return payload;
    }

    if (form.record_type === 'SRV') {
        payload.content = form.content || form.target;
        payload.priority = toInteger(form.priority, 0);
        payload.weight = toInteger(form.weight, 0);
        payload.port = toInteger(form.port, 0);
        payload.service = form.service;
        payload.protocol = form.protocol;
        payload.target = form.target;
        return payload;
    }

    if (form.record_type === 'HTTPS') {
        payload.content = form.content;
        payload.priority = toInteger(form.priority, 1);
        payload.target = form.target;
        return payload;
    }

    payload.content = form.content;

    return payload;
};

const saveRecord = async (): Promise<void> => {
    saving.value = true;
    formErrors.value = {};

    try {
        const payload = buildPayload();
        const response = await axios.post(route('domains.dns.store', props.domain.id), payload);
        addToast('success', response.data.message ?? t('DNS record saved.'));
        closeFormModal();
        await fetchRecords();
    } catch (error: any) {
        if (error.response?.status === 422 && error.response?.data?.errors) {
            formErrors.value = getFirstError(error.response.data.errors);
        }

        addToast('error', error.response?.data?.message ?? t('Failed to save DNS record.'));
    } finally {
        saving.value = false;
    }
};

const deleteRecord = async (): Promise<void> => {
    if (!selectedRecord.value) {
        return;
    }

    deleting.value = true;

    try {
        const response = await axios.delete(route('domains.dns.destroy', props.domain.id), {
            data: { dns_id: selectedRecord.value.id },
        });
        addToast('success', response.data.message ?? t('DNS record deleted.'));
        closeDeleteModal();
        await fetchRecords();
    } catch (error: any) {
        addToast('error', error.response?.data?.message ?? t('Failed to delete DNS record.'));
    } finally {
        deleting.value = false;
    }
};

const switchToLocal = async () => {
    switchLoading.value = true;
    try {
        const response = await axios.post(route('domains.dns.switch-provider', props.domain.id), {
            dns_provider: 'local',
            import_records: importRecordsOnSwitch.value,
        });
        addToast({ type: 'success', message: response.data.message });
        currentProvider.value = 'local';
        showSwitchModal.value = false;
        void fetchRecords();
    } catch (e: any) {
        addToast({ type: 'error', message: e.response?.data?.message ?? t('Failed to switch DNS provider.') });
    } finally {
        switchLoading.value = false;
    }
};

const switchToCloudflare = async () => {
    switchLoading.value = true;
    try {
        const response = await axios.post(route('domains.dns.switch-provider', props.domain.id), {
            dns_provider: 'cloudflare',
            import_records: false,
        });
        addToast({ type: 'success', message: response.data.message });
        currentProvider.value = 'cloudflare';
        void fetchRecords();
    } catch (e: any) {
        addToast({ type: 'error', message: e.response?.data?.message ?? t('Failed to switch DNS provider.') });
    } finally {
        switchLoading.value = false;
    }
};

onMounted(() => {
    void fetchRecords();
});
</script>

<style scoped>
@reference "../../../css/app.css";

.form-input {
    @apply h-10 w-full rounded-lg border border-gray-300 bg-transparent px-3 py-2 text-sm text-gray-800 shadow-theme-xs focus:border-brand-300 focus:outline-hidden focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90;
}

.form-textarea {
    @apply w-full rounded-lg border border-gray-300 bg-transparent px-3 py-2 text-sm text-gray-800 shadow-theme-xs focus:border-brand-300 focus:outline-hidden focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90;
}

.wordbreak {
    -ms-word-break: break-all;
    word-break: break-all;
}

:deep(.cloud) {
    border: 1px solid transparent;
    display: block;
    height: 24px;
}
</style>
