<template>
    <div
        v-if="modelValue"
        class="fixed inset-0 z-1200000 flex items-center justify-center bg-black/60 p-4 backdrop-blur-sm"
    >
        <div class="flex max-h-[92vh] w-full max-w-lg flex-col rounded-xl border border-gray-700 bg-[#171717] text-white/90 shadow-2xl">
            <div class="flex items-center justify-between border-b border-white/10 px-5 py-4">
                <div>
                    <h5 class="text-base text-white">{{ t('Change Password') }}</h5>
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
                    <div class="flex justify-end">
                        <button
                            type="button"
                            class="inline-flex items-center gap-1.5 rounded-lg border border-brand-500/40 px-3 py-1.5 text-xs font-medium text-brand-300 hover:bg-brand-500/10"
                            @click="generatePassword"
                        >
                            <i class="bx bx-shuffle"></i>
                            {{ t('Generate Password') }}
                        </button>
                    </div>

                    <FormField :label="t('New Password')" :error="form.errors.password" required>
                        <div class="relative">
                            <input
                                v-model="form.password"
                                :type="showPassword ? 'text' : 'password'"
                                :placeholder="t('Min 8 characters')"
                                class="form-input pr-10"
                            />
                            <button
                                type="button"
                                class="absolute inset-y-0 right-2 flex items-center text-white/50 hover:text-white"
                                @click="showPassword = !showPassword"
                            >
                                <i :class="showPassword ? 'bx bx-show' : 'bx bx-hide'" class="text-base"></i>
                            </button>
                        </div>
                    </FormField>

                    <FormField :label="t('Confirm Password')" :error="form.errors.password_confirmation" required>
                        <input
                            v-model="form.password_confirmation"
                            :type="showPassword ? 'text' : 'password'"
                            :placeholder="t('Repeat password')"
                            class="form-input"
                        />
                    </FormField>
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
                        class="inline-flex items-center gap-2 rounded-lg bg-warning-500 px-4 py-2.5 text-sm font-medium text-white hover:bg-warning-600 disabled:opacity-60"
                    >
                        {{ form.processing ? t('Processing...') : t('Update Password') }}
                    </button>
                </div>
            </form>
        </div>
    </div>
</template>

<script setup lang="ts">
import { ref, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import FormField from '@/Components/UI/FormField.vue';
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
const showPassword = ref(false);

const form = useForm({
    password: '',
    password_confirmation: '',
});

watch(() => props.modelValue, (isOpen) => {
    if (isOpen) {
        form.reset();
        form.clearErrors();
        showPassword.value = false;
    }
});

const generatePassword = (): void => {
    const chars = 'abcdefghijkmnopqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789!@#$%&*';
    const array = new Uint32Array(20);
    crypto.getRandomValues(array);

    let password = '';
    for (let i = 0; i < 20; i += 1) {
        password += chars[array[i] % chars.length];
    }

    form.password = password;
    form.password_confirmation = password;
    showPassword.value = true;
};

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

    form.put(route('domains.mail.mailboxes.password', [props.domainId, props.mailbox.id]), {
        preserveScroll: true,
        onSuccess: () => {
            emit('update:modelValue', false);
            emit('updated');
            form.reset();
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
