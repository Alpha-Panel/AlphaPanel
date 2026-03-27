<template>
    <Head :title="`${t('Authorized Users')} - ${domain.fqdn}`" />
    <ThemeProvider>
        <SidebarProvider>
            <AdminLayout>
                <PageBreadcrumb
                    :pageTitle="t('Authorized Users')"
                    :items="breadcrumbs"
                    :backHref="route('domains.show', domain.id)"
                />
                <Toast />

                <div class="min-w-0 rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/3 md:p-6">
                    <div class="mb-5 flex items-center">
                        <h3 class="text-lg font-semibold text-gray-800 dark:text-white/90">
                            <i class="fa-solid fa-user-group mr-2 text-brand-500"></i>
                            {{ t('Authorized Users') }}
                        </h3>
                        <span class="ml-3 text-sm text-gray-500 dark:text-gray-400">{{ domain.fqdn }}</span>
                    </div>

                    <p class="mb-6 text-sm text-gray-500 dark:text-gray-400">
                        {{ t('Manage which users can access this domain.') }}
                    </p>

                    <!-- Add User -->
                    <div v-if="localAvailableUsers.length > 0" class="mb-6 flex gap-2">
                        <select
                            v-model="selectedUserId"
                            class="flex-1 rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-800 focus:border-brand-300 focus:outline-hidden focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200"
                        >
                            <option value="">{{ t('Select a user...') }}</option>
                            <option v-for="user in localAvailableUsers" :key="user.id" :value="user.id">
                                {{ user.name }} ({{ user.email }})
                            </option>
                        </select>
                        <button
                            type="button"
                            @click="addUser"
                            :disabled="!selectedUserId || addLoading"
                            class="inline-flex h-10 items-center gap-2 rounded-lg bg-brand-500 px-4 text-sm font-medium text-white shadow-theme-xs hover:bg-brand-600 disabled:cursor-not-allowed disabled:opacity-60"
                        >
                            <i v-if="addLoading" class="bx bx-loader-alt animate-spin text-base"></i>
                            <template v-else>
                                <i class="bx bx-plus text-base"></i>
                                {{ t('Add User') }}
                            </template>
                        </button>
                    </div>

                    <!-- User List -->
                    <div class="space-y-3">
                        <!-- Owner (read-only) -->
                        <div
                            v-if="domain.owner"
                            class="rounded-xl border border-brand-500/30 bg-brand-500/5 p-4 dark:border-brand-500/20 dark:bg-brand-500/5"
                        >
                            <div class="flex items-center gap-3">
                                <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-brand-500/15 text-brand-600 dark:text-brand-400">
                                    <i class="fa-solid fa-crown text-lg"></i>
                                </div>
                                <div class="min-w-0 flex-1">
                                    <div class="flex items-center gap-2">
                                        <h4 class="font-semibold text-gray-800 dark:text-white/90">{{ domain.owner.name }}</h4>
                                        <span class="inline-flex rounded-full bg-brand-500/20 px-2 py-0.5 text-[10px] font-semibold text-brand-600 dark:text-brand-300">
                                            {{ t('Owner') }}
                                        </span>
                                    </div>
                                    <p class="text-xs text-gray-500 dark:text-gray-400">{{ domain.owner.email }}</p>
                                </div>
                            </div>
                        </div>

                        <!-- Authorized Users -->
                        <div
                            v-for="user in localAuthorizedUsers"
                            :key="user.id"
                            class="rounded-xl border border-gray-200 bg-gray-50 p-4 dark:border-gray-800 dark:bg-white/2"
                        >
                            <div class="flex items-center gap-3">
                                <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-gray-200 text-gray-500 dark:bg-gray-800 dark:text-gray-400">
                                    <i class="fa-solid fa-user text-lg"></i>
                                </div>
                                <div class="min-w-0 flex-1">
                                    <h4 class="font-semibold text-gray-800 dark:text-white/90">{{ user.name }}</h4>
                                    <p class="text-xs text-gray-500 dark:text-gray-400">{{ user.email }}</p>
                                </div>
                                <button
                                    type="button"
                                    @click="removeUser(user)"
                                    :disabled="removeLoading === user.id"
                                    class="inline-flex h-9 items-center gap-2 rounded-lg border border-error-500/40 px-3 text-sm font-medium text-error-600 shadow-theme-xs transition-colors hover:bg-error-500/10 disabled:opacity-50 dark:text-error-400"
                                >
                                    <i v-if="removeLoading === user.id" class="bx bx-loader-alt animate-spin text-base"></i>
                                    <template v-else>
                                        <i class="bx bx-trash text-base"></i>
                                        {{ t('Remove') }}
                                    </template>
                                </button>
                            </div>
                        </div>

                        <!-- Empty State -->
                        <div
                            v-if="localAuthorizedUsers.length === 0"
                            class="rounded-xl border border-dashed border-gray-300 p-8 text-center dark:border-gray-700"
                        >
                            <i class="fa-solid fa-user-group mb-2 text-2xl text-gray-400 dark:text-gray-600"></i>
                            <p class="text-sm text-gray-500 dark:text-gray-400">
                                {{ t('No additional users have access to this domain.') }}
                            </p>
                        </div>
                    </div>
                </div>
            </AdminLayout>
        </SidebarProvider>
    </ThemeProvider>
