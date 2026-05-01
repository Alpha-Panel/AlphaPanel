<template>
    <Head :title="t('Security')" />
    <ThemeProvider>
        <SidebarProvider>
            <AdminLayout>
                <PageBreadcrumb :pageTitle="t('Security Settings')" />
                <Toast />

                <div class="space-y-6">
                    <!-- Email Address -->
                    <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/3 md:p-6">
                        <h3 class="mb-4 text-lg font-semibold text-gray-800 dark:text-white/90">{{ t('Email Address') }}</h3>
                        <form @submit.prevent="submitEmail" class="space-y-4 max-w-md">
                            <div>
                                <label class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-400">{{ t('New Email') }}</label>
                                <input v-model="emailForm.email" type="email" class="form-input" :placeholder="page.props.auth.user.email" required />
                                <p v-if="emailErrors.email" class="mt-1 text-xs text-error-500">{{ emailErrors.email }}</p>
                            </div>
                            <div>
                                <label class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-400">{{ t('Current Password') }}</label>
                                <input v-model="emailForm.current_password" type="password" class="form-input" :placeholder="t('Enter your password')" required />
                                <p v-if="emailErrors.current_password" class="mt-1 text-xs text-error-500">{{ emailErrors.current_password }}</p>
                            </div>
                            <button type="submit" :disabled="emailSaving" class="rounded-lg bg-brand-500 px-4 py-2.5 text-sm font-medium text-white hover:bg-brand-600 disabled:opacity-50">
                                {{ emailSaving ? t('Saving...') : t('Update Email') }}
                            </button>
                        </form>
                    </div>

                    <!-- Password -->
                    <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/3 md:p-6">
                        <h3 class="mb-4 text-lg font-semibold text-gray-800 dark:text-white/90">{{ t('Change Password') }}</h3>
                        <form @submit.prevent="submitPassword" class="space-y-4 max-w-md">
                            <div>
                                <label class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-400">{{ t('Current Password') }}</label>
                                <input v-model="passwordForm.current_password" type="password" class="form-input" :placeholder="t('Enter your password')" required />
                                <p v-if="passwordErrors.current_password" class="mt-1 text-xs text-error-500">{{ passwordErrors.current_password }}</p>
                            </div>
                            <div>
                                <label class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-400">{{ t('New Password') }}</label>
                                <input v-model="passwordForm.password" type="password" class="form-input" :placeholder="t('Min 8 characters')" required />
                                <p v-if="passwordErrors.password" class="mt-1 text-xs text-error-500">{{ passwordErrors.password }}</p>
                            </div>
                            <div>
                                <label class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-400">{{ t('Confirm Password') }}</label>
                                <input v-model="passwordForm.password_confirmation" type="password" class="form-input" :placeholder="t('Repeat password')" required />
                            </div>
                            <button type="submit" :disabled="passwordSaving" class="rounded-lg bg-brand-500 px-4 py-2.5 text-sm font-medium text-white hover:bg-brand-600 disabled:opacity-50">
                                {{ passwordSaving ? t('Saving...') : t('Update Password') }}
                            </button>
                        </form>
                    </div>

                    <!-- Two-Factor Authentication -->
                    <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/3 md:p-6">
                        <h3 class="mb-4 text-lg font-semibold text-gray-800 dark:text-white/90">{{ t('Two-Factor Authentication') }}</h3>

                        <div v-if="!twoFactorEnabled">
                            <p class="mb-4 text-sm text-gray-600 dark:text-gray-400">
                                {{ t('Add an extra layer of security to your account using two-factor authentication via an authenticator app.') }}
                            </p>
                            <button @click="enableTwoFactor" :disabled="loading" class="rounded-lg bg-brand-500 px-4 py-2.5 text-sm font-medium text-white hover:bg-brand-600 disabled:opacity-50">
                                {{ loading ? t('Enabling...') : t('Enable 2FA') }}
                            </button>
                        </div>

                        <div v-else>
                            <!-- QR Code / Confirm Step -->
                            <div v-if="!twoFactorConfirmed" class="space-y-4">
                                <p class="text-sm text-gray-600 dark:text-gray-400">
                                    {{ t('Scan this QR code with your authenticator app and enter the code below to confirm.') }}
                                </p>
                                <div v-if="qrSvg" class="inline-block rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-700" v-html="qrSvg"></div>
                                <div class="max-w-xs">
                                    <label class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-400">{{ t('Confirmation Code') }}</label>
                                    <div class="flex gap-2">
                                        <input v-model="confirmCode" type="text" inputmode="numeric" maxlength="6" :placeholder="t('000000')" class="form-input" />
                                        <button @click="confirmTwoFactor" :disabled="loading || confirmCode.length < 6" class="shrink-0 rounded-lg bg-brand-500 px-4 py-2.5 text-sm font-medium text-white hover:bg-brand-600 disabled:opacity-50">
                                            {{ t('Confirm') }}
                                        </button>
                                    </div>
                                </div>
                            </div>

                            <!-- Confirmed State -->
                            <div v-else class="space-y-4">
                                <div class="flex items-center gap-2">
                                    <span class="inline-flex h-6 w-6 items-center justify-center rounded-full bg-success-50 text-success-500">
                                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" /></svg>
                                    </span>
                                    <span class="text-sm font-medium text-success-600 dark:text-success-400">{{ t('Two-factor authentication is enabled.') }}</span>
                                </div>
                                <button @click="disableTwoFactor" :disabled="loading" class="rounded-lg border border-error-300 px-4 py-2.5 text-sm font-medium text-error-600 hover:bg-error-50 disabled:opacity-50 dark:border-error-700 dark:text-error-400">
                                    {{ loading ? t('Disabling...') : t('Disable 2FA') }}
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- WebAuthn / Security Keys -->
                    <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/3 md:p-6">
                        <div class="mb-4 flex items-center justify-between">
                            <h3 class="text-lg font-semibold text-gray-800 dark:text-white/90">{{ t('Security Keys (WebAuthn)') }}</h3>
                            <button @click="registerWebAuthn" :disabled="loading" class="rounded-lg bg-brand-500 px-4 py-2.5 text-sm font-medium text-white hover:bg-brand-600 disabled:opacity-50">
                                {{ t('Add Key') }}
                            </button>
                        </div>

                        <div v-if="keys.length === 0" class="py-4 text-center text-sm text-gray-500 dark:text-gray-400">
                            {{ t('No security keys registered yet.') }}
                        </div>

                        <div v-else class="divide-y divide-gray-200 dark:divide-gray-800">
                            <div v-for="key in keys" :key="key.id" class="flex flex-col gap-3 py-3 sm:flex-row sm:items-center sm:justify-between">
                                <div class="flex items-center gap-3">
                                    <span class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-brand-50 text-brand-500 dark:bg-brand-500/10 dark:text-brand-400">
                                        <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 7a3 3 0 013 3m0 0a3 3 0 01-3 3m3-3h6M6 21l3-3m0 0a3 3 0 11-4.243-4.243L9 9.515 14.485 15 9 21z" /></svg>
                                    </span>
                                    <div>
                                        <p class="text-sm font-medium text-gray-800 dark:text-white/90">{{ key.name || t('Unnamed Key') }}</p>
                                        <p class="text-xs text-gray-500">{{ t('Added') }} {{ formatDateTime(key.created_at) }}</p>
                                    </div>
                                </div>
                                <div class="flex items-center gap-2">
                                    <button
                                        @click="renameKey(key)"
                                        type="button"
                                        :title="t('Rename')"
                                        class="inline-flex items-center gap-1.5 rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm font-medium text-gray-700 shadow-theme-xs transition hover:bg-gray-50 hover:text-brand-600 dark:border-gray-700 dark:bg-white/5 dark:text-gray-300 dark:hover:bg-white/10 dark:hover:text-brand-400"
                                    >
                                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" /></svg>
                                        <span>{{ t('Rename') }}</span>
                                    </button>
                                    <button
                                        @click="deleteKey(key)"
                                        type="button"
                                        :title="t('Delete')"
                                        class="inline-flex items-center gap-1.5 rounded-lg border border-error-200 bg-white px-3 py-2 text-sm font-medium text-error-600 shadow-theme-xs transition hover:bg-error-50 dark:border-error-500/40 dark:bg-error-500/5 dark:text-error-400 dark:hover:bg-error-500/10"
                                    >
                                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6M1 7h22M9 7V4a1 1 0 011-1h4a1 1 0 011 1v3" /></svg>
                                        <span>{{ t('Delete') }}</span>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </AdminLayout>
        </SidebarProvider>
    </ThemeProvider>
