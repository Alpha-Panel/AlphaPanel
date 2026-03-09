<template>
    <Head :title="t('Dashboard')" />
    <ThemeProvider>
        <SidebarProvider>
            <AdminLayout>
                <PageBreadcrumb :pageTitle="t('Dashboard')" />
                <Toast />

                <!-- Active Backup Progress Banner -->
                <div
                    v-if="backupProgress.active"
                    class="rounded-2xl border border-brand-200 bg-brand-50 p-5 dark:border-brand-500/30 dark:bg-brand-500/10"
                >
                    <div class="flex items-center gap-3">
                        <div class="h-8 w-8 animate-spin rounded-full border-4 border-brand-200 border-t-brand-500"></div>
                        <div class="flex-1">
                            <div class="flex items-center justify-between">
                                <p class="text-sm font-medium text-brand-800 dark:text-brand-200">
                                    {{ backupProgress.message || t('Uploading backup...') }}
                                </p>
                                <span class="text-sm font-semibold text-brand-700 dark:text-brand-300">
                                    {{ backupProgress.percent }}%
                                </span>
                            </div>
                            <div class="mt-2 h-2 overflow-hidden rounded-full bg-brand-200 dark:bg-brand-800">
                                <div
                                    class="h-full rounded-full bg-brand-500 transition-all duration-300"
                                    :style="{ width: backupProgress.percent + '%' }"
                                ></div>
                            </div>
                        </div>
                        <Link
                            :href="route('backups.index')"
                            class="text-xs font-medium text-brand-600 hover:text-brand-800 dark:text-brand-300"
                        >
                            {{ t('View') }}
                        </Link>
                    </div>
                </div>

                <div class="grid grid-cols-1 gap-4 md:gap-6">
                    <div
                        :class="[
                            'grid grid-cols-1 gap-4 md:gap-6',
                            isAdmin ? 'sm:grid-cols-2 xl:grid-cols-5' : 'sm:grid-cols-2 xl:grid-cols-3',
                        ]"
                    >
                        <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
                            <div class="flex items-center gap-4">
                                <div class="flex h-12 w-12 items-center justify-center rounded-xl bg-brand-50 text-brand-500 dark:bg-brand-500/20 dark:text-brand-300">
                                    <i class="bx bx-globe text-2xl"></i>
                                </div>
                                <div>
                                    <p class="text-sm text-gray-500 dark:text-gray-400">{{ t('Total Domains') }}</p>
                                    <h3 class="text-2xl font-semibold text-gray-800 dark:text-white/90">
                                        {{ stats.total_domains }}
                                    </h3>
                                    <Link
                                        :href="route('domains.index')"
                                        class="inline-flex items-center gap-1 text-xs font-medium text-brand-500 hover:text-brand-600"
                                    >
                                        {{ t('View all') }}
                                        <i class="bx bx-right-arrow-alt text-sm"></i>
                                    </Link>
                                </div>
                            </div>
                        </div>

                        <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
                            <div class="flex items-center gap-4">
                                <div class="flex h-12 w-12 items-center justify-center rounded-xl bg-success-50 text-success-600 dark:bg-success-500/20 dark:text-success-300">
                                    <i class="bx bx-check-shield text-2xl"></i>
                                </div>
                                <div>
                                    <p class="text-sm text-gray-500 dark:text-gray-400">{{ t('Active Domains') }}</p>
                                    <h3 class="text-2xl font-semibold text-gray-800 dark:text-white/90">
                                        {{ stats.active_domains }}
                                    </h3>
                                    <p class="text-xs text-gray-500 dark:text-gray-400">
                                        {{ stats.subdomains }} {{ t('subdomains') }}
                                    </p>
                                </div>
                            </div>
                        </div>

                        <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
                            <div class="flex items-center gap-4">
                                <div class="flex h-12 w-12 items-center justify-center rounded-xl bg-warning-50 text-warning-600 dark:bg-warning-500/20 dark:text-warning-300">
                                    <i class="bx bx-data text-2xl"></i>
                                </div>
                                <div>
                                    <p class="text-sm text-gray-500 dark:text-gray-400">{{ t('Databases') }}</p>
                                    <h3 class="text-2xl font-semibold text-gray-800 dark:text-white/90">
                                        {{ stats.total_databases }}
                                    </h3>
                                    <p class="text-xs text-gray-500 dark:text-gray-400">{{ t('MySQL') }}</p>
                                </div>
                            </div>
                        </div>

                        <div
                            v-if="isAdmin"
                            class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]"
                        >
                            <div class="flex items-center gap-4">
                                <div class="flex h-12 w-12 items-center justify-center rounded-xl bg-blue-light-50 text-blue-light-600 dark:bg-blue-light-500/20 dark:text-blue-light-300">
                                    <i class="bx bx-group text-2xl"></i>
                                </div>
                                <div>
                                    <p class="text-sm text-gray-500 dark:text-gray-400">{{ t('Users') }}</p>
                                    <h3 class="text-2xl font-semibold text-gray-800 dark:text-white/90">
                                        {{ stats.total_users }}
                                    </h3>
                                    <p class="text-xs text-gray-500 dark:text-gray-400">{{ t('Registered') }}</p>
                                </div>
                            </div>
                        </div>

                        <div
                            v-if="isAdmin"
                            class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]"
                        >
                            <div class="flex items-center gap-4">
                                <div class="flex h-12 w-12 items-center justify-center rounded-xl bg-error-50 text-error-600 dark:bg-error-500/20 dark:text-error-300">
                                    <i class="bx bx-shield-quarter text-2xl"></i>
                                </div>
                                <div class="min-w-0">
                                    <p class="text-sm text-gray-500 dark:text-gray-400">{{ t('CrowdSec') }}</p>
                                    <h3 class="text-2xl font-semibold text-gray-800 dark:text-white/90">
                                        {{ crowdsec?.active_decisions ?? 0 }}
                                    </h3>
                                    <p class="truncate text-xs text-gray-500 dark:text-gray-400">
                                        {{ t('Alerts 24h') }}: {{ crowdsec?.recent_alerts_24h ?? 0 }}
                                        <span v-if="crowdsecTopScenario"> • {{ crowdsecTopScenario }}</span>
                                    </p>
                                    <div class="mt-1 flex items-center gap-2">
                                        <span
                                            :class="[
                                                'inline-flex rounded-full px-2 py-0.5 text-[11px] font-semibold',
                                                crowdsec?.configured && crowdsec?.lapi_online
                                                    ? 'bg-success-500/15 text-success-600 dark:text-success-300'
                                                    : crowdsec?.configured
                                                        ? 'bg-warning-500/15 text-warning-700 dark:text-warning-300'
                                                        : 'bg-gray-200 text-gray-700 dark:bg-gray-700 dark:text-gray-300',
                                            ]"
                                        >
                                            {{
                                                !crowdsec?.configured
                                                    ? t('Not configured')
                                                    : crowdsec?.lapi_online
                                                        ? t('Live')
                                                        : t('Unavailable')
                                            }}
                                        </span>
                                        <Link
                                            :href="route('security.crowdsec.index')"
                                            class="inline-flex items-center gap-1 text-xs font-medium text-brand-500 hover:text-brand-600"
                                        >
                                            {{ t('Details') }}
                                            <i class="bx bx-right-arrow-alt text-sm"></i>
                                        </Link>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div v-if="isAdmin" class="grid grid-cols-1 gap-4 md:gap-6 xl:grid-cols-12">
                        <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03] xl:col-span-5">
                            <div class="mb-4 flex items-center">
                                <h4 class="text-sm font-semibold text-gray-800 dark:text-white/90">
                                    <i class="bx bx-chip mr-1"></i>
                                    {{ t('Host Resources') }}
                                </h4>
                                <span
                                    :class="[
                                        'ml-auto inline-flex rounded-full px-2.5 py-1 text-xs font-semibold',
                                        hostMetrics?.has_error
                                            ? 'bg-error-500/15 text-error-600 dark:text-error-300'
                                            : 'bg-success-500/15 text-success-600 dark:text-success-300',
                                    ]"
                                >
                                    {{ hostMetrics?.has_error ? t('Offline') : t('Live') }}
                                </span>
                            </div>

                            <div class="grid grid-cols-3 gap-3 text-center">
                                <div>
                                    <div ref="cpuGaugeRef" class="min-h-[160px]"></div>
                                    <p class="text-xs text-gray-500 dark:text-gray-400">{{ t('CPU') }}</p>
                                </div>
                                <div>
                                    <div ref="ramGaugeRef" class="min-h-[160px]"></div>
                                    <p class="text-xs text-gray-500 dark:text-gray-400">
                                        {{ t('RAM') }} {{ hostMetrics?.mem_used_mb ?? 0 }}MB / {{ hostMetrics?.mem_total_mb ?? 0 }}MB
                                    </p>
                                </div>
                                <div>
                                    <div ref="diskGaugeRef" class="min-h-[160px]"></div>
                                    <p class="text-xs text-gray-500 dark:text-gray-400">
                                        {{ t('Disk') }} {{ hostMetrics?.disk_used_gb ?? 0 }}GB / {{ hostMetrics?.disk_total_gb ?? 0 }}GB
                                    </p>
                                </div>
                            </div>

                            <div class="mt-3">
                                <p class="mb-1 text-xs text-gray-500 dark:text-gray-400">
                                    <i class="bx bx-line-chart mr-1"></i>
                                    {{ t('CPU History') }}
                                </p>
                                <div ref="cpuSparklineRef" class="min-h-[60px]"></div>
                            </div>
                        </div>

                        <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03] xl:col-span-7">
                            <div class="mb-4 flex items-center gap-3">
                                <h4 class="text-sm font-semibold text-gray-800 dark:text-white/90">
                                    <i class="bx bx-box mr-1"></i>
                                    {{ t('Docker Services') }}
                                </h4>
                                <div class="ml-auto flex items-center gap-2">
                                    <div class="relative">
                                        <i class="bx bx-search absolute left-2 top-1/2 -translate-y-1/2 text-sm text-gray-400"></i>
                                        <input
                                            v-model="containerSearch"
                                            type="text"
                                            :placeholder="t('Filter...')"
                                            class="h-7 w-36 rounded border border-gray-300 bg-transparent pl-7 pr-2 text-xs text-gray-700 placeholder-gray-400 focus:border-brand-500 focus:outline-none dark:border-gray-700 dark:text-gray-300 dark:placeholder-gray-500"
                                        />
                                    </div>
                                    <span class="inline-flex rounded-full bg-gray-100 px-2.5 py-1 text-xs font-semibold text-gray-700 dark:bg-gray-800 dark:text-gray-300">
                                        {{ filteredContainers.length }} / {{ containers.length }}
                                    </span>
                                </div>
                            </div>

                            <div v-if="dockerServices?.has_error" class="py-6 text-center text-gray-500 dark:text-gray-400">
                                <i class="bx bx-error-circle text-3xl"></i>
                                <p class="mt-2 text-sm">{{ t('Portainer unreachable') }}</p>
                            </div>

                            <div v-else class="max-h-[320px] overflow-y-auto overflow-x-auto">
                                <table class="w-full min-w-[700px] table-fixed text-sm">
                                    <thead>
                                        <tr class="border-b border-gray-200 text-left text-xs uppercase text-gray-500 dark:border-gray-800 dark:text-gray-400">
                                            <th class="w-[210px] pb-3 pr-3">{{ t('Name') }}</th>
                                            <th class="w-[96px] pb-3 pr-3">{{ t('Status') }}</th>
                                            <th class="w-[166px] pb-3 pr-3">{{ t('CPU') }}</th>
                                            <th class="w-[166px] pb-3 pr-3">{{ t('RAM') }}</th>
                                            <th class="w-[96px] pb-3 text-center">{{ t('Actions') }}</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr
                                            v-for="container in filteredContainers"
                                            :key="container.id"
                                            class="border-b border-gray-100 last:border-0 dark:border-gray-800"
                                        >
                                            <td class="py-3 pr-3 align-top">
                                                <div class="flex items-start gap-2">
                                                    <i
                                                        :class="[
                                                            'bx mt-0.5 text-lg',
                                                            container.state === 'running'
                                                                ? 'bx-play-circle text-success-500'
                                                                : 'bx-stop-circle text-error-500',
                                                        ]"
                                                    ></i>
                                                    <div class="min-w-0">
                                                        <p
                                                            class="max-w-[180px] truncate font-semibold text-gray-800 dark:text-white/90"
                                                            :title="container.name"
                                                        >
                                                            {{ container.name }}
                                                        </p>
                                                        <p
                                                            class="max-w-[180px] truncate text-xs text-gray-500 dark:text-gray-400"
                                                            :title="container.image"
                                                        >
                                                            {{ container.image }}
                                                        </p>
                                                    </div>
                                                </div>
                                            </td>
                                            <td class="py-3 pr-3 align-middle">
                                                <span
                                                    :class="[
                                                        'inline-flex rounded-full px-2 py-1 text-xs font-semibold',
                                                        container.state === 'running'
                                                            ? 'bg-success-500/15 text-success-600 dark:text-success-300'
                                                            : container.state === 'exited'
                                                                ? 'bg-gray-200 text-gray-700 dark:bg-gray-700 dark:text-gray-300'
                                                                : 'bg-warning-500/15 text-warning-700 dark:text-warning-300',
                                                    ]"
                                                >
                                                    {{ formatContainerState(container.state) }}
                                                </span>
                                            </td>
                                            <td class="py-3 pr-3 align-middle">
                                                <div v-if="container.state === 'running'" class="flex items-center gap-2">
                                                    <div class="h-1.5 w-[110px] shrink-0 overflow-hidden rounded bg-gray-200 dark:bg-gray-800">
                                                        <div
                                                            class="h-full"
                                                            :class="cpuProgressClass(container.cpu_percent)"
                                                            :style="{ width: `${Math.min(container.cpu_percent, 100)}%` }"
                                                        ></div>
                                                    </div>
                                                    <span class="min-w-[44px] text-xs text-gray-500 dark:text-gray-400 whitespace-nowrap">
                                                        {{ container.cpu_percent.toFixed(1) }}%
                                                    </span>
                                                </div>
                                                <span v-else class="text-xs text-gray-500 dark:text-gray-400">-</span>
                                            </td>
                                            <td class="py-3 pr-3 align-middle">
                                                <div
                                                    v-if="container.state === 'running' && container.mem_mb > 0"
                                                    class="flex items-center gap-2"
                                                >
                                                    <div class="h-1.5 w-[110px] shrink-0 overflow-hidden rounded bg-gray-200 dark:bg-gray-800">
                                                        <div
                                                            class="h-full bg-theme-purple-500"
                                                            :style="{ width: `${Math.min(container.mem_mb / 10.24, 100)}%` }"
                                                        ></div>
                                                    </div>
                                                    <span class="min-w-[58px] text-xs text-gray-500 dark:text-gray-400 whitespace-nowrap">
                                                        {{ container.mem_mb.toFixed(0) }} MB
                                                    </span>
                                                </div>
                                                <span v-else class="text-xs text-gray-500 dark:text-gray-400">-</span>
                                            </td>
                                            <td class="py-3 align-top text-center">
                                                <div class="flex justify-center gap-1">
                                                    <template v-if="container.state === 'running'">
                                                        <button
                                                            @click="openTerminal(container)"
                                                            class="inline-flex h-7 w-7 items-center justify-center rounded border border-blue-light-500/40 text-blue-light-600 hover:bg-blue-light-500/10 dark:text-blue-light-300"
                                                            v-tooltip="t('Terminal')"
                                                        >
                                                            <i class="bx bx-terminal text-base"></i>
                                                        </button>
                                                        <button
                                                            @click="performDockerAction('restart', container)"
                                                            :disabled="isDockerActionLoading('restart', container.id)"
                                                            class="inline-flex h-7 w-7 items-center justify-center rounded border border-warning-500/40 text-warning-600 hover:bg-warning-500/10 disabled:opacity-50 dark:text-warning-300"
                                                            v-tooltip="t('Restart')"
                                                        >
                                                            <i class="bx bx-revision text-base"></i>
                                                        </button>
                                                        <button
                                                            @click="performDockerAction('stop', container)"
                                                            :disabled="isDockerActionLoading('stop', container.id)"
                                                            class="inline-flex h-7 w-7 items-center justify-center rounded border border-error-500/40 text-error-600 hover:bg-error-500/10 disabled:opacity-50 dark:text-error-300"
                                                            v-tooltip="t('Stop')"
                                                        >
                                                            <i class="bx bx-stop text-base"></i>
                                                        </button>
                                                    </template>
                                                    <button
                                                        v-else
                                                        @click="performDockerAction('start', container)"
                                                        :disabled="isDockerActionLoading('start', container.id)"
                                                        class="inline-flex h-7 w-7 items-center justify-center rounded border border-success-500/40 text-success-600 hover:bg-success-500/10 disabled:opacity-50 dark:text-success-300"
                                                        v-tooltip="t('Start')"
                                                    >
                                                        <i class="bx bx-play text-base"></i>
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <div :class="['grid grid-cols-1 gap-4 md:gap-6', isAdmin ? 'xl:grid-cols-12' : '']">
                        <div
                            :class="[
                                'rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]',
                                isAdmin ? 'xl:col-span-6' : '',
                            ]"
                        >
                            <div class="mb-4 flex items-center">
                                <h4 class="text-sm font-semibold text-gray-800 dark:text-white/90">
                                    <i class="bx bx-time-five mr-1"></i>
                                    {{ t('Recent Domains') }}
                                </h4>
                                <Link
                                    :href="route('domains.index')"
                                    class="ml-auto inline-flex items-center gap-1 text-xs font-medium text-brand-500 hover:text-brand-600"
                                >
                                    {{ t('View all') }}
                                    <i class="bx bx-right-arrow-alt text-sm"></i>
                                </Link>
                            </div>

                            <div class="max-h-80 overflow-x-hidden overflow-y-auto">
                                <table class="w-full text-sm">
                                    <thead>
                                        <tr class="border-b border-gray-200 text-left text-xs uppercase text-gray-500 dark:border-gray-800 dark:text-gray-400">
                                            <th class="pb-3">{{ t('Domain') }}</th>
                                            <th class="pb-3">{{ t('Type') }}</th>
                                            <th class="pb-3">{{ t('Status') }}</th>
                                            <th class="pb-3">{{ t('Created') }}</th>
                                            <th class="pb-3 text-center">{{ t('Actions') }}</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr
                                            v-if="recentDomains.length === 0"
                                            class="border-b border-gray-100 dark:border-gray-800"
                                        >
                                            <td colspan="5" class="py-6 text-center text-sm text-gray-500 dark:text-gray-400">
                                                {{ t('No domains yet.') }}
                                            </td>
                                        </tr>
                                        <tr
                                            v-for="domain in recentDomains"
                                            :key="domain.id"
                                            class="border-b border-gray-100 last:border-0 dark:border-gray-800"
                                        >
                                            <td class="py-3 align-top">
                                                <Link
                                                    :href="domain.show_url"
                                                    class="font-semibold text-brand-500 hover:text-brand-600"
                                                >
                                                    {{ domain.fqdn }}
                                                </Link>
                                                <p v-if="domain.php_version" class="text-xs text-gray-500 dark:text-gray-400">
                                                    PHP {{ domain.php_version }}
                                                </p>
                                            </td>
                                            <td class="py-3 align-top">
                                                <span
                                                    :class="[
                                                        'inline-flex rounded-full px-2 py-1 text-xs font-semibold',
                                                        domain.type === 'caddy_web_server'
                                                            ? 'bg-blue-light-500/15 text-blue-light-700 dark:text-blue-light-300'
                                                            : 'bg-gray-200 text-gray-700 dark:bg-gray-700 dark:text-gray-300',
                                                    ]"
                                                >
                                                    {{ domain.type_label ?? formatDomainType(domain.type) }}
                                                </span>
                                            </td>
                                            <td class="py-3 align-top">
                                                <span
                                                    :class="[
                                                        'inline-flex rounded-full px-2 py-1 text-xs font-semibold',
                                                        domainStatusClass(domain.status),
                                                    ]"
                                                >
                                                    {{ domain.status_label ?? formatDomainStatus(domain.status) }}
                                                </span>
                                            </td>
                                            <td class="py-3 align-top text-xs text-gray-500 dark:text-gray-400">
                                                {{ domain.created_ago }}
                                            </td>
                                            <td class="py-3 align-top text-center">
                                                <div class="flex justify-center gap-1">
                                                    <Link
                                                        :href="route('domains.files.index', domain.id)"
                                                        class="inline-flex h-6 w-6 items-center justify-center rounded text-gray-500 hover:bg-gray-100 hover:text-brand-500 dark:text-gray-400 dark:hover:bg-gray-800 dark:hover:text-brand-400"
                                                        v-tooltip="t('File Manager')"
                                                    >
                                                        <i class="bx bx-folder text-sm"></i>
                                                    </Link>
                                                    <Link
                                                        :href="route('domains.dns.index', domain.id)"
                                                        class="inline-flex h-6 w-6 items-center justify-center rounded text-gray-500 hover:bg-gray-100 hover:text-blue-light-500 dark:text-gray-400 dark:hover:bg-gray-800 dark:hover:text-blue-light-400"
                                                        v-tooltip="t('DNS')"
                                                    >
                                                        <i class="bx bx-globe text-sm"></i>
                                                    </Link>
                                                    <Link
                                                        :href="route('domains.cloudflare.manage', domain.id)"
                                                        class="inline-flex h-6 w-6 items-center justify-center rounded text-gray-500 hover:bg-gray-100 hover:text-warning-500 dark:text-gray-400 dark:hover:bg-gray-800 dark:hover:text-warning-400"
                                                        v-tooltip="t('Cloudflare')"
                                                    >
                                                        <i class="bx bx-cloud text-sm"></i>
                                                    </Link>
                                                    <button
                                                        v-if="domain.cloudflare_enabled"
                                                        :key="`ua-${domain.id}-${domain.under_attack}`"
                                                        @click="toggleUnderAttack(domain)"
                                                        :disabled="underAttackLoading === domain.id"
                                                        :class="[
                                                            'inline-flex h-6 w-6 items-center justify-center rounded disabled:opacity-50',
                                                            domain.under_attack
                                                                ? 'bg-error-500/20 text-error-600 hover:bg-error-500/30 dark:text-error-400'
                                                                : 'text-gray-500 hover:bg-gray-100 hover:text-error-500 dark:text-gray-400 dark:hover:bg-gray-800 dark:hover:text-error-400',
                                                        ]"
                                                        v-tooltip="t('Under Attack Mode') + '\n' + (domain.under_attack ? t('On') : t('Off'))"
                                                    >
                                                        <i :class="['bx text-sm', underAttackLoading === domain.id ? 'bx-loader-alt animate-spin' : 'bx-shield']"></i>
                                                    </button>
                                                    <span
                                                        v-else
                                                        class="inline-flex h-6 w-6 items-center justify-center rounded text-gray-300 dark:text-gray-600 cursor-not-allowed"
                                                        v-tooltip="t('Cloudflare is not active for this domain.')"
                                                    >
                                                        <i class="bx bx-shield text-sm"></i>
                                                    </span>
                                                </div>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <div
                            v-if="isAdmin"
                            class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03] xl:col-span-6"
                        >
                            <div class="mb-4 flex items-center">
                                <h4 class="text-sm font-semibold text-gray-800 dark:text-white/90">
                                    <i class="bx bx-data mr-1"></i>
                                    {{ t('MySQL Processes') }}
                                </h4>
                                <div class="ml-auto flex items-center gap-2">
                                    <span class="inline-flex rounded-full bg-blue-light-500/15 px-2.5 py-1 text-xs font-semibold text-blue-light-700 dark:text-blue-light-300">
                                        {{ mysqlMonitor?.total_connections ?? 0 }} {{ t('connections') }}
                                    </span>
                                    <button
                                        @click="toggleSleeping"
                                        :class="[
                                            'inline-flex items-center gap-1 rounded border px-2 py-1 text-xs font-medium',
                                            showSleeping
                                                ? 'border-warning-500/40 text-warning-700 hover:bg-warning-500/10 dark:text-warning-300'
                                                : 'border-gray-300 text-gray-600 hover:bg-gray-100 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-gray-800',
                                        ]"
                                    >
                                        <i :class="showSleeping ? 'bx bx-show' : 'bx bx-hide'"></i>
                                        {{ t('Sleep') }}
                                    </button>
                                </div>
                            </div>

                            <div v-if="mysqlMonitor?.has_error" class="py-6 text-center text-gray-500 dark:text-gray-400">
                                <i class="bx bx-error-circle text-3xl"></i>
                                <p class="mt-2 text-sm">{{ t('MySQL unreachable') }}</p>
                            </div>

                            <div v-else class="max-h-[320px] overflow-auto">
                                <table class="w-full min-w-[680px] text-sm">
                                    <thead>
                                        <tr class="border-b border-gray-200 text-left text-xs uppercase text-gray-500 dark:border-gray-800 dark:text-gray-400">
                                            <th class="pb-3">{{ t('ID') }}</th>
                                            <th class="pb-3">{{ t('User') }}</th>
                                            <th class="pb-3">{{ t('DB') }}</th>
                                            <th class="pb-3">{{ t('Time') }}</th>
                                            <th class="pb-3">{{ t('State') }}</th>
                                            <th class="pb-3">{{ t('Query') }}</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr
                                            v-if="processes.length === 0"
                                            class="border-b border-gray-100 dark:border-gray-800"
                                        >
                                            <td colspan="6" class="py-6 text-center text-sm text-gray-500 dark:text-gray-400">
                                                {{ t('No active processes') }}
                                            </td>
                                        </tr>
                                        <tr
                                            v-for="process in processes"
                                            :key="process.id"
                                            class="border-b border-gray-100 last:border-0 dark:border-gray-800"
                                        >
                                            <td class="py-3 text-xs text-gray-500 dark:text-gray-400">
                                                {{ process.id || '-' }}
                                            </td>
                                            <td class="py-3">
                                                <span class="inline-flex rounded bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-700 dark:bg-gray-800 dark:text-gray-300">
                                                    {{ process.user || '-' }}
                                                </span>
                                            </td>
                                            <td class="py-3 text-xs text-gray-500 dark:text-gray-400">
                                                {{ process.database || '-' }}
                                            </td>
                                            <td class="py-3 text-xs font-semibold" :class="mysqlTimeClass(process.time)">
                                                {{ process.time }}s
                                            </td>
                                            <td class="py-3 text-xs" :class="mysqlCommandClass(process.command)">
                                                {{ process.command }}
                                            </td>
                                            <td class="py-3">
                                                <code
                                                    v-if="process.info"
                                                    class="inline-block max-w-[240px] truncate text-xs text-blue-light-600 dark:text-blue-light-300"
                                                    :title="process.info"
                                                >
                                                    {{ truncate(process.info, 80) }}
                                                </code>
                                                <span v-else class="text-xs text-gray-500 dark:text-gray-400">-</span>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </AdminLayout>
        </SidebarProvider>
    </ThemeProvider>
