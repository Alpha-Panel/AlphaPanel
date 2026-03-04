import './plugins/echo';

import { createApp, h } from 'vue';
import type { DefineComponent } from 'vue';
import { createInertiaApp, Link } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { ZiggyVue } from 'ziggy-js';

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

        app.mount(el);
    },
    progress: {
        color: '#465fff',
    },
});
