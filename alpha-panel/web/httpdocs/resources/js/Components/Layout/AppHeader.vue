<template>
    <header class="flex h-11.5 shrink-0 items-center gap-2.5 border-b border-line bg-surface px-3">
        <button
            type="button"
            title="Menu"
            class="inline-flex size-8 items-center justify-center rounded-md text-fg-2 hover:bg-surface-2"
            @click="handleToggle"
        >
            <i :class="['bx', isMobileOpen ? 'bx-x' : 'bx-menu', 'text-xl']"></i>
        </button>

        <!-- domain search -->
        <div ref="searchRef" class="relative min-w-0 flex-1">
            <i class="bx bx-search pointer-events-none absolute start-2.5 top-1/2 -translate-y-1/2 text-[15px] text-fg-3"></i>
            <input
                ref="searchInput"
                v-model="searchQuery"
                type="text"
                :placeholder="t('Search domains...')"
                class="h-8 w-full rounded-[7px] border border-line bg-bg ps-7.5 pe-15 text-sm text-fg placeholder:text-fg-3"
                @focus="onSearchFocus"
                @keydown.esc="showResults = false"
            />
            <button
                v-if="searchQuery"
                type="button"
                class="absolute end-12 top-1/2 inline-flex size-5 -translate-y-1/2 items-center justify-center rounded text-fg-3 hover:bg-surface-2 hover:text-fg"
                @click="clearSearch"
            >
                <i class="bx bx-x text-sm"></i>
            </button>
            <kbd class="pointer-events-none absolute end-2 top-1/2 -translate-y-1/2 rounded border border-line px-1.25 py-px font-mono text-[10px] text-fg-3">Ctrl K</kbd>

            <div v-if="showResults" class="ap-pop inset-x-0 top-[calc(100%+6px)] p-0">
                <div v-if="searchLoading" class="px-3 py-2.5 text-sm text-fg-3">{{ t('Searching...') }}</div>
                <div v-else-if="!searchResults.length" class="px-3 py-2.5 text-sm text-fg-3">{{ t('No domains found.') }}</div>
                <div v-else class="max-h-80 overflow-y-auto p-1">
                    <Link
                        v-for="r in searchResults"
                        :key="r.id"
                        :href="r.show_url"
                        class="flex items-center gap-2.5 rounded-md px-2.5 py-1.5 hover:bg-surface-2 hover:no-underline"
                        @click="showResults = false"
                    >
                        <i :class="['bx', r.is_subdomain ? 'bx-sitemap' : 'bx-globe', 'text-base text-fg-3']"></i>
                        <span class="min-w-0 flex-1">
                            <span class="block truncate font-mono text-sm font-medium text-fg">{{ r.fqdn }}</span>
                            <span class="block text-[11px] text-fg-3">
                                {{ r.is_subdomain ? t('Subdomain') : t('Main Domain') }} · {{ r.type_label ?? r.type }}
                            </span>
                        </span>
                    </Link>
                </div>
            </div>
        </div>

        <!-- locale -->
        <div ref="localeRef" class="relative">
            <button
                type="button"
                class="inline-flex h-8 items-center gap-1.75 rounded-[7px] border border-line bg-surface px-2.5 text-xs font-semibold text-fg-2 hover:text-fg"
                @click="localeOpen = !localeOpen"
            >
                <img
                    v-if="!flagFailed.has(flagCode(locale))"
                    :src="flagPath(locale)"
                    :alt="localeName(locale)"
                    class="h-3.5 w-5 shrink-0 rounded-[3px] object-cover ring-1 ring-line"
                    @error="markFlagFailed(locale)"
                />
                <i v-else class="bx bx-globe text-[15px]"></i>
                <span class="tracking-[.04em] uppercase max-sm:hidden">{{ localeCode(locale) }}</span>
                <i class="bx bx-chevron-down text-[13px] max-sm:hidden"></i>
            </button>
            <div v-if="localeOpen" class="ap-pop end-0 top-[calc(100%+6px)] w-57.5">
                <input v-model="localeQuery" type="text" :placeholder="t('Search language...')" class="ap-input-sm mb-1 w-full" />
                <div class="max-h-64 overflow-y-auto">
                    <button
                        v-for="lang in filteredLocales"
                        :key="lang"
                        type="button"
                        :class="[
                            'flex h-7.5 w-full items-center gap-2.5 rounded-md px-2 text-sm',
                            locale === lang ? 'bg-accent-soft text-accent' : 'text-fg-2 hover:bg-surface-2',
                        ]"
                        @click="switchLocale(lang)"
                    >
                        <img
                            v-if="!flagFailed.has(flagCode(lang))"
                            :src="flagPath(lang)"
                            alt=""
                            loading="lazy"
                            class="h-3.5 w-5 shrink-0 rounded-[3px] object-cover ring-1 ring-line"
                            @error="markFlagFailed(lang)"
                        />
                        <i v-else class="bx bx-globe w-5 shrink-0 text-center text-[15px] text-fg-3"></i>
                        <span class="w-8 shrink-0 text-start font-mono text-2xs text-fg-3 uppercase">{{ localeCode(lang) }}</span>
                        <span class="flex-1 truncate text-start">{{ localeName(lang) }}</span>
                        <i v-if="locale === lang" class="bx bx-check text-base"></i>
                    </button>
                    <div v-if="!filteredLocales.length" class="px-2 py-2 text-xs text-fg-3">{{ t('No language found.') }}</div>
                </div>
            </div>
        </div>

        <ThemeMenu />
        <NotificationMenu />

        <!-- user -->
        <div ref="userRef" class="relative">
            <button
                type="button"
                class="inline-flex h-8 items-center gap-2 rounded-[7px] border border-line bg-surface ps-1 pe-2 text-fg-2"
                @click="userOpen = !userOpen"
            >
                <img v-if="user?.avatar_url" :src="user.avatar_url" :alt="user.name" class="size-6 rounded-full object-cover" />
                <span v-else class="inline-flex size-6 items-center justify-center rounded-full bg-accent text-[10px] font-semibold text-white">{{ initials }}</span>
                <i class="bx bx-chevron-down text-[13px]"></i>
            </button>
            <UserMenuPanel v-if="userOpen" class="end-0 top-[calc(100%+6px)] w-55" @close="userOpen = false" />
        </div>
    </header>
