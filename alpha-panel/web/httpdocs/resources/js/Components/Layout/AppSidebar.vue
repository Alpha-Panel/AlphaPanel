<template>
    <aside
        :class="[
            'fixed mt-16 flex flex-col lg:mt-0 top-0 px-5 bg-white dark:bg-gray-900 dark:border-gray-800 text-gray-900 h-[calc(100dvh-4rem)] lg:h-dvh transition-all duration-300 ease-in-out z-99999 border-gray-200',
            {
                'lg:w-72.5': isExpanded || isMobileOpen || isHovered,
                'lg:w-22.5': !isExpanded && !isHovered,
                'translate-x-0 w-72.5': isMobileOpen,
                '-translate-x-full': !isMobileOpen && !isRtl,
                'translate-x-full': !isMobileOpen && isRtl,
                'lg:translate-x-0': true,
                'left-0 border-r': !isRtl,
                'right-0 border-l': isRtl,
            },
        ]"
        @mouseenter="!isExpanded && (isHovered = true)"
        @mouseleave="isHovered = false"
    >
        <div
            :class="[
                'py-6 flex items-center',
                !isExpanded && !isHovered ? 'lg:justify-center' : 'justify-start',
            ]"
        >
            <Link :href="route('home')" class="flex items-center gap-3">
                <img :src="logoUrl" :alt="appName" class="h-10 w-10 shrink-0 object-contain" />
                <span
                    v-if="isExpanded || isHovered || isMobileOpen"
                    class="text-xl font-bold text-brand-500 dark:text-brand-400"
                >
                    {{ appName }}
                </span>
            </Link>
        </div>
        <div class="flex min-h-0 flex-1 flex-col overflow-y-auto duration-300 ease-linear no-scrollbar">
            <nav class="mb-6">
                <div class="flex flex-col gap-4">
                    <div v-for="(menuGroup, groupIndex) in menuGroups" :key="groupIndex">
                        <h2
                            :class="[
                                'mb-4 text-xs uppercase flex leading-5 text-gray-400',
                                !isExpanded && !isHovered
                                    ? 'lg:justify-center'
                                    : 'justify-start',
                            ]"
                        >
                            <template v-if="isExpanded || isHovered || isMobileOpen">
                                {{ menuGroup.title }}
                            </template>
                            <HorizontalDots v-else />
                        </h2>
                        <ul class="flex flex-col gap-4">
                            <li v-for="(item, index) in menuGroup.items" :key="item.name">
                                <button
                                    v-if="item.subItems"
                                    @click="toggleSubmenuHandler(groupIndex, index)"
                                    :class="[
                                        'menu-item group w-full',
                                        {
                                            'menu-item-active': isSubmenuOpen(groupIndex, index),
                                            'menu-item-inactive': !isSubmenuOpen(groupIndex, index),
                                        },
                                        !isExpanded && !isHovered
                                            ? 'lg:justify-center'
                                            : 'lg:justify-start',
                                    ]"
                                >
                                    <span
                                        :class="[
                                            isSubmenuOpen(groupIndex, index)
                                                ? 'menu-item-icon-active'
                                                : 'menu-item-icon-inactive',
                                        ]"
                                    >
                                        <component v-if="item.icon" :is="item.icon" />
                                        <i v-else-if="item.iconClass" :class="[item.iconClass, 'text-base']"></i>
                                    </span>
                                    <span
                                        v-if="isExpanded || isHovered || isMobileOpen"
                                        class="menu-item-text"
                                    >
                                        {{ item.name }}
                                    </span>
                                    <ChevronDownIcon
                                        v-if="isExpanded || isHovered || isMobileOpen"
                                        :class="[
                                            isRtl ? 'mr-auto w-5 h-5 transition-transform duration-200' : 'ml-auto w-5 h-5 transition-transform duration-200',
                                            {
                                                'rotate-180 text-brand-500': isSubmenuOpen(
                                                    groupIndex,
                                                    index,
                                                ),
                                            },
                                        ]"
                                    />
                                </button>
                                <button
                                    v-else-if="item.action"
                                    @click="item.action()"
                                    :class="[
                                        'menu-item group menu-item-inactive',
                                        !isExpanded && !isHovered
                                            ? 'lg:justify-center'
                                            : 'lg:justify-start',
                                    ]"
                                >
                                    <span class="menu-item-icon-inactive">
                                        <component v-if="item.icon" :is="item.icon" />
                                        <i v-else-if="item.iconClass" :class="[item.iconClass, 'text-base']"></i>
                                    </span>
                                    <span
                                        v-if="isExpanded || isHovered || isMobileOpen"
                                        class="menu-item-text"
                                    >
                                        {{ item.name }}
                                    </span>
                                </button>
                                <a
                                    v-else-if="item.href && item.external"
                                    :href="item.href"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    class="menu-item group menu-item-inactive"
                                >
                                    <span class="menu-item-icon-inactive">
                                        <component v-if="item.icon" :is="item.icon" />
                                        <i v-else-if="item.iconClass" :class="[item.iconClass, 'text-base']"></i>
                                    </span>
                                    <span
                                        v-if="isExpanded || isHovered || isMobileOpen"
                                        class="menu-item-text"
                                    >
                                        {{ item.name }}
                                    </span>
                                </a>
                                <Link
                                    v-else-if="item.href"
                                    :href="item.href"
                                    :class="[
                                        'menu-item group',
                                        {
                                            'menu-item-active': isActive(item.href),
                                            'menu-item-inactive': !isActive(item.href),
                                        },
                                    ]"
                                >
                                    <span
                                        :class="[
                                            isActive(item.href)
                                                ? 'menu-item-icon-active'
                                                : 'menu-item-icon-inactive',
                                        ]"
                                    >
                                        <component v-if="item.icon" :is="item.icon" />
                                        <i v-else-if="item.iconClass" :class="[item.iconClass, 'text-base']"></i>
                                    </span>
                                    <span
                                        v-if="isExpanded || isHovered || isMobileOpen"
                                        class="menu-item-text"
                                    >
                                        {{ item.name }}
                                    </span>
                                </Link>
                                <transition
                                    @enter="startTransition"
                                    @after-enter="endTransition"
                                    @before-leave="startTransition"
                                    @after-leave="endTransition"
                                >
                                    <div
                                        v-show="
                                            isSubmenuOpen(groupIndex, index) &&
                                            (isExpanded || isHovered || isMobileOpen)
                                        "
                                    >
                                        <ul
                                            :class="isRtl ? 'mr-9' : 'ml-9'"
                                            class="mt-2 space-y-1"
                                        >
                                            <li
                                                v-for="subItem in item.subItems"
                                                :key="subItem.name"
                                            >
                                                <Link
                                                    :href="subItem.href"
                                                    :class="[
                                                        'menu-dropdown-item',
                                                        {
                                                            'menu-dropdown-item-active': isActive(
                                                                subItem.href,
                                                            ),
                                                            'menu-dropdown-item-inactive':
                                                                !isActive(subItem.href),
                                                        },
                                                    ]"
                                                >
                                                    <i v-if="subItem.iconClass" :class="[subItem.iconClass, 'mr-2 w-4 text-center text-xs opacity-70']"></i>
                                                    {{ subItem.name }}
                                                </Link>
                                            </li>
                                        </ul>
                                    </div>
                                </transition>
                            </li>
                        </ul>
                    </div>
                </div>
            </nav>

            <div v-if="canAny('panel.audit-logs.view', 'panel.terminal-logs.view')" class="mt-auto border-t border-gray-200 pb-6 pt-4 dark:border-gray-800">
                <Link
                    v-if="can('panel.audit-logs.view')"
                    :href="route('audit-logs.index')"
                    :class="[
                        'menu-item group',
                        {
                            'menu-item-active': isActive(route('audit-logs.index')),
                            'menu-item-inactive': !isActive(route('audit-logs.index')),
                        },
                        !isExpanded && !isHovered ? 'lg:justify-center' : 'lg:justify-start',
                    ]"
                >
                    <span
                        :class="[
                            isActive(route('audit-logs.index'))
                                ? 'menu-item-icon-active'
                                : 'menu-item-icon-inactive',
                        ]"
                    >
                        <i class="fa-solid fa-file-waveform text-base"></i>
                    </span>
                    <span
                        v-if="isExpanded || isHovered || isMobileOpen"
                        class="menu-item-text"
                    >
                        {{ t('Audit Log') }}
                    </span>
                </Link>
                <Link
                    v-if="can('panel.terminal-logs.view')"
                    :href="route('terminal-logs.index')"
                    :class="[
                        'menu-item group mt-1',
                        {
                            'menu-item-active': isActive(route('terminal-logs.index')),
                            'menu-item-inactive': !isActive(route('terminal-logs.index')),
                        },
                        !isExpanded && !isHovered ? 'lg:justify-center' : 'lg:justify-start',
                    ]"
                >
                    <span
                        :class="[
                            isActive(route('terminal-logs.index'))
                                ? 'menu-item-icon-active'
                                : 'menu-item-icon-inactive',
                        ]"
                    >
                        <i class="fa-solid fa-terminal text-base"></i>
                    </span>
                    <span
                        v-if="isExpanded || isHovered || isMobileOpen"
                        class="menu-item-text"
                    >
                        {{ t('Terminal Logs') }}
                    </span>
                </Link>
            </div>
        </div>
    </aside>
