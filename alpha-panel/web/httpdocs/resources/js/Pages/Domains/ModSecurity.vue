<template>
    <Head :title="`${t('ModSecurity')} - ${domain.fqdn}`" />
    <ThemeProvider>
        <SidebarProvider>
            <AdminLayout>
                <PageBreadcrumb
                    :pageTitle="t('ModSecurity')"
                    :items="breadcrumbs"
                    :backHref="route('domains.show', domain.parent_domain_id ?? domain.id)"
                />

                <div class="space-y-4 md:space-y-6">
                    <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03] md:p-6">
                        <h3 class="text-lg font-semibold text-gray-800 dark:text-white/90">
                            {{ t('ModSecurity') }}
                        </h3>
                        <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">
                            {{ t('Manage WAF mode, domain-specific rules and live logs for this domain.') }}
                        </p>
                    </div>

                    <div
                        v-if="globalRules.length > 0"
                        class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03] md:p-6"
                    >
                        <h4 class="text-sm font-semibold text-gray-800 dark:text-white/90">
                            {{ t('Server-wide Global IP Rules (Override Domain Rules)') }}
                        </h4>
                        <div class="mt-3 grid grid-cols-1 gap-2 md:grid-cols-2">
                            <div
                                v-for="rule in globalRules"
                                :key="rule.id"
                                class="rounded-lg border border-gray-200 px-3 py-2 text-sm dark:border-gray-800"
                            >
                                <span
                                    :class="[
                                        'mr-2 inline-flex rounded-full px-2 py-0.5 text-xs font-semibold',
                                        rule.action === 'allow'
                                            ? 'bg-success-500/15 text-success-700 dark:text-success-300'
                                            : 'bg-error-500/15 text-error-700 dark:text-error-300',
                                    ]"
                                >
                                    {{ rule.action.toUpperCase() }}
                                </span>
                                <code class="font-mono text-xs text-gray-700 dark:text-gray-300">{{ rule.ip_or_cidr }}</code>
                                <p v-if="rule.note" class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ rule.note }}</p>
                            </div>
                        </div>
                    </div>

                    <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03] md:p-6">
                        <form class="space-y-5" @submit.prevent="submit">
                            <label class="flex items-center gap-2">
                                <input v-model="form.modsecurity_enabled" type="checkbox" class="form-checkbox" />
                                <span class="text-sm text-gray-700 dark:text-gray-300">{{ t('Enable ModSecurity') }}</span>
                            </label>

                            <FormField
                                v-if="form.modsecurity_enabled"
                                :label="t('ModSecurity Mode')"
                                :error="form.errors.modsecurity_mode"
                            >
                                <select v-model="form.modsecurity_mode" class="form-input">
                                    <option value="active">{{ t('Active') }}</option>
                                    <option value="detection_only">{{ t('Detection Only') }}</option>
                                </select>
                            </FormField>

                            <div v-if="form.modsecurity_enabled" class="grid grid-cols-1 gap-4 xl:grid-cols-2">
                                <FormField :label="t('Domain Allowlist IP/CIDR (one per line)')" :error="form.errors.modsecurity_ip_allowlist">
                                    <textarea
                                        v-model="allowlistText"
                                        rows="6"
                                        class="form-input font-mono"
                                        :placeholder="'1.2.3.4\n203.0.113.0/24'"
                                    ></textarea>
                                </FormField>

                                <FormField :label="t('Domain Blocklist IP/CIDR (one per line)')" :error="form.errors.modsecurity_ip_blocklist">
                                    <textarea
                                        v-model="blocklistText"
                                        rows="6"
                                        class="form-input font-mono"
                                        :placeholder="'5.6.7.8\n198.51.100.0/24'"
                                    ></textarea>
                                </FormField>
                            </div>

                            <FormField v-if="form.modsecurity_enabled" :label="t('Disable Rule IDs for This Domain (comma/newline separated)')" :error="form.errors.modsecurity_disabled_rule_ids">
                                <textarea
                                    v-model="disabledRulesText"
                                    rows="4"
                                    class="form-input font-mono"
                                    :placeholder="'942100,949110'"
                                ></textarea>
                            </FormField>

                            <FormField v-if="form.modsecurity_enabled" :label="t('Custom Coraza Rules (Domain Scope)')" :error="form.errors.modsecurity_custom_rules">
                                <textarea
                                    v-model="form.modsecurity_custom_rules"
                                    rows="8"
                                    class="form-input font-mono"
                                    :placeholder="customRulesPlaceholder"
                                ></textarea>
                            </FormField>

                            <div class="flex items-center gap-3 pt-2">
                                <button
                                    type="submit"
                                    :disabled="form.processing"
                                    class="inline-flex items-center gap-2 rounded-lg bg-brand-500 px-4 py-2.5 text-sm font-medium text-white shadow-theme-xs hover:bg-brand-600 disabled:opacity-50"
                                >
                                    {{ form.processing ? t('Saving...') : t('Save Changes') }}
                                </button>
                                <Link
                                    :href="route('domains.show', domain.parent_domain_id ?? domain.id)"
                                    class="inline-flex items-center gap-2 rounded-lg border border-gray-300 bg-white px-4 py-2.5 text-sm font-medium text-gray-700 shadow-theme-xs hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-400"
                                >
                                    {{ t('Back to Domain') }}
                                </Link>
                            </div>
                        </form>
                    </div>

                    <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03] md:p-6">
                        <div class="mb-3 flex flex-wrap items-center gap-2">
                            <h4 class="text-sm font-semibold text-gray-800 dark:text-white/90">
                                {{ t('Live WAF Logs') }}
                            </h4>
                            <span class="ml-auto text-xs text-gray-500 dark:text-gray-400">
                                {{ t('Auto refresh every 3s') }}
                            </span>
                        </div>

                        <div class="grid grid-cols-1 gap-2 md:grid-cols-4">
                            <input
                                v-model="logFilters.ip"
                                type="text"
                                class="form-input"
                                :placeholder="t('Filter IP')"
                            />
                            <input
                                v-model="logFilters.rule_id"
                                type="text"
                                class="form-input"
                                :placeholder="t('Filter Rule ID')"
                            />
                            <input
                                v-model="logFilters.q"
                                type="text"
                                class="form-input"
                                :placeholder="t('Search URI / message')"
                            />
                            <label class="flex items-center gap-2 rounded-lg border border-gray-300 px-3 text-sm text-gray-700 dark:border-gray-700 dark:text-gray-300">
                                <input v-model="logFilters.blocked_only" type="checkbox" class="form-checkbox" />
                                {{ t('Blocked only') }}
                            </label>
                        </div>

                        <div class="mt-3 max-h-[460px] overflow-auto">
                            <table class="w-full min-w-[980px] text-sm">
                                <thead>
                                    <tr class="border-b border-gray-200 text-left text-xs uppercase text-gray-500 dark:border-gray-800 dark:text-gray-400">
                                        <th class="pb-2">{{ t('Time') }}</th>
                                        <th class="pb-2">{{ t('IP') }}</th>
                                        <th class="pb-2">{{ t('Request') }}</th>
                                        <th class="pb-2">{{ t('Rule') }}</th>
                                        <th class="pb-2">{{ t('Action') }}</th>
                                        <th class="pb-2">{{ t('Message') }}</th>
                                        <th class="pb-2 text-center">{{ t('Quick Action') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-if="logEntries.length === 0">
                                        <td colspan="7" class="py-6 text-center text-gray-500 dark:text-gray-400">
                                            {{ t('No logs found for this filter.') }}
                                        </td>
                                    </tr>
                                    <tr
                                        v-for="entry in logEntries"
                                        :key="`${entry.ts}-${entry.ip}-${entry.rule_id}-${entry.uri}`"
                                        class="border-b border-gray-100 align-top last:border-0 dark:border-gray-800"
                                    >
                                        <td class="py-2 text-xs text-gray-500 dark:text-gray-400">{{ formatDate(entry.ts) }}</td>
                                        <td class="py-2 font-mono text-xs text-gray-700 dark:text-gray-300">{{ entry.ip }}</td>
                                        <td class="py-2 text-xs text-gray-700 dark:text-gray-300">
                                            <span class="font-semibold">{{ entry.method }}</span> {{ entry.uri }}
                                        </td>
                                        <td class="py-2 font-mono text-xs text-gray-700 dark:text-gray-300">{{ entry.rule_id }}</td>
                                        <td class="py-2">
                                            <span
                                                :class="[
                                                    'inline-flex rounded-full px-2 py-0.5 text-xs font-semibold',
                                                    ['deny', 'block'].includes(entry.action)
                                                        ? 'bg-error-500/15 text-error-700 dark:text-error-300'
                                                        : 'bg-gray-200 text-gray-700 dark:bg-gray-700 dark:text-gray-300',
                                                ]"
                                            >
                                                {{ entry.action }}
                                            </span>
                                        </td>
                                        <td class="py-2 text-xs text-gray-600 dark:text-gray-400">{{ entry.message }}</td>
                                        <td class="py-2 text-center">
                                            <button
                                                type="button"
                                                class="inline-flex rounded border border-gray-300 px-2 py-1 text-xs text-gray-700 hover:bg-gray-100 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-gray-800"
                                                @click="appendDisabledRule(entry.rule_id)"
                                            >
                                                {{ t('Disable Rule') }}
                                            </button>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </AdminLayout>
        </SidebarProvider>
    </ThemeProvider>
</template>

<script setup lang="ts">
import { computed, onMounted, onUnmounted, reactive, ref, watch } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import axios from 'axios';
import ThemeProvider from '@/Components/Layout/ThemeProvider.vue';
import SidebarProvider from '@/Components/Layout/SidebarProvider.vue';
import AdminLayout from '@/Components/Layout/AdminLayout.vue';
import PageBreadcrumb from '@/Components/Common/PageBreadcrumb.vue';
import FormField from '@/Components/UI/FormField.vue';
import { useI18n } from '@/Composables/useI18n';

interface GlobalRule {
    id: number;
    ip_or_cidr: string;
    action: 'allow' | 'deny';
    note?: string | null;
}

interface WafLogEntry {
    ts: string | null;
    host: string;
    ip: string;
    method: string;
    uri: string;
    rule_id: string;
    message: string;
    action: string;
}

const props = defineProps<{
    domain: Record<string, any>;
    globalRules: GlobalRule[];
}>();

const { t } = useI18n();
const domain = computed(() => props.domain);
const globalRules = computed(() => props.globalRules ?? []);

const breadcrumbs = computed(() => [
    { label: t('Domains'), href: route('domains.index') },
    { label: domain.value.fqdn, href: route('domains.show', domain.value.parent_domain_id ?? domain.value.id) },
    { label: t('ModSecurity') },
]);

const form = useForm({
    _method: 'PUT',
    modsecurity_enabled: Boolean(domain.value.modsecurity_enabled),
    modsecurity_mode: (domain.value.modsecurity_mode ?? 'detection_only') as 'active' | 'detection_only' | null,
    modsecurity_ip_allowlist: Array.isArray(domain.value.modsecurity_ip_allowlist) ? domain.value.modsecurity_ip_allowlist : [],
    modsecurity_ip_blocklist: Array.isArray(domain.value.modsecurity_ip_blocklist) ? domain.value.modsecurity_ip_blocklist : [],
    modsecurity_disabled_rule_ids: Array.isArray(domain.value.modsecurity_disabled_rule_ids) ? domain.value.modsecurity_disabled_rule_ids : [],
    modsecurity_custom_rules: (domain.value.modsecurity_custom_rules ?? '') as string,
});

const allowlistText = ref((form.modsecurity_ip_allowlist ?? []).join('\n'));
const blocklistText = ref((form.modsecurity_ip_blocklist ?? []).join('\n'));
const disabledRulesText = ref((form.modsecurity_disabled_rule_ids ?? []).join(','));
const customRulesPlaceholder = '# raw ModSecurity directives\nSecRule REQUEST_URI "@contains /health" "id:200001,phase:1,pass,nolog"';

const parseLines = (value: string): string[] => {
    return value
        .split('\n')
        .map((v) => v.trim())
        .filter((v) => v.length > 0);
};

const parseRuleIds = (value: string): number[] => {
    return value
        .split(/[\n,\s]+/)
        .map((v) => Number.parseInt(v.trim(), 10))
        .filter((v) => Number.isInteger(v) && v > 0);
};

watch(() => form.modsecurity_enabled, (enabled) => {
    if (enabled) {
        if (form.modsecurity_mode !== 'active' && form.modsecurity_mode !== 'detection_only') {
            form.modsecurity_mode = 'detection_only';
        }
        return;
    }
    form.modsecurity_mode = null;
});

const submit = (): void => {
    form.modsecurity_ip_allowlist = parseLines(allowlistText.value);
    form.modsecurity_ip_blocklist = parseLines(blocklistText.value);
    form.modsecurity_disabled_rule_ids = parseRuleIds(disabledRulesText.value);

    if (!form.modsecurity_enabled) {
        form.modsecurity_mode = null;
        form.modsecurity_ip_allowlist = [];
        form.modsecurity_ip_blocklist = [];
        form.modsecurity_disabled_rule_ids = [];
        form.modsecurity_custom_rules = '';
    }

    form.post(route('domains.modsecurity.update', domain.value.id));
};

const logEntries = ref<WafLogEntry[]>([]);
const logFilters = reactive({
    ip: '',
    rule_id: '',
    q: '',
    blocked_only: false,
});
const lastSeenTs = ref<string>('');
let logTimer: ReturnType<typeof setInterval> | null = null;

const fetchLogs = async (): Promise<void> => {
    try {
        const response = await axios.get(route('domains.modsecurity.logs', domain.value.id), {
            params: {
                ...logFilters,
                since: logFilters.ip || logFilters.rule_id || logFilters.q || logFilters.blocked_only ? '' : lastSeenTs.value,
            },
        });

        const entries: WafLogEntry[] = Array.isArray(response.data?.entries) ? response.data.entries : [];
        if (entries.length === 0) {
            return;
        }

        if (logFilters.ip || logFilters.rule_id || logFilters.q || logFilters.blocked_only) {
            logEntries.value = entries;
        } else {
            const merged = [...entries, ...logEntries.value];
            const seen = new Set<string>();
            logEntries.value = merged.filter((entry) => {
                const key = `${entry.ts}-${entry.ip}-${entry.rule_id}-${entry.uri}`;
                if (seen.has(key)) return false;
                seen.add(key);
                return true;
            }).slice(0, 500);
        }

        if (logEntries.value[0]?.ts) {
            lastSeenTs.value = logEntries.value[0].ts;
        }
    } catch {
        // ignore transient polling errors
    }
};

const appendDisabledRule = (ruleId: string): void => {
    const parsed = Number.parseInt(ruleId, 10);
    if (!Number.isInteger(parsed) || parsed <= 0) return;
    const current = new Set(parseRuleIds(disabledRulesText.value));
    current.add(parsed);
    disabledRulesText.value = Array.from(current).sort((a, b) => a - b).join(',');
};

const formatDate = (value: string | null): string => {
    if (!value) return '-';
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return value;
    return date.toLocaleString();
};

onMounted(async () => {
    await fetchLogs();
    logTimer = setInterval(() => {
        void fetchLogs();
    }, 3000);
});

onUnmounted(() => {
    if (logTimer) {
        clearInterval(logTimer);
        logTimer = null;
    }
});

watch(logFilters, () => {
    lastSeenTs.value = '';
    void fetchLogs();
}, { deep: true });
</script>

<style scoped>
@reference "../../../css/app.css";

.form-input {
    @apply h-11 w-full rounded-lg border border-gray-300 bg-transparent px-4 py-2.5 text-sm text-gray-800 shadow-theme-xs placeholder:text-gray-400 focus:border-brand-300 focus:outline-hidden focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90 dark:placeholder:text-white/30 dark:focus:border-brand-800;
}

textarea.form-input {
    @apply h-auto;
}

.form-checkbox {
    @apply h-4 w-4 rounded border-gray-300 text-brand-500 focus:ring-brand-500;
}
</style>
