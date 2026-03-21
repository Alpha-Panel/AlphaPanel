<template>
    <Head :title="t('Create Docker Service')" />
    <ThemeProvider>
        <SidebarProvider>
            <AdminLayout>
                <PageBreadcrumb
                    :pageTitle="t('Create Docker Service')"
                    :items="[
                        { label: t('Docker Services'), href: route('docker-services.index') },
                        { label: t('Create') },
                    ]"
                />
                <Toast />

                <div class="rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/3">
                    <!-- Step Indicator -->
                    <div class="border-b border-gray-200 px-5 py-5 dark:border-gray-800 md:px-6">
                        <nav class="flex items-center justify-between">
                            <ol class="flex w-full items-center">
                                <li
                                    v-for="(stepItem, index) in steps"
                                    :key="stepItem.key"
                                    class="flex items-center"
                                    :class="{ 'flex-1': index < steps.length - 1 }"
                                >
                                    <button
                                        type="button"
                                        class="group flex items-center gap-2.5"
                                        :disabled="!canNavigateToStep(index + 1)"
                                        @click="canNavigateToStep(index + 1) && goToStep(index + 1)"
                                    >
                                        <span
                                            class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-xs font-semibold transition-colors"
                                            :class="stepCircleClass(index + 1)"
                                        >
                                            <i v-if="currentStep > index + 1" class="bx bx-check text-sm"></i>
                                            <span v-else>{{ index + 1 }}</span>
                                        </span>
                                        <span
                                            class="hidden text-sm font-medium whitespace-nowrap sm:inline"
                                            :class="stepLabelClass(index + 1)"
                                        >
                                            {{ stepItem.label }}
                                        </span>
                                    </button>
                                    <div
                                        v-if="index < steps.length - 1"
                                        class="mx-3 h-px flex-1"
                                        :class="currentStep > index + 1 ? 'bg-brand-500' : 'bg-gray-200 dark:bg-gray-700'"
                                    ></div>
                                </li>
                            </ol>
                        </nav>
                    </div>

                    <!-- Step Content -->
                    <div class="p-5 md:p-6">
                        <!-- Step 1: Select Image -->
                        <div v-if="currentStep === 1">
                            <h3 class="mb-5 text-lg font-semibold text-gray-800 dark:text-white/90">
                                {{ t('Select Docker Image') }}
                            </h3>

                            <!-- Search Bar -->
                            <div class="relative mb-6">
                                <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-4">
                                    <i class="bx bx-search text-lg text-gray-400"></i>
                                </div>
                                <input
                                    v-model="searchQuery"
                                    type="text"
                                    :placeholder="t('Search Docker Hub...')"
                                    class="form-input"
                                    style="padding-left: 2.75rem"
                                />
                                <div v-if="searchLoading" class="absolute inset-y-0 right-0 flex items-center pr-4">
                                    <div class="h-5 w-5 animate-spin rounded-full border-2 border-gray-300 border-t-brand-500"></div>
                                </div>
                            </div>

                            <!-- Search Results -->
                            <div v-if="searchResults.length > 0" class="mb-8">
                                <h4 class="mb-3 text-sm font-medium text-gray-600 dark:text-gray-400">
                                    {{ t('Search Results') }}
                                </h4>
                                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
                                    <button
                                        v-for="image in searchResults"
                                        :key="image.name"
                                        type="button"
                                        class="group relative flex flex-col rounded-xl border border-gray-200 bg-white p-4 text-left transition-all hover:border-brand-300 hover:shadow-md dark:border-gray-700 dark:bg-gray-900 dark:hover:border-brand-500/50"
                                        @click="selectImage(image)"
                                    >
                                        <div class="mb-2 flex items-center gap-2">
                                            <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-blue-50 text-blue-600 dark:bg-blue-500/10 dark:text-blue-400">
                                                <i class="bx bxl-docker text-xl"></i>
                                            </div>
                                            <div class="min-w-0 flex-1">
                                                <p class="truncate text-sm font-semibold text-gray-800 dark:text-white/90">
                                                    {{ image.name }}
                                                </p>
                                            </div>
                                            <span
                                                v-if="image.is_official"
                                                class="shrink-0 rounded-full bg-brand-50 px-2 py-0.5 text-[10px] font-semibold tracking-wide text-brand-600 uppercase dark:bg-brand-500/10 dark:text-brand-400"
                                            >
                                                {{ t('Official') }}
                                            </span>
                                        </div>
                                        <p class="mb-3 line-clamp-2 text-xs leading-relaxed text-gray-500 dark:text-gray-400">
                                            {{ image.description || t('No description available') }}
                                        </p>
                                        <div class="mt-auto flex items-center gap-3 text-xs text-gray-400 dark:text-gray-500">
                                            <span class="flex items-center gap-1">
                                                <i class="bx bxs-star text-amber-400"></i>
                                                {{ formatNumber(image.star_count) }}
                                            </span>
                                            <span class="flex items-center gap-1">
                                                <i class="bx bx-download"></i>
                                                {{ formatNumber(image.pull_count) }}
                                            </span>
                                        </div>
                                    </button>
                                </div>
                            </div>

                            <!-- Popular Images -->
                            <div v-if="!searchQuery.trim()">
                                <h4 class="mb-3 text-sm font-medium text-gray-600 dark:text-gray-400">
                                    {{ t('Popular Images') }}
                                </h4>
                                <div v-if="popularLoading" class="flex items-center justify-center py-12">
                                    <div class="h-8 w-8 animate-spin rounded-full border-3 border-gray-200 border-t-brand-500 dark:border-gray-700"></div>
                                </div>
                                <div v-else class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
                                    <button
                                        v-for="image in popularImages"
                                        :key="image.name"
                                        type="button"
                                        class="group flex items-center gap-3 rounded-xl border border-gray-200 bg-white p-4 text-left transition-all hover:border-brand-300 hover:shadow-md dark:border-gray-700 dark:bg-gray-900 dark:hover:border-brand-500/50"
                                        @click="selectImage({ ...image, is_official: true, star_count: 0, pull_count: 0 })"
                                    >
                                        <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-gray-100 text-gray-600 transition-colors group-hover:bg-brand-50 group-hover:text-brand-600 dark:bg-gray-800 dark:text-gray-400 dark:group-hover:bg-brand-500/10 dark:group-hover:text-brand-400">
                                            <i :class="image.icon" class="text-lg"></i>
                                        </div>
                                        <div class="min-w-0 flex-1">
                                            <p class="text-sm font-semibold text-gray-800 dark:text-white/90">
                                                {{ image.name }}
                                            </p>
                                            <p class="truncate text-xs text-gray-500 dark:text-gray-400">
                                                {{ image.description }}
                                            </p>
                                        </div>
                                        <span class="shrink-0 rounded-full bg-gray-100 px-2 py-0.5 text-[10px] font-medium text-gray-500 dark:bg-gray-800 dark:text-gray-400">
                                            {{ image.category }}
                                        </span>
                                    </button>
                                </div>
                            </div>

                            <!-- No Results -->
                            <div
                                v-if="searchQuery.trim() && !searchLoading && searchResults.length === 0"
                                class="flex flex-col items-center justify-center py-12 text-center"
                            >
                                <div class="mb-3 flex h-12 w-12 items-center justify-center rounded-full bg-gray-100 dark:bg-gray-800">
                                    <i class="bx bx-search text-xl text-gray-400"></i>
                                </div>
                                <p class="text-sm text-gray-500 dark:text-gray-400">
                                    {{ t('No images found for ":query"', { query: searchQuery }) }}
                                </p>
                            </div>
                        </div>

                        <!-- Step 2: Select Tag -->
                        <div v-if="currentStep === 2">
                            <h3 class="mb-5 text-lg font-semibold text-gray-800 dark:text-white/90">
                                {{ t('Select Tag') }}
                            </h3>

                            <!-- Selected Image Info -->
                            <div class="mb-5 flex items-center gap-3 rounded-xl border border-brand-200 bg-brand-50 p-4 dark:border-brand-500/30 dark:bg-brand-500/10">
                                <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-brand-100 text-brand-600 dark:bg-brand-500/20 dark:text-brand-400">
                                    <i class="bx bxl-docker text-xl"></i>
                                </div>
                                <div>
                                    <p class="text-sm font-semibold text-brand-800 dark:text-brand-200">
                                        {{ form.image }}
                                    </p>
                                    <p v-if="selectedImageData?.description" class="text-xs text-brand-600 dark:text-brand-400">
                                        {{ selectedImageData.description }}
                                    </p>
                                </div>
                            </div>

                            <!-- Tags List -->
                            <div v-if="tagsLoading" class="flex items-center justify-center py-12">
                                <div class="h-8 w-8 animate-spin rounded-full border-3 border-gray-200 border-t-brand-500 dark:border-gray-700"></div>
                            </div>
                            <div v-else-if="availableTags.length > 0" class="space-y-2">
                                <button
                                    v-for="tag in availableTags"
                                    :key="tag.name"
                                    type="button"
                                    class="flex w-full items-center gap-3 rounded-xl border p-3.5 text-left transition-all"
                                    :class="
                                        form.tag === tag.name
                                            ? 'border-brand-300 bg-brand-50 ring-1 ring-brand-300 dark:border-brand-500/50 dark:bg-brand-500/10 dark:ring-brand-500/50'
                                            : 'border-gray-200 bg-white hover:border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:hover:border-gray-600'
                                    "
                                    @click="selectTag(tag.name)"
                                >
                                    <div
                                        class="flex h-5 w-5 shrink-0 items-center justify-center rounded-full border-2 transition-colors"
                                        :class="
                                            form.tag === tag.name
                                                ? 'border-brand-500 bg-brand-500'
                                                : 'border-gray-300 dark:border-gray-600'
                                        "
                                    >
                                        <div
                                            v-if="form.tag === tag.name"
                                            class="h-2 w-2 rounded-full bg-white"
                                        ></div>
                                    </div>
                                    <div class="min-w-0 flex-1">
                                        <p class="text-sm font-medium text-gray-800 dark:text-white/90">
                                            {{ tag.name }}
                                            <span
                                                v-if="tag.name === 'latest'"
                                                class="ml-1.5 rounded bg-green-50 px-1.5 py-0.5 text-[10px] font-semibold text-green-600 uppercase dark:bg-green-500/10 dark:text-green-400"
                                            >
                                                {{ t('Recommended') }}
                                            </span>
                                        </p>
                                    </div>
                                    <div class="flex shrink-0 items-center gap-3 text-xs text-gray-400 dark:text-gray-500">
                                        <span v-if="tag.full_size">{{ formatBytes(tag.full_size) }}</span>
                                        <span v-if="tag.last_updated">{{ formatDate(tag.last_updated) }}</span>
                                    </div>
                                </button>
                            </div>
                            <div
                                v-else
                                class="flex flex-col items-center justify-center py-12 text-center"
                            >
                                <p class="text-sm text-gray-500 dark:text-gray-400">
                                    {{ t('No tags found for this image.') }}
                                </p>
                            </div>

                            <!-- Navigation -->
                            <div class="mt-6 flex items-center gap-3">
                                <button type="button" class="btn-secondary" @click="goToStep(1)">
                                    <i class="bx bx-arrow-back text-base"></i>
                                    {{ t('Back') }}
                                </button>
                            </div>
                            <p class="mt-3 text-xs text-gray-400 dark:text-gray-500">
                                <i class="bx bx-info-circle mr-0.5 align-middle"></i>
                                {{ t('Click a tag to select it and continue.') }}
                            </p>
                        </div>

                        <!-- Step 3: Configure -->
                        <div v-if="currentStep === 3">
                            <h3 class="mb-5 text-lg font-semibold text-gray-800 dark:text-white/90">
                                {{ t('Configure Service') }}
                            </h3>

                            <div class="space-y-5">
                                <!-- Basic Info -->
                                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                    <FormField :label="t('Container Name')" :error="form.errors.name" required>
                                        <input
                                            v-model="form.name"
                                            type="text"
                                            :placeholder="t('ext-my-service')"
                                            class="form-input"
                                        />
                                        <p class="mt-1 text-xs text-gray-400 dark:text-gray-500">
                                            {{ t('Lowercase letters, numbers, and hyphens only.') }}
                                        </p>
                                    </FormField>
                                    <FormField :label="t('Display Name')" :error="form.errors.display_name">
                                        <input
                                            v-model="form.display_name"
                                            type="text"
                                            :placeholder="t('My Service')"
                                            class="form-input"
                                        />
                                    </FormField>
                                </div>

                                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                    <FormField :label="t('Hostname')" :error="form.errors.hostname">
                                        <input
                                            v-model="form.hostname"
                                            type="text"
                                            :placeholder="t('Optional')"
                                            class="form-input"
                                        />
                                    </FormField>
                                    <FormField :label="t('Restart Policy')" :error="form.errors.restart_policy" required>
                                        <select v-model="form.restart_policy" class="form-input">
                                            <option value="no">{{ t('No') }}</option>
                                            <option value="always">{{ t('Always') }}</option>
                                            <option value="unless-stopped">{{ t('Unless Stopped') }}</option>
                                            <option value="on-failure">{{ t('On Failure') }}</option>
                                        </select>
                                    </FormField>
                                </div>

                                <!-- Environment Variables -->
                                <div class="border-t border-gray-200 pt-5 dark:border-gray-800">
                                    <div class="mb-3 flex items-center justify-between">
                                        <h4 class="text-sm font-medium text-gray-800 dark:text-white/90">
                                            {{ t('Environment Variables') }}
                                        </h4>
                                        <button
                                            type="button"
                                            class="inline-flex items-center gap-1 rounded-lg border border-gray-300 px-2.5 py-1.5 text-xs font-medium text-gray-700 transition hover:bg-gray-50 dark:border-gray-700 dark:text-gray-400 dark:hover:bg-white/5"
                                            @click="addEnvVar"
                                        >
                                            <i class="bx bx-plus text-sm"></i>
                                            {{ t('Add Variable') }}
                                        </button>
                                    </div>
                                    <div v-if="envVarRows.length > 0" class="space-y-2">
                                        <div
                                            v-for="(row, index) in envVarRows"
                                            :key="index"
                                            class="flex items-center gap-2"
                                        >
                                            <input
                                                v-model="row.key"
                                                type="text"
                                                :placeholder="t('KEY')"
                                                class="form-input flex-1 font-mono text-xs"
                                            />
                                            <span class="text-gray-400">=</span>
                                            <input
                                                v-model="row.value"
                                                type="text"
                                                :placeholder="t('value')"
                                                class="form-input flex-1 font-mono text-xs"
                                            />
                                            <button
                                                type="button"
                                                class="flex h-11 w-11 shrink-0 items-center justify-center rounded-lg text-gray-400 transition hover:bg-red-50 hover:text-red-500 dark:hover:bg-red-500/10"
                                                @click="removeEnvVar(index)"
                                            >
                                                <i class="bx bx-trash text-base"></i>
                                            </button>
                                        </div>
                                    </div>
                                    <p v-else class="text-xs text-gray-400 dark:text-gray-500">
                                        {{ t('No environment variables configured.') }}
                                    </p>
                                </div>

                                <!-- Volumes -->
                                <div class="border-t border-gray-200 pt-5 dark:border-gray-800">
                                    <div class="mb-3 flex items-center justify-between">
                                        <h4 class="text-sm font-medium text-gray-800 dark:text-white/90">
                                            {{ t('Volumes') }}
                                        </h4>
                                        <button
                                            type="button"
                                            class="inline-flex items-center gap-1 rounded-lg border border-gray-300 px-2.5 py-1.5 text-xs font-medium text-gray-700 transition hover:bg-gray-50 dark:border-gray-700 dark:text-gray-400 dark:hover:bg-white/5"
                                            @click="addVolume"
                                        >
                                            <i class="bx bx-plus text-sm"></i>
                                            {{ t('Add Volume') }}
                                        </button>
                                    </div>
                                    <div v-if="volumeRows.length > 0" class="space-y-2">
                                        <div
                                            v-for="(row, index) in volumeRows"
                                            :key="index"
                                            class="flex items-center gap-2"
                                        >
                                            <input
                                                v-model="row.host_path"
                                                type="text"
                                                :placeholder="t('Host path')"
                                                class="form-input flex-1 font-mono text-xs"
                                            />
                                            <span class="text-gray-400">:</span>
                                            <input
                                                v-model="row.container_path"
                                                type="text"
                                                :placeholder="t('Container path')"
                                                class="form-input flex-1 font-mono text-xs"
                                            />
                                            <select v-model="row.mode" class="form-input w-20 shrink-0 text-xs">
                                                <option value="rw">rw</option>
                                                <option value="ro">ro</option>
                                            </select>
                                            <button
                                                type="button"
                                                class="flex h-11 w-11 shrink-0 items-center justify-center rounded-lg text-gray-400 transition hover:bg-red-50 hover:text-red-500 dark:hover:bg-red-500/10"
                                                @click="removeVolume(index)"
                                            >
                                                <i class="bx bx-trash text-base"></i>
                                            </button>
                                        </div>
                                    </div>
                                    <p v-else class="text-xs text-gray-400 dark:text-gray-500">
                                        {{ t('No volumes configured.') }}
                                    </p>
                                    <p class="mt-2 text-xs text-gray-400 dark:text-gray-500">
                                        <i class="bx bx-info-circle mr-0.5 align-middle"></i>
                                        {{ t('Host paths will be auto-prefixed relative to the Docker data directory.') }}
                                    </p>
                                </div>

                                <!-- Ports -->
                                <div class="border-t border-gray-200 pt-5 dark:border-gray-800">
                                    <div class="mb-3 flex items-center justify-between">
                                        <h4 class="text-sm font-medium text-gray-800 dark:text-white/90">
                                            {{ t('Ports') }}
                                        </h4>
                                        <button
                                            type="button"
                                            class="inline-flex items-center gap-1 rounded-lg border border-gray-300 px-2.5 py-1.5 text-xs font-medium text-gray-700 transition hover:bg-gray-50 dark:border-gray-700 dark:text-gray-400 dark:hover:bg-white/5"
                                            @click="addPort"
                                        >
                                            <i class="bx bx-plus text-sm"></i>
                                            {{ t('Add Port') }}
                                        </button>
                                    </div>
                                    <div v-if="portRows.length > 0" class="space-y-2">
                                        <div
                                            v-for="(row, index) in portRows"
                                            :key="index"
                                            class="flex items-center gap-2"
                                        >
                                            <input
                                                v-model.number="row.host_port"
                                                type="number"
                                                min="1"
                                                max="65535"
                                                :placeholder="t('Host port')"
                                                class="form-input flex-1 font-mono text-xs"
                                            />
                                            <span class="text-gray-400">:</span>
                                            <input
                                                v-model.number="row.container_port"
                                                type="number"
                                                min="1"
                                                max="65535"
                                                :placeholder="t('Container port')"
                                                class="form-input flex-1 font-mono text-xs"
                                            />
                                            <select v-model="row.protocol" class="form-input w-20 shrink-0 text-xs">
                                                <option value="tcp">TCP</option>
                                                <option value="udp">UDP</option>
                                            </select>
                                            <button
                                                type="button"
                                                class="flex h-11 w-11 shrink-0 items-center justify-center rounded-lg text-gray-400 transition hover:bg-red-50 hover:text-red-500 dark:hover:bg-red-500/10"
                                                @click="removePort(index)"
                                            >
                                                <i class="bx bx-trash text-base"></i>
                                            </button>
                                        </div>
                                    </div>
                                    <p v-else class="text-xs text-gray-400 dark:text-gray-500">
                                        {{ t('No ports configured.') }}
                                    </p>
                                </div>

                                <!-- Resource Limits -->
                                <div class="border-t border-gray-200 pt-5 dark:border-gray-800">
                                    <h4 class="mb-3 text-sm font-medium text-gray-800 dark:text-white/90">
                                        {{ t('Resource Limits') }}
                                    </h4>
                                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                        <FormField :label="t('CPU Limit (cores)')" :error="form.errors['resource_limits.cpu_limit']">
                                            <input
                                                v-model.number="form.resource_limits.cpu_limit"
                                                type="number"
                                                step="0.1"
                                                min="0.1"
                                                max="16"
                                                :placeholder="t('Unlimited')"
                                                class="form-input"
                                            />
                                        </FormField>
                                        <FormField :label="t('Memory Limit')" :error="form.errors['resource_limits.memory_limit']">
                                            <select v-model="form.resource_limits.memory_limit" class="form-input">
                                                <option :value="null">{{ t('Unlimited') }}</option>
                                                <option value="128M">128 MB</option>
                                                <option value="256M">256 MB</option>
                                                <option value="512M">512 MB</option>
                                                <option value="1G">1 GB</option>
                                                <option value="2G">2 GB</option>
                                                <option value="4G">4 GB</option>
                                                <option value="8G">8 GB</option>
                                                <option value="16G">16 GB</option>
                                            </select>
                                        </FormField>
                                    </div>
                                </div>
                            </div>

                            <!-- Navigation -->
                            <div class="mt-6 flex items-center gap-3">
                                <button type="button" class="btn-secondary" @click="goToStep(2)">
                                    <i class="bx bx-arrow-back text-base"></i>
                                    {{ t('Back') }}
                                </button>
                                <button type="button" class="btn-primary" @click="goToStep(4)">
                                    {{ t('Next') }}
                                    <i class="bx bx-arrow-back bx-rotate-180 text-base"></i>
                                </button>
                            </div>
                        </div>

                        <!-- Step 4: Review & Deploy -->
                        <div v-if="currentStep === 4">
                            <h3 class="mb-5 text-lg font-semibold text-gray-800 dark:text-white/90">
                                {{ t('Review & Deploy') }}
                            </h3>

                            <div class="space-y-4">
                                <!-- Image & Tag -->
                                <div class="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
                                    <h4 class="mb-3 text-xs font-semibold tracking-wider text-gray-500 uppercase dark:text-gray-400">
                                        {{ t('Image') }}
                                    </h4>
                                    <div class="flex items-center gap-3">
                                        <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-blue-50 text-blue-600 dark:bg-blue-500/10 dark:text-blue-400">
                                            <i class="bx bxl-docker text-xl"></i>
                                        </div>
                                        <div>
                                            <p class="text-sm font-semibold text-gray-800 dark:text-white/90">
                                                {{ form.image }}
                                            </p>
                                            <p class="text-xs text-gray-500 dark:text-gray-400">
                                                {{ t('Tag') }}: <span class="font-mono font-medium">{{ form.tag }}</span>
                                            </p>
                                        </div>
                                    </div>
                                </div>

                                <!-- Container Settings -->
                                <div class="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
                                    <h4 class="mb-3 text-xs font-semibold tracking-wider text-gray-500 uppercase dark:text-gray-400">
                                        {{ t('Container Settings') }}
                                    </h4>
                                    <dl class="grid grid-cols-1 gap-2 text-sm sm:grid-cols-2">
                                        <div>
                                            <dt class="text-gray-500 dark:text-gray-400">{{ t('Name') }}</dt>
                                            <dd class="font-medium font-mono text-gray-800 dark:text-white/90">{{ form.name }}</dd>
                                        </div>
                                        <div v-if="form.display_name">
                                            <dt class="text-gray-500 dark:text-gray-400">{{ t('Display Name') }}</dt>
                                            <dd class="font-medium text-gray-800 dark:text-white/90">{{ form.display_name }}</dd>
                                        </div>
                                        <div v-if="form.hostname">
                                            <dt class="text-gray-500 dark:text-gray-400">{{ t('Hostname') }}</dt>
                                            <dd class="font-medium font-mono text-gray-800 dark:text-white/90">{{ form.hostname }}</dd>
                                        </div>
                                        <div>
                                            <dt class="text-gray-500 dark:text-gray-400">{{ t('Restart Policy') }}</dt>
                                            <dd class="font-medium text-gray-800 dark:text-white/90">{{ restartPolicyLabel(form.restart_policy) }}</dd>
                                        </div>
                                    </dl>
                                </div>

                                <!-- Environment Variables -->
                                <div
                                    v-if="reviewEnvVars.length > 0"
                                    class="rounded-xl border border-gray-200 p-4 dark:border-gray-700"
                                >
                                    <h4 class="mb-3 text-xs font-semibold tracking-wider text-gray-500 uppercase dark:text-gray-400">
                                        {{ t('Environment Variables') }}
                                        <span class="ml-1 text-gray-400">({{ reviewEnvVars.length }})</span>
                                    </h4>
                                    <div class="space-y-1.5">
                                        <div
                                            v-for="env in reviewEnvVars"
                                            :key="env.key"
                                            class="flex items-center gap-2 font-mono text-xs"
                                        >
                                            <span class="font-semibold text-gray-700 dark:text-gray-300">{{ env.key }}</span>
                                            <span class="text-gray-400">=</span>
                                            <span class="text-gray-600 dark:text-gray-400">{{ env.value }}</span>
                                        </div>
                                    </div>
                                </div>

                                <!-- Volumes -->
                                <div
                                    v-if="reviewVolumes.length > 0"
                                    class="rounded-xl border border-gray-200 p-4 dark:border-gray-700"
                                >
                                    <h4 class="mb-3 text-xs font-semibold tracking-wider text-gray-500 uppercase dark:text-gray-400">
                                        {{ t('Volumes') }}
                                        <span class="ml-1 text-gray-400">({{ reviewVolumes.length }})</span>
                                    </h4>
                                    <div class="space-y-1.5">
                                        <div
                                            v-for="(vol, index) in reviewVolumes"
                                            :key="index"
                                            class="font-mono text-xs text-gray-700 dark:text-gray-300"
                                        >
                                            {{ vol.host_path }}:{{ vol.container_path }}:{{ vol.mode }}
                                        </div>
                                    </div>
                                </div>

                                <!-- Ports -->
                                <div
                                    v-if="reviewPorts.length > 0"
                                    class="rounded-xl border border-gray-200 p-4 dark:border-gray-700"
                                >
                                    <h4 class="mb-3 text-xs font-semibold tracking-wider text-gray-500 uppercase dark:text-gray-400">
                                        {{ t('Ports') }}
                                        <span class="ml-1 text-gray-400">({{ reviewPorts.length }})</span>
                                    </h4>
                                    <div class="space-y-1.5">
                                        <div
                                            v-for="(port, index) in reviewPorts"
                                            :key="index"
                                            class="font-mono text-xs text-gray-700 dark:text-gray-300"
                                        >
                                            {{ port.host_port }}:{{ port.container_port }}/{{ port.protocol }}
                                        </div>
                                    </div>
                                </div>

                                <!-- Resources -->
                                <div
                                    v-if="form.resource_limits.cpu_limit || form.resource_limits.memory_limit"
                                    class="rounded-xl border border-gray-200 p-4 dark:border-gray-700"
                                >
                                    <h4 class="mb-3 text-xs font-semibold tracking-wider text-gray-500 uppercase dark:text-gray-400">
                                        {{ t('Resource Limits') }}
                                    </h4>
                                    <dl class="grid grid-cols-1 gap-2 text-sm sm:grid-cols-2">
                                        <div v-if="form.resource_limits.cpu_limit">
                                            <dt class="text-gray-500 dark:text-gray-400">{{ t('CPU') }}</dt>
                                            <dd class="font-medium text-gray-800 dark:text-white/90">
                                                {{ form.resource_limits.cpu_limit }} {{ t('cores') }}
                                            </dd>
                                        </div>
                                        <div v-if="form.resource_limits.memory_limit">
                                            <dt class="text-gray-500 dark:text-gray-400">{{ t('Memory') }}</dt>
                                            <dd class="font-medium text-gray-800 dark:text-white/90">
                                                {{ form.resource_limits.memory_limit }}
                                            </dd>
                                        </div>
                                    </dl>
                                </div>
                            </div>

                            <!-- Navigation -->
                            <div class="mt-6 flex items-center gap-3">
                                <button type="button" class="btn-secondary" @click="goToStep(3)">
                                    <i class="bx bx-arrow-back text-base"></i>
                                    {{ t('Back') }}
                                </button>
                                <button
                                    type="button"
                                    class="btn-primary"
                                    :disabled="form.processing"
                                    @click="deploy"
                                >
                                    <i v-if="form.processing" class="bx bx-loader-alt animate-spin text-base"></i>
                                    <i v-else class="bx bx-rocket text-base"></i>
                                    {{ form.processing ? t('Deploying...') : t('Deploy Service') }}
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </AdminLayout>
        </SidebarProvider>
    </ThemeProvider>
