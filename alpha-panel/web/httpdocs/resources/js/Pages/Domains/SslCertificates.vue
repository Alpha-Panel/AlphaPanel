<template>
    <Head :title="`${t('SSL Certificates')} - ${domain.fqdn}`" />
    <ThemeProvider>
        <SidebarProvider>
            <AdminLayout>
                <PageBreadcrumb
                    :pageTitle="t('SSL Certificates')"
                    :items="breadcrumbs"
                    :backHref="route('domains.show', domain.id)"
                />
                <Toast />

                <div class="space-y-4 md:space-y-6">
                    <!-- SSL Operation Progress -->
                    <div v-if="sslOperationActive" class="mb-5 rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/3">
                        <div class="flex items-center justify-between">
                            <h4 class="mb-3 text-sm font-semibold text-gray-800 dark:text-white/90">
                                <i class="bx bx-loader-alt bx-spin mr-1"></i>
                                {{ t('SSL Operation In Progress') }}
                            </h4>
                            <button
                                v-if="sslState === 'running'"
                                type="button"
                                class="rounded-lg border border-error-300 px-3 py-1.5 text-xs font-medium text-error-600 hover:bg-error-50 dark:border-error-700 dark:text-error-400 dark:hover:bg-error-900/20"
                                @click="cancelSslOperation"
                            >
                                {{ t('Cancel') }}
                            </button>
                        </div>
                        <div :class="['mt-2 mb-3 rounded-lg border px-3 py-2 text-sm', sslMessageClass]">
                            {{ sslMessage }}
                        </div>
                        <div class="h-2 w-full overflow-hidden rounded-full bg-gray-200 dark:bg-gray-800">
                            <div
                                :class="['h-full transition-all duration-300', sslProgressClass]"
                                :style="{ width: `${sslPercent}%` }"
                            ></div>
                        </div>
                        <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">
                            {{ sslState === 'failed' ? t('Failed') : `${sslPercent}%` }}
                        </p>
                    </div>

                    <!-- Active Certificate Status -->
                    <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/3 md:p-6">
                        <h4 class="mb-4 text-sm font-semibold text-gray-800 dark:text-white/90">
                            <i class="bx bx-lock-alt mr-1 text-brand-500"></i>
                            {{ t('Active Certificate') }}
                        </h4>

                        <div v-if="activeCert" class="flex flex-col gap-3 sm:flex-row sm:items-center sm:gap-6">
                            <div class="flex items-center gap-3">
                                <div class="flex h-10 w-10 items-center justify-center rounded-lg bg-success-50 dark:bg-success-500/10">
                                    <i class="bx bx-check-shield text-xl text-success-600 dark:text-success-400"></i>
                                </div>
                                <div>
                                    <p class="text-sm font-medium text-gray-800 dark:text-white/90">
                                        {{ activeCert.common_name }}
                                    </p>
                                    <p class="text-xs text-gray-500 dark:text-gray-400">
                                        {{ t('Issued by :issuer', { issuer: activeCert.issuer || t('Unknown') }) }}
                                    </p>
                                </div>
                            </div>

                            <div class="flex flex-wrap items-center gap-2">
                                <span :class="typeBadgeClass(activeCert)">
                                    {{ typeLabel(activeCert) }}
                                </span>
                                <span :class="expiryBadgeClass(activeCert)">
                                    <template v-if="activeCert.is_expired">
                                        {{ t('Expired') }}
                                    </template>
                                    <template v-else-if="activeCert.is_expiring_soon">
                                        {{ t('Expires in :days days', { days: activeCert.days_until_expiry }) }}
                                    </template>
                                    <template v-else>
                                        {{ t('Expires :date', { date: formatDateTime(activeCert.not_after) }) }}
                                        <span v-if="activeCert.days_until_expiry !== null" class="ml-1">
                                            ({{ t(':days days', { days: activeCert.days_until_expiry }) }})
                                        </span>
                                    </template>
                                </span>
                            </div>
                        </div>

                        <div v-else class="flex items-center gap-3 py-4">
                            <div class="flex h-10 w-10 items-center justify-center rounded-lg bg-gray-100 dark:bg-gray-800">
                                <i class="bx bx-lock-open text-xl text-gray-400 dark:text-gray-500"></i>
                            </div>
                            <div>
                                <p class="text-sm font-medium text-gray-600 dark:text-gray-400">
                                    {{ t('No active certificate') }}
                                </p>
                                <p class="text-xs text-gray-400 dark:text-gray-500">
                                    {{ t('Install a certificate to enable HTTPS for this domain.') }}
                                </p>
                            </div>
                        </div>
                    </div>

                    <!-- Action Buttons -->
                    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
                        <button
                            type="button"
                            @click="showLeModal = true"
                            class="flex items-center gap-3 rounded-xl border-2 border-gray-200 bg-white p-4 text-left transition-colors hover:border-brand-300 hover:bg-brand-50 dark:border-gray-700 dark:bg-gray-900 dark:hover:border-brand-800 dark:hover:bg-brand-500/10"
                        >
                            <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-success-50 dark:bg-success-500/10">
                                <i class="bx bxs-lock text-lg text-success-600 dark:text-success-400"></i>
                            </div>
                            <div>
                                <p class="text-sm font-medium text-gray-800 dark:text-white/90">{{ t("Let's Encrypt") }}</p>
                                <p class="text-xs text-gray-500 dark:text-gray-400">{{ t('Free auto-renewing certificate') }}</p>
                            </div>
                        </button>

                        <button
                            type="button"
                            @click="showCsrModal = true"
                            class="flex items-center gap-3 rounded-xl border-2 border-gray-200 bg-white p-4 text-left transition-colors hover:border-brand-300 hover:bg-brand-50 dark:border-gray-700 dark:bg-gray-900 dark:hover:border-brand-800 dark:hover:bg-brand-500/10"
                        >
                            <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-blue-50 dark:bg-blue-500/10">
                                <i class="bx bx-file text-lg text-blue-600 dark:text-blue-400"></i>
                            </div>
                            <div>
                                <p class="text-sm font-medium text-gray-800 dark:text-white/90">{{ t('Generate CSR') }}</p>
                                <p class="text-xs text-gray-500 dark:text-gray-400">{{ t('Certificate signing request') }}</p>
                            </div>
                        </button>

                        <button
                            type="button"
                            @click="showUploadModal = true"
                            class="flex items-center gap-3 rounded-xl border-2 border-gray-200 bg-white p-4 text-left transition-colors hover:border-brand-300 hover:bg-brand-50 dark:border-gray-700 dark:bg-gray-900 dark:hover:border-brand-800 dark:hover:bg-brand-500/10"
                        >
                            <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-purple-50 dark:bg-purple-500/10">
                                <i class="bx bx-upload text-lg text-purple-600 dark:text-purple-400"></i>
                            </div>
                            <div>
                                <p class="text-sm font-medium text-gray-800 dark:text-white/90">{{ t('Upload Certificate') }}</p>
                                <p class="text-xs text-gray-500 dark:text-gray-400">{{ t('Install a custom certificate') }}</p>
                            </div>
                        </button>

                        <label
                            class="flex cursor-pointer items-center gap-3 rounded-xl border-2 border-gray-200 bg-white p-4 text-left transition-colors hover:border-brand-300 hover:bg-brand-50 dark:border-gray-700 dark:bg-gray-900 dark:hover:border-brand-800 dark:hover:bg-brand-500/10"
                        >
                            <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-amber-50 dark:bg-amber-500/10">
                                <i class="bx bx-import text-lg text-amber-600 dark:text-amber-400"></i>
                            </div>
                            <div>
                                <p class="text-sm font-medium text-gray-800 dark:text-white/90">{{ t('Import PEM') }}</p>
                                <p class="text-xs text-gray-500 dark:text-gray-400">{{ t('Import from a PEM file') }}</p>
                            </div>
                            <input ref="importFileInput" type="file" accept=".pem,.txt" class="hidden" @change="handleImportFile" />
                        </label>
                    </div>

                    <!-- Certificates Table -->
                    <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/3 md:p-6">
                        <div class="mb-5 flex items-center">
                            <h4 class="text-sm font-semibold text-gray-800 dark:text-white/90">
                                <i class="bx bx-list-ul mr-1 text-brand-500"></i>
                                {{ t('Certificates') }}
                            </h4>
                            <span
                                v-if="certificates.length > 0"
                                class="ml-2 inline-flex h-5 min-w-5 items-center justify-center rounded-full bg-brand-100 px-1.5 text-xs font-medium text-brand-700 dark:bg-brand-500/20 dark:text-brand-400"
                            >
                                {{ certificates.length }}
                            </span>
                        </div>

                        <!-- Empty State -->
                        <div
                            v-if="certificates.length === 0"
                            class="py-12 text-center"
                        >
                            <i class="bx bx-lock-open mb-3 text-4xl text-gray-300 dark:text-gray-600"></i>
                            <p class="text-sm text-gray-500 dark:text-gray-400">{{ t('No certificates installed.') }}</p>
                            <p class="mt-1 text-xs text-gray-400 dark:text-gray-500">{{ t('Use the buttons above to add a certificate.') }}</p>
                        </div>

                        <!-- Table -->
                        <div v-else class="overflow-x-auto">
                            <table class="w-full text-sm">
                                <thead>
                                    <tr class="border-b border-gray-200 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:border-gray-700 dark:text-gray-400">
                                        <th class="pb-3 pr-4">{{ t('Label') }}</th>
                                        <th class="pb-3 pr-4">{{ t('Type') }}</th>
                                        <th class="pb-3 pr-4">{{ t('Common Name') }}</th>
                                        <th class="pb-3 pr-4">{{ t('Expires') }}</th>
                                        <th class="pb-3 pr-4">{{ t('Status') }}</th>
                                        <th class="pb-3 text-right">{{ t('Actions') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr
                                        v-for="(cert, index) in certificates"
                                        :key="cert.id"
                                        class="cursor-pointer border-b border-gray-100 transition-colors hover:bg-gray-50 dark:border-gray-800 dark:hover:bg-white/3"
                                        :class="[
                                            index % 2 === 1 ? 'bg-gray-50/50 dark:bg-white/1' : '',
                                            cert.is_active ? 'border-l-4 border-l-success-500' : '',
                                        ]"
                                        @click="openCertDetail(cert)"
                                    >
                                        <td class="py-3 pr-4 text-gray-700 dark:text-gray-300">
                                            <div class="flex flex-col gap-1">
                                                <span>{{ cert.label || t('Untitled') }}</span>
                                                <span
                                                    v-if="cert.inherited_from_fqdn"
                                                    class="inline-flex w-fit items-center gap-1 rounded-full bg-brand-500/10 px-2 py-0.5 text-[10px] font-semibold text-brand-700 dark:text-brand-300"
                                                    :title="t('This certificate is owned by the parent domain and covers this subdomain.')"
                                                >
                                                    <i class="bx bx-link-alt text-xs"></i>
                                                    {{ t('Inherited from :fqdn', { fqdn: cert.inherited_from_fqdn }) }}
                                                </span>
                                            </div>
                                        </td>
                                        <td class="py-3 pr-4">
                                            <span :class="typeBadgeClass(cert)">
                                                {{ typeLabel(cert) }}
                                            </span>
                                        </td>
                                        <td class="py-3 pr-4">
                                            <code class="rounded bg-gray-100 px-2 py-0.5 text-xs text-gray-700 dark:bg-gray-800 dark:text-gray-300">
                                                {{ cert.common_name }}
                                            </code>
                                        </td>
                                        <td class="py-3 pr-4 text-gray-500 dark:text-gray-400">
                                            <template v-if="cert.has_certificate && cert.not_after">
                                                <span :class="{ 'text-error-600 dark:text-error-400': cert.is_expired, 'text-warning-600 dark:text-warning-400': cert.is_expiring_soon && !cert.is_expired }">
                                                    {{ formatDateTime(cert.not_after) }}
                                                </span>
                                            </template>
                                            <span v-else class="text-xs text-gray-400 dark:text-gray-500">-</span>
                                        </td>
                                        <td class="py-3 pr-4">
                                            <span v-if="cert.is_active" class="inline-flex rounded-full bg-success-500/15 px-2 py-0.5 text-xs font-semibold text-success-700 dark:text-success-300">
                                                {{ t('Active') }}
                                            </span>
                                            <span v-else-if="!cert.has_certificate" class="inline-flex rounded-full bg-warning-500/15 px-2 py-0.5 text-xs font-semibold text-warning-700 dark:text-warning-300">
                                                {{ t('Awaiting Certificate') }}
                                            </span>
                                            <span v-else-if="cert.is_expired" class="inline-flex rounded-full bg-error-500/15 px-2 py-0.5 text-xs font-semibold text-error-700 dark:text-error-300">
                                                {{ t('Expired') }}
                                            </span>
                                            <span v-else class="inline-flex rounded-full bg-gray-200 px-2 py-0.5 text-xs font-semibold text-gray-700 dark:bg-gray-700 dark:text-gray-300">
                                                {{ t('Inactive') }}
                                            </span>
                                        </td>
                                        <td class="py-3 text-right" @click.stop>
                                            <div class="flex items-center justify-end gap-2">
                                                <a
                                                    v-if="cert.has_certificate"
                                                    :href="route('domains.ssl.export', [domain.id, cert.id])"
                                                    class="inline-flex items-center gap-1.5 rounded-lg border border-gray-300 px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-300 dark:hover:bg-gray-800"
                                                    :title="t('Export PEM')"
                                                >
                                                    <i class="bx bx-export text-sm"></i>
                                                    {{ t('Export') }}
                                                </a>
                                                <button
                                                    v-if="!cert.is_active && cert.has_certificate"
                                                    type="button"
                                                    :disabled="activating === cert.id"
                                                    @click="activateCert(cert)"
                                                    class="inline-flex items-center gap-1.5 rounded-lg bg-brand-500 px-3 py-1.5 text-xs font-medium text-white hover:bg-brand-600 disabled:opacity-50"
                                                >
                                                    <i v-if="activating === cert.id" class="bx bx-loader-alt animate-spin text-sm"></i>
                                                    <i v-else class="bx bx-check-circle text-sm"></i>
                                                    {{ t('Activate') }}
                                                </button>
                                                <span v-else-if="cert.is_active" class="inline-flex items-center gap-1.5 rounded-lg bg-success-500/15 px-3 py-1.5 text-xs font-semibold text-success-700 dark:text-success-300">
                                                    <i class="bx bx-check-circle text-sm"></i>
                                                    {{ t('Active') }}
                                                </span>
                                                <button
                                                    type="button"
                                                    :disabled="cert.is_active || deleting === cert.id"
                                                    @click="deleteCert(cert)"
                                                    class="inline-flex items-center gap-1.5 rounded-lg border border-error-500/40 px-3 py-1.5 text-xs font-medium text-error-600 hover:bg-error-500/10 disabled:opacity-50 dark:text-error-400"
                                                >
                                                    <i v-if="deleting === cert.id" class="bx bx-loader-alt animate-spin text-sm"></i>
                                                    <i v-else class="bx bx-trash text-sm"></i>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Back Button -->
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

                <!-- Let's Encrypt Modal -->
                <div
                    v-if="showLeModal"
                    class="fixed inset-0 z-1200000 flex items-center justify-center bg-black/60 p-4 backdrop-blur-sm"
                >
                    <div class="flex max-h-[92vh] w-full max-w-lg flex-col rounded-xl border border-gray-700 bg-[#171717] text-white/90 shadow-2xl">
                        <div class="flex items-center justify-between border-b border-white/10 px-5 py-4">
                            <h5 class="text-base text-white">{{ t("Let's Encrypt Certificate") }}</h5>
                            <button
                                type="button"
                                class="text-2xl leading-none text-white/50 hover:text-white"
                                :disabled="leForm.processing"
                                @click="showLeModal = false"
                            >
                                &times;
                            </button>
                        </div>

                        <form class="min-h-0 flex-1 overflow-y-auto p-5" @submit.prevent="submitLe">
                            <div class="space-y-4">
                                <p class="text-sm text-white/60">
                                    {{ t('Select the challenge method to verify domain ownership.') }}
                                </p>

                                <div class="space-y-3">
                                    <button
                                        type="button"
                                        @click="leForm.validation_method = 'dns-01-cloudflare'"
                                        class="w-full rounded-xl border-2 p-4 text-left transition-colors"
                                        :class="leForm.validation_method === 'dns-01-cloudflare'
                                            ? 'border-brand-500 bg-brand-500/10'
                                            : 'border-white/15 bg-white/5 hover:border-white/25'"
                                    >
                                        <div class="flex items-center gap-2">
                                            <div
                                                class="flex h-4 w-4 items-center justify-center rounded-full border-2"
                                                :class="leForm.validation_method === 'dns-01-cloudflare'
                                                    ? 'border-brand-500'
                                                    : 'border-white/30'"
                                            >
                                                <div
                                                    v-if="leForm.validation_method === 'dns-01-cloudflare'"
                                                    class="h-2 w-2 rounded-full bg-brand-500"
                                                ></div>
                                            </div>
                                            <span class="text-sm font-medium text-white">{{ t('DNS-01 (Cloudflare)') }}</span>
                                        </div>
                                        <p class="mt-1.5 pl-6 text-xs text-white/50">
                                            {{ t('Validates via Cloudflare DNS TXT records. Supports wildcard certificates.') }}
                                        </p>
                                    </button>

                                    <button
                                        v-if="domain.dns_provider === 'local'"
                                        type="button"
                                        @click="leForm.validation_method = 'dns-01-local'"
                                        class="w-full rounded-xl border-2 p-4 text-left transition-colors"
                                        :class="leForm.validation_method === 'dns-01-local'
                                            ? 'border-brand-500 bg-brand-500/10'
                                            : 'border-white/15 bg-white/5 hover:border-white/25'"
                                    >
                                        <div class="flex items-center gap-2">
                                            <div
                                                class="flex h-4 w-4 items-center justify-center rounded-full border-2"
                                                :class="leForm.validation_method === 'dns-01-local'
                                                    ? 'border-brand-500'
                                                    : 'border-white/30'"
                                            >
                                                <div
                                                    v-if="leForm.validation_method === 'dns-01-local'"
                                                    class="h-2 w-2 rounded-full bg-brand-500"
                                                ></div>
                                            </div>
                                            <span class="text-sm font-medium text-white">{{ t('DNS-01 (Local DNS)') }}</span>
                                        </div>
                                        <p class="mt-1.5 pl-6 text-xs text-white/50">
                                            {{ t('Validates via local DNS server TXT records. Supports wildcard certificates.') }}
                                        </p>
                                    </button>

                                    <button
                                        type="button"
                                        @click="leForm.validation_method = 'http-01'"
                                        class="w-full rounded-xl border-2 p-4 text-left transition-colors"
                                        :class="leForm.validation_method === 'http-01'
                                            ? 'border-brand-500 bg-brand-500/10'
                                            : 'border-white/15 bg-white/5 hover:border-white/25'"
                                    >
                                        <div class="flex items-center gap-2">
                                            <div
                                                class="flex h-4 w-4 items-center justify-center rounded-full border-2"
                                                :class="leForm.validation_method === 'http-01'
                                                    ? 'border-brand-500'
                                                    : 'border-white/30'"
                                            >
                                                <div
                                                    v-if="leForm.validation_method === 'http-01'"
                                                    class="h-2 w-2 rounded-full bg-brand-500"
                                                ></div>
                                            </div>
                                            <span class="text-sm font-medium text-white">{{ t('HTTP-01 (Webroot)') }}</span>
                                        </div>
                                        <p class="mt-1.5 pl-6 text-xs text-white/50">
                                            {{ t('Validates via an HTTP file placed in the document root. Domain must be publicly accessible.') }}
                                        </p>
                                    </button>
                                </div>
                            </div>

                            <div class="mt-6 flex items-center justify-end gap-2 border-t border-white/10 pt-4">
                                <button
                                    type="button"
                                    class="inline-flex items-center gap-2 rounded-lg border border-white/20 px-4 py-2.5 text-sm font-medium text-white/80 hover:bg-white/10"
                                    :disabled="leForm.processing"
                                    @click="showLeModal = false"
                                >
                                    {{ t('Cancel') }}
                                </button>
                                <button
                                    type="submit"
                                    :disabled="leForm.processing"
                                    class="inline-flex items-center gap-2 rounded-lg bg-brand-500 px-4 py-2.5 text-sm font-medium text-white hover:bg-brand-600 disabled:opacity-60"
                                >
                                    {{ leForm.processing ? t('Requesting...') : t('Request Certificate') }}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Generate CSR Modal -->
                <div
                    v-if="showCsrModal"
                    class="fixed inset-0 z-1200000 flex items-center justify-center bg-black/60 p-4 backdrop-blur-sm"
                >
                    <div class="flex max-h-[92vh] w-full max-w-2xl flex-col rounded-xl border border-gray-700 bg-[#171717] text-white/90 shadow-2xl">
                        <div class="flex items-center justify-between border-b border-white/10 px-5 py-4">
                            <h5 class="text-base text-white">{{ t('Generate CSR') }}</h5>
                            <button
                                type="button"
                                class="text-2xl leading-none text-white/50 hover:text-white"
                                :disabled="csrForm.processing"
                                @click="showCsrModal = false"
                            >
                                &times;
                            </button>
                        </div>

                        <form class="min-h-0 flex-1 overflow-y-auto p-5" @submit.prevent="submitCsr">
                            <div class="space-y-4">
                                <FormField :label="t('Common Name')" :error="csrForm.errors.common_name" required>
                                    <input v-model="csrForm.common_name" type="text" class="form-input" :placeholder="domain.fqdn" />
                                </FormField>

                                <div>
                                    <div class="mb-1.5 flex items-center justify-between">
                                        <label class="text-sm font-medium text-gray-400">{{ t('SAN Domains') }}</label>
                                        <button
                                            type="button"
                                            @click="addSanDomain"
                                            class="inline-flex items-center gap-1 rounded px-2 py-1 text-xs font-medium text-brand-400 hover:bg-brand-500/10"
                                        >
                                            <i class="bx bx-plus text-sm"></i>
                                            {{ t('Add') }}
                                        </button>
                                    </div>
                                    <div class="space-y-2">
                                        <div
                                            v-for="(_, index) in csrForm.san_domains"
                                            :key="index"
                                            class="flex items-center gap-2"
                                        >
                                            <input
                                                v-model="csrForm.san_domains[index]"
                                                type="text"
                                                class="form-input flex-1"
                                                :placeholder="index === 0 ? 'www.example.com' : '*.example.com'"
                                            />
                                            <button
                                                type="button"
                                                @click="removeSanDomain(index)"
                                                class="flex h-11 w-11 shrink-0 items-center justify-center rounded-lg border border-white/20 text-white/50 hover:bg-white/10 hover:text-white"
                                            >
                                                <i class="bx bx-x text-lg"></i>
                                            </button>
                                        </div>
                                    </div>
                                    <p v-if="csrForm.errors.san_domains" class="mt-1 text-sm text-error-500">{{ csrForm.errors.san_domains }}</p>
                                </div>

                                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                    <FormField :label="t('Organization')" :error="csrForm.errors.organization">
                                        <input v-model="csrForm.organization" type="text" class="form-input" :placeholder="t('Company Inc.')" />
                                    </FormField>
                                    <FormField :label="t('Organizational Unit')" :error="csrForm.errors.organizational_unit">
                                        <input v-model="csrForm.organizational_unit" type="text" class="form-input" :placeholder="t('IT Department')" />
                                    </FormField>
                                </div>

                                <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                                    <FormField :label="t('Country (2-letter)')" :error="csrForm.errors.country">
                                        <input v-model="csrForm.country" type="text" class="form-input" maxlength="2" placeholder="US" />
                                    </FormField>
                                    <FormField :label="t('State/Province')" :error="csrForm.errors.state">
                                        <input v-model="csrForm.state" type="text" class="form-input" :placeholder="t('California')" />
                                    </FormField>
                                    <FormField :label="t('Locality')" :error="csrForm.errors.locality">
                                        <input v-model="csrForm.locality" type="text" class="form-input" :placeholder="t('San Francisco')" />
                                    </FormField>
                                </div>

                                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                    <FormField :label="t('Email')" :error="csrForm.errors.email">
                                        <input v-model="csrForm.email" type="email" class="form-input" placeholder="admin@example.com" />
                                    </FormField>
                                    <FormField :label="t('Key Type')" :error="csrForm.errors.key_type" required>
                                        <select v-model="csrForm.key_type" class="form-input">
                                            <option value="rsa2048">{{ t('RSA 2048') }}</option>
                                            <option value="rsa4096">{{ t('RSA 4096') }}</option>
                                            <option value="ec256">{{ t('ECDSA P-256') }}</option>
                                            <option value="ec384">{{ t('ECDSA P-384') }}</option>
                                        </select>
                                    </FormField>
                                </div>
                            </div>

                            <div class="mt-6 flex items-center justify-end gap-2 border-t border-white/10 pt-4">
                                <button
                                    type="button"
                                    class="inline-flex items-center gap-2 rounded-lg border border-white/20 px-4 py-2.5 text-sm font-medium text-white/80 hover:bg-white/10"
                                    :disabled="csrForm.processing"
                                    @click="showCsrModal = false"
                                >
                                    {{ t('Cancel') }}
                                </button>
                                <button
                                    type="submit"
                                    :disabled="csrForm.processing"
                                    class="inline-flex items-center gap-2 rounded-lg bg-brand-500 px-4 py-2.5 text-sm font-medium text-white hover:bg-brand-600 disabled:opacity-60"
                                >
                                    {{ csrForm.processing ? t('Generating...') : t('Generate CSR') }}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Upload Certificate Modal -->
                <div
                    v-if="showUploadModal"
                    class="fixed inset-0 z-1200000 flex items-center justify-center bg-black/60 p-4 backdrop-blur-sm"
                >
                    <div class="flex max-h-[92vh] w-full max-w-2xl flex-col rounded-xl border border-gray-700 bg-[#171717] text-white/90 shadow-2xl">
                        <div class="flex items-center justify-between border-b border-white/10 px-5 py-4">
                            <h5 class="text-base text-white">{{ t('Upload Certificate') }}</h5>
                            <button
                                type="button"
                                class="text-2xl leading-none text-white/50 hover:text-white"
                                :disabled="uploadForm.processing"
                                @click="showUploadModal = false"
                            >
                                &times;
                            </button>
                        </div>

                        <form class="min-h-0 flex-1 overflow-y-auto p-5" @submit.prevent="submitUpload">
                            <div class="space-y-4">
                                <FormField :label="t('Label')" :error="uploadForm.errors.label">
                                    <input v-model="uploadForm.label" type="text" class="form-input" :placeholder="t('My Certificate (optional)')" />
                                </FormField>

                                <FormField :label="t('Private Key')" :error="uploadForm.errors.private_key" required>
                                    <textarea
                                        v-model="uploadForm.private_key"
                                        rows="6"
                                        class="form-input font-mono"
                                        placeholder="-----BEGIN PRIVATE KEY-----"
                                    ></textarea>
                                </FormField>

                                <FormField :label="t('Certificate')" :error="uploadForm.errors.certificate" required>
                                    <textarea
                                        v-model="uploadForm.certificate"
                                        rows="6"
                                        class="form-input font-mono"
                                        placeholder="-----BEGIN CERTIFICATE-----"
                                    ></textarea>
                                </FormField>

                                <FormField :label="t('CA Bundle')" :error="uploadForm.errors.ca_bundle">
                                    <textarea
                                        v-model="uploadForm.ca_bundle"
                                        rows="4"
                                        class="form-input font-mono"
                                        :placeholder="t('Intermediate certificates (optional)')"
                                    ></textarea>
                                </FormField>

                                <!-- Key match status -->
                                <div v-if="uploadKeyStatus === 'checking'" class="flex items-center gap-2 rounded-lg bg-blue-500/10 px-3 py-2 text-sm text-blue-400">
                                    <i class="bx bx-loader-alt animate-spin"></i>
                                    {{ t('Validating key pair...') }}
                                </div>
                                <div v-else-if="uploadKeyStatus === 'valid'" class="flex items-center gap-2 rounded-lg bg-green-500/10 px-3 py-2 text-sm text-green-400">
                                    <i class="bx bx-check-circle"></i>
                                    {{ t('Private key matches the certificate.') }}
                                </div>
                                <div v-else-if="uploadKeyStatus === 'invalid'" class="flex items-center gap-2 rounded-lg bg-red-500/10 px-3 py-2 text-sm text-red-400">
                                    <i class="bx bx-error-circle"></i>
                                    {{ uploadKeyError }}
                                </div>
                            </div>

                            <div class="mt-6 flex items-center justify-end gap-2 border-t border-white/10 pt-4">
                                <button
                                    type="button"
                                    class="inline-flex items-center gap-2 rounded-lg border border-white/20 px-4 py-2.5 text-sm font-medium text-white/80 hover:bg-white/10"
                                    :disabled="uploadForm.processing"
                                    @click="showUploadModal = false"
                                >
                                    {{ t('Cancel') }}
                                </button>
                                <button
                                    type="submit"
                                    :disabled="!canSubmitUpload"
                                    class="inline-flex items-center gap-2 rounded-lg bg-brand-500 px-4 py-2.5 text-sm font-medium text-white hover:bg-brand-600 disabled:opacity-60"
                                >
                                    {{ uploadForm.processing ? t('Uploading...') : t('Upload & Install') }}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Certificate Detail Modal -->
                <div
                    v-if="showDetailModal && detailCert"
                    class="fixed inset-0 z-1200000 flex items-center justify-center bg-black/60 p-4 backdrop-blur-sm"
                    @click.self="showDetailModal = false"
                >
                    <div class="flex max-h-[92vh] w-full max-w-3xl flex-col rounded-xl border border-gray-700 bg-[#171717] text-white/90 shadow-2xl">
                        <div class="flex items-center justify-between border-b border-white/10 px-5 py-4">
                            <h5 class="text-base text-white">
                                <i class="bx bx-lock-alt mr-1.5 text-brand-400"></i>
                                {{ detailCert.label || detailCert.common_name }}
                            </h5>
                            <div class="flex items-center gap-2">
                                <a
                                    v-if="detailCert.has_certificate"
                                    :href="route('domains.ssl.export', [domain.id, detailCert.id])"
                                    class="inline-flex items-center gap-1.5 rounded-lg border border-white/20 px-3 py-1.5 text-xs font-medium text-white/80 hover:bg-white/10"
                                >
                                    <i class="bx bx-export text-sm"></i>
                                    {{ t('Export PEM') }}
                                </a>
                                <button
                                    type="button"
                                    class="text-2xl leading-none text-white/50 hover:text-white"
                                    @click="showDetailModal = false"
                                >
                                    &times;
                                </button>
                            </div>
                        </div>

                        <div class="min-h-0 flex-1 overflow-y-auto p-5">
                            <div v-if="detailLoading" class="flex items-center justify-center py-12">
                                <i class="bx bx-loader-alt animate-spin text-3xl text-brand-400"></i>
                            </div>

                            <template v-else-if="detailData">
                                <!-- Certificate Info Grid -->
                                <div class="mb-5 grid grid-cols-2 gap-3">
                                    <div class="rounded-lg bg-white/5 px-3 py-2">
                                        <p class="text-[10px] uppercase tracking-wider text-white/40">{{ t('Common Name') }}</p>
                                        <p class="font-mono text-sm">{{ detailData.common_name }}</p>
                                    </div>
                                    <div class="rounded-lg bg-white/5 px-3 py-2">
                                        <p class="text-[10px] uppercase tracking-wider text-white/40">{{ t('Issuer') }}</p>
                                        <p class="text-sm">{{ detailData.issuer || '-' }}</p>
                                    </div>
                                    <div class="rounded-lg bg-white/5 px-3 py-2">
                                        <p class="text-[10px] uppercase tracking-wider text-white/40">{{ t('Valid From') }}</p>
                                        <p class="text-sm">{{ detailData.not_before ? formatDateTime(detailData.not_before) : '-' }}</p>
                                    </div>
                                    <div class="rounded-lg bg-white/5 px-3 py-2">
                                        <p class="text-[10px] uppercase tracking-wider text-white/40">{{ t('Valid Until') }}</p>
                                        <p class="text-sm">{{ detailData.not_after ? formatDateTime(detailData.not_after) : '-' }}</p>
                                    </div>
                                    <div v-if="detailData.san_domains && detailData.san_domains.length > 0" class="col-span-2 rounded-lg bg-white/5 px-3 py-2">
                                        <p class="text-[10px] uppercase tracking-wider text-white/40">{{ t('SAN Domains') }}</p>
                                        <div class="mt-1 flex flex-wrap gap-1">
                                            <code v-for="san in detailData.san_domains" :key="san" class="rounded bg-brand-500/15 px-1.5 py-0.5 text-xs text-brand-300">{{ san }}</code>
                                        </div>
                                    </div>
                                    <div v-if="detailData.fingerprint_sha256" class="col-span-2 rounded-lg bg-white/5 px-3 py-2">
                                        <p class="text-[10px] uppercase tracking-wider text-white/40">{{ t('Fingerprint (SHA-256)') }}</p>
                                        <p class="break-all font-mono text-xs">{{ detailData.fingerprint_sha256 }}</p>
                                    </div>
                                </div>

                                <!-- PEM Content Tabs -->
                                <div class="mb-3 flex gap-1 border-b border-white/10">
                                    <button
                                        v-for="tab in pemTabs"
                                        :key="tab.key"
                                        type="button"
                                        @click="activeDetailTab = tab.key"
                                        class="border-b-2 px-3 py-2 text-xs font-medium transition-colors"
                                        :class="activeDetailTab === tab.key
                                            ? 'border-brand-500 text-brand-400'
                                            : 'border-transparent text-white/50 hover:text-white/70'"
                                    >
                                        {{ tab.label }}
                                    </button>
                                </div>

                                <div class="relative">
                                    <textarea
                                        :value="currentPemContent"
                                        readonly
                                        rows="12"
                                        class="w-full rounded-lg border border-white/10 bg-black/40 px-3 py-2 font-mono text-xs text-green-400 focus:outline-none"
                                        :placeholder="t('No content available')"
                                    ></textarea>
                                    <button
                                        v-if="currentPemContent"
                                        type="button"
                                        @click="copyToClipboard(currentPemContent)"
                                        class="absolute right-2 top-2 rounded bg-white/10 px-2 py-1 text-xs text-white/60 hover:bg-white/20 hover:text-white"
                                    >
                                        <i class="bx bx-copy mr-1"></i>{{ t('Copy') }}
                                    </button>
                                </div>

                                <!-- CSR Awaiting Certificate: Install Form -->
                                <div v-if="detailData.csr_pem && !detailData.certificate_pem" class="mt-5 rounded-lg border border-warning-500/30 bg-warning-500/5 p-4">
                                    <h6 class="mb-3 flex items-center gap-2 text-sm font-semibold text-warning-400">
                                        <i class="bx bx-upload"></i>
                                        {{ t('Install Signed Certificate') }}
                                    </h6>
                                    <p class="mb-4 text-xs text-white/50">{{ t('Paste the signed certificate you received from your Certificate Authority.') }}</p>

                                    <form @submit.prevent="submitCompleteCsr">
                                        <div class="space-y-3">
                                            <div>
                                                <label class="mb-1 block text-xs font-medium text-white/70">{{ t('Certificate') }} <span class="text-error-400">*</span></label>
                                                <textarea
                                                    v-model="completeCsrForm.certificate"
                                                    rows="6"
                                                    class="form-input font-mono text-xs"
                                                    placeholder="-----BEGIN CERTIFICATE-----"
                                                    required
                                                ></textarea>
                                            </div>
                                            <div>
                                                <label class="mb-1 block text-xs font-medium text-white/70">{{ t('CA Bundle') }}</label>
                                                <textarea
                                                    v-model="completeCsrForm.ca_bundle"
                                                    rows="4"
                                                    class="form-input font-mono text-xs"
                                                    :placeholder="t('Intermediate certificates (optional)')"
                                                ></textarea>
                                            </div>
                                            <div>
                                                <label class="mb-1 block text-xs font-medium text-white/70">{{ t('Label') }}</label>
                                                <input
                                                    v-model="completeCsrForm.label"
                                                    type="text"
                                                    class="form-input"
                                                    :placeholder="t('My Certificate (optional)')"
                                                />
                                            </div>
                                            <button
                                                type="submit"
                                                :disabled="completeCsrForm.processing || !completeCsrForm.certificate"
                                                class="inline-flex w-full items-center justify-center gap-2 rounded-lg bg-brand-500 px-4 py-2.5 text-sm font-medium text-white hover:bg-brand-600 disabled:opacity-50"
                                            >
                                                <i class="bx bx-check-circle"></i>
                                                {{ completeCsrForm.processing ? t('Installing...') : t('Install Certificate') }}
                                            </button>
                                        </div>
                                    </form>
                                </div>
                            </template>
                        </div>

                        <div class="flex items-center justify-between border-t border-white/10 px-5 py-3">
                            <div class="flex gap-2">
                                <button
                                    v-if="detailCert && !detailCert.is_active && detailCert.has_certificate"
                                    type="button"
                                    :disabled="activating === detailCert.id"
                                    @click="activateCert(detailCert); showDetailModal = false"
                                    class="inline-flex items-center gap-1.5 rounded-lg bg-brand-500 px-4 py-2 text-sm font-medium text-white hover:bg-brand-600 disabled:opacity-50"
                                >
                                    <i class="bx bx-check-circle"></i>
                                    {{ t('Activate') }}
                                </button>
                            </div>
                            <button
                                type="button"
                                class="inline-flex items-center gap-2 rounded-lg border border-white/20 px-4 py-2 text-sm font-medium text-white/80 hover:bg-white/10"
                                @click="showDetailModal = false"
                            >
                                {{ t('Close') }}
                            </button>
                        </div>
                    </div>
                </div>
            </AdminLayout>
        </SidebarProvider>
    </ThemeProvider>
</template>

<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import axios from 'axios';
import ThemeProvider from '@/Components/Layout/ThemeProvider.vue';
import SidebarProvider from '@/Components/Layout/SidebarProvider.vue';
import AdminLayout from '@/Components/Layout/AdminLayout.vue';
import PageBreadcrumb from '@/Components/Common/PageBreadcrumb.vue';
import FormField from '@/Components/UI/FormField.vue';
import Toast from '@/Components/UI/Toast.vue';
import { useToast } from '@/Composables/useToast';
import { useI18n } from '@/Composables/useI18n';
import { loadSweetAlert } from '@/utils/sweetalert';
import { formatDateTime } from '@/utils/dateTime';

interface Certificate {
    id: number;
    type: string;
    label: string | null;
    common_name: string;
    issuer: string | null;
    san_domains: string[] | null;
    certificate_pem: string | null;
    csr_pem: string | null;
    not_before: string | null;
    not_after: string | null;
    is_wildcard: boolean;
    auto_renew: boolean;
    fingerprint_sha256: string | null;
    is_active: boolean;
    is_expired: boolean;
    is_expiring_soon: boolean;
    days_until_expiry: number | null;
    has_certificate: boolean;
    created_at: string;
}

interface Domain {
    id: number;
    fqdn: string;
    dns_provider: string | null;
    active_ssl_certificate_id: number | null;
}

const props = defineProps<{
    domain: Domain;
    certificates: Certificate[];
    activeCertificateId: number | null;
}>();

const { t } = useI18n();
const { addToast } = useToast();

const showLeModal = ref(false);
const showCsrModal = ref(false);
const showUploadModal = ref(false);
const showDetailModal = ref(false);
const activating = ref<number | null>(null);
const deleting = ref<number | null>(null);
const importFileInput = ref<HTMLInputElement | null>(null);

// -- SSL Operation Progress State --
const sslMessage = ref(t('Starting SSL operation...'));
const sslPercent = ref(0);
const sslState = ref<'running' | 'completed' | 'failed' | 'cancelled'>('running');
const sslOperationActive = ref(false);

const sslMessageClass = computed(() => {
    if (sslState.value === 'completed') return 'border-success-200 bg-success-50 text-success-700 dark:border-success-800 dark:bg-success-900/20 dark:text-success-400';
    if (sslState.value === 'failed' || sslState.value === 'cancelled') return 'border-error-200 bg-error-50 text-error-700 dark:border-error-800 dark:bg-error-900/20 dark:text-error-400';
    return 'border-blue-200 bg-blue-50 text-blue-700 dark:border-blue-800 dark:bg-blue-900/20 dark:text-blue-400';
});

const sslProgressClass = computed(() => {
    if (sslState.value === 'completed') return 'bg-success-500';
    if (sslState.value === 'failed' || sslState.value === 'cancelled') return 'bg-error-500';
    return 'bg-brand-500';
});

const cancelSslOperation = () => {
    router.post(route('domains.ssl.cancel', props.domain.id), {}, {
        preserveScroll: true,
        onSuccess: () => {
            sslState.value = 'cancelled';
            sslMessage.value = t('SSL operation cancelled.');
        },
    });
};

// -- WebSocket Listener --
onMounted(() => {
    if (typeof window.Echo !== 'undefined') {
        window.Echo.channel(`domain.${props.domain.id}`)
            .listen('.SslOperationProgress', (event: any) => {
                sslOperationActive.value = true;
                sslMessage.value = event.message;
                sslPercent.value = event.percent;
                sslState.value = event.status;

                if (event.status === 'completed') {
                    setTimeout(() => {
                        sslOperationActive.value = false;
                        router.reload({ only: ['certificates', 'domain'] });
                    }, 2000);
                }
                if (event.status === 'failed' || event.status === 'cancelled') {
                    setTimeout(() => {
                        sslOperationActive.value = false;
                    }, 5000);
                }
            });
    }
});

onUnmounted(() => {
    if (typeof window.Echo !== 'undefined') {
        window.Echo.leave(`domain.${props.domain.id}`);
    }
});

// -- Detail Modal State --
interface CertDetail {
    id: number;
    label: string | null;
    type: string;
    common_name: string;
    issuer: string | null;
    san_domains: string[] | null;
    not_before: string | null;
    not_after: string | null;
    fingerprint_sha256: string | null;
    is_wildcard: boolean;
    is_active: boolean;
    certificate_pem: string | null;
    private_key_pem: string | null;
    ca_bundle_pem: string | null;
    csr_pem: string | null;
}

const detailCert = ref<Certificate | null>(null);
const detailData = ref<CertDetail | null>(null);
const detailLoading = ref(false);
const activeDetailTab = ref<'cert' | 'key' | 'ca' | 'csr'>('cert');

const pemTabs = computed(() => {
    const tabs: Array<{ key: 'cert' | 'key' | 'ca' | 'csr'; label: string }> = [
        { key: 'cert', label: t('Certificate') },
        { key: 'key', label: t('Private Key') },
    ];
    if (detailData.value?.ca_bundle_pem) {
        tabs.push({ key: 'ca', label: t('CA Bundle') });
    }
    if (detailData.value?.csr_pem) {
        tabs.push({ key: 'csr', label: t('CSR') });
    }
    return tabs;
});

const currentPemContent = computed(() => {
    if (!detailData.value) return '';
    switch (activeDetailTab.value) {
        case 'cert': return detailData.value.certificate_pem ?? '';
        case 'key': return detailData.value.private_key_pem ?? '';
        case 'ca': return detailData.value.ca_bundle_pem ?? '';
        case 'csr': return detailData.value.csr_pem ?? '';
        default: return '';
    }
});

const openCertDetail = async (cert: Certificate): Promise<void> => {
    detailCert.value = cert;
    detailData.value = null;
    detailLoading.value = true;
    activeDetailTab.value = 'cert';
    showDetailModal.value = true;

    try {
        const response = await axios.get(route('domains.ssl.show', [props.domain.id, cert.id]));
        detailData.value = response.data;
    } catch {
        addToast('error', t('Failed to load certificate details.'));
        showDetailModal.value = false;
    } finally {
        detailLoading.value = false;
    }
};

const copyToClipboard = async (text: string): Promise<void> => {
    try {
        await navigator.clipboard.writeText(text);
        addToast('success', t('Copied to clipboard.'));
    } catch {
        addToast('error', t('Failed to copy.'));
    }
};

// -- Import PEM File --
const handleImportFile = (event: Event): void => {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0];
    if (!file) return;

    const formData = new FormData();
    formData.append('pem_file', file);

    router.post(route('domains.ssl.import', props.domain.id), formData as any, {
        forceFormData: true,
        preserveScroll: true,
        onFinish: () => {
            if (importFileInput.value) {
                importFileInput.value.value = '';
            }
        },
    });
};

