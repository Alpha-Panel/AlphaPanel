<template>
    <Head :title="`${t('Edit')} ${service.display_name || service.name}`" />
    <ThemeProvider>
        <SidebarProvider>
            <AdminLayout>
                <PageBreadcrumb
                    :pageTitle="`${t('Edit')}: ${service.display_name || service.name}`"
                    :items="breadcrumbs"
                    :backHref="route('docker-services.show', service.id)"
                />
                <Toast />

                <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03] md:p-6">
                    <h3 class="mb-5 text-lg font-semibold text-gray-800 dark:text-white/90">
                        {{ t('Edit Docker Service') }}
                    </h3>

                    <!-- Image & Tag (read-only info) -->
                    <div class="mb-5 rounded-lg border border-gray-200 bg-gray-50 p-4 dark:border-gray-700 dark:bg-gray-800/50">
                        <div class="flex items-center gap-3">
                            <i class="fa-brands fa-docker text-lg text-blue-light-500"></i>
                            <div>
                                <p class="text-sm font-medium text-gray-800 dark:text-white/90">
                                    {{ service.image }}:{{ service.tag }}
                                </p>
                                <p class="text-xs text-gray-500 dark:text-gray-400">{{ t('Image and tag cannot be changed after creation.') }}</p>
                            </div>
                        </div>
                    </div>

                    <!-- Warning -->
                    <div class="mb-5 rounded-lg border border-amber-200 bg-amber-50 p-3 dark:border-amber-800 dark:bg-amber-900/20">
                        <p class="text-xs text-amber-700 dark:text-amber-400">
                            <i class="fa-solid fa-triangle-exclamation mr-1"></i>
                            {{ t('Changes may require container recreation.') }}
                        </p>
                    </div>

                    <form @submit.prevent="submit" class="space-y-5">
                        <!-- Container Name -->
                        <FormField :label="t('Container Name')" :error="form.errors.name" required>
                            <input v-model="form.name" type="text" class="form-input" />
                        </FormField>

                        <!-- Display Name -->
                        <FormField :label="t('Display Name')" :error="form.errors.display_name">
                            <input v-model="form.display_name" type="text" class="form-input" :placeholder="t('Optional friendly name')" />
                        </FormField>

                        <!-- Hostname -->
                        <FormField :label="t('Hostname')" :error="form.errors.hostname">
                            <input v-model="form.hostname" type="text" class="form-input" :placeholder="t('Optional container hostname')" />
                        </FormField>

                        <!-- Restart Policy -->
                        <FormField :label="t('Restart Policy')" :error="form.errors.restart_policy" required>
                            <select v-model="form.restart_policy" class="form-input">
                                <option value="no">{{ t('No') }}</option>
                                <option value="always">{{ t('Always') }}</option>
                                <option value="unless-stopped">{{ t('Unless Stopped') }}</option>
                                <option value="on-failure">{{ t('On Failure') }}</option>
                            </select>
                        </FormField>

                        <!-- Environment Variables -->
                        <div class="pt-5 border-t border-gray-200 dark:border-gray-800">
                            <div class="mb-3 flex items-center justify-between">
                                <h4 class="text-sm font-medium text-gray-800 dark:text-white/90">{{ t('Environment Variables') }}</h4>
                                <button
                                    type="button"
                                    @click="addEnvVar"
                                    class="inline-flex items-center gap-1 rounded-md border border-gray-300 px-2.5 py-1 text-xs font-medium text-gray-600 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-400 dark:hover:bg-gray-800"
                                >
                                    <i class="fa-solid fa-plus text-[10px]"></i>
                                    {{ t('Add') }}
                                </button>
                            </div>
                            <div class="space-y-2">
                                <div v-for="(envVar, index) in envVars" :key="index" class="flex items-center gap-2">
                                    <input
                                        v-model="envVar.key"
                                        type="text"
                                        class="form-input flex-1"
                                        :placeholder="t('KEY')"
                                    />
                                    <span class="text-gray-400">=</span>
                                    <input
                                        v-model="envVar.value"
                                        type="text"
                                        class="form-input flex-1"
                                        :placeholder="t('value')"
                                    />
                                    <button
                                        type="button"
                                        @click="removeEnvVar(index)"
                                        class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg text-gray-400 hover:text-error-500"
                                    >
                                        <i class="fa-solid fa-xmark text-xs"></i>
                                    </button>
                                </div>
                                <p v-if="envVars.length === 0" class="text-xs text-gray-400 dark:text-gray-500">{{ t('No environment variables defined.') }}</p>
                            </div>
                            <p v-if="form.errors.environment_variables" class="mt-1 text-sm text-error-500">{{ form.errors.environment_variables }}</p>
                        </div>

                        <!-- Volumes -->
                        <div class="pt-5 border-t border-gray-200 dark:border-gray-800">
                            <div class="mb-3 flex items-center justify-between">
                                <h4 class="text-sm font-medium text-gray-800 dark:text-white/90">{{ t('Volumes') }}</h4>
                                <button
                                    type="button"
                                    @click="addVolume"
                                    class="inline-flex items-center gap-1 rounded-md border border-gray-300 px-2.5 py-1 text-xs font-medium text-gray-600 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-400 dark:hover:bg-gray-800"
                                >
                                    <i class="fa-solid fa-plus text-[10px]"></i>
                                    {{ t('Add') }}
                                </button>
                            </div>
                            <div class="space-y-2">
                                <div v-for="(vol, index) in volumes" :key="index" class="flex items-center gap-2">
                                    <input
                                        v-model="vol.host"
                                        type="text"
                                        class="form-input flex-1"
                                        :placeholder="t('Host path')"
                                    />
                                    <span class="text-gray-400">:</span>
                                    <input
                                        v-model="vol.container"
                                        type="text"
                                        class="form-input flex-1"
                                        :placeholder="t('Container path')"
                                    />
                                    <button
                                        type="button"
                                        @click="removeVolume(index)"
                                        class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg text-gray-400 hover:text-error-500"
                                    >
                                        <i class="fa-solid fa-xmark text-xs"></i>
                                    </button>
                                </div>
                                <p v-if="volumes.length === 0" class="text-xs text-gray-400 dark:text-gray-500">{{ t('No volumes defined.') }}</p>
                            </div>
                            <p v-if="form.errors.volumes" class="mt-1 text-sm text-error-500">{{ form.errors.volumes }}</p>
                        </div>

                        <!-- Ports -->
                        <div class="pt-5 border-t border-gray-200 dark:border-gray-800">
                            <div class="mb-3 flex items-center justify-between">
                                <h4 class="text-sm font-medium text-gray-800 dark:text-white/90">{{ t('Ports') }}</h4>
                                <button
                                    type="button"
                                    @click="addPort"
                                    class="inline-flex items-center gap-1 rounded-md border border-gray-300 px-2.5 py-1 text-xs font-medium text-gray-600 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-400 dark:hover:bg-gray-800"
                                >
                                    <i class="fa-solid fa-plus text-[10px]"></i>
                                    {{ t('Add') }}
                                </button>
                            </div>
                            <div class="space-y-2">
                                <div v-for="(port, index) in ports" :key="index" class="flex items-center gap-1.5">
                                    <input
                                        v-model="port.host"
                                        type="text"
                                        class="form-input w-28"
                                        :placeholder="t('Host (optional)')"
                                    />
                                    <span class="text-gray-400">:</span>
                                    <input
                                        v-model="port.container"
                                        type="text"
                                        class="form-input w-28"
                                        :placeholder="t('Container')"
                                    />
                                    <select v-model="port.protocol" class="form-input w-[4.5rem] shrink-0 px-1.5 text-xs">
                                        <option value="tcp">TCP</option>
                                        <option value="udp">UDP</option>
                                    </select>
                                    <button
                                        type="button"
                                        @click="removePort(index)"
                                        class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg text-gray-400 hover:text-error-500"
                                    >
                                        <i class="fa-solid fa-xmark text-xs"></i>
                                    </button>
                                </div>
                                <p v-if="ports.length === 0" class="text-xs text-gray-400 dark:text-gray-500">{{ t('No ports defined.') }}</p>
                            </div>
                            <p v-if="form.errors.ports" class="mt-1 text-sm text-error-500">{{ form.errors.ports }}</p>
                        </div>

                        <!-- Resource Limits -->
                        <div class="pt-5 border-t border-gray-200 dark:border-gray-800">
                            <h4 class="mb-3 text-sm font-medium text-gray-800 dark:text-white/90">{{ t('Resource Limits') }}</h4>
                            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                <FormField :label="t('CPU Limit (cores)')" :error="form.errors['resource_limits.cpus']">
                                    <input
                                        v-model.number="resourceLimits.cpus"
                                        type="number"
                                        min="0"
                                        step="0.25"
                                        class="form-input"
                                        :placeholder="t('e.g. 1.5')"
                                    />
                                </FormField>
                                <FormField :label="t('Memory Limit (MB)')" :error="form.errors['resource_limits.memory_mb']">
                                    <input
                                        v-model.number="resourceLimits.memory_mb"
                                        type="number"
                                        min="0"
                                        step="64"
                                        class="form-input"
                                        :placeholder="t('e.g. 512')"
                                    />
                                </FormField>
                            </div>
                        </div>

                        <!-- Submit / Cancel -->
                        <div class="flex items-center gap-3 pt-5">
                            <button
                                type="submit"
                                :disabled="form.processing"
                                class="inline-flex items-center gap-2 rounded-lg bg-brand-500 px-4 py-2.5 text-sm font-medium text-white shadow-theme-xs hover:bg-brand-600 disabled:opacity-50"
                            >
                                {{ form.processing ? t('Saving...') : t('Save Changes') }}
                            </button>
                            <Link
                                :href="route('docker-services.show', service.id)"
                                class="inline-flex items-center gap-2 rounded-lg border border-gray-300 bg-white px-4 py-2.5 text-sm font-medium text-gray-700 shadow-theme-xs hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-400"
                            >
                                {{ t('Cancel') }}
                            </Link>
                        </div>
                    </form>
                </div>
            </AdminLayout>
        </SidebarProvider>
    </ThemeProvider>