</template>

<script setup lang="ts">
import { ref, reactive, onMounted } from 'vue';
import { Head, usePage } from '@inertiajs/vue3';
import axios from 'axios';
import ThemeProvider from '@/Components/Layout/ThemeProvider.vue';
import SidebarProvider from '@/Components/Layout/SidebarProvider.vue';
import AdminLayout from '@/Components/Layout/AdminLayout.vue';
import PageBreadcrumb from '@/Components/Common/PageBreadcrumb.vue';
import Toast from '@/Components/UI/Toast.vue';
import { useToast } from '@/Composables/useToast';
import { useI18n } from '@/Composables/useI18n';
import { formatDateTime } from '@/utils/dateTime';
import { loadSweetAlert } from '@/utils/sweetalert';

const props = defineProps<{
    webauthn: Array<Record<string, any>>;
}>();

const { addToast } = useToast();
const { t } = useI18n();
const page = usePage<{ auth: { user: Record<string, any> } }>();
const loading = ref(false);
const qrSvg = ref('');
const confirmCode = ref('');
const keys = ref([...props.webauthn]);

const twoFactorEnabled = ref(!!page.props.auth.user.two_factor_secret);
const twoFactorConfirmed = ref(!!page.props.auth.user.two_factor_confirmed);

const emailForm = reactive({ email: '', current_password: '' });
const emailErrors = reactive<Record<string, string>>({});
const emailSaving = ref(false);

