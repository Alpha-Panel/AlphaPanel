<template>
    <Head :title="`${t('Databases')} - ${domain.fqdn}`" />
    <ThemeProvider>
        <SidebarProvider>
            <AdminLayout>
                <PageBreadcrumb
                    :pageTitle="t('Databases')"
                    :items="breadcrumbs"
                    :backHref="route('domains.show', domain.id)"
                />
                <Toast />

                <div class="space-y-4 md:space-y-6">
                    <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/3 md:p-6">
                        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                            <h3 class="flex items-center gap-2 text-lg font-semibold text-gray-800 dark:text-white/90">
                                <i class="bx bx-data text-xl text-brand-500"></i>
                                {{ t('Databases') }}
                            </h3>
                            <button
                                type="button"
                                class="inline-flex items-center gap-2 rounded-lg bg-brand-500 px-4 py-2.5 text-sm font-medium text-white shadow-theme-xs hover:bg-brand-600"
                                @click="openCreateDatabaseModal"
                            >
                                <i class="bx bx-plus text-base"></i>
                                {{ t('Create Database') }}
                            </button>
                        </div>
                    </div>

                    <div v-if="dbList.length === 0" class="rounded-2xl border border-gray-200 bg-white p-8 text-center dark:border-gray-800 dark:bg-white/3">
                        <i class="bx bx-data text-3xl text-gray-400"></i>
                        <p class="mt-2 text-gray-500 dark:text-gray-400">{{ t('No databases yet. Create one above.') }}</p>
                    </div>

                    <div v-else class="grid grid-cols-1 gap-4 xl:grid-cols-2">
                        <div
                            v-for="db in dbList"
                            :key="db.id"
                            class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/3 md:p-6"
                        >
                            <div class="mb-3 flex items-start justify-between gap-3">
                                <div>
                                    <h4 class="flex items-center gap-2 font-semibold text-gray-800 dark:text-white/90">
                                        <i class="bx bx-data text-lg text-brand-500"></i>
                                        {{ db.db_name }}
                                    </h4>
                                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                        {{ t('Created') }}: {{ formatDate(db.created_at) }}
                                        <span class="mx-1">•</span>
                                        {{ db.database_users.length }} {{ t('user') }}{{ db.database_users.length === 1 ? '' : 's' }}
                                    </p>
                                </div>
                                <div class="flex items-center gap-1.5">
                                    <a
                                        v-if="hasDatabaseUser(db)"
                                        :href="route('pma.database.sso', { domain: domain.id, database: db.id })"
                                        target="_blank"
                                        rel="noopener"
                                        class="inline-flex h-8 w-8 items-center justify-center rounded-lg border border-blue-light-500/40 text-blue-light-600 hover:bg-blue-light-500/10 dark:text-blue-light-300"
                                        :title="t('Open in phpMyAdmin')"
                                    >
                                        <i class="lni lni-mysql text-sm"></i>
                                    </a>
                                    <button
                                        v-else
                                        type="button"
                                        disabled
                                        class="inline-flex h-8 w-8 cursor-not-allowed items-center justify-center rounded-lg border border-gray-300 text-gray-400 dark:border-gray-700"
                                        :title="t('No database user available')"
                                    >
                                        <i class="lni lni-mysql text-sm"></i>
                                    </button>
                                    <button
                                        type="button"
                                        @click="toggleUserForm(db.id)"
                                        class="inline-flex h-8 w-8 items-center justify-center rounded-lg border border-brand-500/40 text-brand-600 hover:bg-brand-500/10 dark:text-brand-300"
                                        :title="t('Add User')"
                                    >
                                        <i class="bx bx-user-plus text-base"></i>
                                    </button>
                                    <button
                                        type="button"
                                        @click="deleteDatabase(db)"
                                        class="inline-flex h-8 w-8 items-center justify-center rounded-lg border border-error-500/40 text-error-500 hover:bg-error-500/10"
                                        :title="t('Delete Database')"
                                    >
                                        <i class="bx bx-trash text-base"></i>
                                    </button>
                                </div>
                            </div>

                            <div class="overflow-hidden rounded-xl border border-gray-200 dark:border-gray-700">
                                <table class="w-full text-sm">
                                    <thead class="bg-gray-50 dark:bg-gray-800/40">
                                        <tr class="text-left text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                            <th class="px-3 py-2">{{ t('User') }}</th>
                                            <th class="px-3 py-2 text-right">{{ t('Actions') }}</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <template v-if="db.database_users.length > 0">
                                            <template v-for="user in db.database_users" :key="`entry-${user.id}`">
                                                <tr class="border-t border-gray-100 dark:border-gray-800">
                                                    <td class="px-3 py-2 text-gray-700 dark:text-gray-300">
                                                        <span class="inline-flex items-center gap-1.5">
                                                            <i class="bx bx-user text-gray-500 dark:text-gray-400"></i>
                                                            {{ user.db_user }}
                                                        </span>
                                                    </td>
                                                    <td class="px-3 py-2">
                                                        <div class="flex justify-end gap-1.5">
                                                            <button
                                                                type="button"
                                                                class="inline-flex h-7 w-7 items-center justify-center rounded border border-warning-500/40 text-warning-600 hover:bg-warning-500/10 dark:text-warning-300"
                                                                :title="t('Change Password')"
                                                                @click="togglePasswordForm(user.id)"
                                                            >
                                                                <i class="bx bx-key text-sm"></i>
                                                            </button>
                                                            <button
                                                                type="button"
                                                                class="inline-flex h-7 w-7 items-center justify-center rounded border border-error-500/40 text-error-500 hover:bg-error-500/10"
                                                                :title="t('Delete User')"
                                                                @click="deleteUser(user)"
                                                            >
                                                                <i class="bx bx-trash text-sm"></i>
                                                            </button>
                                                        </div>
                                                    </td>
                                                </tr>
                                                <tr
                                                    v-if="passwordForms[user.id]?.open"
                                                    class="border-t border-gray-100 bg-gray-50/60 dark:border-gray-800 dark:bg-gray-800/30"
                                                >
                                                    <td colspan="2" class="px-3 py-3">
                                                        <form @submit.prevent="updateUserPassword(user)" class="grid grid-cols-1 gap-2 md:grid-cols-3">
                                                            <div>
                                                                <label class="mb-1 block text-xs font-medium text-gray-500">{{ t('New Password') }}</label>
                                                                <div class="relative">
                                                                    <input
                                                                        v-model="passwordForms[user.id].db_password"
                                                                        :type="passwordForms[user.id].show_password ? 'text' : 'password'"
                                                                        class="form-input pr-20"
                                                                        :placeholder="t('Min 8 chars')"
                                                                    />
                                                                    <div class="absolute inset-y-0 right-1 flex items-center gap-1">
                                                                        <button
                                                                            type="button"
                                                                            class="password-icon-btn"
                                                                            :title="t('Show/Hide')"
                                                                            @click="passwordForms[user.id].show_password = !passwordForms[user.id].show_password"
                                                                        >
                                                                            <i :class="passwordForms[user.id].show_password ? 'bx bx-show' : 'bx bx-hide'"></i>
                                                                        </button>
                                                                        <button
                                                                            type="button"
                                                                            class="password-icon-btn"
                                                                            :disabled="passwordForms[user.id].db_password === ''"
                                                                            :title="t('Copy')"
                                                                            @click="copyPasswordForUser(user.id)"
                                                                        >
                                                                            <i :class="passwordForms[user.id].copied_password ? 'bx bx-check' : 'bx bx-copy'"></i>
                                                                        </button>
                                                                    </div>
                                                                </div>
                                                            </div>
                                                            <div>
                                                                <label class="mb-1 block text-xs font-medium text-gray-500">{{ t('Confirm Password') }}</label>
                                                                <input
                                                                    v-model="passwordForms[user.id].db_password_confirmation"
                                                                    :type="passwordForms[user.id].show_password ? 'text' : 'password'"
                                                                    class="form-input"
                                                                    :placeholder="t('Repeat password')"
                                                                />
                                                            </div>
                                                            <div class="flex items-end gap-2">
                                                                <button
                                                                    type="button"
                                                                    class="inline-flex h-10 items-center gap-1.5 rounded-lg border border-gray-300 px-3 text-sm text-gray-700 hover:bg-gray-100 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-gray-800"
                                                                    @click="generatePasswordForUser(user.id)"
                                                                >
                                                                    <i class="bx bx-shuffle"></i>
                                                                    {{ t('Generate') }}
                                                                </button>
                                                                <button
                                                                    type="submit"
                                                                    :disabled="passwordForms[user.id].submitting"
                                                                    class="inline-flex h-10 items-center gap-1.5 rounded-lg bg-warning-500 px-4 text-sm font-medium text-white hover:bg-warning-600 disabled:opacity-50"
                                                                >
                                                                    <i class="bx bx-save"></i>
                                                                    {{ passwordForms[user.id].submitting ? '...' : t('Update') }}
                                                                </button>
                                                            </div>
                                                        </form>
                                                    </td>
                                                </tr>
                                            </template>
                                        </template>
                                        <tr v-else class="border-t border-gray-100 dark:border-gray-800">
                                            <td colspan="2" class="px-3 py-4 text-center text-sm text-gray-500 dark:text-gray-400">
                                                {{ t('No users') }}
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>

                            <div v-if="userForms[db.id]?.open" class="mt-3 rounded-xl border border-gray-200 p-3 dark:border-gray-700">
                                <h5 class="mb-3 flex items-center gap-1.5 text-sm font-medium text-gray-700 dark:text-gray-300">
                                    <i class="bx bx-user-plus text-brand-500"></i>
                                    {{ t('Add Database User') }}
                                </h5>
                                <form @submit.prevent="addUser(db)" class="grid grid-cols-1 gap-2 md:grid-cols-3">
                                    <div>
                                        <label class="mb-1 block text-xs font-medium text-gray-500">{{ t('Username') }}</label>
                                        <input
                                            v-model="userForms[db.id].db_user"
                                            type="text"
                                            class="form-input"
                                            :placeholder="t('db_user')"
                                        />
                                    </div>
                                    <div>
                                        <label class="mb-1 block text-xs font-medium text-gray-500">{{ t('Password') }}</label>
                                        <div class="relative">
                                            <input
                                                v-model="userForms[db.id].db_password"
                                                :type="userForms[db.id].show_password ? 'text' : 'password'"
                                                class="form-input pr-20"
                                                :placeholder="t('Min 8 chars')"
                                            />
                                            <div class="absolute inset-y-0 right-1 flex items-center gap-1">
                                                <button
                                                    type="button"
                                                    class="password-icon-btn"
                                                    :title="t('Show/Hide')"
                                                    @click="userForms[db.id].show_password = !userForms[db.id].show_password"
                                                >
                                                    <i :class="userForms[db.id].show_password ? 'bx bx-show' : 'bx bx-hide'"></i>
                                                </button>
                                                <button
                                                    type="button"
                                                    class="password-icon-btn"
                                                    :disabled="userForms[db.id].db_password === ''"
                                                    :title="t('Copy')"
                                                    @click="copyUserPassword(db.id)"
                                                >
                                                    <i :class="userForms[db.id].copied_password ? 'bx bx-check' : 'bx bx-copy'"></i>
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                    <div>
                                        <label class="mb-1 block text-xs font-medium text-gray-500">{{ t('Confirm Password') }}</label>
                                        <input
                                            v-model="userForms[db.id].db_password_confirmation"
                                            :type="userForms[db.id].show_password ? 'text' : 'password'"
                                            class="form-input"
                                            :placeholder="t('Repeat password')"
                                        />
                                    </div>
                                    <div class="md:col-span-3 flex items-center justify-end gap-2">
                                        <button
                                            type="button"
                                            class="inline-flex h-10 items-center gap-1.5 rounded-lg border border-gray-300 px-3 text-sm text-gray-700 hover:bg-gray-100 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-gray-800"
                                            @click="generateUserPassword(db.id)"
                                        >
                                            <i class="bx bx-shuffle"></i>
                                            {{ t('Generate') }}
                                        </button>
                                        <button
                                            type="submit"
                                            :disabled="userForms[db.id].submitting"
                                            class="inline-flex h-10 items-center gap-1.5 rounded-lg bg-brand-500 px-4 text-sm font-medium text-white hover:bg-brand-600 disabled:opacity-50"
                                        >
                                            <i class="bx bx-save"></i>
                                            {{ userForms[db.id].submitting ? '...' : t('Save User') }}
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>

                <div
                    v-if="showCreateDatabaseModal"
                    class="fixed inset-0 z-1200000 flex items-center justify-center bg-black/60 p-4 backdrop-blur-sm"
                >
                    <div class="w-full max-w-2xl rounded-2xl border border-gray-200 bg-white shadow-theme-xl dark:border-gray-800 dark:bg-gray-900">
                        <div class="flex items-center justify-between border-b border-gray-200 px-5 py-4 dark:border-gray-800 md:px-6">
                            <h4 class="text-base font-semibold text-gray-800 dark:text-white/90">{{ t('Create Database') }}</h4>
                            <button
                                type="button"
                                class="text-2xl leading-none text-gray-400 hover:text-gray-700 dark:hover:text-gray-200"
                                :disabled="creating"
                                @click="closeCreateDatabaseModal"
                            >
                                &times;
                            </button>
                        </div>
                        <div class="p-5 md:p-6">
                            <div class="mb-4 flex justify-end">
                                <button
                                    type="button"
                                    class="inline-flex items-center gap-1.5 rounded-lg border border-brand-500/40 px-3 py-1.5 text-xs font-medium text-brand-600 hover:bg-brand-50 dark:text-brand-300 dark:hover:bg-brand-500/10"
                                    @click="generateCreatePassword"
                                >
                                    <i class="bx bx-shuffle"></i>
                                    {{ t('Generate Password') }}
                                </button>
                            </div>

                            <form @submit.prevent="createDatabase" class="grid grid-cols-1 gap-3 md:grid-cols-2">
                                <div>
                                    <label class="mb-1 block text-xs font-medium uppercase tracking-wide text-gray-500">{{ t('Database Name') }}</label>
                                    <input v-model="createForm.db_name" type="text" class="form-input" :placeholder="t('my_database')" />
                                </div>
                                <div>
                                    <label class="mb-1 block text-xs font-medium uppercase tracking-wide text-gray-500">{{ t('Username') }}</label>
                                    <input v-model="createForm.db_user" type="text" class="form-input" :placeholder="t('my_user')" />
                                </div>
                                <div>
                                    <label class="mb-1 block text-xs font-medium uppercase tracking-wide text-gray-500">{{ t('Password') }}</label>
                                    <div class="relative">
                                        <input
                                            v-model="createForm.db_password"
                                            :type="createPasswordVisible ? 'text' : 'password'"
                                            class="form-input pr-20"
                                            :placeholder="t('Min 8 chars')"
                                        />
                                        <div class="absolute inset-y-0 right-1 flex items-center gap-1">
                                            <button
                                                type="button"
                                                class="password-icon-btn"
                                                :title="t('Show/Hide')"
                                                @click="createPasswordVisible = !createPasswordVisible"
                                            >
                                                <i :class="createPasswordVisible ? 'bx bx-show' : 'bx bx-hide'"></i>
                                            </button>
                                            <button
                                                type="button"
                                                class="password-icon-btn"
                                                :disabled="createForm.db_password === ''"
                                                :title="t('Copy')"
                                                @click="copyCreatePassword"
                                            >
                                                <i :class="createPasswordCopied ? 'bx bx-check' : 'bx bx-copy'"></i>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                                <div>
                                    <label class="mb-1 block text-xs font-medium uppercase tracking-wide text-gray-500">{{ t('Confirm Password') }}</label>
                                    <input
                                        v-model="createForm.db_password_confirmation"
                                        :type="createPasswordVisible ? 'text' : 'password'"
                                        class="form-input"
                                        :placeholder="t('Repeat password')"
                                    />
                                </div>

                                <div class="md:col-span-2 flex items-center justify-end gap-2 pt-1">
                                    <button
                                        type="button"
                                        class="rounded-lg border border-gray-300 px-4 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-400 dark:hover:bg-gray-800"
                                        :disabled="creating"
                                        @click="closeCreateDatabaseModal"
                                    >
                                        {{ t('Cancel') }}
                                    </button>
                                    <button
                                        type="submit"
                                        :disabled="creating"
                                        class="inline-flex h-10 shrink-0 items-center gap-1.5 rounded-lg bg-brand-500 px-4 text-sm font-medium text-white hover:bg-brand-600 disabled:opacity-50"
                                    >
                                        <i class="bx bx-plus"></i>
                                        {{ creating ? '...' : t('Create') }}
                                    </button>
                                </div>
                            </form>
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
import Toast from '@/Components/UI/Toast.vue';
import { useToast } from '@/Composables/useToast';
import { useI18n } from '@/Composables/useI18n';
import { formatDateTime } from '@/utils/dateTime';