</template>

<script setup lang="ts">
import { computed, reactive, ref } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import ThemeProvider from '@/Components/Layout/ThemeProvider.vue';
import SidebarProvider from '@/Components/Layout/SidebarProvider.vue';
import AdminLayout from '@/Components/Layout/AdminLayout.vue';
import PageBreadcrumb from '@/Components/Common/PageBreadcrumb.vue';
import Toast from '@/Components/UI/Toast.vue';
import FormField from '@/Components/UI/FormField.vue';
import { useToast } from '@/Composables/useToast';
import { useI18n } from '@/Composables/useI18n';

interface DockerService {
    id: number;
    name: string;
    display_name: string | null;
    image: string;
    tag: string;
    status: string;
    restart_policy: string | null;
    container_id: string | null;
    environment_variables: Record<string, string> | null;
    volumes: Array<{ host: string; container: string }> | null;
    ports: Array<{ host: string; container: string; protocol: string }> | null;
    resource_limits: { cpus?: number; memory_mb?: number } | null;
    hostname: string | null;
    created_by: number | null;
}

const props = defineProps<{
    service: DockerService;
}>();

const { t } = useI18n();
const { watchFlash } = useToast();
watchFlash();

const breadcrumbs = computed(() => [
    { label: t('Docker Services'), href: route('docker-services.index') },
    { label: props.service.display_name || props.service.name, href: route('docker-services.show', props.service.id) },
    { label: t('Edit') },
]);

