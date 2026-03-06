<template>
    <Head :title="domain.fqdn" />
    <ThemeProvider>
        <SidebarProvider>
            <AdminLayout>
                <PageBreadcrumb :pageTitle="domain.fqdn" :items="breadcrumbs" />
                <Toast />

                <div class="space-y-4 md:space-y-6">
                    <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/3 md:p-6">
                        <div class="mb-5 flex items-center">
                            <h3 class="text-lg font-semibold text-gray-800 dark:text-white/90">
                                {{ domain.fqdn }}
                            </h3>
                            <div class="ml-auto flex items-center gap-2">
                                <a
                                    :href="previewUrl"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    class="inline-flex h-9 w-9 items-center justify-center rounded-lg border border-gray-300 bg-white text-gray-700 shadow-theme-xs hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-white/3"
                                    v-tooltip="t('Preview')"
                                >
                                    <i class="fa-solid fa-globe text-sm"></i>
                                </a>
                                <button
                                    v-if="!isSubdomain"
                                    type="button"
                                    :disabled="!isCloudflareManagedForDns || cloudflareActionLoading"
                                    @click="purgeCloudflareCache"
                                    class="inline-flex h-9 w-9 items-center justify-center rounded-lg border border-gray-300 bg-white text-gray-700 shadow-theme-xs hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-50 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-white/3"
                                    v-tooltip="!isCloudflareManagedForDns ? t('Cloudflare is not active for this domain.') : t('Purge Cache')"
                                >
                                    <i class="fa-solid fa-broom text-sm"></i>
                                </button>
                                <Link
                                    :href="route('domains.edit', domain.id)"
                                    class="inline-flex h-9 w-9 items-center justify-center rounded-lg border border-gray-300 bg-white text-gray-700 shadow-theme-xs hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-white/3"
                                    v-tooltip="t('Edit')"
                                >
                                    <i class="fa-solid fa-gears text-sm"></i>
                                </Link>
                                <button
                                    type="button"
                                    @click="deleteDomain(domain.id, false, domain.fqdn)"
                                    class="inline-flex h-9 w-9 items-center justify-center rounded-lg border border-error-500/40 text-error-600 hover:bg-error-500/10 dark:text-error-300"
                                    v-tooltip="t('Delete')"
                                >
                                    <i class="fa-solid fa-trash text-sm"></i>
                                </button>
                            </div>
                        </div>

                        <dl class="grid grid-cols-1 gap-x-8 gap-y-2 text-sm sm:grid-cols-2">
                            <template v-for="row in summaryInfoRows" :key="row.key">
                                <dt class="text-gray-500 dark:text-gray-400">{{ row.label }}</dt>
                                <dd class="text-gray-800 dark:text-white/90">
                                    <template v-if="row.html">
                                        <span v-html="row.value"></span>
                                    </template>
                                    <template v-else>
                                        {{ row.value }}
                                    </template>
                                </dd>
                            </template>
                        </dl>

                        <div class="mt-4 flex items-center justify-end">
                            <button
                                type="button"
                                @click="showDomainDetails = !showDomainDetails"
                                class="inline-flex items-center gap-2 rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-xs font-medium text-gray-700 shadow-theme-xs hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-white/3"
                            >
                                {{ showDomainDetails ? t('Close') : t('Open') }}
                                <i :class="showDomainDetails ? 'fa-solid fa-chevron-up' : 'fa-solid fa-chevron-down'" class="text-[10px]"></i>
                            </button>
                        </div>

                        <div
                            class="overflow-hidden transition-all duration-300 ease-out"
                            :class="showDomainDetails ? 'mt-4 max-h-[1600px] opacity-100' : 'max-h-0 opacity-0'"
                        >
                            <dl class="grid grid-cols-1 gap-x-8 gap-y-2 text-sm sm:grid-cols-2">
                                <template v-for="row in detailInfoRows" :key="row.key">
                                    <dt class="text-gray-500 dark:text-gray-400">{{ row.label }}</dt>
                                    <dd class="text-gray-800 dark:text-white/90">
                                        <template v-if="row.html">
                                            <span v-html="row.value"></span>
                                        </template>
                                        <template v-else>
                                            {{ row.value }}
                                        </template>
                                    </dd>
                                </template>
                            </dl>
                        </div>
                    </div>

                    <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
                        <h4 class="mb-4 text-sm font-semibold text-gray-800 dark:text-white/90">{{ t('Quick Links') }}</h4>
                        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
                            <template v-if="hasStoredFtpPassword">
                                <Link
                                    :href="route('domains.files.index', domain.id)"
                                    class="quick-link"
                                >
                                    <i class="fa-solid fa-folder-open quick-link-icon"></i>
                                    <span class="quick-link-label">{{ t('File Manager') }}</span>
                                    <i class="fa-solid fa-angle-right quick-link-arrow"></i>
                                </Link>
                            </template>
                            <template v-else>
                                <button
                                    type="button"
                                    @click="showFtpModal = true"
                                    class="quick-link"
                                    v-tooltip="t('FTP user with stored password required. Update FTP password first.')"
                                >
                                    <i class="fa-solid fa-folder-open quick-link-icon"></i>
                                    <span class="quick-link-label">{{ t('File Manager') }}</span>
                                    <small class="quick-link-warning"><i class="fa-solid fa-exclamation-triangle"></i></small>
                                    <i class="fa-solid fa-angle-right quick-link-arrow"></i>
                                </button>
                            </template>

                            <Link :href="route('domains.databases.index', domain.id)" class="quick-link">
                                <i class="fa-solid fa-database quick-link-icon"></i>
                                <span class="quick-link-label">{{ t('Databases') }}</span>
                                <i class="fa-solid fa-angle-right quick-link-arrow"></i>
                            </Link>

                            <button type="button" @click="activateSsl(domain.id)" :disabled="sslLoading" class="quick-link disabled:opacity-60">
                                <i class="fa-brands fa-expeditedssl quick-link-icon"></i>
                                <span class="quick-link-label">{{ t('SSL Certificate') }}</span>
                                <i class="fa-solid fa-angle-right quick-link-arrow"></i>
                            </button>

                            <button type="button" @click="showFtpModal = true" class="quick-link">
                                <i class="fa-solid fa-user-pen quick-link-icon"></i>
                                <span class="quick-link-label">{{ t('FTP Users') }}</span>
                                <i class="fa-solid fa-angle-right quick-link-arrow"></i>
                            </button>

                            <Link
                                v-if="!isSubdomain && isCloudflareManagedForDns"
                                :href="route('domains.dns.index', domain.id)"
                                class="quick-link"
                            >
                                <i class="fa-solid fa-globe quick-link-icon"></i>
                                <span class="quick-link-label">{{ t('DNS') }}</span>
                                <i class="fa-solid fa-angle-right quick-link-arrow"></i>
                            </Link>
                            <button
                                v-else-if="!isSubdomain"
                                type="button"
                                disabled
                                class="quick-link quick-link-disabled"
                                v-tooltip="t('DNS management is locked because this domain is not managed on Cloudflare.')"
                            >
                                <i class="fa-solid fa-globe quick-link-icon"></i>
                                <span class="quick-link-label">{{ t('DNS') }}</span>
                                <i class="fa-solid fa-angle-right quick-link-arrow"></i>
                            </button>

                            <Link :href="route('domains.edit', domain.id)" class="quick-link">
                                <i class="fa-solid fa-gears quick-link-icon"></i>
                                <span class="quick-link-label">{{ t('Vhost Settings') }}</span>
                                <i class="fa-solid fa-angle-right quick-link-arrow"></i>
                            </Link>

                            <Link v-if="canManagePhpSettings" :href="route('domains.php.index', domain.id)" class="quick-link">
                                <i class="fa-brands fa-php quick-link-icon"></i>
                                <span class="quick-link-label">{{ t('PHP Settings') }}</span>
                                <i class="fa-solid fa-angle-right quick-link-arrow"></i>
                            </Link>

                            <Link :href="route('domains.supervisor.index', domain.id)" class="quick-link">
                                <i class="fa-brands fa-laravel quick-link-icon"></i>
                                <span class="quick-link-label">{{ t('Laravel Processes') }}</span>
                                <i class="fa-solid fa-angle-right quick-link-arrow"></i>
                            </Link>

                            <button type="button" @click="openJenkinsModal(domain.fqdn)" class="quick-link">
                                <i class="fa-brands fa-jenkins quick-link-icon"></i>
                                <span class="quick-link-label">{{ t('Jenkins file') }}</span>
                                <i class="fa-solid fa-angle-right quick-link-arrow"></i>
                            </button>

                            <button
                                v-if="!isSubdomain"
                                type="button"
                                :disabled="!isCloudflareManagedForDns || cloudflareActionLoading"
                                class="quick-link"
                                v-tooltip="!isCloudflareManagedForDns ? t('Cloudflare is not active for this domain.') : t('Toggle Under Attack mode')"
                                @click="toggleUnderAttackQuick"
                            >
                                <i class="fa-solid fa-shield-halved quick-link-icon"></i>
                                <span class="quick-link-label">{{ t('Under Attack Mode') }}</span>
                                <span class="inline-flex rounded-full px-2 py-0.5 text-[10px] font-semibold"
                                    :class="underAttackEnabled ? 'bg-error-500/20 text-error-600 dark:text-error-300' : 'bg-success-500/20 text-success-600 dark:text-success-300'">
                                    {{ underAttackEnabled ? t('On') : t('Off') }}
                                </span>
                            </button>

                            <Link
                                v-if="!isSubdomain"
                                :href="route('domains.cloudflare.manage', domain.id)"
                                class="quick-link"
                            >
                                <i class="fa-brands fa-cloudflare quick-link-icon"></i>
                                <span class="quick-link-label">{{ t('Cloudflare') }}</span>
                                <span
                                    class="inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-semibold"
                                    :class="isCloudflareManagedForDns ? 'bg-success-500/20 text-success-600 dark:text-success-300' : 'bg-gray-200 text-gray-700 dark:bg-gray-700 dark:text-gray-300'"
                                >
                                    <i :class="isCloudflareManagedForDns ? 'fa-solid fa-circle-check' : 'fa-solid fa-circle-xmark'" class="mr-1 text-[9px]"></i>
                                    {{ isCloudflareManagedForDns ? t('On') : t('Off') }}
                                </span>
                                <i class="fa-solid fa-angle-right quick-link-arrow"></i>
                            </Link>
                        </div>
                    </div>

                    <div v-if="!isSubdomain" class="space-y-3">
                        <div class="flex items-center">
                            <h4 class="text-lg font-semibold text-gray-800 dark:text-white/90">{{ t('Subdomains') }}</h4>
                            <div class="ml-auto">
                                <button
                                    type="button"
                                    @click="showCreateSubdomainModal = true"
                                    class="inline-flex items-center gap-2 rounded-lg bg-brand-500 px-3 py-2 text-xs font-medium text-white shadow-theme-xs hover:bg-brand-600"
                                >
                                    <i class="bx bx-plus text-base"></i>
                                    {{ t('Add Subdomain') }}
                                </button>
                            </div>
                        </div>

                        <div
                            v-for="subdomain in subdomains"
                            :key="subdomain.id"
                            class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/3"
                        >
                            <div class="mb-4 flex items-center">
                                <h5 class="font-semibold text-gray-800 dark:text-white/90">{{ subdomain.fqdn }}</h5>
                                <div class="ml-auto flex items-center gap-2">
                                    <a
                                        :href="`https://${subdomain.fqdn}`"
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        class="inline-flex h-8 w-8 items-center justify-center rounded-lg border border-gray-300 text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-white/3"
                                        v-tooltip="t('Preview')"
                                    >
                                        <i class="fa-solid fa-globe text-xs"></i>
                                    </a>
                                    <Link
                                        :href="route('domains.edit', subdomain.id)"
                                        class="inline-flex h-8 w-8 items-center justify-center rounded-lg border border-gray-300 text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-white/3"
                                        v-tooltip="t('Edit')"
                                    >
                                        <i class="fa-solid fa-gears text-xs"></i>
                                    </Link>
                                    <button
                                        type="button"
                                        @click="deleteDomain(subdomain.id, true, subdomain.fqdn)"
                                        class="inline-flex h-8 w-8 items-center justify-center rounded-lg border border-error-500/40 text-error-600 hover:bg-error-500/10 dark:text-error-300"
                                        v-tooltip="t('Delete')"
                                    >
                                        <i class="fa-solid fa-trash text-xs"></i>
                                    </button>
                                </div>
                            </div>

                            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
                                <button
                                    type="button"
                                    @click="activateSsl(subdomain.id)"
                                    :disabled="sslLoading"
                                    class="quick-link disabled:opacity-60"
                                >
                                    <i class="fa-brands fa-expeditedssl quick-link-icon"></i>
                                    <span class="quick-link-label">{{ t('SSL Certificate') }}</span>
                                    <i class="fa-solid fa-angle-right quick-link-arrow"></i>
                                </button>

                                <Link :href="route('domains.edit', subdomain.id)" class="quick-link">
                                    <i class="fa-solid fa-gears quick-link-icon"></i>
                                    <span class="quick-link-label">{{ t('Vhost Settings') }}</span>
                                    <i class="fa-solid fa-angle-right quick-link-arrow"></i>
                                </Link>

                                <Link
                                    v-if="subdomain.type === 'apache_reverse_proxy'"
                                    :href="route('domains.php.index', subdomain.id)"
                                    class="quick-link"
                                >
                                    <i class="fa-brands fa-php quick-link-icon"></i>
                                    <span class="quick-link-label">{{ t('PHP Settings') }}</span>
                                    <i class="fa-solid fa-angle-right quick-link-arrow"></i>
                                </Link>

                                <Link :href="route('domains.supervisor.index', subdomain.id)" class="quick-link">
                                    <i class="fa-brands fa-laravel quick-link-icon"></i>
                                    <span class="quick-link-label">{{ t('Laravel Processes') }}</span>
                                    <i class="fa-solid fa-angle-right quick-link-arrow"></i>
                                </Link>

                                <button type="button" @click="openJenkinsModal(subdomain.fqdn)" class="quick-link">
                                    <i class="fa-brands fa-jenkins quick-link-icon"></i>
                                    <span class="quick-link-label">{{ t('Jenkins file') }}</span>
                                    <i class="fa-solid fa-angle-right quick-link-arrow"></i>
                                </button>
                            </div>
                        </div>

                        <div
                            v-if="subdomains.length === 0"
                            class="rounded-2xl border border-dashed border-gray-300 bg-gray-50 px-4 py-6 text-sm text-gray-500 dark:border-gray-700 dark:bg-white/3 dark:text-gray-400"
                        >
                            {{ t('No subdomains found yet.') }}
                        </div>
                    </div>

                    <div v-if="showProvisioningCard" class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
                        <h4 class="mb-3 text-sm font-semibold text-gray-800 dark:text-white/90">{{ t('Provisioning Status') }}</h4>
                        <div :class="['mb-3 rounded-lg border px-3 py-2 text-sm', provisioningMessageClass]">
                            {{ provisioningMessage }}
                        </div>
                        <div class="h-2 w-full overflow-hidden rounded-full bg-gray-200 dark:bg-gray-800">
                            <div
                                :class="['h-full transition-all duration-300', provisioningProgressClass]"
                                :style="{ width: `${provisioningPercent}%` }"
                            ></div>
                        </div>
                        <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">{{ provisioningPercentLabel }}</p>
                    </div>
                </div>

                <DomainCreateModal
                    v-model="showCreateSubdomainModal"
                    :php-versions="phpVersions"
                    :parent-domain-id="Number(domain.id)"
                    :parent-domain-fqdn="domain.fqdn"
                    :parent-domain-root-path="parentDomainWebRootPath"
                    :parent-cloudflare-managed="isCloudflareManagedForDns"
                    :server-network-ips="server_network_ips"
                />

                <!-- FTP Modal -->
                <div
                    v-if="showFtpModal"
                    class="fixed inset-0 z-[1200000] flex items-center justify-center bg-black/60 p-4 backdrop-blur-sm"
                >
                    <div class="w-full max-w-xl rounded-xl border border-gray-700 bg-[#171717] text-white/80 shadow-2xl">
                        <div class="flex items-center justify-between border-b border-white/10 px-5 py-4">
                            <h5 class="text-base text-white">
                                <i class="fa-solid fa-user-pen mr-1 opacity-70"></i>
                                {{ t('FTP User') }} - {{ domain.fqdn }}
                            </h5>
                            <button type="button" class="text-2xl leading-none text-white/50 hover:text-white" @click="showFtpModal = false">&times;</button>
                        </div>

                        <div class="space-y-4 p-5">
                            <template v-if="domain.ftp_user">
                                <dl class="grid grid-cols-1 gap-1 text-sm sm:grid-cols-2">
                                    <dt class="text-white/50">{{ t('Username') }}</dt>
                                    <dd><code class="rounded bg-white/10 px-2 py-0.5 text-cyan-200">{{ domain.ftp_user.username }}</code></dd>
                                    <dt class="text-white/50">{{ t('Home Path') }}</dt>
                                    <dd><code class="rounded bg-white/10 px-2 py-0.5 text-cyan-200">{{ domain.ftp_user.home_path }}</code></dd>
                                    <dt class="text-white/50">{{ t('UID') }}</dt>
                                    <dd>{{ domain.ftp_user.uid }}</dd>
                                </dl>
                                <hr class="border-white/10" />
                                <h6 class="text-sm font-medium text-white">{{ t('Change Password') }}</h6>
                            </template>
                            <template v-else>
                                <p class="text-sm text-white/60">{{ t('No FTP user exists for this domain. Create one below.') }}</p>
                                <div class="space-y-1">
                                    <label class="text-xs text-white/60">{{ t('Username') }}</label>
                                    <input v-model="ftpForm.username" type="text" class="ftp-input" />
                                </div>
                            </template>

                            <div class="space-y-1">
                                <label class="text-xs text-white/60">{{ domain.ftp_user ? t('New Password') : t('Password') }}</label>
                                <div class="relative">
                                    <input
                                        v-model="ftpForm.password"
                                        :type="showFtpPassword ? 'text' : 'password'"
                                        class="ftp-input pr-28"
                                        minlength="8"
                                    />
                                    <div class="absolute inset-y-0 right-1 flex items-center gap-1">
                                        <button type="button" class="ftp-icon-btn" @click="showFtpPassword = !showFtpPassword" v-tooltip="t('Show/Hide')">
                                            <i :class="showFtpPassword ? 'bx bx-show' : 'bx bx-hide'"></i>
                                        </button>
                                        <button type="button" class="ftp-icon-btn" @click="generateFtpPassword" v-tooltip="t('Generate')">
                                            <i class="bx bx-refresh"></i>
                                        </button>
                                        <button
                                            v-if="ftpGeneratedPassword"
                                            type="button"
                                            class="ftp-icon-btn"
                                            :class="{ 'ftp-icon-btn-success': ftpCopiedPassword }"
                                            @click="copyFtpPassword"
                                            v-tooltip="t('Copy')"
                                        >
                                            <i :class="ftpCopiedPassword ? 'bx bx-check' : 'bx bx-copy'"></i>
                                        </button>
                                    </div>
                                </div>
                                <p v-if="ftpGeneratedPassword && !ftpCopiedPassword" class="text-xs text-warning-400">
                                    <i class="bx bx-info-circle mr-1"></i>
                                    {{ t('Copy the password before submitting.') }}
                                </p>
                            </div>

                            <div class="flex justify-end gap-2 pt-1">
                                <button type="button" class="rounded-md border border-white/20 px-3 py-2 text-sm text-white/70 hover:bg-white/10" @click="showFtpModal = false">
                                    {{ t('Cancel') }}
                                </button>
                                <button
                                    type="button"
                                    class="rounded-md bg-brand-500 px-3 py-2 text-sm font-medium text-white hover:bg-brand-600 disabled:opacity-60"
                                    :disabled="ftpSubmitting"
                                    @click="submitFtp"
                                >
                                    {{ ftpSubmitting ? t('Processing...') : (domain.ftp_user ? t('Update Password') : t('Create FTP User')) }}
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Jenkins Modal -->
                <div
                    v-if="showJenkinsModal"
                    class="fixed inset-0 z-[1200000] flex items-center justify-center bg-black/60 p-4 backdrop-blur-sm"
                >
                    <div class="flex max-h-[90vh] w-full max-w-4xl flex-col rounded-xl border border-gray-700 bg-[#171717] text-white/80 shadow-2xl">
                        <div class="flex items-center justify-between border-b border-white/10 px-5 py-4">
                            <h5 class="text-base text-white">
                                <i class="fa-brands fa-jenkins mr-1 opacity-70"></i>
                                {{ t('Jenkinsfile Generator') }}
                            </h5>
                            <button type="button" class="text-2xl leading-none text-white/50 hover:text-white" @click="closeJenkinsModal">&times;</button>
                        </div>

                        <div class="min-h-0 flex-1 overflow-y-auto p-5">
                            <div v-if="!jenkinsOutput" class="space-y-4">
                                <div class="space-y-1">
                                    <label class="text-xs text-white/60">{{ t('Repository URL') }}</label>
                                    <input v-model="jenkinsRepoUrl" type="text" class="ftp-input" :placeholder="t('git@github.com:user/repo.git')" />
                                </div>
                                <div class="space-y-1">
                                    <label class="text-xs text-white/60">{{ t('Branch') }}</label>
                                    <input v-model="jenkinsBranch" type="text" class="ftp-input" :placeholder="t('*/main')" />
                                </div>
                                <div class="flex justify-end gap-2">
                                    <button type="button" class="rounded-md border border-white/20 px-3 py-2 text-sm text-white/70 hover:bg-white/10" @click="closeJenkinsModal">
                                        {{ t('Cancel') }}
                                    </button>
                                    <button type="button" class="rounded-md bg-brand-500 px-3 py-2 text-sm font-medium text-white hover:bg-brand-600" @click="generateJenkinsfile">
                                        <i class="fa-solid fa-wand-magic-sparkles mr-1"></i>
                                        {{ t('Generate') }}
                                    </button>
                                </div>
                            </div>

                            <div v-else>
                                <div class="mb-3 flex items-center justify-between">
                                    <span class="text-xs text-white/60">{{ t('Jenkinsfile') }}</span>
                                    <div class="flex gap-2">
                                        <button type="button" class="rounded-md border border-white/20 px-3 py-1.5 text-xs text-white/70 hover:bg-white/10" @click="jenkinsOutput = ''">
                                            <i class="fa-solid fa-arrow-left mr-1"></i>
                                            {{ t('Back') }}
                                        </button>
                                        <button type="button" class="rounded-md bg-brand-500 px-3 py-1.5 text-xs font-medium text-white hover:bg-brand-600" @click="copyJenkinsfile">
                                            <i :class="jenkinsCopied ? 'fa-solid fa-check' : 'fa-solid fa-copy'" class="mr-1"></i>
                                            {{ jenkinsCopied ? t('Copied!') : t('Copy') }}
                                        </button>
                                    </div>
                                </div>
                                <pre class="jenkins-code-block">{{ jenkinsOutput }}</pre>
                            </div>
                        </div>
                    </div>
                </div>
            </AdminLayout>
        </SidebarProvider>
    </ThemeProvider>