</template>

<script setup lang="ts">
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import axios from 'axios';
import ThemeProvider from '@/Components/Layout/ThemeProvider.vue';
import SidebarProvider from '@/Components/Layout/SidebarProvider.vue';
import AdminLayout from '@/Components/Layout/AdminLayout.vue';
import PageBreadcrumb from '@/Components/Common/PageBreadcrumb.vue';
import Toast from '@/Components/UI/Toast.vue';
import { useToast } from '@/Composables/useToast';
import { useI18n } from '@/Composables/useI18n';

type DockerAction = 'start' | 'stop' | 'restart';

interface DashboardStats {
    total_domains: number;
    active_domains: number;
    subdomains: number;
    total_databases: number;
    total_users: number;
}

interface HostMetrics {
    has_error: boolean;
    cpu_percent: number;
    mem_used_mb: number;
    mem_total_mb: number;
    mem_percent: number;
    disk_used_gb: number;
    disk_total_gb: number;
    disk_percent: number;
}

interface DockerContainer {
    id: string;
    name: string;
    image: string;
    status: string;
    state: string;
    cpu_percent: number;
    mem_mb: number;
}

interface DockerServices {
    has_error: boolean;
    containers: DockerContainer[];
}

interface RecentDomain {
    id: number;
    fqdn: string;
    type: string;
    type_label?: string;
    status: string;
    status_label?: string;
    php_version: string | null;
    created_ago: string;
    show_url: string;
    cloudflare_enabled: boolean;
    under_attack: boolean | null;
}

