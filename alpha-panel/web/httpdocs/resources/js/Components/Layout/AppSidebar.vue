<template>
    <aside
        :class="[
            'z-99999 flex shrink-0 flex-col overflow-hidden border-e border-line bg-surface transition-[width,transform] duration-200 ease-out',
            'fixed start-0 bottom-0 w-58 lg:relative lg:inset-auto lg:translate-x-0',
            impersonationActive ? 'top-12 lg:top-auto' : 'top-0',
            wide ? 'lg:w-58' : 'lg:w-14',
            isMobileOpen ? 'translate-x-0' : isRtl ? 'translate-x-full' : '-translate-x-full',
        ]"
        @mouseenter="!isExpanded && setIsHovered(true)"
        @mouseleave="setIsHovered(false)"
    >
        <!-- brand -->
        <Link :href="route('home')" class="flex h-11.5 shrink-0 items-center gap-2.5 border-b border-line-2 px-3.5 hover:no-underline">
            <img :src="logoUrl" :alt="appName" class="size-6.5 shrink-0" />
            <span v-show="wide" class="truncate text-sm font-bold tracking-tight text-fg">{{ appName }}</span>
        </Link>

        <ServerStats v-if="isAdmin" :wide="wide" />

        <!-- nav -->
        <nav class="no-scrollbar min-h-0 flex-1 overflow-x-hidden overflow-y-auto px-2 py-1.5">
            <div v-for="section in sections" :key="section.title">
                <div v-show="wide" class="ap-eyebrow px-2 pt-2.5 pb-1 tracking-[.1em]">{{ section.title }}</div>
                <div v-show="!wide" class="mx-auto my-2 h-px w-6 bg-line"></div>

                <template v-for="item in section.items" :key="item.key">
                    <!-- group -->
                    <button
                        v-if="item.children"
                        type="button"
                        :title="item.name"
                        :class="itemClass(itemActive(item) && !wide)"
                        @click="toggle(item.key)"
                    >
                        <i :class="[iconOf(item.icon), iconClass]"></i>
                        <span v-show="wide" class="flex min-w-0 flex-1 items-center justify-between">
                            <span class="truncate">{{ item.name }}</span>
                            <i class="bx bx-chevron-down text-sm text-fg-3 transition-transform" :class="{ 'rotate-180': isOpen(item) }"></i>
                        </span>
                    </button>
                    <!-- action -->
                    <button v-else-if="item.action" type="button" :title="item.name" :class="itemClass(false)" @click="item.action()">
                        <i :class="[iconOf(item.icon), iconClass]"></i>
                        <span v-show="wide" class="truncate">{{ item.name }}</span>
                    </button>
                    <!-- external -->
                    <a v-else-if="item.external" :href="item.href" target="_blank" rel="noopener noreferrer" :title="item.name" :class="itemClass(false)">
                        <i :class="[iconOf(item.icon), iconClass]"></i>
                        <span v-show="wide" class="flex min-w-0 flex-1 items-center justify-between">
                            <span class="truncate">{{ item.name }}</span>
                            <i class="bx bx-link-external text-xs text-fg-3"></i>
                        </span>
                    </a>
                    <!-- link -->
                    <Link v-else :href="item.href!" :title="item.name" :class="itemClass(isActive(item.href))">
                        <i :class="[iconOf(item.icon), iconClass]"></i>
                        <span v-show="wide" class="truncate">{{ item.name }}</span>
                    </Link>

                    <!-- children -->
                    <div v-if="item.children && wide && isOpen(item)" class="mt-0.5 mb-1 flex flex-col gap-px">
                        <template v-for="child in item.children" :key="child.name">
                            <template v-if="isNavGroup(child)">
                                <div class="ap-eyebrow px-2.5 pt-1.5 pb-0.5 ps-9">{{ child.name }}</div>
                                <Link v-for="leaf in child.children" :key="leaf.href" :href="leaf.href" :title="leaf.name" :class="subClass(isActive(leaf.href))">
                                    <i :class="[iconOf(leaf.icon), 'w-5 shrink-0 text-center text-sm']"></i>
                                    <span class="truncate">{{ leaf.name }}</span>
                                </Link>
                            </template>
                            <a v-else-if="child.external" :href="child.href" target="_blank" rel="noopener noreferrer" :class="subClass(false)">
                                <i :class="[iconOf(child.icon), 'w-5 shrink-0 text-center text-sm']"></i>
                                <span class="truncate">{{ child.name }}</span>
                            </a>
                            <Link v-else :href="child.href" :title="child.name" :class="subClass(isActive(child.href))">
                                <i :class="[iconOf(child.icon), 'w-5 shrink-0 text-center text-sm']"></i>
                                <span class="truncate">{{ child.name }}</span>
                            </Link>
                        </template>
                    </div>
                </template>
            </div>
        </nav>

        <!-- user -->
        <div ref="userRef" class="relative shrink-0 border-t border-line-2">
            <button type="button" class="flex w-full items-center gap-2.5 px-3 py-2.5 text-start hover:bg-surface-2" @click="userOpen = !userOpen">
                <img v-if="user?.avatar_url" :src="user.avatar_url" :alt="user.name" class="size-7 shrink-0 rounded-full object-cover" />
                <span v-else class="flex size-7 shrink-0 items-center justify-center rounded-full bg-accent text-[11px] font-semibold text-white">{{ initials }}</span>
                <span v-show="wide" class="min-w-0 flex-1">
                    <span class="block truncate text-xs leading-tight font-semibold">{{ user?.name }}</span>
                    <span class="block truncate text-[11px] text-fg-3">{{ roleLabel }}</span>
                </span>
                <i v-show="wide" class="bx bx-chevron-up text-[15px] text-fg-3"></i>
            </button>
            <UserMenuPanel v-if="userOpen" class="start-2 bottom-[calc(100%+6px)] w-55" @close="userOpen = false" />
        </div>
    </aside>