</template>

<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { ref, computed, watch, onMounted } from 'vue';
import ThemeProvider from '@/Components/Layout/ThemeProvider.vue';
import SidebarProvider from '@/Components/Layout/SidebarProvider.vue';
import AdminLayout from '@/Components/Layout/AdminLayout.vue';
import PageBreadcrumb from '@/Components/Common/PageBreadcrumb.vue';
import Toast from '@/Components/UI/Toast.vue';
import FormField from '@/Components/UI/FormField.vue';
import { useI18n } from '@/Composables/useI18n';

const { t } = useI18n();

// ─── Step State ────────────────────────────────────────────────────────────────

const currentStep = ref(1);

const steps = [
    { key: 'image', label: t('Select Image') },
    { key: 'tag', label: t('Select Tag') },
    { key: 'configure', label: t('Configure') },
    { key: 'deploy', label: t('Review & Deploy') },
];

const stepCircleClass = (step: number): string => {
    if (currentStep.value > step) {
        return 'bg-brand-500 text-white';
    }
    if (currentStep.value === step) {
        return 'bg-brand-500 text-white ring-4 ring-brand-100 dark:ring-brand-500/20';
    }
    return 'bg-gray-100 text-gray-500 dark:bg-gray-800 dark:text-gray-400';
};

