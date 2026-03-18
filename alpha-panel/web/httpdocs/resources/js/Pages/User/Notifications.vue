<template>
    <Head :title="t('Notifications')" />
    <ThemeProvider>
        <SidebarProvider>
            <AdminLayout>
                <PageBreadcrumb :pageTitle="t('Notifications')" :items="breadcrumbs" />

                <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03] md:p-6">
                    <div class="mb-5 flex flex-wrap items-center justify-end gap-3">
                        <button
                            v-if="unreadCount > 0"
                            type="button"
                            class="inline-flex items-center rounded-lg border border-brand-300 px-3 py-2 text-sm font-medium text-brand-600 transition-colors hover:bg-brand-50 disabled:opacity-50 dark:border-brand-700 dark:text-brand-300 dark:hover:bg-brand-950/40"
                            :disabled="markAllProcessing"
                            @click="markAllAsRead"
                        >
                            {{ t('Mark all read') }}
                        </button>
                        <button
                            v-if="items.length > 0"
                            type="button"
                            class="inline-flex items-center rounded-lg border border-error-300 px-3 py-2 text-sm font-medium text-error-600 transition-colors hover:bg-error-50 disabled:opacity-50 dark:border-error-700 dark:text-error-300 dark:hover:bg-error-950/40"
                            :disabled="deleteAllProcessing"
                            @click="deleteAllNotifications"
                        >
                            {{ t('Delete all') }}
                        </button>
                    </div>

                    <div v-if="items.length === 0" class="py-10 text-center text-sm text-gray-500 dark:text-gray-400">
                        {{ t('No notifications') }}
                    </div>

                    <div v-else class="space-y-3">
                        <div
                            v-for="notification in items"
                            :key="notification.id"
                            class="rounded-xl border p-4 transition-colors"
                            :class="notification.read_at ? 'border-gray-200 dark:border-gray-800' : 'border-brand-300 dark:border-brand-700'"
                        >
                            <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                                <div class="min-w-0">
                                    <div class="flex items-center gap-2">
                                        <span class="text-base" :class="notification.data.icon || 'bx bx-bell'"></span>
                                        <p class="truncate text-sm font-semibold text-gray-800 dark:text-white/90">
                                            {{ notification.data.title || t('Notification') }}
                                        </p>
                                    </div>
                                    <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                                        {{ notification.data.body || '' }}
                                    </p>
                                    <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">
                                        {{ notification.created_at_human || '-' }}
                                    </p>
                                </div>

                                <div class="flex shrink-0 flex-wrap items-center gap-2">
                                    <button
                                        v-if="notification.data.url"
                                        type="button"
                                        class="inline-flex items-center rounded-lg border border-gray-300 px-3 py-2 text-xs font-medium text-gray-700 transition-colors hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-gray-800"
                                        @click="openNotification(notification)"
                                    >
                                        {{ t('View') }}
                                    </button>
                                    <button
                                        v-if="!notification.read_at"
                                        type="button"
                                        class="inline-flex items-center rounded-lg border border-brand-300 px-3 py-2 text-xs font-medium text-brand-600 transition-colors hover:bg-brand-50 disabled:opacity-50 dark:border-brand-700 dark:text-brand-300 dark:hover:bg-brand-950/40"
                                        :disabled="isReading(notification.id) || markAllProcessing"
                                        @click="markAsRead(notification)"
                                    >
                                        {{ t('Mark as read') }}
                                    </button>
                                    <button
                                        type="button"
                                        class="inline-flex items-center rounded-lg border border-error-300 px-3 py-2 text-xs font-medium text-error-600 transition-colors hover:bg-error-50 disabled:opacity-50 dark:border-error-700 dark:text-error-300 dark:hover:bg-error-950/40"
                                        :disabled="isDeleting(notification.id)"
                                        @click="deleteNotification(notification)"
                                    >
                                        {{ t('Delete') }}
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div v-if="hasPagination" class="mt-6 flex items-center justify-center gap-2">
                        <button
                            type="button"
                            class="h-9 w-9 rounded-lg border border-gray-300 text-sm text-gray-700 transition-colors hover:bg-gray-50 disabled:opacity-40 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-gray-800"
                            :disabled="currentPage === 1"
                            @click="goToPage(currentPage - 1)"
                        >
                            &lsaquo;
                        </button>
                        <button
                            v-for="pageNumber in pageNumbers"
                            :key="pageNumber"
                            type="button"
                            class="h-9 min-w-9 rounded-lg border px-3 text-sm font-medium transition-colors"
                            :class="pageNumber === currentPage
                                ? 'border-brand-500 bg-brand-500 text-white'
                                : 'border-gray-300 text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-gray-800'"
                            @click="goToPage(pageNumber)"
                        >
                            {{ pageNumber }}
                        </button>
                        <button
                            type="button"
                            class="h-9 w-9 rounded-lg border border-gray-300 text-sm text-gray-700 transition-colors hover:bg-gray-50 disabled:opacity-40 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-gray-800"
                            :disabled="currentPage === lastPage"
                            @click="goToPage(currentPage + 1)"
                        >
                            &rsaquo;
                        </button>
                    </div>
                </div>
            </AdminLayout>
        </SidebarProvider>
    </ThemeProvider>
