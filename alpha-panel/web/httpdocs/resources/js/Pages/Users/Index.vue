<template>
    <Head :title="t('Users')" />
    <ThemeProvider>
        <SidebarProvider>
            <AdminLayout>
                <PageBreadcrumb :pageTitle="t('User Management')" />
                <Toast />

                <div class="rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/3">
                    <div class="flex flex-col gap-4 px-5 py-4 sm:flex-row sm:items-center sm:justify-between md:px-6">
                        <h3 class="text-lg font-semibold text-gray-800 dark:text-white/90">{{ t('Users') }}</h3>
                        <div class="flex items-center gap-3">
                            <input
                                v-model="searchInput"
                                @input="table.setSearch(searchInput)"
                                type="text"
                                :placeholder="t('Search users...')"
                                class="h-10 w-64 rounded-lg border border-gray-200 bg-transparent py-2 pl-4 pr-4 text-sm text-gray-800 shadow-theme-xs placeholder:text-gray-400 focus:border-brand-300 focus:outline-hidden focus:ring-3 focus:ring-brand-500/10 dark:border-gray-800 dark:bg-gray-900 dark:text-white/90"
                            />
                            <button
                                @click="showCreateModal = true"
                                class="inline-flex items-center gap-2 rounded-lg bg-brand-500 px-4 py-2.5 text-sm font-medium text-white shadow-theme-xs hover:bg-brand-600"
                            >
                                {{ t('Add User') }}
                            </button>
                        </div>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="w-full">
                            <thead>
                                <tr class="border-t border-gray-200 dark:border-gray-800">
                                    <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-500">{{ t('Name') }}</th>
                                    <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-500">{{ t('Username') }}</th>
                                    <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-500">{{ t('Email') }}</th>
                                    <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-500">{{ t('Role') }}</th>
                                    <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-500">{{ t('Domains') }}</th>
                                    <th class="px-5 py-3 text-right text-xs font-medium uppercase text-gray-500">{{ t('Actions') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-if="table.loading.value" class="border-t border-gray-200 dark:border-gray-800">
                                    <td colspan="6" class="px-5 py-8 text-center text-gray-500">{{ t('Loading...') }}</td>
                                </tr>
                                <tr v-for="user in table.data.value" :key="(user.id as number)" class="border-t border-gray-200 hover:bg-gray-50 dark:border-gray-800 dark:hover:bg-white/2">
                                    <td class="px-5 py-4 font-medium text-gray-800 dark:text-white/90">{{ user.name }}</td>
                                    <td class="px-5 py-4 text-sm text-gray-600 dark:text-gray-400">{{ user.username }}</td>
                                    <td class="px-5 py-4 text-sm text-gray-600 dark:text-gray-400">{{ user.email }}</td>
                                    <td class="px-5 py-4">
                                        <div class="flex flex-wrap gap-1">
                                            <span
                                                v-for="roleName in (user.roles as string[] || [])"
                                                :key="roleName"
                                                class="inline-flex rounded-full px-2 py-0.5 text-[10px] font-semibold"
                                                :class="roleName === 'Admin' ? 'bg-success-500/20 text-success-600 dark:text-success-300' : 'bg-brand-500/10 text-brand-600 dark:text-brand-400'"
                                            >
                                                {{ roleName }}
                                            </span>
                                            <span v-if="!user.roles || (user.roles as string[]).length === 0" class="text-xs text-gray-400">-</span>
                                        </div>
                                    </td>
                                    <td class="px-5 py-4 text-sm text-gray-600 dark:text-gray-400">{{ user.owned_domains_count }}</td>
                                    <td class="px-5 py-4 text-right">
                                        <div class="flex items-center justify-end gap-1.5">
                                            <button
                                                v-if="user.can_impersonate"
                                                @click="impersonate(user)"
                                                :title="t('Login as this user')"
                                                class="inline-flex items-center gap-1.5 rounded-lg border border-amber-300 bg-amber-50 px-2.5 py-1.5 text-xs font-medium text-amber-700 shadow-theme-xs transition hover:bg-amber-100 hover:text-amber-800 focus:outline-none focus:ring-2 focus:ring-amber-500/30 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-300 dark:hover:bg-amber-500/20"
                                            >
                                                <svg xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                                    <path fill-rule="evenodd" d="M10 9a3 3 0 1 0 0-6 3 3 0 0 0 0 6Zm-7 9a7 7 0 0 1 14 0H3Z" clip-rule="evenodd" />
                                                </svg>
                                                <span>{{ t('Login as') }}</span>
                                            </button>
                                            <button
                                                @click="editUser(user)"
                                                :title="t('Edit')"
                                                class="inline-flex items-center gap-1.5 rounded-lg border border-brand-200 bg-brand-50 px-2.5 py-1.5 text-xs font-medium text-brand-600 shadow-theme-xs transition hover:bg-brand-100 hover:text-brand-700 focus:outline-none focus:ring-2 focus:ring-brand-500/30 dark:border-brand-500/30 dark:bg-brand-500/10 dark:text-brand-300 dark:hover:bg-brand-500/20"
                                            >
                                                <svg xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                                    <path d="M2.695 14.763l-1.262 3.154a.5.5 0 0 0 .65.65l3.155-1.262a4 4 0 0 0 1.343-.885L17.5 5.5a2.121 2.121 0 0 0-3-3L3.58 13.42a4 4 0 0 0-.885 1.343Z" />
                                                </svg>
                                                <span>{{ t('Edit') }}</span>
                                            </button>
                                            <button
                                                @click="deleteUser(user)"
                                                :title="t('Delete')"
                                                class="inline-flex items-center gap-1.5 rounded-lg border border-error-200 bg-error-50 px-2.5 py-1.5 text-xs font-medium text-error-600 shadow-theme-xs transition hover:bg-error-100 hover:text-error-700 focus:outline-none focus:ring-2 focus:ring-error-500/30 dark:border-error-500/30 dark:bg-error-500/10 dark:text-error-300 dark:hover:bg-error-500/20"
                                            >
                                                <svg xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                                    <path fill-rule="evenodd" d="M8.75 1A2.75 2.75 0 0 0 6 3.75v.443c-.795.077-1.584.176-2.365.298a.75.75 0 1 0 .23 1.482l.149-.022.841 10.518A2.75 2.75 0 0 0 7.596 19h4.807a2.75 2.75 0 0 0 2.742-2.53l.841-10.52.149.023a.75.75 0 0 0 .23-1.482A41.03 41.03 0 0 0 14 4.193V3.75A2.75 2.75 0 0 0 11.25 1h-2.5ZM10 4c.84 0 1.673.025 2.5.075V3.75c0-.69-.56-1.25-1.25-1.25h-2.5c-.69 0-1.25.56-1.25 1.25v.325C8.327 4.025 9.16 4 10 4ZM8.58 7.72a.75.75 0 0 0-1.5.06l.3 7.5a.75.75 0 1 0 1.5-.06l-.3-7.5Zm4.34.06a.75.75 0 1 0-1.5-.06l-.3 7.5a.75.75 0 1 0 1.5.06l.3-7.5Z" clip-rule="evenodd" />
                                                </svg>
                                                <span>{{ t('Delete') }}</span>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div v-if="table.totalPages.value > 1" class="flex items-center justify-between border-t border-gray-200 px-5 py-4 dark:border-gray-800">
                        <p class="text-sm text-gray-500">
                            {{ table.recordsFiltered.value }} {{ t('total') }}
                        </p>
                        <div class="flex gap-2">
                            <button v-for="page in table.totalPages.value" :key="page" @click="table.setPage(page)" :class="['h-8 w-8 rounded-lg text-sm font-medium', page === table.currentPage.value ? 'bg-brand-500 text-white' : 'text-gray-700 hover:bg-gray-100 dark:text-gray-400']">
                                {{ page }}
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Create/Edit Modal -->
                <div v-if="showCreateModal || editingUser" class="fixed inset-0 z-99999 flex items-center justify-center bg-gray-900/50">
                    <div class="w-full max-w-md rounded-2xl border border-gray-200 bg-white p-6 shadow-theme-xl dark:border-gray-800 dark:bg-gray-900">
                        <h3 class="mb-4 text-lg font-semibold text-gray-800 dark:text-white/90">
                            {{ editingUser ? t('Edit User') : t('Create User') }}
                        </h3>
                        <form @submit.prevent="submitUser" class="space-y-4">
                            <div>
                                <label class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-400">{{ t('Name') }}</label>
                                <input v-model="userForm.name" type="text" class="form-input" />
                            </div>
                            <div>
                                <label class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-400">{{ t('Username') }}</label>
                                <input v-model="userForm.username" type="text" class="form-input" />
                            </div>
                            <div>
                                <label class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-400">{{ t('Email') }}</label>
                                <input v-model="userForm.email" type="email" class="form-input" />
                            </div>
                            <div>
                                <label class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-400">
                                    {{ t('Password') }} {{ editingUser ? t('(leave blank to keep)') : '' }}
                                </label>
                                <input v-model="userForm.password" type="password" class="form-input" />
                            </div>
                            <div>
                                <label class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-400">{{ t('Role') }}</label>
                                <select v-model="userForm.role" class="form-input">
                                    <option v-for="role in props.availableRoles" :key="role.id" :value="role.name">{{ role.name }}</option>
                                </select>
                            </div>
                            <label class="flex items-center gap-2">
                                <input v-model="userForm.admin" type="checkbox" class="h-4 w-4 rounded border-gray-300 text-brand-500" />
                                <span class="text-sm text-gray-700 dark:text-gray-400">{{ t('Super Admin') }}</span>
                            </label>
                            <div class="flex gap-3 pt-2">
                                <button type="submit" :disabled="submitting" class="rounded-lg bg-brand-500 px-4 py-2.5 text-sm font-medium text-white hover:bg-brand-600 disabled:opacity-50">
                                    {{ submitting ? t('Saving...') : t('Save') }}
                                </button>
                                <button type="button" @click="closeModal" class="rounded-lg border border-gray-300 px-4 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-400">
                                    {{ t('Cancel') }}
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
import { ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import axios from 'axios';
import ThemeProvider from '@/Components/Layout/ThemeProvider.vue';
import SidebarProvider from '@/Components/Layout/SidebarProvider.vue';
import AdminLayout from '@/Components/Layout/AdminLayout.vue';
import PageBreadcrumb from '@/Components/Common/PageBreadcrumb.vue';
import Toast from '@/Components/UI/Toast.vue';
import { useToast } from '@/Composables/useToast';
import { useDataTable } from '@/Composables/useDataTable';
import { useI18n } from '@/Composables/useI18n';

const props = defineProps<{
    availableRoles: Array<{ id: number; name: string }>;
}>();

const { addToast } = useToast();
const { t } = useI18n();
const searchInput = ref('');
const showCreateModal = ref(false);
const editingUser = ref<any>(null);
const submitting = ref(false);

const table = useDataTable({
    url: route('users.json'),
    columns: ['name', 'username', 'email', 'admin', 'owned_domains_count', 'created_at', 'actions'],
});

const userForm = ref({
    name: '',
    username: '',
    email: '',
    password: '',
    admin: false,
    role: 'Domain Manager',
});

const editUser = (user: any) => {
    editingUser.value = user;
    userForm.value = {
        name: user.name as string,
        username: user.username as string,
        email: user.email as string,
        password: '',
        admin: !!user.admin,
        role: (user.roles as string[])?.[0] ?? 'Domain Manager',
    };
};

const closeModal = () => {
    showCreateModal.value = false;
    editingUser.value = null;
    userForm.value = { name: '', username: '', email: '', password: '', admin: false, role: 'Domain Manager' };
};

const submitUser = async () => {
    submitting.value = true;
    try {
        if (editingUser.value) {
            await axios.put(editingUser.value.update_url, userForm.value);
            addToast('success', t('User updated successfully'));
        } else {
            await axios.post(route('users.store'), userForm.value);
            addToast('success', t('User created successfully'));
        }
        closeModal();
        table.fetch();
    } catch (e: any) {
        addToast('error', e.response?.data?.message || t('Failed to save user'));
    } finally {
        submitting.value = false;
    }
};

const impersonate = (user: any) => {
    if (!confirm(t('Login as :name?', { name: String(user.name) }))) return;
    router.post(user.impersonate_url as string);
};

const deleteUser = async (user: any) => {
    if (!confirm(t('Delete user :name? Their domains will be transferred to you.', { name: String(user.name) }))) return;
    try {
        await axios.delete(user.destroy_url);
        addToast('success', t('User deleted'));
        table.fetch();
    } catch (e: any) {
        addToast('error', e.response?.data?.message || t('Failed to delete user'));
    }
};
</script>

<style scoped>
@reference "../../../css/app.css";

.form-input {
    @apply h-11 w-full rounded-lg border border-gray-300 bg-transparent px-4 py-2.5 text-sm text-gray-800 shadow-theme-xs focus:border-brand-300 focus:outline-hidden focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90;
}
</style>
