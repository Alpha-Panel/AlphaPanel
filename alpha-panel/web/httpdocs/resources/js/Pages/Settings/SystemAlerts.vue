<template>
    <Head :title="t('System Alerts')" />
    <ThemeProvider>
        <SidebarProvider>
            <AdminLayout>
                <PageBreadcrumb
                    :pageTitle="t('System Alerts')"
                    :items="breadcrumbs"
                />
                <Toast />

                <div class="space-y-6">
                    <!-- Settings Card -->
                    <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/3 md:p-6">
                        <h3 class="mb-5 text-lg font-semibold text-gray-800 dark:text-white/90">
                            <i class="fa-solid fa-bell mr-2 text-brand-500"></i>
                            {{ t('System Alerts') }}
                        </h3>

                        <form @submit.prevent="submit" class="space-y-6">
                            <!-- Section 1: Enable/Disable -->
                            <div>
                                <label class="inline-flex items-center gap-3 cursor-pointer">
                                    <input
                                        v-model="form.enabled"
                                        type="checkbox"
                                        class="h-5 w-5 rounded border-gray-300 text-brand-500 focus:ring-brand-500 dark:border-gray-700 dark:bg-gray-900"
                                    />
                                    <div>
                                        <span class="text-sm font-medium text-gray-800 dark:text-white/90">{{ t('Enable System Health Monitoring') }}</span>
                                        <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">{{ t('Monitors CPU, RAM, and disk usage at regular intervals and sends notifications when thresholds are exceeded') }}</p>
                                    </div>
                                </label>
                            </div>

                            <!-- Section 2: Thresholds -->
                            <div v-if="form.enabled" class="border-t border-gray-200 pt-5 dark:border-gray-800">
                                <h4 class="mb-4 flex items-center gap-2 text-sm font-semibold text-gray-800 dark:text-white/90">
                                    <i class="fa-solid fa-sliders text-base text-brand-500"></i>
                                    {{ t('Thresholds') }}
                                </h4>
                                <div class="grid grid-cols-1 gap-4 lg:grid-cols-3">
                                    <!-- CPU Thresholds -->
                                    <div class="rounded-xl border border-gray-200 p-4 dark:border-gray-800">
                                        <div class="mb-3 flex items-center gap-2">
                                            <i class="fa-solid fa-microchip text-base text-brand-500"></i>
                                            <span class="text-sm font-semibold text-gray-800 dark:text-white/90">{{ t('CPU Thresholds') }}</span>
                                        </div>
                                        <div class="space-y-3">
                                            <FormField :label="t('Warning')" :error="form.errors.cpu_warning">
                                                <div class="relative">
                                                    <div class="pointer-events-none absolute inset-y-0 left-0 w-1 rounded-l-lg bg-amber-400"></div>
                                                    <input v-model.number="form.cpu_warning" type="number" min="0" max="100" class="form-input pl-4" />
                                                    <span class="pointer-events-none absolute inset-y-0 right-3 flex items-center text-sm text-gray-400">%</span>
                                                </div>
                                            </FormField>
                                            <FormField :label="t('Critical')" :error="form.errors.cpu_critical">
                                                <div class="relative">
                                                    <div class="pointer-events-none absolute inset-y-0 left-0 w-1 rounded-l-lg bg-red-500"></div>
                                                    <input v-model.number="form.cpu_critical" type="number" min="0" max="100" class="form-input pl-4" />
                                                    <span class="pointer-events-none absolute inset-y-0 right-3 flex items-center text-sm text-gray-400">%</span>
                                                </div>
                                            </FormField>
                                        </div>
                                    </div>

                                    <!-- RAM Thresholds -->
                                    <div class="rounded-xl border border-gray-200 p-4 dark:border-gray-800">
                                        <div class="mb-3 flex items-center gap-2">
                                            <i class="fa-solid fa-memory text-base text-brand-500"></i>
                                            <span class="text-sm font-semibold text-gray-800 dark:text-white/90">{{ t('RAM Thresholds') }}</span>
                                        </div>
                                        <div class="space-y-3">
                                            <FormField :label="t('Warning')" :error="form.errors.ram_warning">
                                                <div class="relative">
                                                    <div class="pointer-events-none absolute inset-y-0 left-0 w-1 rounded-l-lg bg-amber-400"></div>
                                                    <input v-model.number="form.ram_warning" type="number" min="0" max="100" class="form-input pl-4" />
                                                    <span class="pointer-events-none absolute inset-y-0 right-3 flex items-center text-sm text-gray-400">%</span>
                                                </div>
                                            </FormField>
                                            <FormField :label="t('Critical')" :error="form.errors.ram_critical">
                                                <div class="relative">
                                                    <div class="pointer-events-none absolute inset-y-0 left-0 w-1 rounded-l-lg bg-red-500"></div>
                                                    <input v-model.number="form.ram_critical" type="number" min="0" max="100" class="form-input pl-4" />
                                                    <span class="pointer-events-none absolute inset-y-0 right-3 flex items-center text-sm text-gray-400">%</span>
                                                </div>
                                            </FormField>
                                        </div>
                                    </div>

                                    <!-- Disk Thresholds -->
                                    <div class="rounded-xl border border-gray-200 p-4 dark:border-gray-800">
                                        <div class="mb-3 flex items-center gap-2">
                                            <i class="fa-solid fa-hard-drive text-base text-brand-500"></i>
                                            <span class="text-sm font-semibold text-gray-800 dark:text-white/90">{{ t('Disk Thresholds') }}</span>
                                        </div>
                                        <div class="space-y-3">
                                            <FormField :label="t('Warning')" :error="form.errors.disk_warning">
                                                <div class="relative">
                                                    <div class="pointer-events-none absolute inset-y-0 left-0 w-1 rounded-l-lg bg-amber-400"></div>
                                                    <input v-model.number="form.disk_warning" type="number" min="0" max="100" class="form-input pl-4" />
                                                    <span class="pointer-events-none absolute inset-y-0 right-3 flex items-center text-sm text-gray-400">%</span>
                                                </div>
                                            </FormField>
                                            <FormField :label="t('Critical')" :error="form.errors.disk_critical">
                                                <div class="relative">
                                                    <div class="pointer-events-none absolute inset-y-0 left-0 w-1 rounded-l-lg bg-red-500"></div>
                                                    <input v-model.number="form.disk_critical" type="number" min="0" max="100" class="form-input pl-4" />
                                                    <span class="pointer-events-none absolute inset-y-0 right-3 flex items-center text-sm text-gray-400">%</span>
                                                </div>
                                            </FormField>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Section 3: Monitoring Settings -->
                            <div v-if="form.enabled" class="border-t border-gray-200 pt-5 dark:border-gray-800">
                                <h4 class="mb-3 flex items-center gap-2 text-sm font-semibold text-gray-800 dark:text-white/90">
                                    <i class="fa-solid fa-gauge text-base text-brand-500"></i>
                                    {{ t('Monitoring Settings') }}
                                </h4>
                                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                    <FormField :label="t('Check Interval (minutes)')" :error="form.errors.check_interval">
                                        <input v-model.number="form.check_interval" type="number" min="1" class="form-input" />
                                    </FormField>

                                    <FormField :label="t('Cooldown (minutes)')" :error="form.errors.cooldown_minutes">
                                        <input v-model.number="form.cooldown_minutes" type="number" min="0" class="form-input" />
                                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ t('Minimum time between repeated notifications for the same metric') }}</p>
                                    </FormField>
                                </div>
                            </div>

                            <!-- Save & Run Now Buttons -->
                            <div class="flex flex-wrap items-center gap-3 border-t border-gray-200 pt-5 dark:border-gray-800">
                                <button
                                    type="submit"
                                    :disabled="form.processing"
                                    class="inline-flex items-center gap-2 rounded-lg bg-brand-500 px-5 py-2.5 text-sm font-medium text-white shadow-theme-xs hover:bg-brand-600 disabled:opacity-50"
                                >
                                    <i v-if="form.processing" class="fa-solid fa-spinner animate-spin text-base"></i>
                                    {{ form.processing ? t('Saving...') : t('Save Settings') }}
                                </button>

                                <button
                                    type="button"
                                    :disabled="runCheckForm.processing || !form.enabled"
                                    @click="runHealthCheck"
                                    class="inline-flex items-center gap-2 rounded-lg border border-gray-300 bg-white px-5 py-2.5 text-sm font-medium text-gray-700 shadow-theme-xs hover:bg-gray-50 disabled:opacity-50 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 dark:hover:bg-gray-800"
                                    :title="!form.enabled ? t('Enable monitoring first') : ''"
                                >
                                    <i v-if="runCheckForm.processing" class="fa-solid fa-spinner animate-spin text-base"></i>
                                    <i v-else class="fa-solid fa-play text-base"></i>
                                    {{ runCheckForm.processing ? t('Running...') : t('Run Check Now') }}
                                </button>
                            </div>
                        </form>
                    </div>

                    <!-- Section 4: Alert History -->
                    <div v-if="alerts.length > 0" class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/3 md:p-6">
                        <h3 class="mb-5 text-lg font-semibold text-gray-800 dark:text-white/90">
                            <i class="fa-solid fa-clock-rotate-left mr-2 text-brand-500"></i>
                            {{ t('Recent Alerts') }}
                        </h3>

                        <div class="overflow-x-auto">
                            <table class="w-full text-sm">
                                <thead>
                                    <tr class="border-b border-gray-200 text-left text-xs uppercase text-gray-500 dark:border-gray-800 dark:text-gray-400">
                                        <th class="pb-2">{{ t('Metric') }}</th>
                                        <th class="pb-2">{{ t('Level') }}</th>
                                        <th class="pb-2">{{ t('Value') }}</th>
                                        <th class="pb-2">{{ t('Threshold') }}</th>
                                        <th class="pb-2">{{ t('Time') }}</th>
                                        <th class="pb-2">{{ t('Status') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr
                                        v-for="alert in alerts"
                                        :key="alert.id"
                                        class="border-b border-gray-100 last:border-0 dark:border-gray-800/50"
                                    >
                                        <!-- Metric -->
                                        <td class="py-2.5">
                                            <span class="inline-flex items-center gap-1.5 text-xs font-medium text-gray-700 dark:text-gray-300">
                                                <i :class="metricIcon(alert.metric)" class="text-brand-500"></i>
                                                {{ alert.metric.toUpperCase() }}
                                            </span>
                                        </td>

                                        <!-- Level -->
                                        <td class="py-2.5">
                                            <span
                                                :class="levelBadgeClass(alert.level)"
                                                class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium"
                                            >
                                                {{ t(alert.level.charAt(0).toUpperCase() + alert.level.slice(1)) }}
                                            </span>
                                        </td>

                                        <!-- Value -->
                                        <td class="py-2.5 text-xs text-gray-700 dark:text-gray-300">
                                            {{ alert.value }}%
                                        </td>

                                        <!-- Threshold -->
                                        <td class="py-2.5 text-xs text-gray-500 dark:text-gray-400">
                                            {{ alert.threshold }}%
                                        </td>

                                        <!-- Time -->
                                        <td class="py-2.5 text-xs text-gray-500 dark:text-gray-400">
                                            {{ formatDate(alert.created_at) }}
                                        </td>

                                        <!-- Status -->
                                        <td class="py-2.5">
                                            <span
                                                v-if="alert.resolved_at"
                                                class="inline-flex items-center rounded-full bg-green-100 px-2 py-0.5 text-xs font-medium text-green-700 dark:bg-green-900/30 dark:text-green-400"
                                                :title="t('Resolved at') + ' ' + formatDate(alert.resolved_at) + ' (' + alert.resolved_value + '%)'"
                                            >
                                                {{ t('Resolved') }}
                                            </span>
                                            <span
                                                v-else
                                                :class="activeBadgeClass(alert.level)"
                                                class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium"
                                            >
                                                <span class="relative mr-1.5 flex h-2 w-2">
                                                    <span :class="activePulseClass(alert.level)" class="absolute inline-flex h-full w-full animate-ping rounded-full opacity-75"></span>
                                                    <span :class="activeDotClass(alert.level)" class="relative inline-flex h-2 w-2 rounded-full"></span>
                                                </span>
                                                {{ t('Active') }}
                                            </span>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Empty state for alerts -->
                    <div v-else class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/3 md:p-6">
                        <h3 class="mb-4 text-lg font-semibold text-gray-800 dark:text-white/90">
                            <i class="fa-solid fa-clock-rotate-left mr-2 text-brand-500"></i>
                            {{ t('Recent Alerts') }}
                        </h3>
                        <p class="text-sm text-gray-500 dark:text-gray-400">{{ t('No alerts recorded yet') }}</p>
                    </div>
                </div>
            </AdminLayout>
        </SidebarProvider>
    </ThemeProvider>
</template>

<script setup lang="ts">
import { computed } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import ThemeProvider from '@/Components/Layout/ThemeProvider.vue';
import SidebarProvider from '@/Components/Layout/SidebarProvider.vue';
import AdminLayout from '@/Components/Layout/AdminLayout.vue';
import PageBreadcrumb from '@/Components/Common/PageBreadcrumb.vue';
import FormField from '@/Components/UI/FormField.vue';
import Toast from '@/Components/UI/Toast.vue';
import { useI18n } from '@/Composables/useI18n';

interface AlertItem {
    id: number;
    metric: 'cpu' | 'ram' | 'disk';
    level: 'warning' | 'critical';
    value: number;
    threshold: number;
    resolved_at: string | null;
    resolved_value: number | null;
    notified_at: string | null;
    created_at: string;
}

interface Props {
    settings: {
        id: number;
        enabled: boolean;
        cpu_warning: number;
        cpu_critical: number;
        ram_warning: number;
        ram_critical: number;
        disk_warning: number;
        disk_critical: number;
        check_interval: number;
        cooldown_minutes: number;
    };
    alerts: AlertItem[];
}

const props = defineProps<Props>();

const { t } = useI18n();

const breadcrumbs = computed(() => [
    { label: t('Settings') },
    { label: t('System Alerts') },
]);

const form = useForm({
    _method: 'PUT' as const,
    enabled: props.settings.enabled,
    cpu_warning: props.settings.cpu_warning,
    cpu_critical: props.settings.cpu_critical,
    ram_warning: props.settings.ram_warning,
    ram_critical: props.settings.ram_critical,
    disk_warning: props.settings.disk_warning,
    disk_critical: props.settings.disk_critical,
    check_interval: props.settings.check_interval,
    cooldown_minutes: props.settings.cooldown_minutes,
});

const runCheckForm = useForm({});

const submit = (): void => {
    form.post(route('settings.alerts.update'), {
        preserveScroll: true,
    });
};

const runHealthCheck = (): void => {
    runCheckForm.post(route('settings.alerts.run-check'), {
        preserveScroll: true,
    });
};

const metricIcon = (metric: string): string => {
    const icons: Record<string, string> = {
        cpu: 'fa-solid fa-microchip',
        ram: 'fa-solid fa-memory',
        disk: 'fa-solid fa-hard-drive',
    };
    return icons[metric] ?? 'fa-solid fa-circle-question';
};

const levelBadgeClass = (level: string): string => {
    if (level === 'critical') {
        return 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400';
    }
    return 'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-400';
};

const activeBadgeClass = (level: string): string => {
    if (level === 'critical') {
        return 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400';
    }
    return 'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-400';
};

const activePulseClass = (level: string): string => {
    return level === 'critical' ? 'bg-red-400' : 'bg-amber-400';
};

const activeDotClass = (level: string): string => {
    return level === 'critical' ? 'bg-red-500' : 'bg-amber-500';
};

const formatDate = (dateString: string): string => {
    const date = new Date(dateString);
    return date.toLocaleString(undefined, {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
};
</script>

<style scoped>
@reference "../../../css/app.css";

.form-input {
    @apply h-11 w-full rounded-lg border border-gray-300 bg-transparent px-4 py-2.5 text-sm text-gray-800 shadow-theme-xs placeholder:text-gray-400 focus:border-brand-300 focus:outline-hidden focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90 dark:placeholder:text-white/30 dark:focus:border-brand-800;
}
</style>