</template>

<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import axios from 'axios';
import ThemeProvider from '@/Components/Layout/ThemeProvider.vue';
import SidebarProvider from '@/Components/Layout/SidebarProvider.vue';
import AdminLayout from '@/Components/Layout/AdminLayout.vue';
import PageBreadcrumb from '@/Components/Common/PageBreadcrumb.vue';
import { useI18n } from '@/Composables/useI18n';

interface NotificationPayload {
    level?: 'success' | 'error' | 'warning' | 'info';
    title?: string;
    body?: string;
    url?: string | null;
    icon?: string;
}

interface NotificationItem {
    id: string;
    data: NotificationPayload;
    read_at: string | null;
    created_at: string | null;
    created_at_human: string | null;
}

interface NotificationsPaginator {
    data: NotificationItem[];
    current_page: number;
    last_page: number;
}

const props = defineProps<{
    notifications: NotificationsPaginator;
    unread_count: number;
}>();

const { t } = useI18n();
const items = ref<NotificationItem[]>([...props.notifications.data]);
const unreadCount = ref(Number(props.unread_count ?? 0));
const markingIds = ref<string[]>([]);
const deletingIds = ref<string[]>([]);
const markAllProcessing = ref(false);
const deleteAllProcessing = ref(false);

const breadcrumbs = computed(() => [
    { label: t('Security Settings'), href: route('user.security') },
    { label: t('Notifications') },
]);

const currentPage = computed(() => Number(props.notifications.current_page ?? 1));
const lastPage = computed(() => Number(props.notifications.last_page ?? 1));
const hasPagination = computed(() => lastPage.value > 1);
const pageNumbers = computed(() => {
    const start = Math.max(1, currentPage.value - 2);
    const end = Math.min(lastPage.value, currentPage.value + 2);

    return Array.from({ length: end - start + 1 }, (_, index) => start + index);
});

watch(() => props.notifications.data, (notifications) => {
    items.value = [...notifications];
});

watch(() => props.unread_count, (count) => {
    unreadCount.value = Number(count ?? 0);
});

const isReading = (id: string): boolean => markingIds.value.includes(id);
const isDeleting = (id: string): boolean => deletingIds.value.includes(id);

const goToPage = (page: number): void => {
    if (page < 1 || page > lastPage.value || page === currentPage.value) {
        return;
    }

    router.get(route('user.notifications.page'), { page }, {
        preserveScroll: true,
        preserveState: false,
        replace: true,
    });
};

const markAsRead = async (notification: NotificationItem): Promise<void> => {
    if (notification.read_at || isReading(notification.id)) {
        return;
    }

    markingIds.value = [...markingIds.value, notification.id];

    try {
        const response = await axios.post(route('user.notifications.read', notification.id));
        notification.read_at = new Date().toISOString();
        unreadCount.value = Number(response.data.unread_count ?? unreadCount.value);
    } catch {
        // silent
    } finally {
        markingIds.value = markingIds.value.filter((id) => id !== notification.id);
    }
};

const markAllAsRead = async (): Promise<void> => {
    if (unreadCount.value === 0 || markAllProcessing.value) {
        return;
    }

    markAllProcessing.value = true;

    try {
        await axios.post(route('user.notifications.read-all'));
        unreadCount.value = 0;
        items.value = items.value.map((notification) => ({
            ...notification,
            read_at: notification.read_at ?? new Date().toISOString(),
        }));
    } catch {
        // silent
    } finally {
        markAllProcessing.value = false;
    }
};

const deleteNotification = async (notification: NotificationItem): Promise<void> => {
    if (isDeleting(notification.id)) {
        return;
    }

    deletingIds.value = [...deletingIds.value, notification.id];

    try {
        const response = await axios.delete(route('user.notifications.destroy', notification.id));
        items.value = items.value.filter((item) => item.id !== notification.id);
        unreadCount.value = Number(response.data.unread_count ?? unreadCount.value);

        if (items.value.length === 0 && currentPage.value > 1) {
            goToPage(currentPage.value - 1);
        }
    } catch {
        // silent
    } finally {
        deletingIds.value = deletingIds.value.filter((id) => id !== notification.id);
    }
};

const deleteAllNotifications = async (): Promise<void> => {
    if (deleteAllProcessing.value) {
        return;
    }

    deleteAllProcessing.value = true;

    try {
        await axios.delete(route('user.notifications.destroy-all'));
        items.value = [];
        unreadCount.value = 0;

        if (currentPage.value > 1) {
            goToPage(1);
        }
    } catch {
        // silent
    } finally {
        deleteAllProcessing.value = false;
    }
};

const openNotification = async (notification: NotificationItem): Promise<void> => {
    if (!notification.read_at) {
        await markAsRead(notification);
    }

    if (notification.data.url) {
        router.visit(notification.data.url);
    }
};
</script>
