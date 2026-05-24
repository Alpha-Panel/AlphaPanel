<template>
    <Head :title="t('Mail Settings')" />
    <ThemeProvider>
        <SidebarProvider>
            <AdminLayout>
                <PageBreadcrumb
                    :pageTitle="t('Mail Settings')"
                    :items="breadcrumbs"
                    :backHref="route('mail.index')"
                />
                <Toast />

                <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/3 md:p-6">
                    <div class="mb-6 flex gap-3 border-b border-gray-200 dark:border-gray-800">
                        <button
                            v-for="tab in tabs"
                            :key="tab.key"
                            class="border-b-2 px-3 py-2 text-sm font-medium"
                            :class="active === tab.key ? 'border-brand-500 text-brand-500' : 'border-transparent text-gray-500 hover:text-gray-700'"
                            @click="active = tab.key"
                            :disabled="!tab.enabled"
                        >
                            {{ tab.label }}
                        </button>
                    </div>

                    <RelayPanel v-if="active === 'relay'" :relay="relay" />
                    <ZimbraPanel v-if="active === 'zimbra'" :zimbra="zimbra" />
                    <MailuPanel v-if="active === 'mailu'" :features="features" />
                </div>
            </AdminLayout>
        </SidebarProvider>
    </ThemeProvider>
</template>

<script setup>
import { computed, ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import ThemeProvider from '@/Components/Layout/ThemeProvider.vue';
import SidebarProvider from '@/Components/Layout/SidebarProvider.vue';
import AdminLayout from '@/Components/Layout/AdminLayout.vue';
import PageBreadcrumb from '@/Components/Common/PageBreadcrumb.vue';
import Toast from '@/Components/UI/Toast.vue';
import RelayPanel from './Relay.vue';
import ZimbraPanel from './Zimbra.vue';
import MailuPanel from './Mailu.vue';
import { useI18n } from '@/Composables/useI18n';

const { t } = useI18n();
const props = defineProps({
    features: { type: Object, required: true },
    relay: { type: Object, required: true },
    zimbra: { type: Object, required: true },
});

const tabs = computed(() => [
    { key: 'relay', label: t('Outbound Relay'), enabled: true },
    { key: 'zimbra', label: t('Zimbra'), enabled: true },
    { key: 'mailu', label: t('Mailu'), enabled: props.features.mailu },
]);

const active = ref(tabs.value[0].key);

const breadcrumbs = computed(() => [
    { label: t('Settings') },
    { label: t('Mail Settings') },
]);
</script>