const props = defineProps<{
    domain: Record<string, any>;
    databases: Array<Record<string, any>>;
}>();
const { t } = useI18n();

const breadcrumbs = computed(() => [
    { label: t('Domains'), href: route('domains.index') },
    { label: props.domain.fqdn, href: route('domains.show', props.domain.id) },
    { label: t('Databases') },
]);

interface DatabaseUser {
    id: number;
    db_user: string;
}

interface ManagedDatabase {
    id: number;
    db_name: string;
    created_at?: string | null;
    database_users: DatabaseUser[];
}

interface DatabaseUserForm {
    open: boolean;
    db_user: string;
    db_password: string;
    db_password_confirmation: string;
    show_password: boolean;
    copied_password: boolean;
    submitting: boolean;
}

interface DatabaseUserPasswordForm {
    open: boolean;
    db_password: string;
    db_password_confirmation: string;
    show_password: boolean;
    copied_password: boolean;
    submitting: boolean;
}

const { addToast } = useToast();
const dbList = ref<ManagedDatabase[]>([...(props.databases as ManagedDatabase[])]);
const creating = ref(false);
const showCreateDatabaseModal = ref(false);
const userForms = reactive<Record<number, DatabaseUserForm>>({});
const passwordForms = reactive<Record<number, DatabaseUserPasswordForm>>({});