</template>

<script setup lang="ts">
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import { useSidebar } from '@/Composables/useSidebar';
import { useCan } from '@/Composables/useCan';
import {
    GridIcon,
    UserCircleIcon,
    ChevronDownIcon,
    HorizontalDots,
    TableIcon,
} from '@/Components/Icons';
import { useI18n } from '@/Composables/useI18n';
import type { SharedProps } from '@/types/inertia';

const page = usePage<SharedProps>();
const { isExpanded, isMobileOpen, isHovered, openSubmenu } = useSidebar();
const { t } = useI18n();
const { can, canAny, isAdmin } = useCan();
const isRtl = computed(() => page.props.text_direction === 'rtl');
const appName = computed(() => page.props.app?.name ?? 'AlphaPanel');
const logoUrl = computed(() => page.props.app?.logo_url ?? '/img/AlphaPanel-dark.svg');
const externalLinks = computed(() => page.props.app?.links ?? {});

interface SidebarMenuItem {
    icon?: unknown;
    iconClass?: string;
    name: string;
    href?: string;
    external?: boolean;
    action?: () => void;
    subItems?: Array<{ name: string; href: string; iconClass?: string }>;
}

const menuGroups = computed(() => {
    const groups = [
        {
            title: t('Main'),
            items: [
                {
                    icon: GridIcon,
                    name: t('Dashboard'),
                    href: route('home'),
                },
                {
                    icon: TableIcon,
                    name: t('Domains'),
                    href: route('domains.index'),
                },
            ] as SidebarMenuItem[],
        },
        {
            title: t('Management'),
            items: (() => {
                const items: SidebarMenuItem[] = [];

                if (can('panel.users.manage')) {
                    const userMgmtSubItems = [
                        { name: t('Users'), href: route('users.list'), iconClass: 'fa-solid fa-users' },
                        { name: t('Roles'), href: route('roles.index'), iconClass: 'fa-solid fa-user-shield' },
                    ];
                    if (isAdmin.value) {
                        userMgmtSubItems.push({ name: t('Push Notifications'), href: route('admin.push-notifications.index'), iconClass: 'fa-solid fa-bell' });
                    }
                    items.push({
                        icon: UserCircleIcon,
                        name: t('User Management'),
                        subItems: userMgmtSubItems,
                    });
                }

                if (canAny('panel.backups.view', 'panel.backups.manage')) {
                    items.push({
                        iconClass: 'fa-solid fa-cloud-arrow-up',
                        name: t('Backups'),
                        href: route('backups.index'),
                    });
                }

                if (can('panel.terminal.access')) {
                    items.push({
                        iconClass: 'fa-solid fa-terminal',
                        name: t('Terminal'),
                        action: () => document.dispatchEvent(new CustomEvent('open-host-terminal')),
                    });
                }

                if (can('panel.phpmyadmin.access')) {
                    items.push({
                        iconClass: 'lni lni-mysql',
                        name: t('phpMyAdmin'),
                        href: route('pma.admin.sso'),
                        external: true,
                    });
                }

                if (externalLinks.value.file_manager && isAdmin.value) {
                    items.push({
                        iconClass: 'fa-solid fa-folder-open',
                        name: t('File Manager'),
                        href: externalLinks.value.file_manager,
                        external: true,
                    });
                }

                if (externalLinks.value.jenkins && isAdmin.value) {
                    items.push({
                        iconClass: 'fa-brands fa-jenkins',
                        name: t('Jenkins'),
                        href: externalLinks.value.jenkins,
                        external: true,
                    });
                }

                if (externalLinks.value.n8n && isAdmin.value) {
                    items.push({
                        iconClass: 'lni lni-n8n',
                        name: t('N8N'),
                        href: externalLinks.value.n8n,
                        external: true,
                    });
                }

                if (canAny('panel.firewall.view', 'panel.waf-rules.view', 'panel.waf-rules.manage', 'panel.crowdsec.view', 'panel.ftp-bans.view')) {
                    const securitySubItems: Array<{ name: string; href: string; iconClass?: string }> = [];
                    if (can('panel.firewall.view')) {
                        securitySubItems.push({ name: t('Firewall'), href: route('security.firewall.index'), iconClass: 'fa-solid fa-fire' });
                    }
                    if (canAny('panel.waf-rules.view', 'panel.waf-rules.manage')) {
                        securitySubItems.push({ name: t('WAF Rules'), href: route('security.waf-global.index'), iconClass: 'fa-solid fa-shield' });
                    }
                    if (can('panel.crowdsec.view')) {
                        securitySubItems.push({ name: t('CrowdSec'), href: route('security.crowdsec.index'), iconClass: 'fa-solid fa-robot' });
                    }
                    if (can('panel.ftp-bans.view')) {
                        securitySubItems.push({ name: t('FTP Bans'), href: route('security.ftp-bans.index'), iconClass: 'fa-solid fa-ban' });
                    }
                    if (securitySubItems.length > 0) {
                        items.push({
                            iconClass: 'fa-solid fa-shield-halved',
                            name: t('Security'),
                            subItems: securitySubItems,
                        });
                    }
                }

                if (can('panel.system.updates')) {
                    items.push({
                        iconClass: 'fa-solid fa-arrow-up-from-bracket',
                        name: t('System Updates'),
                        href: route('system.updates.index'),
                    });
                }

                return items;
            })(),
        },
    ];

    return groups.filter((group) => group.items.length > 0);
});

