<template>
    <form @submit.prevent="submit" class="space-y-4">
        <p class="text-sm text-gray-500">
            {{ t('Configure an external SMTP relay (Mailgun, SES, Postmark) for outbound mail. Leave blank to send directly from this server.') }}
        </p>

        <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
            <div>
                <label class="form-label">{{ t('Relay host') }}</label>
                <input v-model="form.host" type="text" class="form-input" placeholder="smtp.example.com" />
            </div>
            <div>
                <label class="form-label">{{ t('Relay port') }}</label>
                <input v-model.number="form.port" type="number" min="1" max="65535" class="form-input" />
            </div>
            <div>
                <label class="form-label">{{ t('Username') }}</label>
                <input v-model="form.username" type="text" class="form-input" autocomplete="off" />
            </div>
            <div>
                <label class="form-label">{{ t('Password') }}</label>
                <input
                    v-model="form.password"
                    type="password"
                    class="form-input"
                    :placeholder="relay.password_set ? t('Leave blank to keep current') : ''"
                    autocomplete="new-password"
                />
            </div>
        </div>

        <div class="flex justify-end">
            <button
                type="submit"
                class="rounded-lg bg-brand-500 px-4 py-2 text-sm font-medium text-white hover:bg-brand-600"
                :disabled="form.processing"
            >
                {{ t('Save') }}
            </button>
        </div>
    </form>
</template>

<script setup>
import { useForm } from '@inertiajs/vue3';
import { useI18n } from '@/Composables/useI18n';

const { t } = useI18n();
const props = defineProps({
    relay: { type: Object, required: true },
});

const form = useForm({
    host: props.relay.host ?? '',
    port: props.relay.port ?? 587,
    username: props.relay.username ?? '',
    password: '',
});

function submit() {
    form.put(route('mail.settings.relay.update'));
}
</script>

<style scoped>
@reference "../../../../css/app.css";

.form-input {
    @apply h-auto min-h-11 w-full rounded-lg border border-gray-300 bg-transparent px-4 py-2.5 text-sm text-gray-800 shadow-theme-xs placeholder:text-gray-400 focus:border-brand-300 focus:outline-hidden focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90 dark:placeholder:text-white/30 dark:focus:border-brand-800;
}
.form-label {
    @apply mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400;
}
</style>