const stepLabelClass = (step: number): string => {
    if (currentStep.value >= step) {
        return 'text-gray-800 dark:text-white/90';
    }
    return 'text-gray-400 dark:text-gray-500';
};

const canNavigateToStep = (step: number): boolean => {
    if (step <= currentStep.value) {
        return true;
    }
    // Can only go forward one step at a time
    return false;
};

const goToStep = (step: number): void => {
    // When moving to step 3, sync env var rows to form
    if (currentStep.value === 3 && step !== 3) {
        syncEnvVarsToForm();
    }
    currentStep.value = step;

    // Load data when entering steps
    if (step === 2 && availableTags.value.length === 0) {
        fetchTags();
    }
    if (step === 3) {
        fetchImageConfig();
    }
    if (step === 4) {
        syncEnvVarsToForm();
    }
};

// ─── Form ──────────────────────────────────────────────────────────────────────

const form = useForm({
    name: '',
    display_name: '',
    image: '',
    tag: 'latest',
    hostname: '',
    restart_policy: 'unless-stopped',
    environment_variables: {} as Record<string, string>,
    volumes: [] as Array<{ host_path: string; container_path: string; mode: string }>,
    ports: [] as Array<{ host_port: number | null; container_port: number | null; protocol: string }>,
    resource_limits: {
        cpu_limit: null as number | null,
        memory_limit: null as string | null,
    },
    networks: [] as string[],
});