const breadcrumbs = computed(() => [
    { label: t('Domains'), href: route('domains.index') },
    { label: props.domain.fqdn, href: route('domains.show', props.domain.id) },
    { label: t('SSL Certificates') },
]);

const activeCert = computed(() => {
    return props.certificates.find((cert) => cert.is_active) ?? null;
});

// -- Let's Encrypt Form --

const leForm = useForm({
    validation_method: 'dns-01-cloudflare' as 'dns-01-cloudflare' | 'dns-01-local' | 'http-01',
});

const submitLe = (): void => {
    leForm.post(route('domains.ssl.letsencrypt', props.domain.id), {
        preserveScroll: true,
        onSuccess: () => {
            showLeModal.value = false;
            sslOperationActive.value = true;
            sslPercent.value = 5;
            sslMessage.value = t('SSL operation queued...');
            sslState.value = 'running';
            addToast('success', t('Certificate requested successfully.'));
            leForm.reset();
        },
    });
};

// -- CSR Form --

const csrForm = useForm({
    common_name: props.domain.fqdn,
    san_domains: [] as string[],
    organization: '',
    organizational_unit: '',
    country: '',
    state: '',
    locality: '',
    email: '',
    key_type: 'rsa2048',
});

const addSanDomain = (): void => {
    csrForm.san_domains.push('');
};

