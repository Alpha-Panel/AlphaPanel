<template>
    <div
        v-if="modelValue"
        class="fixed inset-0 z-1200000 flex items-center justify-center bg-black/60 p-4 backdrop-blur-sm"
    >
        <div class="flex max-h-[92vh] w-full max-w-3xl flex-col rounded-xl border border-gray-700 bg-[#171717] text-white/90 shadow-2xl">
            <div class="flex items-center justify-between border-b border-white/10 px-5 py-4">
                <div>
                    <h5 class="text-base text-white">{{ modalTitle }}</h5>
                    <p v-if="isSubdomain && parentDomainFqdn" class="text-xs text-white/60">{{ t('Parent: :fqdn', { fqdn: parentDomainFqdn }) }}</p>
                </div>
                <button
                    type="button"
                    class="text-2xl leading-none text-white/50 hover:text-white"
                    :disabled="form.processing"
                    @click="attemptClose"
                >
                    &times;
                </button>
            </div>

            <form class="min-h-0 flex-1 overflow-y-auto p-5" @submit.prevent="submit">
                <div class="space-y-4">
                    <FormField :label="t('Domain Name (FQDN)')" :error="form.errors.fqdn" required>
                        <input v-model="form.fqdn" type="text" :placeholder="isSubdomain ? t('sub.example.com') : t('example.com')" class="form-input" />
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
                            <option v-for="version in phpVersions" :key="String(version.id)" :value="Number(version.id)">
                                {{ version.slug }}
                            </option>
                        </select>
                    </FormField>

                    <FormField
                        v-if="showOwnerField"
                        :label="t('Owner')"
                        :error="form.errors.owner_user_id"
                    >
                        <select v-model="form.owner_user_id" class="form-input">
                            <option :value="null">{{ t('-- Current User --') }}</option>
                            <option v-for="user in users" :key="String(user.id)" :value="Number(user.id)">
                                {{ user.name }} ({{ user.email }})
                            </option>
                        </select>
                    </FormField>

                    <div v-if="isSubdomain" class="space-y-3 rounded-lg border border-white/10 bg-white/5 p-3">
                        <label class="flex items-center gap-2 text-sm text-white/80">
                            <input v-model="form.inherit_parent_root_path" type="checkbox" class="form-checkbox" />
                            {{ t('Inherit main domain path') }}
                        </label>
                        <p v-if="parentDomainRootPath !== ''" class="text-xs text-white/60">
                            {{ t('Main domain path: :path', { path: parentDomainRootPath }) }}
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

                    <div class="flex flex-wrap items-center gap-4">
                        <label class="flex items-center gap-2 text-sm text-white/80">
                            <input v-model="form.enable_www_redirect" type="checkbox" class="form-checkbox" />
                            {{ t('Enable www redirect') }}
                        </label>
                    </div>

                    <FormField
                        v-if="!isSubdomain"
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

                    <label
                        v-if="showSubdomainDnsOption"
                        class="flex items-center gap-2 text-sm text-white/80"
                    >
                        <input v-model="form.create_dns_record" type="checkbox" class="form-checkbox" />
                        {{ t('Create DNS record') }}
                    </label>

                    <FormField
                        v-if="showDnsTargetIpSelect"
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

                    <div v-if="form.type === 'apache_reverse_proxy' && !isSubdomain" class="space-y-3 border-t border-white/10 pt-4">
                        <h6 class="text-sm font-medium text-white">{{ t('FTP Access') }}</h6>
                        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <FormField :label="t('FTP Username')" :error="form.errors.ftp_username">
                                <input v-model="form.ftp_username" type="text" :placeholder="t('ftpuser')" class="form-input" />
                            </FormField>
                            <FormField :label="t('FTP Password')" :error="form.errors.ftp_password">
                                <input v-model="form.ftp_password" type="password" :placeholder="t('Min 8 characters')" class="form-input" />
                            </FormField>
                        </div>
                    </div>

                </div>

                <div class="mt-6 flex items-center justify-end gap-2 border-t border-white/10 pt-4">
                    <button
                        type="button"
                        class="inline-flex items-center gap-2 rounded-lg border border-white/20 px-4 py-2.5 text-sm font-medium text-white/80 hover:bg-white/10"
                        :disabled="form.processing"
                        @click="attemptClose"
                    >
                        {{ t('Cancel') }}
                    </button>
                    <button
                        type="submit"
                        :disabled="form.processing"
                        class="inline-flex items-center gap-2 rounded-lg bg-brand-500 px-4 py-2.5 text-sm font-medium text-white hover:bg-brand-600 disabled:opacity-60"
                    >
                        {{ form.processing ? t('Processing...') : submitLabel }}
                    </button>
                </div>
            </form>
        </div>
    </div>
