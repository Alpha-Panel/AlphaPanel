<template>
    <Head :title="`${t('Package Manager')} - ${domain.fqdn}`" />
    <ThemeProvider>
        <SidebarProvider>
            <AdminLayout>
                <PageBreadcrumb
                    :pageTitle="t('Package Manager')"
                    :items="breadcrumbs"
                    :backHref="route('domains.show', domain.id)"
                />
                <Toast />

                <div class="space-y-4 md:space-y-6">
                    <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/3 md:p-6">
                        <h3 class="text-lg font-semibold text-gray-800 dark:text-white/90">
                            {{ t('Package Manager') }}
                        </h3>
                        <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">
                            {{ t('Run package commands and inspect installed packages for this domain.') }}
                        </p>
                    </div>

                    <div class="grid grid-cols-1 gap-4 xl:grid-cols-2">
                        <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/3 md:p-6">
                            <div class="mb-4 flex items-center gap-2">
                                <i class="fa-brands fa-npm text-xl text-error-500"></i>
                                <h4 class="text-base font-semibold text-gray-800 dark:text-white/90">{{ t('NPM Manager') }}</h4>
                            </div>

                            <div class="mb-4 flex flex-wrap items-center gap-2">
                                <button
                                    type="button"
                                    :disabled="npmListingLoading || npmActionLoading !== null || composerActionLoading !== null"
                                    class="inline-flex h-9 items-center gap-1.5 rounded-lg border border-gray-300 bg-white px-3 text-sm text-gray-700 hover:bg-gray-50 disabled:opacity-60 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300"
                                    @click="fetchNpmPackages"
                                >
                                    <i :class="npmListingLoading ? 'bx bx-loader-alt animate-spin' : 'bx bx-refresh'" class="text-base"></i>
                                    {{ t('Refresh Packages') }}
                                </button>
                                <button
                                    type="button"
                                    :disabled="npmListingLoading || npmActionLoading !== null || composerActionLoading !== null"
                                    class="inline-flex h-9 items-center gap-1.5 rounded-lg bg-brand-500 px-3 text-sm font-medium text-white hover:bg-brand-600 disabled:opacity-60"
                                    @click="runNpmInstall"
                                >
                                    <i :class="npmActionLoading === 'install' ? 'bx bx-loader-alt animate-spin' : 'bx bx-download'" class="text-base"></i>
                                    {{ t('npm install') }}
                                </button>
                                <button
                                    type="button"
                                    :disabled="npmListingLoading || npmActionLoading !== null || composerActionLoading !== null"
                                    class="inline-flex h-9 items-center gap-1.5 rounded-lg bg-success-500 px-3 text-sm font-medium text-white hover:bg-success-600 disabled:opacity-60"
                                    @click="runNpmBuild"
                                >
                                    <i :class="npmActionLoading === 'build' ? 'bx bx-loader-alt animate-spin' : 'bx bx-play'" class="text-base"></i>
                                    {{ t('npm run build') }}
                                </button>
                                <button
                                    type="button"
                                    :disabled="npmListingLoading || npmActionLoading !== null || composerActionLoading !== null"
                                    class="inline-flex h-9 items-center gap-1.5 rounded-lg bg-warning-500 px-3 text-sm font-medium text-white hover:bg-warning-600 disabled:opacity-60"
                                    @click="runNpmAuditFix"
                                >
                                    <i :class="npmActionLoading === 'audit_fix' ? 'bx bx-loader-alt animate-spin' : 'bx bx-shield-quarter'" class="text-base"></i>
                                    {{ t('npm audit fix') }}
                                </button>
                            </div>

                            <div v-if="!npmHasPackageJson" class="rounded-lg border border-warning-500/30 bg-warning-500/10 px-3 py-2 text-sm text-warning-700 dark:text-warning-300">
                                {{ t('package.json not found in this domain path.') }}
                            </div>
                            <div v-else-if="npmPackages.length === 0" class="rounded-lg border border-gray-200 bg-gray-50 px-3 py-6 text-center text-sm text-gray-500 dark:border-gray-700 dark:bg-gray-900/30 dark:text-gray-400">
                                {{ npmListingLoading ? t('Loading package list...') : t('No NPM packages found.') }}
                            </div>
                            <div v-else class="max-h-80 overflow-auto rounded-xl border border-gray-200 dark:border-gray-700">
                                <table class="w-full text-sm">
                                    <thead class="bg-gray-50 dark:bg-gray-800/40">
                                        <tr class="text-left text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                            <th class="px-3 py-2">{{ t('Package') }}</th>
                                            <th class="px-3 py-2">{{ t('Version') }}</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr v-for="pkg in npmPackages" :key="`npm-${pkg.name}`" class="border-t border-gray-100 dark:border-gray-800">
                                            <td class="px-3 py-2 font-medium text-gray-700 dark:text-gray-300">{{ pkg.name }}</td>
                                            <td class="px-3 py-2 text-gray-500 dark:text-gray-400">{{ pkg.version }}</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/3 md:p-6">
                            <div class="mb-4 flex items-center gap-2">
                                <i class="fa-solid fa-box-archive text-lg text-blue-light-600 dark:text-blue-light-300"></i>
                                <h4 class="text-base font-semibold text-gray-800 dark:text-white/90">{{ t('Composer Manager') }}</h4>
                            </div>

                            <div class="mb-4 flex flex-wrap items-center gap-3">
                                <label class="inline-flex items-center gap-2 text-sm text-gray-600 dark:text-gray-300">
                                    <input
                                        v-model="composerNoDev"
                                        type="checkbox"
                                        class="h-4 w-4 rounded border-gray-300 text-brand-500 focus:ring-brand-500"
                                        :disabled="composerActionLoading !== null || npmActionLoading !== null"
                                    />
                                    {{ t('Run with --no-dev') }}
                                </label>
                            </div>

                            <div class="mb-4 flex flex-wrap items-center gap-2">
                                <button
                                    type="button"
                                    :disabled="composerListingLoading || composerActionLoading !== null || npmActionLoading !== null"
                                    class="inline-flex h-9 items-center gap-1.5 rounded-lg border border-gray-300 bg-white px-3 text-sm text-gray-700 hover:bg-gray-50 disabled:opacity-60 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300"
                                    @click="fetchComposerPackages"
                                >
                                    <i :class="composerListingLoading ? 'bx bx-loader-alt animate-spin' : 'bx bx-refresh'" class="text-base"></i>
                                    {{ t('Refresh Packages') }}
                                </button>
                                <button
                                    type="button"
                                    :disabled="composerListingLoading || composerActionLoading !== null || npmActionLoading !== null"
                                    class="inline-flex h-9 items-center gap-1.5 rounded-lg bg-brand-500 px-3 text-sm font-medium text-white hover:bg-brand-600 disabled:opacity-60"
                                    @click="runComposerInstall"
                                >
                                    <i :class="composerActionLoading === 'install' ? 'bx bx-loader-alt animate-spin' : 'bx bx-download'" class="text-base"></i>
                                    {{ t('composer install') }}
                                </button>
                                <button
                                    type="button"
                                    :disabled="composerListingLoading || composerActionLoading !== null || npmActionLoading !== null"
                                    class="inline-flex h-9 items-center gap-1.5 rounded-lg bg-success-500 px-3 text-sm font-medium text-white hover:bg-success-600 disabled:opacity-60"
                                    @click="runComposerUpdate"
                                >
                                    <i :class="composerActionLoading === 'update' ? 'bx bx-loader-alt animate-spin' : 'bx bx-sync'" class="text-base"></i>
                                    {{ t('composer update') }}
                                </button>
                                <button
                                    type="button"
                                    :disabled="composerListingLoading || composerActionLoading !== null || npmActionLoading !== null"
                                    class="inline-flex h-9 items-center gap-1.5 rounded-lg bg-blue-light-500 px-3 text-sm font-medium text-white hover:bg-blue-light-600 disabled:opacity-60"
                                    @click="runComposerDumpAutoload"
                                >
                                    <i :class="composerActionLoading === 'dump_autoload' ? 'bx bx-loader-alt animate-spin' : 'bx bx-link-alt'" class="text-base"></i>
                                    {{ t('composer dump-autoloads') }}
                                </button>
                            </div>

                            <div v-if="!composerHasComposerJson" class="rounded-lg border border-warning-500/30 bg-warning-500/10 px-3 py-2 text-sm text-warning-700 dark:text-warning-300">
                                {{ t('composer.json not found in this domain path.') }}
                            </div>
                            <div v-else-if="composerPackages.length === 0" class="rounded-lg border border-gray-200 bg-gray-50 px-3 py-6 text-center text-sm text-gray-500 dark:border-gray-700 dark:bg-gray-900/30 dark:text-gray-400">
                                {{ composerListingLoading ? t('Loading package list...') : t('No Composer packages found.') }}
                            </div>
                            <div v-else class="max-h-80 overflow-auto rounded-xl border border-gray-200 dark:border-gray-700">
                                <table class="w-full text-sm">
                                    <thead class="bg-gray-50 dark:bg-gray-800/40">
                                        <tr class="text-left text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                            <th class="px-3 py-2">{{ t('Package') }}</th>
                                            <th class="px-3 py-2">{{ t('Version') }}</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr v-for="pkg in composerPackages" :key="`composer-${pkg.name}`" class="border-t border-gray-100 dark:border-gray-800">
                                            <td class="px-3 py-2 font-medium text-gray-700 dark:text-gray-300">{{ pkg.name }}</td>
                                            <td class="px-3 py-2 text-gray-500 dark:text-gray-400">{{ pkg.version }}</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <div v-if="lastCommand.output !== ''" class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/3 md:p-6">
                        <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                            <h4 class="text-sm font-semibold text-gray-800 dark:text-white/90">{{ t('Last Command Output') }}</h4>
                            <span class="text-xs text-gray-500 dark:text-gray-400">{{ lastCommand.label }} · {{ lastCommand.executedAt }}</span>
                        </div>
                        <pre class="max-h-90 max-w-full overflow-auto rounded-lg border border-gray-200 bg-gray-50 p-3 text-xs text-gray-700 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300">{{ lastCommand.output }}</pre>
                        <p v-if="lastCommand.truncated" class="mt-2 text-xs text-warning-600 dark:text-warning-300">
                            {{ t('Output is truncated due to size.') }}
                        </p>
                    </div>

                    <div class="flex">
                        <Link
                            :href="route('domains.show', domain.id)"
                            class="inline-flex items-center gap-2 rounded-lg border border-gray-300 bg-white px-4 py-2.5 text-sm font-medium text-gray-700 shadow-theme-xs hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-400"
                        >
                            <i class="bx bx-arrow-back text-base"></i>
                            {{ t('Back to Domain') }}
                        </Link>
                    </div>
                </div>
            </AdminLayout>
        </SidebarProvider>
    </ThemeProvider>
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import axios from 'axios';
import ThemeProvider from '@/Components/Layout/ThemeProvider.vue';
import SidebarProvider from '@/Components/Layout/SidebarProvider.vue';
import AdminLayout from '@/Components/Layout/AdminLayout.vue';
import PageBreadcrumb from '@/Components/Common/PageBreadcrumb.vue';
import Toast from '@/Components/UI/Toast.vue';
import { useToast } from '@/Composables/useToast';
import { useI18n } from '@/Composables/useI18n';