</template>

<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import axios from 'axios';
import ThemeProvider from '@/Components/Layout/ThemeProvider.vue';
import SidebarProvider from '@/Components/Layout/SidebarProvider.vue';
import AdminLayout from '@/Components/Layout/AdminLayout.vue';
import PageBreadcrumb from '@/Components/Common/PageBreadcrumb.vue';
import Toast from '@/Components/UI/Toast.vue';
import DomainCreateModal from '@/Components/Domains/DomainCreateModal.vue';
import { useToast } from '@/Composables/useToast';
import { useI18n } from '@/Composables/useI18n';
import { formatDateTime } from '@/utils/dateTime';
import { loadSweetAlert } from '@/utils/sweetalert';

interface JenkinsTemplateParams {
    repoUrl: string;
    branch: string;
    siteRoot: string;
    stageDir: string;
}

interface CloudflareFirewallRule {
    id: string;
    action: string;
    expression: string;
    description: string;
}

type CloudflareToggleSettingKey =
    | 'always_use_https'
    | 'automatic_https_rewrites'
    | 'tls_1_3'
    | 'development_mode'
    | 'websockets'
    | 'ip_geolocation'
    | 'opportunistic_onion'
    | 'http3'
    | 'early_hints';

interface CloudflareFormState {
    security_level: string;
    ssl: string;
    min_tls_version: string;
    browser_cache_ttl: number;
    always_use_https: boolean;
    automatic_https_rewrites: boolean;
    tls_1_3: boolean;
    development_mode: boolean;
    websockets: boolean;
    ip_geolocation: boolean;
    opportunistic_onion: boolean;
    http3: boolean;
    early_hints: boolean;
    dnssec_status: 'active' | 'disabled';
    hsts: {
        enabled: boolean;
        max_age: number;
        include_subdomains: boolean;
        preload: boolean;
        nosniff: boolean;
    };
}

