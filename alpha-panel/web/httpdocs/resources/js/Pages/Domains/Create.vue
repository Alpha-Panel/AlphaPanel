<template>
    <Head :title="t('Create Domain')" />
    <ThemeProvider>
        <SidebarProvider>
            <AdminLayout>
                <PageBreadcrumb :pageTitle="t('Create Domain')" />

                <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/3 md:p-6">
                    <h3 class="mb-5 text-lg font-semibold text-gray-800 dark:text-white/90">
                        {{ t('Add New Domain') }}
                    </h3>

                    <form @submit.prevent="submit" class="space-y-5">
                        <FormField :label="t('Domain Mode')" required>
                            <select v-model="form.mode" class="form-input">
                                <option value="main">{{ t('Main Domain') }}</option>
                                <option value="subdomain">{{ t('Subdomain') }}</option>
                                <option value="addon">{{ t('Addon Domain') }}</option>
                                <option value="wildcard_subdomain">{{ t('Wildcard Subdomain') }}</option>
                                <option
                                    v-if="canCreateCatchall"
                                    value="wildcard_catchall"
                                    :disabled="wildcardCatchallExists"
                                    :title="wildcardCatchallExists ? t('Wildcard catch-all already defined on this server') : ''"
                                >
                                    {{ t('Wildcard Catch-All') }}
                                </option>
                            </select>
                        </FormField>

                        <FormField v-if="needsParent" :label="t('Parent Domain')" :error="form.errors.parent_domain_id" required>
                            <select v-model="form.parent_domain_id" class="form-input">
                                <option :value="null">{{ t('-- Select --') }}</option>
                                <option v-for="d in parentDomains" :key="d.id" :value="d.id">
                                    {{ d.fqdn }}
                                </option>
                            </select>
                        </FormField>

                        <FormField v-if="!isModeCatchall" :label="t('Domain Name (FQDN)')" :error="form.errors.fqdn" required>
                            <input
                                v-model="form.fqdn"
                                type="text"
                                :placeholder="t('example.com')"
                                :readonly="isModeWildcardSub"
                                class="form-input"
                                :class="{ 'opacity-70 cursor-not-allowed': isModeWildcardSub }"
                            />
                        </FormField>

                        <FormField :label="t('Type')" :error="form.errors.type" required>
                            <select v-model="form.type" class="form-input">
                                <option value="caddy_web_server">{{ t('Caddy Web Server') }}</option>
                                <option value="apache_reverse_proxy">{{ t('Apache + Reverse Proxy') }}</option>
                            </select>
                        </FormField>

                        <FormField
                            v-if="form.type === 'apache_reverse_proxy'"
                            :label="t('PHP Version')"
                            :error="form.errors.php_version_id"
                        >
                            <select v-model="form.php_version_id" class="form-input">
                                <option :value="null">{{ t('-- Select --') }}</option>
                                <option v-for="v in phpVersions" :key="v.id" :value="v.id">
                                    {{ v.slug }}
                                </option>
                            </select>
                        </FormField>

                        <div v-if="users.length > 0 && !needsParent">
                            <FormField :label="t('Owner')" :error="form.errors.owner_user_id">
                                <select v-model="form.owner_user_id" class="form-input">
                                    <option :value="null">{{ t('-- Current User --') }}</option>
                                    <option v-for="u in users" :key="u.id" :value="u.id">
                                        {{ u.name }} ({{ u.email }})
                                    </option>
                                </select>
                            </FormField>
                        </div>

                        <div v-if="needsParent" class="space-y-3 rounded-lg border border-gray-200 bg-gray-50 p-3 dark:border-gray-700 dark:bg-white/5">
                            <label class="flex items-center gap-2">
                                <input v-model="form.inherit_parent_root_path" type="checkbox" class="form-checkbox" />
                                <span class="text-sm text-gray-700 dark:text-gray-400">{{ t('Inherit main domain path') }}</span>
                            </label>
                            <p v-if="selectedParentDomainWebRootPath !== ''" class="text-xs text-gray-600 dark:text-gray-400">
                                {{ t('Main domain path: :path', { path: selectedParentDomainWebRootPath }) }}
                            </p>
                            <FormField
                                v-if="!form.inherit_parent_root_path && (!needsLinkedDomain || !form.inherit_linked_root_path)"
                                :label="t('Root Path (Optional)')"
                                :error="form.errors.root_path"
                            >
                                <input
                                    v-model="form.root_path"
                                    type="text"
                                    :placeholder="t('/var/www/vhosts/example.com/subdomains/app/httpdocs/public')"
                                    class="form-input"
                                />
                            </FormField>
                        </div>

                        <FormField v-if="needsLinkedDomain" :label="t('Linked Domain')" :error="form.errors.linked_domain_id" :required="isModeAddon">
                            <select v-model="form.linked_domain_id" class="form-input">
                                <option :value="null">{{ t('-- Select --') }}</option>
                                <option v-for="d in linkableDomains" :key="d.id" :value="d.id">
                                    {{ d.fqdn }}
                                </option>
                            </select>
                        </FormField>

                        <template v-if="needsLinkedDomain && form.linked_domain_id">
                            <div class="flex items-center gap-2">
                                <input type="checkbox" v-model="form.inherit_linked_root_path" id="inherit_linked" class="form-checkbox" />
                                <label for="inherit_linked" class="text-sm text-gray-700 dark:text-gray-400">{{ t('Inherit linked domain path') }}</label>
                            </div>
                            <div v-if="form.inherit_linked_root_path" class="text-sm text-gray-500 dark:text-gray-400">
                                {{ t('Linked domain path: :path', { path: selectedLinkedDomain?.root_path ?? '' }) }}
                            </div>
                        </template>

                        <FormField
                            v-if="!needsParent && (!needsLinkedDomain || !form.inherit_linked_root_path)"
                            :label="t('Root Path (Optional)')"
                            :error="form.errors.root_path"
                        >
                            <input
                                v-model="form.root_path"
                                type="text"
                                :placeholder="t('/var/www/vhosts/example.com/httpdocs/public')"
                                class="form-input"
                            />
                        </FormField>

                        <div v-if="isModeCatchall" class="flex items-center gap-2">
                            <input type="checkbox" v-model="form.catchall_confirmed" id="catchall_confirm" class="form-checkbox" />
                            <label for="catchall_confirm" class="text-sm text-yellow-600 dark:text-yellow-400">
                                {{ t('I understand this will receive all unknown hosts on the server.') }}
                            </label>
                        </div>

                        <div class="flex items-center gap-3">
                            <label class="flex items-center gap-2">
                                <input v-model="form.enable_www_redirect" type="checkbox" class="form-checkbox" />
                                <span class="text-sm text-gray-700 dark:text-gray-400">{{ t('Enable www redirect') }}</span>
                            </label>
                        </div>

                        <FormField
                            v-if="!isModeSubdomain && !isModeWildcardSub && !isModeCatchall"
                            :label="t('DNS Provider')"
                            :error="form.errors.dns_provider"
                            required
                        >
                            <select v-model="form.dns_provider" class="form-input">
                                <option value="local">{{ t('Local DNS') }}</option>
                                <option value="cloudflare">{{ t('Cloudflare DNS') }}</option>
                            </select>
                        </FormField>

                        <FormField
                            v-if="!isModeSubdomain && !isModeWildcardSub && !isModeCatchall && form.dns_provider === 'cloudflare'"
                            :label="t('Cloudflare Status')"
                            :error="form.errors.cloudflare_mode"
                            required
                        >
                            <select v-model="form.cloudflare_mode" class="form-input">
                                <option value="add">{{ t('Add to Cloudflare') }}</option>
                                <option value="skip">{{ t('Do not add') }}</option>
                                <option value="existing">{{ t('Already added') }}</option>
                            </select>
                        </FormField>

                        <label v-if="shouldShowSubdomainDnsOption" class="flex items-center gap-2">
                            <input v-model="form.create_dns_record" type="checkbox" class="form-checkbox" />
                            <span class="text-sm text-gray-700 dark:text-gray-400">{{ t('Create DNS record') }}</span>
                        </label>

                        <FormField
                            v-if="shouldShowDnsTargetIpSelect"
                            :label="t('Domain Target IP')"
                            :error="form.errors.dns_target_ip"
                            required
                        >
                            <select v-model="form.dns_target_ip" class="form-input">
                                <option value="">{{ t('-- Select --') }}</option>
                                <optgroup :label="t('Server Public IPs')">
                                    <option v-for="ip in publicIps" :key="`public-${ip}`" :value="ip">
                                        {{ ip }}
                                    </option>
                                </optgroup>
                                <optgroup :label="t('Server Private IPs')">
                                    <option v-for="ip in privateIps" :key="`private-${ip}`" :value="ip">
                                        {{ ip }}
                                    </option>
                                </optgroup>
                            </select>
                        </FormField>

                        <!-- FTP Section -->
                        <div v-if="form.type === 'apache_reverse_proxy' && !isModeSubdomain && !isModeWildcardSub && !isModeCatchall" class="pt-5 border-t border-gray-200 dark:border-gray-800">
                            <h4 class="mb-3 text-sm font-medium text-gray-800 dark:text-white/90">{{ t('FTP Access') }}</h4>
                            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                <FormField :label="t('FTP Username')" :error="form.errors.ftp_username">
                                    <input v-model="form.ftp_username" type="text" :placeholder="t('ftpuser')" class="form-input" />
                                </FormField>
                                <FormField :label="t('FTP Password')" :error="form.errors.ftp_password">
                                    <input v-model="form.ftp_password" type="password" :placeholder="t('Min 8 characters')" class="form-input" />
                                </FormField>
                            </div>
                        </div>

                        <div class="flex items-center gap-3 pt-5">
                            <button
                                type="submit"
                                :disabled="form.processing || (isModeCatchall && !form.catchall_confirmed)"
                                class="inline-flex items-center gap-2 rounded-lg bg-brand-500 px-4 py-2.5 text-sm font-medium text-white shadow-theme-xs hover:bg-brand-600 disabled:opacity-50"
                            >
                                {{ form.processing ? t('Creating...') : t('Create Domain') }}
                            </button>
                            <Link
                                :href="route('domains.index')"
                                class="inline-flex items-center gap-2 rounded-lg border border-gray-300 bg-white px-4 py-2.5 text-sm font-medium text-gray-700 shadow-theme-xs hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-400"
                            >
                                {{ t('Cancel') }}
                            </Link>
                        </div>
                    </form>
                </div>
            </AdminLayout>
        </SidebarProvider>
    </ThemeProvider>
