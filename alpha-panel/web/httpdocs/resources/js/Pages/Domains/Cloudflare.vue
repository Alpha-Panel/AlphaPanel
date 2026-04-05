<template>
    <Head :title="`${t('Cloudflare')} - ${domain.fqdn}`" />
    <ThemeProvider>
        <SidebarProvider>
            <AdminLayout>
                <PageBreadcrumb
                    :pageTitle="t('Cloudflare')"
                    :items="breadcrumbs"
                    :backHref="route('domains.show', domain.id)"
                />
                <Toast />

                <div class="space-y-4 md:space-y-6">
                    <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/3 md:p-6">
                        <div class="mb-3 flex items-center justify-between">
                            <h3 class="text-sm font-semibold text-gray-800 dark:text-white/90">{{ t('Cloudflare') }}</h3>
                            <span v-if="summaryLoading" class="text-xs text-gray-500 dark:text-gray-400">{{ t('Loading...') }}</span>
                        </div>
                        <div class="grid grid-cols-1 gap-4 text-sm md:grid-cols-3">
                            <div>
                                <p class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ t('Cloudflare Status') }}</p>
                                <p class="mt-1">
                                    <span
                                        class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold"
                                        :class="isCloudflareManagedForDns ? 'bg-success-500/15 text-success-600 dark:text-success-300' : 'bg-gray-200 text-gray-700 dark:bg-gray-700 dark:text-gray-300'"
                                    >
                                        {{ isCloudflareManagedForDns ? t('Connected') : t('Not Connected') }}
                                    </span>
                                </p>
                            </div>
                            <div>
                                <p class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ t('Cloudflare Zone') }}</p>
                                <p class="mt-1 text-gray-800 dark:text-white/90">
                                    {{ cloudflareZoneSummary.exists ? (cloudflareZoneSummary.zone_name ?? domain.fqdn) : t('Not found on Cloudflare') }}
                                </p>
                            </div>
                            <div>
                                <p class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ t('Cloudflare Nameservers') }}</p>
                                <p class="mt-1 text-gray-800 dark:text-white/90">
                                    {{ cloudflareZoneSummary.exists ? joinOrFallback(cloudflareZoneSummary.name_servers) : '-' }}
                                </p>
                            </div>
                        </div>
                    </div>

                    <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/3">
                        <div class="mb-4 flex flex-wrap items-center gap-2">
                            <h4 class="text-sm font-semibold text-gray-800 dark:text-white/90">
                                <i class="fa-brands fa-cloudflare mr-1"></i>
                                {{ t('Cloudflare') }}
                            </h4>
                            <button
                                type="button"
                                class="ml-auto inline-flex items-center gap-1 rounded-lg border border-gray-300 px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-100 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-gray-800 disabled:opacity-60"
                                :disabled="syncLoading"
                                @click="syncCloudflareStatus"
                            >
                                <i class="fa-solid fa-rotate"></i>
                                {{ t('Sync Cloudflare Status') }}
                            </button>
                            <button
                                type="button"
                                class="inline-flex items-center gap-1 rounded-lg border border-warning-500/40 px-3 py-1.5 text-xs font-medium text-warning-700 hover:bg-warning-500/10 dark:text-warning-300 disabled:opacity-60"
                                :disabled="!cloudflareZoneSummary.exists || purgeLoading"
                                @click="purgeCloudflareCache"
                            >
                                <i class="fa-solid fa-broom"></i>
                                {{ t('Purge Cache') }}
                            </button>
                        </div>

                        <div v-if="summaryLoading" class="py-6 text-center text-sm text-gray-500 dark:text-gray-400">
                            {{ t('Loading Cloudflare status...') }}
                        </div>

                        <div v-else-if="!cloudflareZoneSummary.exists" class="rounded-lg border border-dashed border-gray-300 px-4 py-5 text-sm text-gray-600 dark:border-gray-700 dark:text-gray-300">
                            {{ t('Cloudflare zone was not found for this domain.') }}
                        </div>

                        <div v-else class="space-y-5">
                            <div class="rounded-lg border border-gray-200 p-4 dark:border-gray-700">
                                <div class="mb-3 flex items-center justify-between">
                                    <h5 class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ t('Cloudflare') }}</h5>
                                    <span v-if="settingsLoading || settingUpdating" class="text-xs text-gray-500 dark:text-gray-400">{{ t('Loading...') }}</span>
                                </div>

                                <div class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
                                    <label class="space-y-1">
                                        <span class="cf-field-label">{{ t('Firewall Level') }}</span>
                                        <select
                                            v-model="cloudflareForm.security_level"
                                            class="cf-select"
                                            :disabled="settingsControlsDisabled"
                                            @change="updateSecurityLevel"
                                        >
                                            <option value="essentially_off">{{ t('Essentially Off') }}</option>
                                            <option value="low">{{ t('Low') }}</option>
                                            <option value="medium">{{ t('Medium') }}</option>
                                            <option value="high">{{ t('High') }}</option>
                                            <option value="under_attack">{{ t('Under Attack') }}</option>
                                        </select>
                                    </label>

                                    <label class="space-y-1">
                                        <span class="cf-field-label">{{ t('SSL Mode') }}</span>
                                        <select
                                            v-model="cloudflareForm.ssl"
                                            class="cf-select"
                                            :disabled="settingsControlsDisabled"
                                            @change="updateSslMode"
                                        >
                                            <option value="off">{{ t('Off') }}</option>
                                            <option value="flexible">{{ t('Flexible') }}</option>
                                            <option value="full">{{ t('Full') }}</option>
                                            <option value="strict">{{ t('Strict') }}</option>
                                        </select>
                                    </label>

                                    <label class="space-y-1">
                                        <span class="cf-field-label">{{ t('Minimum TLS Version') }}</span>
                                        <select
                                            v-model="cloudflareForm.min_tls_version"
                                            class="cf-select"
                                            :disabled="settingsControlsDisabled"
                                            @change="updateMinimumTlsVersion"
                                        >
                                            <option value="1.0">1.0</option>
                                            <option value="1.1">1.1</option>
                                            <option value="1.2">1.2</option>
                                            <option value="1.3">1.3</option>
                                        </select>
                                    </label>

                                    <label class="space-y-1">
                                        <span class="cf-field-label">{{ t('Browser Cache TTL (seconds)') }}</span>
                                        <input
                                            v-model.number="cloudflareForm.browser_cache_ttl"
                                            type="number"
                                            min="0"
                                            class="cf-input"
                                            :disabled="settingsControlsDisabled"
                                            @change="updateBrowserCacheTtl"
                                        />
                                    </label>

                                    <label class="space-y-1">
                                        <span class="cf-field-label">{{ t('DNSSEC') }}</span>
                                        <div class="flex items-center gap-2">
                                            <select v-model="cloudflareForm.dnssec_status" class="cf-select" :disabled="dnssecControlsDisabled">
                                                <option value="active">{{ t('Active') }}</option>
                                                <option value="disabled">{{ t('Disabled') }}</option>
                                            </select>
                                            <button
                                                type="button"
                                                class="inline-flex h-10 items-center rounded-lg border border-gray-300 px-3 text-xs font-medium text-gray-700 hover:bg-gray-100 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-gray-800 disabled:opacity-60"
                                                :disabled="dnssecControlsDisabled"
                                                @click="updateDnssec"
                                            >
                                                {{ t('Save') }}
                                            </button>
                                        </div>
                                    </label>
                                </div>

                                <div class="mt-4 grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-3">
                                    <label v-for="toggleSetting in toggleSettings" :key="toggleSetting.key" class="inline-flex items-center justify-between rounded-lg border border-gray-200 px-3 py-2 text-xs dark:border-gray-700">
                                        <span class="text-gray-600 dark:text-gray-300">{{ t(toggleSetting.label) }}</span>
                                        <input
                                            :checked="Boolean(cloudflareForm[toggleSetting.key])"
                                            type="checkbox"
                                            class="form-checkbox"
                                            :disabled="settingsControlsDisabled"
                                            @change="toggleSettingValue(toggleSetting.key)"
                                        />
                                    </label>
                                </div>
                            </div>

                            <div class="rounded-lg border border-gray-200 p-4 dark:border-gray-700">
                                <h5 class="mb-3 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ t('HSTS') }}</h5>
                                <div class="grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-4">
                                    <label class="inline-flex items-center gap-2 text-xs text-gray-600 dark:text-gray-300">
                                        <input v-model="cloudflareForm.hsts.enabled" type="checkbox" class="form-checkbox" :disabled="settingsControlsDisabled" />
                                        {{ t('Enabled') }}
                                    </label>
                                    <label class="inline-flex items-center gap-2 text-xs text-gray-600 dark:text-gray-300">
                                        <input v-model="cloudflareForm.hsts.include_subdomains" type="checkbox" class="form-checkbox" :disabled="settingsControlsDisabled" />
                                        {{ t('Include Subdomains') }}
                                    </label>
                                    <label class="inline-flex items-center gap-2 text-xs text-gray-600 dark:text-gray-300">
                                        <input v-model="cloudflareForm.hsts.preload" type="checkbox" class="form-checkbox" :disabled="settingsControlsDisabled" />
                                        {{ t('Preload') }}
                                    </label>
                                    <label class="inline-flex items-center gap-2 text-xs text-gray-600 dark:text-gray-300">
                                        <input v-model="cloudflareForm.hsts.nosniff" type="checkbox" class="form-checkbox" :disabled="settingsControlsDisabled" />
                                        {{ t('NoSniff') }}
                                    </label>
                                </div>
                                <div class="mt-3 flex flex-wrap items-center gap-2">
                                    <input
                                        v-model.number="cloudflareForm.hsts.max_age"
                                        type="number"
                                        min="0"
                                        class="cf-input max-w-60"
                                        :disabled="settingsControlsDisabled"
                                    />
                                    <button
                                        type="button"
                                        class="inline-flex h-10 items-center rounded-lg bg-brand-500 px-3 text-xs font-medium text-white hover:bg-brand-600 disabled:opacity-60"
                                        :disabled="settingsControlsDisabled"
                                        @click="updateHsts"
                                    >
                                        {{ t('Save HSTS') }}
                                    </button>
                                </div>
                            </div>

                            <div class="rounded-lg border border-gray-200 p-4 dark:border-gray-700">
                                <div class="mb-3 flex items-center justify-between">
                                    <h5 class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ t('DNSSEC') }}</h5>
                                    <div class="flex items-center gap-2">
                                        <span v-if="dnssecLoading" class="text-xs text-gray-500 dark:text-gray-400">{{ t('Loading...') }}</span>
                                        <span
                                            class="inline-flex rounded-full px-2 py-0.5 text-[10px] font-semibold"
                                            :class="cloudflareForm.dnssec_status === 'active' ? 'bg-success-500/20 text-success-700 dark:text-success-300' : 'bg-gray-200 text-gray-700 dark:bg-gray-700 dark:text-gray-300'"
                                        >
                                            {{ cloudflareForm.dnssec_status === 'active' ? t('Active') : t('Disabled') }}
                                        </span>
                                    </div>
                                </div>

                                <div
                                    v-if="dnssecLoading"
                                    class="rounded-lg border border-dashed border-gray-300 px-3 py-4 text-xs text-gray-500 dark:border-gray-700 dark:text-gray-400"
                                >
                                    {{ t('Loading...') }}
                                </div>

                                <div
                                    v-else-if="cloudflareDnssecEntries.length === 0"
                                    class="rounded-lg border border-dashed border-gray-300 px-3 py-4 text-xs text-gray-500 dark:border-gray-700 dark:text-gray-400"
                                >
                                    -
                                </div>

                                <div v-else class="grid grid-cols-1 gap-3 md:grid-cols-2">
                                    <div
                                        v-for="entry in cloudflareDnssecEntries"
                                        :key="entry.key"
                                        class="rounded-lg border border-gray-100 bg-gray-50 px-3 py-2 dark:border-gray-700 dark:bg-gray-900/50"
                                    >
                                        <p class="text-[11px] font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ entry.key }}</p>
                                        <p class="mt-1 break-all text-xs text-gray-700 dark:text-gray-200">{{ entry.value }}</p>
                                    </div>
                                </div>
                            </div>

                            <div class="rounded-lg border border-gray-200 p-4 dark:border-gray-700">
                                <div class="mb-3 flex items-center justify-between">
                                    <h5 class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ t('Firewall Rules') }}</h5>
                                    <span v-if="firewallLoading" class="text-xs text-gray-500 dark:text-gray-400">{{ t('Loading...') }}</span>
                                </div>

                                <div class="mb-3 grid grid-cols-1 gap-3 md:grid-cols-5">
                                    <label class="space-y-1 md:col-span-3">
                                        <span class="cf-field-label">{{ t('Expression') }}</span>
                                        <input
                                            v-model="firewallForm.expression"
                                            type="text"
                                            class="cf-input"
                                            :placeholder="t('Expression, e.g. (ip.src eq 1.2.3.4)')"
                                            :disabled="firewallControlsDisabled"
                                        />
                                    </label>

                                    <label class="space-y-1">
                                        <span class="cf-field-label">{{ t('Action') }}</span>
                                        <select v-model="firewallForm.action" class="cf-select" :disabled="firewallControlsDisabled">
                                            <option value="block">{{ t('Block') }}</option>
                                            <option value="allow">{{ t('Allow') }}</option>
                                            <option value="challenge">{{ t('Challenge') }}</option>
                                            <option value="js_challenge">{{ t('JS Challenge') }}</option>
                                            <option value="log">{{ t('Log') }}</option>
                                        </select>
                                    </label>

                                    <label class="space-y-1">
                                        <span class="cf-field-label">{{ t('Priority') }}</span>
                                        <input
                                            v-model.number="firewallForm.priority"
                                            type="number"
                                            min="1"
                                            class="cf-input"
                                            placeholder="1000"
                                            :disabled="firewallControlsDisabled"
                                        />
                                    </label>

                                    <label class="space-y-1 md:col-span-4">
                                        <span class="cf-field-label">{{ t('Description (optional)') }}</span>
                                        <input
                                            v-model="firewallForm.description"
                                            type="text"
                                            class="cf-input"
                                            :placeholder="t('Description (optional)')"
                                            :disabled="firewallControlsDisabled"
                                        />
                                    </label>

                                    <div class="md:col-span-1 md:self-end">
                                        <button
                                            type="button"
                                            class="inline-flex h-10 w-full items-center justify-center rounded-lg bg-brand-500 px-3 text-xs font-medium text-white hover:bg-brand-600 disabled:opacity-60"
                                            :disabled="firewallControlsDisabled"
                                            @click="createFirewallRule"
                                        >
                                            {{ t('Add Rule') }}
                                        </button>
                                    </div>
                                </div>

                                <div class="max-h-80 overflow-auto rounded-lg border border-gray-200 dark:border-gray-700">
                                    <table class="w-full text-xs">
                                        <thead class="bg-gray-50 dark:bg-gray-800">
                                            <tr>
                                                <th class="px-3 py-2 text-left">{{ t('Action') }}</th>
                                                <th class="px-3 py-2 text-left">{{ t('Expression') }}</th>
                                                <th class="px-3 py-2 text-left">{{ t('Description') }}</th>
                                                <th class="px-3 py-2 text-right">{{ t('Delete') }}</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <tr v-if="!firewallLoading && cloudflareFirewallRules.length === 0">
                                                <td colspan="4" class="px-3 py-4 text-center text-gray-500 dark:text-gray-400">
                                                    {{ t('No firewall rules found.') }}
                                                </td>
                                            </tr>
                                            <tr v-for="rule in cloudflareFirewallRules" :key="rule.id" class="border-t border-gray-100 dark:border-gray-800">
                                                <td class="px-3 py-2">{{ rule.action }}</td>
                                                <td class="px-3 py-2 break-all">{{ rule.expression }}</td>
                                                <td class="px-3 py-2 break-all">{{ rule.description || '-' }}</td>
                                                <td class="px-3 py-2 text-right">
                                                    <button
                                                        type="button"
                                                        class="inline-flex h-7 w-7 items-center justify-center rounded border border-error-500/40 text-error-600 hover:bg-error-500/10 dark:text-error-300 disabled:opacity-60"
                                                        :disabled="firewallControlsDisabled"
                                                        @click="deleteFirewallRule(rule.id)"
                                                    >
                                                        <i class="fa-solid fa-trash text-[10px]"></i>
                                                    </button>
                                                </td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
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

