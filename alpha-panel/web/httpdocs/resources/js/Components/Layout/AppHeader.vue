<template>
    <header
        class="sticky top-0 flex w-full bg-white border-gray-200 z-99999 dark:border-gray-800 dark:bg-gray-900 lg:border-b"
    >
        <div class="flex flex-col justify-between grow lg:flex-row lg:items-center lg:px-6">
            <div
                class="flex items-center justify-between w-full gap-2 px-3 py-3 border-b border-gray-200 dark:border-gray-800 sm:gap-4 lg:w-auto lg:shrink-0 lg:justify-normal lg:border-b-0 lg:px-0 lg:py-4"
            >
                <button
                    @click="handleToggle"
                    class="flex items-center justify-center w-10 h-10 text-gray-500 border-gray-200 rounded-lg z-99999 dark:border-gray-800 dark:text-gray-400 lg:h-11 lg:w-11 lg:border"
                    :class="[
                        isMobileOpen
                            ? 'lg:bg-transparent dark:lg:bg-transparent bg-gray-100 dark:bg-gray-800'
                            : '',
                    ]"
                >
                    <!-- Close icon -->
                    <svg
                        v-if="isMobileOpen"
                        class="fill-current"
                        width="24"
                        height="24"
                        viewBox="0 0 24 24"
                        fill="none"
                        xmlns="http://www.w3.org/2000/svg"
                    >
                        <path
                            fill-rule="evenodd"
                            clip-rule="evenodd"
                            d="M6.21967 7.28131C5.92678 6.98841 5.92678 6.51354 6.21967 6.22065C6.51256 5.92775 6.98744 5.92775 7.28033 6.22065L11.999 10.9393L16.7176 6.22078C17.0105 5.92789 17.4854 5.92788 17.7782 6.22078C18.0711 6.51367 18.0711 6.98855 17.7782 7.28144L13.0597 12L17.7782 16.7186C18.0711 17.0115 18.0711 17.4863 17.7782 17.7792C17.4854 18.0721 17.0105 18.0721 16.7176 17.7792L11.999 13.0607L7.28033 17.7794C6.98744 18.0722 6.51256 18.0722 6.21967 17.7794C5.92678 17.4865 5.92678 17.0116 6.21967 16.7187L10.9384 12L6.21967 7.28131Z"
                            fill=""
                        />
                    </svg>
                    <!-- Hamburger icon -->
                    <svg
                        v-else
                        width="16"
                        height="12"
                        viewBox="0 0 16 12"
                        fill="none"
                        xmlns="http://www.w3.org/2000/svg"
                    >
                        <path
                            fill-rule="evenodd"
                            clip-rule="evenodd"
                            d="M0.583252 1C0.583252 0.585788 0.919038 0.25 1.33325 0.25H14.6666C15.0808 0.25 15.4166 0.585786 15.4166 1C15.4166 1.41421 15.0808 1.75 14.6666 1.75L1.33325 1.75C0.919038 1.75 0.583252 1.41422 0.583252 1ZM0.583252 11C0.583252 10.5858 0.919038 10.25 1.33325 10.25L14.6666 10.25C15.0808 10.25 15.4166 10.5858 15.4166 11C15.4166 11.4142 15.0808 11.75 14.6666 11.75L1.33325 11.75C0.919038 11.75 0.583252 11.4142 0.583252 11ZM1.33325 5.25C0.919038 5.25 0.583252 5.58579 0.583252 6C0.583252 6.41421 0.919038 6.75 1.33325 6.75L7.99992 6.75C8.41413 6.75 8.74992 6.41421 8.74992 6C8.74992 5.58579 8.41413 5.25 7.99992 5.25L1.33325 5.25Z"
                            fill="currentColor"
                        />
                    </svg>
                </button>

                <Link :href="route('home')" class="flex items-center gap-2 lg:hidden">
                    <img :src="logoUrl" :alt="appName" class="h-8 w-8 shrink-0 object-contain" />
                    <span class="text-xl font-bold text-brand-500">{{ appName }}</span>
                </Link>

                <!-- Notification icon visible on mobile, beside three-dots -->
                <div class="lg:hidden">
                    <NotificationMenu />
                </div>

                <button
                    @click="toggleApplicationMenu"
                    class="flex items-center justify-center w-10 h-10 text-gray-700 rounded-lg z-99999 hover:bg-gray-100 dark:text-gray-400 dark:hover:bg-gray-800 lg:hidden"
                >
                    <svg
                        width="24"
                        height="24"
                        viewBox="0 0 24 24"
                        fill="none"
                        xmlns="http://www.w3.org/2000/svg"
                    >
                        <path
                            fill-rule="evenodd"
                            clip-rule="evenodd"
                            d="M5.99902 10.4951C6.82745 10.4951 7.49902 11.1667 7.49902 11.9951V12.0051C7.49902 12.8335 6.82745 13.5051 5.99902 13.5051C5.1706 13.5051 4.49902 12.8335 4.49902 12.0051V11.9951C4.49902 11.1667 5.1706 10.4951 5.99902 10.4951ZM17.999 10.4951C18.8275 10.4951 19.499 11.1667 19.499 11.9951V12.0051C19.499 12.8335 18.8275 13.5051 17.999 13.5051C17.1706 13.5051 16.499 12.8335 16.499 12.0051V11.9951C16.499 11.1667 17.1706 10.4951 17.999 10.4951ZM13.499 11.9951C13.499 11.1667 12.8275 10.4951 11.999 10.4951C11.1706 10.4951 10.499 11.1667 10.499 11.9951V12.0051C10.499 12.8335 11.1706 13.5051 11.999 13.5051C12.8275 13.5051 13.499 12.8335 13.499 12.0051V11.9951Z"
                            fill="currentColor"
                        />
                    </svg>
                </button>
            </div>

            <div
                :class="[
                    isApplicationMenuOpen ? 'flex' : 'hidden',
                    isRtl ? 'lg:mr-4' : 'lg:ml-4',
                ]"
                class="w-full items-center gap-4 px-5 py-4 shadow-theme-md lg:flex lg:flex-1 lg:justify-between lg:px-0 lg:shadow-none"
            >
                <div
                    ref="searchContainerRef"
                    :class="isRtl ? 'lg:ml-auto' : 'lg:mr-auto'"
                    class="relative w-full lg:max-w-none lg:flex-1"
                >
                    <div class="relative">
                        <span
                            :class="isRtl ? 'right-3' : 'left-3'"
                            class="pointer-events-none absolute top-1/2 -translate-y-1/2 text-gray-400 dark:text-gray-500"
                        >
                            <i class="fa-solid fa-magnifying-glass text-xs"></i>
                        </span>
                        <input
                            v-model="searchQuery"
                            type="text"
                            :placeholder="t('Search domains...')"
                            class="h-10 w-full rounded-lg border border-gray-200 bg-white pl-9 pr-9 text-sm text-gray-700 shadow-theme-xs focus:border-brand-300 focus:outline-hidden focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-200"
                            @focus="handleSearchFocus"
                            @keydown.esc="closeSearchDropdown"
                        />
                        <button
                            v-if="searchQuery !== ''"
                            type="button"
                            :class="isRtl ? 'left-2' : 'right-2'"
                            class="absolute top-1/2 inline-flex h-6 w-6 -translate-y-1/2 items-center justify-center rounded text-gray-400 hover:bg-gray-100 hover:text-gray-600 dark:text-gray-500 dark:hover:bg-gray-700 dark:hover:text-gray-300"
                            @click="clearSearch"
                        >
                            <i class="fa-solid fa-xmark text-xs"></i>
                        </button>
                    </div>

                    <div
                        v-if="showSearchDropdown"
                        class="absolute left-0 right-0 top-[calc(100%+0.4rem)] z-[1300000] overflow-hidden rounded-xl border border-gray-200 bg-white shadow-theme-xl dark:border-gray-700 dark:bg-gray-900"
                    >
                        <div v-if="searchLoading" class="px-4 py-3 text-sm text-gray-500 dark:text-gray-400">
                            {{ t('Searching...') }}
                        </div>
                        <div v-else-if="searchResults.length === 0" class="px-4 py-3 text-sm text-gray-500 dark:text-gray-400">
                            {{ t('No domains found.') }}
                        </div>
                        <div v-else class="max-h-80 overflow-y-auto py-1">
                            <Link
                                v-for="result in searchResults"
                                :key="result.id"
                                :href="result.show_url"
                                class="flex items-start gap-3 px-3 py-2.5 hover:bg-gray-100 dark:hover:bg-gray-800"
                                @click="handleResultClick"
                            >
                                <span class="mt-0.5 inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-md bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-300">
                                    <i :class="result.is_subdomain ? 'fa-solid fa-sitemap' : 'fa-solid fa-globe'" class="text-xs"></i>
                                </span>
                                <span class="min-w-0 flex-1">
                                    <span class="block truncate text-sm font-medium text-gray-800 dark:text-gray-100">{{ result.fqdn }}</span>
                                    <span class="block text-xs text-gray-500 dark:text-gray-400">
                                        {{ result.is_subdomain ? t('Subdomain') : t('Main Domain') }} · {{ result.type_label ?? result.type }}
                                    </span>
                                </span>
                            </Link>
                        </div>
                    </div>
                </div>

                <div
                    :class="isRtl ? 'lg:mr-4' : 'lg:ml-4'"
                    class="flex w-full items-center justify-between gap-4 lg:w-auto lg:justify-end"
                >
                    <div class="flex items-center gap-2 2xsm:gap-3">
                        <div ref="localeMenuRef" class="relative">
                            <button
                                type="button"
                                class="inline-flex h-10 items-center gap-2 rounded-lg border border-gray-200 bg-white px-2.5 text-xs font-medium text-gray-700 shadow-theme-xs transition-colors hover:bg-gray-50 focus:border-brand-300 focus:outline-hidden focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-200 dark:hover:bg-gray-700"
                                @click="toggleLocaleMenu"
                            >
                                <span class="inline-flex h-5 w-5 items-center justify-center overflow-hidden rounded-full border border-gray-200 bg-gray-100 dark:border-gray-700 dark:bg-gray-700">
                                    <img
                                        v-if="!hasFlagError(locale)"
                                        :src="localeFlagPath(locale)"
                                        :alt="localeCode(locale)"
                                        class="h-full w-full object-cover"
                                        @error="markFlagError(locale)"
                                    />
                                    <i v-else class="fa-solid fa-globe text-[10px] text-gray-500 dark:text-gray-300"></i>
                                </span>
                                <span class="hidden w-7 text-center text-xs font-semibold tracking-wide uppercase lg:inline">{{ localeCode(locale) }}</span>
                                <i
                                    class="hidden fa-solid fa-chevron-down text-[10px] text-gray-500 transition-transform dark:text-gray-400 lg:inline"
                                    :class="isLocaleMenuOpen ? 'rotate-180' : ''"
                                ></i>
                            </button>

                            <div
                                v-if="isLocaleMenuOpen"
                                :class="isRtl ? 'left-0' : 'right-0'"
                                class="absolute top-[calc(100%+0.45rem)] z-1300000 w-56 overflow-hidden rounded-xl border border-gray-200 bg-white shadow-theme-xl dark:border-gray-700 dark:bg-gray-900"
                            >
                                <div class="border-b border-gray-100 p-2 dark:border-gray-800">
                                    <div class="relative">
                                        <span
                                            :class="isRtl ? 'right-2' : 'left-2'"
                                            class="pointer-events-none absolute top-1/2 -translate-y-1/2 text-gray-400 dark:text-gray-500"
                                        >
                                            <i class="fa-solid fa-magnifying-glass text-[10px]"></i>
                                        </span>
                                        <input
                                            v-model="localeSearchQuery"
                                            type="text"
                                            :placeholder="t('Search language...')"
                                            :class="isRtl ? 'pr-7 pl-2' : 'pl-7 pr-2'"
                                            class="h-8 w-full rounded-md border border-gray-200 bg-white text-xs text-gray-700 focus:border-brand-300 focus:outline-hidden focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-200"
                                        />
                                    </div>
                                </div>

                                <div class="max-h-64 overflow-y-auto py-1">
                                    <button
                                        v-for="lang in filteredLocales"
                                        :key="lang"
                                        type="button"
                                        :class="isRtl ? 'text-right' : 'text-left'"
                                        class="flex w-full items-center justify-between gap-2 px-3 py-2 text-xs text-gray-700 hover:bg-gray-100 dark:text-gray-200 dark:hover:bg-gray-800"
                                        @click="selectLocale(lang)"
                                    >
                                        <span class="inline-flex min-w-0 items-center gap-2">
                                            <span class="inline-flex h-4 w-4 items-center justify-center overflow-hidden rounded-full border border-gray-200 bg-gray-100 dark:border-gray-700 dark:bg-gray-700">
                                                <img
                                                    v-if="!hasFlagError(lang)"
                                                    :src="localeFlagPath(lang)"
                                                    :alt="localeCode(lang)"
                                                    class="h-full w-full object-cover"
                                                    @error="markFlagError(lang)"
                                                />
                                                <i v-else class="fa-solid fa-globe text-[9px] text-gray-500 dark:text-gray-300"></i>
                                            </span>
                                            <span class="truncate font-medium">{{ localeName(lang) }}</span>
                                        </span>
                                        <i v-if="locale === lang" class="fa-solid fa-check text-[10px] text-brand-600 dark:text-brand-400"></i>
                                    </button>

                                    <div v-if="filteredLocales.length === 0" class="px-3 py-2 text-xs text-gray-500 dark:text-gray-400">
                                        {{ t('No language found.') }}
                                    </div>
                                </div>
                            </div>
                        </div>
                        <ThemeToggler />
                        <!-- NotificationMenu hidden on mobile (shown next to three-dots instead) -->
                        <div class="hidden lg:block">
                            <NotificationMenu />
                        </div>
                    </div>
                    <UserMenu />
                </div>
            </div>
        </div>
    </header>
