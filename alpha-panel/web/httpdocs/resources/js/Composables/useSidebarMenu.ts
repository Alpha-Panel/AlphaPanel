import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { useCan } from '@/Composables/useCan';
import { useI18n } from '@/Composables/useI18n';
import { useAppTabs } from '@/Composables/useAppTabs';
import type { SharedProps } from '@/types/inertia';

export interface NavLeaf {
    name: string;
    href: string;
    icon?: string;
    external?: boolean;
}
export interface NavGroup {
    name: string;
    icon?: string;
    children: NavLeaf[];
}
export type NavChild = NavLeaf | NavGroup;
export interface NavItem {
    key: string;
    name: string;
    icon: string;
    href?: string;
    external?: boolean;
    action?: () => void;
    children?: NavChild[];
}
export interface NavSection {
    title: string;
    items: NavItem[];
}

export const isNavGroup = (c: NavChild): c is NavGroup => (c as NavGroup).children !== undefined;

/**
 * Sidebar menu definition — same permission gates as the previous AppSidebar.vue,
 * moved out of the template so the layout stays readable. Icons are boxicons.
 */
export function useSidebarMenu() {
    const page = usePage<SharedProps>();
    const { t } = useI18n();
    const { can, canAny, isAdmin } = useCan();
    const links = computed(() => page.props.app?.links ?? {});
    const { openTab } = useAppTabs();

    const sections = computed<NavSection[]>(() => {
        const main: NavItem[] = [
            { key: 'home', name: t('Dashboard'), icon: 'bx-grid-alt', href: route('home') },
            { key: 'domains', name: t('Domains'), icon: 'bx-globe', href: route('domains.index') },
        ];

        const mgmt: NavItem[] = [];

        if (can('panel.users.manage')) {
            const children: NavLeaf[] = [
                { name: t('Users'), href: route('users.list'), icon: 'bx-group' },
                { name: t('Roles'), href: route('roles.index'), icon: 'bx-shield-quarter' },
            ];
            if (isAdmin.value) {
                children.push({ name: t('Push Notifications'), href: route('admin.push-notifications.index'), icon: 'bx-bell' });
            }
            mgmt.push({ key: 'users', name: t('User Management'), icon: 'bx-user-circle', children });
        }

        if (canAny('panel.backups.view', 'panel.backups.manage')) {
            mgmt.push({ key: 'backups', name: t('Backups'), icon: 'bx-cloud-upload', href: route('backups.index') });
        }

        if (canAny('panel.docker-services.view', 'panel.docker-services.manage')) {
            mgmt.push({
                key: 'docker',
                name: t('External Services'),
                icon: 'bxl-docker',
                children: [
                    { name: t('Docker Services'), href: route('docker-services.index'), icon: 'bx-server' },
                    { name: t('Docker Projects'), href: route('docker-projects.index'), icon: 'bx-code-block' },
                ],
            });
        }

        if (can('panel.terminal.access')) {
            mgmt.push({
                key: 'terminal',
                name: t('Terminal'),
                icon: 'bx-terminal',
                action: () => document.dispatchEvent(new CustomEvent('open-host-terminal')),
            });
        }

        // DB tools open as in-app tabs; the panel SSO route redirects the frame to the tool's own domain.
        if (can('panel.phpmyadmin.access')) {
            const name = t('phpMyAdmin');
            mgmt.push({ key: 'pma', name, icon: 'lni lni-mysql', action: () => openTab(route('pma.admin.sso'), true, name) });
        }
        if (can('panel.pgadmin.access') && links.value.pgadmin) {
            const name = t('pgAdmin');
            mgmt.push({ key: 'pga', name, icon: 'lni lni-postgresql', action: () => openTab(route('pgadmin.sso'), true, name) });
        }
        if (isAdmin.value) {
            mgmt.push({ key: 'fm', name: t('File Manager'), icon: 'bx-folder-open', href: route('file-manager.index') });
        }
        if (isAdmin.value && links.value.jenkins) {
            mgmt.push({ key: 'jenkins', name: t('Jenkins'), icon: 'fa-brands fa-jenkins', href: links.value.jenkins, external: true });
        }
        if (isAdmin.value && links.value.n8n) {
            mgmt.push({ key: 'n8n', name: t('N8N'), icon: 'lni lni-n8n', href: links.value.n8n, external: true });
        }

        // DNS and Security are promoted to top-level groups (they were nested under Settings).
        if (canAny('panel.dns-settings.view', 'panel.dns-settings.manage')) {
            const children: NavLeaf[] = [];
            if (can('panel.dns-settings.manage')) children.push({ name: t('DNS Settings'), href: route('settings.dns.index'), icon: 'bx-cog' });
            if (can('panel.dns-templates.manage')) children.push({ name: t('DNS Templates'), href: route('settings.dns-templates.index'), icon: 'bx-file' });
            if (children.length) mgmt.push({ key: 'dns', name: t('DNS'), icon: 'bx-network-chart', children });
        }

        {
            const children: NavLeaf[] = [];
            if (can('panel.firewall.view')) children.push({ name: t('Firewall'), href: route('security.firewall.index'), icon: 'bx-shield-alt-2' });
            if (canAny('panel.waf-rules.view', 'panel.waf-rules.manage')) children.push({ name: t('WAF Rules'), href: route('security.waf-global.index'), icon: 'bx-shield' });
            if (can('panel.crowdsec.view')) children.push({ name: t('CrowdSec'), href: route('security.crowdsec.index'), icon: 'bx-bot' });
            if (can('panel.ftp-bans.view')) children.push({ name: t('FTP Bans'), href: route('security.ftp-bans.index'), icon: 'bx-block' });
            if (can('panel.security-settings.manage')) {
                children.push({ name: t('Login IP Filter'), href: route('settings.security.login-ip-filter.index'), icon: 'bx-filter-alt' });
                children.push({ name: t('Anti-Bot Protection'), href: route('settings.security.anti-bot.index'), icon: 'bx-shield-x' });
            }
            if (children.length) mgmt.push({ key: 'security', name: t('Security'), icon: 'bx-shield-alt-2', children });
        }

        const settings: NavLeaf[] = [];
        if (can('panel.acme-settings.manage')) {
            settings.push({ name: t('ACME Settings'), href: route('settings.acme.index'), icon: 'bx-lock-alt' });
            settings.push({ name: t('Panel SSL Certificate'), href: route('settings.panel-ssl.index'), icon: 'bx-certification' });
        }
        if (can('panel.mysql-config.manage')) settings.push({ name: t('MySQL Config'), href: route('settings.mysql-config.index'), icon: 'bx-data' });
        if (can('panel.php-versions.view')) settings.push({ name: t('PHP Versions'), href: route('php-versions.index'), icon: 'bxl-php' });
        if (can('panel.alert-settings.manage')) settings.push({ name: t('System Alerts'), href: route('settings.alerts.index'), icon: 'bx-bell-plus' });
        if (can('panel.system.updates')) settings.push({ name: t('System Updates'), href: route('system.updates.index'), icon: 'bx-cloud-download' });
        if (isAdmin.value) {
            settings.push({ name: t('Mail Settings'), href: route('mail.settings.edit'), icon: 'bx-envelope' });
            settings.push({ name: t('API Tokens'), href: route('settings.api-tokens.index'), icon: 'bx-key' });
            settings.push({ name: t('Webhooks'), href: route('settings.webhooks.index'), icon: 'bx-git-pull-request' });
        }
        if (settings.length) mgmt.push({ key: 'settings', name: t('Settings'), icon: 'bx-cog', children: settings });

        const logs: NavItem[] = [];
        if (can('panel.audit-logs.view')) logs.push({ key: 'audit', name: t('Audit Log'), icon: 'bx-history', href: route('audit-logs.index') });
        if (can('panel.terminal-logs.view')) logs.push({ key: 'tlogs', name: t('Terminal Logs'), icon: 'bx-terminal', href: route('terminal-logs.index') });

        return [
            { title: t('Main'), items: main },
            { title: t('Management'), items: mgmt },
            { title: t('Logs'), items: logs },
        ].filter((s) => s.items.length > 0);
    });

    const isActive = (href?: string): boolean => {
        if (!href) return false;
        const target = new URL(href, window.location.origin).pathname;
        const current = page.url.split('?')[0].split('#')[0];
        if (target === '/') return current === '/';
        return current === target || current.startsWith(`${target}/`);
    };

    const childActive = (c: NavChild): boolean =>
        isNavGroup(c) ? c.children.some((l) => isActive(l.href)) : isActive(c.href);

    const itemActive = (item: NavItem): boolean =>
        item.children ? item.children.some(childActive) : isActive(item.href);

    return { sections, isActive, childActive, itemActive };
}