interface PackageRow {
    name: string;
    version: string;
}

const props = defineProps<{
    domain: {
        id: number;
        fqdn: string;
    };
}>();

const { addToast } = useToast();
const { t } = useI18n();

const domain = computed(() => props.domain);
const breadcrumbs = computed(() => [
    { label: t('Domains'), href: route('domains.index') },
    { label: domain.value.fqdn, href: route('domains.show', domain.value.id) },
    { label: t('Package Manager') },
]);

const npmPackages = ref<PackageRow[]>([]);
const composerPackages = ref<PackageRow[]>([]);
const npmListingLoading = ref(false);
const composerListingLoading = ref(false);
const npmActionLoading = ref<'install' | 'build' | 'audit_fix' | null>(null);
const composerActionLoading = ref<'install' | 'update' | 'dump_autoload' | null>(null);
const composerNoDev = ref(false);
const npmHasPackageJson = ref(true);
const composerHasComposerJson = ref(true);

const lastCommand = ref({
    label: '',
    output: '',
    truncated: false,
    executedAt: '',
});

const nowLabel = (): string => new Date().toLocaleString();

const setCommandOutput = (label: string, output: string, truncated: boolean): void => {
    if (output.trim() === '') {
        return;
    }

    lastCommand.value = {
        label,
        output,
        truncated,
        executedAt: nowLabel(),
    };
};

