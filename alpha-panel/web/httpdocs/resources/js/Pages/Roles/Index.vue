<template>
    <Head :title="t('Roles')" />
    <ThemeProvider>
        <SidebarProvider>
            <AdminLayout>
                <PageBreadcrumb :pageTitle="t('Role Management')" />
                <Toast />

                <div class="space-y-6">
                    <div class="flex items-center justify-between">
                        <h3 class="text-lg font-semibold text-gray-800 dark:text-white/90">{{ t('Roles') }}</h3>
                        <button
                            @click="openCreateModal"
                            class="inline-flex items-center gap-2 rounded-lg bg-brand-500 px-4 py-2.5 text-sm font-medium text-white shadow-theme-xs hover:bg-brand-600"
                        >
                            <i class="fa-solid fa-plus text-xs"></i>
                            {{ t('Create Role') }}
                        </button>
                    </div>

                    <div class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
                        <div
                            v-for="role in roleList"
                            :key="role.id"
                            class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]"
                        >
                            <div class="mb-3 flex items-center justify-between">
                                <div class="flex items-center gap-2">
                                    <h4 class="font-semibold text-gray-800 dark:text-white/90">{{ role.name }}</h4>
                                    <span
                                        v-if="role.is_default"
                                        class="inline-flex rounded-full bg-brand-500/10 px-2 py-0.5 text-[10px] font-semibold text-brand-600 dark:text-brand-400"
                                    >
                                        {{ t('Default') }}
                                    </span>
                                </div>
                                <div class="flex items-center gap-1">
                                    <button
                                        @click="openEditModal(role)"
                                        class="inline-flex h-8 w-8 items-center justify-center rounded-lg text-gray-500 hover:bg-gray-100 dark:text-gray-400 dark:hover:bg-white/5"
                                        v-tooltip="t('Edit')"
                                    >
                                        <i class="fa-solid fa-pen text-xs"></i>
                                    </button>
                                    <button
                                        v-if="!role.is_default"
                                        @click="deleteRole(role)"
                                        :disabled="role.users_count > 0"
                                        class="inline-flex h-8 w-8 items-center justify-center rounded-lg text-error-500 hover:bg-error-500/10 disabled:cursor-not-allowed disabled:opacity-40"
                                        v-tooltip="role.users_count > 0 ? t('Cannot delete: has assigned users') : t('Delete')"
                                    >
                                        <i class="fa-solid fa-trash text-xs"></i>
                                    </button>
                                </div>
                            </div>
                            <div class="mb-3 flex items-center gap-4 text-xs text-gray-500 dark:text-gray-400">
                                <span><i class="fa-solid fa-users mr-1"></i> {{ role.users_count }} {{ t('users') }}</span>
                                <span><i class="fa-solid fa-key mr-1"></i> {{ role.permissions.length }} {{ t('permissions') }}</span>
                            </div>
                            <div class="flex flex-wrap gap-1">
                                <span
                                    v-for="perm in role.permissions.slice(0, 5)"
                                    :key="perm"
                                    class="inline-flex rounded bg-gray-100 px-1.5 py-0.5 text-[10px] text-gray-600 dark:bg-gray-800 dark:text-gray-400"
                                >
                                    {{ perm }}
                                </span>
                                <span
                                    v-if="role.permissions.length > 5"
                                    class="inline-flex rounded bg-gray-100 px-1.5 py-0.5 text-[10px] text-gray-600 dark:bg-gray-800 dark:text-gray-400"
                                >
                                    +{{ role.permissions.length - 5 }} {{ t('more') }}
                                </span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Create/Edit Modal -->
                <div
                    v-if="showModal"
                    class="fixed inset-0 z-99999 flex items-center justify-center bg-gray-900/50 p-4"
                    @click.self="closeModal"
                >
                    <div class="flex max-h-[90vh] w-full max-w-3xl flex-col rounded-2xl border border-gray-200 bg-white shadow-theme-xl dark:border-gray-800 dark:bg-gray-900">
                        <div class="flex items-center justify-between border-b border-gray-200 px-6 py-4 dark:border-gray-800">
                            <h3 class="text-lg font-semibold text-gray-800 dark:text-white/90">
                                {{ editingRole ? t('Edit Role') : t('Create Role') }}
                            </h3>
                            <button @click="closeModal" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300">
                                <i class="fa-solid fa-xmark"></i>
                            </button>
                        </div>

                        <div class="min-h-0 flex-1 overflow-y-auto px-6 py-4">
                            <div class="mb-5">
                                <label class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-400">{{ t('Role Name') }}</label>
                                <input
                                    v-model="form.name"
                                    type="text"
                                    class="form-input"
                                    :placeholder="t('e.g. Content Editor')"
                                    :disabled="editingRole?.is_default"
                                />
                            </div>

                            <div class="space-y-5">
                                <div v-for="group in props.permissionGroups" :key="group.label">
                                    <div class="mb-2 flex items-center justify-between">
                                        <h5 class="text-sm font-semibold text-gray-700 dark:text-gray-300">{{ t(group.label) }}</h5>
                                        <label class="flex items-center gap-1.5 text-xs text-gray-500 dark:text-gray-400">
                                            <input
                                                type="checkbox"
                                                :checked="isGroupFullySelected(group.permissions)"
                                                :indeterminate="isGroupPartiallySelected(group.permissions)"
                                                @change="toggleGroup(group.permissions, ($event.target as HTMLInputElement).checked)"
                                                class="h-3.5 w-3.5 rounded border-gray-300 text-brand-500 focus:ring-brand-500"
                                            />
                                            {{ t('Select All') }}
                                        </label>
                                    </div>
                                    <div class="grid grid-cols-1 gap-2 sm:grid-cols-2">
                                        <label
                                            v-for="perm in group.permissions"
                                            :key="perm.name"
                                            class="flex items-center gap-2 rounded-lg border border-gray-100 px-3 py-2 text-sm transition hover:bg-gray-50 dark:border-gray-800 dark:hover:bg-white/[0.02]"
                                            :class="{ 'border-brand-200 bg-brand-500/5 dark:border-brand-900': form.permissions.includes(perm.name) }"
                                        >
                                            <input
                                                type="checkbox"
                                                :value="perm.name"
                                                v-model="form.permissions"
                                                class="h-4 w-4 shrink-0 rounded border-gray-300 text-brand-500 focus:ring-brand-500"
                                            />
                                            <div class="min-w-0">
                                                <span class="block text-gray-700 dark:text-gray-300">{{ formatPermissionLabel(perm.name) }}</span>
                                                <span class="block text-[11px] leading-tight text-gray-400 dark:text-gray-500">{{ t(perm.description) }}</span>
                                            </div>
                                        </label>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="flex items-center justify-between border-t border-gray-200 px-6 py-4 dark:border-gray-800">
                            <span class="text-xs text-gray-500">
                                {{ form.permissions.length }} {{ t('permissions selected') }}
                            </span>
                            <div class="flex gap-3">
                                <button
                                    type="button"
                                    @click="closeModal"
                                    class="rounded-lg border border-gray-300 px-4 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-400"
                                >
                                    {{ t('Cancel') }}
                                </button>
                                <button
                                    type="button"
                                    @click="submitRole"
                                    :disabled="submitting || !form.name.trim() || form.permissions.length === 0"
                                    class="rounded-lg bg-brand-500 px-4 py-2.5 text-sm font-medium text-white hover:bg-brand-600 disabled:opacity-50"
                                >
                                    {{ submitting ? t('Saving...') : t('Save') }}
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
import { ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import axios from 'axios';
import ThemeProvider from '@/Components/Layout/ThemeProvider.vue';
import SidebarProvider from '@/Components/Layout/SidebarProvider.vue';
import AdminLayout from '@/Components/Layout/AdminLayout.vue';
import PageBreadcrumb from '@/Components/Common/PageBreadcrumb.vue';
import Toast from '@/Components/UI/Toast.vue';
import { useToast } from '@/Composables/useToast';
import { useI18n } from '@/Composables/useI18n';

interface PermissionEntry {
    name: string;
    description: string;
}

interface PermissionGroup {
    label: string;
    permissions: PermissionEntry[];
}

interface Role {
    id: number;
    name: string;
    permissions: string[];
    users_count: number;
    is_default: boolean;
}

const props = defineProps<{
    roles: Role[];
    permissionGroups: PermissionGroup[];
}>();

const { addToast } = useToast();
const { t } = useI18n();

const roleList = ref<Role[]>([...props.roles]);
const showModal = ref(false);
const editingRole = ref<Role | null>(null);
const submitting = ref(false);

const form = ref({
    name: '',
    permissions: [] as string[],
});

const formatPermissionLabel = (perm: string): string => {
    const parts = perm.split('.');
    parts.shift();
    return parts
        .join(' ')
        .replace(/[-_]/g, ' ')
        .replace(/\b\w/g, (c) => c.toUpperCase());
};

const isGroupFullySelected = (perms: PermissionEntry[]): boolean => {
    return perms.every((p) => form.value.permissions.includes(p.name));
};

const isGroupPartiallySelected = (perms: PermissionEntry[]): boolean => {
    const selected = perms.filter((p) => form.value.permissions.includes(p.name)).length;
    return selected > 0 && selected < perms.length;
};

const toggleGroup = (perms: PermissionEntry[], checked: boolean) => {
    const names = perms.map((p) => p.name);
    if (checked) {
        const toAdd = names.filter((n) => !form.value.permissions.includes(n));
        form.value.permissions.push(...toAdd);
    } else {
        form.value.permissions = form.value.permissions.filter((p) => !names.includes(p));
    }
};

const openCreateModal = () => {
    editingRole.value = null;
    form.value = { name: '', permissions: [] };
    showModal.value = true;
};

const openEditModal = (role: Role) => {
    editingRole.value = role;
    form.value = { name: role.name, permissions: [...role.permissions] };
    showModal.value = true;
};

const closeModal = () => {
    showModal.value = false;
    editingRole.value = null;
    form.value = { name: '', permissions: [] };
};

const submitRole = async () => {
    submitting.value = true;
    try {
        if (editingRole.value) {
            const { data } = await axios.put(route('roles.update', editingRole.value.id), form.value);
            const idx = roleList.value.findIndex((r) => r.id === editingRole.value!.id);
            if (idx !== -1) {
                roleList.value[idx] = data.role;
            }
            addToast('success', t('Role updated successfully.'));
        } else {
            const { data } = await axios.post(route('roles.store'), form.value);
            roleList.value.push(data.role);
            addToast('success', t('Role created successfully.'));
        }
        closeModal();
    } catch (e: any) {
        const message = e.response?.data?.message || t('Failed to save role.');
        addToast('error', message);
    } finally {
        submitting.value = false;
    }
};

const deleteRole = async (role: Role) => {
    if (!confirm(t('Delete role ":name"?', { name: role.name }))) {
        return;
    }

    try {
        await axios.delete(route('roles.destroy', role.id));
        roleList.value = roleList.value.filter((r) => r.id !== role.id);
        addToast('success', t('Role deleted successfully.'));
    } catch (e: any) {
        addToast('error', e.response?.data?.message || t('Failed to delete role.'));
    }
};
</script>

<style scoped>
@reference "../../../css/app.css";

.form-input {
    @apply h-11 w-full rounded-lg border border-gray-300 bg-transparent px-4 py-2.5 text-sm text-gray-800 shadow-theme-xs focus:border-brand-300 focus:outline-hidden focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90;
}
</style>
