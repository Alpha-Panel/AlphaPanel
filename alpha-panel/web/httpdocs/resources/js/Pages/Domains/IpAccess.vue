<template>
    <Head :title="`${t('IP Access Control')} - ${domain.fqdn}`" />
    <ThemeProvider>
        <SidebarProvider>
            <AdminLayout>
                <PageBreadcrumb
                    :pageTitle="t('IP Access Control')"
                    :items="breadcrumbs"
                    :backHref="route('domains.show', domain.id)"
                />
                <Toast />

                <div class="space-y-4 md:space-y-6">
                    <!-- IP Access Mode -->
                    <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/3 md:p-6">
                        <h4 class="mb-4 text-sm font-semibold text-gray-800 dark:text-white/90">
                            <i class="bx bx-shield mr-1 text-brand-500"></i>
                            {{ t('IP Access Mode') }}
                        </h4>

                        <div class="mb-4 grid grid-cols-1 gap-3 sm:grid-cols-3">
                            <button
                                v-for="option in modeOptions"
                                :key="option.value"
                                type="button"
                                @click="selectedMode = option.value"
                                class="rounded-xl border-2 p-4 text-left transition-colors"
                                :class="selectedMode === option.value
                                    ? 'border-brand-500 bg-brand-50 dark:bg-brand-500/10'
                                    : 'border-gray-200 bg-white hover:border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:hover:border-gray-600'"
                            >
                                <div class="flex items-center gap-2">
                                    <div
                                        class="flex h-4 w-4 items-center justify-center rounded-full border-2"
                                        :class="selectedMode === option.value
                                            ? 'border-brand-500'
                                            : 'border-gray-300 dark:border-gray-600'"
                                    >
                                        <div
                                            v-if="selectedMode === option.value"
                                            class="h-2 w-2 rounded-full bg-brand-500"
                                        ></div>
                                    </div>
                                    <span class="text-sm font-medium text-gray-800 dark:text-white/90">
                                        {{ option.label }}
                                    </span>
                                </div>
                                <p class="mt-1.5 pl-6 text-xs text-gray-500 dark:text-gray-400">
                                    {{ option.description }}
                                </p>
                            </button>
                        </div>

                        <!-- Whitelist Warning -->
                        <div
                            v-if="selectedMode === 'whitelist'"
                            class="mb-4 rounded-lg border border-amber-200 bg-amber-50 p-3 dark:border-amber-800 dark:bg-amber-900/20"
                        >
                            <div class="flex items-start gap-2">
                                <i class="bx bx-error mt-0.5 text-amber-600 dark:text-amber-400"></i>
                                <p class="text-xs text-amber-700 dark:text-amber-400">
                                    {{ t('Warning: Enabling whitelist mode will block all IP addresses not listed below. Ensure your current IP is included to avoid being locked out.') }}
                                </p>
                            </div>
                        </div>

                        <!-- Blacklist Info -->
                        <div
                            v-if="selectedMode === 'blacklist'"
                            class="mb-4 rounded-lg border border-blue-200 bg-blue-50 p-3 dark:border-blue-800 dark:bg-blue-900/20"
                        >
                            <div class="flex items-start gap-2">
                                <i class="bx bx-info-circle mt-0.5 text-blue-600 dark:text-blue-400"></i>
                                <p class="text-xs text-blue-700 dark:text-blue-400">
                                    {{ t('Listed IPs will be blocked with a 403 response. All others will be allowed.') }}
                                </p>
                            </div>
                        </div>

                        <div class="flex justify-end">
                            <button
                                type="button"
                                @click="saveMode"
                                :disabled="modeSaving || selectedMode === domain.ip_access_mode"
                                class="inline-flex h-9 items-center gap-2 rounded-lg bg-brand-500 px-4 text-sm font-medium text-white hover:bg-brand-600 disabled:opacity-50"
                            >
                                <i v-if="modeSaving" class="bx bx-loader-alt animate-spin text-base"></i>
                                <i v-else class="bx bx-save text-base"></i>
                                {{ t('Save Mode') }}
                            </button>
                        </div>
                    </div>

                    <!-- IP Rules -->
                    <div
                        v-if="selectedMode !== 'none'"
                        class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/3 md:p-6"
                    >
                        <div class="mb-5 flex items-center">
                            <h4 class="text-sm font-semibold text-gray-800 dark:text-white/90">
                                <i class="bx bx-list-ul mr-1 text-brand-500"></i>
                                {{ t('IP Rules') }}
                            </h4>
                            <span
                                v-if="localRules.length > 0"
                                class="ml-2 inline-flex h-5 min-w-5 items-center justify-center rounded-full bg-brand-100 px-1.5 text-xs font-medium text-brand-700 dark:bg-brand-500/20 dark:text-brand-400"
                            >
                                {{ localRules.length }}
                            </span>
                        </div>

                        <!-- Add Form -->
                        <div class="mb-5">
                            <div class="flex flex-col gap-3 sm:flex-row sm:items-start">
                                <div class="flex-1">
                                    <input
                                        v-model="addForm.ip_address"
                                        type="text"
                                        class="form-input font-mono"
                                        placeholder="192.168.1.0/24"
                                    />
                                    <p v-if="addErrors.ip_address" class="mt-1 text-xs text-error-500">{{ addErrors.ip_address }}</p>
                                </div>
                                <div class="w-40 shrink-0">
                                    <input
                                        v-model="addForm.path"
                                        type="text"
                                        class="form-input font-mono"
                                        :placeholder="t('Path (optional)')"
                                    />
                                    <p v-if="addErrors.path" class="mt-1 text-xs text-error-500">{{ addErrors.path }}</p>
                                </div>
                                <div class="flex-1">
                                    <input
                                        v-model="addForm.note"
                                        type="text"
                                        class="form-input"
                                        :placeholder="t('Note (optional)')"
                                    />
                                    <p v-if="addErrors.note" class="mt-1 text-xs text-error-500">{{ addErrors.note }}</p>
                                </div>
                                <button
                                    type="button"
                                    @click="addRule"
                                    :disabled="addLoading || !addForm.ip_address"
                                    class="inline-flex h-11 items-center gap-2 rounded-lg bg-brand-500 px-4 text-sm font-medium text-white hover:bg-brand-600 disabled:opacity-50"
                                >
                                    <i v-if="addLoading" class="bx bx-loader-alt animate-spin text-base"></i>
                                    <i v-else class="bx bx-plus text-base"></i>
                                    {{ t('Add') }}
                                </button>
                            </div>
                            <p class="mt-2 text-xs text-gray-400 dark:text-gray-500">
                                {{ t('CIDR notation supported (e.g. 192.168.1.0/24). Path is optional — use wildcards like /admin* to restrict by path.') }}
                            </p>
                        </div>

                        <!-- Empty State -->
                        <div
                            v-if="localRules.length === 0"
                            class="py-12 text-center"
                        >
                            <i class="bx bx-shield mb-3 text-4xl text-gray-300 dark:text-gray-600"></i>
                            <p class="text-sm text-gray-500 dark:text-gray-400">{{ t('No IP rules configured.') }}</p>
                        </div>

                        <!-- Rules Table -->
                        <div v-else class="overflow-x-auto">
                            <table class="w-full text-sm">
                                <thead>
                                    <tr class="border-b border-gray-200 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:border-gray-700 dark:text-gray-400">
                                        <th class="pb-3 pr-4">{{ t('IP Address') }}</th>
                                        <th class="pb-3 pr-4">{{ t('Path') }}</th>
                                        <th class="pb-3 pr-4">{{ t('Note') }}</th>
                                        <th class="pb-3 pr-4">{{ t('Created By') }}</th>
                                        <th class="pb-3 pr-4">{{ t('Created At') }}</th>
                                        <th class="pb-3 text-right">{{ t('Actions') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr
                                        v-for="(rule, index) in localRules"
                                        :key="rule.id"
                                        class="border-b border-gray-100 dark:border-gray-800"
                                        :class="index % 2 === 1 ? 'bg-gray-50/50 dark:bg-white/[0.01]' : ''"
                                    >
                                        <td class="py-3 pr-4">
                                            <code class="rounded bg-gray-100 px-2 py-0.5 text-xs text-gray-700 dark:bg-gray-800 dark:text-gray-300">
                                                {{ rule.ip_address }}
                                            </code>
                                        </td>
                                        <td class="py-3 pr-4">
                                            <code
                                                v-if="rule.path"
                                                class="rounded bg-purple-100 px-2 py-0.5 text-xs text-purple-700 dark:bg-purple-900/30 dark:text-purple-400"
                                            >
                                                {{ rule.path }}
                                            </code>
                                            <span v-else class="text-xs text-gray-400 dark:text-gray-500">
                                                {{ t('All paths') }}
                                            </span>
                                        </td>
                                        <td class="py-3 pr-4 text-gray-600 dark:text-gray-400">
                                            {{ rule.note || '-' }}
                                        </td>
                                        <td class="py-3 pr-4 text-gray-600 dark:text-gray-400">
                                            {{ rule.creator?.name || '-' }}
                                        </td>
                                        <td class="py-3 pr-4 text-gray-500 dark:text-gray-400">
                                            {{ formatDateTime(rule.created_at) }}
                                        </td>
                                        <td class="py-3 text-right">
                                            <button
                                                type="button"
                                                :disabled="deleteLoading === rule.id"
                                                @click="deleteRule(rule)"
                                                class="inline-flex items-center gap-1.5 rounded-lg border border-error-500/40 px-3 py-1.5 text-xs font-medium text-error-600 hover:bg-error-500/10 disabled:opacity-50 dark:text-error-400"
                                            >
                                                <i v-if="deleteLoading === rule.id" class="bx bx-loader-alt animate-spin text-sm"></i>
                                                <i v-else class="bx bx-trash text-sm"></i>
                                                {{ t('Delete') }}
                                            </button>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div class="flex">
                        <Link
                            :href="route('domains.show', domain.id)"
                            class="inline-flex items-center gap-2 rounded-lg border border-gray-300 bg-white px-4 py-2.5 text-sm font-medium text-gray-700 shadow-theme-xs hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-400"
                        >
                            <i class="bx bx-arrow-back text-base"></i>
                            {{ t('Back to Domain') }}
                        </Link>
                    </div>
                </div>
            </AdminLayout>
        </SidebarProvider>
    </ThemeProvider>
</template>

<script setup lang="ts">
import { computed, reactive, ref } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import axios from 'axios';
import ThemeProvider from '@/Components/Layout/ThemeProvider.vue';
import SidebarProvider from '@/Components/Layout/SidebarProvider.vue';
import AdminLayout from '@/Components/Layout/AdminLayout.vue';
import PageBreadcrumb from '@/Components/Common/PageBreadcrumb.vue';
import Toast from '@/Components/UI/Toast.vue';
import { useToast } from '@/Composables/useToast';
import { useI18n } from '@/Composables/useI18n';
import { loadSweetAlert } from '@/utils/sweetalert';
import { formatDateTime } from '@/utils/dateTime';

interface IpRule {
    id: number;
    ip_address: string;
    path: string;
    note: string | null;
    creator: { id: number; name: string } | null;
    created_at: string;
}

interface Domain {
    id: number;
    fqdn: string;
    display_name: string | null;
    parent_domain_id: number | null;
    ip_access_mode: 'none' | 'whitelist' | 'blacklist';
}

const props = defineProps<{
    domain: Domain;
    rules: IpRule[];
}>();

const { t } = useI18n();
const { addToast } = useToast();

const selectedMode = ref<'none' | 'whitelist' | 'blacklist'>(props.domain.ip_access_mode);
const modeSaving = ref(false);

const localRules = reactive<IpRule[]>(props.rules.map((r) => ({ ...r })));

const addLoading = ref(false);
const addErrors = reactive<Record<string, string>>({});
const addForm = reactive({
    ip_address: '',
    path: '',
    note: '',
});

const deleteLoading = ref<number | null>(null);

const breadcrumbs = computed(() => [
    { label: t('Domains'), href: route('domains.index') },
    { label: props.domain.fqdn, href: route('domains.show', props.domain.id) },
    { label: t('IP Access Control') },
]);

const modeOptions = computed(() => [
    {
        value: 'none' as const,
        label: t('None'),
        description: t('No IP restriction, normal access for all visitors.'),
    },
    {
        value: 'whitelist' as const,
        label: t('Whitelist'),
        description: t('Only listed IPs are allowed access.'),
    },
    {
        value: 'blacklist' as const,
        label: t('Blacklist'),
        description: t('Listed IPs are blocked from access.'),
    },
]);

const saveMode = async (): Promise<void> => {
    modeSaving.value = true;

    try {
        const response = await axios.put(route('domains.ip-access.update-mode', props.domain.id), {
            ip_access_mode: selectedMode.value,
        });

        props.domain.ip_access_mode = selectedMode.value;
        addToast('success', response.data.message ?? t('Mode updated successfully.'));
    } catch (error: any) {
        addToast('error', error.response?.data?.message ?? t('Operation failed.'));
    } finally {
        modeSaving.value = false;
    }
};

const addRule = async (): Promise<void> => {
    addLoading.value = true;
    Object.keys(addErrors).forEach((key) => delete addErrors[key]);

    try {
        const response = await axios.post(route('domains.ip-access.store', props.domain.id), {
            ip_address: addForm.ip_address,
            path: addForm.path || null,
            note: addForm.note || null,
        });

        localRules.push(response.data.rule);
        addForm.ip_address = '';
        addForm.path = '';
        addForm.note = '';
        addToast('success', response.data.message ?? t('IP rule added successfully.'));
    } catch (error: any) {
        if (error.response?.status === 422) {
            const errors = error.response.data.errors;
            for (const key in errors) {
                addErrors[key] = errors[key][0];
            }
        } else {
            addToast('error', error.response?.data?.message ?? t('Operation failed.'));
        }
    } finally {
        addLoading.value = false;
    }
};

const deleteRule = async (rule: IpRule): Promise<void> => {
    const swal = await loadSweetAlert();
    if (!swal) {
        return;
    }

    const result = await swal.fire({
        title: t('Delete IP Rule?'),
        text: rule.path
            ? t('This will remove the IP access rule for :ip on path :path.', { ip: rule.ip_address, path: rule.path })
            : t('This will remove the IP access rule for :ip.', { ip: rule.ip_address }),
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: t('Delete'),
        cancelButtonText: t('Cancel'),
    });

    if (!result.isConfirmed) {
        return;
    }

    deleteLoading.value = rule.id;

    try {
        const response = await axios.delete(
            route('domains.ip-access.destroy', [props.domain.id, rule.id]),
        );

        const index = localRules.findIndex((r) => r.id === rule.id);
        if (index !== -1) {
            localRules.splice(index, 1);
        }

        addToast('success', response.data.message ?? t('IP rule deleted successfully.'));
    } catch (error: any) {
        addToast('error', error.response?.data?.message ?? t('Operation failed.'));
    } finally {
        deleteLoading.value = null;
    }
};
</script>

<style scoped>
@reference "../../../css/app.css";

.form-input {
    @apply h-11 w-full rounded-lg border border-gray-300 bg-transparent px-4 py-2.5 text-sm text-gray-800 shadow-theme-xs placeholder:text-gray-400 focus:border-brand-300 focus:outline-hidden focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90 dark:placeholder:text-white/30 dark:focus:border-brand-800;
}
</style>