const removeSanDomain = (index: number): void => {
    csrForm.san_domains.splice(index, 1);
};

const submitCsr = (): void => {
    csrForm.post(route('domains.ssl.csr', props.domain.id), {
        preserveScroll: true,
        onSuccess: () => {
            showCsrModal.value = false;
            csrForm.reset();
            csrForm.common_name = props.domain.fqdn;
        },
    });
};

// -- Upload Form --

const uploadForm = useForm({
    label: '',
    private_key: '',
    certificate: '',
    ca_bundle: '',
});

// -- Complete CSR Form --
const completeCsrForm = useForm({
    certificate: '',
    ca_bundle: '',
    label: '',
});

const submitCompleteCsr = (): void => {
    if (!detailCert.value) return;
    completeCsrForm.post(route('domains.ssl.complete-csr', [props.domain.id, detailCert.value.id]), {
        preserveScroll: true,
        onSuccess: () => {
            showDetailModal.value = false;
            completeCsrForm.reset();
        },
    });
};

const uploadKeyStatus = ref<'idle' | 'checking' | 'valid' | 'invalid'>('idle');
const uploadKeyError = ref('');
let keyCheckTimeout: ReturnType<typeof setTimeout> | null = null;

const isPemFormat = (pem: string, type: 'key' | 'cert'): boolean => {
    if (type === 'key') {
        return /-----BEGIN (RSA |EC |ENCRYPTED )?PRIVATE KEY-----/.test(pem)
            && /-----END (RSA |EC |ENCRYPTED )?PRIVATE KEY-----/.test(pem);
    }
    return pem.includes('-----BEGIN CERTIFICATE-----')
        && pem.includes('-----END CERTIFICATE-----');
};

