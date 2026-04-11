import './plugins/echo';
import '../css/tooltip.css';

import { createApp, h } from 'vue';
import type { DefineComponent } from 'vue';
import { createInertiaApp, Link, router } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { ZiggyVue } from 'ziggy-js';
import tooltip from '@/Directives/tooltip';
import { initializeColorBlindMode } from '@/Composables/useColorBlindMode';

initializeColorBlindMode();

function syncLocaleFromStorage(pageProps: Record<string, unknown>): void {
    const stored = localStorage.getItem('locale');
    if (!stored) return;

    const serverLocale = (pageProps.locale as string) ?? 'en';
    if (stored === serverLocale) return;

    router.post('/locale', { locale: stored }, {
        preserveScroll: true,
        preserveState: false,
        replace: true,
    });
}

createInertiaApp({
    title: (title) => (title ? `${title} - AlphaPanel` : 'AlphaPanel'),
    resolve: (name) =>
        resolvePageComponent(
            `./Pages/${name}.vue`,
            import.meta.glob<DefineComponent>('./Pages/**/*.vue'),
        ),
    setup({ el, App, props, plugin }) {
        const app = createApp({ render: () => h(App, props) });

        app.use(plugin);
        app.use(ZiggyVue);
        app.component('Link', Link);
        app.directive('tooltip', tooltip);

        app.mount(el);

        syncLocaleFromStorage(props.initialPage.props);
    },
    progress: {
        color: '#465fff',
    },
});
