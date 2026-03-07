<template>
    <Head :title="`${t('ModSecurity')} - ${domain.fqdn}`" />
    <ThemeProvider>
        <SidebarProvider>
            <AdminLayout>
                <PageBreadcrumb
                    :pageTitle="t('ModSecurity')"
                    :items="breadcrumbs"
                    :backHref="route('domains.show', domain.parent_domain_id ?? domain.id)"
                />

                <div class="space-y-4 md:space-y-6">
                    <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03] md:p-6">
                        <h3 class="text-lg font-semibold text-gray-800 dark:text-white/90">
                            {{ t('ModSecurity') }}
                        </h3>
                        <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">
                            {{ t('Manage ModSecurity status and protection mode for this domain.') }}
                        </p>
                    </div>

                    <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03] md:p-6">
                        <form class="space-y-5" @submit.prevent="submit">
                            <label class="flex items-center gap-2">
                                <input v-model="form.modsecurity_enabled" type="checkbox" class="form-checkbox" />
                                <span class="text-sm text-gray-700 dark:text-gray-300">{{ t('Enable ModSecurity') }}</span>
                            </label>

                            <FormField
                                v-if="form.modsecurity_enabled"
                                :label="t('ModSecurity Mode')"
                                :error="form.errors.modsecurity_mode"
                            >
                                <select v-model="form.modsecurity_mode" class="form-input">
                                    <option value="active">{{ t('Active') }}</option>
                                    <option value="detection_only">{{ t('Detection Only') }}</option>
                                </select>
                            </FormField>

                            <div class="flex items-center gap-3 pt-2">
                                <button
                                    type="submit"
                                    :disabled="form.processing"
                                    class="inline-flex items-center gap-2 rounded-lg bg-brand-500 px-4 py-2.5 text-sm font-medium text-white shadow-theme-xs hover:bg-brand-600 disabled:opacity-50"
                                >
                                    {{ form.processing ? t('Saving...') : t('Save Changes') }}
                                </button>
                                <Link
                                    :href="route('domains.show', domain.parent_domain_id ?? domain.id)"
                                    class="inline-flex items-center gap-2 rounded-lg border border-gray-300 bg-white px-4 py-2.5 text-sm font-medium text-gray-700 shadow-theme-xs hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-400"
                                >
                                    {{ t('Back to Domain') }}
                                </Link>
                            </div>
                        </form>
                    </div>
                </div>
            </AdminLayout>
        </SidebarProvider>
    </ThemeProvider>
</template>

<script setup lang="ts">
import { computed, watch } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import ThemeProvider from '@/Components/Layout/ThemeProvider.vue';
import SidebarProvider from '@/Components/Layout/SidebarProvider.vue';
import AdminLayout from '@/Components/Layout/AdminLayout.vue';
import PageBreadcrumb from '@/Components/Common/PageBreadcrumb.vue';
import FormField from '@/Components/UI/FormField.vue';
import { useI18n } from '@/Composables/useI18n';

const props = defineProps<{
    domain: Record<string, any>;
}>();

const { t } = useI18n();
const domain = computed(() => props.domain);

const breadcrumbs = computed(() => [
    { label: t('Domains'), href: route('domains.index') },
    { label: domain.value.fqdn, href: route('domains.show', domain.value.parent_domain_id ?? domain.value.id) },
    { label: t('ModSecurity') },
]);

const form = useForm({
    _method: 'PUT',
    modsecurity_enabled: Boolean(domain.value.modsecurity_enabled),
    modsecurity_mode: (domain.value.modsecurity_mode ?? 'detection_only') as 'active' | 'detection_only' | null,
});

watch(() => form.modsecurity_enabled, (enabled) => {
    if (enabled) {
        if (form.modsecurity_mode !== 'active' && form.modsecurity_mode !== 'detection_only') {
            form.modsecurity_mode = 'detection_only';
        }

        return;
    }

    form.modsecurity_mode = null;
});

const submit = (): void => {
    if (!form.modsecurity_enabled) {
        form.modsecurity_mode = null;
    } else if (form.modsecurity_mode !== 'active' && form.modsecurity_mode !== 'detection_only') {
        form.modsecurity_mode = 'detection_only';
    }

    form.post(route('domains.modsecurity.update', domain.value.id));
};
</script>

<style scoped>
@reference "../../../css/app.css";

.form-input {
    @apply h-11 w-full rounded-lg border border-gray-300 bg-transparent px-4 py-2.5 text-sm text-gray-800 shadow-theme-xs placeholder:text-gray-400 focus:border-brand-300 focus:outline-hidden focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90 dark:placeholder:text-white/30 dark:focus:border-brand-800;
}

.form-checkbox {
    @apply h-4 w-4 rounded border-gray-300 text-brand-500 focus:ring-brand-500;
}
</style>