</template>

<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import axios from 'axios';
import { useSidebar } from '@/Composables/useSidebar';
import { useI18n } from '@/Composables/useI18n';
import ThemeToggler from '@/Components/Common/ThemeToggler.vue';
import NotificationMenu from './header/NotificationMenu.vue';
import UserMenu from './header/UserMenu.vue';
import type { SharedProps } from '@/types/inertia';

interface DomainSearchResult {
    id: number;
    fqdn: string;
    type: string;
    status: string;
    type_label?: string;
    status_label?: string;
    is_subdomain: boolean;
    show_url: string;
}

const { toggleSidebar, toggleMobileSidebar, isMobileOpen } = useSidebar();
const { t } = useI18n();
const page = usePage<SharedProps>();
const appName = computed(() => page.props.app?.name ?? 'AlphaPanel');
const logoUrl = computed(() => page.props.app?.logo_url ?? '/img/AlphaPanel-dark.svg');
const locale = computed(() => page.props.locale ?? 'en');
const textDirection = computed(() => page.props.text_direction ?? 'ltr');
const rtlLocales = computed(() => page.props.rtl_locales ?? ['tr-gokturk']);
const isRtl = computed(() => textDirection.value === 'rtl');
const availableLocales = computed(() => page.props.available_locales ?? ['tr', 'tr-gokturk', 'gokturk-latin', 'az', 'en', 'de', 'es', 'fr', 'ru']);
const localeMenuRef = ref<HTMLElement | null>(null);
const isLocaleMenuOpen = ref(false);
const localeSearchQuery = ref('');
const failedFlagCodes = ref<Set<string>>(new Set());

