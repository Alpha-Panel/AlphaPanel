<template>
    <Head :title="t('DNS Templates')" />
    <ThemeProvider>
        <SidebarProvider>
            <AdminLayout>
                <PageBreadcrumb
                    :pageTitle="t('DNS Templates')"
                    :items="breadcrumbs"
                />
                <Toast />

                <div class="rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/3">
                    <div class="flex flex-col gap-4 border-b border-gray-200 px-5 py-4 sm:flex-row sm:items-center sm:justify-between dark:border-gray-800 md:px-6">
                        <h3 class="text-lg font-semibold text-gray-800 dark:text-white/90">
                            <i class="bx bx-file mr-2 text-brand-500"></i>
                            {{ t('DNS Templates') }}
                        </h3>
                        <button
                            type="button"
                            @click="openCreateModal"
                            class="inline-flex items-center gap-2 rounded-lg bg-brand-500 px-4 py-2.5 text-sm font-medium text-white shadow-theme-xs hover:bg-brand-600"
                        >
                            <i class="bx bx-plus text-base"></i>
                            {{ t('Create Template') }}
                        </button>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="w-full">
                            <thead>
                                <tr class="border-b border-gray-200 dark:border-gray-800">
                                    <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-500">{{ t('Name') }}</th>
                                    <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-500">{{ t('Records') }}</th>
                                    <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-500">{{ t('Default') }}</th>
                                    <th class="px-5 py-3 text-right text-xs font-medium uppercase text-gray-500">
                                        <i class="bx bx-cog"></i>
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-if="localTemplates.length === 0" class="border-t border-gray-200 dark:border-gray-800">
                                    <td colspan="4" class="px-5 py-10 text-center text-sm text-gray-500 dark:text-gray-400">
                                        {{ t('No DNS templates found. Create one to get started.') }}
                                    </td>
                                </tr>
                                <tr
                                    v-for="tpl in localTemplates"
                                    :key="tpl.id"
                                    class="border-t border-gray-200 hover:bg-gray-50 dark:border-gray-800 dark:hover:bg-white/2"
                                >
                                    <td class="px-5 py-3 text-sm font-medium text-gray-800 dark:text-white/90">{{ tpl.name }}</td>
                                    <td class="px-5 py-3 text-sm text-gray-600 dark:text-gray-400">{{ tpl.records_count }}</td>
                                    <td class="px-5 py-3">
                                        <span
                                            v-if="tpl.is_default"
                                            class="inline-flex rounded-full bg-success-500/15 px-2.5 py-0.5 text-xs font-semibold text-success-600 dark:text-success-300"
                                        >
                                            {{ t('Default') }}
                                        </span>
                                    </td>
                                    <td class="px-5 py-3 text-right">
                                        <div class="flex items-center justify-end gap-2">
                                            <button
                                                v-if="!tpl.is_default"
                                                type="button"
                                                class="inline-flex h-8 items-center gap-1.5 rounded-md border border-success-500/30 px-2.5 text-xs font-medium text-success-600 hover:bg-success-500/10 dark:text-success-300"
                                                :title="t('Set as Default')"
                                                :disabled="actionLoading"
                                                @click="setDefault(tpl)"
                                            >
                                                <i class="bx bx-check text-sm"></i>
                                                {{ t('Set Default') }}
                                            </button>
                                            <button
                                                type="button"
                                                class="inline-flex h-8 w-8 items-center justify-center rounded-md border border-brand-500/30 text-brand-600 hover:bg-brand-500/10 dark:text-brand-300"
                                                :title="t('Edit')"
                                                :disabled="actionLoading"
                                                @click="openEditModal(tpl)"
                                            >
                                                <i class="bx bx-edit-alt text-sm"></i>
                                            </button>
                                            <button
                                                type="button"
                                                class="inline-flex h-8 w-8 items-center justify-center rounded-md border border-error-500/30 text-error-600 hover:bg-error-500/10 dark:text-error-400"
                                                :title="t('Delete')"
                                                :disabled="actionLoading"
                                                @click="openDeleteModal(tpl)"
                                            >
                                                <i class="bx bx-trash text-sm"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Create / Edit Modal -->
                <div
                    v-if="showFormModal"
                    class="fixed inset-0 z-1200000 flex items-center justify-center bg-black/60 p-4 backdrop-blur-sm"
                >
                    <div class="w-full max-w-4xl rounded-2xl border border-gray-200 bg-white shadow-theme-xl dark:border-gray-800 dark:bg-gray-900" style="max-height: 90vh; display: flex; flex-direction: column;">
                        <div class="flex shrink-0 items-center justify-between border-b border-gray-200 px-5 py-4 dark:border-gray-800 md:px-6">
                            <h4 class="text-base font-semibold text-gray-800 dark:text-white/90">
                                {{ editingTemplateId ? t('Edit Template') : t('Create Template') }}
                            </h4>
                            <button
                                type="button"
                                class="text-2xl leading-none text-gray-400 hover:text-gray-700 dark:hover:text-gray-200"
                                @click="closeFormModal"
                            >
                                &times;
                            </button>
                        </div>

                        <form class="flex-1 overflow-y-auto px-5 py-5 md:px-6" @submit.prevent="saveTemplate">
                            <div class="mb-5">
                                <FormField :label="t('Template Name')" :error="formErrors.name" required>
                                    <input v-model="templateName" type="text" class="form-input" :placeholder="t('e.g. Default Web Hosting')" />
                                </FormField>
                            </div>

                            <!-- Placeholder Reference -->
                            <div class="mb-5 rounded-lg border border-blue-200 bg-blue-50 p-3 dark:border-blue-800 dark:bg-blue-900/20">
                                <p class="mb-1 text-xs font-semibold text-blue-800 dark:text-blue-300">{{ t('Available Placeholders') }}</p>
                                <p class="text-xs text-blue-700 dark:text-blue-400">
                                    <code class="rounded bg-blue-100 px-1 dark:bg-blue-900">{domain}</code>,
                                    <code class="rounded bg-blue-100 px-1 dark:bg-blue-900">{ip}</code>,
                                    <code class="rounded bg-blue-100 px-1 dark:bg-blue-900">{ns1}</code>,
                                    <code class="rounded bg-blue-100 px-1 dark:bg-blue-900">{ns2}</code>,
                                    <code class="rounded bg-blue-100 px-1 dark:bg-blue-900">{mail_server}</code>
                                </p>
                            </div>

                            <!-- Records Table -->
                            <div class="mb-4">
                                <h5 class="mb-3 text-sm font-semibold text-gray-800 dark:text-white/90">{{ t('Template Records') }}</h5>
                                <div class="overflow-x-auto rounded-lg border border-gray-200 dark:border-gray-700">
                                    <table class="w-full">
                                        <thead>
                                            <tr class="border-b border-gray-200 bg-gray-50 dark:border-gray-700 dark:bg-gray-800/50">
                                                <th class="px-3 py-2 text-left text-xs font-medium uppercase text-gray-500">{{ t('Type') }}</th>
                                                <th class="px-3 py-2 text-left text-xs font-medium uppercase text-gray-500">{{ t('Name') }}</th>
                                                <th class="px-3 py-2 text-left text-xs font-medium uppercase text-gray-500">{{ t('Content') }}</th>
                                                <th class="w-24 px-3 py-2 text-left text-xs font-medium uppercase text-gray-500">{{ t('TTL') }}</th>
                                                <th class="w-24 px-3 py-2 text-left text-xs font-medium uppercase text-gray-500">{{ t('Priority') }}</th>
                                                <th class="w-12 px-3 py-2"></th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <tr v-if="templateRecords.length === 0" class="border-t border-gray-200 dark:border-gray-700">
                                                <td colspan="6" class="px-3 py-6 text-center text-sm text-gray-500 dark:text-gray-400">
                                                    {{ t('No records added yet. Click "Add Record" below.') }}
                                                </td>
                                            </tr>
                                            <tr
                                                v-for="(record, index) in templateRecords"
                                                :key="index"
                                                class="border-t border-gray-200 dark:border-gray-700"
                                            >
                                                <td class="px-3 py-2">
                                                    <select v-model="record.type" class="form-input-sm">
                                                        <option v-for="type in dnsTypes" :key="type" :value="type">{{ type }}</option>
                                                    </select>
                                                </td>
                                                <td class="px-3 py-2">
                                                    <input v-model="record.name" type="text" class="form-input-sm" placeholder="@" />
                                                </td>
                                                <td class="px-3 py-2">
                                                    <input v-model="record.content" type="text" class="form-input-sm" :placeholder="t('Value')" />
                                                </td>
                                                <td class="px-3 py-2">
                                                    <input v-model.number="record.ttl" type="number" min="60" class="form-input-sm" />
                                                </td>
                                                <td class="px-3 py-2">
                                                    <input
                                                        v-if="showPriority(record.type)"
                                                        v-model.number="record.priority"
                                                        type="number"
                                                        min="0"
                                                        class="form-input-sm"
                                                        placeholder="10"
                                                    />
                                                    <span v-else class="text-xs text-gray-400">&mdash;</span>
                                                </td>
                                                <td class="px-3 py-2 text-center">
                                                    <button
                                                        type="button"
                                                        class="inline-flex h-7 w-7 items-center justify-center rounded-md text-error-500 hover:bg-error-500/10"
                                                        :title="t('Remove')"
                                                        @click="removeRecord(index)"
                                                    >
                                                        <i class="bx bx-x text-lg"></i>
                                                    </button>
                                                </td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                                <p v-if="formErrors.records" class="mt-1 text-sm text-error-500">{{ formErrors.records }}</p>
                            </div>

                            <button
                                type="button"
                                class="mb-5 inline-flex items-center gap-1.5 rounded-lg border border-dashed border-gray-300 px-3 py-2 text-sm font-medium text-gray-600 hover:border-brand-500 hover:text-brand-600 dark:border-gray-700 dark:text-gray-400 dark:hover:border-brand-500 dark:hover:text-brand-400"
                                @click="addRecord"
                            >
                                <i class="bx bx-plus text-base"></i>
                                {{ t('Add Record') }}
                            </button>

                            <div class="flex items-center justify-end gap-2 border-t border-gray-200 pt-4 dark:border-gray-800">
                                <button
                                    type="button"
                                    class="rounded-lg border border-gray-300 px-4 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-400 dark:hover:bg-gray-800"
                                    @click="closeFormModal"
                                >
                                    {{ t('Cancel') }}
                                </button>
                                <button
                                    type="submit"
                                    :disabled="saving"
                                    class="inline-flex items-center gap-2 rounded-lg bg-brand-500 px-4 py-2.5 text-sm font-medium text-white hover:bg-brand-600 disabled:opacity-50"
                                >
                                    <i v-if="saving" class="bx bx-loader-alt animate-spin text-base"></i>
                                    {{ saving ? t('Saving...') : (editingTemplateId ? t('Update Template') : t('Create Template')) }}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Delete Confirmation Modal -->
                <div
                    v-if="showDeleteModal && deletingTemplate"
                    class="fixed inset-0 z-1200000 flex items-center justify-center bg-black/60 p-4 backdrop-blur-sm"
                >
                    <div class="w-full max-w-lg rounded-2xl border border-gray-200 bg-white shadow-theme-xl dark:border-gray-800 dark:bg-gray-900">
                        <div class="flex items-center justify-between border-b border-gray-200 px-5 py-4 dark:border-gray-800">
                            <h4 class="text-base font-semibold text-gray-800 dark:text-white/90">{{ t('Delete Template') }}</h4>
                            <button
                                type="button"
                                class="text-2xl leading-none text-gray-400 hover:text-gray-700 dark:hover:text-gray-200"
                                @click="closeDeleteModal"
                            >
                                &times;
                            </button>
                        </div>
                        <div class="px-5 py-5">
                            <p class="text-sm text-gray-700 dark:text-gray-300">
                                {{ t('Are you sure you want to delete the template ":name"?', { name: deletingTemplate.name }) }}
                            </p>
                            <div class="mt-5 flex items-center justify-end gap-2">
                                <button
                                    type="button"
                                    class="rounded-lg border border-gray-300 px-4 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-400 dark:hover:bg-gray-800"
                                    @click="closeDeleteModal"
                                >
                                    {{ t('Cancel') }}
                                </button>
                                <button
                                    type="button"
                                    :disabled="deleting"
                                    class="inline-flex items-center gap-2 rounded-lg bg-error-500 px-4 py-2.5 text-sm font-medium text-white hover:bg-error-600 disabled:opacity-50"
                                    @click="deleteTemplate"
                                >
                                    <i v-if="deleting" class="bx bx-loader-alt animate-spin text-base"></i>
                                    {{ deleting ? t('Deleting...') : t('Delete') }}
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </AdminLayout>
        </SidebarProvider>
    </ThemeProvider>
