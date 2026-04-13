<template>
    <Head :title="`${t('Mail')} - ${domain.fqdn}`" />
    <ThemeProvider>
        <SidebarProvider>
            <AdminLayout>
                <PageBreadcrumb
                    :pageTitle="t('Mail')"
                    :items="breadcrumbs"
                    :backHref="route('mail.index')"
                />
                <Toast />

                <div class="space-y-4 md:space-y-6">
                    <!-- Domain header -->
                    <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/3 md:p-6">
                        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                            <h3 class="flex items-center gap-2 text-lg font-semibold text-gray-800 dark:text-white/90">
                                <i class="bx bx-envelope text-xl text-brand-500"></i>
                                {{ domain.fqdn }}
                            </h3>
                            <MailStatusBadge :active="mailDomain?.is_active ?? false" />
                        </div>
                    </div>

                    <!-- Tabs -->
                    <div class="border-b border-gray-200 dark:border-gray-800">
                        <nav class="-mb-px flex gap-4" aria-label="Tabs">
                            <button
                                v-for="tab in tabs"
                                :key="tab.key"
                                type="button"
                                @click="activeTab = tab.key"
                                class="whitespace-nowrap border-b-2 px-1 py-3 text-sm font-medium transition-colors"
                                :class="activeTab === tab.key
                                    ? 'border-brand-500 text-brand-600 dark:text-brand-400'
                                    : 'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-300'"
                            >
                                <i :class="tab.icon" class="mr-1.5"></i>
                                {{ tab.label }}
                            </button>
                        </nav>
                    </div>

                    <!-- Tab: Mailboxes -->
                    <div v-if="activeTab === 'mailboxes'" class="space-y-4">
                        <div class="flex items-center justify-end">
                            <button
                                type="button"
                                class="inline-flex items-center gap-2 rounded-lg bg-brand-500 px-4 py-2.5 text-sm font-medium text-white shadow-theme-xs hover:bg-brand-600"
                                @click="showCreateMailbox = true"
                            >
                                <i class="bx bx-plus text-base"></i>
                                {{ t('Create Mailbox') }}
                            </button>
                        </div>

                        <div
                            v-if="mailboxes.length === 0"
                            class="rounded-2xl border border-gray-200 bg-white p-8 text-center dark:border-gray-800 dark:bg-white/3"
                        >
                            <i class="bx bx-inbox text-3xl text-gray-400"></i>
                            <p class="mt-2 text-gray-500 dark:text-gray-400">{{ t('No mailboxes yet. Create one above.') }}</p>
                        </div>

                        <div
                            v-else
                            class="rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/3"
                        >
                            <div class="overflow-x-auto">
                                <table class="w-full">
                                    <thead>
                                        <tr class="border-b border-gray-200 dark:border-gray-800">
                                            <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-500 dark:text-gray-400 md:px-6">
                                                {{ t('Email') }}
                                            </th>
                                            <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-500 dark:text-gray-400">
                                                {{ t('Display Name') }}
                                            </th>
                                            <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-500 dark:text-gray-400">
                                                {{ t('Quota') }}
                                            </th>
                                            <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-500 dark:text-gray-400">
                                                {{ t('Last Login') }}
                                            </th>
                                            <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-500 dark:text-gray-400">
                                                {{ t('Status') }}
                                            </th>
                                            <th class="px-5 py-3 text-right text-xs font-medium uppercase text-gray-500 dark:text-gray-400 md:px-6">
                                                {{ t('Actions') }}
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr
                                            v-for="mailbox in mailboxes"
                                            :key="mailbox.id"
                                            class="border-t border-gray-200 hover:bg-gray-50 dark:border-gray-800 dark:hover:bg-white/2"
                                        >
                                            <td class="px-5 py-4 md:px-6">
                                                <span class="font-medium text-gray-800 dark:text-white/90">{{ mailbox.email }}</span>
                                            </td>
                                            <td class="px-5 py-4 text-sm text-gray-600 dark:text-gray-400">
                                                {{ mailbox.display_name || '-' }}
                                            </td>
                                            <td class="px-5 py-4" style="min-width: 180px;">
                                                <QuotaBar :used="mailbox.quota_used_mb ?? 0" :total="mailbox.quota_mb ?? 256" />
                                            </td>
                                            <td class="px-5 py-4 text-sm text-gray-600 dark:text-gray-400">
                                                {{ mailbox.last_login ?? t('Never') }}
                                            </td>
                                            <td class="px-5 py-4">
                                                <MailStatusBadge :active="mailbox.is_active ?? false" />
                                            </td>
                                            <td class="px-5 py-4 text-right md:px-6">
                                                <div class="flex items-center justify-end gap-1.5">
                                                    <button
                                                        type="button"
                                                        @click="openEditMailbox(mailbox)"
                                                        class="inline-flex h-7 w-7 items-center justify-center rounded-md bg-warning-500 text-white hover:bg-warning-600"
                                                        v-tooltip="t('Edit')"
                                                    >
                                                        <i class="bx bx-edit text-sm"></i>
                                                    </button>
                                                    <button
                                                        type="button"
                                                        @click="openPasswordMailbox(mailbox)"
                                                        class="inline-flex h-7 w-7 items-center justify-center rounded border border-warning-500/40 text-warning-600 hover:bg-warning-500/10 dark:text-warning-300"
                                                        v-tooltip="t('Change Password')"
                                                    >
                                                        <i class="bx bx-key text-sm"></i>
                                                    </button>
                                                    <button
                                                        type="button"
                                                        @click="deleteMailbox(mailbox)"
                                                        class="inline-flex h-7 w-7 items-center justify-center rounded-md bg-error-500 text-white hover:bg-error-600"
                                                        v-tooltip="t('Delete')"
                                                    >
                                                        <i class="bx bx-trash text-sm"></i>
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <!-- Tab: Aliases -->
                    <div v-if="activeTab === 'aliases'" class="space-y-4">
                        <div class="flex items-center justify-end">
                            <button
                                type="button"
                                class="inline-flex items-center gap-2 rounded-lg bg-brand-500 px-4 py-2.5 text-sm font-medium text-white shadow-theme-xs hover:bg-brand-600"
                                @click="showCreateAlias = true"
                            >
                                <i class="bx bx-plus text-base"></i>
                                {{ t('Create Alias') }}
                            </button>
                        </div>

                        <div
                            v-if="aliases.length === 0"
                            class="rounded-2xl border border-gray-200 bg-white p-8 text-center dark:border-gray-800 dark:bg-white/3"
                        >
                            <i class="bx bx-transfer text-3xl text-gray-400"></i>
                            <p class="mt-2 text-gray-500 dark:text-gray-400">{{ t('No aliases yet. Create one above.') }}</p>
                        </div>

                        <div
                            v-else
                            class="rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/3"
                        >
                            <div class="overflow-x-auto">
                                <table class="w-full">
                                    <thead>
                                        <tr class="border-b border-gray-200 dark:border-gray-800">
                                            <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-500 dark:text-gray-400 md:px-6">
                                                {{ t('Source') }}
                                            </th>
                                            <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-500 dark:text-gray-400">
                                                {{ t('Destination(s)') }}
                                            </th>
                                            <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-500 dark:text-gray-400">
                                                {{ t('Status') }}
                                            </th>
                                            <th class="px-5 py-3 text-right text-xs font-medium uppercase text-gray-500 dark:text-gray-400 md:px-6">
                                                {{ t('Actions') }}
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr
                                            v-for="alias in aliases"
                                            :key="alias.id"
                                            class="border-t border-gray-200 hover:bg-gray-50 dark:border-gray-800 dark:hover:bg-white/2"
                                        >
                                            <td class="px-5 py-4 md:px-6">
                                                <span class="font-medium text-gray-800 dark:text-white/90">{{ alias.address }}</span>
                                            </td>
                                            <td class="px-5 py-4 text-sm text-gray-600 dark:text-gray-400">
                                                <div class="flex flex-col gap-0.5">
                                                    <span
                                                        v-for="(dest, idx) in parseDestinations(alias.goto)"
                                                        :key="idx"
                                                    >
                                                        {{ dest }}
                                                    </span>
                                                </div>
                                            </td>
                                            <td class="px-5 py-4">
                                                <MailStatusBadge :active="alias.is_active ?? false" />
                                            </td>
                                            <td class="px-5 py-4 text-right md:px-6">
                                                <div class="flex items-center justify-end gap-1.5">
                                                    <button
                                                        type="button"
                                                        @click="openEditAlias(alias)"
                                                        class="inline-flex h-7 w-7 items-center justify-center rounded-md bg-warning-500 text-white hover:bg-warning-600"
                                                        v-tooltip="t('Edit')"
                                                    >
                                                        <i class="bx bx-edit text-sm"></i>
                                                    </button>
                                                    <button
                                                        type="button"
                                                        @click="deleteAlias(alias)"
                                                        class="inline-flex h-7 w-7 items-center justify-center rounded-md bg-error-500 text-white hover:bg-error-600"
                                                        v-tooltip="t('Delete')"
                                                    >
                                                        <i class="bx bx-trash text-sm"></i>
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <!-- Tab: Settings -->
                    <div v-if="activeTab === 'settings'" class="space-y-4">
                        <!-- DKIM Record -->
                        <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/3 md:p-6">
                            <h4 class="mb-4 flex items-center gap-2 text-base font-semibold text-gray-800 dark:text-white/90">
                                <i class="bx bx-shield text-lg text-brand-500"></i>
                                {{ t('DKIM Record') }}
                            </h4>
                            <DkimDisplay :record="dkimRecord ?? ''" :domain="domain.fqdn" />
                        </div>

                        <!-- Domain quota settings -->
                        <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/3 md:p-6">
                            <h4 class="mb-4 flex items-center gap-2 text-base font-semibold text-gray-800 dark:text-white/90">
                                <i class="bx bx-cog text-lg text-brand-500"></i>
                                {{ t('Domain Settings') }}
                            </h4>
                            <form @submit.prevent="saveSettings" class="space-y-4">
                                <FormField :label="t('Domain Quota (MB)')" :error="settingsForm.errors.quota_mb">
                                    <input
                                        v-model.number="settingsForm.quota_mb"
                                        type="number"
                                        min="0"
                                        class="form-input max-w-xs"
                                    />
                                </FormField>

                                <div class="flex items-center gap-3">
                                    <ToggleSwitch v-model="settingsForm.is_active" />
                                    <span class="text-sm text-gray-700 dark:text-gray-300">{{ t('Mail enabled for this domain') }}</span>
                                </div>

                                <div class="pt-2">
                                    <button
                                        type="submit"
                                        :disabled="settingsForm.processing"
                                        class="inline-flex items-center gap-2 rounded-lg bg-brand-500 px-4 py-2.5 text-sm font-medium text-white hover:bg-brand-600 disabled:opacity-50"
                                    >
                                        <i class="bx bx-save text-base"></i>
                                        {{ settingsForm.processing ? t('Saving...') : t('Save Settings') }}
                                    </button>
                                </div>
                            </form>
                        </div>

                        <!-- DNS records status -->
                        <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/3 md:p-6">
                            <h4 class="mb-4 flex items-center gap-2 text-base font-semibold text-gray-800 dark:text-white/90">
                                <i class="bx bx-globe text-lg text-brand-500"></i>
                                {{ t('DNS Records') }}
                            </h4>
                            <div class="space-y-2 text-sm text-gray-600 dark:text-gray-400">
                                <div class="flex items-center gap-2">
                                    <i class="bx bx-check-circle text-success-500"></i>
                                    <span>{{ t('MX Record: :domain', { domain: domain.fqdn }) }}</span>
                                </div>
                                <div class="flex items-center gap-2">
                                    <i :class="dkimRecord ? 'bx bx-check-circle text-success-500' : 'bx bx-x-circle text-error-500'"></i>
                                    <span>{{ t('DKIM Record') }}</span>
                                </div>
                                <div class="flex items-center gap-2">
                                    <i class="bx bx-minus-circle text-gray-400"></i>
                                    <span>{{ t('SPF Record') }}</span>
                                </div>
                                <div class="flex items-center gap-2">
                                    <i class="bx bx-minus-circle text-gray-400"></i>
                                    <span>{{ t('DMARC Record') }}</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Modals -->
                <MailboxCreateModal
                    v-model="showCreateMailbox"
                    :domain-id="domain.id"
                    :domain-name="domain.fqdn"
                    @created="reloadPage"
                />

                <MailboxEditModal
                    v-model="showEditMailbox"
                    :domain-id="domain.id"
                    :mailbox="selectedMailbox"
                    @updated="reloadPage"
                />

                <MailboxPasswordModal
                    v-model="showPasswordMailbox"
                    :domain-id="domain.id"
                    :mailbox="selectedMailbox"
                    @updated="reloadPage"
                />

                <AliasCreateModal
                    v-model="showCreateAlias"
                    :domain-id="domain.id"
                    :domain-name="domain.fqdn"
                    @created="reloadPage"
                />

                <AliasEditModal
                    v-model="showEditAlias"
                    :domain-id="domain.id"
                    :alias="selectedAlias"
                    @updated="reloadPage"
                />
            </AdminLayout>
        </SidebarProvider>
    </ThemeProvider>