const localeCodeOverrides: Record<string, string> = {
    'tr-gokturk': 'GKT',
    'gokturk-latin': 'GKL',
};

const localeFlagLocaleOverrides: Record<string, string> = {
    'tr-gokturk': 'tr-gokturk',
    'gokturk-latin': 'tr-gokturk',
};

const localeFlagOverrides: Record<string, string> = {
    en: 'gb',
    ja: 'jp',
    ko: 'kr',
    zh: 'cn',
    ar: 'sa',
};

const normalizeLocale = (code: string): string => {
    return code.toLowerCase().replace('_', '-');
};

const localeDirection = (code: string): 'ltr' | 'rtl' => {
    const normalizedLocale = normalizeLocale(code);
    const normalizedRtlLocales = rtlLocales.value.map((rtlLocale) => normalizeLocale(rtlLocale));

    return normalizedRtlLocales.includes(normalizedLocale) ? 'rtl' : 'ltr';
};

const localeCode = (code: string): string => {
    const normalized = normalizeLocale(code);

    if (localeCodeOverrides[normalized]) {
        return localeCodeOverrides[normalized];
    }

    return normalized.split('-')[0].toUpperCase();
};

const nativeLocaleNamesByLocale: Record<string, string> = {
    'tr-gokturk': '𐰍𐰇𐰚𐱃𐰇𐰺𐰚𐰲𐰁',
    'gokturk-latin': 'Göktürkçe (Latin)',
};