</template>

<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import { useSidebar } from '@/Composables/useSidebar';
import { useCan } from '@/Composables/useCan';
import { useI18n } from '@/Composables/useI18n';
import { isNavGroup, useSidebarMenu, type NavItem } from '@/Composables/useSidebarMenu';
import ServerStats from './ServerStats.vue';
import UserMenuPanel from './header/UserMenuPanel.vue';
import type { SharedProps } from '@/types/inertia';

const page = usePage<SharedProps>();
const { isExpanded, isMobileOpen, isHovered, openSubmenu, setIsHovered } = useSidebar();
const { isAdmin } = useCan();
const { t } = useI18n();
const { sections, isActive, itemActive } = useSidebarMenu();

const wide = computed(() => isExpanded.value || isHovered.value || isMobileOpen.value);
const isRtl = computed(() => page.props.text_direction === 'rtl');
const appName = computed(() => page.props.app?.name ?? 'AlphaPanel');
const logoUrl = computed(() => page.props.app?.logo_url ?? '/img/AlphaPanel-logo.svg');
const user = computed(() => page.props.auth?.user);
const roleLabel = computed(() => page.props.auth?.roles?.[0] ?? t(isAdmin.value ? 'Admin' : 'User'));
const impersonationActive = computed(
    () => !!(page.props as Record<string, unknown> & { impersonation?: { active?: boolean } }).impersonation?.active,
);
const initials = computed(() =>
    (user.value?.name ?? '?').split(/\s+/).map((p) => p[0]).slice(0, 2).join('').toUpperCase(),
);

const iconClass = 'w-5 shrink-0 text-center text-[17px]';
// Boxicons by default ('bx-globe'); a full class list ('fa-brands fa-jenkins', 'lni lni-mysql') is used as-is.
const iconOf = (icon?: string): string => (!icon ? '' : icon.includes(' ') ? icon : `bx ${icon}`);

function itemClass(active: boolean): string[] {
    return [
        'flex h-8 w-full items-center gap-2.5 rounded-md px-2 text-sm font-medium whitespace-nowrap transition-colors hover:no-underline',
        active ? 'bg-accent-soft text-accent' : 'text-fg-2 hover:bg-surface-2 hover:text-fg',
    ];
}
function subClass(active: boolean): string[] {
    return [
        'flex h-7 items-center gap-2.5 rounded-md ps-2.5 pe-2 text-sm whitespace-nowrap transition-colors hover:no-underline',
        active ? 'bg-accent-soft font-medium text-accent' : 'text-fg-2 hover:bg-surface-2 hover:text-fg',
    ];
}

// One explicit open/closed override per group; otherwise a group is open when it contains the active route.
const overrides = ref<Record<string, boolean>>({});
const isOpen = (item: NavItem): boolean => overrides.value[item.key] ?? (openSubmenu.value === item.key || itemActive(item));
function toggle(key: string): void {
    const item = sections.value.flatMap((s) => s.items).find((i) => i.key === key);
    if (!item) return;
    overrides.value = { ...overrides.value, [key]: !isOpen(item) };
}

const userOpen = ref(false);
const userRef = ref<HTMLElement | null>(null);
const onDocClick = (e: MouseEvent) => {
    if (userRef.value && !userRef.value.contains(e.target as Node)) userOpen.value = false;
};
onMounted(() => document.addEventListener('click', onDocClick));
onBeforeUnmount(() => document.removeEventListener('click', onDocClick));
</script>