const passwordForm = reactive({ current_password: '', password: '', password_confirmation: '' });
const passwordErrors = reactive<Record<string, string>>({});
const passwordSaving = ref(false);

const submitEmail = async () => {
    emailSaving.value = true;
    Object.keys(emailErrors).forEach((k) => delete emailErrors[k]);
    try {
        await axios.post(route('user.security.update-email'), emailForm);
        emailForm.email = '';
        emailForm.current_password = '';
        addToast('success', t('Email updated successfully.'));
    } catch (e: any) {
        const errors = e.response?.data?.errors ?? {};
        Object.entries(errors).forEach(([k, v]) => { emailErrors[k] = Array.isArray(v) ? v[0] : String(v); });
        if (!Object.keys(errors).length) {
            addToast('error', e.response?.data?.message || t('Failed to update email.'));
        }
    } finally {
        emailSaving.value = false;
    }
};

const submitPassword = async () => {
    passwordSaving.value = true;
    Object.keys(passwordErrors).forEach((k) => delete passwordErrors[k]);
    try {
        await axios.post(route('user.security.update-password'), passwordForm);
        passwordForm.current_password = '';
        passwordForm.password = '';
        passwordForm.password_confirmation = '';
        addToast('success', t('Password updated.'));
    } catch (e: any) {
        const errors = e.response?.data?.errors ?? {};
        Object.entries(errors).forEach(([k, v]) => { passwordErrors[k] = Array.isArray(v) ? v[0] : String(v); });
        if (!Object.keys(errors).length) {
            addToast('error', e.response?.data?.message || t('Failed to update password'));
        }
    } finally {
        passwordSaving.value = false;
    }
};

const enableTwoFactor = async () => {
    loading.value = true;
    try {
        await axios.post(route('two-factor.enable'));
        // Fetch QR code SVG
        const res = await axios.get(route('two-factor.qr-code'));
        qrSvg.value = res.data.svg;
        twoFactorEnabled.value = true;
        twoFactorConfirmed.value = false;
        addToast('success', t('Scan the QR code with your authenticator app.'));
    } catch (e: any) {
        addToast('error', e.response?.data?.message || t('Failed to enable 2FA'));
    } finally {
        loading.value = false;
    }
};

const confirmTwoFactor = async () => {
    loading.value = true;
    try {
        await axios.post(route('user.two-factor.confirm'), { code: confirmCode.value });
        twoFactorConfirmed.value = true;
        confirmCode.value = '';
        qrSvg.value = '';
        addToast('success', t('Two-factor authentication confirmed.'));
    } catch (e: any) {
        addToast('error', e.response?.data?.message || t('Invalid code. Please try again.'));
    } finally {
        loading.value = false;
    }
};

const disableTwoFactor = async () => {
    if (!confirm(t('Are you sure you want to disable two-factor authentication?'))) return;
    loading.value = true;
    try {
        await axios.delete(route('two-factor.disable'));
        twoFactorEnabled.value = false;
        twoFactorConfirmed.value = false;
        qrSvg.value = '';
        addToast('success', t('Two-factor authentication disabled.'));
    } catch (e: any) {
        addToast('error', e.response?.data?.message || t('Failed to disable 2FA'));
    } finally {
        loading.value = false;
    }
};

