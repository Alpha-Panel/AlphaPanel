<template>
    <div
        v-if="modelValue"
        class="fixed inset-0 z-1200000 flex items-center justify-center bg-black/60 p-4 backdrop-blur-sm"
    >
        <div class="flex max-h-[92vh] w-full max-w-4xl flex-col rounded-xl border border-gray-700 bg-[#171717] text-white/90 shadow-2xl">
            <div class="flex items-center justify-between border-b border-white/10 px-5 py-4">
                <div>
                    <h5 class="text-base text-white">
                        <i class="fa-brands fa-php mr-2 text-brand-500"></i>
                        {{ title }}
                    </h5>
                    <p class="mt-0.5 text-xs text-white/50">php.ini</p>
                </div>
                <button
                    type="button"
                    class="text-2xl leading-none text-white/50 hover:text-white"
                    :disabled="saving"
                    @click="attemptClose"
                >
                    &times;
                </button>
            </div>

            <div class="relative min-h-0 flex-1">
                <div v-if="loading" class="flex h-96 items-center justify-center">
                    <i class="bx bx-loader-alt animate-spin text-3xl text-brand-500"></i>
                </div>

                <div v-else ref="editorContainer" class="h-[60vh]"></div>
            </div>

            <div class="flex items-center justify-between border-t border-white/10 px-5 py-3">
                <p class="text-xs text-white/40">Ctrl+S</p>
                <div class="flex gap-2">
                    <button
                        type="button"
                        class="rounded-lg border border-white/10 px-4 py-2 text-sm text-white/70 hover:bg-white/5"
                        :disabled="saving"
                        @click="attemptClose"
                    >
                        {{ t('Cancel') }}
                    </button>
                    <button
                        type="button"
                        class="inline-flex items-center gap-2 rounded-lg bg-brand-500 px-4 py-2 text-sm font-medium text-white hover:bg-brand-600 disabled:opacity-50"
                        :disabled="saving || loading"
                        @click="save"
                    >
                        <i v-if="saving" class="bx bx-loader-alt animate-spin text-base"></i>
                        {{ saving ? t('Saving...') : t('Save') }}
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup lang="ts">
import { ref, computed, watch, nextTick, onBeforeUnmount } from 'vue';
import axios from 'axios';
import { useToast } from '@/Composables/useToast';
import { useI18n } from '@/Composables/useI18n';

interface PhpVersion {
    id: number;
    slug: string;
    is_enabled: boolean;
    domains_count: number;
}

const props = defineProps<{
    modelValue: boolean;
    phpVersion: PhpVersion | null;
    frankenphp?: boolean;
}>();

const emit = defineEmits<{
    'update:modelValue': [value: boolean];
    saved: [];
}>();

const { t } = useI18n();
const { addToast } = useToast();

const title = computed(() => {
    if (props.frankenphp) return t('FrankenPHP Settings');
    return t('PHP :version Settings', { version: props.phpVersion?.slug ?? '' });
});

const getRoute = computed(() => {
    if (props.frankenphp) return route('php-versions.frankenphp-ini');
    return route('php-versions.php-ini', props.phpVersion!.id);
});

const putRoute = computed(() => {
    if (props.frankenphp) return route('php-versions.frankenphp-ini.update');
    return route('php-versions.php-ini.update', props.phpVersion!.id);
});

const loading = ref(false);
const saving = ref(false);
const editorContainer = ref<HTMLElement | null>(null);
const originalContent = ref('');

let monacoModule: typeof import('monaco-editor') | null = null;
let editor: ReturnType<typeof import('monaco-editor')['editor']['create']> | null = null;

async function initMonaco(): Promise<typeof import('monaco-editor')> {
    if (!monacoModule) {
        monacoModule = await import('monaco-editor');
    }
    return monacoModule;
}

async function loadContent(): Promise<void> {
    if (!props.frankenphp && !props.phpVersion) return;

    loading.value = true;

    try {
        const response = await axios.get(getRoute.value);
        originalContent.value = response.data.content ?? '';
    } catch {
        addToast('error', t('Failed to load PHP configuration.'));
        originalContent.value = '';
    } finally {
        loading.value = false;
    }
}

async function setupEditor(): Promise<void> {
    const monaco = await initMonaco();

    await nextTick();

    if (!editorContainer.value) return;

    editor = monaco.editor.create(editorContainer.value, {
        value: originalContent.value,
        language: 'ini',
        theme: 'vs-dark',
        fontSize: 14,
        minimap: { enabled: false },
        automaticLayout: true,
        scrollBeyondLastLine: false,
        wordWrap: 'on',
        lineNumbers: 'on',
        renderWhitespace: 'selection',
        tabSize: 4,
        insertSpaces: true,
        bracketPairColorization: { enabled: true },
        padding: { top: 8 },
    });

    editor.addCommand(monaco.KeyMod.CtrlCmd | monaco.KeyCode.KeyS, () => {
        save();
    });
}

function destroyEditor(): void {
    if (editor) {
        editor.dispose();
        editor = null;
    }
}

function hasUnsavedChanges(): boolean {
    if (!editor) return false;
    return editor.getValue() !== originalContent.value;
}

function attemptClose(): void {
    if (hasUnsavedChanges()) {
        if (!confirm(t('This file has unsaved changes. Close anyway?'))) {
            return;
        }
    }
    close();
}

function close(): void {
    destroyEditor();
    emit('update:modelValue', false);
}

async function save(): Promise<void> {
    if ((!props.frankenphp && !props.phpVersion) || !editor || saving.value) return;

    saving.value = true;

    try {
        const response = await axios.put(
            putRoute.value,
            { content: editor.getValue() },
        );

        originalContent.value = editor.getValue();
        addToast('success', response.data.message);
        emit('saved');
    } catch (error: any) {
        const message = error.response?.data?.message
            ?? t('Failed to save PHP configuration: :error', { error: 'Unknown error' });
        addToast('error', message);
    } finally {
        saving.value = false;
    }
}

watch(() => props.modelValue, async (visible) => {
    if (visible && (props.phpVersion || props.frankenphp)) {
        await loadContent();
        await nextTick();
        await setupEditor();
    } else {
        destroyEditor();
    }
});

onBeforeUnmount(() => {
    destroyEditor();
});
</script>