const props = defineProps<{
    domain: Record<string, any>;
    phpVersions: Array<Record<string, any>>;
    cloudflare_zone: {
        exists: boolean;
        zone_id: string | null;
        zone_name: string | null;
        status: string | null;
        name_servers: string[];
    };
    server_network_ips: {
        public: string[];
        private: string[];
    };
}>();

const { addToast } = useToast();
const { t } = useI18n();

const domain = computed(() => props.domain);
const parentDomainWebRootPath = computed(() => {
    const customRootPath = String(domain.value.root_path ?? '').trim();
    if (customRootPath !== '') {
        return customRootPath;
    }

    const fqdn = String(domain.value.fqdn ?? '').trim();
    if (fqdn === '') {
        return '';
    }

    if (domain.value.type === 'apache_reverse_proxy') {
        return `/var/www/vhosts/${fqdn}/httpdocs`;
    }

    return `/var/www/vhosts/${fqdn}/httpdocs/public`;
});
const breadcrumbs = computed(() => [
    { label: t('Domains'), href: route('domains.index') },
    { label: domain.value.fqdn },
]);
const previewUrl = computed(() => `https://${domain.value.fqdn}`);
const isSubdomain = computed(() => Boolean(domain.value.parent_domain_id));
const cloudflareEnabledOverride = ref<boolean | null>(null);
const cloudflareZoneSummary = ref({
    exists: Boolean(props.cloudflare_zone.exists),
    zone_name: props.cloudflare_zone.zone_name as string | null,
    name_servers: Array.isArray(props.cloudflare_zone.name_servers) ? props.cloudflare_zone.name_servers : [],
});
const isCloudflareManagedForDns = computed(() => {
    if (cloudflareEnabledOverride.value !== null) {
        return cloudflareEnabledOverride.value;
    }

    if (domain.value.cloudflare_enabled === true) {
        return true;
    }

    if (domain.value.cloudflare_enabled === false) {
        return false;
    }

    return cloudflareZoneSummary.value.exists;
});
const canManagePhpSettings = computed(() => domain.value.type === 'apache_reverse_proxy');
const hasStoredFtpPassword = computed(() => Boolean(domain.value.ftp_user?.encrypted_password));
const subdomains = computed(() => domain.value.subdomains ?? []);