</template>

<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ref, onMounted } from 'vue';
import axios from 'axios';
import ThemeProvider from '@/Components/Layout/ThemeProvider.vue';
import SidebarProvider from '@/Components/Layout/SidebarProvider.vue';
import AdminLayout from '@/Components/Layout/AdminLayout.vue';
import PageBreadcrumb from '@/Components/Common/PageBreadcrumb.vue';
import Toast from '@/Components/UI/Toast.vue';
import { useToast } from '@/Composables/useToast';
import { useI18n } from '@/Composables/useI18n';

interface SimpleUser {
    id: number;
    name: string;
    email: string;
}

const props = defineProps<{
    domain: {
        id: number;
        fqdn: string;
        owner: SimpleUser | null;
    };
    authorizedUsers: SimpleUser[];
    availableUsers: SimpleUser[];
}>();

const { t } = useI18n();
const { addToast } = useToast();

const localAuthorizedUsers = ref<SimpleUser[]>([]);
const localAvailableUsers = ref<SimpleUser[]>([]);
const selectedUserId = ref<number | string>('');
const addLoading = ref(false);
const removeLoading = ref<number | null>(null);

const breadcrumbs = [
    { label: t('Domains'), href: route('domains.index') },
    { label: props.domain.fqdn, href: route('domains.show', props.domain.id) },
    { label: t('Authorized Users') },
];

onMounted(() => {
    localAuthorizedUsers.value = [...props.authorizedUsers];
    localAvailableUsers.value = [...props.availableUsers];
});

const addUser = async () => {
    if (!selectedUserId.value) return;

    addLoading.value = true;

    try {
        const response = await axios.post(route('domains.users.store', props.domain.id), {
            user_id: selectedUserId.value,
        });

        const newUser = response.data.user as SimpleUser;
        localAuthorizedUsers.value.push(newUser);
        localAvailableUsers.value = localAvailableUsers.value.filter(u => u.id !== newUser.id);
        selectedUserId.value = '';

        addToast('success', response.data.message);
    } catch (error: any) {
        addToast('error', error.response?.data?.message ?? t('Failed to add user.'));
    } finally {
        addLoading.value = false;
    }
};

const removeUser = async (user: SimpleUser) => {
    removeLoading.value = user.id;

    try {
        const response = await axios.delete(route('domains.users.destroy', [props.domain.id, user.id]));

        localAuthorizedUsers.value = localAuthorizedUsers.value.filter(u => u.id !== user.id);
        localAvailableUsers.value.push(user);
        localAvailableUsers.value.sort((a, b) => a.name.localeCompare(b.name));

        addToast('success', response.data.message);
    } catch (error: any) {
        addToast('error', error.response?.data?.message ?? t('Failed to remove user.'));
    } finally {
        removeLoading.value = null;
    }
};
</script>
