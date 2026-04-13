<template>
    <Head :title="`${t('Sieve Filters')} - ${mailbox}`" />
    <ThemeProvider>
        <SidebarProvider>
            <AdminLayout>
                <PageBreadcrumb
                    :pageTitle="t('Sieve Filters')"
                    :items="breadcrumbs"
                    :backHref="route('domains.mail.index', domain.id)"
                />
                <Toast />

                <div class="space-y-4 md:space-y-6">
                    <!-- Header -->
                    <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/3 md:p-6">
                        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                            <h3 class="flex items-center gap-2 text-lg font-semibold text-gray-800 dark:text-white/90">
                                <i class="bx bx-filter text-xl text-brand-500"></i>
                                {{ t('Sieve Filter Editor') }}
                            </h3>
                            <span class="text-sm text-gray-500 dark:text-gray-400">{{ mailbox }}</span>
                        </div>
                    </div>

                    <!-- Editor -->
                    <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/3 md:p-6">
                        <form @submit.prevent="saveScript" class="space-y-4">
                            <div>
                                <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">
                                    {{ t('Sieve Script') }}
                                </label>
                                <textarea
                                    v-model="form.script"
                                    rows="18"
                                    class="w-full rounded-lg border border-gray-300 bg-gray-50 px-4 py-3 font-mono text-sm text-gray-800 shadow-theme-xs focus:border-brand-300 focus:outline-hidden focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90"
                                    :placeholder="sievePlaceholder"
                                    spellcheck="false"
                                />
                                <p v-if="form.errors.script" class="mt-1 text-sm text-error-500">{{ form.errors.script }}</p>
                            </div>

                            <div class="flex items-center justify-between">
                                <button
                                    type="submit"
                                    :disabled="form.processing"
                                    class="inline-flex items-center gap-2 rounded-lg bg-brand-500 px-4 py-2.5 text-sm font-medium text-white hover:bg-brand-600 disabled:opacity-50"
                                >
                                    <i class="bx bx-save text-base"></i>
                                    {{ form.processing ? t('Saving...') : t('Save Script') }}
                                </button>
                            </div>
                        </form>
                    </div>

                    <!-- Help -->
                    <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/3 md:p-6">
                        <h4 class="mb-3 flex items-center gap-2 text-base font-semibold text-gray-800 dark:text-white/90">
                            <i class="bx bx-help-circle text-lg text-brand-500"></i>
                            {{ t('Sieve Reference') }}
                        </h4>
                        <div class="space-y-3 text-sm text-gray-600 dark:text-gray-400">
                            <p>{{ t('Sieve is a scripting language for filtering email messages at the mail server level.') }}</p>
                            <div class="rounded-lg bg-gray-50 p-3 dark:bg-gray-800/50">
                                <p class="mb-1 text-xs font-medium uppercase text-gray-500 dark:text-gray-400">{{ t('Example: Redirect emails') }}</p>
                                <pre class="whitespace-pre-wrap font-mono text-xs text-gray-700 dark:text-gray-300">require "fileinto";
if header :contains "subject" "important" {
    fileinto "INBOX.Important";
}</pre>
                            </div>
                            <div class="rounded-lg bg-gray-50 p-3 dark:bg-gray-800/50">
                                <p class="mb-1 text-xs font-medium uppercase text-gray-500 dark:text-gray-400">{{ t('Example: Vacation auto-reply') }}</p>
                                <pre class="whitespace-pre-wrap font-mono text-xs text-gray-700 dark:text-gray-300">require "vacation";
vacation :days 7 :subject "Out of Office"
    "I am currently out of the office.";</pre>
                            </div>
                        </div>
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
import Toast from '@/Components/UI/Toast.vue';
import { useI18n } from '@/Composables/useI18n';

const props = defineProps<{
    domain: Record<string, any>;
    mailbox: string;
    script: string;
}>();

const { t } = useI18n();

const breadcrumbs = computed(() => [
    { label: t('Mail Server'), href: route('mail.index') },
    { label: props.domain.fqdn, href: route('domains.mail.index', props.domain.id) },
    { label: t('Sieve Filters') },
]);

const sievePlaceholder = `require "fileinto";\n\n# Your filter rules here`;

const form = useForm({
    script: props.script ?? '',
});

const saveScript = (): void => {
    form.put(route('domains.mail.sieve.update', [props.domain.id, props.mailbox]), {
        preserveScroll: true,
    });
};
</script>