const sslLoading = ref(false);
const showCreateSubdomainModal = ref(false);
const showDomainDetails = ref(false);

const showFtpModal = ref(false);
const ftpSubmitting = ref(false);
const showFtpPassword = ref(false);
const ftpGeneratedPassword = ref(false);
const ftpCopiedPassword = ref(false);
const ftpForm = ref({
    username: domain.value.ftp_user?.username ?? '',
    password: '',
});

const showJenkinsModal = ref(false);
const jenkinsFqdn = ref('');
const jenkinsRepoUrl = ref('');
const jenkinsBranch = ref('*/main');
const jenkinsOutput = ref('');
const jenkinsCopied = ref(false);

const cloudflareStateLoading = ref(false);
const cloudflareActionLoading = ref(false);
const cloudflareFirewallRules = ref<CloudflareFirewallRule[]>([]);
const cloudflareDnssecDetails = ref<Record<string, unknown> | null>(null);
const cloudflareForm = ref<CloudflareFormState>({
    security_level: 'medium',
    ssl: 'full',
    min_tls_version: '1.2',
    browser_cache_ttl: 14400,
    always_use_https: false,
    automatic_https_rewrites: false,
    tls_1_3: true,
    development_mode: false,
    websockets: true,
    ip_geolocation: false,
    opportunistic_onion: false,
    http3: false,
    early_hints: false,
    dnssec_status: 'disabled',
    hsts: {
        enabled: false,
        max_age: 0,
        include_subdomains: false,
        preload: false,
        nosniff: false,
    },
});
const firewallForm = ref({
    expression: '',
    action: 'block',
    description: '',
    priority: null as number | null,
});
const toggleSettings: Array<{ key: CloudflareToggleSettingKey; label: string }> = [
    { key: 'always_use_https', label: 'Always Use HTTPS' },
    { key: 'automatic_https_rewrites', label: 'Automatic HTTPS Rewrites' },
    { key: 'tls_1_3', label: 'TLS 1.3' },
    { key: 'development_mode', label: 'Development Mode' },
    { key: 'websockets', label: 'WebSockets' },
    { key: 'ip_geolocation', label: 'IP Geolocation' },
    { key: 'opportunistic_onion', label: 'Onion Routing' },
    { key: 'http3', label: 'HTTP/3' },
    { key: 'early_hints', label: 'Early Hints' },
];
const underAttackEnabled = computed(() => cloudflareForm.value.security_level === 'under_attack');
const cloudflareDnssecEntries = computed<Array<{ key: string; value: string }>>(() => {
    if (!cloudflareDnssecDetails.value || typeof cloudflareDnssecDetails.value !== 'object') {
        return [];
    }

    const entries: Array<{ key: string; value: string }> = [];
    const preferredKeys = [
        'ds',
        'public_key',
        'digest',
        'digest_type',
        'algorithm',
        'key_tag',
        'flags',
        'key_type',
        'modified_on',
    ];
    const seenKeys = new Set<string>();
    const dnssec = cloudflareDnssecDetails.value;

    const normalizeValue = (value: unknown): string => {
        if (value === null || value === undefined) {
            return '';
        }

        if (typeof value === 'string') {
            return value.trim();
        }

        if (typeof value === 'object') {
            return JSON.stringify(value);
        }

        return String(value);
    };

    for (const key of preferredKeys) {
        if (!Object.prototype.hasOwnProperty.call(dnssec, key)) {
            continue;
        }

        const value = normalizeValue((dnssec as Record<string, unknown>)[key]);
        if (value === '') {
            continue;
        }

        entries.push({ key, value });
        seenKeys.add(key);
    }

    for (const [key, rawValue] of Object.entries(dnssec)) {
        if (seenKeys.has(key) || key === 'status') {
            continue;
        }

        const value = normalizeValue(rawValue);
        if (value === '') {
            continue;
        }

        entries.push({ key, value });
    }

    return entries;
});

const provisioningMessage = ref(t('Waiting for provisioning updates...'));
const provisioningPercent = ref(0);
const provisioningState = ref<'info' | 'success' | 'error'>('info');
const showProvisioningCard = computed(() => ['pending_cert', 'provisioning', 'failed'].includes(String(domain.value.status)));

const provisioningMessageClass = computed(() => {
    if (provisioningState.value === 'success') {
        return 'border-success-500/30 bg-success-500/10 text-success-700 dark:text-success-300';
    }

    if (provisioningState.value === 'error') {
        return 'border-error-500/30 bg-error-500/10 text-error-700 dark:text-error-300';
    }

    return 'border-blue-light-500/30 bg-blue-light-500/10 text-blue-light-700 dark:text-blue-light-300';
});

