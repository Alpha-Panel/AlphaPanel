<template>
    <Head :title="t('Notification Settings')" />
    <ThemeProvider>
        <SidebarProvider>
            <AdminLayout>
                <PageBreadcrumb :pageTitle="t('Notification Settings')" />
                <Toast />

                <div class="space-y-6">
                    <!-- Tabs -->
                    <div class="flex gap-2 border-b border-gray-200 dark:border-gray-800">
                        <Link
                            :href="route('user.notification-settings.index')"
                            class="px-4 py-2.5 text-sm font-medium border-b-2 transition-colors"
                            :class="tab === 'preferences'
                                ? 'border-brand-500 text-brand-600 dark:text-brand-400'
                                : 'border-transparent text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-300'"
                            preserve-scroll
                        >
                            <i class="bx bx-cog mr-1.5"></i>
                            {{ t('Notification Preferences') }}
                        </Link>
                        <Link
                            :href="route('user.notification-settings.devices')"
                            class="px-4 py-2.5 text-sm font-medium border-b-2 transition-colors"
                            :class="tab === 'devices'
                                ? 'border-brand-500 text-brand-600 dark:text-brand-400'
                                : 'border-transparent text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-300'"
                            preserve-scroll
                        >
                            <i class="bx bx-devices mr-1.5"></i>
                            {{ t('Push Devices') }}
                        </Link>
                    </div>

                    <!-- Tab: Notification Preferences -->
                    <template v-if="tab === 'preferences'">
                        <div class="rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
                            <!-- Table -->
                            <div class="overflow-x-auto">
                                <table class="w-full">
                                    <thead>
                                        <tr class="border-b border-gray-200 dark:border-gray-800">
                                            <th class="px-5 py-3.5 text-left text-sm font-semibold text-gray-800 dark:text-white/90">
                                                {{ t('Notification Type') }}
                                            </th>
                                            <th class="px-5 py-3.5 text-center text-sm font-semibold text-gray-800 dark:text-white/90 w-28">
                                                <div class="flex flex-col items-center gap-0.5">
                                                    <i class="bx bx-bell text-lg"></i>
                                                    <span>{{ t('In-App') }}</span>
                                                </div>
                                            </th>
                                            <th class="px-5 py-3.5 text-center text-sm font-semibold text-gray-800 dark:text-white/90 w-28">
                                                <div class="flex flex-col items-center gap-0.5">
                                                    <i class="bx bx-mobile-alt text-lg"></i>
                                                    <span>Push</span>
                                                </div>
                                            </th>
                                            <th class="px-5 py-3.5 text-center text-sm font-semibold text-gray-800 dark:text-white/90 w-28">
                                                <div class="flex flex-col items-center gap-0.5">
                                                    <i class="bx bx-envelope text-lg"></i>
                                                    <span>{{ t('E-mail') }}</span>
                                                </div>
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-gray-200 dark:divide-gray-800">
                                        <tr v-for="(pref, idx) in localPreferences" :key="pref.type">
                                            <td class="px-5 py-4">
                                                <div class="flex items-center gap-3">
                                                    <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-gray-100 dark:bg-gray-800">
                                                        <i :class="typeInfo(pref.type)?.icon" class="text-lg text-gray-500 dark:text-gray-400"></i>
                                                    </div>
                                                    <div>
                                                        <p class="text-sm font-medium text-gray-800 dark:text-white/90">
                                                            {{ typeInfo(pref.type)?.label }}
                                                        </p>
                                                        <p class="text-xs text-gray-500 dark:text-gray-400">
                                                            {{ typeInfo(pref.type)?.description }}
                                                        </p>
                                                    </div>
                                                </div>
                                            </td>
                                            <td class="px-5 py-4">
                                                <div class="flex justify-center">
                                                    <ToggleSwitch
                                                        :modelValue="pref.database"
                                                        @update:modelValue="toggleDatabase(idx, $event)"
                                                    />
                                                </div>
                                            </td>
                                            <td class="px-5 py-4">
                                                <div class="flex justify-center" :class="{ 'opacity-40 pointer-events-none': !pref.database }">
                                                    <ToggleSwitch
                                                        :modelValue="pref.push"
                                                        :disabled="!pref.database"
                                                        @update:modelValue="localPreferences[idx].push = $event"
                                                    />
                                                </div>
                                            </td>
                                            <td class="px-5 py-4">
                                                <div class="flex justify-center" :class="{ 'opacity-40 pointer-events-none': !pref.database }">
                                                    <ToggleSwitch
                                                        :modelValue="pref.mail"
                                                        :disabled="!pref.database"
                                                        @update:modelValue="localPreferences[idx].mail = $event"
                                                    />
                                                </div>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>

                            <!-- Info + Save -->
                            <div class="flex items-center justify-between border-t border-gray-200 px-5 py-4 dark:border-gray-800">
                                <p class="text-xs text-gray-500 dark:text-gray-400">
                                    <i class="bx bx-info-circle mr-1"></i>
                                    {{ t('In-App must be enabled to use Push and E-mail channels.') }}
                                </p>
                                <button
                                    @click="savePreferences"
                                    :disabled="saving"
                                    class="rounded-lg bg-brand-500 px-5 py-2.5 text-sm font-medium text-white hover:bg-brand-600 disabled:opacity-50"
                                >
                                    {{ saving ? t('Saving...') : t('Save Preferences') }}
                                </button>
                            </div>
                        </div>
                    </template>

                    <!-- Tab: Push Devices -->
                    <template v-if="tab === 'devices'">
                        <!-- This Device -->
                        <div v-if="pushSupported" class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03] md:p-6">
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
                        <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03] md:p-6">
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
                    </template>
                </div>
            </AdminLayout>
        </SidebarProvider>
    </ThemeProvider>
