<template>
    <form @submit.prevent="submit" class="space-y-4">
        <p class="text-sm text-gray-500">
            {{ t('Connect this panel to an existing Zimbra server. The panel manages mailboxes via Zimbra SOAP Admin API without touching the Zimbra UI.') }}
        </p>

        <div class="flex items-center gap-2">
            <input id="zimbra-enabled" v-model="form.enabled" type="checkbox" />
            <label for="zimbra-enabled" class="text-sm">{{ t('Enable Zimbra integration') }}</label>
        </div>

        <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
            <div class="md:col-span-2">
                <label class="form-label">{{ t('Admin SOAP URL') }}</label>
                <input v-model="form.admin_url" type="url" class="form-input" placeholder="https://zimbra.example.com:7071/service/admin/soap" />
            </div>
            <div>
                <label class="form-label">{{ t('Admin user') }}</label>
                <input v-model="form.admin_user" type="text" class="form-input" placeholder="admin@example.com" autocomplete="off" />
            </div>
            <div>
                <label class="form-label">{{ t('Admin password') }}</label>
                <input
                    v-model="form.admin_password"
                    type="password"
                    class="form-input"
                    :placeholder="zimbra.admin_password_set ? t('Leave blank to keep current') : ''"
                    autocomplete="new-password"
                />
            </div>
            <div>
                <label class="form-label">{{ t('Default MX host') }}</label>
                <input v-model="form.default_mx_host" type="text" class="form-input" placeholder="zimbra.example.com" />
            </div>
            <div>
                <label class="form-label">{{ t('Default MX priority') }}</label>
                <input v-model.number="form.default_mx_priority" type="number" min="0" max="65535" class="form-input" />
            </div>
            <div class="md:col-span-2">
                <label class="form-label">{{ t('Default SPF include (optional)') }}</label>
                <input v-model="form.default_spf_include" type="text" class="form-input" placeholder="include:_spf.zimbra.example.com" />
            </div>
            <div class="flex items-center gap-2">
                <input id="verify-tls" v-model="form.verify_tls" type="checkbox" />
                <label for="verify-tls" class="text-sm">{{ t('Verify TLS certificate') }}</label>
            </div>
            <div>
                <label class="form-label">{{ t('Timeout (seconds)') }}</label>
                <input v-model.number="form.timeout_seconds" type="number" min="1" max="120" class="form-input" />
            </div>
        </div>

        <div class="flex flex-wrap items-center justify-between gap-3">
            <button
                type="button"
                class="rounded-lg border border-gray-300 px-4 py-2 text-sm hover:bg-gray-50 dark:border-gray-700 dark:hover:bg-gray-900"
                @click="test"
                :disabled="testing"
            >
                {{ testing ? t('Testing…') : t('Test connection') }}
            </button>
            <div class="flex items-center gap-3">
                <p v-if="testResult" class="text-sm" :class="testResult.ok ? 'text-green-600' : 'text-red-600'">
                    {{ testResult.message }}
                </p>
                <button
                    type="submit"
                    class="rounded-lg bg-brand-500 px-4 py-2 text-sm font-medium text-white hover:bg-brand-600"
                    :disabled="form.processing"
                >
                    {{ t('Save') }}
                </button>
            </div>
        </div>

        <p v-if="zimbra.last_health_check_at" class="text-xs text-gray-400">
            {{ t('Last health check') }}: {{ zimbra.last_health_check_at }} — {{ zimbra.last_health_status }}
        </p>
    </form>
</template>

<script setup>
import { ref } from 'vue';
import axios from 'axios';
import { useForm } from '@inertiajs/vue3';
import { useI18n } from '@/Composables/useI18n';

const { t } = useI18n();
const props = defineProps({
    zimbra: { type: Object, required: true },
});

const form = useForm({
    enabled: !!props.zimbra.enabled,
    admin_url: props.zimbra.admin_url ?? '',
    admin_user: props.zimbra.admin_user ?? '',
    admin_password: '',
    default_mx_host: props.zimbra.default_mx_host ?? '',
    default_mx_priority: props.zimbra.default_mx_priority ?? 10,
    default_spf_include: props.zimbra.default_spf_include ?? '',
    verify_tls: props.zimbra.verify_tls ?? true,
    timeout_seconds: props.zimbra.timeout_seconds ?? 15,
});

const testing = ref(false);
const testResult = ref(null);

function submit() {
    form.put(route('mail.settings.zimbra.update'));
}

async function test() {
    testing.value = true;
    testResult.value = null;
    try {
        const resp = await axios.post(route('mail.settings.zimbra.test'));
        testResult.value = { ok: true, message: t('Authentication successful.') };
    } catch (e) {
        const data = e?.response?.data;
        testResult.value = {
            ok: false,
            message: data?.message || t('Connection failed.'),
        };
    } finally {
        testing.value = false;
    }
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
