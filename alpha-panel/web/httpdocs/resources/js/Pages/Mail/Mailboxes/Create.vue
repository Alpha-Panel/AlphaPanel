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
                            <span class="inline-flex items-center rounded-r-md border border-l-0 border-gray-300 bg-gray-50 px-3 text-sm text-gray-500 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-400">
                                @{{ domain.fqdn }}
                            </span>
                        </div>
                        <p v-if="form.errors.local_part" class="mt-1 text-sm text-red-500">{{ form.errors.local_part }}</p>
                    </div>

                    <div>
                        <label class="form-label">{{ t('Password') }}</label>
                        <div class="relative">
                            <input
                                v-model="form.password"
                                :type="showPassword ? 'text' : 'password'"
                                class="form-input pr-28"
                                minlength="8"
                                required
                            />
                            <div class="absolute inset-y-0 right-1 flex items-center gap-1">
                                <button type="button" class="pwd-icon-btn" @click="showPassword = !showPassword" :title="t('Show/Hide')">
                                    <i :class="showPassword ? 'bx bx-show' : 'bx bx-hide'"></i>
                                </button>
                                <button type="button" class="pwd-icon-btn" @click="generatePassword" :title="t('Generate')">
                                    <i class="bx bx-refresh"></i>
                                </button>
                                <button
                                    v-if="generatedPassword"
                                    type="button"
                                    class="pwd-icon-btn"
                                    :class="{ 'pwd-icon-btn-success': copiedPassword }"
                                    @click="copyPassword"
                                    :title="t('Copy')"
                                >
                                    <i :class="copiedPassword ? 'bx bx-check' : 'bx bx-copy'"></i>
                                </button>
                            </div>
                        </div>
                        <p v-if="generatedPassword && !copiedPassword" class="mt-1 text-xs text-warning-500">
                            <i class="bx bx-info-circle mr-1"></i>
                            {{ t('Copy the password before submitting.') }}
                        </p>
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
import { computed, ref } from 'vue';
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

const showPassword = ref(false);
const generatedPassword = ref(false);
const copiedPassword = ref(false);

function generatePassword() {
    const chars = 'abcdefghijkmnopqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789!@#$%&*';
    const length = 20;
    const array = new Uint32Array(length);
    crypto.getRandomValues(array);

    let password = '';
    for (let index = 0; index < length; index += 1) {
        password += chars[array[index] % chars.length];
    }

    form.password = password;
    showPassword.value = true;
    generatedPassword.value = true;
    copiedPassword.value = false;
}

async function copyPassword() {
    if (!form.password) {
        return;
    }

    try {
        await navigator.clipboard.writeText(form.password);
        copiedPassword.value = true;
    } catch {
        copiedPassword.value = false;
    }
}

function submit() {
    form.post(route('mail.mailboxes.store', props.domain.id), {
        onSuccess: () => {
            generatedPassword.value = false;
            copiedPassword.value = false;
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
.pwd-icon-btn {
    @apply inline-flex h-8 w-8 items-center justify-center rounded border border-gray-300 bg-white text-gray-600 hover:bg-gray-100 hover:text-gray-800 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-gray-700;
}
.pwd-icon-btn-success {
    @apply border-success-500 bg-success-500 text-white hover:bg-success-600 hover:text-white dark:border-success-500 dark:bg-success-500 dark:text-white;
}
</style>