</template>

<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import axios from 'axios';
import { useSidebar } from '@/Composables/useSidebar';
import { useI18n } from '@/Composables/useI18n';
import ThemeMenu from '@/Components/Common/ThemeMenu.vue';
import NotificationMenu from './header/NotificationMenu.vue';
import UserMenuPanel from './header/UserMenuPanel.vue';
import type { SharedProps } from '@/types/inertia';

interface DomainSearchResult {
    id: number;
    fqdn: string;
    type: string;
    type_label?: string;
    is_subdomain: boolean;
    show_url: string;
}

const { toggleSidebar, toggleMobileSidebar, isMobileOpen } = useSidebar();
const { t } = useI18n();
const page = usePage<SharedProps>();

const user = computed(() => page.props.auth?.user as { name: string; avatar_url?: string } | undefined);
const initials = computed(() =>
    (user.value?.name ?? '?').split(/\s+/).map((p) => p[0]).slice(0, 2).join('').toUpperCase(),
);

const handleToggle = () => (window.innerWidth >= 1024 ? toggleSidebar() : toggleMobileSidebar());

/* ---------- locale (same behaviour as previous header) ---------- */
const locale = computed(() => page.props.locale ?? 'en');
const rtlLocales = computed(() => page.props.rtl_locales ?? ['tr-gokturk']);
const availableLocales = computed(
    () => page.props.available_locales ?? ['tr', 'tr-gokturk', 'gokturk-latin', 'az', 'en', 'de', 'es', 'fr', 'ru'],
);
const normalize = (c: string) => c.toLowerCase().replace('_', '-');
const codeOverrides: Record<string, string> = { 'tr-gokturk': 'GKT', 'gokturk-latin': 'GKL' };
const names: Record<string, string> = {
    'tr-gokturk': '𐰍𐰇𐰚𐱃𐰇𐰺𐰚𐰲𐰁',
    'gokturk-latin': 'Göktürkçe (Latin)',
    tr: 'Türkçe',
    az: 'Azərbaycanca',
    en: 'English',
    de: 'Deutsch',
    es: 'Español',
    fr: 'Français',
    ru: 'Русский',
};
const localeCode = (c: string) => codeOverrides[normalize(c)] ?? normalize(c).split('-')[0].toUpperCase();
const localeName = (c: string) => names[normalize(c)] ?? names[normalize(c).split('-')[0]] ?? localeCode(c);