interface MysqlProcess {
    id: number;
    user: string;
    database: string;
    time: number;
    command: string;
    info: string;
}

interface MysqlMonitor {
    has_error: boolean;
    show_sleeping: boolean;
    total_connections: number;
    processes: MysqlProcess[];
}

interface CrowdSecSummary {
    configured: boolean;
    has_error: boolean;
    lapi_online: boolean;
    status_code: number | null;
    active_decisions: number;
    recent_alerts_24h: number;
    top_scenarios: Array<{ name: string; count: number }>;
    last_sync_at: string;
}

interface ActiveBackup {
    id: number;
    status: string;
    progress_percent: number;
    started_at: string;
}

interface DashboardPayload {
    is_admin: boolean;
    stats: DashboardStats;
    recent_domains: RecentDomain[];
    host_metrics: HostMetrics | null;
    docker_services: DockerServices | null;
    mysql_monitor: MysqlMonitor | null;
    crowdsec: CrowdSecSummary | null;
    active_backup: ActiveBackup | null;
}

interface DockerActionResponse {
    success: boolean;
    message: string;
    dashboard: DashboardPayload;
}

type ApexChartInstance = {
    render: () => Promise<void>;
    updateSeries: (series: unknown[]) => void;
    destroy: () => void;
};

declare global {
    interface Window {
        ApexCharts?: new (element: HTMLElement, options: Record<string, unknown>) => ApexChartInstance;
    }
}