// ─── Step 1: Image Selection ───────────────────────────────────────────────────

const searchQuery = ref('');
const searchResults = ref<Array<{
    name: string;
    description: string;
    star_count: number;
    pull_count: number;
    is_official: boolean;
}>>([]);
const searchLoading = ref(false);
const popularImages = ref<Array<{
    name: string;
    description: string;
    icon: string;
    category: string;
}>>([]);
const popularLoading = ref(false);
const selectedImageData = ref<{
    name: string;
    description: string;
} | null>(null);

let searchDebounceTimer: ReturnType<typeof setTimeout> | null = null;

watch(searchQuery, (query) => {
    if (searchDebounceTimer) {
        clearTimeout(searchDebounceTimer);
    }

    const trimmed = query.trim();
    if (trimmed.length < 2) {
        searchResults.value = [];
        return;
    }

    searchLoading.value = true;
    searchDebounceTimer = setTimeout(async () => {
        try {
            const response = await fetch(route('docker-hub.search') + '?' + new URLSearchParams({ query: trimmed }));
            const data = await response.json();
            searchResults.value = data.results ?? [];
        } catch {
            searchResults.value = [];
        } finally {
            searchLoading.value = false;
        }
    }, 300);
});

const fetchPopularImages = async (): Promise<void> => {
    popularLoading.value = true;
    try {
        const response = await fetch(route('docker-hub.popular'));
        const data = await response.json();
        popularImages.value = data.images ?? [];
    } catch {
        popularImages.value = [];
    } finally {
        popularLoading.value = false;
    }
};