// Flags live in public/flags/{code}.webp; a missing file falls back to the globe icon.
const flagLocaleOverrides: Record<string, string> = { 'tr-gokturk': 'tr-gokturk', 'gokturk-latin': 'tr-gokturk' };
const flagLanguageOverrides: Record<string, string> = { en: 'gb', ja: 'jp', ko: 'kr', zh: 'cn', ar: 'sa' };
const flagCode = (c: string): string => {
    const n = normalize(c);
    const [language, region] = n.split('-');
    return flagLocaleOverrides[n] ?? region ?? flagLanguageOverrides[language] ?? language;
};
const flagPath = (c: string) => `/flags/${flagCode(c)}.webp`;
const flagFailed = ref(new Set<string>());
const markFlagFailed = (c: string) => (flagFailed.value = new Set(flagFailed.value).add(flagCode(c)));

const localeOpen = ref(false);
const localeQuery = ref('');
const localeRef = ref<HTMLElement | null>(null);
const filteredLocales = computed(() => {
    const q = localeQuery.value.trim().toLowerCase();
    if (!q) return availableLocales.value;
    return availableLocales.value.filter(
        (l) => l.includes(q) || localeCode(l).toLowerCase().includes(q) || localeName(l).toLowerCase().includes(q),
    );
});

const applyLocaleToDocument = (lang: string) => {
    const n = normalize(lang);
    const dir = rtlLocales.value.map(normalize).includes(n) ? 'rtl' : 'ltr';
    document.documentElement.lang = n;
    document.documentElement.dir = dir;
    document.documentElement.setAttribute('data-text-direction', dir);
};

function switchLocale(lang: string): void {
    localeOpen.value = false;
    localeQuery.value = '';
    if (lang === locale.value) return;
    localStorage.setItem('locale', lang);
    router.post(route('locale.set'), { locale: lang }, { preserveScroll: true, onSuccess: () => applyLocaleToDocument(lang) });
}
watch(locale, applyLocaleToDocument, { immediate: true });

/* ---------- domain search ---------- */
const searchRef = ref<HTMLElement | null>(null);
const searchInput = ref<HTMLInputElement | null>(null);
const searchQuery = ref('');
const searchLoading = ref(false);
const searchResults = ref<DomainSearchResult[]>([]);
const showResults = ref(false);
let debounce: ReturnType<typeof setTimeout> | null = null;
let reqId = 0;

async function fetchResults(): Promise<void> {
    const q = searchQuery.value.trim();
    if (!q) return;
    const id = ++reqId;
    searchLoading.value = true;
    try {
        const res = await axios.get(route('domains.search'), { params: { q, limit: 12 } });
        if (id !== reqId) return;
        searchResults.value = Array.isArray(res.data?.data) ? res.data.data : [];
    } catch {
        if (id === reqId) searchResults.value = [];
    } finally {
        if (id === reqId) {
            searchLoading.value = false;
            showResults.value = true;
        }
    }
}
function onSearchFocus(): void {
    if (!searchQuery.value.trim()) return;
    showResults.value = true;
    if (!searchResults.value.length) void fetchResults();
}
function clearSearch(): void {
    searchQuery.value = '';
    searchResults.value = [];
    showResults.value = false;
}
watch(searchQuery, (q) => {
    if (debounce) clearTimeout(debounce);
    if (!q.trim()) {
        searchResults.value = [];
        showResults.value = false;
        return;
    }
    debounce = setTimeout(() => void fetchResults(), 250);
});

/* ---------- menus ---------- */
const userOpen = ref(false);
const userRef = ref<HTMLElement | null>(null);

function onDocClick(e: MouseEvent): void {
    const n = e.target as Node;
    if (searchRef.value && !searchRef.value.contains(n)) showResults.value = false;
    if (localeRef.value && !localeRef.value.contains(n)) localeOpen.value = false;
    if (userRef.value && !userRef.value.contains(n)) userOpen.value = false;
}
function onKey(e: KeyboardEvent): void {
    if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') {
        e.preventDefault();
        searchInput.value?.focus();
    }
    if (e.key === 'Escape') {
        localeOpen.value = false;
        userOpen.value = false;
        showResults.value = false;
    }
}
onMounted(() => {
    document.addEventListener('click', onDocClick);
    document.addEventListener('keydown', onKey);
});
onBeforeUnmount(() => {
    document.removeEventListener('click', onDocClick);
    document.removeEventListener('keydown', onKey);
    if (debounce) clearTimeout(debounce);
});
</script>
