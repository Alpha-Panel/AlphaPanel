import { ref, watch } from 'vue';
import { usePage } from '@inertiajs/vue3';
import type { SharedProps } from '@/types/inertia';

interface Toast {
    id: number;
    type: 'success' | 'error' | 'warning' | 'info';
    message: string;
}

let nextId = 0;

const toasts = ref<Toast[]>([]);

export function useToast() {
    const addToast = (type: Toast['type'], message: string) => {
        if (toasts.value.some((t) => t.message === message)) {
            return;
        }
        const id = nextId++;
        toasts.value.push({ id, type, message });
        setTimeout(() => {
            removeToast(id);
        }, 5000);
    };

    const removeToast = (id: number) => {
        toasts.value = toasts.value.filter((t) => t.id !== id);
    };

    const watchFlash = () => {
        const page = usePage<SharedProps>();
        watch(
            () => page.props.flash,
            (flash) => {
                if (flash?.success) addToast('success', flash.success);
                if (flash?.error) addToast('error', flash.error);
                if (flash?.warning) addToast('warning', flash.warning);
                if (flash?.info) addToast('info', flash.info);
            },
            { immediate: true },
        );
    };

    return {
        toasts,
        addToast,
        removeToast,
        watchFlash,
    };
}