const selectImage = (image: { name: string; description?: string }): void => {
    form.image = image.name;
    selectedImageData.value = {
        name: image.name,
        description: image.description ?? '',
    };

    // Auto-generate container name
    const baseName = image.name.replace(/[^a-z0-9]/g, '-').replace(/-+/g, '-').replace(/^-|-$/g, '');
    form.name = `ext-${baseName}`;

    // Reset tag and advance
    form.tag = 'latest';
    availableTags.value = [];
    goToStep(2);
};

// ─── Step 2: Tag Selection ─────────────────────────────────────────────────────

const availableTags = ref<Array<{
    name: string;
    last_updated: string | null;
    full_size: number;
    digest: string | null;
}>>([]);
const tagsLoading = ref(false);

const fetchTags = async (): Promise<void> => {
    if (!form.image) return;

    tagsLoading.value = true;
    try {
        const response = await fetch(route('docker-hub.tags') + '?' + new URLSearchParams({ image: form.image }));
        const data = await response.json();
        availableTags.value = data.results ?? [];

        // Pre-select "latest" if it exists
        const hasLatest = availableTags.value.some((tag) => tag.name === 'latest');
        if (hasLatest) {
            form.tag = 'latest';
        } else if (availableTags.value.length > 0) {
            form.tag = availableTags.value[0].name;
        }
    } catch {
        availableTags.value = [];
    } finally {
        tagsLoading.value = false;
    }
};

