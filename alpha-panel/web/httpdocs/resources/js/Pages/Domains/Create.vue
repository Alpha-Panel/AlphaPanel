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
                        <FormField :label="t('Domain Name (FQDN)')" :error="form.errors.fqdn" required>
                            <input v-model="form.fqdn" type="text" :placeholder="t('example.com')" class="form-input" />
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

                        <div v-if="users.length > 0 && !form.parent_domain_id">
                            <FormField :label="t('Owner')" :error="form.errors.owner_user_id">
                                <select v-model="form.owner_user_id" class="form-input">
                                    <option :value="null">{{ t('-- Current User --') }}</option>
                                    <option v-for="u in users" :key="u.id" :value="u.id">
                                        {{ u.name }} ({{ u.email }})
                                    </option>
                                </select>
                            </FormField>
                        </div>

                        <div v-if="isSubdomainCreate" class="space-y-3 rounded-lg border border-gray-200 bg-gray-50 p-3 dark:border-gray-700 dark:bg-white/5">
                            <label class="flex items-center gap-2">
                                <input v-model="form.inherit_parent_root_path" type="checkbox" class="form-checkbox" />
                                <span class="text-sm text-gray-700 dark:text-gray-400">{{ t('Inherit main domain path') }}</span>
                            </label>
                            <p v-if="selectedParentDomainWebRootPath !== ''" class="text-xs text-gray-600 dark:text-gray-400">
                                {{ t('Main domain path: :path', { path: selectedParentDomainWebRootPath }) }}
                            </p>
                            <FormField
                                v-if="!form.inherit_parent_root_path"
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

                        <div class="flex items-center gap-3">
                            <label class="flex items-center gap-2">
                                <input v-model="form.enable_www_redirect" type="checkbox" class="form-checkbox" />
                                <span class="text-sm text-gray-700 dark:text-gray-400">{{ t('Enable www redirect') }}</span>
                            </label>
                        </div>

                        <div class="pt-5 border-t border-gray-200 dark:border-gray-800">
                            <h4 class="mb-3 text-sm font-medium text-gray-800 dark:text-white/90">{{ t('ModSecurity') }}</h4>
                            <div class="flex items-center gap-4 mb-4">
                                <label class="flex items-center gap-2">
                                    <input v-model="form.modsecurity_enabled" type="checkbox" class="form-checkbox" />
                                    <span class="text-sm text-gray-700 dark:text-gray-400">{{ t('Enable ModSecurity') }}</span>
                                </label>
                            </div>

                            <FormField v-if="form.modsecurity_enabled" :label="t('ModSecurity Mode')" :error="form.errors.modsecurity_mode">
                                <select v-model="form.modsecurity_mode" class="form-input">
                                    <option value="active">{{ t('Active') }}</option>
                                    <option value="detection_only">{{ t('Detection Only') }}</option>
                                </select>
                            </FormField>
                        </div>

                        <FormField
                            v-if="!isSubdomainCreate"
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
                        <div v-if="form.type === 'apache_reverse_proxy' && !form.parent_domain_id" class="pt-5 border-t border-gray-200 dark:border-gray-800">
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

                        <!-- Worker Section -->
                        <div v-if="form.type === 'caddy_web_server'" class="pt-5 border-t border-gray-200 dark:border-gray-800">
                            <h4 class="mb-3 text-sm font-medium text-gray-800 dark:text-white/90">{{ t('Worker Settings') }}</h4>
                            <div class="flex items-center gap-4 mb-4">
                                <label class="flex items-center gap-2">
                                    <input v-model="form.enable_worker" type="checkbox" class="form-checkbox" />
                                    <span class="text-sm text-gray-700 dark:text-gray-400">{{ t('Enable Worker') }}</span>
                                </label>
                                <label v-if="form.enable_worker" class="flex items-center gap-2">
                                    <input v-model="form.worker_watch" type="checkbox" class="form-checkbox" />
                                    <span class="text-sm text-gray-700 dark:text-gray-400">{{ t('Watch mode') }}</span>
                                </label>
                            </div>
                            <FormField v-if="form.enable_worker" :label="t('Worker Count')" :error="form.errors.worker_num">
                                <input v-model.number="form.worker_num" type="number" min="1" max="100" class="form-input w-24" />
                            </FormField>
                        </div>

                        <div class="flex items-center gap-3 pt-5">
                            <button
                                type="submit"
                                :disabled="form.processing"
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
}>();
const { t } = useI18n();

const form = useForm({
    fqdn: '',
    type: 'caddy_web_server',
    parent_domain_id: null as number | null,
    php_version_id: null as number | null,
    owner_user_id: null as number | null,
    root_path: '',
    inherit_parent_root_path: false,
    enable_www_redirect: true,
    enable_worker: false,
    worker_num: 2,
    worker_watch: false,
    modsecurity_enabled: false,
    modsecurity_mode: 'active' as 'active' | 'detection_only' | null,
    cloudflare_mode: 'skip' as 'add' | 'skip' | 'existing',
    create_dns_record: false,
    dns_target_ip: '',
    ftp_username: '',
    ftp_password: '',
});

const isSubdomainCreate = computed(() => form.parent_domain_id !== null);
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
    if (!isSubdomainCreate.value) {
        return false;
    }

    const parentDomain = selectedParentDomain.value;
    if (!parentDomain) {
        return false;
    }

    return parentDomain.cloudflare_enabled !== false;
});
const publicIps = computed(() => Array.isArray(props.server_network_ips?.public) ? props.server_network_ips.public : []);
const privateIps = computed(() => Array.isArray(props.server_network_ips?.private) ? props.server_network_ips.private : []);
const shouldShowDnsTargetIpSelect = computed(() => {
    if (isSubdomainCreate.value) {
        return shouldShowSubdomainDnsOption.value && form.create_dns_record;
    }

    return form.cloudflare_mode === 'add';
});

const submit = () => {
    if (isSubdomainCreate.value) {
        form.cloudflare_mode = 'skip';
    } else {
        form.create_dns_record = false;
        form.root_path = '';
        form.inherit_parent_root_path = false;
    }

    if (!shouldShowSubdomainDnsOption.value) {
        form.create_dns_record = false;
    }

    if (!shouldShowDnsTargetIpSelect.value) {
        form.dns_target_ip = '';
    }

    if (!form.modsecurity_enabled) {
        form.modsecurity_mode = null;
    } else if (form.modsecurity_mode !== 'active' && form.modsecurity_mode !== 'detection_only') {
        form.modsecurity_mode = 'active';
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
    } else {
        form.create_dns_record = false;
        form.root_path = '';
        form.inherit_parent_root_path = false;
    }
});

watch(shouldShowSubdomainDnsOption, (canCreateDnsRecord) => {
    if (!canCreateDnsRecord) {
        form.create_dns_record = false;
    }
});

watch(() => form.cloudflare_mode, (cloudflareMode) => {
    if (cloudflareMode !== 'add' && !isSubdomainCreate.value) {
        form.dns_target_ip = '';
    }
});

watch(() => form.create_dns_record, (createDnsRecord) => {
    if (!createDnsRecord && isSubdomainCreate.value) {
        form.dns_target_ip = '';
    }
});

watch(() => form.inherit_parent_root_path, (inheritParentRootPath) => {
    if (inheritParentRootPath) {
        form.root_path = '';
    }
});

watch(() => form.modsecurity_enabled, (enabled) => {
    if (enabled) {
        if (form.modsecurity_mode !== 'active' && form.modsecurity_mode !== 'detection_only') {
            form.modsecurity_mode = 'active';
        }

        return;
    }

    form.modsecurity_mode = null;
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
