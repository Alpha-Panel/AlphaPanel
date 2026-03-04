interface SweetAlertFireResult {
    isConfirmed: boolean;
    value?: unknown;
}

export interface SweetAlertLike {
    fire: (options: Record<string, unknown>) => Promise<SweetAlertFireResult>;
}

declare global {
    interface Window {
        Swal?: SweetAlertLike;
    }
}

const SWEETALERT_SCRIPT_ID = 'alphapanel-sweetalert2-script';
const SWEETALERT_STYLE_ID = 'alphapanel-sweetalert2-style';
const SWEETALERT_SCRIPT_SRC = '/themes/default/app-assets/vendors/js/extensions/sweetalert2.all.min.js';
const SWEETALERT_STYLE_HREF = '/themes/default/app-assets/vendors/css/extensions/sweetalert2.min.css.tmp';

let sweetAlertLoaderPromise: Promise<SweetAlertLike | null> | null = null;

const getGlobalSweetAlert = (): SweetAlertLike | null => {
    return window.Swal ?? null;
};

const ensureSweetAlertStyle = (): void => {
    if (document.getElementById(SWEETALERT_STYLE_ID) !== null) {
        return;
    }

    const style = document.createElement('link');
    style.id = SWEETALERT_STYLE_ID;
    style.rel = 'stylesheet';
    style.href = SWEETALERT_STYLE_HREF;
    document.head.appendChild(style);
};

export const loadSweetAlert = async (): Promise<SweetAlertLike | null> => {
    if (typeof window === 'undefined') {
        return null;
    }

    const existingSweetAlert = getGlobalSweetAlert();
    if (existingSweetAlert !== null) {
        return existingSweetAlert;
    }

    if (sweetAlertLoaderPromise !== null) {
        return sweetAlertLoaderPromise;
    }

    sweetAlertLoaderPromise = new Promise((resolve) => {
        ensureSweetAlertStyle();

        const existingScript = document.getElementById(SWEETALERT_SCRIPT_ID) as HTMLScriptElement | null;
        if (existingScript !== null) {
            const availableSweetAlert = getGlobalSweetAlert();
            if (availableSweetAlert !== null) {
                resolve(availableSweetAlert);

                return;
            }

            existingScript.addEventListener('load', () => resolve(getGlobalSweetAlert()), { once: true });
            existingScript.addEventListener('error', () => resolve(null), { once: true });

            return;
        }

        const script = document.createElement('script');
        script.id = SWEETALERT_SCRIPT_ID;
        script.src = SWEETALERT_SCRIPT_SRC;
        script.async = true;
        script.onload = () => resolve(getGlobalSweetAlert());
        script.onerror = () => resolve(null);
        document.head.appendChild(script);
    });

    return sweetAlertLoaderPromise;
};