const selectTag = (tagName: string): void => {
    form.tag = tagName;
    goToStep(3);
};

// ─── Step 3: Configuration ─────────────────────────────────────────────────────

const envVarRows = ref<Array<{ key: string; value: string }>>([]);
const volumeRows = ref<Array<{ host_path: string; container_path: string; mode: string }>>([]);
const portRows = ref<Array<{ host_port: number | null; container_port: number | null; protocol: string }>>([]);
let imageConfigFetched = false;

const addEnvVar = (): void => {
    envVarRows.value.push({ key: '', value: '' });
};

const removeEnvVar = (index: number): void => {
    envVarRows.value.splice(index, 1);
};

const addVolume = (): void => {
    volumeRows.value.push({ host_path: '', container_path: '', mode: 'rw' });
};

const removeVolume = (index: number): void => {
    volumeRows.value.splice(index, 1);
};

const addPort = (): void => {
    portRows.value.push({ host_port: null, container_port: null, protocol: 'tcp' });
};

const removePort = (index: number): void => {
    portRows.value.splice(index, 1);
};

const fetchImageConfig = async (): Promise<void> => {
    if (!form.image || imageConfigFetched) return;

    try {
        const response = await fetch(route('docker-hub.image-config') + '?' + new URLSearchParams({ image: form.image }));
        const data = await response.json();

        // Pre-populate environment variables
        if (data.env && Object.keys(data.env).length > 0 && envVarRows.value.length === 0) {
            envVarRows.value = Object.entries(data.env).map(([key, value]) => ({
                key,
                value: value as string,
            }));
        }

        // Pre-populate exposed ports
        if (data.exposed_ports && data.exposed_ports.length > 0 && portRows.value.length === 0) {
            portRows.value = data.exposed_ports.map((portSpec: string) => {
                const [port, protocol] = portSpec.split('/');
                return {
                    host_port: parseInt(port, 10) || null,
                    container_port: parseInt(port, 10) || null,
                    protocol: protocol || 'tcp',
                };
            });
        }

        // Pre-populate volumes
        if (data.volumes && data.volumes.length > 0 && volumeRows.value.length === 0) {
            volumeRows.value = data.volumes.map((containerPath: string) => ({
                host_path: '',
                container_path: containerPath,
                mode: 'rw',
            }));
        }

        imageConfigFetched = true;
    } catch {
        // silently ignore -- image config is best-effort
    }
};