interface CloudflareFirewallRule {
    id: string;
    action: string;
    expression: string;
    description: string;
}

type CloudflareToggleSettingKey =
    | 'always_use_https'
    | 'automatic_https_rewrites'
    | 'tls_1_3'
    | 'development_mode'
    | 'websockets'
    | 'ip_geolocation'
    | 'opportunistic_onion'
    | 'http3'
    | 'early_hints';

interface CloudflareFormState {
    security_level: string;
    ssl: string;
    min_tls_version: string;
    browser_cache_ttl: number;
    always_use_https: boolean;
    automatic_https_rewrites: boolean;
    tls_1_3: boolean;
    development_mode: boolean;
    websockets: boolean;
    ip_geolocation: boolean;
    opportunistic_onion: boolean;
    http3: boolean;
    early_hints: boolean;
    dnssec_status: 'active' | 'disabled';
    hsts: {
        enabled: boolean;
        max_age: number;
        include_subdomains: boolean;
        preload: boolean;
        nosniff: boolean;
    };
}

const props = defineProps<{
    domain: Record<string, any>;
    cloudflare_zone: {
        exists: boolean;
        zone_id: string | null;
        zone_name: string | null;
        status: string | null;
        name_servers: string[];
    };
}>();

const { addToast } = useToast();
const { t } = useI18n();

