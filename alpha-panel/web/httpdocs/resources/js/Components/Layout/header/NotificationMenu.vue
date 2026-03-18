<template>
    <div class="relative" ref="dropdownRef">
        <button
            class="relative flex items-center justify-center text-gray-500 transition-colors bg-white border border-gray-200 rounded-full hover:text-dark-900 h-11 w-11 hover:bg-gray-100 hover:text-gray-700 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-400 dark:hover:bg-gray-800 dark:hover:text-white"
            @click="toggleDropdown"
        >
            <span
                :class="{ hidden: !showIndicator, flex: showIndicator }"
                class="absolute right-0 top-0.5 z-1 h-2 w-2 rounded-full bg-orange-400"
            >
                <span
                    class="absolute inline-flex w-full h-full bg-orange-400 rounded-full opacity-75 -z-1 animate-ping"
                ></span>
            </span>
            <svg
                class="fill-current"
                width="20"
                height="20"
                viewBox="0 0 20 20"
                fill="none"
                xmlns="http://www.w3.org/2000/svg"
            >
                <path
                    fill-rule="evenodd"
                    clip-rule="evenodd"
                    d="M10.75 2.29248C10.75 1.87827 10.4143 1.54248 10 1.54248C9.58583 1.54248 9.25004 1.87827 9.25004 2.29248V2.83613C6.08266 3.20733 3.62504 5.9004 3.62504 9.16748V14.4591H3.33337C2.91916 14.4591 2.58337 14.7949 2.58337 15.2091C2.58337 15.6234 2.91916 15.9591 3.33337 15.9591H4.37504H15.625H16.6667C17.0809 15.9591 17.4167 15.6234 17.4167 15.2091C17.4167 14.7949 17.0809 14.4591 16.6667 14.4591H16.375V9.16748C16.375 5.9004 13.9174 3.20733 10.75 2.83613V2.29248ZM14.875 14.4591V9.16748C14.875 6.47509 12.6924 4.29248 10 4.29248C7.30765 4.29248 5.12504 6.47509 5.12504 9.16748V14.4591H14.875ZM8.00004 17.7085C8.00004 18.1228 8.33583 18.4585 8.75004 18.4585H11.25C11.6643 18.4585 12 18.1228 12 17.7085C12 17.2943 11.6643 16.9585 11.25 16.9585H8.75004C8.33583 16.9585 8.00004 17.2943 8.00004 17.7085Z"
                    fill=""
                />
            </svg>
        </button>

        <div
            v-if="dropdownOpen"
            class="fixed inset-x-3 top-16 z-[1300000] flex h-[480px] max-h-[calc(100dvh-5rem)] flex-col rounded-2xl border border-gray-200 bg-white p-3 shadow-theme-lg dark:border-gray-800 dark:bg-gray-dark sm:absolute sm:inset-auto sm:right-0 sm:top-[calc(100%+17px)] sm:w-[361px]"
        >
            <div
                class="flex items-center justify-between pb-3 mb-3 border-b border-gray-100 dark:border-gray-800"
            >
                <h5 class="text-lg font-semibold text-gray-800 dark:text-white/90">
                    {{ t('Notifications') }}
                </h5>
                <div class="flex items-center gap-2">
                    <button
                        v-if="unreadCount > 0"
                        type="button"
                        class="text-xs font-medium text-brand-500 hover:text-brand-600"
                        @click="markAllAsRead"
                    >
                        {{ t('Mark all read') }}
                    </button>
                    <button @click="closeDropdown" class="text-gray-500 dark:text-gray-400">
                        <svg
                            class="fill-current"
                            width="24"
                            height="24"
                            viewBox="0 0 24 24"
                            fill="none"
                            xmlns="http://www.w3.org/2000/svg"
                        >
                            <path
                                fill-rule="evenodd"
                                clip-rule="evenodd"
                                d="M6.21967 7.28131C5.92678 6.98841 5.92678 6.51354 6.21967 6.22065C6.51256 5.92775 6.98744 5.92775 7.28033 6.22065L11.999 10.9393L16.7176 6.22078C17.0105 5.92789 17.4854 5.92788 17.7782 6.22078C18.0711 6.51367 18.0711 6.98855 17.7782 7.28144L13.0597 12L17.7782 16.7186C18.0711 17.0115 18.0711 17.4863 17.7782 17.7792C17.4854 18.0721 17.0105 18.0721 16.7176 17.7792L11.999 13.0607L7.28033 17.7794C6.98744 18.0722 6.51256 18.0722 6.21967 17.7794C5.92678 17.4865 5.92678 17.0116 6.21967 16.7187L10.9384 12L6.21967 7.28131Z"
                                fill=""
                            />
                        </svg>
                    </button>
                </div>
            </div>

            <div v-if="loading" class="flex flex-1 items-center justify-center text-gray-400 text-theme-sm">
                {{ t('Loading...') }}
            </div>

            <div v-else-if="notifications.length === 0" class="flex flex-1 items-center justify-center text-gray-400 text-theme-sm">
                {{ t('No notifications') }}
            </div>

            <div v-else class="flex-1 overflow-y-auto pr-1 space-y-2">
                <button
                    v-for="notification in notifications"
                    :key="notification.id"
                    type="button"
                    class="w-full rounded-lg border px-3 py-2 text-left transition-colors hover:bg-gray-50 dark:hover:bg-gray-800"
                    :class="notification.read_at ? 'border-gray-200 dark:border-gray-800' : 'border-brand-300 dark:border-brand-700'"
                    @click="openNotification(notification)"
                >
                    <div class="flex items-start gap-2">
                        <span class="mt-0.5 text-base" :class="notification.data.icon || 'bx bx-bell'"></span>
                        <div class="min-w-0 flex-1">
                            <div class="flex items-center justify-between gap-2">
                                <p class="truncate text-sm font-semibold text-gray-800 dark:text-white/90">
                                    {{ notification.data.title || t('Notification') }}
                                </p>
                                <span class="shrink-0 text-[11px] text-gray-500 dark:text-gray-400">
                                    {{ notification.created_at_human || '-' }}
                                </span>
                            </div>
                            <p class="mt-0.5 line-clamp-2 text-xs text-gray-600 dark:text-gray-400">
                                {{ notification.data.body || '' }}
                            </p>
                        </div>
                    </div>
                </button>
            </div>

            <div class="mt-3 flex items-center gap-2 border-t border-gray-100 pt-3 dark:border-gray-800">
                <button
                    v-if="isPushSupported"
                    type="button"
                    class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg border border-gray-200 text-gray-500 transition-colors hover:bg-gray-50 dark:border-gray-700 dark:hover:bg-gray-800"
                    :class="{ 'text-brand-500': isPushSubscribed }"
                    :disabled="pushLoading"
                    :title="isPushSubscribed ? t('Disable push notifications') : t('Enable push notifications')"
                    @click="togglePush"
                >
                    <i :class="isPushSubscribed ? 'fa-solid fa-bell' : 'fa-solid fa-bell-slash'" class="text-sm"></i>
                </button>
                <Link
                    :href="route('user.notifications.page')"
                    class="block flex-1 rounded-lg border border-gray-200 px-3 py-2 text-center text-sm font-medium text-gray-700 transition-colors hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-gray-800"
                    @click="closeDropdown"
                >
                    {{ t('View all') }}
                </Link>
            </div>
        </div>
    </div>
