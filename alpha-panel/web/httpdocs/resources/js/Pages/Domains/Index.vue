<template>
    <Head :title="t('Domains')" />
    <ThemeProvider>
        <SidebarProvider>
            <AdminLayout>
                <PageBreadcrumb :pageTitle="t('Domains')" />
                <Toast />

                <div class="rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
                    <!-- Header -->
                    <div class="flex flex-col gap-4 px-5 py-4 sm:flex-row sm:items-center sm:justify-between md:px-6">
                        <div>
                            <h3 class="text-lg font-semibold text-gray-800 dark:text-white/90">
                                {{ t('All Domains') }}
                            </h3>
                        </div>
                        <div class="flex items-center gap-3">
                            <div class="relative">
                                <input
                                    v-model="searchInput"
                                    @input="table.setSearch(searchInput)"
                                    type="text"
                                    :placeholder="t('Search domains...')"
                                    class="h-10 w-full rounded-lg border border-gray-200 bg-transparent py-2 pl-10 pr-4 text-sm text-gray-800 shadow-theme-xs placeholder:text-gray-400 focus:border-brand-300 focus:outline-hidden focus:ring-3 focus:ring-brand-500/10 dark:border-gray-800 dark:bg-gray-900 dark:text-white/90 dark:placeholder:text-white/30 sm:w-64"
                                />
                                <svg
                                    class="absolute left-3 top-1/2 -translate-y-1/2 fill-gray-400"
                                    width="16"
                                    height="16"
                                    viewBox="0 0 20 20"
                                >
                                    <path
                                        fill-rule="evenodd"
                                        d="M3.04175 9.37363C3.04175 5.87693 5.87711 3.04199 9.37508 3.04199C12.8731 3.04199 15.7084 5.87693 15.7084 9.37363C15.7084 12.8703 12.8731 15.7053 9.37508 15.7053C5.87711 15.7053 3.04175 12.8703 3.04175 9.37363ZM9.37508 1.54199C5.04902 1.54199 1.54175 5.04817 1.54175 9.37363C1.54175 13.6991 5.04902 17.2053 9.37508 17.2053C11.2674 17.2053 13.003 16.5344 14.357 15.4176L17.177 18.238C17.4699 18.5309 17.9448 18.5309 18.2377 18.238C18.5306 17.9451 18.5306 17.4703 18.2377 17.1774L15.418 14.3573C16.5365 13.0033 17.2084 11.2669 17.2084 9.37363C17.2084 5.04817 13.7011 1.54199 9.37508 1.54199Z"
                                    />
                                </svg>
                            </div>
                            <button
                                type="button"
                                @click="showCreateDomainModal = true"
                                class="inline-flex items-center gap-2 rounded-lg bg-brand-500 px-4 py-2.5 text-sm font-medium text-white shadow-theme-xs hover:bg-brand-600"
                            >
                                <i class="bx bx-plus text-base"></i>
                                {{ t('Add Domain') }}
                            </button>
                        </div>
                    </div>

                    <!-- Table -->
                    <div class="overflow-x-auto">
                        <table class="w-full">
                            <thead>
                                <tr class="border-t border-gray-200 dark:border-gray-800">
                                    <th
                                        class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-500 dark:text-gray-400 md:px-6 cursor-pointer"
                                        @click="toggleSort(0)"
                                    >
                                        {{ t('Domain') }}
                                    </th>
                                    <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-500 dark:text-gray-400">
                                        {{ t('Type') }}
                                    </th>
                                    <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-500 dark:text-gray-400">
                                        {{ t('Status') }}
                                    </th>
                                    <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-500 dark:text-gray-400">
                                        {{ t('PHP') }}
                                    </th>
                                    <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-500 dark:text-gray-400">
                                        {{ t('Created') }}
                                    </th>
                                    <th class="px-5 py-3 text-right text-xs font-medium uppercase text-gray-500 dark:text-gray-400 md:px-6">
                                        {{ t('Actions') }}
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-if="table.loading.value" class="border-t border-gray-200 dark:border-gray-800">
                                    <td colspan="6" class="px-5 py-8 text-center text-gray-500 dark:text-gray-400">
                                        {{ t('Loading...') }}
                                    </td>
                                </tr>
                                <tr
                                    v-else-if="table.data.value.length === 0"
                                    class="border-t border-gray-200 dark:border-gray-800"
                                >
                                    <td colspan="6" class="px-5 py-8 text-center text-gray-500 dark:text-gray-400">
                                        {{ t('No domains found.') }}
                                    </td>
                                </tr>
                                <tr
                                    v-for="domain in table.data.value"
                                    :key="(domain.id as number)"
                                    class="border-t border-gray-200 hover:bg-gray-50 dark:border-gray-800 dark:hover:bg-white/[0.02]"
                                >
                                    <td class="px-5 py-4 md:px-6">
                                        <Link
                                            :href="(domain.show_url as string)"
                                            class="font-medium text-gray-800 hover:text-brand-500 dark:text-white/90"
                                        >
                                            {{ domain.fqdn }}
                                        </Link>
                                    </td>
                                    <td class="px-5 py-4" v-html="domain.type_badge"></td>
                                    <td class="px-5 py-4" v-html="domain.status_badge"></td>
                                    <td class="px-5 py-4 text-sm text-gray-600 dark:text-gray-400">
                                        {{ domain.php_version }}
                                    </td>
                                    <td class="px-5 py-4 text-sm text-gray-600 dark:text-gray-400">
                                        {{ domain.created_at }}
                                    </td>
                                    <td class="px-5 py-4 text-right md:px-6">
                                        <div class="flex items-center justify-end gap-2">
                                            <Link
                                                :href="(domain.show_url as string)"
                                                class="inline-flex h-8 w-8 items-center justify-center rounded-md bg-blue-light-500 text-white hover:bg-blue-light-600"
                                                :title="t('View')"
                                            >
                                                <i class="bx bx-show text-base"></i>
                                            </Link>
                                            <Link
                                                :href="(domain.edit_url as string)"
                                                class="inline-flex h-8 w-8 items-center justify-center rounded-md bg-warning-500 text-white hover:bg-warning-600"
                                                :title="t('Edit')"
                                            >
                                                <i class="bx bx-edit text-base"></i>
                                            </Link>
                                            <button
                                                @click="deleteDomain(domain)"
                                                class="inline-flex h-8 w-8 items-center justify-center rounded-md bg-error-500 text-white hover:bg-error-600"
                                                :title="t('Delete')"
                                            >
                                                <i class="bx bx-trash text-base"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <!-- Pagination -->
                    <div
                        v-if="table.totalPages.value > 1"
                        class="flex items-center justify-between border-t border-gray-200 px-5 py-4 dark:border-gray-800 md:px-6"
                    >
                        <p class="text-sm text-gray-500 dark:text-gray-400">
                            {{ t('Showing') }} {{ (table.currentPage.value - 1) * table.perPage.value + 1 }}
                            {{ t('to') }}
                            {{ Math.min(table.currentPage.value * table.perPage.value, table.recordsFiltered.value) }}
                            {{ t('of') }} {{ table.recordsFiltered.value }}
                        </p>
                        <div class="flex gap-2">
                            <button
                                v-for="page in table.totalPages.value"
                                :key="page"
                                @click="table.setPage(page)"
                                :class="[
                                    'h-8 w-8 rounded-lg text-sm font-medium',
                                    page === table.currentPage.value
                                        ? 'bg-brand-500 text-white'
                                        : 'text-gray-700 hover:bg-gray-100 dark:text-gray-400 dark:hover:bg-gray-800',
                                ]"
                            >
                                {{ page }}
                            </button>
                        </div>
                    </div>
                </div>

                <DomainCreateModal
                    v-model="showCreateDomainModal"
                    :php-versions="phpVersions"
                    :users="users"
                    :server-network-ips="server_network_ips"
                />
            </AdminLayout>
        </SidebarProvider>
    </ThemeProvider>