const props = defineProps<{
    dashboard: DashboardPayload;
}>();

const { addToast } = useToast();
const { t } = useI18n();
const VISIBLE_DASHBOARD_POLL_MS = 20000;
const HIDDEN_DASHBOARD_POLL_MS = 60000;

const dashboard = ref<DashboardPayload>(props.dashboard);
const showSleeping = ref<boolean>(dashboard.value.mysql_monitor?.show_sleeping ?? false);
const dockerActionLoading = ref<string | null>(null);
const containerSearch = ref('');
const cpuHistory = ref<number[]>([]);
const hasShownRefreshError = ref(false);

const cpuGaugeRef = ref<HTMLElement | null>(null);
const ramGaugeRef = ref<HTMLElement | null>(null);
const diskGaugeRef = ref<HTMLElement | null>(null);
const cpuSparklineRef = ref<HTMLElement | null>(null);

// Backup progress state
const backupProgress = ref({
    active: !!props.dashboard.active_backup,
    percent: props.dashboard.active_backup?.progress_percent ?? 0,
    message: '',
    runId: props.dashboard.active_backup?.id ?? null as number | null,
});

const isAdmin = computed(() => dashboard.value.is_admin);
const stats = computed(() => dashboard.value.stats);
const hostMetrics = computed(() => dashboard.value.host_metrics);
const dockerServices = computed(() => dashboard.value.docker_services);
const mysqlMonitor = computed(() => dashboard.value.mysql_monitor);
const crowdsec = computed(() => dashboard.value.crowdsec);
const recentDomains = computed(() => dashboard.value.recent_domains ?? []);
const crowdsecTopScenario = computed(() => {
    const top = crowdsec.value?.top_scenarios?.[0];
    if (!top || !top.name || top.name === '-') {
        return '';
    }

    return `${top.name} (${top.count})`;
});
const containers = computed(() => dockerServices.value?.containers ?? []);
const filteredContainers = computed(() => {
    const q = containerSearch.value.toLowerCase().trim();
    if (!q) {
        return containers.value;
    }
    return containers.value.filter(
        (c) => c.name.toLowerCase().includes(q) || c.image.toLowerCase().includes(q),
    );
});
const processes = computed(() => mysqlMonitor.value?.processes ?? []);
const underAttackLoading = ref<number | null>(null);