const createForm = ref({
    db_name: '',
    db_user: '',
    db_password: '',
    db_password_confirmation: '',
});
const createPasswordVisible = ref(false);
const createPasswordCopied = ref(false);

const resetCreateForm = (): void => {
    createForm.value = {
        db_name: '',
        db_user: '',
        db_password: '',
        db_password_confirmation: '',
    };
    createPasswordVisible.value = false;
    createPasswordCopied.value = false;
};

const openCreateDatabaseModal = (): void => {
    showCreateDatabaseModal.value = true;
};

const closeCreateDatabaseModal = (): void => {
    showCreateDatabaseModal.value = false;
    resetCreateForm();
};

const ensureUserForm = (databaseId: number): DatabaseUserForm => {
    if (!userForms[databaseId]) {
        userForms[databaseId] = {
            open: false,
            db_user: '',
            db_password: '',
            db_password_confirmation: '',
            show_password: false,
            copied_password: false,
            submitting: false,
        };
    }

    return userForms[databaseId];
};

const ensurePasswordForm = (userId: number): DatabaseUserPasswordForm => {
    if (!passwordForms[userId]) {
        passwordForms[userId] = {
            open: false,
            db_password: '',
            db_password_confirmation: '',
            show_password: false,
            copied_password: false,
            submitting: false,
        };
    }

    return passwordForms[userId];
};