</template>

<script setup lang="ts">
import { computed, watch } from 'vue';
import axios from 'axios';
import { router, useForm } from '@inertiajs/vue3';
import FormField from '@/Components/UI/FormField.vue';
import { useI18n } from '@/Composables/useI18n';

const props = withDefaults(defineProps<{
    modelValue: boolean;
    phpVersions: Array<Record<string, any>>;
    users?: Array<Record<string, any>>;
    parentDomainId?: number | null;
    parentDomainFqdn?: string | null;
    parentDomainRootPath?: string | null;
    parentCloudflareManaged?: boolean;
    serverNetworkIps?: {
        public: string[];
        private: string[];
    };
}>(), {
    users: () => [],
    parentDomainId: null,
    parentDomainFqdn: null,
    parentDomainRootPath: null,
    parentCloudflareManaged: false,
    serverNetworkIps: () => ({ public: [], private: [] }),
});

const emit = defineEmits<{
    (e: 'update:modelValue', value: boolean): void;
}>();

const isSubdomain = computed(() => props.parentDomainId !== null && props.parentDomainId !== undefined);
const showOwnerField = computed(() => !isSubdomain.value && props.users.length > 0);
const showSubdomainDnsOption = computed(() => isSubdomain.value && props.parentCloudflareManaged);
const parentDomainRootPath = computed(() => (props.parentDomainRootPath ?? '').trim());
const publicIps = computed(() => Array.isArray(props.serverNetworkIps?.public) ? props.serverNetworkIps.public : []);
const privateIps = computed(() => Array.isArray(props.serverNetworkIps?.private) ? props.serverNetworkIps.private : []);
const showDnsTargetIpSelect = computed(() => {
    if (isSubdomain.value) {
        return showSubdomainDnsOption.value && form.create_dns_record;
    }

    return form.cloudflare_mode === 'add';
});
const { t } = useI18n();

const form = useForm({
    fqdn: '',
    type: 'caddy_web_server',
    parent_domain_id: props.parentDomainId as number | null,
    php_version_id: null as number | null,
    owner_user_id: null as number | null,
    root_path: '',
    inherit_parent_root_path: false,
    enable_www_redirect: true,
    cloudflare_mode: 'skip' as 'add' | 'skip' | 'existing',
    create_dns_record: false,
    dns_target_ip: '',
    ftp_username: '',
    ftp_password: '',
});

const modalTitle = computed(() => (isSubdomain.value ? t('Add Subdomain') : t('Add Domain')));
const submitLabel = computed(() => (isSubdomain.value ? t('Create Subdomain') : t('Create Domain')));

const hasUnsavedInput = computed(() => {
    if (form.fqdn.trim() !== '') {
        return true;
    }

    if (form.type !== 'caddy_web_server') {
        return true;
    }

    if (form.php_version_id !== null) {
        return true;
    }

    if (!isSubdomain.value && form.owner_user_id !== null) {
        return true;
    }

    if (form.enable_www_redirect !== true) {
        return true;
    }

    if (form.cloudflare_mode !== 'skip') {
        return true;
    }

    if (showSubdomainDnsOption.value && form.create_dns_record !== false) {
        return true;
    }

    if (isSubdomain.value && form.inherit_parent_root_path !== false) {
        return true;
    }

    if (form.root_path.trim() !== '') {
        return true;
    }

    if (form.dns_target_ip.trim() !== '') {
        return true;
    }

    return form.ftp_username.trim() !== '' || form.ftp_password !== '';


});