const syncEnvVarsToForm = (): void => {
    const envObj: Record<string, string> = {};
    for (const row of envVarRows.value) {
        const key = row.key.trim();
        if (key) {
            envObj[key] = row.value;
        }
    }
    form.environment_variables = envObj;
    form.volumes = volumeRows.value.filter((v) => v.container_path.trim());
    form.ports = portRows.value.filter((p) => p.container_port);
};

// ─── Step 4: Review ────────────────────────────────────────────────────────────

const reviewEnvVars = computed(() => {
    return Object.entries(form.environment_variables)
        .filter(([key]) => key.trim())
        .map(([key, value]) => ({ key, value }));
});

const reviewVolumes = computed(() => {
    return form.volumes.filter((v) => v.container_path.trim());
});

const reviewPorts = computed(() => {
    return form.ports.filter((p) => p.container_port);
});

const restartPolicyLabel = (policy: string): string => {
    const labels: Record<string, string> = {
        'no': t('No'),
        'always': t('Always'),
        'unless-stopped': t('Unless Stopped'),
        'on-failure': t('On Failure'),
    };
    return labels[policy] ?? policy;
};

const deploy = (): void => {
    syncEnvVarsToForm();
    form.post(route('docker-services.store'));
};

// ─── Utility Functions ─────────────────────────────────────────────────────────