const domain = computed(() => props.domain);
const breadcrumbs = computed(() => [
    { label: t('Domains'), href: route('domains.index') },
    { label: domain.value.fqdn, href: route('domains.show', domain.value.id) },
    { label: t('Cloudflare') },
]);

const cloudflareEnabledOverride = ref<boolean | null>(null);
const cloudflareZoneSummary = ref({
    exists: Boolean(props.cloudflare_zone.exists),
    zone_name: props.cloudflare_zone.zone_name as string | null,
    name_servers: Array.isArray(props.cloudflare_zone.name_servers) ? props.cloudflare_zone.name_servers : [],
});
const isCloudflareManagedForDns = computed(() => {
    if (cloudflareEnabledOverride.value !== null) {
        return cloudflareEnabledOverride.value;
    }

    if (domain.value.cloudflare_enabled === true) {
        return true;
    }

    if (domain.value.cloudflare_enabled === false) {
        return false;
    }

    return cloudflareZoneSummary.value.exists;
});

const summaryLoading = ref(false);
const settingsLoading = ref(false);
const dnssecLoading = ref(false);
const firewallLoading = ref(false);

const syncLoading = ref(false);
const purgeLoading = ref(false);
const settingUpdating = ref(false);
const dnssecUpdating = ref(false);
const firewallMutating = ref(false);

