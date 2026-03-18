import { computed, onMounted, ref } from 'vue';
import { usePage } from '@inertiajs/vue3';
import axios from 'axios';
import type { SharedProps } from '@/types/inertia';

function urlBase64ToUint8Array(base64String: string): Uint8Array {
    const padding = '='.repeat((4 - (base64String.length % 4)) % 4);
    const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
    const rawData = window.atob(base64);
    return Uint8Array.from(rawData, (char) => char.charCodeAt(0));
}

function isBraveBrowser(): boolean {
    return 'brave' in navigator;
}

export function usePushSubscription() {
    const page = usePage<SharedProps>();
    const isSubscribed = ref(false);
    const isSupported = ref(false);
    const loading = ref(false);
    const error = ref<string | null>(null);

    const vapidPublicKey = computed(() => page.props.vapid_public_key ?? '');

    async function checkStatus(): Promise<void> {
        if (!('serviceWorker' in navigator) || !('PushManager' in window)) {
            isSupported.value = false;
            return;
        }
        isSupported.value = true;

        try {
            const registration = await navigator.serviceWorker.getRegistration('/service-worker.js');
            if (!registration) {
                isSubscribed.value = false;
                return;
            }

            const subscription = await registration.pushManager.getSubscription();
            isSubscribed.value = subscription !== null;
        } catch {
            isSubscribed.value = false;
        }
    }

    async function cleanupStaleSubscriptions(): Promise<void> {
        try {
            const registrations = await navigator.serviceWorker.getRegistrations();
            for (const registration of registrations) {
                const existing = await registration.pushManager.getSubscription();
                if (existing) {
                    await existing.unsubscribe();
                    console.info('[Push] Cleaned up stale subscription from:', registration.scope);
                }
                await registration.unregister();
                console.info('[Push] Unregistered old service worker:', registration.scope);
            }
        } catch (e) {
            console.warn('[Push] Cleanup warning (non-fatal):', e);
        }
    }

    async function subscribe(): Promise<void> {
        if (!vapidPublicKey.value) {
            error.value = 'VAPID public key is missing.';
            return;
        }

        loading.value = true;
        error.value = null;
        try {
            // Step 1: Request notification permission
            const permission = await Notification.requestPermission();
            if (permission !== 'granted') {
                error.value = 'notification_denied';
                return;
            }

            // Step 2: Clean up any stale service workers and subscriptions
            // This prevents "applicationServerKey mismatch" errors after VAPID key rotation
            await cleanupStaleSubscriptions();

            // Step 3: Register a fresh service worker
            let registration: ServiceWorkerRegistration;
            try {
                registration = await navigator.serviceWorker.register('/service-worker.js');
            } catch (swError: any) {
                error.value = 'sw_register_failed';
                console.error('[Push] Service worker registration failed:', swError);
                return;
            }

            // Step 4: Wait for the service worker to become active
            const activeRegistration = await navigator.serviceWorker.ready;

            // Step 5: Subscribe to push service with current VAPID key
            let subscription: PushSubscription;
            try {
                subscription = await activeRegistration.pushManager.subscribe({
                    userVisibleOnly: true,
                    applicationServerKey: urlBase64ToUint8Array(vapidPublicKey.value),
                });
            } catch (pushError: any) {
                console.error('[Push] pushManager.subscribe failed:', pushError);
                console.error('[Push] VAPID key length:', vapidPublicKey.value.length);
                console.error('[Push] Permission state:', Notification.permission);
                console.error('[Push] SW state:', activeRegistration.active?.state);

                if (isBraveBrowser()) {
                    error.value = 'brave_push_blocked';
                } else {
                    error.value = 'push_service_error';
                }
                return;
            }

            // Step 6: Send subscription to backend
            const json = subscription.toJSON();
            await axios.post(route('user.push-subscription.store'), {
                endpoint: subscription.endpoint,
                keys: {
                    p256dh: json.keys?.p256dh ?? '',
                    auth: json.keys?.auth ?? '',
                },
                content_encoding:
                    (PushManager.supportedContentEncodings ?? ['aesgcm'])[0],
            });

            isSubscribed.value = true;
        } catch (err: any) {
            error.value = 'unknown_error';
            console.error('[Push] Unexpected error during subscribe:', err);
        } finally {
            loading.value = false;
        }
    }

    async function unsubscribe(): Promise<void> {
        loading.value = true;
        error.value = null;
        try {
            const registration = await navigator.serviceWorker.getRegistration('/service-worker.js');
            if (!registration) return;

            const subscription = await registration.pushManager.getSubscription();
            if (!subscription) return;

            await axios.delete(route('user.push-subscription.destroy'), {
                data: { endpoint: subscription.endpoint },
            });

            await subscription.unsubscribe();
            isSubscribed.value = false;
        } finally {
            loading.value = false;
        }
    }

    async function toggle(): Promise<void> {
        if (isSubscribed.value) {
            await unsubscribe();
        } else {
            await subscribe();
        }
    }

    onMounted(() => checkStatus());

    return { isSubscribed, isSupported, loading, error, subscribe, unsubscribe, toggle };
}
