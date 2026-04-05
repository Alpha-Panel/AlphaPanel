<template>
    <Head :title="t('Push Devices')" />
    <ThemeProvider>
        <SidebarProvider>
            <AdminLayout>
                <PageBreadcrumb :pageTitle="t('Push Devices')" />
                <Toast />

                <div class="space-y-6">
                    <!-- This Device -->
                    <div v-if="pushSupported" class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/3 md:p-6">
                        <h3 class="mb-4 text-lg font-semibold text-gray-800 dark:text-white/90">{{ t('This Device') }}</h3>
                        <div class="flex items-center justify-between gap-3">
                            <div>
                                <p class="text-sm text-gray-600 dark:text-gray-400">
                                    {{ t('Receive browser notifications even when the panel is closed.') }}
                                </p>
                            </div>
                            <button
                                type="button"
                                :disabled="pushLoading"
                                class="relative inline-flex h-6 w-11 shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out focus:outline-none disabled:opacity-50"
                                :class="pushSubscribed ? 'bg-brand-500' : 'bg-gray-200 dark:bg-gray-700'"
                                @click="handleToggle"
                            >
                                <span
                                    class="pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out"
                                    :class="pushSubscribed ? 'translate-x-5' : 'translate-x-0'"
                                />
                            </button>
                        </div>
                        <p class="mt-2 text-xs text-gray-500 dark:text-gray-500">
                            {{ pushSubscribed ? t('Push notifications enabled.') : t('Push notifications disabled.') }}
                        </p>
                        <p v-if="pushError" class="mt-2 text-xs text-error-500">
                            <template v-if="pushError === 'notification_denied'">{{ t('Notification permission was denied. Please allow notifications in your browser settings.') }}</template>
                            <template v-else-if="pushError === 'sw_register_failed'">{{ t('Service worker registration failed. Please check your browser settings.') }}</template>
                            <template v-else-if="pushError === 'brave_push_blocked'">{{ t('Brave browser blocks push notifications by default. Go to brave://settings/privacy and enable "Use Google services for push messaging".') }}</template>
                            <template v-else-if="pushError === 'push_service_error'">{{ t('Push service error. Please check your internet connection and try again.') }}</template>
                            <template v-else>{{ t('Failed to enable push notifications.') }}</template>
                        </p>
                    </div>

                    <!-- Subscribed Devices -->
                    <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/3 md:p-6">
                        <h3 class="mb-4 text-lg font-semibold text-gray-800 dark:text-white/90">{{ t('Subscribed Devices') }}</h3>

                        <div v-if="devices.length === 0" class="py-8 text-center text-sm text-gray-500 dark:text-gray-400">
                            {{ t('No push devices registered yet.') }}
                        </div>

                        <div v-else class="divide-y divide-gray-200 dark:divide-gray-800">
                            <div v-for="device in devices" :key="device.id" class="flex items-center justify-between gap-4 py-3">
                                <div class="flex items-center gap-3 min-w-0">
                                    <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-gray-100 dark:bg-gray-800">
                                        <i :class="deviceIcon(device.device_type)" class="text-xl text-gray-500 dark:text-gray-400"></i>
                                    </div>
                                    <div class="min-w-0">
                                        <div class="flex items-center gap-2">
                                            <p class="text-sm font-medium text-gray-800 dark:text-white/90 truncate">
                                                {{ device.browser_name || t('Unknown Browser') }}
                                                <span v-if="device.browser_version" class="text-gray-500 dark:text-gray-400">{{ device.browser_version }}</span>
                                            </p>
                                            <span v-if="device.is_current" class="inline-flex items-center rounded-full bg-brand-50 px-2 py-0.5 text-xs font-medium text-brand-600 dark:bg-brand-500/10 dark:text-brand-400">
                                                {{ t('Current') }}
                                            </span>
                                        </div>
                                        <p class="text-xs text-gray-500 dark:text-gray-400 truncate">
                                            {{ device.os_name || t('Unknown OS') }}
                                            <span v-if="device.created_at" class="ml-1">&middot; {{ formatDateTime(device.created_at) }}</span>
                                        </p>
                                    </div>
                                </div>
                                <button
                                    @click="removeDevice(device)"
                                    :disabled="removing === device.id"
                                    class="shrink-0 rounded-lg border border-error-300 px-3 py-1.5 text-xs font-medium text-error-600 hover:bg-error-50 disabled:opacity-50 dark:border-error-700 dark:text-error-400 dark:hover:bg-error-500/10"
                                >
                                    {{ removing === device.id ? t('Removing...') : t('Remove') }}
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
import { ref, onMounted } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import axios from 'axios';
import ThemeProvider from '@/Components/Layout/ThemeProvider.vue';
import SidebarProvider from '@/Components/Layout/SidebarProvider.vue';
import AdminLayout from '@/Components/Layout/AdminLayout.vue';
import PageBreadcrumb from '@/Components/Common/PageBreadcrumb.vue';
import Toast from '@/Components/UI/Toast.vue';
import { usePushSubscription } from '@/Composables/usePushSubscription';
import { useToast } from '@/Composables/useToast';
import { useI18n } from '@/Composables/useI18n';
import { formatDateTime } from '@/utils/dateTime';

interface PushDevice {
    id: number;
    endpoint: string;
    browser_name: string | null;
    browser_version: string | null;
    os_name: string | null;
    device_type: string | null;
    created_at: string | null;
    is_current?: boolean;
}

const props = defineProps<{
    subscriptions: PushDevice[];
}>();

const { addToast } = useToast();
const { t } = useI18n();
const {
    isSubscribed: pushSubscribed,
    isSupported: pushSupported,
    loading: pushLoading,
    error: pushError,
    toggle: togglePush,
    getCurrentEndpoint,
} = usePushSubscription();

const devices = ref<PushDevice[]>([...props.subscriptions]);
const removing = ref<number | null>(null);

function deviceIcon(type: string | null): string {
    switch (type) {
        case 'mobile':
            return 'bx bx-mobile-alt';
        case 'tablet':
            return 'bx bx-tab';
        default:
            return 'bx bx-desktop';
    }
}

async function markCurrentDevice(): Promise<void> {
    try {
        const currentEndpoint = await getCurrentEndpoint();
        if (!currentEndpoint) return;
        for (const device of devices.value) {
            device.is_current = device.endpoint === currentEndpoint;
        }
    } catch {
        // non-critical
    }
}

async function handleToggle(): Promise<void> {
    await togglePush();

    // Only reload if toggle succeeded (no error) to refresh device list
    if (!pushError.value) {
        router.reload({ only: ['subscriptions'], onSuccess: () => {
            devices.value = [...(props.subscriptions ?? [])];
            markCurrentDevice();
        }});
    }
}

async function removeDevice(device: PushDevice): Promise<void> {
    removing.value = device.id;
    try {
        await axios.delete(route('user.push-devices.destroy', { pushSubscription: device.id }));
        devices.value = devices.value.filter((d) => d.id !== device.id);
        addToast('success', t('Device removed.'));

        // If we just removed the current device, update subscription state
        if (device.is_current) {
            pushSubscribed.value = false;
        }
    } catch (e: any) {
        addToast('error', e.response?.data?.message || t('Failed to remove device.'));
    } finally {
        removing.value = null;
    }
}

onMounted(() => markCurrentDevice());
</script>

<style scoped>
@reference "../../../css/app.css";
</style>