const provisioningProgressClass = computed(() => {
    if (provisioningState.value === 'success') {
        return 'bg-success-500';
    }

    if (provisioningState.value === 'error') {
        return 'bg-error-500';
    }

    return 'bg-blue-light-500';
});

const provisioningPercentLabel = computed(() => {
    if (provisioningState.value === 'error') {
        return t('Failed');
    }

    return `${provisioningPercent.value}%`;
});

const formatDomainStatus = (status: string): string => {
    switch (status) {
        case 'active':
            return t('Active');
        case 'disabled':
            return t('Disabled');
        case 'pending_cert':
            return t('Pending Cert');
        case 'failed':
            return t('Failed');
        default:
            return status;
    }
};

const domainStatusBadge = (status: string): string => {
    switch (status) {
        case 'active':
            return `<span class="inline-flex items-center gap-1 rounded-full bg-success-500/15 px-2 py-0.5 text-xs font-semibold text-success-700 dark:text-success-300"><i class="fa-solid fa-circle-check text-[10px]"></i>${t('Active')}</span>`;
        case 'disabled':
            return `<span class="inline-flex items-center gap-1 rounded-full bg-gray-200 px-2 py-0.5 text-xs font-semibold text-gray-700 dark:bg-gray-700 dark:text-gray-300"><i class="fa-solid fa-circle-xmark text-[10px]"></i>${t('Disabled')}</span>`;
        case 'pending_cert':
            return `<span class="inline-flex items-center gap-1 rounded-full bg-warning-500/15 px-2 py-0.5 text-xs font-semibold text-warning-700 dark:text-warning-300"><i class="fa-solid fa-hourglass-half text-[10px]"></i>${t('Pending Cert')}</span>`;
        case 'failed':
            return `<span class="inline-flex items-center gap-1 rounded-full bg-error-500/15 px-2 py-0.5 text-xs font-semibold text-error-700 dark:text-error-300"><i class="fa-solid fa-triangle-exclamation text-[10px]"></i>${t('Failed')}</span>`;
        default:
            return String(status);
    }
};

const stateBadge = (enabled: boolean, enabledLabel: string, disabledLabel: string): string => {
    const toneClass = enabled
        ? 'bg-success-500/15 text-success-700 dark:text-success-300'
        : 'bg-gray-200 text-gray-700 dark:bg-gray-700 dark:text-gray-300';
    const iconClass = enabled ? 'fa-solid fa-circle-check' : 'fa-solid fa-circle-xmark';
    const label = enabled ? enabledLabel : disabledLabel;

    return `<span class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-semibold ${toneClass}"><i class="${iconClass} text-[10px]"></i>${label}</span>`;
};

const typeBadge = (type: string): string => {
    if (type === 'caddy_web_server') {
        return `<span class="inline-flex rounded-full bg-blue-light-500/15 px-2 py-0.5 text-xs font-semibold text-blue-light-700 dark:text-blue-light-300">${t('Caddy Web Server')}</span>`;
    }

    return `<span class="inline-flex rounded-full bg-gray-200 px-2 py-0.5 text-xs font-semibold text-gray-700 dark:bg-gray-700 dark:text-gray-300">${t('Apache + Reverse Proxy')}</span>`;
};

const joinOrFallback = (values: string[]): string => {
    if (!Array.isArray(values) || values.length === 0) {
        return '-';
    }

    return values.join(', ');
};

const primaryServerIp = computed(() => {
    if (Array.isArray(props.server_network_ips.public) && props.server_network_ips.public.length > 0) {
        return props.server_network_ips.public[0];
    }

    if (Array.isArray(props.server_network_ips.private) && props.server_network_ips.private.length > 0) {
        return props.server_network_ips.private[0];
    }

    return '-';
});

const infoRows = computed(() => {
    const rows: Array<{ key: string; label: string; value: string; html?: boolean }> = [
        { key: 'fqdn', label: t('FQDN'), value: domain.value.fqdn },
        { key: 'type', label: t('Type'), value: typeBadge(domain.value.type), html: true },
        { key: 'status', label: t('Status'), value: domainStatusBadge(domain.value.status), html: true },
    ];

    if (domain.value.type === 'apache_reverse_proxy') {
        rows.push({ key: 'php_version', label: t('PHP Version'), value: domain.value.php_version?.slug ?? t('N/A') });
    }

    rows.push(
        { key: 'root_path', label: t('Root Path'), value: domain.value.root_path ?? t('Default') },
        { key: 'enable_www_redirect', label: t('Enable WWW Redirect'), value: stateBadge(domain.value.enable_www_redirect, t('Yes'), t('No')), html: true },
        {
            key: 'additional_hostnames',
            label: t('Additional Hostnames'),
            value: Array.isArray(domain.value.additional_hostnames) && domain.value.additional_hostnames.length > 0
                ? domain.value.additional_hostnames.join(', ')
                : t('None'),
        },
    );

    if (domain.value.type === 'caddy_web_server') {
        rows.push({ key: 'worker_enabled', label: t('Worker Enabled'), value: stateBadge(domain.value.enable_worker, t('Yes'), t('No')), html: true });

        if (domain.value.enable_worker) {
            rows.push(
                { key: 'worker_count', label: t('Worker Count'), value: String(domain.value.worker_num ?? t('N/A')) },
                { key: 'worker_watch_enabled', label: t('Worker Watch Enabled'), value: stateBadge(domain.value.worker_watch, t('Yes'), t('No')), html: true },
            );
        }
    }

    rows.push(
        { key: 'created_at', label: t('Created At'), value: formatDateTime(domain.value.created_at) },
        { key: 'updated_at', label: t('Updated At'), value: formatDateTime(domain.value.updated_at) },
    );

    rows.push({
        key: 'cloudflare_status',
        label: t('Cloudflare Status'),
        value: stateBadge(isCloudflareManagedForDns.value, t('Connected'), t('Not Connected')),
        html: true,
    });

    rows.push({
        key: 'cloudflare_zone',
        label: t('Cloudflare Zone'),
        value: cloudflareZoneSummary.value.exists
            ? (cloudflareZoneSummary.value.zone_name ?? domain.value.fqdn)
            : t('Not found on Cloudflare'),
    });

    rows.push({
        key: 'cloudflare_nameservers',
        label: t('Cloudflare Nameservers'),
        value: cloudflareZoneSummary.value.exists
            ? joinOrFallback(cloudflareZoneSummary.value.name_servers)
            : t('-'),
    });

    rows.push({
        key: 'domain_target_ip',
        label: t('Domain Target IP'),
        value: primaryServerIp.value,
    });

    rows.push({
        key: 'server_public_ips',
        label: t('Server Public IPs'),
        value: joinOrFallback(props.server_network_ips.public),
    });

    rows.push({
        key: 'server_private_ips',
        label: t('Server Private IPs'),
        value: joinOrFallback(props.server_network_ips.private),
    });

    return rows;
});

const summaryInfoRows = computed(() => infoRows.value.filter((row) => ['cloudflare_nameservers', 'domain_target_ip'].includes(row.key)));
const detailInfoRows = computed(() => infoRows.value.filter((row) => !['cloudflare_nameservers', 'domain_target_ip'].includes(row.key)));

const toOnOff = (value: unknown): boolean => {
    if (typeof value === 'boolean') {
        return value;
    }

    if (typeof value === 'number') {
        return value === 1;
    }

    const normalized = String(value).toLowerCase();

    return normalized === 'on' || normalized === 'true' || normalized === '1';
};

