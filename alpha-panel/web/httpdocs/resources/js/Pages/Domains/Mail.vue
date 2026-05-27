<template>
    <Head :title="`${t('Mail')} - ${domain.fqdn}`" />
    <ThemeProvider>
        <SidebarProvider>
            <AdminLayout>
                <PageBreadcrumb
                    :pageTitle="t('Mail')"
                    :items="breadcrumbs"
                    :backHref="route('domains.show', domain.id)"
                />
                <Toast />

                <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/3 md:p-6">
                    <!-- Header -->
                    <div class="mb-5 flex items-center justify-between">
                        <div>
                            <h3 class="text-lg font-semibold text-gray-800 dark:text-white/90">{{ domain.fqdn }}</h3>
                            <p class="text-sm text-gray-500">{{ t('Provider') }}: <strong>{{ provider }}</strong></p>
                        </div>
                        <div class="flex items-center gap-2">
                            <a
                                v-if="webmailUrl"
                                :href="webmailUrl"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="inline-flex items-center gap-1.5 rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-gray-700"
                            >
                                <i class="bx bx-envelope-open text-base"></i>
                                {{ t('Webmail') }}
                                <i class="fa-solid fa-arrow-up-right-from-square text-xs opacity-60"></i>
                            </a>
                            <Link
                                v-if="activeTab === 'mailboxes' && !providerError"
                                :href="route('mail.mailboxes.create', domain.id)"
                                class="rounded-lg bg-brand-500 px-3 py-2 text-sm font-medium text-white hover:bg-brand-600"
                            >
                                {{ t('Create mailbox') }}
                            </Link>
                        </div>
                    </div>

                    <!-- Provider error -->
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

                    <!-- Tabs -->
                    <div class="mb-5 border-b border-gray-200 dark:border-gray-700">
                        <nav class="-mb-px flex gap-6">
                            <button
                                type="button"
                                class="border-b-2 pb-3 text-sm font-medium transition-colors"
                                :class="activeTab === 'mailboxes'
                                    ? 'border-brand-500 text-brand-600 dark:text-brand-400'
                                    : 'border-transparent text-gray-500 hover:text-gray-700 dark:hover:text-gray-300'"
                                @click="activeTab = 'mailboxes'"
                            >
                                <i class="bx bx-envelope mr-1.5"></i>
                                {{ t('Mailboxes') }}
                                <span class="ml-1.5 rounded-full bg-gray-100 px-2 py-0.5 text-xs font-semibold text-gray-600 dark:bg-gray-700 dark:text-gray-300">
                                    {{ mailboxes.length }}
                                </span>
                            </button>
                            <button
                                type="button"
                                class="border-b-2 pb-3 text-sm font-medium transition-colors"
                                :class="activeTab === 'aliases'
                                    ? 'border-brand-500 text-brand-600 dark:text-brand-400'
                                    : 'border-transparent text-gray-500 hover:text-gray-700 dark:hover:text-gray-300'"
                                @click="activeTab = 'aliases'"
                            >
                                <i class="bx bx-share-alt mr-1.5"></i>
                                {{ t('Aliases') }}
                                <span class="ml-1.5 rounded-full bg-gray-100 px-2 py-0.5 text-xs font-semibold text-gray-600 dark:bg-gray-700 dark:text-gray-300">
                                    {{ aliases.length }}
                                </span>
                            </button>
                        </nav>
                    </div>

                    <!-- Mailboxes Tab -->
                    <div v-show="activeTab === 'mailboxes'">
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
                                        <button class="text-red-500 hover:underline" @click="askDeleteMailbox(mailbox)">{{ t('Delete') }}</button>
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

                    <!-- Aliases Tab -->
                    <div v-show="activeTab === 'aliases'">
                        <form @submit.prevent="submitAlias" class="mb-6 flex flex-wrap items-end gap-3">
                            <div class="flex-1 min-w-40">
                                <label class="form-label">{{ t('Alias (local part)') }}</label>
                                <div class="flex">
                                    <input v-model="aliasForm.from_local_part" type="text" class="form-input rounded-r-none" required />
                                    <span class="inline-flex items-center rounded-r-md border border-l-0 border-gray-300 bg-gray-50 px-3 text-sm text-gray-500 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-400">
                                        @{{ domain.fqdn }}
                                    </span>
                                </div>
                            </div>
                            <div class="flex-1 min-w-40">
                                <label class="form-label">{{ t('Destination address') }}</label>
                                <input v-model="aliasForm.to_address" type="email" class="form-input" required />
                            </div>
                            <button
                                type="submit"
                                class="rounded-lg bg-brand-500 px-4 py-2 text-sm font-medium text-white hover:bg-brand-600 disabled:opacity-50"
                                :disabled="aliasFormProcessing"
                            >
                                {{ t('Add alias') }}
                            </button>
                        </form>

                        <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-800">
                            <thead>
                                <tr>
                                    <th class="px-4 py-2 text-left text-xs font-semibold uppercase text-gray-500">{{ t('From') }}</th>
                                    <th class="px-4 py-2 text-left text-xs font-semibold uppercase text-gray-500">{{ t('To') }}</th>
                                    <th class="px-4 py-2"></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200 dark:divide-gray-800">
                                <tr v-for="alias in aliases" :key="alias.address">
                                    <td class="px-4 py-3 font-medium">{{ alias.address }}</td>
                                    <td class="px-4 py-3 text-sm text-gray-500">{{ alias.destination }}</td>
                                    <td class="px-4 py-3 text-right">
                                        <button class="text-red-500 hover:underline" @click="askDeleteAlias(alias)">{{ t('Delete') }}</button>
                                    </td>
                                </tr>
                                <tr v-if="aliases.length === 0">
                                    <td colspan="3" class="px-4 py-6 text-center text-sm text-gray-500">
                                        {{ t('No aliases yet.') }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Mailbox Edit Modal -->
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
                                        <div class="relative">
                                            <input
                                                v-model="editForm.new_password"
                                                :type="showEditPassword ? 'text' : 'password'"
                                                class="form-input pr-28"
                                                :placeholder="t('Leave blank to keep current')"
                                                autocomplete="new-password"
                                            />
                                            <div class="absolute inset-y-0 right-1 flex items-center gap-1">
                                                <button type="button" class="pwd-icon-btn" @click="showEditPassword = !showEditPassword" :title="t('Show/Hide')">
                                                    <i :class="showEditPassword ? 'bx bx-show' : 'bx bx-hide'"></i>
                                                </button>
                                                <button type="button" class="pwd-icon-btn" @click="generateEditPassword" :title="t('Generate')">
                                                    <i class="bx bx-refresh"></i>
                                                </button>
                                                <button
                                                    v-if="editGeneratedPassword"
                                                    type="button"
                                                    class="pwd-icon-btn"
                                                    :class="{ 'pwd-icon-btn-success': editCopiedPassword }"
                                                    @click="copyEditPassword"
                                                    :title="t('Copy')"
                                                >
                                                    <i :class="editCopiedPassword ? 'bx bx-check' : 'bx bx-copy'"></i>
                                                </button>
                                            </div>
                                        </div>
                                        <p v-if="editGeneratedPassword && !editCopiedPassword" class="mt-1 text-xs text-warning-500">
                                            <i class="bx bx-info-circle mr-1"></i>
                                            {{ t('Copy the password before submitting.') }}
                                        </p>
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
                    v-model="deleteMailboxDialogOpen"
                    :title="t('Delete mailbox?')"
                    :message="deleteMailboxTarget ? t('This will permanently delete :addr. This action cannot be undone.', { addr: deleteMailboxTarget.address }) : ''"
                    :confirm-label="t('Yes, delete it')"
                    :cancel-label="t('Cancel')"
                    variant="danger"
                    @confirm="confirmDeleteMailbox"
                />

                <ConfirmDialog
                    v-model="deleteAliasDialogOpen"
                    :title="t('Delete alias?')"
                    :message="deleteAliasTarget ? t('This will permanently delete the alias :addr.', { addr: deleteAliasTarget.address }) : ''"
                    :confirm-label="t('Yes, delete it')"
                    :cancel-label="t('Cancel')"
                    variant="danger"
                    @confirm="confirmDeleteAlias"
                />
            </AdminLayout>
        </SidebarProvider>
    </ThemeProvider>
</template>

<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import axios from 'axios';
import ThemeProvider from '@/Components/Layout/ThemeProvider.vue';
import SidebarProvider from '@/Components/Layout/SidebarProvider.vue';
import AdminLayout from '@/Components/Layout/AdminLayout.vue';
import PageBreadcrumb from '@/Components/Common/PageBreadcrumb.vue';
import Toast from '@/Components/UI/Toast.vue';
import ConfirmDialog from '@/Components/UI/ConfirmDialog.vue';
import { useI18n } from '@/Composables/useI18n';
import { loadSweetAlert } from '@/utils/sweetalert';

const { t } = useI18n();
const props = defineProps({
    domain: { type: Object, required: true },
    provider: { type: String, required: true },
    mailboxes: { type: Array, required: true },
    aliases: { type: Array, required: true },
    provider_error: { type: String, default: null },
    webmail_url: { type: String, default: null },
});

const providerError = computed(() => props.provider_error);
const webmailUrl = computed(() => props.webmail_url);

const breadcrumbs = computed(() => [
    { label: t('Domains'), href: route('domains.index') },
    { label: props.domain.fqdn, href: route('domains.show', props.domain.id) },
    { label: t('Mail') },
]);

const activeTab = ref('mailboxes');

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

// ── Mailbox edit ──────────────────────────────────────────────
const editing = ref(null);
const saving = ref(false);
const editForm = ref({ display_name: '', quota_bytes: 0, active: true, new_password: '' });
const showEditPassword = ref(false);
const editGeneratedPassword = ref(false);
const editCopiedPassword = ref(false);

async function showError(message) {
    const swal = await loadSweetAlert();
    if (!swal) {
        return;
    }
    await swal.fire({
        title: t('Error'),
        text: message,
        icon: 'error',
        confirmButtonText: t('OK'),
    });
}

function openEdit(mailbox) {
    if (!mailbox?.address || !mailbox.address.includes('@')) {
        showError(t('Invalid mailbox address.'));
        return;
    }
    editing.value = mailbox;
    editForm.value = {
        display_name: mailbox.display_name || '',
        quota_bytes: mailbox.quota_bytes || 0,
        active: mailbox.active,
        new_password: '',
    };
    showEditPassword.value = false;
    editGeneratedPassword.value = false;
    editCopiedPassword.value = false;
}

function closeEdit() {
    editing.value = null;
    saving.value = false;
    editGeneratedPassword.value = false;
    editCopiedPassword.value = false;
}

function generateEditPassword() {
    const chars = 'abcdefghijkmnopqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789!@#$%&*';
    const length = 20;
    const array = new Uint32Array(length);
    crypto.getRandomValues(array);

    let password = '';
    for (let index = 0; index < length; index += 1) {
        password += chars[array[index] % chars.length];
    }

    editForm.value.new_password = password;
    showEditPassword.value = true;
    editGeneratedPassword.value = true;
    editCopiedPassword.value = false;
}

async function copyEditPassword() {
    if (!editForm.value.new_password) {
        return;
    }
    try {
        await navigator.clipboard.writeText(editForm.value.new_password);
        editCopiedPassword.value = true;
    } catch {
        editCopiedPassword.value = false;
    }
}

async function saveEdit() {
    if (!editing.value) return;
    const address = editing.value.address || '';
    const localPart = address.includes('@') ? address.split('@')[0] : '';
    if (localPart === '') {
        await showError(t('Invalid mailbox address.'));
        return;
    }

    saving.value = true;

    const encodedLocal = encodeURIComponent(localPart);
    const updateUrl = `/mail/domains/${props.domain.id}/mailboxes/${encodedLocal}`;
    const passwordUrl = `/mail/domains/${props.domain.id}/mailboxes/${encodedLocal}/password`;

    // eslint-disable-next-line no-console
    console.log('[mailbox.saveEdit] PUT', updateUrl, 'domainId:', props.domain.id, 'localPart:', localPart);

    try {
        await axios.put(updateUrl, {
            local_part: localPart,
            display_name: editForm.value.display_name,
            quota_bytes: editForm.value.quota_bytes,
            active: editForm.value.active,
        });

        if (editForm.value.new_password) {
            await axios.post(passwordUrl, {
                local_part: localPart,
                password: editForm.value.new_password,
            });
        }

        closeEdit();
        router.reload({ only: ['mailboxes'] });
    } catch (e) {
        const msg = e?.response?.data?.message || e?.message || t('Save error');
        await showError(msg);
    } finally {
        saving.value = false;
    }
}

// ── Mailbox delete ────────────────────────────────────────────
const deleteMailboxDialogOpen = ref(false);
const deleteMailboxTarget = ref(null);

function askDeleteMailbox(mailbox) {
    deleteMailboxTarget.value = mailbox;
    deleteMailboxDialogOpen.value = true;
}

function confirmDeleteMailbox() {
    if (!deleteMailboxTarget.value) return;
    const localPart = deleteMailboxTarget.value.address.split('@')[0];
    router.delete(`/mail/domains/${props.domain.id}/mailboxes/${encodeURIComponent(localPart)}`, {
        preserveScroll: true,
        onFinish: () => { deleteMailboxTarget.value = null; },
    });
}

// ── Alias add ─────────────────────────────────────────────────
const aliasFormProcessing = ref(false);
const aliasForm = ref({ from_local_part: '', to_address: '' });

function submitAlias() {
    aliasFormProcessing.value = true;
    router.post(route('mail.aliases.store', props.domain.id), {
        from_local_part: aliasForm.value.from_local_part,
        to_address: aliasForm.value.to_address,
    }, {
        preserveScroll: true,
        onSuccess: () => { aliasForm.value = { from_local_part: '', to_address: '' }; },
        onFinish: () => { aliasFormProcessing.value = false; },
    });
}

// ── Alias delete ──────────────────────────────────────────────
const deleteAliasDialogOpen = ref(false);
const deleteAliasTarget = ref(null);

function askDeleteAlias(alias) {
    deleteAliasTarget.value = alias;
    deleteAliasDialogOpen.value = true;
}

function confirmDeleteAlias() {
    if (!deleteAliasTarget.value) return;
    const localPart = deleteAliasTarget.value.address.split('@')[0];
    router.delete(`/mail/domains/${props.domain.id}/aliases/${encodeURIComponent(localPart)}`, {
        preserveScroll: true,
        onFinish: () => { deleteAliasTarget.value = null; },
    });
}
</script>

<style scoped>
@reference "../../../css/app.css";

.form-input {
    @apply h-auto min-h-11 w-full rounded-lg border border-gray-300 bg-transparent px-4 py-2.5 text-sm text-gray-800 shadow-theme-xs placeholder:text-gray-400 focus:border-brand-300 focus:outline-hidden focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90 dark:placeholder:text-white/30 dark:focus:border-brand-800;
}
.form-label {
    @apply mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400;
}
.pwd-icon-btn {
    @apply inline-flex h-8 w-8 items-center justify-center rounded border border-gray-300 bg-white text-gray-600 hover:bg-gray-100 hover:text-gray-800 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-gray-700;
}
.pwd-icon-btn-success {
    @apply border-success-500 bg-success-500 text-white hover:bg-success-600 hover:text-white dark:border-success-500 dark:bg-success-500 dark:text-white;
}
</style>
