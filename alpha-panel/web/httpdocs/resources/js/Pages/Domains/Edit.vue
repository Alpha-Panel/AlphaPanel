<template>
    <Head :title="`${t('Edit')} ${domain.fqdn}`" />
    <ThemeProvider>
        <SidebarProvider>
            <AdminLayout>
                <PageBreadcrumb
                    :pageTitle="`${t('Edit')}: ${domain.fqdn}`"
                    :items="breadcrumbs"
                    :backHref="route('domains.show', domain.id)"
                />

                <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03] md:p-6">
                    <h3 class="mb-5 text-lg font-semibold text-gray-800 dark:text-white/90">
                        {{ t('Edit Domain') }}
                    </h3>

                    <form @submit.prevent="submit" class="space-y-5">
                        <FormField :label="t('Domain Name (FQDN)')" :error="form.errors.fqdn" required>
                            <input v-model="form.fqdn" type="text" class="form-input" />
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

                        <FormField :label="t('Root Path')" :error="form.errors.root_path">
                            <input v-model="form.root_path" type="text" :placeholder="t('Leave empty to use automatic path')" class="form-input" />
                        </FormField>

                        <div v-if="showOwnerField">
                            <FormField :label="t('Owner')" :error="form.errors.owner_user_id">
                                <select v-model="form.owner_user_id" class="form-input">
                                    <option v-for="u in users" :key="u.id" :value="u.id">
                                        {{ u.name }} ({{ u.email }})
                                    </option>
                                </select>
                            </FormField>
                        </div>

                        <label class="flex items-center gap-2">
                            <input v-model="form.enable_www_redirect" type="checkbox" class="form-checkbox" />
                            <span class="text-sm text-gray-700 dark:text-gray-400">{{ t('Enable www redirect') }}</span>
                        </label>

                        <!-- SSL Method -->
                        <FormField :label="t('SSL Method')" :error="form.errors.ssl_method">
                            <select v-model="form.ssl_method" class="form-input">
                                <option value="cloudflare_dns">{{ t('Cloudflare DNS (DNS-01)') }}</option>
                                <option value="webroot_http">{{ t('Webroot HTTP (HTTP-01)') }}</option>
                                <option value="self_signed">{{ t('Self-Signed Certificate') }}</option>
                                <option value="none">{{ t('No SSL') }}</option>
                            </select>
                        </FormField>

                        <!-- CORS Configuration -->
                        <div class="pt-5 border-t border-gray-200 dark:border-gray-800">
                            <h4 class="mb-3 text-sm font-medium text-gray-800 dark:text-white/90">{{ t('CORS (Cross-Origin Resource Sharing)') }}</h4>

                            <label class="flex items-center gap-2 mb-4">
                                <input v-model="form.cors_enabled" type="checkbox" class="form-checkbox" />
                                <span class="text-sm text-gray-700 dark:text-gray-400">{{ t('Enable CORS Headers') }}</span>
                            </label>

                            <div v-if="form.cors_enabled">
                                <div class="mb-4 rounded-lg border border-amber-200 bg-amber-50 p-3 dark:border-amber-800 dark:bg-amber-900/20">
                                    <p class="text-xs text-amber-700 dark:text-amber-400">
                                        {{ t('Allows other websites to make API requests to this domain. Use * to allow all origins, or enter specific origins separated by commas (e.g. https://app.example.com, https://other.example.com).') }}
                                    </p>
                                </div>

                                <FormField :label="t('Allowed Origins')" :error="form.errors.cors_allowed_origins">
                                    <input
                                        v-model="form.cors_allowed_origins"
                                        type="text"
                                        class="form-input"
                                        placeholder="* or https://app.example.com, https://other.example.com"
                                    />
                                </FormField>
                            </div>
                        </div>

                        <!-- Bypass Reverse Proxy / Custom Caddy Directives -->
                        <div class="pt-5 border-t border-gray-200 dark:border-gray-800">
                            <h4 class="mb-3 text-sm font-medium text-gray-800 dark:text-white/90">{{ t('Custom Server Configuration') }}</h4>

                            <label class="flex items-center gap-2 mb-4">
                                <input v-model="form.bypass_reverse_proxy" type="checkbox" class="form-checkbox" />
                                <span class="text-sm text-gray-700 dark:text-gray-400">{{ t('Bypass Reverse Proxy') }}</span>
                            </label>

                            <div v-if="form.bypass_reverse_proxy">
                                <div class="mb-4 rounded-lg border border-blue-200 bg-blue-50 p-4 dark:border-blue-800 dark:bg-blue-900/20">
                                    <p class="mb-2 text-sm font-medium text-blue-800 dark:text-blue-300">
                                        {{ t('Custom Caddy Directives') }}
                                    </p>
                                    <p class="mb-3 text-xs text-blue-600 dark:text-blue-400">
                                        {{ t('These directives replace the default server configuration. Enter valid Caddy directives for the root path.') }}
                                    </p>
                                    <pre class="rounded bg-gray-900 p-3 text-xs text-green-400 overflow-x-auto whitespace-pre">{{ exampleDirectives }}</pre>
                                </div>

                                <FormField :label="t('Custom Caddy Directives')" :error="form.errors.custom_caddy_directives">
                                    <textarea
                                        v-model="form.custom_caddy_directives"
                                        rows="16"
                                        class="form-input font-mono text-xs min-h-[200px]"
                                        :placeholder="exampleDirectives"
                                    />
                                </FormField>
                            </div>
                        </div>

                        <!-- Worker Section -->
                        <div v-if="form.type === 'caddy_web_server'" class="pt-5 border-t border-gray-200 dark:border-gray-800">
                            <h4 class="mb-3 text-sm font-medium text-gray-800 dark:text-white/90">{{ t('Worker Settings') }}</h4>

                            <div v-if="!octane_configured" class="mb-4 rounded-lg border border-amber-200 bg-amber-50 p-3 dark:border-amber-800 dark:bg-amber-900/20">
                                <p class="text-xs text-amber-700 dark:text-amber-400">
                                    {{ t('FrankenPHP worker requires laravel/octane. Install it with:') }}
                                    <code class="ml-1 rounded bg-amber-100 px-1.5 py-0.5 font-mono text-xs dark:bg-amber-900/40">composer require laravel/octane && php artisan octane:install</code>
                                </p>
                            </div>

                            <div class="flex items-center gap-4 mb-4">
                                <label class="flex items-center gap-2" :class="{ 'opacity-50 cursor-not-allowed': !octane_configured }">
                                    <input
                                        v-model="form.enable_worker"
                                        type="checkbox"
                                        class="form-checkbox"
                                        :disabled="!octane_configured"
                                    />
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
                                {{ form.processing ? t('Saving...') : t('Save Changes') }}
                            </button>
                            <Link
                                :href="route('domains.show', domain.id)"
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
import { computed, watch } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import ThemeProvider from '@/Components/Layout/ThemeProvider.vue';
import SidebarProvider from '@/Components/Layout/SidebarProvider.vue';
import AdminLayout from '@/Components/Layout/AdminLayout.vue';
import PageBreadcrumb from '@/Components/Common/PageBreadcrumb.vue';
import FormField from '@/Components/UI/FormField.vue';
import { useI18n } from '@/Composables/useI18n';

