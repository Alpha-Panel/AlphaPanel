import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';
import type { SharedProps } from '@/types/inertia';

export function useCan() {
    const page = usePage<SharedProps>();

    const isAdmin = computed(() => Boolean(page.props.auth?.user?.is_admin));

    const permissions = computed(() => page.props.auth?.permissions ?? []);

    const roles = computed(() => page.props.auth?.roles ?? []);

    const can = (permission: string): boolean => {
        if (isAdmin.value) {
            return true;
        }

        return permissions.value.includes(permission);
    };

    const canAny = (...perms: string[]): boolean => {
        if (isAdmin.value) {
            return true;
        }

        return perms.some((p) => permissions.value.includes(p));
    };

    const canAll = (...perms: string[]): boolean => {
        if (isAdmin.value) {
            return true;
        }

        return perms.every((p) => permissions.value.includes(p));
    };

    const hasRole = (role: string): boolean => {
        return roles.value.includes(role);
    };

    return { can, canAny, canAll, hasRole, isAdmin, permissions, roles };
}
