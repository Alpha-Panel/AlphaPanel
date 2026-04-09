<template>
    <Head :title="t('Login IP Filter')" />
    <ThemeProvider>
        <SidebarProvider>
            <AdminLayout>
                <PageBreadcrumb
                    :pageTitle="t('Login IP Filter')"
                    :items="breadcrumbs"
                />
                <Toast />

                <div class="space-y-4">
                    <!-- Filter Mode -->
                    <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/3 md:p-6">
                        <h3 class="mb-5 text-lg font-semibold text-gray-800 dark:text-white/90">
                            <i class="fa-solid fa-filter mr-2 text-brand-500"></i>
                            {{ t('Login IP Filter') }}
                        </h3>

                        <!-- Mode Selection -->
                        <div>
                            <h4 class="mb-3 flex items-center gap-2 text-sm font-semibold text-gray-800 dark:text-white/90">
                                <i class="fa-solid fa-sliders text-base text-brand-500"></i>
                                {{ t('Filter Mode') }}
                            </h4>
                            <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                                <label
                                    v-for="option in modeOptions"
                                    :key="option.value"
                                    class="relative flex cursor-pointer items-start gap-3 rounded-xl border-2 p-4 transition-all duration-200"
                                    :class="[
                                        currentMode === option.value
                                            ? 'border-brand-500 bg-brand-50/50 shadow-sm dark:border-brand-400 dark:bg-brand-500/5'
                                            : 'border-gray-200 hover:border-gray-300 dark:border-gray-700 dark:hover:border-gray-600',
                                    ]"
                                >
                                    <input
                                        :checked="currentMode === option.value"
                                        type="radio"
                                        name="filter_mode"
                                        :value="option.value"
                                        class="mt-0.5 h-4 w-4 border-gray-300 text-brand-500 focus:ring-brand-500 dark:border-gray-600"
                                        @change="updateMode(option.value)"
                                    />
                                    <div class="flex-1">
                                        <div class="flex items-center gap-2">
                                            <i :class="[option.icon, 'text-base', currentMode === option.value ? 'text-brand-500' : 'text-gray-400 dark:text-gray-500']"></i>
                                            <span class="text-sm font-medium text-gray-800 dark:text-white/90">{{ t(option.label) }}</span>
                                        </div>
                                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ t(option.description) }}</p>
                                    </div>
                                </label>
                            </div>
                        </div>

                        <!-- Whitelist Warning -->
                        <div
                            v-if="currentMode === 'whitelist' && rules.length === 0"
                            class="mt-4 rounded-lg border border-warning-200 bg-warning-50 p-3 text-sm text-warning-700 dark:border-warning-800 dark:bg-warning-900/20 dark:text-warning-400"
                        >
                            <i class="fa-solid fa-triangle-exclamation mr-1"></i>
                            {{ t('Warning: Whitelist mode is active with no IP rules. All logins will be blocked.') }}
                        </div>
                    </div>

                    <!-- IP Rules -->
                    <div v-if="currentMode !== 'off'" class="space-y-4">
                        <!-- Add Rule Form -->
                        <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/3">
                            <h4 class="mb-3 flex items-center gap-2 text-sm font-semibold text-gray-800 dark:text-white/90">
                                <i class="fa-solid fa-plus text-base text-brand-500"></i>
                                {{ t('Add IP Rule') }}
                            </h4>
                            <form class="grid grid-cols-1 gap-3 md:grid-cols-4" @submit.prevent="addRule">
                                <div class="md:col-span-1">
                                    <input
                                        v-model="addForm.ip_address"
                                        type="text"
                                        class="form-input"
                                        :placeholder="t('IP Address (e.g. 192.168.1.1)')"
                                    />
                                    <p v-if="addForm.errors.ip_address" class="mt-1 text-sm text-error-500">{{ addForm.errors.ip_address }}</p>
                                </div>
                                <div class="md:col-span-2">
                                    <input
                                        v-model="addForm.note"
                                        type="text"
                                        class="form-input"
                                        :placeholder="t('Note (optional)')"
                                    />
                                    <p v-if="addForm.errors.note" class="mt-1 text-sm text-error-500">{{ addForm.errors.note }}</p>
                                </div>
                                <button
                                    type="submit"
                                    :disabled="addForm.processing"
                                    class="inline-flex items-center justify-center rounded-lg bg-brand-500 px-4 py-2 text-sm font-medium text-white hover:bg-brand-600 disabled:opacity-50"
                                >
                                    <i v-if="addForm.processing" class="bx bx-loader-alt mr-1.5 animate-spin text-base"></i>
                                    {{ addForm.processing ? t('Adding...') : t('Add Rule') }}
                                </button>
                            </form>
                        </div>

                        <!-- Rules Table -->
                        <div class="rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/3">
                            <div class="overflow-x-auto">
                                <table class="w-full text-sm">
                                    <thead>
                                        <tr class="border-b border-gray-200 text-left text-xs uppercase text-gray-500 dark:border-gray-800 dark:text-gray-400">
                                            <th class="px-5 py-3">{{ t('IP Address') }}</th>
                                            <th class="px-5 py-3">{{ t('Note') }}</th>
                                            <th class="px-5 py-3">{{ t('Created By') }}</th>
                                            <th class="px-5 py-3">{{ t('Created At') }}</th>
                                            <th class="px-5 py-3 text-right">{{ t('Actions') }}</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr v-if="rules.length === 0">
                                            <td colspan="5" class="px-5 py-8 text-center text-gray-500 dark:text-gray-400">
                                                {{ t('No IP rules configured yet') }}
                                            </td>
                                        </tr>
                                        <tr
                                            v-for="rule in rules"
                                            :key="rule.id"
                                            class="border-b border-gray-100 last:border-0 dark:border-gray-800"
                                        >
                                            <td class="px-5 py-3 font-mono text-xs text-gray-700 dark:text-gray-300">
                                                {{ rule.ip_address }}
                                            </td>
                                            <td class="px-5 py-3 text-xs text-gray-600 dark:text-gray-300">
                                                {{ rule.note || '-' }}
                                            </td>
                                            <td class="px-5 py-3 text-xs text-gray-600 dark:text-gray-300">
                                                {{ rule.creator?.name || '-' }}
                                            </td>
                                            <td class="px-5 py-3 text-xs text-gray-600 dark:text-gray-300">
                                                {{ formatDate(rule.created_at) }}
                                            </td>
                                            <td class="px-5 py-3 text-right">
                                                <button
                                                    type="button"
                                                    class="inline-flex rounded border border-error-500/40 px-2 py-1 text-xs text-error-700 hover:bg-error-500/10 dark:text-error-300"
                                                    @click="deleteRule(rule)"
                                                >
                                                    {{ t('Delete') }}
                                                </button>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </AdminLayout>
        </SidebarProvider>
    </ThemeProvider>
