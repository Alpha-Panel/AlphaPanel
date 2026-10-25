import { createSSRApp, h } from 'vue';
import type { DefineComponent } from 'vue';
import { renderToString } from 'vue/server-renderer';
import { createInertiaApp, Link } from '@inertiajs/vue3';
import createServer from '@inertiajs/vue3/server';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { route, ZiggyVue } from 'ziggy-js';
import tooltip from '@/Directives/tooltip';

// Entry for `vite build --ssr`, served by `php artisan inertia:start-ssr` (supervisor: inertia-ssr).
// Browser-only setup from app.ts (Echo, color-blind mode, locale sync) is left out on purpose.
// A page that touches window/document while rendering makes this render fail; Laravel then
// falls back to normal client-side rendering for that request, so nothing breaks for users.
createServer((page) =>
    createInertiaApp({
        page,
        render: renderToString,
        title: (title) => (title ? `${title} - AlphaPanel` : 'AlphaPanel'),
        resolve: (name) =>
            resolvePageComponent(
                `./Pages/${name}.vue`,
                import.meta.glob<DefineComponent>('./Pages/**/*.vue'),
            ),
        setup({ App, props, plugin }) {
            // Pages call the global route() that @routes defines in the browser. Ziggy reads
            // globalThis.Ziggy when no config is passed, so expose the shared route list there.
            const ziggy = page.props.ziggy as Record<string, unknown> | undefined;
            (globalThis as Record<string, unknown>).Ziggy = ziggy
                ? { ...ziggy, location: new URL(String(ziggy.location)) }
                : undefined;
            (globalThis as Record<string, unknown>).route = route;

            return createSSRApp({ render: () => h(App, props) })
                .use(plugin)
                .use(ZiggyVue)
                .component('Link', Link)
                .directive('tooltip', tooltip);
        },
    }),
);