const toggleUnderAttack = async (domain: RecentDomain): Promise<void> => {
    if (underAttackLoading.value !== null) return;
    underAttackLoading.value = domain.id;

    try {
        const newValue = !domain.under_attack;
        const response = await axios.post(route('domains.cloudflare.setting', domain.id), {
            setting: 'under_attack',
            value: newValue,
        });
        domain.under_attack = newValue;
        addToast('success', response.data.message ?? t('Cloudflare setting updated.'));
    } catch (error: any) {
        const message = error?.response?.data?.message ?? t('Cloudflare setting update failed.');
        addToast('error', String(message));
    } finally {
        underAttackLoading.value = null;
    }
};

let cpuChart: ApexChartInstance | null = null;
let ramChart: ApexChartInstance | null = null;
let diskChart: ApexChartInstance | null = null;
let sparkChart: ApexChartInstance | null = null;
let dashboardPollTimer: ReturnType<typeof setInterval> | null = null;
let apexScriptPromise: Promise<void> | null = null;
let dashboardRefreshInFlight = false;
let lastDashboardRefreshAt = Date.now();
let themeObserver: MutationObserver | null = null;

const cpuProgressClass = (value: number): string => {
    if (value > 80) {
        return 'bg-error-500';
    }

    if (value > 50) {
        return 'bg-warning-500';
    }

    return 'bg-blue-light-500';
};