const applyCloudflareStatusPayload = (payload: Record<string, any>): void => {
    const zone = payload.zone ?? {};
    cloudflareZoneSummary.value = {
        exists: Boolean(zone.exists),
        zone_name: typeof zone.zone_name === 'string' ? zone.zone_name : null,
        name_servers: Array.isArray(zone.name_servers) ? zone.name_servers.map((nameServer) => String(nameServer)) : [],
    };

    if (typeof payload.cloudflare_enabled === 'boolean') {
        cloudflareEnabledOverride.value = payload.cloudflare_enabled;
    } else if (typeof payload.cloudflare_effective_enabled === 'boolean') {
        cloudflareEnabledOverride.value = payload.cloudflare_effective_enabled;
    } else {
        cloudflareEnabledOverride.value = null;
    }

    const settings = payload.settings ?? {};
    const securityHeader = settings.security_header && typeof settings.security_header === 'object'
        ? settings.security_header
        : {};
    const dnssecDetails = payload.dnssec && typeof payload.dnssec === 'object'
        ? payload.dnssec as Record<string, unknown>
        : null;
    cloudflareDnssecDetails.value = dnssecDetails;

    cloudflareForm.value = {
        ...cloudflareForm.value,
        security_level: String(settings.security_level ?? 'medium'),
        ssl: String(settings.ssl ?? 'full'),
        min_tls_version: String(settings.min_tls_version ?? '1.2'),
        browser_cache_ttl: Number(settings.browser_cache_ttl ?? 14400),
        always_use_https: toOnOff(settings.always_use_https),
        automatic_https_rewrites: toOnOff(settings.automatic_https_rewrites),
        tls_1_3: toOnOff(settings.tls_1_3),
        development_mode: toOnOff(settings.development_mode),
        websockets: toOnOff(settings.websockets),
        ip_geolocation: toOnOff(settings.ip_geolocation),
        opportunistic_onion: toOnOff(settings.opportunistic_onion),
        http3: toOnOff(settings.http3),
        early_hints: toOnOff(settings.early_hints),
        dnssec_status: String(dnssecDetails?.status ?? 'disabled') === 'active' ? 'active' : 'disabled',
        hsts: {
            enabled: Boolean(securityHeader.enabled ?? false),
            max_age: Number(securityHeader.max_age ?? 0),
            include_subdomains: Boolean(securityHeader.include_subdomains ?? false),
            preload: Boolean(securityHeader.preload ?? false),
            nosniff: Boolean(securityHeader.nosniff ?? false),
        },
    };

    cloudflareFirewallRules.value = Array.isArray(payload.firewall_rules) ? payload.firewall_rules : [];
};

const fetchCloudflareStatus = async (): Promise<void> => {
    if (isSubdomain.value) {
        return;
    }

    cloudflareStateLoading.value = true;

    try {
        const response = await axios.get(route('domains.cloudflare.status', domain.value.id));
        applyCloudflareStatusPayload(response.data ?? {});
    } catch {
        addToast('error', t('Cloudflare status could not be loaded.'));
    } finally {
        cloudflareStateLoading.value = false;
    }
};

const syncCloudflareStatus = async (): Promise<void> => {
    cloudflareActionLoading.value = true;

    try {
        const response = await axios.post(route('domains.cloudflare.sync', domain.value.id));
        addToast('success', response.data.message ?? t('Cloudflare status synchronized.'));
        await fetchCloudflareStatus();
    } catch (error: any) {
        const message = error?.response?.data?.message ?? t('Cloudflare status sync failed.');
        addToast('error', String(message));
    } finally {
        cloudflareActionLoading.value = false;
    }
};

const purgeCloudflareCache = async (): Promise<void> => {
    cloudflareActionLoading.value = true;

    try {
        const response = await axios.post(route('domains.cloudflare.purge-cache', domain.value.id));
        addToast('success', response.data.message ?? t('Cloudflare cache purge started.'));
    } catch (error: any) {
        const message = error?.response?.data?.message ?? t('Cloudflare cache purge failed.');
        addToast('error', String(message));
    } finally {
        cloudflareActionLoading.value = false;
    }
};

const updateCloudflareSetting = async (setting: string, value: unknown): Promise<boolean> => {
    cloudflareActionLoading.value = true;

    try {
        const response = await axios.post(route('domains.cloudflare.setting', domain.value.id), {
            setting,
            value,
        });
        addToast('success', response.data.message ?? t('Cloudflare setting updated.'));
        await fetchCloudflareStatus();
        return true;
    } catch (error: any) {
        const message = error?.response?.data?.message ?? t('Cloudflare setting update failed.');
        addToast('error', String(message));
        return false;
    } finally {
        cloudflareActionLoading.value = false;
    }
};

const toggleSettingValue = async (setting: CloudflareToggleSettingKey): Promise<void> => {
    const current = cloudflareForm.value[setting];
    const next = !current;
    cloudflareForm.value[setting] = next;

    const updated = await updateCloudflareSetting(setting, next ? 'on' : 'off');
    if (!updated) {
        cloudflareForm.value[setting] = current;
    }
};

const updateHsts = async (): Promise<void> => {
    await updateCloudflareSetting('security_header', cloudflareForm.value.hsts);
};

const updateDnssec = async (): Promise<void> => {
    cloudflareActionLoading.value = true;

    try {
        const response = await axios.post(route('domains.cloudflare.dnssec', domain.value.id), {
            status: cloudflareForm.value.dnssec_status,
        });
        addToast('success', response.data.message ?? t('Cloudflare DNSSEC updated.'));
        await fetchCloudflareStatus();
    } catch (error: any) {
        const message = error?.response?.data?.message ?? t('Cloudflare DNSSEC update failed.');
        addToast('error', String(message));
    } finally {
        cloudflareActionLoading.value = false;
    }
};

const createFirewallRule = async (): Promise<void> => {
    if (firewallForm.value.expression.trim() === '') {
        addToast('warning', t('Firewall expression is required.'));

        return;
    }

    cloudflareActionLoading.value = true;

    try {
        const response = await axios.post(route('domains.cloudflare.firewall-rules.store', domain.value.id), firewallForm.value);
        addToast('success', response.data.message ?? t('Firewall rule created.'));
        firewallForm.value.expression = '';
        firewallForm.value.description = '';
        firewallForm.value.priority = null;
        await fetchCloudflareStatus();
    } catch (error: any) {
        const message = error?.response?.data?.message ?? t('Firewall rule could not be created.');
        addToast('error', String(message));
    } finally {
        cloudflareActionLoading.value = false;
    }
};

const deleteFirewallRule = async (ruleId: string): Promise<void> => {
    if (!window.confirm(t('Are you sure you want to delete this firewall rule?'))) {
        return;
    }

    cloudflareActionLoading.value = true;

    try {
        const response = await axios.delete(route('domains.cloudflare.firewall-rules.delete', [domain.value.id, ruleId]));
        addToast('success', response.data.message ?? t('Firewall rule deleted.'));
        await fetchCloudflareStatus();
    } catch (error: any) {
        const message = error?.response?.data?.message ?? t('Firewall rule could not be deleted.');
        addToast('error', String(message));
    } finally {
        cloudflareActionLoading.value = false;
    }
};

const toggleUnderAttackQuick = async (): Promise<void> => {
    await updateCloudflareSetting('under_attack', !underAttackEnabled.value);
};

const activateSsl = (domainId: number) => {
    sslLoading.value = true;
    router.post(route('domains.ssl.activate', domainId), {}, {
        preserveScroll: true,
            onSuccess: () => {
            addToast('success', t('SSL certificate operation started.'));
        },
        onFinish: () => {
            sslLoading.value = false;
        },
    });
};

const confirmDomainDeletion = async (isSubdomain: boolean, fqdn: string): Promise<boolean | null> => {
    const shouldShowDnsOption = isSubdomain && isCloudflareManagedForDns.value;
    const swal = await loadSweetAlert();

    if (swal === null) {
        const shouldDelete = window.confirm(
            isSubdomain
                ? t('Are you sure you want to delete subdomain :fqdn?', { fqdn })
                : t('Are you sure you want to delete this domain?'),
        );

        if (!shouldDelete) {
            return null;
        }

        if (!shouldShowDnsOption) {
            return false;
        }

        return window.confirm(t('Also delete Cloudflare DNS A record?'));
    }

    const response = await swal.fire({
        title: isSubdomain ? t('Delete subdomain') : t('Delete domain'),
        text: isSubdomain
            ? t('Are you sure you want to delete subdomain :fqdn?', { fqdn })
            : t('Are you sure you want to delete this domain?'),
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: t('Delete'),
        cancelButtonText: t('Cancel'),
        reverseButtons: true,
        focusCancel: true,
        input: shouldShowDnsOption ? 'checkbox' : undefined,
        inputPlaceholder: shouldShowDnsOption ? t('Also delete Cloudflare DNS A record') : undefined,
        inputValue: 0,
    });

    if (!response.isConfirmed) {
        return null;
    }

    return shouldShowDnsOption ? Boolean(response.value) : false;
};

