/// <reference types="vite/client" />

import type { route as ziggyRoute } from 'ziggy-js';
import type { router as inertiaRouter } from '@inertiajs/vue3';

declare module 'vue' {
    interface ComponentCustomProperties {
        route: typeof ziggyRoute;
    }
}

declare global {
    const route: typeof ziggyRoute;
    const router: typeof inertiaRouter;
}

declare module '*.vue' {
    import type { DefineComponent } from 'vue';
    const component: DefineComponent<object, object, unknown>;
    export default component;
}

interface ImportMetaEnv {
    readonly VITE_REVERB_APP_KEY: string;
    readonly VITE_REVERB_HOST: string;
    readonly VITE_REVERB_PORT: string;
    readonly VITE_REVERB_SCHEME: string;
}

interface ImportMeta {
    readonly env: ImportMetaEnv;
}