const nativeLocaleNamesByLanguage: Record<string, string> = {
    tr: 'Türkçe',
    az: 'Azərbaycanca',
    en: 'English',
    de: 'Deutsch',
    es: 'Español',
    fr: 'Français',
    ru: 'Русский',
};

const localeName = (code: string): string => {
    const normalized = normalizeLocale(code);
    const [language] = normalized.split('-');

    if (nativeLocaleNamesByLocale[normalized]) {
        return nativeLocaleNamesByLocale[normalized];
    }

    if (nativeLocaleNamesByLanguage[language]) {
        return nativeLocaleNamesByLanguage[language];
    }

    return localeCode(code);
};

const localeFlagCode = (code: string): string => {
    const normalized = normalizeLocale(code);
    const [language, region] = normalized.split('-');

    if (localeFlagLocaleOverrides[normalized]) {
        return localeFlagLocaleOverrides[normalized];
    }

    if (region) {
        return region;
    }

    return localeFlagOverrides[language] ?? language;
};

const localeFlagPath = (code: string): string => {
    return `/flags/${localeFlagCode(code)}.webp`;
};

const hasFlagError = (code: string): boolean => {
    return failedFlagCodes.value.has(localeFlagCode(code));
};

const markFlagError = (code: string): void => {
    const flagCode = localeFlagCode(code);

    if (failedFlagCodes.value.has(flagCode)) {
        return;
    }

    failedFlagCodes.value = new Set([...failedFlagCodes.value, flagCode]);
};