</template>

<script setup lang="ts">
import { computed, ref } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import ThemeProvider from '@/Components/Layout/ThemeProvider.vue';
import SidebarProvider from '@/Components/Layout/SidebarProvider.vue';
import AdminLayout from '@/Components/Layout/AdminLayout.vue';
import PageBreadcrumb from '@/Components/Common/PageBreadcrumb.vue';
import Toast from '@/Components/UI/Toast.vue';
import FormField from '@/Components/UI/FormField.vue';
import ToggleSwitch from '@/Components/UI/ToggleSwitch.vue';
import QuotaBar from '@/Components/Mail/QuotaBar.vue';
import MailStatusBadge from '@/Components/Mail/MailStatusBadge.vue';
import DkimDisplay from '@/Components/Mail/DkimDisplay.vue';
import MailboxCreateModal from '@/Components/Mail/MailboxCreateModal.vue';
import MailboxEditModal from '@/Components/Mail/MailboxEditModal.vue';
import MailboxPasswordModal from '@/Components/Mail/MailboxPasswordModal.vue';
import AliasCreateModal from '@/Components/Mail/AliasCreateModal.vue';
import AliasEditModal from '@/Components/Mail/AliasEditModal.vue';
import { useI18n } from '@/Composables/useI18n';

const props = defineProps<{
    domain: Record<string, any>;
    mailDomain: Record<string, any> | null;
    mailboxes: Array<Record<string, any>>;
    aliases: Array<Record<string, any>>;
    dkimRecord: string | null;
}>();