const domainStatusClass = (status: string): string => {
    switch (status) {
        case 'active':
            return 'bg-success-500/15 text-success-600 dark:text-success-300';
        case 'disabled':
            return 'bg-gray-200 text-gray-700 dark:bg-gray-700 dark:text-gray-300';
        case 'pending_cert':
            return 'bg-warning-500/15 text-warning-700 dark:text-warning-300';
        case 'failed':
            return 'bg-error-500/15 text-error-600 dark:text-error-300';
        default:
            return 'bg-gray-200 text-gray-700 dark:bg-gray-700 dark:text-gray-300';
    }
};

const formatDomainStatus = (status: string): string => {
    switch (status) {
        case 'active':
            return t('Active');
        case 'disabled':
            return t('Disabled');
        case 'pending_cert':
            return t('Pending');
        case 'failed':
            return t('Failed');
        default:
            return status;
    }
};

const formatDomainType = (type: string): string => {
    switch (type) {
        case 'caddy_web_server':
            return t('Caddy Web Server');
        case 'apache_reverse_proxy':
            return t('Apache + Reverse Proxy');
        default:
            return type;
    }
};

const formatContainerState = (state: string): string => {
    switch (state) {
        case 'running':
            return t('Running');
        case 'exited':
            return t('Exited');
        default:
            return state.charAt(0).toUpperCase() + state.slice(1);
    }
};

