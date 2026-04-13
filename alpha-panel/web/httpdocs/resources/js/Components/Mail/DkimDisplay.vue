<template>
    <div class="space-y-3">
        <div>
            <label class="mb-1 block text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                {{ t('DNS Record Name') }}
            </label>
            <div class="flex items-center gap-2">
                <code class="flex-1 rounded-lg border border-gray-200 bg-gray-50 px-3 py-2 text-sm text-gray-800 dark:border-gray-700 dark:bg-gray-800 dark:text-white/90">
                    dkim._domainkey.{{ domain }}
                </code>
                <button
                    type="button"
                    class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-lg border border-gray-300 text-gray-500 hover:bg-gray-100 hover:text-gray-700 dark:border-gray-700 dark:text-gray-400 dark:hover:bg-gray-800 dark:hover:text-gray-200"
                    :title="t('Copy')"
                    @click="copyText(`dkim._domainkey.${domain}`)"
                >
                    <i :class="copiedName ? 'bx bx-check' : 'bx bx-copy'" class="text-base"></i>
                </button>
            </div>
        </div>

        <div>
            <label class="mb-1 block text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                {{ t('Record Value') }}
            </label>
            <div class="flex items-start gap-2">
                <pre class="flex-1 overflow-x-auto whitespace-pre-wrap break-all rounded-lg border border-gray-200 bg-gray-50 px-3 py-2 text-xs text-gray-800 dark:border-gray-700 dark:bg-gray-800 dark:text-white/90">{{ record }}</pre>
                <button
                    type="button"
                    class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-lg border border-gray-300 text-gray-500 hover:bg-gray-100 hover:text-gray-700 dark:border-gray-700 dark:text-gray-400 dark:hover:bg-gray-800 dark:hover:text-gray-200"
                    :title="t('Copy')"
                    @click="copyText(record)"
                >
                    <i :class="copiedValue ? 'bx bx-check' : 'bx bx-copy'" class="text-base"></i>
                </button>
            </div>
        </div>

        <p v-if="!record" class="text-sm text-gray-500 dark:text-gray-400">
            {{ t('No DKIM record available for this domain.') }}
        </p>
    </div>
</template>

<script setup lang="ts">
import { ref } from 'vue';
import { useI18n } from '@/Composables/useI18n';

withDefaults(defineProps<{
    record: string;
    domain: string;
}>(), {
    record: '',
    domain: '',
});

const { t } = useI18n();
const copiedName = ref(false);
const copiedValue = ref(false);

const copyText = async (text: string): Promise<void> => {
    if (!text.trim() || !navigator?.clipboard?.writeText) {
        return;
    }

    try {
        await navigator.clipboard.writeText(text);
        const isName = text.startsWith('dkim._domainkey.');

        if (isName) {
            copiedName.value = true;
            setTimeout(() => { copiedName.value = false; }, 1500);
        } else {
            copiedValue.value = true;
            setTimeout(() => { copiedValue.value = false; }, 1500);
        }
    } catch {
        // Clipboard API not available
    }
};
</script>
