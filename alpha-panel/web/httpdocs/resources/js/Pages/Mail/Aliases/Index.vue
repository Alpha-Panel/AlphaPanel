<template>
    <Head :title="`${t('Aliases')} - ${domain.fqdn}`" />
    <ThemeProvider>
        <SidebarProvider>
            <AdminLayout>
                <PageBreadcrumb
                    :pageTitle="t('Aliases')"
                    :items="breadcrumbs"
                    :backHref="route('mail.index')"
                />
                <Toast />

                <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/3 md:p-6">
                    <form @submit.prevent="submit" class="mb-6 flex items-end gap-3">
                        <div class="flex-1">
                            <label class="form-label">{{ t('Alias (local part)') }}</label>
                            <div class="flex">
                                <input v-model="form.from_local_part" type="text" class="form-input rounded-r-none" required />
                                <span class="inline-flex items-center rounded-r-md border border-l-0 border-gray-300 bg-gray-50 px-3 text-sm text-gray-500">
                                    @{{ domain.fqdn }}
                                </span>
                            </div>
                        </div>
                        <div class="flex-1">
                            <label class="form-label">{{ t('Destination address') }}</label>
                            <input v-model="form.to_address" type="email" class="form-input" required />
                        </div>
                        <button
                            type="submit"
                            class="rounded-lg bg-brand-500 px-4 py-2 text-sm font-medium text-white hover:bg-brand-600"
                            :disabled="form.processing"
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
                                    <button class="text-red-500 hover:underline" @click="askDelete(alias)">{{ t('Delete') }}</button>
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

                <ConfirmDialog
                    v-model="deleteDialogOpen"
                    :title="t('Delete alias?')"
                    :message="deleteTarget ? t('This will permanently delete the alias :addr.', { addr: deleteTarget.address }) : ''"
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
import { Head, router, useForm } from '@inertiajs/vue3';
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
    aliases: { type: Array, required: true },
});

const breadcrumbs = computed(() => [
    { label: t('Domains'), href: route('domains.index') },
    { label: props.domain.fqdn, href: route('domains.show', props.domain.id) },
    { label: t('Aliases') },
]);

const form = useForm({
    from_local_part: '',
    to_address: '',
});

function submit() {
    form.post(route('mail.aliases.store', props.domain.id), {
        onSuccess: () => form.reset(),
    });
}

const deleteDialogOpen = ref(false);
const deleteTarget = ref(null);

function askDelete(alias) {
    deleteTarget.value = alias;
    deleteDialogOpen.value = true;
}

function confirmDelete() {
    if (!deleteTarget.value) return;
    const localPart = deleteTarget.value.address.split('@')[0];
    router.delete(route('mail.aliases.destroy', [props.domain.id, localPart]), {
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
