import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import type { UIEvent } from 'react';
import { Link, usePage } from '@inertiajs/react';
import {
    Activity,
    ChevronDown,
    ChevronRight,
    Clock,
    Cpu,
    CreditCard,
    FileText,
    Gamepad2,
    Globe2,
    Home,
    Landmark,
    Menu,
    Package,
    Settings,
    Shield,
    Ticket,
    UserRound,
    Users,
    X,
    Zap,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import type { PageProps } from '@/types';

interface SidebarProps {
    isOpen: boolean;
    onClose: () => void;
}

interface MenuItem {
    key: string;
    label: string;
    href?: string;
    icon: LucideIcon;
    children?: MenuItem[];
    description?: string;
    permission?: string | string[];
}

const SIDEBAR_SCROLL_STORAGE_KEY = 'admin-sidebar-scroll-top';

const menuItems: MenuItem[] = [
    {
        key: 'dashboard',
        label: 'Dashboard',
        href: '/admin',
        icon: Home,
        description: 'Tổng quan hệ thống',
        permission: 'dashboard.view',
    },
    {
        key: 'users-group',
        label: 'Quản lý người dùng',
        icon: Users,
        description: 'Tài khoản, phân quyền & số dư',
        permission: 'users.view',
        children: [
            {
                key: 'users',
                label: 'Danh sách người dùng',
                href: '/admin/users',
                icon: Users,
                description: 'Quản lý tài khoản và số dư',
                permission: 'users.view',
            },
            {
                key: 'roles',
                label: 'Vai trò & phân quyền',
                href: '/admin/roles',
                icon: Shield,
                description: 'Phân quyền cho quản trị và cộng tác viên',
                permission: ['roles.view', 'roles.manage'],
            },
            {
                key: 'ctv',
                label: 'Cộng tác viên',
                href: '/admin/users/ctv',
                icon: UserRound,
                description: 'Phân danh mục cho cộng tác viên',
                permission: ['users.view', 'user-categories.manage'],
            },
        ],
    },
    {
        key: 'finance-group',
        label: 'Quản lý dòng tiền',
        icon: CreditCard,
        description: 'Số dư, nạp tiền & giao dịch',
        permission: [
            'transactions.view',
            'deposits.view',
            'card-types.view',
            'card-types.manage',
            'cards.view',
            'withdrawals.view',
            'withdrawals.process',
        ],
        children: [
            {
                key: 'transactions',
                label: 'Lịch sử số dư',
                href: '/admin/transactions',
                icon: Clock,
                description: 'Theo dõi cộng và trừ tiền',
                permission: 'transactions.view',
            },
            {
                key: 'deposits',
                label: 'Nạp tiền',
                href: '/admin/deposits',
                icon: Landmark,
                description: 'Thẻ cào và ngân hàng SePay',
                permission: 'deposits.view',
            },
            {
                key: 'cardtypes',
                label: 'Loại thẻ cào',
                href: '/admin/cardtypes',
                icon: CreditCard,
                description: 'Cấu hình nhà mạng và chiết khấu',
                permission: ['card-types.view', 'card-types.manage'],
            },
            {
                key: 'cards',
                label: 'Lịch sử thẻ',
                href: '/admin/cards',
                icon: FileText,
                description: 'Theo dõi giao dịch thẻ cào',
                permission: 'cards.view',
            },
            {
                key: 'withdrawals',
                label: 'Yêu cầu rút tiền',
                href: '/admin/withdrawals',
                icon: Landmark,
                description: 'Duyệt và đối soát tiền rút',
                permission: ['withdrawals.view', 'withdrawals.process'],
            },
        ],
    },
    {
        key: 'webgame-group',
        label: 'Webgame',
        icon: Gamepad2,
        description: 'Danh mục, thuộc tính và kho tài khoản',
        permission: [
            'analytics.view',
            'game-types.view',
            'game-types.manage',
            'categories.view',
            'categories.manage',
            'attributes.view',
            'attributes.manage',
            'nicks.view',
            'nicks.manage',
            'carot-recharges.view',
        ],
        children: [
            { key: 'webgame-analytics', label: 'Thống kê webgame', href: '/admin/analytics', icon: Activity, permission: 'analytics.view' },
            { key: 'game-types', label: 'Loại game', href: '/admin/games/gametypes', icon: Gamepad2, permission: ['game-types.view', 'game-types.manage'] },
            { key: 'categories', label: 'Danh mục', href: '/admin/games/categories', icon: FileText, permission: ['categories.view', 'categories.manage'] },
            { key: 'attributes', label: 'Thuộc tính nick', href: '/admin/games/attributes', icon: Shield, permission: ['attributes.view', 'attributes.manage'] },
            { key: 'category-attributes', label: 'Thuộc tính danh mục', href: '/admin/games/category-attributes', icon: Settings, permission: ['attributes.view', 'attributes.manage'] },
            { key: 'nicks', label: 'Kho nick', href: '/admin/games/accounts', icon: Users, permission: ['nicks.view', 'nicks.manage'] },
            { key: 'nick-orders', label: 'Đơn mua nick', href: '/admin/games/accounts/history', icon: Clock, permission: ['nicks.view', 'nicks.manage'] },
            { key: 'carot-recharges', label: 'Nạp Carot', href: '/admin/carot-recharges', icon: CreditCard, permission: 'carot-recharges.view' },
        ],
    },
    {
        key: 'services-group',
        label: 'Dịch vụ game',
        icon: Zap,
        description: 'Cấu hình dịch vụ và xử lý đơn thuê',
        permission: [
            'services.view',
            'services.manage',
            'fields.view',
            'fields.manage',
            'service-configuration.manage',
            'service-orders.view',
            'service-orders.process',
        ],
        children: [
            { key: 'services', label: 'Cấu hình dịch vụ', href: '/admin/services', icon: Settings, permission: ['services.view', 'services.manage'] },
            { key: 'fields', label: 'Trường thông tin', href: '/admin/fields', icon: FileText, permission: ['fields.view', 'fields.manage'] },
            { key: 'service-fields', label: 'Trường nhập dịch vụ', href: '/admin/service-fields', icon: Settings, permission: 'service-configuration.manage' },
            { key: 'category-services', label: 'Dịch vụ theo danh mục', href: '/admin/category-services', icon: Gamepad2, permission: 'service-configuration.manage' },
            { key: 'category-templates', label: 'Template danh mục', href: '/admin/category-templates', icon: FileText, permission: 'service-configuration.manage' },
            { key: 'service-orders', label: 'Đơn đang chờ', href: '/admin/services/orders', icon: Clock, permission: ['service-orders.view', 'service-orders.process'] },
            { key: 'receiver-orders', label: 'Đơn đã nhận', href: '/admin/services/orders/receiver', icon: UserRound, permission: ['service-orders.view', 'service-orders.process'] },
        ],
    },
    {
        key: 'minigames-group',
        label: 'Random & vòng quay',
        icon: Package,
        description: 'Kho random, vòng quay và lịch sử trúng',
        permission: [
            'random-boxes.view',
            'random-boxes.manage',
            'random-nicks.view',
            'random-nicks.manage',
            'spins.view',
            'spins.manage',
            'spin-tickets.view',
            'spin-tickets.manage',
        ],
        children: [
            { key: 'random-boxes', label: 'Loại Random', href: '/admin/randombox', icon: Package, permission: ['random-boxes.view', 'random-boxes.manage'] },
            { key: 'random-nicks', label: 'Kho nick Random', href: '/admin/random-nicks', icon: Users, permission: ['random-nicks.view', 'random-nicks.manage'] },
            { key: 'spins', label: 'Cấu hình vòng quay', href: '/admin/spins', icon: Activity, permission: ['spins.view', 'spins.manage'] },
            { key: 'spin-results', label: 'Lịch sử vòng quay', href: '/admin/spin-results', icon: Clock, permission: ['spins.view', 'spins.manage'] },
            { key: 'spin-tickets', label: 'Vé vòng quay', href: '/admin/spin-tickets', icon: Ticket, permission: ['spin-tickets.view', 'spin-tickets.manage'] },
        ],
    },
    {
        key: 'trading-group',
        label: 'Vàng & ngọc tự động',
        icon: Landmark,
        description: 'Server, bot, kho vàng/ngọc và đơn hàng',
        permission: [
            'trading-dashboard.view',
            'servers.view',
            'servers.manage',
            'server-game-logins.view',
            'server-game-logins.manage',
            'bots.view',
            'bots.manage',
            'gold-prices.view',
            'gold-prices.manage',
            'imports.view',
            'gold-orders.view',
            'gold-orders.process',
            'gem-bots.view',
            'gem-bots.manage',
            'gem-prices.view',
            'gem-prices.manage',
            'gem-orders.view',
            'gem-orders.process',
            'bot-histories.view',
        ],
        children: [
            { key: 'trading-dashboard', label: 'Thống kê giao dịch', href: '/admin/dashboard', icon: Activity, permission: 'trading-dashboard.view' },
            { key: 'servers', label: 'Server', href: '/admin/servers', icon: Cpu, permission: ['servers.view', 'servers.manage'] },
            { key: 'server-game-logins', label: 'Tài khoản server', href: '/admin/server-game-logins', icon: Shield, permission: ['server-game-logins.view', 'server-game-logins.manage'] },
            { key: 'bots', label: 'Bot bán vàng', href: '/admin/bots', icon: Cpu, permission: ['bots.view', 'bots.manage'] },
            { key: 'gold-prices', label: 'Giá vàng', href: '/admin/gold-prices', icon: Landmark, permission: ['gold-prices.view', 'gold-prices.manage'] },
            { key: 'imports', label: 'Nhập vàng', href: '/admin/imports', icon: CreditCard, permission: 'imports.view' },
            { key: 'orders', label: 'Đơn vàng', href: '/admin/orders', icon: Clock, permission: ['gold-orders.view', 'gold-orders.process'] },
            { key: 'gem-bots', label: 'Bot bán ngọc', href: '/admin/gem-bots', icon: Cpu, permission: ['gem-bots.view', 'gem-bots.manage'] },
            { key: 'gem-prices', label: 'Giá ngọc', href: '/admin/gem-prices', icon: Landmark, permission: ['gem-prices.view', 'gem-prices.manage'] },
            { key: 'gem-orders', label: 'Đơn ngọc', href: '/admin/gem-orders', icon: Clock, permission: ['gem-orders.view', 'gem-orders.process'] },
            { key: 'bot-history', label: 'Lịch sử bot', href: '/admin/bot-history', icon: FileText, permission: 'bot-histories.view' },
        ],
    },
    {
        key: 'system-group',
        label: 'Hệ thống',
        icon: Shield,
        description: 'Ứng dụng frontend và bảo mật',
        permission: ['frontend-clients.view', 'frontend-clients.manage'],
        children: [
            {
                key: 'frontend-clients',
                label: 'Frontend Clients',
                href: '/admin/frontend-clients',
                icon: Globe2,
                description: 'Domain được phép kết nối SSO',
                permission: ['frontend-clients.view', 'frontend-clients.manage'],
            },
        ],
    },
    {
        key: 'profile',
        label: 'Hồ sơ',
        href: '/profile',
        icon: UserRound,
        description: 'Thông tin tài khoản quản trị',
    },
];

export default function Sidebar({ isOpen, onClose }: SidebarProps) {
    const { props, url } = usePage<PageProps>();
    const { auth } = props;
    const [collapsed, setCollapsed] = useState(false);
    const [expandedItems, setExpandedItems] = useState<string[]>([]);
    const [mounted, setMounted] = useState(false);
    const navRef = useRef<HTMLElement>(null);

    const hasPermission = useCallback((permission?: string) => {
        if (!permission) return true;
        if (auth.is_super_admin) return true;
        return Array.isArray(auth.permissions) && auth.permissions.includes(permission);
    }, [auth.is_super_admin, auth.permissions]);

    const filteredMenuItems = useMemo(() => {
        const filter = (items: MenuItem[]): MenuItem[] => items.reduce<MenuItem[]>((visible, item) => {
            const itemPermissions = Array.isArray(item.permission) ? item.permission : [item.permission];
            const allowed = itemPermissions.some(permission => hasPermission(permission));
            const children = item.children ? filter(item.children) : [];

            if (allowed || children.length > 0) {
                visible.push({ ...item, children: children.length > 0 ? children : undefined });
            }

            return visible;
        }, []);

        return filter(menuItems);
    }, [hasPermission]);

    const currentPath = url.split('?')[0];
    const isActiveRoute = useCallback((href?: string) => {
        if (!href) return false;
        return href === '/admin' ? currentPath === href : currentPath.startsWith(href);
    }, [currentPath]);

    useEffect(() => {
        setMounted(true);
        try {
            setCollapsed(localStorage.getItem('admin-sidebar-collapsed') === 'true');
        } catch {
            // Sidebar remains usable when browser storage is unavailable.
        }
    }, []);

    useEffect(() => {
        const activeGroups = filteredMenuItems
            .filter(item => item.children?.some(child => isActiveRoute(child.href)))
            .map(item => item.key);

        if (activeGroups.length > 0) {
            setExpandedItems(previous => Array.from(new Set([...previous, ...activeGroups])));
        }
    }, [filteredMenuItems, isActiveRoute]);

    useEffect(() => {
        let savedScrollTop = 0;
        try {
            savedScrollTop = Number(sessionStorage.getItem(SIDEBAR_SCROLL_STORAGE_KEY)) || 0;
        } catch {
            // Keep the default position when browser storage is unavailable.
        }

        let animationFrame = 0;
        let attempts = 0;
        const restoreScrollPosition = () => {
            if (navRef.current) {
                navRef.current.scrollTop = savedScrollTop;
                return;
            }

            attempts += 1;
            if (attempts < 3) {
                animationFrame = window.requestAnimationFrame(restoreScrollPosition);
            }
        };

        animationFrame = window.requestAnimationFrame(() => {
            animationFrame = window.requestAnimationFrame(restoreScrollPosition);
        });

        return () => window.cancelAnimationFrame(animationFrame);
    }, [currentPath]);

    const handleSidebarScroll = useCallback((event: UIEvent<HTMLElement>) => {
        try {
            sessionStorage.setItem(SIDEBAR_SCROLL_STORAGE_KEY, String(event.currentTarget.scrollTop));
        } catch {
            // Scrolling still works when browser storage is unavailable.
        }
    }, []);

    const toggleExpanded = useCallback((key: string) => {
        if (collapsed) {
            setCollapsed(false);
            try {
                localStorage.setItem('admin-sidebar-collapsed', 'false');
            } catch {
                // Keep the in-memory state when storage is unavailable.
            }
        }

        setExpandedItems(previous => previous.includes(key)
            ? previous.filter(item => item !== key)
            : [...previous, key]);
    }, [collapsed]);

    const toggleCollapsed = useCallback(() => {
        setCollapsed(previous => {
            const next = !previous;
            try {
                localStorage.setItem('admin-sidebar-collapsed', String(next));
            } catch {
                // Keep the in-memory state when storage is unavailable.
            }
            if (next) setExpandedItems([]);
            return next;
        });
    }, []);

    const handleItemClick = useCallback(() => {
        try {
            if (navRef.current) {
                sessionStorage.setItem(SIDEBAR_SCROLL_STORAGE_KEY, String(navRef.current.scrollTop));
            }
        } catch {
            // Navigation still works when browser storage is unavailable.
        }

        if (window.innerWidth < 1024) onClose();
    }, [onClose]);

    const MenuItemComponent = ({ item, level = 0 }: { item: MenuItem; level?: number }) => {
        const hasChildren = Boolean(item.children?.length);
        const expanded = expandedItems.includes(item.key);
        const active = isActiveRoute(item.href)
            || Boolean(item.children?.some(child => isActiveRoute(child.href)));
        const hideDesktopLabel = collapsed && level === 0 ? 'lg:hidden' : '';

        const content = (
            <div
                onClick={hasChildren ? () => toggleExpanded(item.key) : undefined}
                className={`group relative mx-1.5 mb-0.5 flex cursor-pointer items-center overflow-visible rounded-lg px-2.5 py-2.5 transition-colors duration-200 ${level > 0 ? 'ml-3' : ''} ${collapsed && level === 0 ? 'lg:justify-center lg:px-2' : ''} ${active
                    ? 'text-gray-900 dark:text-white'
                    : 'text-gray-500 hover:bg-gray-100/70 hover:text-gray-800 dark:text-gray-400 dark:hover:bg-white/5 dark:hover:text-gray-200'}`}
            >
                <item.icon
                    size={collapsed && level === 0 ? 22 : 18}
                    className={`relative z-10 shrink-0 transition-colors duration-200 ${active
                        ? 'text-gray-900 dark:text-white'
                        : 'text-gray-400 group-hover:text-gray-600 dark:text-gray-500 dark:group-hover:text-gray-300'}`}
                />

                <div className={`relative z-10 ml-2.5 min-w-0 flex-1 ${hideDesktopLabel}`}>
                    <span className={`block truncate text-sm leading-tight ${active ? 'font-semibold' : 'font-medium'}`}>{item.label}</span>
                    {item.description && !hasChildren && (
                        <span className="block truncate text-xs text-gray-400 opacity-75 dark:text-gray-500">
                            {item.description}
                        </span>
                    )}
                </div>

                {hasChildren && (
                    <span className={`relative z-10 ${hideDesktopLabel}`}>
                        {expanded ? <ChevronDown size={14} /> : <ChevronRight size={14} />}
                    </span>
                )}

                {collapsed && level === 0 && (
                    <div className="pointer-events-none absolute left-full z-[70] ml-3 hidden whitespace-nowrap rounded-lg border border-gray-200 bg-white px-2.5 py-1.5 text-gray-700 opacity-0 shadow-lg transition-opacity group-hover:opacity-100 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-200 lg:block">
                        <span className="block text-xs font-medium">{item.label}</span>
                        {item.description && <span className="block text-xs text-gray-500 dark:text-gray-400">{item.description}</span>}
                    </div>
                )}
            </div>
        );

        return (
            <div key={item.key}>
                {item.href ? (
                    <Link href={item.href} onClick={handleItemClick}>{content}</Link>
                ) : content}

                {hasChildren && expanded && (
                    <div className={`ml-1 mt-0.5 space-y-0.5 ${collapsed ? 'lg:hidden' : ''}`}>
                        {item.children?.map(child => <MenuItemComponent key={child.key} item={child} level={level + 1} />)}
                    </div>
                )}
            </div>
        );
    };

    if (!mounted) return null;

    const dashboard = filteredMenuItems.find(item => item.key === 'dashboard');
    const profile = filteredMenuItems.find(item => item.key === 'profile');
    const managementItems = filteredMenuItems.filter(item => !['dashboard', 'profile'].includes(item.key));

    return (
        <>
            {isOpen && (
                <button
                    type="button"
                    aria-label="Đóng menu"
                    className="fixed inset-0 z-40 bg-black/50 backdrop-blur-sm lg:hidden"
                    onClick={onClose}
                />
            )}

            <aside className={`fixed inset-y-0 left-0 z-50 h-full w-80 bg-transparent transition-[width,transform] duration-500 ease-in-out lg:relative lg:inset-auto lg:translate-x-0 ${collapsed ? 'lg:w-16' : 'lg:w-80'} ${isOpen ? 'translate-x-0' : '-translate-x-full'}`}>
                <div className="absolute inset-0 border-r border-gray-200 bg-white/95 shadow-xl backdrop-blur-xl dark:border-gray-700 dark:bg-gray-900/95">
                    <div className="absolute inset-0 bg-gradient-to-b from-blue-50/50 via-white/20 to-purple-50/50 dark:from-blue-900/20 dark:via-gray-900/20 dark:to-purple-900/20" />
                    <div className="absolute right-[-1rem] top-8 h-16 w-16 animate-pulse rounded-full bg-gradient-to-br from-blue-400/30 to-purple-500/30 blur-xl" />
                    <div className="absolute bottom-16 left-[-1rem] h-12 w-12 animate-pulse rounded-full bg-gradient-to-br from-pink-400/30 to-orange-500/30 blur-xl" />
                </div>

                <div className="relative z-10 flex h-full flex-col">
                    <div className="border-b border-gray-200 bg-gradient-to-r from-transparent to-gray-50/30 p-4 dark:border-gray-700 dark:to-gray-800/30">
                        <div className="flex items-center justify-between">
                            <Link href="/admin" onClick={handleItemClick} className="group flex min-w-0 items-center gap-2.5">
                                <span className="relative grid h-10 w-10 shrink-0 place-items-center rounded-lg bg-gradient-to-r from-blue-500 to-purple-600 text-white shadow-lg transition-transform group-hover:scale-105">
                                    <Gamepad2 size={20} />
                                </span>
                                <span className={`min-w-0 ${collapsed ? 'lg:hidden' : ''}`}>
                                    <span className="block bg-gradient-to-r from-gray-700 to-gray-600 bg-clip-text text-lg font-bold text-transparent dark:from-gray-200 dark:to-gray-400">NROCHECK</span>
                                    <span className="block truncate text-xs text-gray-500 dark:text-gray-400">
                                        {auth.user?.username || 'Admin'} • {Array.isArray(auth.roles) ? auth.roles.join(', ') : ''}
                                    </span>
                                </span>
                            </Link>
                            <button type="button" onClick={onClose} aria-label="Đóng menu" className="rounded-lg p-1.5 text-gray-500 transition hover:bg-red-50 hover:text-red-500 dark:text-gray-400 dark:hover:bg-red-900/20 lg:hidden">
                                <X size={18} />
                            </button>
                        </div>
                    </div>

                    <nav
                        ref={navRef}
                        onScroll={handleSidebarScroll}
                        className="flex-1 overflow-y-auto overflow-x-hidden px-1.5 py-4"
                    >
                        {dashboard && (
                            <>
                                <div className={`px-3 py-1.5 text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400 ${collapsed ? 'lg:hidden' : ''}`}>
                                    <div className="flex items-center gap-1.5"><Activity size={12} /><span>Tổng quan</span></div>
                                </div>
                                <MenuItemComponent item={dashboard} />
                            </>
                        )}

                        {managementItems.length > 0 && (
                            <>
                                <div className={`mt-4 px-3 py-1.5 text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400 ${collapsed ? 'lg:hidden' : ''}`}>
                                    <div className="flex items-center gap-1.5"><Zap size={12} /><span>Quản lý</span></div>
                                </div>
                                {managementItems.map(item => <MenuItemComponent key={item.key} item={item} />)}
                            </>
                        )}

                        {profile && (
                            <>
                                <div className={`mt-4 px-3 py-1.5 text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400 ${collapsed ? 'lg:hidden' : ''}`}>
                                    <div className="flex items-center gap-1.5"><FileText size={12} /><span>Hệ thống</span></div>
                                </div>
                                <MenuItemComponent item={profile} />
                            </>
                        )}
                    </nav>

                    <div className="border-t border-gray-200 bg-gradient-to-r from-transparent to-gray-50/30 p-3 dark:border-gray-700 dark:to-gray-800/30">
                        <button
                            type="button"
                            onClick={toggleCollapsed}
                            className="hidden w-full items-center justify-center rounded-lg px-3 py-2.5 text-gray-600 transition-all duration-300 hover:scale-105 hover:bg-gray-100 hover:text-blue-500 dark:text-gray-400 dark:hover:bg-gray-800 dark:hover:text-blue-400 lg:flex"
                            title={collapsed ? 'Mở rộng menu' : 'Thu gọn menu'}
                        >
                            <Menu size={18} />
                            {!collapsed && <span className="ml-2 text-sm font-medium">Thu gọn menu</span>}
                        </button>
                    </div>
                </div>
            </aside>
        </>
    );
}