const { t } = useI18n();

const breadcrumbs = computed(() => [
    { label: t('Mail Server'), href: route('mail.index') },
    { label: props.domain.fqdn },
]);

// Tabs
const tabs = computed(() => [
    { key: 'mailboxes', label: t('Mailboxes'), icon: 'bx bx-inbox' },
    { key: 'aliases', label: t('Aliases'), icon: 'bx bx-transfer' },
    { key: 'settings', label: t('Settings'), icon: 'bx bx-cog' },
]);

const activeTab = ref('mailboxes');

// Mailbox modals
const showCreateMailbox = ref(false);
const showEditMailbox = ref(false);
const showPasswordMailbox = ref(false);
const selectedMailbox = ref<Record<string, any> | null>(null);

const openEditMailbox = (mailbox: Record<string, any>): void => {
    selectedMailbox.value = mailbox;
    showEditMailbox.value = true;
};

const openPasswordMailbox = (mailbox: Record<string, any>): void => {
    selectedMailbox.value = mailbox;
    showPasswordMailbox.value = true;
};

const deleteMailbox = (mailbox: Record<string, any>): void => {
    if (confirm(t('Are you sure you want to delete :email?', { email: String(mailbox.email ?? '') }))) {
        router.delete(route('domains.mail.mailboxes.destroy', [props.domain.id, mailbox.id]), {
            preserveScroll: true,
        });
    }
};