const hasDatabaseUser = (database: ManagedDatabase): boolean => {
    return Array.isArray(database.database_users) && database.database_users.length > 0;
};

const formatDate = (value?: string | null): string => {
    return formatDateTime(value ?? null);
};

const loadDatabases = async (): Promise<void> => {
    const response = await axios.get(route('domains.databases.json', props.domain.id));
    dbList.value = response.data as ManagedDatabase[];
};

const toggleUserForm = (databaseId: number): void => {
    const form = ensureUserForm(databaseId);
    form.open = !form.open;
};

const togglePasswordForm = (userId: number): void => {
    const form = ensurePasswordForm(userId);
    form.open = !form.open;
};

const generateRandomPassword = (length = 20): string => {
    const chars = 'abcdefghijkmnopqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789!@#$%&*';
    const array = new Uint32Array(length);
    crypto.getRandomValues(array);

    let password = '';
    for (let index = 0; index < length; index += 1) {
        password += chars[array[index] % chars.length];
    }

    return password;
};

const generateCreatePassword = (): void => {
    const password = generateRandomPassword();
    createForm.value.db_password = password;
    createForm.value.db_password_confirmation = password;
    createPasswordVisible.value = true;
    createPasswordCopied.value = false;
};

const generateUserPassword = (databaseId: number): void => {
    const form = ensureUserForm(databaseId);
    const password = generateRandomPassword();
    form.db_password = password;
    form.db_password_confirmation = password;
    form.show_password = true;
    form.copied_password = false;
};