const checkKeyMatch = (): void => {
    const key = uploadForm.private_key.trim();
    const cert = uploadForm.certificate.trim();

    // Both empty — nothing to validate yet
    if (!key && !cert) {
        uploadKeyStatus.value = 'idle';
        uploadKeyError.value = '';
        return;
    }

    // Check PEM format before sending to server
    if (key && !isPemFormat(key, 'key')) {
        uploadKeyStatus.value = 'invalid';
        uploadKeyError.value = t('Invalid private key format. Must be PEM format (-----BEGIN PRIVATE KEY-----).');
        return;
    }

    if (cert && !isPemFormat(cert, 'cert')) {
        uploadKeyStatus.value = 'invalid';
        uploadKeyError.value = t('Invalid certificate format. Must be PEM format (-----BEGIN CERTIFICATE-----).');
        return;
    }

    // Need both to validate match
    if (!key || !cert) {
        uploadKeyStatus.value = 'idle';
        uploadKeyError.value = '';
        return;
    }

    uploadKeyStatus.value = 'checking';
    uploadKeyError.value = '';

    if (keyCheckTimeout) clearTimeout(keyCheckTimeout);
    keyCheckTimeout = setTimeout(async () => {
        try {
            const response = await axios.post(route('domains.ssl.validate-key', props.domain.id), {
                private_key: key,
                certificate: cert,
                ca_bundle: uploadForm.ca_bundle.trim() || null,
            });
            uploadKeyStatus.value = response.data.valid ? 'valid' : 'invalid';
            uploadKeyError.value = response.data.valid ? '' : (response.data.message ?? t('Private key does not match the certificate.'));
        } catch {
            uploadKeyStatus.value = 'invalid';
            uploadKeyError.value = t('Could not validate key pair.');
        }
    }, 500);
};

