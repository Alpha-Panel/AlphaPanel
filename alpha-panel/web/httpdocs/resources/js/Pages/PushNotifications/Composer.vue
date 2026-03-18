<template>
    <Head :title="t('Send Push Notification')" />
    <ThemeProvider>
        <SidebarProvider>
            <AdminLayout>
                <PageBreadcrumb :pageTitle="t('Send Push Notification')" />
                <Toast />

                <div class="mx-auto max-w-2xl">
                    <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03] md:p-6">
                        <h3 class="mb-5 text-lg font-semibold text-gray-800 dark:text-white/90">
                            {{ t('Send Push Notification') }}
                        </h3>

                        <form @submit.prevent="submit" class="space-y-5">
                            <!-- Title -->
                            <div>
                                <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">
                                    {{ t('Notification Title') }}
                                </label>
                                <input
                                    v-model="form.title"
                                    type="text"
                                    maxlength="255"
                                    class="form-input"
                                    :placeholder="t('Notification Title')"
                                />
                                <p v-if="errors.title" class="mt-1 text-xs text-error-500">{{ errors.title }}</p>
                            </div>

                            <!-- Body -->
                            <div>
                                <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">
                                    {{ t('Notification Body') }}
                                </label>
                                <textarea
                                    v-model="form.body"
                                    rows="4"
                                    maxlength="1000"
                                    class="form-input"
                                    :placeholder="t('Notification Body')"
                                ></textarea>
                                <p v-if="errors.body" class="mt-1 text-xs text-error-500">{{ errors.body }}</p>
                            </div>

                            <!-- URL -->
                            <div>
                                <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">
                                    {{ t('Target URL (optional)') }}
                                </label>
                                <input
                                    v-model="form.url"
                                    type="url"
                                    maxlength="500"
                                    class="form-input"
                                    placeholder="https://..."
                                />
                                <p v-if="errors.url" class="mt-1 text-xs text-error-500">{{ errors.url }}</p>
                            </div>

                            <!-- Target Audience -->
                            <div>
                                <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">
                                    {{ t('Target Audience') }}
                                </label>
                                <select v-model="form.target" class="form-input">
                                    <option value="all">{{ t('All Users') }}</option>
                                    <option value="admins">{{ t('Admins Only') }}</option>
                                    <option value="domain">{{ t('Domain Users') }}</option>
                                </select>
                                <p v-if="errors.target" class="mt-1 text-xs text-error-500">{{ errors.target }}</p>
                            </div>

                            <!-- Domain Search (conditional) -->
                            <div v-if="form.target === 'domain'">
                                <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">
                                    {{ t('Select Domain') }}
                                </label>
                                <div class="relative">
                                    <input
                                        v-model="domainSearch"
                                        type="text"
                                        class="form-input"
                                        :placeholder="t('Select Domain')"
                                        @input="searchDomains"
                                    />
                                    <div
                                        v-if="domainResults.length > 0 && domainDropdownOpen"
                                        class="absolute z-10 mt-1 max-h-48 w-full overflow-y-auto rounded-lg border border-gray-200 bg-white shadow-lg dark:border-gray-700 dark:bg-gray-900"
                                    >
                                        <button
                                            v-for="domain in domainResults"
                                            :key="domain.id"
                                            type="button"
                                            class="block w-full px-3 py-2 text-left text-sm text-gray-700 hover:bg-gray-50 dark:text-gray-300 dark:hover:bg-gray-800"
                                            @click="selectDomain(domain)"
                                        >
                                            {{ domain.fqdn }}
                                        </button>
                                    </div>
                                </div>
                                <p v-if="selectedDomain" class="mt-1.5 text-xs text-brand-500">
                                    {{ t('Selected') }}: {{ selectedDomain.fqdn }}
                                </p>
                                <p v-if="errors.domain_id" class="mt-1 text-xs text-error-500">{{ errors.domain_id }}</p>
                            </div>

                            <!-- Submit -->
                            <div class="flex justify-end">
                                <button
                                    type="submit"
                                    :disabled="sending"
                                    class="rounded-lg bg-brand-500 px-5 py-2.5 text-sm font-medium text-white hover:bg-brand-600 disabled:opacity-50"
                                >
                                    {{ sending ? t('Sending...') : t('Send Notification') }}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </AdminLayout>
        </SidebarProvider>
    </ThemeProvider>
</template>

<script setup lang="ts">
import { reactive, ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import axios from 'axios';
import ThemeProvider from '@/Components/Layout/ThemeProvider.vue';
import SidebarProvider from '@/Components/Layout/SidebarProvider.vue';
import AdminLayout from '@/Components/Layout/AdminLayout.vue';
import PageBreadcrumb from '@/Components/Common/PageBreadcrumb.vue';
import Toast from '@/Components/UI/Toast.vue';
import { useToast } from '@/Composables/useToast';
import { useI18n } from '@/Composables/useI18n';

interface DomainResult {
    id: number;
    fqdn: string;
}

const { addToast } = useToast();
const { t } = useI18n();

const form = reactive({
    title: '',
    body: '',
    url: '',
    target: 'all',
    domain_id: null as number | null,
});

const errors = reactive<Record<string, string>>({});
const sending = ref(false);
const domainSearch = ref('');
const domainResults = ref<DomainResult[]>([]);
const domainDropdownOpen = ref(false);
const selectedDomain = ref<DomainResult | null>(null);
let searchTimeout: ReturnType<typeof setTimeout> | null = null;

const searchDomains = () => {
    if (searchTimeout) clearTimeout(searchTimeout);
    domainDropdownOpen.value = true;

    searchTimeout = setTimeout(async () => {
        if (domainSearch.value.length < 2) {
            domainResults.value = [];
            return;
        }
        try {
            const res = await axios.get(route('domains.search'), {
                params: { q: domainSearch.value },
            });
            domainResults.value = res.data;
        } catch {
            domainResults.value = [];
        }
    }, 300);
};

const selectDomain = (domain: DomainResult) => {
    selectedDomain.value = domain;
    form.domain_id = domain.id;
    domainSearch.value = domain.fqdn;
    domainDropdownOpen.value = false;
};

const submit = async () => {
    sending.value = true;
    Object.keys(errors).forEach((key) => delete errors[key]);

    try {
        const response = await axios.post(route('admin.push-notifications.send'), form);
        addToast('success', t(':count users notified.', { count: response.data.recipients_count }));
        form.title = '';
        form.body = '';
        form.url = '';
        form.target = 'all';
        form.domain_id = null;
        domainSearch.value = '';
        selectedDomain.value = null;
    } catch (error: any) {
        if (error.response?.status === 422) {
            const serverErrors = error.response.data.errors ?? {};
            Object.entries(serverErrors).forEach(([key, messages]) => {
                errors[key] = Array.isArray(messages) ? messages[0] : String(messages);
            });
            if (error.response.data.message && !Object.keys(serverErrors).length) {
                addToast('error', error.response.data.message);
            }
        } else {
            addToast('error', error.response?.data?.message ?? t('Failed to send notification.'));
        }
    } finally {
        sending.value = false;
    }
};
</script>

<style scoped>
@reference "../../../css/app.css";

.form-input {
    @apply h-11 w-full rounded-lg border border-gray-300 bg-transparent px-4 py-2.5 text-sm text-gray-800 shadow-theme-xs focus:border-brand-300 focus:outline-hidden focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90;
}
</style>