const generatePasswordForUser = (userId: number): void => {
    const form = ensurePasswordForm(userId);
    const password = generateRandomPassword();
    form.db_password = password;
    form.db_password_confirmation = password;
    form.show_password = true;
    form.copied_password = false;
};

const copyToClipboard = async (value: string): Promise<boolean> => {
    if (value.trim() === '') {
        return false;
    }

    try {
        await navigator.clipboard.writeText(value);

        return true;
    } catch {
        if (typeof document === 'undefined') {
            return false;
        }

        const textarea = document.createElement('textarea');
        textarea.value = value;
        textarea.setAttribute('readonly', 'readonly');
        textarea.style.position = 'fixed';
        textarea.style.opacity = '0';
        document.body.appendChild(textarea);
        textarea.select();
        const copied = document.execCommand('copy');
        document.body.removeChild(textarea);

        return copied;
    }
};

const copyCreatePassword = async (): Promise<void> => {
    const copied = await copyToClipboard(createForm.value.db_password);

    if (!copied) {
        return;
    }

    createPasswordCopied.value = true;
    addToast('success', t('Copied!'));

    setTimeout(() => {
        createPasswordCopied.value = false;
    }, 1500);
};

const copyUserPassword = async (databaseId: number): Promise<void> => {
    const form = ensureUserForm(databaseId);
    const copied = await copyToClipboard(form.db_password);

    if (!copied) {
        return;
    }

    form.copied_password = true;
    addToast('success', t('Copied!'));

    setTimeout(() => {
        form.copied_password = false;
    }, 1500);
};