</template>

<script setup lang="ts">
import { ref, onMounted } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import axios from 'axios';
import ThemeProvider from '@/Components/Layout/ThemeProvider.vue';
import SidebarProvider from '@/Components/Layout/SidebarProvider.vue';
import AdminLayout from '@/Components/Layout/AdminLayout.vue';
import PageBreadcrumb from '@/Components/Common/PageBreadcrumb.vue';
import Toast from '@/Components/UI/Toast.vue';
import ToggleSwitch from '@/Components/UI/ToggleSwitch.vue';
import { usePushSubscription } from '@/Composables/usePushSubscription';
import { useToast } from '@/Composables/useToast';
import { useI18n } from '@/Composables/useI18n';
import { formatDateTime } from '@/utils/dateTime';

interface NotificationPref {
    type: string;
    database: boolean;
    push: boolean;
    mail: boolean;
}

interface NotificationTypeInfo {
    value: string;
    label: string;
    icon: string;
    description: string;
}

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
    tab: 'preferences' | 'devices';
    preferences: NotificationPref[];
    types: NotificationTypeInfo[];
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

// Preferences tab state
const localPreferences = ref<NotificationPref[]>(JSON.parse(JSON.stringify(props.preferences)));
const saving = ref(false);

// Devices tab state
const devices = ref<PushDevice[]>([...props.subscriptions]);
const removing = ref<number | null>(null);

function typeInfo(typeValue: string): NotificationTypeInfo | undefined {
    return props.types.find((t) => t.value === typeValue);
}

function toggleDatabase(idx: number, value: boolean): void {
    localPreferences.value[idx].database = value;
    if (!value) {
        localPreferences.value[idx].push = false;
        localPreferences.value[idx].mail = false;
    }
}

async function savePreferences(): Promise<void> {
    saving.value = true;
    try {
        await axios.put(route('user.notification-settings.update'), {
            preferences: localPreferences.value,
        });
        addToast('success', t('Preferences saved.'));
    } catch (e: any) {
        addToast('error', e.response?.data?.message || t('Failed to save preferences.'));
    } finally {
        saving.value = false;
    }
}

// Push devices tab methods
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

    if (!pushError.value) {
        router.reload({
            only: ['subscriptions'],
            onSuccess: () => {
                devices.value = [...(props.subscriptions ?? [])];
                markCurrentDevice();
            },
        });
    }
}

async function removeDevice(device: PushDevice): Promise<void> {
    removing.value = device.id;
    try {
        await axios.delete(route('user.notification-settings.destroy-device', { pushSubscription: device.id }));
        devices.value = devices.value.filter((d) => d.id !== device.id);
        addToast('success', t('Device removed.'));

        if (device.is_current) {
            pushSubscribed.value = false;
        }
    } catch (e: any) {
        addToast('error', e.response?.data?.message || t('Failed to remove device.'));
    } finally {
        removing.value = null;
    }
}

onMounted(() => {
    if (props.tab === 'devices') {
        markCurrentDevice();
    }
});
</script>

<style scoped>
@reference "../../../css/app.css";
</style>