</template>

<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, onMounted, watch } from 'vue';
import ThemeProvider from '@/Components/Layout/ThemeProvider.vue';
import SidebarProvider from '@/Components/Layout/SidebarProvider.vue';
import AdminLayout from '@/Components/Layout/AdminLayout.vue';
import PageBreadcrumb from '@/Components/Common/PageBreadcrumb.vue';
import FormField from '@/Components/UI/FormField.vue';
import { useI18n } from '@/Composables/useI18n';

const props = defineProps<{
    phpVersions: Array<Record<string, any>>;
    parentDomains: Array<Record<string, any>>;
    users: Array<Record<string, any>>;
    server_network_ips: {
        public: string[];
        private: string[];
    };
    wildcardCatchallExists: boolean;
    canCreateCatchall: boolean;
    linkableDomains: Array<{id: number, fqdn: string, mode: string, root_path: string|null}>;
}>();
const { t } = useI18n();

const form = useForm({
    fqdn: '',
    type: 'caddy_web_server',
    mode: 'main' as string,
    parent_domain_id: null as number | null,
    linked_domain_id: null as number | null,
    inherit_linked_root_path: true,
    catchall_confirmed: false,
    php_version_id: null as number | null,
    owner_user_id: null as number | null,
    root_path: '',
    inherit_parent_root_path: false,
    enable_www_redirect: true,
    dns_provider: 'local' as 'local' | 'cloudflare',
    cloudflare_mode: 'skip' as 'add' | 'skip' | 'existing',
    create_dns_record: false,
    dns_target_ip: '',
    ftp_username: '',
    ftp_password: '',
});