const copyPasswordForUser = async (userId: number): Promise<void> => {
    const form = ensurePasswordForm(userId);
    const copied = await copyToClipboard(form.db_password);

    if (!copied) {
        return;
    }

    form.copied_password = true;
    addToast('success', t('Copied!'));

    setTimeout(() => {
        form.copied_password = false;
    }, 1500);
};

const createDatabase = async (): Promise<void> => {
    if (!createForm.value.db_name || !createForm.value.db_user || !createForm.value.db_password) {
        addToast('warning', t('Database name, username and password are required.'));

        return;
    }

    if (createForm.value.db_password !== createForm.value.db_password_confirmation) {
        addToast('warning', t('Password confirmation does not match.'));

        return;
    }

    creating.value = true;
    try {
        const res = await axios.post(route('domains.databases.store', props.domain.id), createForm.value);
        addToast('success', res.data.message || t('Database created successfully.'));
        closeCreateDatabaseModal();
        await loadDatabases();
    } catch (error: any) {
        addToast('error', error.response?.data?.message || t('Failed to create database'));
    } finally {
        creating.value = false;
    }
};

const addUser = async (database: ManagedDatabase): Promise<void> => {
    const form = ensureUserForm(database.id);

    if (!form.db_user || !form.db_password) {
        addToast('warning', t('Username and password are required.'));

        return;
    }

    if (form.db_password !== form.db_password_confirmation) {
        addToast('warning', t('Password confirmation does not match.'));

        return;
    }

    form.submitting = true;

    try {
        const res = await axios.post(route('domains.databases.users.store', {
            domain: props.domain.id,
            database: database.id,
        }), {
            db_user: form.db_user,
            db_password: form.db_password,
            db_password_confirmation: form.db_password_confirmation,
        });

        addToast('success', res.data.message || t('User created successfully.'));
        form.db_user = '';
        form.db_password = '';
        form.db_password_confirmation = '';
        form.show_password = false;
        form.copied_password = false;
        form.open = false;
        await loadDatabases();
    } catch (error: any) {
        addToast('error', error.response?.data?.message || t('Failed to create user'));
    } finally {
        form.submitting = false;
    }
};