const fetchNpmPackages = async (): Promise<void> => {
    npmListingLoading.value = true;

    try {
        const response = await axios.get(route('domains.packages.npm.packages', domain.value.id));
        npmHasPackageJson.value = Boolean(response.data?.has_package_json ?? true);
        npmPackages.value = Array.isArray(response.data?.packages) ? response.data.packages : [];
    } catch (error: any) {
        addToast('error', error.response?.data?.message ?? t('Failed to list NPM packages.'));
    } finally {
        npmListingLoading.value = false;
    }
};

const fetchComposerPackages = async (): Promise<void> => {
    composerListingLoading.value = true;

    try {
        const response = await axios.get(route('domains.packages.composer.packages', domain.value.id));
        composerHasComposerJson.value = Boolean(response.data?.has_composer_json ?? true);
        composerPackages.value = Array.isArray(response.data?.packages) ? response.data.packages : [];
    } catch (error: any) {
        addToast('error', error.response?.data?.message ?? t('Failed to list Composer packages.'));
    } finally {
        composerListingLoading.value = false;
    }
};

const runNpmInstall = async (): Promise<void> => {
    npmActionLoading.value = 'install';

    try {
        const response = await axios.post(route('domains.packages.npm.install', domain.value.id));
        addToast('success', response.data?.message ?? t('NPM install completed successfully.'));
        setCommandOutput('npm install', String(response.data?.output ?? ''), Boolean(response.data?.output_truncated ?? false));
        await fetchNpmPackages();
    } catch (error: any) {
        addToast('error', error.response?.data?.message ?? t('NPM install failed.'));
        setCommandOutput('npm install', String(error.response?.data?.output ?? ''), Boolean(error.response?.data?.output_truncated ?? false));
    } finally {
        npmActionLoading.value = null;
    }
};