const currentUrl = computed(() => page.url);

const isActive = (href: string) => {
    const url = new URL(href, window.location.origin);
    const currentPath = currentUrl.value.split('?')[0].split('#')[0];
    const targetPath = url.pathname;

    if (targetPath === '/') {
        return currentPath === '/';
    }

    return currentPath === targetPath || currentPath.startsWith(`${targetPath}/`);
};

const toggleSubmenuHandler = (groupIndex: number, itemIndex: number) => {
    const key = `${groupIndex}-${itemIndex}`;
    openSubmenu.value = openSubmenu.value === key ? null : key;
};

const isAnySubmenuRouteActive = computed(() => {
    return menuGroups.value.some((group) =>
        group.items.some(
            (item) =>
                'subItems' in item &&
                item.subItems &&
                item.subItems.some((subItem) => isActive(subItem.href)),
        ),
    );
});

const isSubmenuOpen = (groupIndex: number, itemIndex: number) => {
    const key = `${groupIndex}-${itemIndex}`;
    const item = menuGroups.value[groupIndex].items[itemIndex];
    return (
        openSubmenu.value === key ||
        (isAnySubmenuRouteActive.value &&
            'subItems' in item &&
            item.subItems?.some((subItem) => isActive(subItem.href)))
    );
};

const startTransition = (el: Element) => {
    const htmlEl = el as HTMLElement;
    htmlEl.style.height = 'auto';
    const height = htmlEl.scrollHeight;
    htmlEl.style.height = '0px';
    htmlEl.offsetHeight; // force reflow
    htmlEl.style.height = height + 'px';
};

const endTransition = (el: Element) => {
    (el as HTMLElement).style.height = '';
};
</script>