const updateUserPassword = async (user: DatabaseUser): Promise<void> => {
    const form = ensurePasswordForm(user.id);

    if (!form.db_password) {
        addToast('warning', t('Password is required.'));

        return;
    }

    if (form.db_password !== form.db_password_confirmation) {
        addToast('warning', t('Password confirmation does not match.'));

        return;
    }

    form.submitting = true;

    try {
        const res = await axios.put(route('domains.databases.users.password', {
            domain: props.domain.id,
            user: user.id,
        }), {
            db_password: form.db_password,
            db_password_confirmation: form.db_password_confirmation,
        });

        addToast('success', res.data.message || t('Password updated.'));
        form.db_password = '';
        form.db_password_confirmation = '';
        form.show_password = false;
        form.copied_password = false;
        form.open = false;
        await loadDatabases();
    } catch (error: any) {
        addToast('error', error.response?.data?.message || t('Failed to update password'));
    } finally {
        form.submitting = false;
    }
};

const deleteDatabase = async (database: ManagedDatabase): Promise<void> => {
    if (!confirm(t('Delete database :name and all its users?', { name: database.db_name }))) {
        return;
    }

    try {
        const res = await axios.delete(route('domains.databases.destroy', {
            domain: props.domain.id,
            database: database.id,
        }));
        addToast('success', res.data.message || t('Database deleted.'));
        await loadDatabases();
    } catch (error: any) {
        addToast('error', error.response?.data?.message || t('Failed to delete database'));
    }
};

const deleteUser = async (user: DatabaseUser): Promise<void> => {
    if (!confirm(t('Remove user :name?', { name: user.db_user }))) {
        return;
    }

    try {
        const res = await axios.delete(route('domains.databases.users.destroy', {
            domain: props.domain.id,
            user: user.id,
        }));
        addToast('success', res.data.message || t('Database user deleted.'));
        await loadDatabases();
    } catch (error: any) {
        addToast('error', error.response?.data?.message || t('Failed to delete user'));
    }
};
</script>

<style scoped>
@reference "../../../css/app.css";

.form-input {
    @apply h-10 w-full rounded-lg border border-gray-300 bg-transparent px-3 py-2 text-sm text-gray-800 shadow-theme-xs focus:border-brand-300 focus:outline-hidden focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90;
}

.password-icon-btn {
    @apply inline-flex h-7 w-7 items-center justify-center rounded-md border border-gray-300 text-gray-500 transition hover:bg-gray-100 hover:text-gray-700 disabled:cursor-not-allowed disabled:opacity-40 dark:border-gray-700 dark:text-gray-400 dark:hover:bg-gray-800 dark:hover:text-gray-200;
}
</style>