const resetFormState = (): void => {
    form.defaults({
        fqdn: '',
        type: 'caddy_web_server',
        parent_domain_id: props.parentDomainId,
        php_version_id: null,
        owner_user_id: null,
        root_path: '',
        inherit_parent_root_path: false,
        enable_www_redirect: true,
        cloudflare_mode: 'skip',
        create_dns_record: false,
        dns_target_ip: '',
        ftp_username: '',
        ftp_password: '',
    });

    form.reset();
    form.clearErrors();
};

watch(() => props.parentDomainId, (parentDomainId) => {
    form.parent_domain_id = parentDomainId;
    form.root_path = '';
    form.inherit_parent_root_path = false;
});

watch(() => props.modelValue, (isOpen) => {
    if (isOpen) {
        resetFormState();
    }
});

watch(() => form.type, (type) => {
    if (type !== 'apache_reverse_proxy') {
        form.php_version_id = null;
        form.ftp_username = '';
        form.ftp_password = '';
    }
});

watch(showSubdomainDnsOption, (canCreateDnsRecord) => {
    if (!canCreateDnsRecord) {
        form.create_dns_record = false;
    }
});

watch(() => form.cloudflare_mode, (cloudflareMode) => {
    if (cloudflareMode !== 'add' && !isSubdomain.value) {
        form.dns_target_ip = '';
    }
});

watch(() => form.create_dns_record, (createDnsRecord) => {
    if (!createDnsRecord && isSubdomain.value) {
        form.dns_target_ip = '';
    }
});

watch(() => form.inherit_parent_root_path, (inheritParentRootPath) => {
    if (inheritParentRootPath) {
        form.root_path = '';
    }
});

const attemptClose = (): void => {
    if (form.processing) {
        return;
    }

    if (hasUnsavedInput.value && !window.confirm(t('Entered form data will be lost. Do you want to close?'))) {
        return;
    }

    emit('update:modelValue', false);
    resetFormState();
};

const submit = (): void => {
    form.parent_domain_id = props.parentDomainId;

    if (isSubdomain.value) {
        form.owner_user_id = null;
        form.cloudflare_mode = 'skip';
    } else {
        form.create_dns_record = false;
        form.root_path = '';
        form.inherit_parent_root_path = false;
    }

    if (!showSubdomainDnsOption.value) {
        form.create_dns_record = false;
    }

    if (!showDnsTargetIpSelect.value) {
        form.dns_target_ip = '';
    }

    form.clearErrors();
    form.processing = true;

    axios.post(route('domains.store'), form.data(), {
        headers: {
            Accept: 'application/json',
        },
    }).then(() => {
        emit('update:modelValue', false);
        resetFormState();
        router.reload({ preserveScroll: true });
    }).catch((error: any) => {
        const errors = error?.response?.data?.errors;
        if (!errors || typeof errors !== 'object') {
            return;
        }

        for (const [field, messages] of Object.entries(errors)) {
            if (Array.isArray(messages)) {
                form.setError(field, String(messages[0] ?? ''));
            } else {
                form.setError(field, String(messages ?? ''));
            }
        }
    }).finally(() => {
        form.processing = false;
    });
};
</script>

<style scoped>
@reference "../../../css/app.css";

.form-input {
    @apply h-11 w-full rounded-lg border border-white/20 bg-black/20 px-4 py-2.5 text-sm text-white shadow-theme-xs placeholder:text-white/40 focus:border-brand-400 focus:outline-hidden focus:ring-3 focus:ring-brand-500/20;
}

select.form-input {
    @apply border-white/25 bg-[#202020];
}

select.form-input option {
    @apply bg-[#202020] text-white;
}

.form-checkbox {
    @apply h-4 w-4 rounded border-white/30 bg-transparent text-brand-500 focus:ring-brand-500;
}
</style>
