<template>
    <Head :title="t('Webhooks')" />
    <ThemeProvider>
        <SidebarProvider>
            <AdminLayout>
                <PageBreadcrumb
                    :pageTitle="t('Webhooks')"
                    :items="breadcrumbs"
                    :backHref="route('settings.webhooks.index')"
                />
                <Toast />

                <div class="space-y-4 md:space-y-6">
                    <!-- Header card -->
                    <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/3 md:p-6">
                        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                            <div>
                                <h3 class="flex items-center gap-2 text-lg font-semibold text-gray-800 dark:text-white/90">
                                    <i class="bx bx-broadcast text-xl text-brand-500"></i>
                                    {{ t('Webhook Endpoints') }}
                                </h3>
                                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                                    {{ t('Receive real-time push events from AlphaPanel to your URL.') }}
                                </p>
                            </div>
                            <button
                                type="button"
                                class="inline-flex items-center gap-2 rounded-lg bg-brand-500 px-4 py-2.5 text-sm font-medium text-white shadow-theme-xs hover:bg-brand-600"
                                @click="openCreate"
                            >
                                <i class="bx bx-plus text-base"></i>
                                {{ t('Add Endpoint') }}
                            </button>
                        </div>
                    </div>

                    <!-- Empty state -->
                    <div
                        v-if="endpoints.length === 0"
                        class="rounded-2xl border border-gray-200 bg-white p-8 text-center dark:border-gray-800 dark:bg-white/3"
                    >
                        <i class="bx bx-broadcast text-3xl text-gray-400"></i>
                        <p class="mt-2 text-gray-500 dark:text-gray-400">{{ t('No webhook endpoints configured.') }}</p>
                    </div>

                    <!-- Endpoint list -->
                    <div
                        v-else
                        class="divide-y divide-gray-100 rounded-2xl border border-gray-200 bg-white dark:divide-gray-800 dark:border-gray-800 dark:bg-white/3"
                    >
                        <div v-for="ep in endpoints" :key="ep.id" class="p-5">
                            <div class="flex items-start justify-between gap-4">
                                <div class="flex-1 min-w-0">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <span class="font-semibold text-gray-800 dark:text-white/90">{{ ep.name }}</span>
                                        <span
                                            :class="ep.active ? 'bg-success-50 text-success-700 dark:bg-success-500/10 dark:text-success-400' : 'bg-gray-100 text-gray-500 dark:bg-gray-800 dark:text-gray-400'"
                                            class="rounded-full px-2 py-0.5 text-xs font-medium"
                                        >{{ ep.active ? t('Active') : t('Inactive') }}</span>
                                        <span
                                            v-if="ep.last_status_code"
                                            :class="ep.last_status_code < 300 ? 'text-success-600 dark:text-success-400' : 'text-error-600 dark:text-error-400'"
                                            class="text-xs font-mono"
                                        >HTTP {{ ep.last_status_code }}</span>
                                    </div>
                                    <div class="mt-1 truncate font-mono text-xs text-gray-500 dark:text-gray-400">{{ ep.url }}</div>
                                    <div class="mt-1.5 flex flex-wrap gap-1">
                                        <span
                                            v-for="event in ep.events"
                                            :key="event"
                                            class="rounded bg-gray-100 px-1.5 py-0.5 font-mono text-xs dark:bg-gray-800"
                                        >{{ event }}</span>
                                    </div>
                                    <div v-if="ep.last_triggered_at" class="mt-1 text-xs text-gray-400">
                                        {{ t('Last triggered') }}: {{ new Date(ep.last_triggered_at).toLocaleString() }}
                                    </div>
                                </div>
                                <div class="flex shrink-0 items-center gap-1.5">
                                    <button
                                        type="button"
                                        class="inline-flex h-8 items-center gap-1 rounded-lg border border-gray-200 px-3 text-xs text-gray-600 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-400 dark:hover:bg-gray-800"
                                        @click="sendTest(ep)"
                                    >
                                        <i class="bx bx-send text-sm"></i>
                                        {{ t('Test') }}
                                    </button>
                                    <button
                                        type="button"
                                        class="inline-flex h-8 w-8 items-center justify-center rounded-lg border border-gray-200 text-gray-600 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-400 dark:hover:bg-gray-800"
                                        :title="t('Edit')"
                                        @click="openEdit(ep)"
                                    >
                                        <i class="bx bx-edit text-sm"></i>
                                    </button>
                                    <button
                                        type="button"
                                        class="inline-flex h-8 w-8 items-center justify-center rounded-lg border border-gray-200 hover:bg-gray-50 dark:border-gray-700 dark:hover:bg-gray-800"
                                        :class="ep.active ? 'text-warning-600 dark:text-warning-400' : 'text-success-600 dark:text-success-400'"
                                        :title="ep.active ? t('Pause') : t('Resume')"
                                        @click="toggleActive(ep)"
                                    >
                                        <i :class="ep.active ? 'bx bx-pause' : 'bx bx-play'" class="text-sm"></i>
                                    </button>
                                    <button
                                        type="button"
                                        class="inline-flex h-8 w-8 items-center justify-center rounded-lg border border-error-500/40 text-error-500 hover:bg-error-500/10"
                                        :title="t('Delete')"
                                        @click="deleteEndpoint(ep)"
                                    >
                                        <i class="bx bx-trash text-sm"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Create/Edit Modal -->
                <div
                    v-if="showModal"
                    class="fixed inset-0 z-1200000 flex items-center justify-center bg-black/60 p-4 backdrop-blur-sm"
                >
                    <div class="w-full max-w-lg rounded-2xl border border-gray-200 bg-white shadow-theme-xl dark:border-gray-800 dark:bg-gray-900">
                        <div class="flex items-center justify-between border-b border-gray-200 px-5 py-4 dark:border-gray-800 md:px-6">
                            <h4 class="text-base font-semibold text-gray-800 dark:text-white/90">
                                {{ editingEndpoint ? t('Edit Endpoint') : t('Add Webhook Endpoint') }}
                            </h4>
                            <button
                                type="button"
                                class="text-2xl leading-none text-gray-400 hover:text-gray-700 dark:hover:text-gray-200"
                                @click="closeModal"
                            >
                                &times;
                            </button>
                        </div>
                        <div class="max-h-[80vh] overflow-y-auto p-5 md:p-6">
                            <form @submit.prevent="saveEndpoint" class="space-y-4">
                                <div>
                                    <label class="mb-1 block text-xs font-medium uppercase tracking-wide text-gray-500">{{ t('Name') }} *</label>
                                    <input v-model="form.name" type="text" class="form-input" :placeholder="t('e.g. AlphaCenter Production')" />
                                    <p v-if="formErrors.name" class="mt-1 text-xs text-error-500">{{ formErrors.name }}</p>
                                </div>
                                <div>
                                    <label class="mb-1 block text-xs font-medium uppercase tracking-wide text-gray-500">{{ t('URL') }} *</label>
                                    <input v-model="form.url" type="url" class="form-input" placeholder="https://..." />
                                    <p v-if="formErrors.url" class="mt-1 text-xs text-error-500">{{ formErrors.url }}</p>
                                </div>
                                <div>
                                    <label class="mb-1 block text-xs font-medium uppercase tracking-wide text-gray-500">
                                        {{ t('Secret') }} {{ editingEndpoint ? '' : '*' }}
                                    </label>
                                    <input
                                        v-model="form.secret"
                                        type="password"
                                        class="form-input"
                                        :placeholder="editingEndpoint ? t('Leave blank to keep existing') : t('Min 16 characters')"
                                    />
                                    <p v-if="formErrors.secret" class="mt-1 text-xs text-error-500">{{ formErrors.secret }}</p>
                                </div>
                                <div>
                                    <label class="mb-2 block text-xs font-medium uppercase tracking-wide text-gray-500">{{ t('Events') }}</label>
                                    <div class="grid grid-cols-2 gap-1.5 rounded-xl border border-gray-200 p-3 dark:border-gray-700">
                                        <label
                                            v-for="event in props.availableEvents"
                                            :key="event"
                                            class="flex cursor-pointer items-center gap-2 rounded py-0.5"
                                        >
                                            <input type="checkbox" :value="event" v-model="form.events" class="accent-brand-500" />
                                            <span class="font-mono text-xs text-gray-700 dark:text-gray-300">{{ event }}</span>
                                        </label>
                                    </div>
                                    <p v-if="formErrors.events" class="mt-1 text-xs text-error-500">{{ formErrors.events }}</p>
                                </div>
                                <div class="flex items-center justify-end gap-2 pt-1">
                                    <button
                                        type="button"
                                        class="rounded-lg border border-gray-300 px-4 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-400 dark:hover:bg-gray-800"
                                        @click="closeModal"
                                    >
                                        {{ t('Cancel') }}
                                    </button>
                                    <button
                                        type="submit"
                                        :disabled="saving"
                                        class="inline-flex h-10 items-center gap-1.5 rounded-lg bg-brand-500 px-4 text-sm font-medium text-white hover:bg-brand-600 disabled:opacity-50"
                                    >
                                        <i class="bx bx-save"></i>
                                        {{ saving ? '...' : t('Save') }}
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
import { ref, reactive } from 'vue';
import { Head } from '@inertiajs/vue3';
import axios from 'axios';
import ThemeProvider from '@/Components/Layout/ThemeProvider.vue';
import SidebarProvider from '@/Components/Layout/SidebarProvider.vue';
import AdminLayout from '@/Components/Layout/AdminLayout.vue';
import PageBreadcrumb from '@/Components/Common/PageBreadcrumb.vue';
import Toast from '@/Components/UI/Toast.vue';
import { useToast } from '@/Composables/useToast';
import { useI18n } from '@/Composables/useI18n';