// Dynamic arrays for env vars, volumes, ports
interface EnvVarEntry { key: string; value: string }
interface VolumeEntry { host: string; container: string }
interface PortEntry { host: string; container: string; protocol: string }

const envVars = ref<EnvVarEntry[]>(
    props.service.environment_variables
        ? Object.entries(props.service.environment_variables).map(([key, value]) => ({ key, value }))
        : [],
);

const volumes = ref<VolumeEntry[]>(
    props.service.volumes
        ? props.service.volumes.map((v) => ({ host: v.host ?? '', container: v.container ?? '' }))
        : [],
);

const ports = ref<PortEntry[]>(
    props.service.ports
        ? props.service.ports.map((p) => ({ host: p.host ?? '', container: p.container ?? '', protocol: p.protocol ?? 'tcp' }))
        : [],
);

const resourceLimits = reactive({
    cpus: props.service.resource_limits?.cpus ?? null as number | null,
    memory_mb: props.service.resource_limits?.memory_mb ?? null as number | null,
});

const addEnvVar = (): void => {
    envVars.value.push({ key: '', value: '' });
};

const removeEnvVar = (index: number): void => {
    envVars.value.splice(index, 1);
};

const addVolume = (): void => {
    volumes.value.push({ host: '', container: '' });
};

const removeVolume = (index: number): void => {
    volumes.value.splice(index, 1);
};