const filteredLocales = computed(() => {
    const query = localeSearchQuery.value.trim().toLowerCase();

    if (query === '') {
        return availableLocales.value;
    }

    return availableLocales.value.filter((lang) => {
        return lang.toLowerCase().includes(query)
            || localeCode(lang).toLowerCase().includes(query)
            || localeName(lang).toLowerCase().includes(query);
    });
});

const toggleLocaleMenu = (): void => {
    isLocaleMenuOpen.value = !isLocaleMenuOpen.value;

    if (!isLocaleMenuOpen.value) {
        localeSearchQuery.value = '';
    }
};

const handleToggle = () => {
    if (window.innerWidth >= 1024) {
        toggleSidebar();
    } else {
        toggleMobileSidebar();
    }
};

const isApplicationMenuOpen = ref(false);

const toggleApplicationMenu = () => {
    isApplicationMenuOpen.value = !isApplicationMenuOpen.value;
};

const searchContainerRef = ref<HTMLElement | null>(null);
const searchQuery = ref('');
const searchLoading = ref(false);
const searchResults = ref<DomainSearchResult[]>([]);
const showSearchDropdown = ref(false);
let searchDebounceTimer: ReturnType<typeof setTimeout> | null = null;
let searchRequestCounter = 0;

const closeSearchDropdown = (): void => {
    showSearchDropdown.value = false;
};

