<template>
    <div
        v-if="modelValue"
        class="fixed inset-0 z-1200000 flex items-center justify-center bg-black/60 p-4 backdrop-blur-sm"
    >
        <div class="flex max-h-[92vh] w-full max-w-lg flex-col rounded-xl border border-gray-700 bg-[#171717] text-white/90 shadow-2xl">
            <div class="flex items-center justify-between border-b border-white/10 px-5 py-4">
                <div>
                    <h5 class="text-base text-white">{{ t('Edit Mailbox') }}</h5>
                    <p v-if="mailbox" class="text-xs text-white/60">{{ mailbox.email }}</p>
                </div>
                <button
                    type="button"
                    class="text-2xl leading-none text-white/50 hover:text-white"
                    :disabled="form.processing"
                    @click="close"
                >
                    &times;
                </button>
            </div>

            <form class="min-h-0 flex-1 overflow-y-auto p-5" @submit.prevent="submit">
                <div class="space-y-4">
                    <FormField :label="t('Display Name')" :error="form.errors.display_name">
                        <input v-model="form.display_name" type="text" :placeholder="t('John Doe')" class="form-input" />
                    </FormField>

                    <FormField :label="t('Quota (MB)')" :error="form.errors.quota_mb">
                        <input v-model.number="form.quota_mb" type="number" min="0" :placeholder="t('256')" class="form-input" />
                    </FormField>

                    <div class="flex items-center gap-3">
                        <ToggleSwitch v-model="form.is_active" />
                        <span class="text-sm text-white/80">{{ t('Active') }}</span>
                    </div>
                </div>

                <div class="mt-6 flex items-center justify-end gap-2 border-t border-white/10 pt-4">
                    <button
                        type="button"
                        class="inline-flex items-center gap-2 rounded-lg border border-white/20 px-4 py-2.5 text-sm font-medium text-white/80 hover:bg-white/10"
                        :disabled="form.processing"
                        @click="close"
                    >
                        {{ t('Cancel') }}
                    </button>
                    <button
                        type="submit"
                        :disabled="form.processing"
                        class="inline-flex items-center gap-2 rounded-lg bg-brand-500 px-4 py-2.5 text-sm font-medium text-white hover:bg-brand-600 disabled:opacity-60"
                    >
                        {{ form.processing ? t('Processing...') : t('Save Changes') }}
                    </button>
                </div>
            </form>
        </div>
    </div>
</template>

<script setup lang="ts">
import { watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import FormField from '@/Components/UI/FormField.vue';
import ToggleSwitch from '@/Components/UI/ToggleSwitch.vue';
import { useI18n } from '@/Composables/useI18n';

const props = defineProps<{
    modelValue: boolean;
    domainId: number;
    mailbox: Record<string, any> | null;
}>();

const emit = defineEmits<{
    (e: 'update:modelValue', value: boolean): void;
    (e: 'updated'): void;
}>();

const { t } = useI18n();

const form = useForm({
    display_name: '',
    quota_mb: 256,
    is_active: true,
});

watch(() => props.modelValue, (isOpen) => {
    if (isOpen && props.mailbox) {
        form.defaults({
            display_name: props.mailbox.display_name ?? '',
            quota_mb: props.mailbox.quota_mb ?? 256,
            is_active: props.mailbox.is_active ?? true,
        });
        form.reset();
        form.clearErrors();
    }
});

const close = (): void => {
    if (form.processing) {
        return;
    }

    emit('update:modelValue', false);
};

const submit = (): void => {
    if (!props.mailbox) {
        return;
    }

    form.put(route('domains.mail.mailboxes.update', [props.domainId, props.mailbox.id]), {
        preserveScroll: true,
        onSuccess: () => {
            emit('update:modelValue', false);
            emit('updated');
        },
    });
};
</script>

<style scoped>
@reference "../../../css/app.css";

.form-input {
    @apply h-11 w-full rounded-lg border border-white/20 bg-black/20 px-4 py-2.5 text-sm text-white shadow-theme-xs placeholder:text-white/40 focus:border-brand-400 focus:outline-hidden focus:ring-3 focus:ring-brand-500/20;
}
</style>