const createDefaultCloudflareForm = (): CloudflareFormState => ({
    security_level: 'medium',
    ssl: 'full',
    min_tls_version: '1.2',
    browser_cache_ttl: 14400,
    always_use_https: false,
    automatic_https_rewrites: false,
    tls_1_3: true,
    development_mode: false,
    websockets: true,
    ip_geolocation: false,
    opportunistic_onion: false,
    http3: false,
    early_hints: false,
    dnssec_status: 'disabled',
    hsts: {
        enabled: false,
        max_age: 0,
        include_subdomains: false,
        preload: false,
        nosniff: false,
    },
});

const cloudflareForm = ref<CloudflareFormState>(createDefaultCloudflareForm());
const cloudflareFirewallRules = ref<CloudflareFirewallRule[]>([]);
const cloudflareDnssecDetails = ref<Record<string, unknown> | null>(null);
const firewallForm = ref({
    expression: '',
    action: 'block',
    description: '',
    priority: null as number | null,
});
const toggleSettings: Array<{ key: CloudflareToggleSettingKey; label: string }> = [
    { key: 'always_use_https', label: 'Always Use HTTPS' },
    { key: 'automatic_https_rewrites', label: 'Automatic HTTPS Rewrites' },
    { key: 'tls_1_3', label: 'TLS 1.3' },
    { key: 'development_mode', label: 'Development Mode' },
    { key: 'websockets', label: 'WebSockets' },
    { key: 'ip_geolocation', label: 'IP Geolocation' },
    { key: 'opportunistic_onion', label: 'Onion Routing' },
    { key: 'http3', label: 'HTTP/3' },
    { key: 'early_hints', label: 'Early Hints' },
];

