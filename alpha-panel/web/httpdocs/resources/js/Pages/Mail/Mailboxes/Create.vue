<template>
    <Head :title="`${t('New mailbox')} - ${domain.fqdn}`" />
    <ThemeProvider>
        <SidebarProvider>
            <AdminLayout>
                <PageBreadcrumb
                    :pageTitle="t('New mailbox')"
                    :items="breadcrumbs"
                    :backHref="route('mail.mailboxes.index', domain.id)"
                />
                <Toast />

                <form @submit.prevent="submit" class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/3 md:p-6 space-y-4">
                    <div>
                        <label class="form-label">{{ t('Local part') }}</label>
                        <div class="flex">
                            <input v-model="form.local_part" type="text" class="form-input rounded-r-none" required />
                            <span class="inline-flex items-center rounded-r-md border border-l-0 border-gray-300 bg-gray-50 px-3 text-sm text-gray-500">
                                @{{ domain.fqdn }}
                            </span>
                        </div>
                        <p v-if="form.errors.local_part" class="mt-1 text-sm text-red-500">{{ form.errors.local_part }}</p>
                    </div>

                    <div>
                        <label class="form-label">{{ t('Password') }}</label>
                        <input v-model="form.password" type="password" class="form-input" minlength="8" required />
                        <p v-if="form.errors.password" class="mt-1 text-sm text-red-500">{{ form.errors.password }}</p>
                    </div>

                    <div>
                        <label class="form-label">{{ t('Display name') }}</label>
                        <input v-model="form.display_name" type="text" class="form-input" />
                    </div>

                    <div>
                        <label class="form-label">{{ t('Quota (bytes, 0 = unlimited)') }}</label>
                        <input v-model.number="form.quota_bytes" type="number" min="0" class="form-input" />
                    </div>

                    <div class="flex items-center justify-end gap-3">
                        <Link :href="route('mail.mailboxes.index', domain.id)" class="text-sm text-gray-500 hover:underline">
                            {{ t('Cancel') }}
                        </Link>
                        <button
                            type="submit"
                            class="rounded-lg bg-brand-500 px-4 py-2 text-sm font-medium text-white hover:bg-brand-600"
                            :disabled="form.processing"
                        >
                            {{ t('Create') }}
                        </button>
                    </div>
                </form>
            </AdminLayout>
        </SidebarProvider>
    </ThemeProvider>
</template>

<script setup>
import { computed } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import ThemeProvider from '@/Components/Layout/ThemeProvider.vue';
import SidebarProvider from '@/Components/Layout/SidebarProvider.vue';
import AdminLayout from '@/Components/Layout/AdminLayout.vue';
import PageBreadcrumb from '@/Components/Common/PageBreadcrumb.vue';
import Toast from '@/Components/UI/Toast.vue';
import { useI18n } from '@/Composables/useI18n';

const { t } = useI18n();
const props = defineProps({
    domain: { type: Object, required: true },
    provider: { type: String, required: true },
});

const breadcrumbs = computed(() => [
    { label: t('Domains'), href: route('domains.index') },
    { label: props.domain.fqdn, href: route('domains.show', props.domain.id) },
    { label: t('Mailboxes'), href: route('mail.mailboxes.index', props.domain.id) },
    { label: t('New mailbox') },
]);

const form = useForm({
    local_part: '',
    password: '',
    display_name: '',
    quota_bytes: 0,
});

function submit() {
    form.post(route('mail.mailboxes.store', props.domain.id));
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
