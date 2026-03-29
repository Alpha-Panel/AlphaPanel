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

                    <form @submit.prevent="saveSettings" class="space-y-5">
                        <div>
                            <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">{{ t('PHP Version') }}</label>
                            <select v-model="form.php_version_id" class="form-input">
                                <option v-for="v in phpVersions" :key="v.id" :value="v.id">{{ v.slug }}</option>
                            </select>
                        </div>

                        <div>
                            <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">{{ t('Display Errors') }}</label>
                            <select v-model="form.display_errors" class="form-input">
                                <option value="On">{{ t('On') }}</option>
                                <option value="Off">{{ t('Off') }}</option>
                            </select>
                            <p class="mt-1 text-xs text-gray-500 dark:text-gray-500">{{ t('Show PHP errors on the page. Should be Off in production.') }}</p>
                        </div>

                        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <div>
                                <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">{{ t('memory_limit') }}</label>
                                <input v-model="form.memory_limit" type="text" class="form-input" placeholder="256M" />
                            </div>
                            <div>
                                <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">{{ t('upload_max_filesize') }}</label>
                                <input v-model="form.upload_max_filesize" type="text" class="form-input" placeholder="64M" />
                            </div>
                            <div>
                                <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">{{ t('post_max_size') }}</label>
                                <input v-model="form.post_max_size" type="text" class="form-input" placeholder="64M" />
                            </div>
                            <div>
                                <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">{{ t('max_execution_time') }}</label>
                                <input v-model="form.max_execution_time" type="text" class="form-input" placeholder="30" />
                            </div>
                            <div>
                                <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">{{ t('max_input_time') }}</label>
                                <input v-model="form.max_input_time" type="text" class="form-input" placeholder="60" />
                            </div>
                            <div>
                                <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">{{ t('max_input_vars') }}</label>
                                <input v-model="form.max_input_vars" type="text" class="form-input" placeholder="1000" />
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

const phpSetting = props.domain.php_setting || {};

const form = ref({
    php_version_id: props.domain.php_version_id,
    display_errors: phpSetting.display_errors ?? 'Off',
    memory_limit: phpSetting.memory_limit ?? '256M',
    upload_max_filesize: phpSetting.upload_max_filesize ?? '64M',
    post_max_size: phpSetting.post_max_size ?? '64M',
    max_execution_time: phpSetting.max_execution_time ?? '30',
    max_input_time: phpSetting.max_input_time ?? '60',
    max_input_vars: phpSetting.max_input_vars ?? '1000',
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

.form-input {
    @apply h-11 w-full rounded-lg border border-gray-300 bg-transparent px-4 py-2.5 text-sm text-gray-800 shadow-theme-xs placeholder:text-gray-400 focus:border-brand-300 focus:outline-hidden focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90 dark:placeholder:text-white/30 dark:focus:border-brand-800;
}
</style>