const props = defineProps<{
    domain: Record<string, any>;
    phpVersions: Array<Record<string, any>>;
    users: Array<Record<string, any>>;
    octane_configured?: boolean;
}>();
const { t } = useI18n();

const breadcrumbs = computed(() => [
    { label: t('Domains'), href: route('domains.index') },
    { label: props.domain.fqdn, href: route('domains.show', props.domain.id) },
    { label: t('Edit') },
]);
const isSubdomain = computed(() => Boolean(props.domain.parent_domain_id));
const showOwnerField = computed(() => props.users.length > 0 && !isSubdomain.value);
const octane_configured = computed(() => props.octane_configured ?? false);

const form = useForm({
    _method: 'PUT',
    fqdn: props.domain.fqdn,
    type: props.domain.type,
    php_version_id: props.domain.php_version_id,
    owner_user_id: props.domain.owner_user_id,
    root_path: props.domain.root_path,
    enable_www_redirect: props.domain.enable_www_redirect,
    ssl_method: props.domain.ssl_method ?? 'cloudflare_dns',
    cors_enabled: props.domain.cors_enabled ?? false,
    cors_allowed_origins: props.domain.cors_allowed_origins ?? '*',
    bypass_reverse_proxy: props.domain.bypass_reverse_proxy ?? false,
    custom_caddy_directives: props.domain.custom_caddy_directives ?? '',
    enable_worker: props.domain.enable_worker,
    worker_num: props.domain.worker_num ?? 2,
    worker_watch: props.domain.worker_watch,
});

const exampleDirectives = `reverse_proxy http://10.0.0.5:3000 {
    header_up Host {host}
    header_up X-Real-IP {remote_host}
    header_up X-Forwarded-For {remote_host}
    header_up X-Forwarded-Proto {scheme}
}`;

watch(() => form.type, (type) => {
    if (type !== 'apache_reverse_proxy') {
        form.php_version_id = null;
    }
});

const submit = () => {
    if (form.type !== 'apache_reverse_proxy') {
        form.php_version_id = null;
    }

    if (!octane_configured.value) {
        form.enable_worker = false;
    }

    form.post(route('domains.update', props.domain.id));
};
</script>

<style scoped>
@reference "../../../css/app.css";

.form-input {
    @apply h-11 w-full rounded-lg border border-gray-300 bg-transparent px-4 py-2.5 text-sm text-gray-800 shadow-theme-xs placeholder:text-gray-400 focus:border-brand-300 focus:outline-hidden focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90 dark:placeholder:text-white/30 dark:focus:border-brand-800;
}
.form-checkbox {
    @apply w-4 h-4 rounded border-gray-300 text-brand-500 focus:ring-brand-500;
}
</style>
