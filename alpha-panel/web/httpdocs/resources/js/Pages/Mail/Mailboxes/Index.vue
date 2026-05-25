<template>
    <Head :title="`${t('Mailboxes')} - ${domain.fqdn}`" />
    <ThemeProvider>
        <SidebarProvider>
            <AdminLayout>
                <PageBreadcrumb
                    :pageTitle="t('Mailboxes')"
                    :items="breadcrumbs"
                    :backHref="route('domains.show', domain.id)"
                />
                <Toast />

                <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/3 md:p-6">
                    <div class="mb-4 flex items-center justify-between">
                        <div>
                            <h3 class="text-lg font-semibold text-gray-800 dark:text-white/90">{{ domain.fqdn }}</h3>
                            <p class="text-sm text-gray-500">{{ t('Provider') }}: <strong>{{ provider }}</strong></p>
                        </div>
                        <Link
                            v-if="!providerError"
                            :href="route('mail.mailboxes.create', domain.id)"
                            class="rounded-lg bg-brand-500 px-3 py-2 text-sm font-medium text-white hover:bg-brand-600"
                        >
                            {{ t('Create mailbox') }}
                        </Link>
                    </div>

                    <div
                        v-if="providerError"
                        class="mb-4 rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800 dark:border-amber-700 dark:bg-amber-900/20 dark:text-amber-200"
                    >
                        <p class="font-medium">{{ t('Mail provider unavailable') }}</p>
                        <p class="mt-1">{{ providerError }}</p>
                        <Link
                            :href="route('mail.settings.edit')"
                            class="mt-2 inline-block text-amber-700 hover:underline dark:text-amber-300"
                        >
                            {{ t('Open Mail Settings') }} →
                        </Link>
                    </div>

                    <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-800">
                        <thead>
                            <tr>
                                <th class="px-4 py-2 text-left text-xs font-semibold uppercase text-gray-500">{{ t('Address') }}</th>
                                <th class="px-4 py-2 text-left text-xs font-semibold uppercase text-gray-500">{{ t('Display name') }}</th>
                                <th class="px-4 py-2 text-left text-xs font-semibold uppercase text-gray-500">{{ t('Quota') }}</th>
                                <th class="px-4 py-2 text-left text-xs font-semibold uppercase text-gray-500">{{ t('Status') }}</th>
                                <th class="px-4 py-2"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 dark:divide-gray-800">
                            <tr v-for="mailbox in mailboxes" :key="mailbox.address">
                                <td class="px-4 py-3 font-medium">{{ mailbox.address }}</td>
                                <td class="px-4 py-3 text-sm text-gray-500">{{ mailbox.display_name || '—' }}</td>
                                <td class="px-4 py-3 text-sm text-gray-500">
                                    {{ mailbox.quota_used_bytes ? formatBytes(mailbox.quota_used_bytes) : '—' }} /
                                    {{ mailbox.quota_bytes ? formatBytes(mailbox.quota_bytes) : t('Unlimited') }}
                                </td>
                                <td class="px-4 py-3">
                                    <span
                                        class="rounded-full px-2 py-1 text-xs font-medium"
                                        :class="mailbox.active ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700'"
                                    >
                                        {{ mailbox.active ? t('Active') : t('Locked') }}
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-right space-x-3">
                                    <button class="text-brand-500 hover:underline" @click="openEdit(mailbox)">{{ t('Edit') }}</button>
                                    <button class="text-red-500 hover:underline" @click="askDelete(mailbox)">{{ t('Delete') }}</button>
                                </td>
                            </tr>
                            <tr v-if="mailboxes.length === 0">
                                <td colspan="5" class="px-4 py-6 text-center text-sm text-gray-500">
                                    {{ t('No mailboxes yet.') }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Edit Modal -->
                <Teleport to="body">
                    <Transition
                        enter-active-class="transition ease-out duration-150"
                        enter-from-class="opacity-0"
                        enter-to-class="opacity-100"
                        leave-active-class="transition ease-in duration-100"
                        leave-from-class="opacity-100"
                        leave-to-class="opacity-0"
                    >
                        <div
                            v-if="editing"
                            class="fixed inset-0 z-[9998] flex items-center justify-center bg-black/50 px-4"
                            @click.self="closeEdit"
                        >
                            <div class="w-full max-w-lg rounded-2xl border border-gray-200 bg-white p-6 shadow-2xl dark:border-gray-700 dark:bg-gray-900">
                                <h3 class="mb-4 text-lg font-semibold text-gray-900 dark:text-white">
                                    {{ t('Edit') }}: {{ editing?.address }}
                                </h3>

                                <form @submit.prevent="saveEdit" class="space-y-4">
                                    <div>
                                        <label class="form-label">{{ t('Display name') }}</label>
                                        <input v-model="editForm.display_name" type="text" class="form-input" />
                                    </div>

                                    <div>
                                        <label class="form-label">{{ t('Quota (bytes, 0 = unlimited)') }}</label>
                                        <input v-model.number="editForm.quota_bytes" type="number" min="0" class="form-input" />
                                    </div>

                                    <div class="flex items-center gap-2">
                                        <input id="mailbox-active" v-model="editForm.active" type="checkbox" />
                                        <label for="mailbox-active" class="text-sm text-gray-700 dark:text-gray-400">{{ t('Active') }}</label>
                                    </div>

                                    <div class="border-t border-gray-200 pt-4 dark:border-gray-700">
                                        <p class="mb-2 text-sm font-medium text-gray-700 dark:text-gray-400">{{ t('Change password (optional)') }}</p>
                                        <input
                                            v-model="editForm.new_password"
                                            type="password"
                                            class="form-input"
                                            :placeholder="t('Leave blank to keep current')"
                                            autocomplete="new-password"
                                        />
                                    </div>

                                    <div class="flex justify-end gap-2 pt-2">
                                        <button
                                            type="button"
                                            class="rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-gray-700"
                                            @click="closeEdit"
                                        >
                                            {{ t('Cancel') }}
                                        </button>
                                        <button
                                            type="submit"
                                            class="rounded-lg bg-brand-500 px-4 py-2 text-sm font-medium text-white hover:bg-brand-600 disabled:opacity-50"
                                            :disabled="saving"
                                        >
                                            {{ saving ? t('Saving...') : t('Save') }}
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </Transition>
                </Teleport>

                <ConfirmDialog
                    v-model="deleteDialogOpen"
                    :title="t('Delete mailbox?')"
                    :message="deleteTarget ? t('This will permanently delete :addr. This action cannot be undone.', { addr: deleteTarget.address }) : ''"
                    :confirm-label="t('Yes, delete it')"
                    :cancel-label="t('Cancel')"
                    variant="danger"
                    @confirm="confirmDelete"
                />
            </AdminLayout>
        </SidebarProvider>
    </ThemeProvider>
</template>

<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import axios from 'axios';
import ThemeProvider from '@/Components/Layout/ThemeProvider.vue';
import SidebarProvider from '@/Components/Layout/SidebarProvider.vue';
import AdminLayout from '@/Components/Layout/AdminLayout.vue';
import PageBreadcrumb from '@/Components/Common/PageBreadcrumb.vue';
import Toast from '@/Components/UI/Toast.vue';
import ConfirmDialog from '@/Components/UI/ConfirmDialog.vue';
import { useI18n } from '@/Composables/useI18n';

const { t } = useI18n();
const props = defineProps({
    domain: { type: Object, required: true },
    provider: { type: String, required: true },
    mailboxes: { type: Array, required: true },
    provider_error: { type: String, default: null },
});

const providerError = computed(() => props.provider_error);

const breadcrumbs = computed(() => [
    { label: t('Domains'), href: route('domains.index') },
    { label: props.domain.fqdn, href: route('domains.show', props.domain.id) },
    { label: t('Mailboxes') },
]);

function formatBytes(bytes) {
    if (!bytes) return '0';
    const units = ['B', 'KB', 'MB', 'GB', 'TB'];
    let i = 0;
    let n = bytes;
    while (n >= 1024 && i < units.length - 1) {
        n /= 1024;
        i++;
    }
    return `${n.toFixed(1)} ${units[i]}`;
}

// Edit modal
const editing = ref(null);
const saving = ref(false);
const editForm = ref({
    display_name: '',
    quota_bytes: 0,
    active: true,
    new_password: '',
});

function openEdit(mailbox) {
    editing.value = mailbox;
    editForm.value = {
        display_name: mailbox.display_name || '',
        quota_bytes: mailbox.quota_bytes || 0,
        active: mailbox.active,
        new_password: '',
    };
}

function closeEdit() {
    editing.value = null;
    saving.value = false;
}

async function saveEdit() {
    if (!editing.value) return;
    saving.value = true;
    const localPart = editing.value.address.split('@')[0];

    try {
        await axios.put(route('mail.mailboxes.update', [props.domain.id, localPart]), {
            display_name: editForm.value.display_name,
            quota_bytes: editForm.value.quota_bytes,
            active: editForm.value.active,
        });

        if (editForm.value.new_password) {
            await axios.post(route('mail.mailboxes.password', [props.domain.id, localPart]), {
                password: editForm.value.new_password,
            });
        }

        closeEdit();
        router.reload({ only: ['mailboxes'] });
    } catch (e) {
        const msg = e?.response?.data?.message || t('Save error');
        alert(msg);
    } finally {
        saving.value = false;
    }
}

// Delete confirm
const deleteDialogOpen = ref(false);
const deleteTarget = ref(null);

function askDelete(mailbox) {
    deleteTarget.value = mailbox;
    deleteDialogOpen.value = true;
}

function confirmDelete() {
    if (!deleteTarget.value) return;
    const localPart = deleteTarget.value.address.split('@')[0];
    router.delete(route('mail.mailboxes.destroy', [props.domain.id, localPart]), {
        preserveScroll: true,
        onFinish: () => {
            deleteTarget.value = null;
        },
    });
}
</script>

<style scoped>
@reference "../../../../css/app.css";

.form-input {
    @apply h-auto min-h-11 w-full rounded-lg border border-gray-300 bg-transparent px-4 py-2.5 text-sm text-gray-800 shadow-theme-xs placeholder:text-gray-400 focus:border-brand-300 focus:outline-hidden focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90 dark:placeholder:text-white/30 dark:focus:border-brand-800;
}
.form-label {
    @apply mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400;
}
</style>