const promptKeyName = async (titleKey: string, defaultValue = ''): Promise<string | null> => {
    const swal = await loadSweetAlert();
    if (!swal) {
        const fallback = window.prompt(t(titleKey), defaultValue);
        return fallback === null ? null : fallback.trim();
    }

    const result = await swal.fire({
        title: t(titleKey),
        input: 'text',
        inputValue: defaultValue,
        inputLabel: t('Device name'),
        inputPlaceholder: t('e.g. YubiKey 5C, MacBook Touch ID'),
        inputAttributes: { maxlength: '120', autocapitalize: 'off', autocorrect: 'off' },
        showCancelButton: true,
        confirmButtonText: t('Save'),
        cancelButtonText: t('Cancel'),
        confirmButtonColor: '#465fff',
        inputValidator: (value: unknown) => {
            const str = typeof value === 'string' ? value.trim() : '';
            if (str.length === 0) return t('Name is required');
            if (str.length > 120) return t('Name is too long');
            return null;
        },
    });

    if (!result.isConfirmed) return null;
    const value = typeof result.value === 'string' ? result.value.trim() : '';
    return value.length > 0 ? value : null;
};

const registerWebAuthn = async () => {
    const name = await promptKeyName('Give this key a name:', t('Security Key'));
    if (!name) return;

    loading.value = true;
    try {
        const optionsRes = await axios.post(route('webauthn.register.options'));
        const publicKey = optionsRes.data;

        // Convert base64url fields to ArrayBuffer
        publicKey.challenge = base64urlToBuffer(publicKey.challenge);
        publicKey.user.id = base64urlToBuffer(publicKey.user.id);
        if (publicKey.excludeCredentials) {
            publicKey.excludeCredentials = publicKey.excludeCredentials.map((c: any) => ({
                ...c,
                id: base64urlToBuffer(c.id),
            }));
        }

        const credential = await navigator.credentials.create({ publicKey }) as PublicKeyCredential;
        const attestation = credential.response as AuthenticatorAttestationResponse;

        await axios.post(route('webauthn.register'), {
            id: credential.id,
            rawId: bufferToBase64url(credential.rawId),
            type: credential.type,
            response: {
                attestationObject: bufferToBase64url(attestation.attestationObject),
                clientDataJSON: bufferToBase64url(attestation.clientDataJSON),
            },
            name,
        });

        addToast('success', t('Security key registered successfully.'));
        await refreshKeys();
    } catch (e: any) {
        if (e.name !== 'NotAllowedError') {
            addToast('error', e.response?.data?.message || t('Failed to register security key'));
        }
    } finally {
        loading.value = false;
    }
};

const renameKey = async (key: any) => {
    const newName = await promptKeyName('Enter a new name:', key.name || '');
    if (!newName || newName === key.name) return;
    try {
        await axios.post(route('user.security.webauthn.rename'), { id: key.id, name: newName });
        key.name = newName;
        addToast('success', t('Key renamed.'));
    } catch (e: any) {
        addToast('error', e.response?.data?.message || t('Failed to rename key'));
    }
};

const deleteKey = async (key: any) => {
    const label = key.name || t('Unnamed Key');
    const swal = await loadSweetAlert();

    let confirmed = false;
    if (swal) {
        const result = await swal.fire({
            title: t('Delete security key?'),
            text: t('":name" will be removed and can no longer be used to sign in.', { name: label }),
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#f04438',
            confirmButtonText: t('Yes, delete it'),
            cancelButtonText: t('Cancel'),
        });
        confirmed = !!result.isConfirmed;
    } else {
        confirmed = window.confirm(t('Delete key ":name"?', { name: label }));
    }

    if (!confirmed) return;

    try {
        await axios.post(route('user.security.webauthn.delete'), { id: key.id });
        keys.value = keys.value.filter((k) => k.id !== key.id);
        addToast('success', t('Key deleted.'));
    } catch (e: any) {
        addToast('error', e.response?.data?.message || t('Failed to delete key'));
    }
};

const refreshKeys = async () => {
    try {
        const res = await axios.post(route('user.security.webauthn'));
        keys.value = res.data;
    } catch {
        // silent
    }
};

function base64urlToBuffer(base64url: string): ArrayBuffer {
    const base64 = base64url.replace(/-/g, '+').replace(/_/g, '/');
    const pad = base64.length % 4 === 0 ? '' : '='.repeat(4 - (base64.length % 4));
    const binary = atob(base64 + pad);
    const bytes = new Uint8Array(binary.length);
    for (let i = 0; i < binary.length; i++) bytes[i] = binary.charCodeAt(i);
    return bytes.buffer;
}

function bufferToBase64url(buffer: ArrayBuffer): string {
    const bytes = new Uint8Array(buffer);
    let binary = '';
    for (let i = 0; i < bytes.length; i++) binary += String.fromCharCode(bytes[i]);
    return btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
}
</script>

<style scoped>
@reference "../../../css/app.css";

.form-input {
    @apply h-11 w-full rounded-lg border border-gray-300 bg-transparent px-4 py-2.5 text-sm text-gray-800 shadow-theme-xs focus:border-brand-300 focus:outline-hidden focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90;
}
</style>
