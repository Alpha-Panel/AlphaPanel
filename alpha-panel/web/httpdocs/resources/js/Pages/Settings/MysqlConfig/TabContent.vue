<template>
    <div class="space-y-5">
        <!-- Mode Toggle -->
        <div class="flex items-center justify-between">
            <p class="text-sm text-gray-500 dark:text-gray-400">
                {{ advancedMode ? t('Editing raw config file') : t('Editing structured settings') }}
            </p>
            <button
                type="button"
                class="inline-flex items-center gap-1.5 rounded-lg border border-gray-300 px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-gray-800"
                @click="toggleMode"
            >
                <i :class="advancedMode ? 'bx bx-list-ul' : 'bx bx-code-alt'" class="text-sm"></i>
                {{ advancedMode ? t('Structured Mode') : t('Advanced Mode') }}
            </button>
        </div>

        <!-- Structured Mode -->
        <form v-if="!advancedMode" @submit.prevent="submitStructured" class="space-y-5">
            <div v-if="props.schema.length === 0" class="py-6 text-center text-sm text-gray-400 dark:text-gray-500">
                {{ t('No configurable parameters defined for this file.') }}
            </div>

            <div v-else class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                <div
                    v-for="param in props.schema"
                    :key="param.key"
                    class="rounded-xl border border-gray-200 p-4 dark:border-gray-700"
                >
                    <div class="mb-2 flex items-start justify-between gap-2">
                        <div class="flex-1">
                            <label class="block text-sm font-medium text-gray-800 dark:text-white/90">
                                {{ param.label }}
                            </label>
                            <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">{{ param.description }}</p>
                        </div>
                        <span
                            v-if="param.restart_required"
                            class="shrink-0 inline-flex items-center gap-1 rounded-full border border-amber-300 bg-amber-50 px-2 py-0.5 text-xs font-medium text-amber-700 dark:border-amber-700 dark:bg-amber-900/20 dark:text-amber-400"
                        >
                            <i class="bx bx-refresh text-xs"></i>
                            {{ t('Restart') }}
                        </span>
                    </div>

                    <!-- bool -->
                    <div v-if="param.type === 'bool'" class="flex items-center gap-2 pt-1">
                        <ToggleSwitch
                            :modelValue="structuredForm[param.key] === '1' || structuredForm[param.key] === 'ON'"
                            @update:modelValue="(val) => setToggle(param.key, val)"
                        />
                        <span class="text-xs text-gray-500 dark:text-gray-400">
                            {{ structuredForm[param.key] === '1' || structuredForm[param.key] === 'ON' ? t('Enabled') : t('Disabled') }}
                        </span>
                    </div>

                    <!-- select -->
                    <select
                        v-else-if="param.type === 'select'"
                        v-model="structuredForm[param.key]"
                        class="form-input mt-1"
                    >
                        <option v-for="opt in param.options" :key="opt" :value="opt">{{ opt }}</option>
                    </select>

                    <!-- int -->
                    <input
                        v-else-if="param.type === 'int'"
                        v-model="structuredForm[param.key]"
                        type="number"
                        class="form-input mt-1"
                    />

                    <!-- size -->
                    <input
                        v-else-if="param.type === 'size'"
                        v-model="structuredForm[param.key]"
                        type="text"
                        class="form-input mt-1"
                        placeholder="128M / 5G"
                    />

                    <!-- string (default) -->
                    <input
                        v-else
                        v-model="structuredForm[param.key]"
                        type="text"
                        class="form-input mt-1"
                    />

                    <p v-if="structuredForm.errors[param.key]" class="mt-1 text-xs text-error-500">
                        {{ structuredForm.errors[param.key] }}
                    </p>
                </div>
            </div>

            <div class="flex items-center gap-3 border-t border-gray-200 pt-4 dark:border-gray-800">
                <button
                    type="submit"
                    :disabled="structuredForm.processing"
                    class="inline-flex items-center gap-2 rounded-lg bg-brand-500 px-4 py-2.5 text-sm font-medium text-white shadow-theme-xs hover:bg-brand-600 disabled:opacity-50"
                >
                    <i v-if="structuredForm.processing" class="bx bx-loader-alt animate-spin text-base"></i>
                    {{ structuredForm.processing ? t('Saving...') : t('Save Settings') }}
                </button>
            </div>
        </form>

        <!-- Advanced / Raw Mode -->
        <div v-else class="space-y-4">
            <div class="rounded-lg border border-blue-200 bg-blue-50 p-3 dark:border-blue-800 dark:bg-blue-900/20">
                <p class="text-xs text-blue-700 dark:text-blue-400">
                    <i class="bx bx-info-circle mr-1"></i>
                    {{ t('Editing the raw configuration file. The file must contain a [mysqld] section header.') }}
                </p>
            </div>

            <textarea
                v-model="rawForm.content"
                rows="20"
                spellcheck="false"
                class="raw-textarea font-mono"
            ></textarea>

            <p v-if="rawError" class="text-sm text-error-500">{{ rawError }}</p>

            <div class="flex items-center gap-3 border-t border-gray-200 pt-4 dark:border-gray-800">
                <button
                    type="button"
                    :disabled="rawForm.processing"
                    class="inline-flex items-center gap-2 rounded-lg bg-brand-500 px-4 py-2.5 text-sm font-medium text-white shadow-theme-xs hover:bg-brand-600 disabled:opacity-50"
                    @click="submitRaw"
                >
                    <i v-if="rawForm.processing" class="bx bx-loader-alt animate-spin text-base"></i>
                    {{ rawForm.processing ? t('Saving...') : t('Save Raw Config') }}
                </button>
            </div>
        </div>
    </div>