const mysqlTimeClass = (time: number): string => {
    if (time > 10) {
        return 'text-error-600 dark:text-error-300';
    }

    if (time > 3) {
        return 'text-warning-600 dark:text-warning-300';
    }

    return 'text-gray-500 dark:text-gray-400';
};

const mysqlCommandClass = (command: string): string => {
    if (command === 'Query') {
        return 'text-success-600 dark:text-success-300';
    }

    if (command === 'Sleep') {
        return 'text-gray-500 dark:text-gray-400';
    }

    return 'text-blue-light-700 dark:text-blue-light-300';
};

const truncate = (value: string, max: number): string => {
    if (value.length <= max) {
        return value;
    }

    return `${value.slice(0, max - 3)}...`;
};

const openTerminal = (container: DockerContainer): void => {
    document.dispatchEvent(
        new CustomEvent('open-terminal', {
            detail: {
                containerId: container.id,
                containerName: container.name,
            },
        }),
    );
};

const dockerActionKey = (action: DockerAction, containerId: string): string => {
    return `${action}:${containerId}`;
};

const isDockerActionLoading = (action: DockerAction, containerId: string): boolean => {
    return dockerActionLoading.value === dockerActionKey(action, containerId);
};

const toggleSleeping = (): void => {
    showSleeping.value = !showSleeping.value;
};

const ensureApexCharts = async (): Promise<boolean> => {
    if (typeof window === 'undefined') {
        return false;
    }

    if (window.ApexCharts) {
        return true;
    }

    if (!apexScriptPromise) {
        apexScriptPromise = new Promise((resolve, reject) => {
            const existingScript = document.getElementById('apexcharts-runtime') as HTMLScriptElement | null;
            if (existingScript) {
                existingScript.addEventListener('load', () => resolve(), { once: true });
                existingScript.addEventListener('error', () => reject(new Error('ApexCharts failed to load')), {
                    once: true,
                });

                return;
            }

            const script = document.createElement('script');
            script.id = 'apexcharts-runtime';
            script.src = '/themes/Cryptograph/assets/plugins/apexcharts-bundle/js/apexcharts.min.js';
            script.async = true;
            script.onload = () => resolve();
            script.onerror = () => reject(new Error('ApexCharts failed to load'));
            document.head.appendChild(script);
        });
    }

    try {
        await apexScriptPromise;

        return typeof window.ApexCharts !== 'undefined';
    } catch {
        apexScriptPromise = null;

        return false;
    }
};

const initializeCpuHistory = (): void => {
    const currentCpu = hostMetrics.value?.cpu_percent ?? 0;
    cpuHistory.value = Array.from({ length: 20 }, () => currentCpu);
};

const pushCpuHistory = (value: number): void => {
    if (cpuHistory.value.length === 0) {
        initializeCpuHistory();
    }

    cpuHistory.value = [...cpuHistory.value, value].slice(-20);
};

const gaugeValueColor = (): string => {
    return document.documentElement.classList.contains('dark') ? '#f8fafc' : '#1d2939';
};

const gaugeOptions = (value: number, colors: [string, string]): Record<string, unknown> => ({
    chart: {
        type: 'radialBar',
        height: 160,
        sparkline: {
            enabled: true,
        },
    },
    series: [value],
    plotOptions: {
        radialBar: {
            startAngle: -135,
            endAngle: 135,
            hollow: {
                size: '60%',
            },
            track: {
                background: 'rgba(148, 163, 184, 0.2)',
                strokeWidth: '97%',
            },
            dataLabels: {
                name: {
                    show: false,
                },
                value: {
                    offsetY: 8,
                    fontSize: '20px',
                    fontWeight: 700,
                    color: gaugeValueColor(),
                    formatter: (chartValue: number) => `${chartValue.toFixed(1)}%`,
                },
            },
        },
    },
    fill: {
        type: 'gradient',
        gradient: {
            shade: 'dark',
            type: 'horizontal',
            colorStops: [
                {
                    offset: 0,
                    color: colors[0],
                    opacity: 1,
                },
                {
                    offset: 100,
                    color: colors[1],
                    opacity: 1,
                },
            ],
        },
    },
    stroke: {
        lineCap: 'round',
    },
});

const sparklineOptions = (): Record<string, unknown> => ({
    chart: {
        type: 'area',
        height: 60,
        sparkline: {
            enabled: true,
        },
        animations: {
            enabled: true,
            easing: 'linear',
            dynamicAnimation: {
                speed: 500,
            },
        },
    },
    series: [
        {
            name: 'CPU %',
            data: cpuHistory.value,
        },
    ],
    stroke: {
        curve: 'smooth',
        width: 2,
    },
    fill: {
        type: 'gradient',
        gradient: {
            shadeIntensity: 1,
            opacityFrom: 0.4,
            opacityTo: 0.05,
        },
    },
    colors: ['#8be9fd'],
    tooltip: {
        theme: document.documentElement.classList.contains('dark') ? 'dark' : 'light',
        y: {
            formatter: (value: number) => `${value.toFixed(1)}%`,
        },
    },
    yaxis: {
        min: 0,
        max: 100,
    },
});

const destroyCharts = (): void => {
    cpuChart?.destroy();
    ramChart?.destroy();
    diskChart?.destroy();
    sparkChart?.destroy();

    cpuChart = null;
    ramChart = null;
    diskChart = null;
    sparkChart = null;
};