watch(() => uploadForm.private_key, checkKeyMatch);
watch(() => uploadForm.certificate, checkKeyMatch);
watch(() => uploadForm.ca_bundle, checkKeyMatch);

const canSubmitUpload = computed(() => {
    return uploadKeyStatus.value === 'valid' && !uploadForm.processing;
});

const submitUpload = (): void => {
    uploadForm.post(route('domains.ssl.upload', props.domain.id), {
        preserveScroll: true,
        onSuccess: () => {
            showUploadModal.value = false;
            uploadForm.reset();
            uploadKeyStatus.value = 'idle';
            uploadKeyError.value = '';
        },
    });
};

// -- Activate / Delete --

const activateCert = (cert: Certificate): void => {
    activating.value = cert.id;
    router.post(route('domains.ssl.cert.activate', [props.domain.id, cert.id]), {}, {
        preserveScroll: true,
        onFinish: () => {
            activating.value = null;
        },
    });
};

const deleteCert = async (cert: Certificate): Promise<void> => {
    const swal = await loadSweetAlert();
    if (!swal) {
        return;
    }

    const result = await swal.fire({
        title: t('Delete Certificate?'),
        text: t('This will permanently remove the certificate ":name". This action cannot be undone.', { name: cert.label || cert.common_name }),
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: t('Delete'),
        cancelButtonText: t('Cancel'),
    });

    if (!result.isConfirmed) {
        return;
    }

    deleting.value = cert.id;
    router.delete(route('domains.ssl.destroy', [props.domain.id, cert.id]), {
        preserveScroll: true,
        onSuccess: () => {
            addToast('success', t('Certificate deleted successfully.'));
        },
        onError: () => {
            addToast('error', t('Failed to delete certificate.'));
        },
        onFinish: () => {
            deleting.value = null;
        },
    });
};