</template>

<script setup lang="ts">
import { computed, ref } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import ThemeProvider from '@/Components/Layout/ThemeProvider.vue';
import SidebarProvider from '@/Components/Layout/SidebarProvider.vue';
import AdminLayout from '@/Components/Layout/AdminLayout.vue';
import PageBreadcrumb from '@/Components/Common/PageBreadcrumb.vue';
import Toast from '@/Components/UI/Toast.vue';
import { useI18n } from '@/Composables/useI18n';

interface IpRule {
    id: number;
    ip_address: string;
    note: string | null;
    created_at: string;
    creator: { id: number; name: string } | null;
}

interface Props {
    mode: 'off' | 'whitelist' | 'blacklist';
    rules: IpRule[];
}

const props = defineProps<Props>();

const { t } = useI18n();

const breadcrumbs = computed(() => [
    { label: t('Settings') },
    { label: t('Security') },
    { label: t('Login IP Filter') },
]);

const currentMode = ref<'off' | 'whitelist' | 'blacklist'>(props.mode);

const rules = computed(() => props.rules ?? []);

const modeOptions = [
    {
        value: 'off' as const,
        label: 'Off',
        description: 'All IP addresses can access the login page',
        icon: 'fa-solid fa-unlock',
    },
    {
        value: 'whitelist' as const,
        label: 'Whitelist',
        description: 'Only IP addresses in the list below can login',
        icon: 'fa-solid fa-check-circle',
    },
    {
        value: 'blacklist' as const,
        label: 'Blacklist',
        description: 'IP addresses in the list below cannot login',
        icon: 'fa-solid fa-times-circle',
    },
];

const updateMode = (newMode: 'off' | 'whitelist' | 'blacklist'): void => {
    currentMode.value = newMode;
    router.put(route('settings.security.login-ip-filter.update-mode'), {
        mode: newMode,
    }, {
        preserveScroll: true,
    });
};

const addForm = useForm({
    ip_address: '',
    note: '',
});

const addRule = (): void => {
    addForm.post(route('settings.security.login-ip-filter.store'), {
        preserveScroll: true,
        onSuccess: () => {
            addForm.reset('ip_address', 'note');
        },
    });
};

const deleteRule = (rule: IpRule): void => {
    if (!confirm(t('Are you sure you want to delete this IP rule?'))) {
        return;
    }

    router.delete(route('settings.security.login-ip-filter.destroy', rule.id), {
        preserveScroll: true,
    });
};

const formatDate = (dateString: string): string => {
    const date = new Date(dateString);

    return date.toLocaleDateString(undefined, {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
};
</script>

<style scoped>
@reference "../../../../css/app.css";

.form-input {
    @apply h-11 w-full rounded-lg border border-gray-300 bg-transparent px-4 py-2.5 text-sm text-gray-800 shadow-theme-xs placeholder:text-gray-400 focus:border-brand-300 focus:outline-hidden focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90 dark:placeholder:text-white/30 dark:focus:border-brand-800;
}
</style>