</template>

<script setup lang="ts">
import { ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import ThemeProvider from '@/Components/Layout/ThemeProvider.vue';
import SidebarProvider from '@/Components/Layout/SidebarProvider.vue';
import AdminLayout from '@/Components/Layout/AdminLayout.vue';
import PageBreadcrumb from '@/Components/Common/PageBreadcrumb.vue';
import Toast from '@/Components/UI/Toast.vue';
import { useDataTable } from '@/Composables/useDataTable';
import DomainCreateModal from '@/Components/Domains/DomainCreateModal.vue';
import { useI18n } from '@/Composables/useI18n';

defineProps<{
    phpVersions: Array<Record<string, any>>;
    users: Array<Record<string, any>>;
    server_network_ips: {
        public: string[];
        private: string[];
    };
}>();

const searchInput = ref('');
const showCreateDomainModal = ref(false);
const { t } = useI18n();

const table = useDataTable({
    url: route('domains.json'),
    columns: ['fqdn', 'type', 'status', 'php_version', 'worker', 'created_at', 'actions'],
    defaultOrderColumn: 5,
    defaultOrderDir: 'desc',
});

const toggleSort = (column: number) => {
    if (table.orderColumn.value === column) {
        table.setOrder(column, table.orderDir.value === 'asc' ? 'desc' : 'asc');
    } else {
        table.setOrder(column, 'asc');
    }
};

const deleteDomain = (domain: Record<string, unknown>) => {
    if (confirm(t('Are you sure you want to delete :fqdn?', { fqdn: String(domain.fqdn ?? '') }))) {
        router.delete(domain.destroy_url as string);
    }
};
</script>