const runNpmBuild = async (): Promise<void> => {
    npmActionLoading.value = 'build';

    try {
        const response = await axios.post(route('domains.packages.npm.build', domain.value.id));
        addToast('success', response.data?.message ?? t('NPM build completed successfully.'));
        setCommandOutput('npm run build', String(response.data?.output ?? ''), Boolean(response.data?.output_truncated ?? false));
    } catch (error: any) {
        addToast('error', error.response?.data?.message ?? t('NPM build failed.'));
        setCommandOutput('npm run build', String(error.response?.data?.output ?? ''), Boolean(error.response?.data?.output_truncated ?? false));
    } finally {
        npmActionLoading.value = null;
    }
};

const runNpmAuditFix = async (): Promise<void> => {
    npmActionLoading.value = 'audit_fix';

    try {
        const response = await axios.post(route('domains.packages.npm.audit-fix', domain.value.id));
        addToast('success', response.data?.message ?? t('NPM audit fix completed successfully.'));
        setCommandOutput('npm audit fix', String(response.data?.output ?? ''), Boolean(response.data?.output_truncated ?? false));
        await fetchNpmPackages();
    } catch (error: any) {
        addToast('error', error.response?.data?.message ?? t('NPM audit fix failed.'));
        setCommandOutput('npm audit fix', String(error.response?.data?.output ?? ''), Boolean(error.response?.data?.output_truncated ?? false));
    } finally {
        npmActionLoading.value = null;
    }
};

const runComposerInstall = async (): Promise<void> => {
    composerActionLoading.value = 'install';

    try {
        const response = await axios.post(route('domains.packages.composer.install', domain.value.id), {
            no_dev: composerNoDev.value,
        });
        addToast('success', response.data?.message ?? t('Composer install completed successfully.'));
        setCommandOutput('composer install', String(response.data?.output ?? ''), Boolean(response.data?.output_truncated ?? false));
        await fetchComposerPackages();
    } catch (error: any) {
        addToast('error', error.response?.data?.message ?? t('Composer install failed.'));
        setCommandOutput('composer install', String(error.response?.data?.output ?? ''), Boolean(error.response?.data?.output_truncated ?? false));
    } finally {
        composerActionLoading.value = null;
    }
};

const runComposerUpdate = async (): Promise<void> => {
    composerActionLoading.value = 'update';

    try {
        const response = await axios.post(route('domains.packages.composer.update', domain.value.id), {
            no_dev: composerNoDev.value,
        });
        addToast('success', response.data?.message ?? t('Composer update completed successfully.'));
        setCommandOutput('composer update', String(response.data?.output ?? ''), Boolean(response.data?.output_truncated ?? false));
        await fetchComposerPackages();
    } catch (error: any) {
        addToast('error', error.response?.data?.message ?? t('Composer update failed.'));
        setCommandOutput('composer update', String(error.response?.data?.output ?? ''), Boolean(error.response?.data?.output_truncated ?? false));
    } finally {
        composerActionLoading.value = null;
    }
};

const runComposerDumpAutoload = async (): Promise<void> => {
    composerActionLoading.value = 'dump_autoload';

    try {
        const response = await axios.post(route('domains.packages.composer.dump-autoload', domain.value.id));
        addToast('success', response.data?.message ?? t('Composer dump-autoload completed successfully.'));
        setCommandOutput('composer dump-autoload', String(response.data?.output ?? ''), Boolean(response.data?.output_truncated ?? false));
    } catch (error: any) {
        addToast('error', error.response?.data?.message ?? t('Composer dump-autoload failed.'));
        setCommandOutput('composer dump-autoload', String(error.response?.data?.output ?? ''), Boolean(error.response?.data?.output_truncated ?? false));
    } finally {
        composerActionLoading.value = null;
    }
};

onMounted(() => {
    void Promise.all([
        fetchNpmPackages(),
        fetchComposerPackages(),
    ]);
});
</script>