</template>

<script setup lang="ts">
import { computed, reactive, ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import axios from 'axios';
import ThemeProvider from '@/Components/Layout/ThemeProvider.vue';
import SidebarProvider from '@/Components/Layout/SidebarProvider.vue';
import AdminLayout from '@/Components/Layout/AdminLayout.vue';
import PageBreadcrumb from '@/Components/Common/PageBreadcrumb.vue';
import FormField from '@/Components/UI/FormField.vue';
import Toast from '@/Components/UI/Toast.vue';
import { useToast } from '@/Composables/useToast';
import { useI18n } from '@/Composables/useI18n';

interface TemplateRecord {
    type: string;
    name: string;
    content: string;
    ttl: number;
    priority: number | null;
}

interface Template {
    id: number;
    name: string;
    is_default: boolean;
    records_count: number;
}

const props = defineProps<{
    templates: Template[];
}>();

const { t } = useI18n();
const { addToast } = useToast();

const dnsTypes = ['A', 'AAAA', 'CNAME', 'MX', 'TXT', 'SRV', 'CAA', 'NS', 'SOA'];
const priorityTypes = ['MX', 'SRV'];

const breadcrumbs = computed(() => [
    { label: t('Settings') },
    { label: t('DNS Templates') },
]);

const localTemplates = reactive<Template[]>(props.templates.map((tpl) => ({ ...tpl })));

const showFormModal = ref(false);
const showDeleteModal = ref(false);
const editingTemplateId = ref<number | null>(null);
const deletingTemplate = ref<Template | null>(null);
const saving = ref(false);
const deleting = ref(false);
const actionLoading = ref(false);
const formErrors = ref<Record<string, string>>({});

const templateName = ref('');
const templateRecords = ref<TemplateRecord[]>([]);

const showPriority = (type: string): boolean => priorityTypes.includes(type);

const defaultRecord = (): TemplateRecord => ({
    type: 'A',
    name: '@',
    content: '',
    ttl: 3600,
    priority: null,
});

const addRecord = (): void => {
    templateRecords.value.push(defaultRecord());
};

const removeRecord = (index: number): void => {
    templateRecords.value.splice(index, 1);
};

const resetForm = (): void => {
    templateName.value = '';
    templateRecords.value = [];
    editingTemplateId.value = null;
    formErrors.value = {};
};

const openCreateModal = (): void => {
    resetForm();
    showFormModal.value = true;
};

const openEditModal = async (tpl: Template): Promise<void> => {
    resetForm();
    editingTemplateId.value = tpl.id;
    actionLoading.value = true;

    try {
        const response = await axios.get(route('settings.dns-templates.show', { dnsTemplate: tpl.id }));
        const data = response.data;
        templateName.value = data.name;
        templateRecords.value = (data.records ?? []).map((r: any) => ({
            type: r.type ?? 'A',
            name: r.name ?? '@',
            content: r.content ?? '',
            ttl: r.ttl ?? 3600,
            priority: r.priority ?? null,
        }));
        showFormModal.value = true;
    } catch (error: any) {
        addToast('error', error.response?.data?.message ?? t('Failed to load template.'));
    } finally {
        actionLoading.value = false;
    }
};

const closeFormModal = (): void => {
    showFormModal.value = false;
    resetForm();
};

const openDeleteModal = (tpl: Template): void => {
    deletingTemplate.value = tpl;
    showDeleteModal.value = true;
};

const closeDeleteModal = (): void => {
    showDeleteModal.value = false;
    deletingTemplate.value = null;
};

const saveTemplate = async (): Promise<void> => {
    saving.value = true;
    formErrors.value = {};

    const payload = {
        name: templateName.value,
        records: templateRecords.value,
    };

    try {
        if (editingTemplateId.value) {
            const response = await axios.put(
                route('settings.dns-templates.update', { dnsTemplate: editingTemplateId.value }),
                payload,
            );
            const index = localTemplates.findIndex((t) => t.id === editingTemplateId.value);
            if (index !== -1) {
                localTemplates[index].name = response.data.name ?? templateName.value;
                localTemplates[index].records_count = templateRecords.value.length;
            }
            addToast('success', response.data.message ?? t('Template updated.'));
        } else {
            const response = await axios.post(route('settings.dns-templates.store'), payload);
            localTemplates.push({
                id: response.data.id,
                name: response.data.name ?? templateName.value,
                is_default: false,
                records_count: templateRecords.value.length,
            });
            addToast('success', response.data.message ?? t('Template created.'));
        }

        closeFormModal();
    } catch (error: any) {
        if (error.response?.status === 422 && error.response?.data?.errors) {
            const errors = error.response.data.errors;
            Object.entries(errors).forEach(([field, messages]) => {
                formErrors.value[field] = Array.isArray(messages) ? messages[0] : (messages as string);
            });
        }

        addToast('error', error.response?.data?.message ?? t('Failed to save template.'));
    } finally {
        saving.value = false;
    }
};

const deleteTemplate = async (): Promise<void> => {
    if (!deletingTemplate.value) return;
    deleting.value = true;

    try {
        await axios.delete(route('settings.dns-templates.destroy', { dnsTemplate: deletingTemplate.value.id }));
        const index = localTemplates.findIndex((t) => t.id === deletingTemplate.value!.id);
        if (index !== -1) {
            localTemplates.splice(index, 1);
        }
        addToast('success', t('Template deleted.'));
        closeDeleteModal();
    } catch (error: any) {
        addToast('error', error.response?.data?.message ?? t('Failed to delete template.'));
    } finally {
        deleting.value = false;
    }
};

const setDefault = async (tpl: Template): Promise<void> => {
    actionLoading.value = true;

    try {
        await axios.post(route('settings.dns-templates.set-default', { dnsTemplate: tpl.id }));
        localTemplates.forEach((t) => {
            t.is_default = t.id === tpl.id;
        });
        addToast('success', t('Default template updated.'));
    } catch (error: any) {
        addToast('error', error.response?.data?.message ?? t('Failed to set default template.'));
    } finally {
        actionLoading.value = false;
    }
};
</script>

<style scoped>
@reference "../../../css/app.css";

.form-input {
    @apply h-11 w-full rounded-lg border border-gray-300 bg-transparent px-4 py-2.5 text-sm text-gray-800 shadow-theme-xs placeholder:text-gray-400 focus:border-brand-300 focus:outline-hidden focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90 dark:placeholder:text-white/30 dark:focus:border-brand-800;
}

.form-input-sm {
    @apply h-9 w-full rounded-md border border-gray-300 bg-transparent px-2.5 py-1.5 text-sm text-gray-800 shadow-theme-xs placeholder:text-gray-400 focus:border-brand-300 focus:outline-hidden focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90 dark:placeholder:text-white/30 dark:focus:border-brand-800;
}
</style>
