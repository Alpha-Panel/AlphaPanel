<template>
    <Head :title="t('WAF Global Rules')" />
    <ThemeProvider>
        <SidebarProvider>
            <AdminLayout>
                <PageBreadcrumb :pageTitle="t('WAF Global Rules')" />

                <div class="space-y-4">
                    <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
                        <h3 class="text-lg font-semibold text-gray-800 dark:text-white/90">{{ t('Server-wide WAF IP Rules') }}</h3>
                        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                            {{ t('Global rules are evaluated before domain-specific rules.') }}
                        </p>

                        <form class="mt-4 grid grid-cols-1 gap-3 md:grid-cols-5" @submit.prevent="createRule">
                            <input v-model="createForm.ip_or_cidr" type="text" class="form-input md:col-span-2" :placeholder="t('IP or CIDR')" />
                            <select v-model="createForm.action" class="form-input">
                                <option value="allow">{{ t('Allow') }}</option>
                                <option value="deny">{{ t('Deny') }}</option>
                            </select>
                            <input v-model="createForm.note" type="text" class="form-input md:col-span-1" :placeholder="t('Note (optional)')" />
                            <button
                                type="submit"
                                :disabled="createForm.processing"
                                class="inline-flex items-center justify-center rounded-lg bg-brand-500 px-4 py-2 text-sm font-medium text-white hover:bg-brand-600 disabled:opacity-50"
                            >
                                {{ createForm.processing ? t('Saving...') : t('Add Rule') }}
                            </button>
                        </form>
                    </div>

                    <div class="rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
                        <div class="overflow-x-auto">
                            <table class="w-full text-sm">
                                <thead>
                                    <tr class="border-b border-gray-200 text-left text-xs uppercase text-gray-500 dark:border-gray-800 dark:text-gray-400">
                                        <th class="px-5 py-3">{{ t('IP/CIDR') }}</th>
                                        <th class="px-5 py-3">{{ t('Action') }}</th>
                                        <th class="px-5 py-3">{{ t('Status') }}</th>
                                        <th class="px-5 py-3">{{ t('Note') }}</th>
                                        <th class="px-5 py-3 text-right">{{ t('Actions') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-if="rules.length === 0">
                                        <td colspan="5" class="px-5 py-8 text-center text-gray-500 dark:text-gray-400">{{ t('No global rules yet.') }}</td>
                                    </tr>
                                    <tr v-for="rule in rules" :key="rule.id" class="border-b border-gray-100 last:border-0 dark:border-gray-800">
                                        <td class="px-5 py-3 font-mono text-xs text-gray-700 dark:text-gray-300">{{ rule.ip_or_cidr }}</td>
                                        <td class="px-5 py-3">
                                            <span
                                                :class="[
                                                    'inline-flex rounded-full px-2 py-0.5 text-xs font-semibold',
                                                    rule.action === 'allow'
                                                        ? 'bg-success-500/15 text-success-700 dark:text-success-300'
                                                        : 'bg-error-500/15 text-error-700 dark:text-error-300',
                                                ]"
                                            >
                                                {{ rule.action.toUpperCase() }}
                                            </span>
                                        </td>
                                        <td class="px-5 py-3">
                                            <label class="inline-flex items-center gap-2 text-xs text-gray-600 dark:text-gray-300">
                                                <input
                                                    type="checkbox"
                                                    class="form-checkbox"
                                                    :checked="rule.enabled"
                                                    @change="toggleEnabled(rule)"
                                                />
                                                {{ rule.enabled ? t('Enabled') : t('Disabled') }}
                                            </label>
                                        </td>
                                        <td class="px-5 py-3 text-xs text-gray-600 dark:text-gray-300">{{ rule.note || '-' }}</td>
                                        <td class="px-5 py-3 text-right">
                                            <button
                                                type="button"
                                                class="inline-flex rounded border border-error-500/40 px-2 py-1 text-xs text-error-700 hover:bg-error-500/10 dark:text-error-300"
                                                @click="removeRule(rule)"
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
            </AdminLayout>
        </SidebarProvider>
    </ThemeProvider>
</template>

<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import ThemeProvider from '@/Components/Layout/ThemeProvider.vue';
import SidebarProvider from '@/Components/Layout/SidebarProvider.vue';
import AdminLayout from '@/Components/Layout/AdminLayout.vue';
import PageBreadcrumb from '@/Components/Common/PageBreadcrumb.vue';
import { useI18n } from '@/Composables/useI18n';

interface GlobalRule {
    id: number;
    ip_or_cidr: string;
    action: 'allow' | 'deny';
    note: string | null;
    enabled: boolean;
}

const props = defineProps<{ rules: GlobalRule[] }>();
const { t } = useI18n();
const rules = props.rules ?? [];

const createForm = useForm({
    ip_or_cidr: '',
    action: 'deny' as 'allow' | 'deny',
    note: '',
    enabled: true,
});

const createRule = (): void => {
    createForm.post(route('security.waf-global.store'), {
        preserveScroll: true,
        onSuccess: () => {
            createForm.reset('ip_or_cidr', 'note');
            createForm.action = 'deny';
            createForm.enabled = true;
        },
    });
};

const toggleEnabled = (rule: GlobalRule): void => {
    router.put(route('security.waf-global.update', rule.id), {
        ip_or_cidr: rule.ip_or_cidr,
        action: rule.action,
        note: rule.note,
        enabled: !rule.enabled,
    }, {
        preserveScroll: true,
    });
};

const removeRule = (rule: GlobalRule): void => {
    router.delete(route('security.waf-global.destroy', rule.id), {
        preserveScroll: true,
    });
};
</script>

<style scoped>
@reference "../../../css/app.css";

.form-input {
    @apply h-11 w-full rounded-lg border border-gray-300 bg-transparent px-4 py-2.5 text-sm text-gray-800 shadow-theme-xs placeholder:text-gray-400 focus:border-brand-300 focus:outline-hidden focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90 dark:placeholder:text-white/30 dark:focus:border-brand-800;
}

.form-checkbox {
    @apply h-4 w-4 rounded border-gray-300 text-brand-500 focus:ring-brand-500;
}
</style>
