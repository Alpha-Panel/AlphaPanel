import './plugins/echo';
import '../css/tooltip.css';

import { createApp, h } from 'vue';
import type { DefineComponent } from 'vue';
import { createInertiaApp, Link, router } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { ZiggyVue } from 'ziggy-js';
import tooltip from '@/Directives/tooltip';
import { initializeColorBlindMode } from '@/Composables/useColorBlindMode';
import { installAppTabs } from '@/Composables/useAppTabs';
import AppTabsHost from '@/Components/Layout/AppTabsHost.vue';

initializeColorBlindMode();

/**
 * iOS (PWA) pans the window to reveal a focused input above the keyboard and often leaves it
 * there, so the header ends up under the status bar. The panel shell scrolls inside <main> and
 * never the window: once focus leaves the text field (keyboard closed), snap the window back.
 * Pages without the shell (auth) keep normal window scrolling.
 */
document.addEventListener('focusout', () => {
    setTimeout(() => {
        const active = document.activeElement as HTMLElement | null;
        const typing = !!active && (active.isContentEditable || /^(INPUT|TEXTAREA|SELECT)$/.test(active.tagName));
        if (!typing && window.scrollY !== 0 && document.querySelector('[scroll-region]')) window.scrollTo(0, 0);
    }, 100);
});

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
        const app = createApp({ render: () => [h(App, props), h(AppTabsHost)] });

        app.use(plugin);
        app.use(ZiggyVue);
        app.component('Link', Link);
        app.directive('tooltip', tooltip);

        app.mount(el);
        installAppTabs();

        syncLocaleFromStorage(props.initialPage.props);
    },
    progress: {
        color: '#7c8cff',
    },
});