const settingsControlsDisabled = computed(() => !cloudflareZoneSummary.value.exists || settingsLoading.value || settingUpdating.value);
const dnssecControlsDisabled = computed(() => !cloudflareZoneSummary.value.exists || dnssecLoading.value || dnssecUpdating.value);
const firewallControlsDisabled = computed(() => !cloudflareZoneSummary.value.exists || firewallLoading.value || firewallMutating.value);

const cloudflareDnssecEntries = computed<Array<{ key: string; value: string }>>(() => {
    if (!cloudflareDnssecDetails.value || typeof cloudflareDnssecDetails.value !== 'object') {
        return [];
    }

    const entries: Array<{ key: string; value: string }> = [];
    const preferredKeys = [
        'ds',
        'public_key',
        'digest',
        'digest_type',
        'algorithm',
        'key_tag',
        'flags',
        'key_type',
        'modified_on',
    ];
    const seenKeys = new Set<string>();
    const dnssec = cloudflareDnssecDetails.value;

    const normalizeValue = (value: unknown): string => {
        if (value === null || value === undefined) {
            return '';
        }

        if (typeof value === 'string') {
            return value.trim();
        }

        if (typeof value === 'object') {
            return JSON.stringify(value);
        }

        return String(value);
    };

    for (const key of preferredKeys) {
        if (!Object.prototype.hasOwnProperty.call(dnssec, key)) {
            continue;
        }

        const value = normalizeValue((dnssec as Record<string, unknown>)[key]);
        if (value === '') {
            continue;
        }

        entries.push({ key, value });
        seenKeys.add(key);
    }

    for (const [key, rawValue] of Object.entries(dnssec)) {
        if (seenKeys.has(key) || key === 'status') {
            continue;
        }

        const value = normalizeValue(rawValue);
        if (value === '') {
            continue;
        }

        entries.push({ key, value });
    }

    return entries;
});

