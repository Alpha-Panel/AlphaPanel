<template>
    <Head :title="`${t('PHP Settings')} - ${domain.fqdn}`" />
    <ThemeProvider>
        <SidebarProvider>
            <AdminLayout>
                <PageBreadcrumb
                    :pageTitle="t('PHP Settings')"
                    :items="breadcrumbs"
                    :backHref="route('domains.show', domain.id)"
                />
                <Toast />

                <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03] md:p-6">
                    <h3 class="mb-5 text-lg font-semibold text-gray-800 dark:text-white/90">{{ t('PHP Configuration') }}</h3>

                    <form @submit.prevent="saveSettings" class="space-y-6">
                        <!-- PHP Version -->
                        <div>
                            <label class="form-label">{{ t('PHP Version') }}</label>
                            <select v-model="form.php_version_id" class="form-input">
                                <option v-for="v in phpVersions" :key="v.id" :value="v.id">{{ v.slug }}</option>
                            </select>
                        </div>

                        <!-- Error Handling -->
                        <div>
                            <h4 class="mb-3 text-sm font-semibold text-gray-800 dark:text-white/80">{{ t('Error Handling') }}</h4>
                            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                <div>
                                    <label class="form-label">{{ t('Display Errors') }}</label>
                                    <select v-model="form.display_errors" class="form-input">
                                        <option value="On">{{ t('On') }}</option>
                                        <option value="Off">{{ t('Off') }}</option>
                                    </select>
                                    <p class="form-hint">{{ t('Show PHP errors on the page. Should be Off in production.') }}</p>
                                </div>
                                <div>
                                    <label class="form-label">{{ t('Error Reporting') }}</label>
                                    <select v-model="form.error_reporting" class="form-input">
                                        <option value="E_ALL">E_ALL</option>
                                        <option value="E_ALL & ~E_NOTICE">E_ALL & ~E_NOTICE</option>
                                        <option value="E_ALL & ~E_NOTICE & ~E_DEPRECATED">E_ALL & ~E_NOTICE & ~E_DEPRECATED</option>
                                        <option value="E_ERROR | E_WARNING | E_PARSE">E_ERROR | E_WARNING | E_PARSE</option>
                                        <option value="0">{{ t('None') }} (0)</option>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <!-- Resource Limits -->
                        <div>
                            <h4 class="mb-3 text-sm font-semibold text-gray-800 dark:text-white/80">{{ t('Resource Limits') }}</h4>
                            <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                                <div>
                                    <label class="form-label">{{ t('Memory Limit') }}</label>
                                    <select v-model="form.memory_limit" class="form-input">
                                        <option v-for="s in memorySizes" :key="s" :value="s">{{ s }}</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="form-label">{{ t('Upload Max Filesize') }}</label>
                                    <select v-model="form.upload_max_filesize" class="form-input">
                                        <option v-for="s in uploadSizes" :key="s" :value="s">{{ s }}</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="form-label">{{ t('Post Max Size') }}</label>
                                    <select v-model="form.post_max_size" class="form-input">
                                        <option v-for="s in uploadSizes" :key="s" :value="s">{{ s }}</option>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <!-- Execution Limits -->
                        <div>
                            <h4 class="mb-3 text-sm font-semibold text-gray-800 dark:text-white/80">{{ t('Execution Limits') }}</h4>
                            <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                                <div>
                                    <label class="form-label">{{ t('Max Execution Time') }}</label>
                                    <div class="flex items-center gap-2">
                                        <input v-model.number="form.max_execution_time" type="number" min="0" max="86400" class="form-input" />
                                        <span class="shrink-0 text-xs text-gray-500 dark:text-gray-500">{{ t('seconds') }}</span>
                                    </div>
                                </div>
                                <div>
                                    <label class="form-label">{{ t('Max Input Time') }}</label>
                                    <div class="flex items-center gap-2">
                                        <input v-model.number="form.max_input_time" type="number" min="0" max="86400" class="form-input" />
                                        <span class="shrink-0 text-xs text-gray-500 dark:text-gray-500">{{ t('seconds') }}</span>
                                    </div>
                                </div>
                                <div>
                                    <label class="form-label">{{ t('Max Input Vars') }}</label>
                                    <input v-model.number="form.max_input_vars" type="number" min="100" max="100000" class="form-input" />
                                </div>
                            </div>
                        </div>

                        <!-- Session -->
                        <div>
                            <h4 class="mb-3 text-sm font-semibold text-gray-800 dark:text-white/80">{{ t('Session') }}</h4>
                            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                <div>
                                    <label class="form-label">{{ t('Session GC Maxlifetime') }}</label>
                                    <div class="flex items-center gap-2">
                                        <input v-model.number="form.session_gc_maxlifetime" type="number" min="0" max="604800" class="form-input" />
                                        <span class="shrink-0 text-xs text-gray-500 dark:text-gray-500">{{ t('seconds') }}</span>
                                    </div>
                                </div>
                                <div>
                                    <label class="form-label">{{ t('Session Cookie Lifetime') }}</label>
                                    <div class="flex items-center gap-2">
                                        <input v-model.number="form.session_cookie_lifetime" type="number" min="0" max="604800" class="form-input" />
                                        <span class="shrink-0 text-xs text-gray-500 dark:text-gray-500">{{ t('seconds') }}</span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Other Settings -->
                        <div>
                            <h4 class="mb-3 text-sm font-semibold text-gray-800 dark:text-white/80">{{ t('Other Settings') }}</h4>
                            <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                                <div>
                                    <label class="form-label">{{ t('OPcache') }}</label>
                                    <select v-model="form.opcache_enable" class="form-input">
                                        <option value="On">{{ t('On') }}</option>
                                        <option value="Off">{{ t('Off') }}</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="form-label">{{ t('Date Timezone') }}</label>
                                    <input v-model="form.date_timezone" type="text" class="form-input" placeholder="Europe/Istanbul" />
                                </div>
                                <div>
                                    <label class="form-label">{{ t('Allow URL Fopen') }}</label>
                                    <select v-model="form.allow_url_fopen" class="form-input">
                                        <option value="On">{{ t('On') }}</option>
                                        <option value="Off">{{ t('Off') }}</option>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <!-- Security -->
                        <div>
                            <h4 class="mb-3 text-sm font-semibold text-gray-800 dark:text-white/80">{{ t('Security') }}</h4>
                            <div>
                                <label class="form-label">{{ t('Disabled Functions') }}</label>
                                <textarea v-model="form.disable_functions" rows="3" class="form-input" :placeholder="t('Comma-separated list of disabled PHP functions')"></textarea>
                            </div>
                        </div>

                        <div class="flex items-center gap-3 pt-5">
                            <button type="submit" :disabled="saving" class="inline-flex items-center gap-2 rounded-lg bg-brand-500 px-4 py-2.5 text-sm font-medium text-white shadow-theme-xs hover:bg-brand-600 disabled:opacity-50">
                                {{ saving ? t('Saving...') : t('Save Settings') }}
                            </button>
                            <Link :href="route('domains.show', domain.id)" class="inline-flex items-center gap-2 rounded-lg border border-gray-300 bg-white px-4 py-2.5 text-sm font-medium text-gray-700 shadow-theme-xs hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-400">
                                {{ t('Back') }}
                            </Link>
                        </div>
                    </form>
                </div>
            </AdminLayout>
        </SidebarProvider>
    </ThemeProvider>