const isModeSubdomain = computed(() => form.mode === 'subdomain');
const isModeWildcardSub = computed(() => form.mode === 'wildcard_subdomain');
const isModeAddon = computed(() => form.mode === 'addon');
const isModeCatchall = computed(() => form.mode === 'wildcard_catchall');
const needsParent = computed(() => isModeSubdomain.value || isModeWildcardSub.value);
const needsLinkedDomain = computed(() => isModeAddon.value || isModeWildcardSub.value || isModeCatchall.value);

const selectedLinkedDomain = computed(() =>
    props.linkableDomains.find((d) => d.id === form.linked_domain_id) ?? null,
);
const selectedParentDomain = computed(() => props.parentDomains.find((domain) => Number(domain.id) === Number(form.parent_domain_id)) ?? null);
const selectedParentDomainWebRootPath = computed(() => {
    if (!selectedParentDomain.value) {
        return '';
    }

    const customRootPath = String(selectedParentDomain.value.root_path ?? '').trim();
    if (customRootPath !== '') {
        return customRootPath;
    }

    const fqdn = String(selectedParentDomain.value.fqdn ?? '').trim();
    if (fqdn === '') {
        return '';
    }

    if (selectedParentDomain.value.type === 'apache_reverse_proxy') {
        return `/var/www/vhosts/${fqdn}/httpdocs`;
    }

    return `/var/www/vhosts/${fqdn}/httpdocs/public`;
});
const shouldShowSubdomainDnsOption = computed(() => {
    if (!isModeSubdomain.value) {
        return false;
    }

    const parentDomain = selectedParentDomain.value;
    if (!parentDomain) {
        return false;
    }

    return parentDomain.dns_provider === 'cloudflare';
});
const publicIps = computed(() => Array.isArray(props.server_network_ips?.public) ? props.server_network_ips.public : []);
const privateIps = computed(() => Array.isArray(props.server_network_ips?.private) ? props.server_network_ips.private : []);
const shouldShowDnsTargetIpSelect = computed(() => {
    if (isModeSubdomain.value) {
        return shouldShowSubdomainDnsOption.value && form.create_dns_record;
    }

    if (form.dns_provider === 'cloudflare') {
        return form.cloudflare_mode === 'add';
    }

    return form.dns_provider === 'local';
});

