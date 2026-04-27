<template>
    <div
        v-if="impersonation && impersonation.active"
        class="fixed inset-x-0 top-0 z-[100] flex items-center justify-between gap-3 border-b-2 border-amber-700 bg-amber-500 px-4 py-2.5 text-amber-950 shadow-lg dark:border-amber-700 dark:bg-amber-600 dark:text-amber-50"
        role="alert"
    >
        <div class="flex items-center gap-3">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 shrink-0" viewBox="0 0 20 20" fill="currentColor">
                <path fill-rule="evenodd" d="M8.485 2.495c.673-1.167 2.357-1.167 3.03 0l6.28 10.875c.673 1.167-.17 2.625-1.516 2.625H3.72c-1.347 0-2.189-1.458-1.515-2.625L8.485 2.495ZM10 6a.75.75 0 0 1 .75.75v3.5a.75.75 0 0 1-1.5 0v-3.5A.75.75 0 0 1 10 6Zm0 9a1 1 0 1 0 0-2 1 1 0 0 0 0 2Z" clip-rule="evenodd" />
            </svg>
            <p class="text-sm font-medium">
                {{ message }}
            </p>
        </div>
        <button
            type="button"
            @click="stop"
            :disabled="stopping"
            class="inline-flex items-center gap-1.5 rounded-md bg-amber-950/15 px-3 py-1.5 text-xs font-semibold uppercase tracking-wide hover:bg-amber-950/25 disabled:opacity-50 dark:bg-amber-50/15 dark:hover:bg-amber-50/25"
        >
            {{ stopping ? t('Stopping...') : t('Stop impersonation') }}
        </button>
    </div>
</template>

<script setup lang="ts">
import { computed, ref } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import { useI18n } from '@/Composables/useI18n';

interface ImpersonationProp {
    active: boolean;
    impersonator: { id: number; name: string; username: string };
    target: { id: number; name: string; username: string };
    started_at: string | null;
    stop_url: string;
}

const { t } = useI18n();
const page = usePage();
const stopping = ref(false);

const impersonation = computed<ImpersonationProp | null>(
    () => ((page.props as Record<string, unknown>).impersonation as ImpersonationProp | null) ?? null
);

const message = computed(() => {
    if (!impersonation.value) return '';
    return t('You are viewing the panel as :target. Click here to return to your :impersonator account.', {
        target: impersonation.value.target.name,
        impersonator: impersonation.value.impersonator.name,
    });
});

const stop = () => {
    if (!impersonation.value || stopping.value) return;
    stopping.value = true;
    router.post(impersonation.value.stop_url, {}, {
        onFinish: () => { stopping.value = false; },
    });
};
</script>