// -- Helpers --

const typeLabel = (cert: Certificate): string => {
    switch (cert.type) {
        case 'letsencrypt':
            return t("Let's Encrypt");
        case 'custom':
            return t('Custom');
        case 'self_signed':
            return t('Self-Signed');
        default:
            return cert.type;
    }
};

const typeBadgeClass = (cert: Certificate): string => {
    const base = 'inline-flex rounded-full px-2 py-0.5 text-xs font-semibold';
    switch (cert.type) {
        case 'letsencrypt':
            return `${base} bg-success-500/15 text-success-700 dark:text-success-300`;
        case 'custom':
            return `${base} bg-blue-light-500/15 text-blue-light-700 dark:text-blue-light-300`;
        case 'self_signed':
            return `${base} bg-warning-500/15 text-warning-700 dark:text-warning-300`;
        default:
            return `${base} bg-gray-200 text-gray-700 dark:bg-gray-700 dark:text-gray-300`;
    }
};

const expiryBadgeClass = (cert: Certificate): string => {
    const base = 'inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium';
    if (cert.is_expired) {
        return `${base} bg-error-500/15 text-error-700 dark:text-error-300`;
    }
    if (cert.is_expiring_soon) {
        return `${base} bg-warning-500/15 text-warning-700 dark:text-warning-300`;
    }
    return `${base} bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-400`;
};
</script>

<style scoped>
@reference "../../../css/app.css";

.form-input {
    @apply h-11 w-full rounded-lg border border-white/20 bg-black/20 px-4 py-2.5 text-sm text-white shadow-theme-xs placeholder:text-white/40 focus:border-brand-400 focus:outline-hidden focus:ring-3 focus:ring-brand-500/20;
}

select.form-input {
    @apply border-white/25 bg-[#202020];
}

select.form-input option {
    @apply bg-[#202020] text-white;
}

textarea.form-input {
    @apply h-auto;
}
</style>