const submit = () => {
    if (isModeCatchall.value) {
        form.fqdn = '*';
    }

    if (isModeSubdomain.value || isModeWildcardSub.value) {
        form.cloudflare_mode = 'skip';
        form.dns_provider = 'local';
    } else {
        form.create_dns_record = false;
        if (!needsParent.value && !needsLinkedDomain.value) {
            form.inherit_parent_root_path = false;
        }
    }

    if (!shouldShowSubdomainDnsOption.value) {
        form.create_dns_record = false;
    }

    if (!shouldShowDnsTargetIpSelect.value) {
        form.dns_target_ip = '';
    }

    if (needsLinkedDomain.value && form.inherit_linked_root_path) {
        form.root_path = '';
        form.inherit_parent_root_path = false;
    }

    form.post(route('domains.store'));
};

watch(() => form.type, (type) => {
    if (type !== 'apache_reverse_proxy') {
        form.php_version_id = null;
    }
});

watch(() => form.parent_domain_id, (parentDomainId) => {
    if (parentDomainId) {
        form.owner_user_id = null;
        form.cloudflare_mode = 'skip';

        if (isModeWildcardSub.value) {
            const parent = props.parentDomains.find((d) => Number(d.id) === Number(parentDomainId));
            if (parent) {
                form.fqdn = `*.${parent.fqdn}`;
            }
        }
    } else {
        form.create_dns_record = false;
        form.root_path = '';
        form.inherit_parent_root_path = false;
    }
});

watch(() => form.mode, (newMode) => {
    if (newMode !== 'subdomain' && newMode !== 'wildcard_subdomain') {
        form.parent_domain_id = null;
        form.inherit_parent_root_path = false;
    }

    if (newMode !== 'addon' && newMode !== 'wildcard_subdomain' && newMode !== 'wildcard_catchall') {
        form.linked_domain_id = null;
        form.inherit_linked_root_path = true;
    }

    if (newMode !== 'wildcard_subdomain') {
        if (form.fqdn.startsWith('*.')) {
            form.fqdn = '';
        }
    }

    if (newMode === 'wildcard_catchall') {
        form.fqdn = '*';
    }

    form.catchall_confirmed = false;
});

watch(shouldShowSubdomainDnsOption, (canCreateDnsRecord) => {
    if (!canCreateDnsRecord) {
        form.create_dns_record = false;
    }
});

watch(() => form.dns_provider, (dnsProvider) => {
    if (dnsProvider === 'local') {
        form.cloudflare_mode = 'skip';
    }
});

watch(() => form.cloudflare_mode, (cloudflareMode) => {
    if (cloudflareMode !== 'add' && !isModeSubdomain.value && !isModeWildcardSub.value && form.dns_provider === 'cloudflare') {
        form.dns_target_ip = '';
    }
});

watch(() => form.create_dns_record, (createDnsRecord) => {
    if (!createDnsRecord && isModeSubdomain.value) {
        form.dns_target_ip = '';
    }
});

watch(() => form.inherit_parent_root_path, (inheritParentRootPath) => {
    if (inheritParentRootPath) {
        form.root_path = '';
    }
});

onMounted(() => {
    const query = new URLSearchParams(window.location.search);
    const parentDomainId = Number(query.get('parent_domain_id'));

    if (Number.isNaN(parentDomainId) || parentDomainId < 1) {
        return;
    }

    const parentExists = props.parentDomains.some((domain) => Number(domain.id) === parentDomainId);
    if (!parentExists) {
        return;
    }

    form.mode = 'subdomain';
    form.parent_domain_id = parentDomainId;
});
</script>

<style scoped>
@reference "../../../css/app.css";

.form-input {
    @apply h-11 w-full rounded-lg border border-gray-300 bg-transparent px-4 py-2.5 text-sm text-gray-800 shadow-theme-xs placeholder:text-gray-400 focus:border-brand-300 focus:outline-hidden focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90 dark:placeholder:text-white/30 dark:focus:border-brand-800;
}

select.form-input {
    @apply bg-white dark:bg-gray-900;
}

select.form-input option {
    @apply bg-white text-gray-800 dark:bg-gray-900 dark:text-white/90;
}

.form-checkbox {
    @apply w-4 h-4 rounded border-gray-300 text-brand-500 focus:ring-brand-500;
}
</style>