</template>

<script setup lang="ts">
import { ref } from 'vue';
import { useForm } from '@inertiajs/vue3';
import ToggleSwitch from '@/Components/UI/ToggleSwitch.vue';
import { useI18n } from '@/Composables/useI18n';

interface SchemaParam {
    key: string;
    type: 'bool' | 'int' | 'size' | 'string' | 'select';
    label: string;
    description: string;
    set_global: boolean;
    restart_required: boolean;
    options: string[];
    global_var: string;
}

const props = defineProps<{
    file: string;
    schema: SchemaParam[];
    rawContent: string;
    parsedValues: Record<string, string>;
}>();

const emit = defineEmits<{
    saved: [restartRequired: boolean];
}>();

const { t } = useI18n();

const advancedMode = ref(false);

// ---- Structured mode ----
const initialValues = Object.fromEntries(
    props.schema.map((param) => [param.key, props.parsedValues[param.key] ?? '']),
);

const structuredForm = useForm<Record<string, string>>(initialValues);

const setToggle = (key: string, val: boolean): void => {
    structuredForm[key] = val ? '1' : '0';
};

const submitStructured = (): void => {
    structuredForm.put(route('settings.mysql-config.update', { file: props.file }), {
        preserveScroll: true,
        onSuccess: () => {
            const needsRestart = props.schema.some(
                (p) => p.restart_required && structuredForm[p.key] !== (props.parsedValues[p.key] ?? ''),
            );
            emit('saved', needsRestart);
        },
    });
};

// ---- Raw / Advanced mode ----
const rawForm = useForm({ content: props.rawContent });
const rawError = ref('');

const toggleMode = (): void => {
    rawError.value = '';
    advancedMode.value = !advancedMode.value;
};

const submitRaw = (): void => {
    rawError.value = '';

    if (!rawForm.content.includes('[mysqld]')) {
        rawError.value = t('The configuration file must contain a [mysqld] section header.');
        return;
    }

    rawForm.put(route('settings.mysql-config.update-raw', { file: props.file }), {
        preserveScroll: true,
        onSuccess: () => {
            emit('saved', true);
        },
        onError: (errors) => {
            rawError.value = errors.content ?? t('Failed to save configuration.');
        },
    });
};
</script>

<style scoped>
@reference "../../../../css/app.css";

.form-input {
    @apply h-11 w-full rounded-lg border border-gray-300 bg-transparent px-4 py-2.5 text-sm text-gray-800 shadow-theme-xs placeholder:text-gray-400 focus:border-brand-300 focus:outline-hidden focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90 dark:placeholder:text-white/30 dark:focus:border-brand-800;
}

.raw-textarea {
    @apply h-auto w-full rounded-lg border border-gray-300 bg-transparent px-4 py-3 text-sm text-gray-800 shadow-theme-xs placeholder:text-gray-400 focus:border-brand-300 focus:outline-hidden focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90 dark:placeholder:text-white/30 dark:focus:border-brand-800;
}
</style>
