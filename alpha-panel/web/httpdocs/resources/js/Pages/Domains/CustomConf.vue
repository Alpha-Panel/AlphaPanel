<template>
    <Head :title="`${t('Custom Config')} - ${domain.fqdn}`" />
    <ThemeProvider>
        <SidebarProvider>
            <AdminLayout>
                <PageBreadcrumb
                    :pageTitle="t('Custom Config')"
                    :items="breadcrumbs"
                    :backHref="route('domains.show', domain.parent_domain_id ?? domain.id)"
                />

                <div class="space-y-4 md:space-y-6">
                    <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/3 md:p-6">
                        <h3 class="text-lg font-semibold text-gray-800 dark:text-white/90">
                            {{ t('Custom Caddy Configuration') }}
                        </h3>
                        <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">
                            {{ t('Custom Caddy directives imported into this domain\'s server block. The panel never overwrites this file.') }}
                        </p>
                    </div>

                    <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/3 md:p-6">
                        <form class="space-y-4" @submit.prevent="submit">
                            <textarea
                                v-model="form.content"
                                rows="24"
                                class="form-input font-mono text-xs"
                                :placeholder="placeholder"
                                spellcheck="false"
                            ></textarea>

                            <div class="flex items-center gap-3 pt-1">
                                <button
                                    type="submit"
                                    :disabled="form.processing"
                                    class="inline-flex items-center gap-2 rounded-lg bg-brand-500 px-4 py-2 text-sm font-medium text-white hover:bg-brand-600 disabled:opacity-60"
                                >
                                    <i v-if="form.processing" class="bx bx-loader-alt animate-spin text-sm"></i>
                                    {{ t('Save & Reload Caddy') }}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </AdminLayout>
        </SidebarProvider>
    </ThemeProvider>
</template>

<script setup lang="ts">
import { computed } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import AdminLayout from '@/Components/Layout/AdminLayout.vue';
import PageBreadcrumb from '@/Components/Common/PageBreadcrumb.vue';
import SidebarProvider from '@/Components/Layout/SidebarProvider.vue';
import ThemeProvider from '@/Components/Layout/ThemeProvider.vue';
import { useI18n } from '@/Composables/useI18n';

const props = defineProps<{
    domain: Record<string, any>;
    content: string;
}>();

const { t } = useI18n();
const domain = computed(() => props.domain);

const breadcrumbs = computed(() => [
    { label: t('Domains'), href: route('domains.index') },
    { label: domain.value.fqdn, href: route('domains.show', domain.value.parent_domain_id ?? domain.value.id) },
    { label: t('Custom Config') },
]);

const placeholder = `# header_up X-Custom-Header "value"\n# respond /ping 200`;

const form = useForm({
    content: props.content ?? '',
});

const submit = (): void => {
    form.put(route('domains.custom-conf.update', domain.value.id));
};
</script>

<style scoped>
@reference "../../../css/app.css";

.form-input {
    @apply h-11 w-full rounded-lg border border-gray-300 bg-transparent px-4 py-2.5 text-sm text-gray-800 shadow-theme-xs placeholder:text-gray-400 focus:border-brand-300 focus:outline-hidden focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90 dark:placeholder:text-white/30 dark:focus:border-brand-800;
}

textarea.form-input {
    @apply h-auto;
}
</style>