const formatNumber = (num: number): string => {
    if (num >= 1_000_000_000) {
        return (num / 1_000_000_000).toFixed(1) + 'B';
    }
    if (num >= 1_000_000) {
        return (num / 1_000_000).toFixed(1) + 'M';
    }
    if (num >= 1_000) {
        return (num / 1_000).toFixed(1) + 'K';
    }
    return String(num);
};

const formatBytes = (bytes: number): string => {
    if (bytes === 0) return '0 B';
    const units = ['B', 'KB', 'MB', 'GB'];
    const i = Math.floor(Math.log(bytes) / Math.log(1024));
    return (bytes / Math.pow(1024, i)).toFixed(1) + ' ' + units[i];
};

const formatDate = (dateStr: string): string => {
    const date = new Date(dateStr);
    const now = new Date();
    const diff = now.getTime() - date.getTime();
    const days = Math.floor(diff / (1000 * 60 * 60 * 24));

    if (days === 0) return t('Today');
    if (days === 1) return t('Yesterday');
    if (days < 30) return t(':days days ago', { days });
    if (days < 365) return t(':months months ago', { months: Math.floor(days / 30) });
    return t(':years years ago', { years: Math.floor(days / 365) });
};

// ─── Lifecycle ─────────────────────────────────────────────────────────────────

onMounted(() => {
    fetchPopularImages();
});
</script>

<style scoped>
@reference "../../../css/app.css";

.form-input {
    @apply h-11 w-full rounded-lg border border-gray-300 bg-transparent px-4 py-2.5 text-sm text-gray-800 shadow-theme-xs placeholder:text-gray-400 focus:border-brand-300 focus:outline-hidden focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90 dark:placeholder:text-white/30 dark:focus:border-brand-800;
}

select.form-input {
    @apply bg-white dark:bg-gray-900;
}

select.form-input option {
    @apply bg-white text-gray-800 dark:bg-gray-900 dark:text-white/90;
}

.btn-primary {
    @apply inline-flex items-center gap-2 rounded-lg bg-brand-500 px-4 py-2.5 text-sm font-medium text-white shadow-theme-xs transition hover:bg-brand-600 disabled:opacity-50 disabled:cursor-not-allowed;
}

.btn-secondary {
    @apply inline-flex items-center gap-2 rounded-lg border border-gray-300 bg-white px-4 py-2.5 text-sm font-medium text-gray-700 shadow-theme-xs transition hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-400 dark:hover:bg-white/5;
}
</style>