const deleteDomain = async (domainId: number, isChild: boolean, fqdn: string) => {
    const deleteDnsRecord = await confirmDomainDeletion(isChild, fqdn);

    if (deleteDnsRecord === null) {
        return;
    }

    router.delete(route('domains.destroy', domainId), {
        data: {
            delete_dns_record: deleteDnsRecord,
        },
        preserveScroll: true,
        onSuccess: () => {
            addToast('success', t('Domain deletion in progress.'));
        },
        onError: () => {
            addToast('error', t('Delete failed.'));
        },
    });
};

const generateFtpPassword = () => {
    const chars = 'abcdefghijkmnopqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789!@#$%&*';
    const length = 20;
    const array = new Uint32Array(length);
    crypto.getRandomValues(array);

    let password = '';
    for (let index = 0; index < length; index += 1) {
        password += chars[array[index] % chars.length];
    }

    ftpForm.value.password = password;
    showFtpPassword.value = true;
    ftpGeneratedPassword.value = true;
    ftpCopiedPassword.value = false;
};

const copyFtpPassword = async () => {
    if (!ftpForm.value.password) {
        return;
    }

    await navigator.clipboard.writeText(ftpForm.value.password);
    ftpCopiedPassword.value = true;
};

const submitFtp = () => {
    if (!ftpForm.value.password || ftpForm.value.password.length < 8) {
        addToast('error', t('Password must be at least 8 characters.'));

        return;
    }

    if (!domain.value.ftp_user && !ftpForm.value.username) {
        addToast('error', t('Username is required.'));

        return;
    }

    if (ftpGeneratedPassword.value && !ftpCopiedPassword.value) {
        addToast('warning', t('Copy the generated password before submitting.'));

        return;
    }

    ftpSubmitting.value = true;

    router.put(route('domains.ftp.update', domain.value.id), {
        ftp_username: domain.value.ftp_user ? null : ftpForm.value.username,
        ftp_password: ftpForm.value.password,
    }, {
        preserveScroll: true,
        onSuccess: () => {
            showFtpModal.value = false;
            ftpForm.value.password = '';
            ftpGeneratedPassword.value = false;
            ftpCopiedPassword.value = false;
            addToast('success', domain.value.ftp_user ? t('FTP password updated successfully.') : t('FTP user created successfully.'));
        },
        onError: (errors) => {
            const firstError = Object.values(errors)[0];
            addToast('error', String(firstError ?? t('FTP update failed.')));
        },
        onFinish: () => {
            ftpSubmitting.value = false;
        },
    });
};

const closeJenkinsModal = () => {
    showJenkinsModal.value = false;
    jenkinsOutput.value = '';
    jenkinsCopied.value = false;
};

const openJenkinsModal = (fqdn: string) => {
    jenkinsFqdn.value = fqdn;
    jenkinsRepoUrl.value = '';
    jenkinsBranch.value = '*/main';
    jenkinsOutput.value = '';
    jenkinsCopied.value = false;
    showJenkinsModal.value = true;
};

const jenkinsTemplate = ({ repoUrl, branch, siteRoot, stageDir }: JenkinsTemplateParams): string => {
    const template = `pipeline {
  agent any
  triggers { githubPush() }
  options { disableConcurrentBuilds() }

  environment {
    REPO_URL = '__REPO_URL__'
    BRANCH   = '__BRANCH__'
    CREDS_ID = 'git'

    PHP_CONTAINER = 'php-code-server'

    SITE_ROOT = '__SITE_ROOT__'
    CURRENT   = "\${SITE_ROOT}/httpdocs"
    RELEASES  = "\${SITE_ROOT}/releases"
    SHARED    = "\${SITE_ROOT}/shared"
    STAGE_DIR = '__STAGE_DIR__'

    COMPOSER_CMD  = 'install'
    KEEP_RELEASES = '5'

    FRANKENPHP_CONTAINER = 'frankenphp'
    CADDYFILE_PATH = '/etc/frankenphp/Caddyfile'
  }

  stages {
    stage('Checkout') {
      steps {
        checkout([\$class: 'GitSCM',
          branches: [[name: "\${BRANCH}"]],
          userRemoteConfigs: [[url: "\${REPO_URL}", credentialsId: "\${CREDS_ID}"]],
        ])
      }
    }

    stage('Stage repo to cache') {
      steps {
        sh '''
          set -e
          rm -rf "\${STAGE_DIR}"
          mkdir -p "\${STAGE_DIR}"
          rsync -a --delete --one-file-system \\
            --exclude ".git" \\
            --exclude "node_modules" \\
            --exclude "vendor" \\
            --exclude "Modules/**" \\
            --exclude "modules/**" \\
            --exclude "_build_site1/**" \\
            --exclude "_build_site2/**" \\
            --exclude "tmp/**" \\
            "\$WORKSPACE/" "\${STAGE_DIR}/"
        '''
      }
    }

    stage('Deploy + Build + Migrate') {
      steps {
        sh '''
          set -e

          # 1) Build/Deploy her şeyi php-code-server içinde tamamla
          docker exec -u root \\
            -e CURRENT="\${CURRENT}" \\
            -e RELEASES="\${RELEASES}" \\
            -e SHARED="\${SHARED}" \\
            -e STAGE="\${STAGE_DIR}" \\
            -e KEEP_RELEASES="\${KEEP_RELEASES}" \\
            -e COMPOSER_CMD="\${COMPOSER_CMD}" \\
            \${PHP_CONTAINER} sh -lc '
              set -e
              umask 002

              [ -d "$STAGE" ] || { echo "STAGE not found: $STAGE"; exit 1; }
              [ -e "$CURRENT" ] || { echo "CURRENT not found: $CURRENT"; exit 1; }

              mkdir -p "$RELEASES" "$SHARED"

              if [ ! -L "$CURRENT" ]; then
                mv "$CURRENT" "$RELEASES/initial"
                ln -sfn "$RELEASES/initial" "$CURRENT"
              fi

              ts="$(date +%Y%m%d%H%M%S)"
              release="$RELEASES/$ts"
              prev="$(readlink -f "$CURRENT" || true)"

              do_rollback=1
              trap "status=\\$?; if [ \\"\\\${do_rollback:-0}\\" != \\"0\\" ] && [ \\"\\\${status:-0}\\" != \\"0\\" ] && [ -n \\"$prev\\" ]; then ln -sfn \\"$prev\\" \\"$CURRENT\\"; fi; exit \\$status" EXIT

              mkdir -p "$release"
              rsync -a --delete --no-owner --no-group --one-file-system \\
                --exclude ".env" \\
                --exclude "modules_statuses.json" \\
                --exclude "storage/**" \\
                --exclude "bootstrap/cache/**" \\
                --exclude "public/theme/**" \\
                --exclude "public/ads.txt" \\
                --exclude "public/themes/fontawesome/**" \\
                --exclude "public/storage/**" \\
                --exclude "resources/js/**" \\
                --exclude "resources/views/themes/**" \\
                --exclude "Modules/**" \\
                --exclude "modules/**" \\
                "$STAGE/" "$release/"

              seed_paths="
.env
modules_statuses.json
storage
public/storage
public/theme
public/themes/fontawesome
public/ads.txt
resources/js
resources/views/themes
Modules
modules
package.json
package-lock.json
pnpm-lock.yaml
yarn.lock
"
              link_paths="
.env
modules_statuses.json
storage
public/storage
public/theme
public/themes/fontawesome
public/ads.txt
resources/views/themes
Modules
modules
package.json
package-lock.json
pnpm-lock.yaml
yarn.lock
"
              copy_paths="resources/js"

              for path in $seed_paths; do
                src="$CURRENT/$path"
                dest="$SHARED/$path"
                if [ ! -e "$dest" ] && [ -e "$src" ]; then
                  mkdir -p "$(dirname "$dest")"
                  if [ -d "$src" ] && [ ! -L "$src" ]; then
                    rsync -a "$src/" "$dest/"
                  else
                    cp -a "$src" "$dest"
                  fi
                fi
              done

              for path in $link_paths; do
                if [ -e "$SHARED/$path" ]; then
                  rm -rf "$release/$path"
                  mkdir -p "$(dirname "$release/$path")"
                  ln -sfn "$SHARED/$path" "$release/$path"
                fi
              done

              for path in $copy_paths; do
                if [ -e "$SHARED/$path" ]; then
                  rm -rf "$release/$path"
                  mkdir -p "$(dirname "$release/$path")"
                  rsync -a "$SHARED/$path/" "$release/$path/"
                fi
              done

              cd "$release"
              mkdir -p storage/framework/{cache,sessions,views} storage/logs bootstrap/cache
              chmod -R ug+rwX storage bootstrap/cache || true
              touch storage/logs/laravel.log || true

              if [ "$COMPOSER_CMD" = "update" ]; then
                composer update --no-dev --prefer-dist --no-interaction --optimize-autoloader
              else
                composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader
              fi

              php artisan optimize:clear || true

              if [ -f package.json ]; then
                if [ -f package-lock.json ]; then
                  npm ci --no-audit --no-fund
                else
                  npm install --no-audit --no-fund
                fi
                npm run theme:install || true
                npm run build
              fi

              php artisan migrate --force
              php artisan vendor:publish --tag=log-viewer-assets --force
              php artisan optimize
              php artisan storage:link || true

              # ATOMIC FLIP
              ln -sfn "$release" "$CURRENT"
              do_rollback=0

              # Cleanup (CURRENT hedefini asla silme)
              if [ -n "$KEEP_RELEASES" ]; then
                cur="$(readlink -f "$CURRENT" || true)"
                ls -1dt "$RELEASES"/* 2>/dev/null \\
                  | grep -v "/initial$" \\
                  | grep -vFx "$cur" \\
                  | tail -n +$((KEEP_RELEASES+1)) \\
                  | xargs -r rm -rf
              fi
            '
            docker exec -u root frankenphp supervisorctl restart all
            curl -X POST http://frankenphp:2019/frankenphp/workers/restart

        '''
      }
    }
  }

  post { always { deleteDir() } }
}`;

    return template
        .replace(/__REPO_URL__/g, repoUrl)
        .replace(/__BRANCH__/g, branch)
        .replace(/__SITE_ROOT__/g, siteRoot)
        .replace(/__STAGE_DIR__/g, stageDir);
};

