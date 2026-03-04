import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';
import type { SharedProps } from '@/types/inertia';

type Replacements = Record<string, string | number>;

export function useI18n() {
    const page = usePage<SharedProps>();
    const locale = computed(() => page.props.locale ?? 'en');
    const translations = computed(() => page.props.translations ?? {});

    const t = (key: string, replacements: Replacements = {}): string => {
        let translated = translations.value[key] ?? key;

        Object.entries(replacements).forEach(([name, value]) => {
            translated = translated.replaceAll(`:${name}`, String(value));
        });

        return translated;
    };

    return {
        t,
        locale,
    };
}