const joinOrFallback = (values: string[]): string => {
    if (!Array.isArray(values) || values.length === 0) {
        return '-';
    }

    return values.join(', ');
};

const toOnOff = (value: unknown): boolean => {
    if (typeof value === 'boolean') {
        return value;
    }

    if (typeof value === 'number') {
        return value === 1;
    }

    const normalized = String(value).toLowerCase();

    return normalized === 'on' || normalized === 'true' || normalized === '1';
};

const resetCloudflareDetails = (): void => {
    cloudflareForm.value = createDefaultCloudflareForm();
    cloudflareDnssecDetails.value = null;
    cloudflareFirewallRules.value = [];
};

const applySummaryPayload = (payload: Record<string, any>): void => {
    const zone = payload.zone ?? {};
    cloudflareZoneSummary.value = {
        exists: Boolean(zone.exists),
        zone_name: typeof zone.zone_name === 'string' ? zone.zone_name : null,
        name_servers: Array.isArray(zone.name_servers) ? zone.name_servers.map((nameServer) => String(nameServer)) : [],
    };

    if (typeof payload.cloudflare_enabled === 'boolean') {
        cloudflareEnabledOverride.value = payload.cloudflare_enabled;
    } else if (typeof payload.cloudflare_effective_enabled === 'boolean') {
        cloudflareEnabledOverride.value = payload.cloudflare_effective_enabled;
    } else {
        cloudflareEnabledOverride.value = null;
    }
};

const applySettingsPayload = (payload: Record<string, any>): void => {
    applySummaryPayload(payload);

    const settings = payload.settings ?? {};
    const securityHeader = settings.security_header && typeof settings.security_header === 'object'
        ? settings.security_header
        : {};

    cloudflareForm.value = {
        ...cloudflareForm.value,
        security_level: String(settings.security_level ?? 'medium'),
        ssl: String(settings.ssl ?? 'full'),
        min_tls_version: String(settings.min_tls_version ?? '1.2'),
        browser_cache_ttl: Number(settings.browser_cache_ttl ?? 14400),
        always_use_https: toOnOff(settings.always_use_https),
        automatic_https_rewrites: toOnOff(settings.automatic_https_rewrites),
        tls_1_3: toOnOff(settings.tls_1_3),
        development_mode: toOnOff(settings.development_mode),
        websockets: toOnOff(settings.websockets),
        ip_geolocation: toOnOff(settings.ip_geolocation),
        opportunistic_onion: toOnOff(settings.opportunistic_onion),
        http3: toOnOff(settings.http3),
        early_hints: toOnOff(settings.early_hints),
        hsts: {
            enabled: Boolean(securityHeader.enabled ?? false),
            max_age: Number(securityHeader.max_age ?? 0),
            include_subdomains: Boolean(securityHeader.include_subdomains ?? false),
            preload: Boolean(securityHeader.preload ?? false),
            nosniff: Boolean(securityHeader.nosniff ?? false),
        },
    };
};

const applyDnssecPayload = (payload: Record<string, any>): void => {
    applySummaryPayload(payload);

    const dnssecDetails = payload.dnssec && typeof payload.dnssec === 'object'
        ? payload.dnssec as Record<string, unknown>
        : null;
    cloudflareDnssecDetails.value = dnssecDetails;
    cloudflareForm.value.dnssec_status = String(dnssecDetails?.status ?? 'disabled') === 'active' ? 'active' : 'disabled';
};