const { t } = useI18n();
const { addToast } = useToast();

const breadcrumbs = [
    { label: t('Settings'), href: route('settings.webhooks.index') },
    { label: t('Webhooks') },
];

interface Endpoint {
    id: number;
    name: string;
    url: string;
    events: string[];
    active: boolean;
    last_triggered_at: string | null;
    last_status_code: number | null;
}

const props = defineProps<{
    endpoints: Endpoint[];
    availableEvents: string[];
}>();

const endpoints = ref<Endpoint[]>([...props.endpoints]);
const showModal = ref(false);
const editingEndpoint = ref<Endpoint | null>(null);
const saving = ref(false);

const form = reactive({ name: '', url: '', secret: '', events: [] as string[], active: true });
const formErrors = reactive<Record<string, string>>({});

function openCreate(): void {
    editingEndpoint.value = null;
    Object.assign(form, { name: '', url: '', secret: '', events: [], active: true });
    Object.keys(formErrors).forEach((k) => delete formErrors[k]);
    showModal.value = true;
}

function openEdit(ep: Endpoint): void {
    editingEndpoint.value = ep;
    Object.assign(form, { name: ep.name, url: ep.url, secret: '', events: [...ep.events], active: ep.active });
    Object.keys(formErrors).forEach((k) => delete formErrors[k]);
    showModal.value = true;
}