const generateJenkinsfile = () => {
    const repoUrl = jenkinsRepoUrl.value.trim();
    const branch = jenkinsBranch.value.trim() || '*/main';

    if (!repoUrl) {
        addToast('warning', t('Repository URL is required.'));

        return;
    }

    const fqdn = jenkinsFqdn.value;
    const siteRoot = `/var/www/vhosts/${fqdn}`;
    const stageDir = `/deploy_cache/${fqdn}`;

    jenkinsOutput.value = jenkinsTemplate({
        repoUrl,
        branch,
        siteRoot,
        stageDir,
    });
    jenkinsCopied.value = false;
};

const copyJenkinsfile = async () => {
    if (!jenkinsOutput.value) {
        return;
    }

    await navigator.clipboard.writeText(jenkinsOutput.value);
    jenkinsCopied.value = true;

    setTimeout(() => {
        jenkinsCopied.value = false;
    }, 2000);
};

onMounted(() => {
    void fetchCloudflareStatus();

    if (typeof window.Echo === 'undefined') {
        return;
    }

    const domainId = domain.value.id;

    window.Echo.channel(`domain.${domainId}`)
        .listen('.DomainProvisionProgress', (event: { message: string; percent: number }) => {
            provisioningMessage.value = event.message;
            provisioningPercent.value = event.percent;
            provisioningState.value = 'info';
        })
        .listen('.DomainProvisioned', () => {
            provisioningMessage.value = t('Domain provisioned successfully!');
            provisioningPercent.value = 100;
            provisioningState.value = 'success';

            setTimeout(() => {
                window.location.reload();
            }, 2000);
        })
        .listen('.DomainProvisionFailed', (event: { error: string }) => {
            provisioningMessage.value = t('Provisioning failed: :error', { error: event.error });
            provisioningPercent.value = 100;
            provisioningState.value = 'error';
        });
});

onBeforeUnmount(() => {
    if (typeof window.Echo === 'undefined') {
        return;
    }

    window.Echo.leave(`domain.${domain.value.id}`);
});
</script>

<style scoped>
@reference "../../../css/app.css";

.quick-link {
    @apply inline-flex w-full items-center gap-3 rounded-xl border border-gray-200 bg-gray-50 px-3 py-2.5 text-sm font-medium text-gray-700 shadow-theme-xs transition-all hover:-translate-y-0.5 hover:border-brand-300 hover:bg-brand-50 hover:text-brand-700 dark:border-gray-700 dark:bg-gray-800/70 dark:text-gray-200 dark:hover:border-brand-500/50 dark:hover:bg-brand-500/10 dark:hover:text-white;
}

.quick-link-icon {
    @apply inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-lg border border-gray-200 bg-white text-gray-600 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-300;
}

.quick-link:hover .quick-link-icon {
    @apply border-brand-300 bg-brand-100 text-brand-700 dark:border-brand-500/60 dark:bg-brand-500/20 dark:text-brand-200;
}

.quick-link-label {
    @apply min-w-0 flex-1 truncate text-left;
}

.quick-link-arrow {
    @apply text-xs text-gray-400 transition-all dark:text-gray-500;
}

.quick-link-warning {
    @apply text-warning-500;
}

.quick-link-disabled {
    @apply cursor-not-allowed border-gray-200/80 bg-gray-100 text-gray-400 hover:translate-y-0 hover:border-gray-200/80 hover:bg-gray-100 hover:text-gray-400 dark:border-gray-700 dark:bg-gray-800/50 dark:text-gray-500 dark:hover:border-gray-700 dark:hover:bg-gray-800/50 dark:hover:text-gray-500;
}

.quick-link-disabled .quick-link-icon {
    @apply border-gray-300 bg-gray-100 text-gray-400 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-500;
}

.quick-link-disabled .quick-link-arrow {
    @apply text-gray-300 dark:text-gray-600;
}

.quick-link:hover .quick-link-arrow {
    @apply translate-x-0.5 text-brand-500;
}

.ftp-input {
    @apply h-10 w-full rounded-md border border-white/15 bg-black/20 px-3 text-sm text-white focus:border-white/40 focus:outline-hidden;
}

.form-input {
    @apply h-9 w-full rounded-lg border border-gray-300 bg-transparent px-3 py-2 text-xs text-gray-800 focus:border-brand-300 focus:outline-hidden focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90;
}

.form-select {
    @apply h-9 w-full rounded-lg border border-gray-300 bg-transparent px-3 py-2 text-xs text-gray-800 focus:border-brand-300 focus:outline-hidden focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90;
}

.form-checkbox {
    @apply h-4 w-4 rounded border-gray-300 text-brand-500 focus:ring-brand-500;
}

.ftp-icon-btn {
    @apply inline-flex h-7 w-7 items-center justify-center rounded border border-white/15 bg-white/10 text-white/60 hover:bg-white/20 hover:text-white;
}

.ftp-icon-btn-success {
    @apply border-success-500/50 bg-success-500/20 text-success-300;
}

.jenkins-code-block {
    background: #0d1117;
    border: 1px solid rgba(255, 255, 255, 0.1);
    border-radius: 8px;
    padding: 1rem;
    color: #e6edf3;
    font-size: 12px;
    line-height: 1.5;
    overflow: auto;
    max-height: 60vh;
    margin: 0;
    white-space: pre;
    tab-size: 2;
}
</style>