</template>

<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import axios from 'axios';
import type { SharedProps } from '@/types/inertia';
import { usePushSubscription } from '@/Composables/usePushSubscription';
import { useToast } from '@/Composables/useToast';
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

const page = usePage<SharedProps>();
const { addToast } = useToast();
const { t } = useI18n();
const {
    isSubscribed: isPushSubscribed,
    isSupported: isPushSupported,
    loading: pushLoading,
    toggle: togglePush,
} = usePushSubscription();

const dropdownOpen = ref(false);
const notifying = ref(false);
const loading = ref(false);
const notifications = ref<NotificationItem[]>([]);
const unreadCount = ref(0);
const dropdownRef = ref<HTMLElement | null>(null);

const userId = computed(() => page.props.auth?.user?.id ?? null);
const showIndicator = computed(() => notifying.value || unreadCount.value > 0);
let notificationChannelName: string | null = null;

const loadNotifications = async (): Promise<void> => {
    loading.value = true;
    try {
        const response = await axios.get(route('user.notifications.index'));
        notifications.value = response.data.notifications ?? [];
        unreadCount.value = Number(response.data.unread_count ?? 0);
    } catch {
        // keep silent to avoid noisy UI
    } finally {
        loading.value = false;
    }
};

const toggleDropdown = () => {
    dropdownOpen.value = !dropdownOpen.value;
};

const closeDropdown = () => {
    dropdownOpen.value = false;
};

const markAsRead = async (notificationId: string): Promise<void> => {
    try {
        const response = await axios.post(route('user.notifications.read', notificationId));
        unreadCount.value = Number(response.data.unread_count ?? unreadCount.value);
        const notification = notifications.value.find((item) => item.id === notificationId);
        if (notification) {
            notification.read_at = new Date().toISOString();
        }
    } catch {
        // ignore
    }
};

const markAllAsRead = async (): Promise<void> => {
    try {
        await axios.post(route('user.notifications.read-all'));
        unreadCount.value = 0;
        notifications.value = notifications.value.map((notification) => ({
            ...notification,
            read_at: notification.read_at ?? new Date().toISOString(),
        }));
    } catch {
        // ignore
    }
};

const openNotification = async (notification: NotificationItem): Promise<void> => {
    if (!notification.read_at) {
        await markAsRead(notification.id);
    }

    closeDropdown();

    if (notification.data.url) {
        router.visit(notification.data.url);
    }
};

const handleRealtimeNotification = (payload: NotificationPayload) => {
    notifications.value = [{
        id: `live-${Date.now()}`,
        data: payload,
        read_at: null,
        created_at: new Date().toISOString(),
        created_at_human: t('just now'),
    }, ...notifications.value].slice(0, 15);

    unreadCount.value += 1;

    if (!dropdownOpen.value) {
        notifying.value = true;
    }

    const level = payload.level ?? 'info';
    const message = payload.body ?? t('You have a new notification.');
    addToast(level, message);
};

const subscribeEchoNotifications = () => {
    if (typeof window.Echo === 'undefined' || !userId.value) {
        return;
    }

    notificationChannelName = `App.Models.User.${userId.value}`;
    window.Echo.private(notificationChannelName).notification((payload: NotificationPayload) => {
        handleRealtimeNotification(payload);
    });
};

const unsubscribeEchoNotifications = () => {
    if (typeof window.Echo === 'undefined' || !notificationChannelName) {
        return;
    }

    window.Echo.leave(notificationChannelName);
    notificationChannelName = null;
};

const handleClickOutside = (event: MouseEvent) => {
    if (dropdownRef.value && !dropdownRef.value.contains(event.target as Node)) {
        closeDropdown();
    }
};

watch(dropdownOpen, async (open) => {
    if (open) {
        notifying.value = false;
        await loadNotifications();
    }
});

onMounted(async () => {
    document.addEventListener('click', handleClickOutside);
    await loadNotifications();
    subscribeEchoNotifications();
});

onUnmounted(() => {
    document.removeEventListener('click', handleClickOutside);
    unsubscribeEchoNotifications();
});
</script>