</template>

<script setup lang="ts">
import { computed, ref } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import axios from 'axios';
import ThemeProvider from '@/Components/Layout/ThemeProvider.vue';
import SidebarProvider from '@/Components/Layout/SidebarProvider.vue';
import AdminLayout from '@/Components/Layout/AdminLayout.vue';
import PageBreadcrumb from '@/Components/Common/PageBreadcrumb.vue';
import Toast from '@/Components/UI/Toast.vue';
import { useToast } from '@/Composables/useToast';
import { useI18n } from '@/Composables/useI18n';

const props = defineProps<{
    domain: Record<string, any>;
    phpVersions: Array<Record<string, any>>;
}>();
const { t } = useI18n();

const breadcrumbs = computed(() => [
    { label: t('Domains'), href: route('domains.index') },
    { label: props.domain.fqdn, href: route('domains.show', props.domain.id) },
    { label: t('PHP Settings') },
]);

const { addToast } = useToast();
const saving = ref(false);

const memorySizes = ['32M', '64M', '128M', '256M', '512M', '1G', '2G', '4G'];
const uploadSizes = ['8M', '16M', '32M', '64M', '128M', '256M', '512M', '1G', '2G', '4G'];

const phpSetting = props.domain.php_setting || {};

const form = ref({
    php_version_id: props.domain.php_version_id,
    display_errors: phpSetting.display_errors ?? 'Off',
    error_reporting: phpSetting.error_reporting ?? 'E_ALL',
    memory_limit: phpSetting.memory_limit ?? '256M',
    upload_max_filesize: phpSetting.upload_max_filesize ?? '256M',
    post_max_size: phpSetting.post_max_size ?? '256M',
    max_execution_time: phpSetting.max_execution_time ?? 3000,
    max_input_time: phpSetting.max_input_time ?? 3000,
    max_input_vars: phpSetting.max_input_vars ?? 3000,
    session_gc_maxlifetime: phpSetting.session_gc_maxlifetime ?? 1440,
    session_cookie_lifetime: phpSetting.session_cookie_lifetime ?? 1440,
    opcache_enable: phpSetting.opcache_enable ?? 'On',
    date_timezone: phpSetting.date_timezone ?? 'Europe/Istanbul',
    allow_url_fopen: phpSetting.allow_url_fopen ?? 'On',
    disable_functions: phpSetting.disable_functions ?? '',
});

const saveSettings = async () => {
    saving.value = true;
    try {
        const res = await axios.put(route('domains.php.update', props.domain.id), form.value);
        addToast('success', res.data.message);
    } catch (e: any) {
        addToast('error', e.response?.data?.message || t('Failed to save settings'));
    } finally {
        saving.value = false;
    }
};
</script>

<style scoped>
@reference "../../../css/app.css";

.form-label {
    @apply mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400;
}

.form-input {
    @apply h-11 w-full rounded-lg border border-gray-300 bg-transparent px-4 py-2.5 text-sm text-gray-800 shadow-theme-xs placeholder:text-gray-400 focus:border-brand-300 focus:outline-hidden focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90 dark:placeholder:text-white/30 dark:focus:border-brand-800;
}

textarea.form-input {
    @apply h-auto;
}

.form-hint {
    @apply mt-1 text-xs text-gray-500 dark:text-gray-500;
}
</style>
