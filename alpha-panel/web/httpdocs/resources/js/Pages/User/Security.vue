<template>
    <Head :title="t('Security')" />
    <ThemeProvider>
        <SidebarProvider>
            <AdminLayout>
                <PageBreadcrumb :pageTitle="t('Security Settings')" />
                <Toast />

                <div class="space-y-6">
                    <!-- Two-Factor Authentication -->
                    <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03] md:p-6">
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
                    <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03] md:p-6">
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
                            <div v-for="key in keys" :key="key.id" class="flex items-center justify-between py-3">
                                <div>
                                    <p class="text-sm font-medium text-gray-800 dark:text-white/90">{{ key.name || t('Unnamed Key') }}</p>
                                    <p class="text-xs text-gray-500">{{ t('Added') }} {{ formatDateTime(key.created_at) }}</p>
                                </div>
                                <div class="flex items-center gap-2">
                                    <button @click="renameKey(key)" class="text-sm text-brand-500 hover:text-brand-600">{{ t('Rename') }}</button>
                                    <button @click="deleteKey(key)" class="text-sm text-error-500 hover:text-error-600">{{ t('Delete') }}</button>
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
import { ref, onMounted } from 'vue';
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

const registerWebAuthn = async () => {
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

        const name = prompt(t('Give this key a name:')) || t('Security Key');

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
    const newName = prompt(t('Enter a new name:'), key.name);
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
    if (!confirm(t('Delete key ":name"?', { name: key.name || t('Unnamed') }))) return;
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