const initializeCharts = async (): Promise<void> => {
    if (!isAdmin.value || !hostMetrics.value || hostMetrics.value.has_error) {
        return;
    }

    if (!cpuGaugeRef.value || !ramGaugeRef.value || !diskGaugeRef.value || !cpuSparklineRef.value) {
        return;
    }

    const isLoaded = await ensureApexCharts();
    if (!isLoaded || !window.ApexCharts) {
        return;
    }

    destroyCharts();

    cpuChart = new window.ApexCharts(cpuGaugeRef.value, gaugeOptions(hostMetrics.value.cpu_percent, ['#8be9fd', '#ff5555']));
    ramChart = new window.ApexCharts(ramGaugeRef.value, gaugeOptions(hostMetrics.value.mem_percent, ['#50fa7b', '#f1fa8c']));
    diskChart = new window.ApexCharts(diskGaugeRef.value, gaugeOptions(hostMetrics.value.disk_percent, ['#bd93f9', '#ff79c6']));
    sparkChart = new window.ApexCharts(cpuSparklineRef.value, sparklineOptions());

    await cpuChart.render();
    await ramChart.render();
    await diskChart.render();
    await sparkChart.render();
};

const syncCharts = async (): Promise<void> => {
    if (!isAdmin.value || !hostMetrics.value || hostMetrics.value.has_error) {
        return;
    }

    if (!cpuChart || !ramChart || !diskChart || !sparkChart) {
        await nextTick();
        await initializeCharts();

        return;
    }

    cpuChart.updateSeries([hostMetrics.value.cpu_percent]);
    ramChart.updateSeries([hostMetrics.value.mem_percent]);
    diskChart.updateSeries([hostMetrics.value.disk_percent]);
    sparkChart.updateSeries([{ data: cpuHistory.value }]);
};

const applyDashboardPayload = async (payload: DashboardPayload): Promise<void> => {
    dashboard.value = payload;

    if (payload.host_metrics && !payload.host_metrics.has_error) {
        pushCpuHistory(payload.host_metrics.cpu_percent);
        await syncCharts();
    }
};

const refreshDashboard = async (): Promise<void> => {
    if (dashboardRefreshInFlight) {
        return;
    }

    dashboardRefreshInFlight = true;

    try {
        const response = await axios.get<DashboardPayload>(route('dashboard.data'), {
            params: {
                show_sleeping: showSleeping.value,
            },
        });

        await applyDashboardPayload(response.data);
        hasShownRefreshError.value = false;
    } catch {
        if (!hasShownRefreshError.value) {
            addToast('error', t('Dashboard data could not be refreshed.'));
            hasShownRefreshError.value = true;
        }
    } finally {
        dashboardRefreshInFlight = false;
        lastDashboardRefreshAt = Date.now();
    }
};

const performDockerAction = async (action: DockerAction, container: DockerContainer): Promise<void> => {
    const key = dockerActionKey(action, container.id);
    dockerActionLoading.value = key;

    try {
        const response = await axios.post<DockerActionResponse>(route('dashboard.docker.action'), {
            action,
            container_id: container.id,
            container_name: container.name,
            show_sleeping: showSleeping.value,
        });

        addToast(response.data.success ? 'success' : 'error', response.data.message);
        await applyDashboardPayload(response.data.dashboard);
    } catch (error: any) {
        const message = error?.response?.data?.message ?? t('Container action failed.');
        addToast('error', message);

        if (error?.response?.data?.dashboard) {
            await applyDashboardPayload(error.response.data.dashboard as DashboardPayload);
        }
    } finally {
        dockerActionLoading.value = null;
    }
};

const getDashboardPollInterval = (): number => {
    if (typeof document === 'undefined') {
        return VISIBLE_DASHBOARD_POLL_MS;
    }

    return document.hidden ? HIDDEN_DASHBOARD_POLL_MS : VISIBLE_DASHBOARD_POLL_MS;
};

const restartDashboardPolling = (): void => {
    if (dashboardPollTimer) {
        clearInterval(dashboardPollTimer);
        dashboardPollTimer = null;
    }

    dashboardPollTimer = setInterval(() => {
        void refreshDashboard();
    }, getDashboardPollInterval());
};

const handleVisibilityChange = (): void => {
    restartDashboardPolling();

    if (!document.hidden && Date.now() - lastDashboardRefreshAt >= VISIBLE_DASHBOARD_POLL_MS) {
        void refreshDashboard();
    }
};

const handleThemeChange = (): void => {
    if (!isAdmin.value || !hostMetrics.value || hostMetrics.value.has_error) {
        return;
    }

    void initializeCharts();
};

watch(showSleeping, () => {
    if (isAdmin.value) {
        void refreshDashboard();
    }
});

// Backup progress Echo listener
let backupEchoChannel: any = null;

onMounted(async () => {
    if (isAdmin.value) {
        initializeCpuHistory();
        await nextTick();
        await initializeCharts();

        // Listen for backup progress on admin channel
        if (typeof window.Echo !== 'undefined') {
            backupEchoChannel = window.Echo.private('admin').listen('BackupProgress', (e: any) => {
                backupProgress.value.active = true;
                backupProgress.value.percent = e.percent;
                backupProgress.value.message = e.message;
                backupProgress.value.runId = e.backup_run_id;

                if (e.status === 'completed' || e.status === 'failed') {
                    setTimeout(() => {
                        backupProgress.value.active = false;
                    }, 2000);
                }
            });
        }
    }

    restartDashboardPolling();
    document.addEventListener('visibilitychange', handleVisibilityChange);

    themeObserver = new MutationObserver(() => {
        handleThemeChange();
    });
    themeObserver.observe(document.documentElement, {
        attributes: true,
        attributeFilter: ['class'],
    });
});

onUnmounted(() => {
    if (dashboardPollTimer) {
        clearInterval(dashboardPollTimer);
        dashboardPollTimer = null;
    }

    if (backupEchoChannel) {
        backupEchoChannel.stopListening('BackupProgress');
    }

    document.removeEventListener('visibilitychange', handleVisibilityChange);
    themeObserver?.disconnect();
    themeObserver = null;
    destroyCharts();
});
</script>
