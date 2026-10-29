<template>
    <div ref="dropdownRef" class="relative">
        <button type="button" :title="t('Notifications')" class="ap-header-btn" @click="toggleDropdown">
            <i class="bx bx-bell text-base"></i>
            <span v-if="showIndicator" class="absolute end-1 top-1 size-1.5 rounded-full bg-warn">
                <span class="absolute inset-0 animate-ping rounded-full bg-warn opacity-75"></span>
            </span>
        </button>

        <div
            v-if="dropdownOpen"
            class="ap-pop flex h-120 max-h-[calc(100dvh-5rem)] flex-col p-0 max-sm:fixed max-sm:inset-x-3 max-sm:top-14 sm:end-0 sm:top-[calc(100%+6px)] sm:w-90"
        >
            <div class="ap-card-head">
                <i class="bx bx-bell text-[15px] text-fg-3"></i>
                <span>{{ t('Notifications') }}</span>
                <div class="ms-auto flex items-center gap-1.5 font-normal">
                    <button v-if="unreadCount > 0" type="button" class="text-xs font-medium text-accent hover:underline" @click="markAllAsRead">
                        {{ t('Mark all read') }}
                    </button>
                    <button type="button" class="inline-flex size-6 items-center justify-center rounded-md text-fg-3 hover:bg-surface-2 hover:text-fg" :aria-label="t('Close')" @click="closeDropdown">
                        <i class="bx bx-x text-base"></i>
                    </button>
                </div>
            </div>

            <div v-if="loading" class="flex flex-1 items-center justify-center text-sm text-fg-3">
                {{ t('Loading...') }}
            </div>

            <div v-else-if="notifications.length === 0" class="flex flex-1 flex-col items-center justify-center gap-1 text-sm text-fg-3">
                <i class="bx bx-bell-off text-2xl"></i>{{ t('No notifications') }}
            </div>

            <div v-else class="flex-1 space-y-1 overflow-y-auto p-1.5">
                <button
                    v-for="notification in notifications"
                    :key="notification.id"
                    type="button"
                    class="w-full rounded-md border px-2.5 py-2 text-start transition-colors hover:bg-surface-2"
                    :class="notification.read_at ? 'border-transparent' : 'border-accent/40 bg-accent-soft'"
                    @click="openNotification(notification)"
                >
                    <div class="flex items-start gap-2">
                        <span class="mt-0.5 text-base text-fg-3" :class="notification.data.icon || 'bx bx-bell'"></span>
                        <div class="min-w-0 flex-1">
                            <div class="flex items-center justify-between gap-2">
                                <p class="truncate text-sm font-semibold text-fg">
                                    {{ notification.data.title || t('Notification') }}
                                </p>
                                <span class="shrink-0 font-mono text-2xs text-fg-3">
                                    {{ notification.created_at_human || '-' }}
                                </span>
                            </div>
                            <p class="mt-0.5 line-clamp-2 text-xs text-fg-2">
                                {{ notification.data.body || '' }}
                            </p>
                        </div>
                    </div>
                </button>
            </div>

            <div class="flex items-center gap-2 border-t border-line-2 p-2">
                <button
                    v-if="isPushSupported"
                    type="button"
                    class="ap-icon-btn size-7"
                    :class="{ '!text-accent': isPushSubscribed }"
                    :disabled="pushLoading"
                    :title="isPushSubscribed ? t('Disable push notifications') : t('Enable push notifications')"
                    @click="togglePush"
                >
                    <i :class="isPushSubscribed ? 'bx bxs-bell-ring' : 'bx bx-bell-off'"></i>
                </button>
                <Link :href="route('user.notifications.page')" class="ap-btn flex-1 justify-center hover:no-underline" @click="closeDropdown">
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