// Alias modals
const showCreateAlias = ref(false);
const showEditAlias = ref(false);
const selectedAlias = ref<Record<string, any> | null>(null);

const openEditAlias = (alias: Record<string, any>): void => {
    selectedAlias.value = alias;
    showEditAlias.value = true;
};

const deleteAlias = (alias: Record<string, any>): void => {
    if (confirm(t('Are you sure you want to delete :address?', { address: String(alias.address ?? '') }))) {
        router.delete(route('domains.mail.aliases.destroy', [props.domain.id, alias.id]), {
            preserveScroll: true,
        });
    }
};

const parseDestinations = (goto: string | string[] | null): string[] => {
    if (!goto) {
        return [];
    }

    if (Array.isArray(goto)) {
        return goto;
    }

    return goto.split(/[,\n]/).map((s) => s.trim()).filter(Boolean);
};

// Settings form
const settingsForm = useForm({
    quota_mb: props.mailDomain?.quota_mb ?? 10240,
    is_active: props.mailDomain?.is_active ?? true,
});

const saveSettings = (): void => {
    settingsForm.put(route('domains.mail.settings.update', props.domain.id), {
        preserveScroll: true,
    });
};

const reloadPage = (): void => {
    router.reload();
};
</script>

<style scoped>
@reference "../../../css/app.css";

.form-input {
    @apply h-10 w-full rounded-lg border border-gray-300 bg-transparent px-3 py-2 text-sm text-gray-800 shadow-theme-xs focus:border-brand-300 focus:outline-hidden focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90;
}
</style>