const addPort = (): void => {
    ports.value.push({ host: '', container: '', protocol: 'tcp' });
};

const removePort = (index: number): void => {
    ports.value.splice(index, 1);
};

// Build environment_variables object from entries
const buildEnvVars = (): Record<string, string> | null => {
    const filtered = envVars.value.filter((e) => e.key.trim() !== '');
    if (filtered.length === 0) {
        return null;
    }

    const result: Record<string, string> = {};
    for (const entry of filtered) {
        result[entry.key.trim()] = entry.value;
    }

    return result;
};

// Build volumes array
const buildVolumes = (): Array<{ host: string; container: string }> | null => {
    const filtered = volumes.value.filter((v) => v.host.trim() !== '' || v.container.trim() !== '');
    return filtered.length > 0 ? filtered : null;
};

// Build ports array
const buildPorts = (): Array<{ host: string; container: string; protocol: string }> | null => {
    const filtered = ports.value.filter((p) => p.host.trim() !== '' || p.container.trim() !== '');
    return filtered.length > 0 ? filtered : null;
};

// Build resource limits
const buildResourceLimits = (): { cpus?: number; memory_mb?: number } | null => {
    const limits: { cpus?: number; memory_mb?: number } = {};
    if (resourceLimits.cpus !== null && resourceLimits.cpus > 0) {
        limits.cpus = resourceLimits.cpus;
    }
    if (resourceLimits.memory_mb !== null && resourceLimits.memory_mb > 0) {
        limits.memory_mb = resourceLimits.memory_mb;
    }

    return Object.keys(limits).length > 0 ? limits : null;
};

const form = useForm({
    _method: 'PUT' as const,
    name: props.service.name,
    display_name: props.service.display_name ?? '',
    hostname: props.service.hostname ?? '',
    restart_policy: props.service.restart_policy ?? 'no',
    environment_variables: null as Record<string, string> | null,
    volumes: null as Array<{ host: string; container: string }> | null,
    ports: null as Array<{ host: string; container: string; protocol: string }> | null,
    resource_limits: null as { cpus?: number; memory_mb?: number } | null,
});

const submit = (): void => {
    form.environment_variables = buildEnvVars();
    form.volumes = buildVolumes();
    form.ports = buildPorts();
    form.resource_limits = buildResourceLimits();

    form.post(route('docker-services.update', props.service.id));
};
</script>

<style scoped>
@reference "../../../css/app.css";

.form-input {
    @apply h-11 w-full rounded-lg border border-gray-300 bg-transparent px-4 py-2.5 text-sm text-gray-800 shadow-theme-xs placeholder:text-gray-400 focus:border-brand-300 focus:outline-hidden focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90 dark:placeholder:text-white/30 dark:focus:border-brand-800;
}
</style>