const applyFirewallRulesPayload = (payload: Record<string, any>): void => {
    applySummaryPayload(payload);
    cloudflareFirewallRules.value = Array.isArray(payload.firewall_rules) ? payload.firewall_rules : [];
};

const fetchCloudflareSummary = async (): Promise<void> => {
    summaryLoading.value = true;

    try {
        const response = await axios.get(route('domains.cloudflare.summary', domain.value.id));
        applySummaryPayload(response.data ?? {});
    } catch {
        addToast('error', t('Cloudflare status could not be loaded.'));
    } finally {
        summaryLoading.value = false;
    }
};

const fetchCloudflareSettings = async (): Promise<void> => {
    if (!cloudflareZoneSummary.value.exists) {
        return;
    }

    settingsLoading.value = true;

    try {
        const response = await axios.get(route('domains.cloudflare.settings', domain.value.id));
        applySettingsPayload(response.data ?? {});
    } catch {
        addToast('error', t('Cloudflare status could not be loaded.'));
    } finally {
        settingsLoading.value = false;
    }
};

const fetchCloudflareDnssecStatus = async (): Promise<void> => {
    if (!cloudflareZoneSummary.value.exists) {
        return;
    }

    dnssecLoading.value = true;

    try {
        const response = await axios.get(route('domains.cloudflare.dnssec.status', domain.value.id));
        applyDnssecPayload(response.data ?? {});
    } catch {
        addToast('error', t('Cloudflare status could not be loaded.'));
    } finally {
        dnssecLoading.value = false;
    }
};

const fetchCloudflareFirewallRules = async (): Promise<void> => {
    if (!cloudflareZoneSummary.value.exists) {
        return;
    }

    firewallLoading.value = true;

    try {
        const response = await axios.get(route('domains.cloudflare.firewall-rules.index', domain.value.id));
        applyFirewallRulesPayload(response.data ?? {});
    } catch {
        addToast('error', t('Cloudflare status could not be loaded.'));
    } finally {
        firewallLoading.value = false;
    }
};

const initializeCloudflareData = async (): Promise<void> => {
    await fetchCloudflareSummary();

    if (!cloudflareZoneSummary.value.exists) {
        resetCloudflareDetails();
        return;
    }

    void fetchCloudflareSettings();
    void fetchCloudflareDnssecStatus();
    void fetchCloudflareFirewallRules();
};

const syncCloudflareStatus = async (): Promise<void> => {
    syncLoading.value = true;

    try {
        const response = await axios.post(route('domains.cloudflare.sync', domain.value.id));
        const payload = response.data ?? {};
        applySummaryPayload(payload);
        addToast('success', payload.message ?? t('Cloudflare status synchronized.'));

        if (!cloudflareZoneSummary.value.exists) {
            resetCloudflareDetails();
            return;
        }

        void fetchCloudflareSettings();
        void fetchCloudflareDnssecStatus();
        void fetchCloudflareFirewallRules();
    } catch (error: any) {
        const message = error?.response?.data?.message ?? t('Cloudflare status sync failed.');
        addToast('error', String(message));
    } finally {
        syncLoading.value = false;
    }
};

const purgeCloudflareCache = async (): Promise<void> => {
    purgeLoading.value = true;

    try {
        const response = await axios.post(route('domains.cloudflare.purge-cache', domain.value.id));
        addToast('success', response.data.message ?? t('Cloudflare cache purge started.'));
    } catch (error: any) {
        const message = error?.response?.data?.message ?? t('Cloudflare cache purge failed.');
        addToast('error', String(message));
    } finally {
        purgeLoading.value = false;
    }
};

const updateCloudflareSetting = async (setting: string, value: unknown): Promise<boolean> => {
    settingUpdating.value = true;

    try {
        const response = await axios.post(route('domains.cloudflare.setting', domain.value.id), {
            setting,
            value,
        });
        addToast('success', response.data.message ?? t('Cloudflare setting updated.'));
        return true;
    } catch (error: any) {
        const message = error?.response?.data?.message ?? t('Cloudflare setting update failed.');
        addToast('error', String(message));
        return false;
    } finally {
        settingUpdating.value = false;
    }
};

const updateSecurityLevel = async (): Promise<void> => {
    const updated = await updateCloudflareSetting('security_level', cloudflareForm.value.security_level);
    if (!updated) {
        await fetchCloudflareSettings();
    }
};