function closeModal(): void {
    showModal.value = false;
    editingEndpoint.value = null;
}

async function saveEndpoint(): Promise<void> {
    saving.value = true;
    Object.keys(formErrors).forEach((k) => delete formErrors[k]);
    try {
        const payload: Record<string, unknown> = {
            name: form.name,
            url: form.url,
            events: form.events,
            active: form.active,
        };
        if (form.secret) payload['secret'] = form.secret;

        if (editingEndpoint.value) {
            const res = await axios.put(`/settings/webhooks/${editingEndpoint.value.id}`, payload);
            const idx = endpoints.value.findIndex((e) => e.id === editingEndpoint.value!.id);
            if (idx !== -1) endpoints.value[idx] = { ...endpoints.value[idx], ...res.data.data };
            addToast('success', t('Endpoint updated.'));
        } else {
            const res = await axios.post('/settings/webhooks', payload);
            endpoints.value.push(res.data.data);
            addToast('success', t('Endpoint created.'));
        }
        closeModal();
    } catch (e: unknown) {
        const errors = (e as { response?: { data?: { errors?: Record<string, string[]> } } })?.response?.data?.errors ?? {};
        Object.entries(errors).forEach(([key, msgs]) => { formErrors[key] = Array.isArray(msgs) ? msgs[0] : String(msgs); });
    } finally {
        saving.value = false;
    }
}

async function sendTest(ep: Endpoint): Promise<void> {
    try {
        await axios.post(`/settings/webhooks/${ep.id}/test`);
        addToast('success', t('Test payload dispatched.'));
    } catch {
        addToast('error', t('Failed to send test.'));
    }
}

async function toggleActive(ep: Endpoint): Promise<void> {
    try {
        await axios.put(`/settings/webhooks/${ep.id}`, { active: !ep.active });
        const idx = endpoints.value.findIndex((e) => e.id === ep.id);
        if (idx !== -1) endpoints.value[idx].active = !ep.active;
    } catch {
        addToast('error', t('Failed to update endpoint.'));
    }
}

async function deleteEndpoint(ep: Endpoint): Promise<void> {
    if (!confirm(t('Delete webhook endpoint ":name"?', { name: ep.name }))) return;
    try {
        await axios.delete(`/settings/webhooks/${ep.id}`);
        endpoints.value = endpoints.value.filter((e) => e.id !== ep.id);
        addToast('success', t('Endpoint deleted.'));
    } catch {
        addToast('error', t('Failed to delete endpoint.'));
    }
}
</script>

<style scoped>
@reference "../../../css/app.css";

.form-input {
    @apply h-11 w-full rounded-lg border border-gray-300 bg-transparent px-4 py-2.5 text-sm text-gray-800 shadow-theme-xs placeholder:text-gray-400 focus:border-brand-300 focus:outline-hidden focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90 dark:placeholder:text-white/30 dark:focus:border-brand-800;
}
</style>