const clearSearch = (): void => {
    searchQuery.value = '';
    searchResults.value = [];
    searchLoading.value = false;
    showSearchDropdown.value = false;
};

const fetchSearchResults = async (): Promise<void> => {
    const query = searchQuery.value.trim();
    if (query === '') {
        searchResults.value = [];
        showSearchDropdown.value = false;
        searchLoading.value = false;

        return;
    }

    const currentRequest = ++searchRequestCounter;
    searchLoading.value = true;

    try {
        const response = await axios.get(route('domains.search'), {
            params: {
                q: query,
                limit: 12,
            },
        });

        if (currentRequest !== searchRequestCounter) {
            return;
        }

        const results = Array.isArray(response.data?.data) ? response.data.data as DomainSearchResult[] : [];
        searchResults.value = results;
        showSearchDropdown.value = true;
    } catch {
        if (currentRequest !== searchRequestCounter) {
            return;
        }

        searchResults.value = [];
        showSearchDropdown.value = true;
    } finally {
        if (currentRequest === searchRequestCounter) {
            searchLoading.value = false;
        }
    }
};

const handleSearchFocus = (): void => {
    if (searchQuery.value.trim() === '') {
        return;
    }

    showSearchDropdown.value = true;

    if (searchResults.value.length === 0) {
        void fetchSearchResults();
    }
};

const handleResultClick = (): void => {
    showSearchDropdown.value = false;
    isApplicationMenuOpen.value = false;
};

const applyLocaleToDocument = (lang: string): void => {
    if (typeof document === 'undefined') {
        return;
    }

    const normalizedLocale = normalizeLocale(lang);
    const direction = localeDirection(normalizedLocale);

    document.documentElement.lang = normalizedLocale;
    document.documentElement.dir = direction;
    document.documentElement.setAttribute('data-text-direction', direction);
};

const switchLocale = (lang: string): void => {
    if (locale.value === lang) {
        return;
    }

    isLocaleMenuOpen.value = false;
    localeSearchQuery.value = '';
    localStorage.setItem('locale', lang);
    router.post(route('locale.set'), { locale: lang }, {
        preserveScroll: true,
        onSuccess: () => {
            applyLocaleToDocument(lang);
        },
    });
};

const selectLocale = (lang: string): void => {
    switchLocale(lang);
};

const handleOutsideClick = (event: MouseEvent): void => {
    const target = event.target as Node | null;

    if (!target) {
        return;
    }

    if (searchContainerRef.value && !searchContainerRef.value.contains(target)) {
        showSearchDropdown.value = false;
    }

    if (localeMenuRef.value && !localeMenuRef.value.contains(target)) {
        isLocaleMenuOpen.value = false;
        localeSearchQuery.value = '';
    }
};

watch(searchQuery, () => {
    if (searchDebounceTimer) {
        clearTimeout(searchDebounceTimer);
    }

    if (searchQuery.value.trim() === '') {
        searchResults.value = [];
        searchLoading.value = false;
        showSearchDropdown.value = false;

        return;
    }

    searchDebounceTimer = setTimeout(() => {
        void fetchSearchResults();
    }, 250);
});

watch(locale, (currentLocale) => {
    applyLocaleToDocument(currentLocale);
}, { immediate: true });

onMounted(() => {
    document.addEventListener('click', handleOutsideClick);
});

onBeforeUnmount(() => {
    document.removeEventListener('click', handleOutsideClick);

    if (searchDebounceTimer) {
        clearTimeout(searchDebounceTimer);
        searchDebounceTimer = null;
    }
});
</script>