const updateSslMode = async (): Promise<void> => {
    const updated = await updateCloudflareSetting('ssl', cloudflareForm.value.ssl);
    if (!updated) {
        await fetchCloudflareSettings();
    }
};

const updateMinimumTlsVersion = async (): Promise<void> => {
    const updated = await updateCloudflareSetting('min_tls_version', cloudflareForm.value.min_tls_version);
    if (!updated) {
        await fetchCloudflareSettings();
    }
};

const updateBrowserCacheTtl = async (): Promise<void> => {
    const ttl = Number(cloudflareForm.value.browser_cache_ttl);
    cloudflareForm.value.browser_cache_ttl = Number.isFinite(ttl) ? ttl : 0;

    const updated = await updateCloudflareSetting('browser_cache_ttl', cloudflareForm.value.browser_cache_ttl);
    if (!updated) {
        await fetchCloudflareSettings();
    }
};

const toggleSettingValue = async (setting: CloudflareToggleSettingKey): Promise<void> => {
    const current = cloudflareForm.value[setting];
    const next = !current;
    cloudflareForm.value[setting] = next;

    const updated = await updateCloudflareSetting(setting, next ? 'on' : 'off');
    if (!updated) {
        cloudflareForm.value[setting] = current;
    }
};

const updateHsts = async (): Promise<void> => {
    const previous = { ...cloudflareForm.value.hsts };
    const updated = await updateCloudflareSetting('security_header', cloudflareForm.value.hsts);

    if (!updated) {
        cloudflareForm.value.hsts = previous;
        await fetchCloudflareSettings();
    }
};

const updateDnssec = async (): Promise<void> => {
    dnssecUpdating.value = true;

    try {
        const response = await axios.post(route('domains.cloudflare.dnssec', domain.value.id), {
            status: cloudflareForm.value.dnssec_status,
        });
        addToast('success', response.data.message ?? t('Cloudflare DNSSEC updated.'));
        await fetchCloudflareDnssecStatus();
    } catch (error: any) {
        const message = error?.response?.data?.message ?? t('Cloudflare DNSSEC update failed.');
        addToast('error', String(message));
        await fetchCloudflareDnssecStatus();
    } finally {
        dnssecUpdating.value = false;
    }
};

const createFirewallRule = async (): Promise<void> => {
    if (firewallForm.value.expression.trim() === '') {
        addToast('warning', t('Firewall expression is required.'));
        return;
    }

    firewallMutating.value = true;

    try {
        const response = await axios.post(route('domains.cloudflare.firewall-rules.store', domain.value.id), firewallForm.value);
        addToast('success', response.data.message ?? t('Firewall rule created.'));
        firewallForm.value.expression = '';
        firewallForm.value.description = '';
        firewallForm.value.priority = null;
        await fetchCloudflareFirewallRules();
    } catch (error: any) {
        const message = error?.response?.data?.message ?? t('Firewall rule could not be created.');
        addToast('error', String(message));
    } finally {
        firewallMutating.value = false;
    }
};

const deleteFirewallRule = async (ruleId: string): Promise<void> => {
    if (!window.confirm(t('Are you sure you want to delete this firewall rule?'))) {
        return;
    }

    firewallMutating.value = true;

    try {
        const response = await axios.delete(route('domains.cloudflare.firewall-rules.delete', [domain.value.id, ruleId]));
        addToast('success', response.data.message ?? t('Firewall rule deleted.'));
        await fetchCloudflareFirewallRules();
    } catch (error: any) {
        const message = error?.response?.data?.message ?? t('Firewall rule could not be deleted.');
        addToast('error', String(message));
    } finally {
        firewallMutating.value = false;
    }
};

onMounted(() => {
    void initializeCloudflareData();
});
</script>

<style scoped>
@reference "../../../css/app.css";

.cf-field-label {
    @apply block text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400;
}

.cf-select {
    @apply h-10 w-full rounded-lg border border-gray-300 bg-white px-3 text-sm text-gray-800 shadow-theme-xs placeholder:text-gray-400 focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/20 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100 dark:placeholder:text-gray-500;
}

.cf-input {
    @apply h-10 w-full rounded-lg border border-gray-300 bg-white px-3 text-sm text-gray-800 shadow-theme-xs placeholder:text-gray-400 focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/20 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100 dark:placeholder:text-gray-500;
}
</style>
