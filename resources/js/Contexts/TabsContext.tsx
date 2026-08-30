// resources/js/Contexts/TabsContext.tsx - Simple tabs without cache
import React, { createContext, useContext, useState, useEffect, ReactNode } from 'react';
import { usePage, router } from '@inertiajs/react';

interface Tab {
    id: string;
    label: string;
    href: string;
    timestamp?: number;
}

interface TabsContextType {
    tabs: Tab[];
    activeTabId: string;
    addTab: (tab: Tab) => void;
    removeTab: (tabId: string) => void;
    navigateToTab: (tabId: string) => void;
}

const TabsContext = createContext<TabsContextType | undefined>(undefined);

export const useTabs = () => {
    const context = useContext(TabsContext);
    if (!context) {
        throw new Error('useTabs must be used within TabsProvider');
    }
    return context;
};

interface TabsProviderProps {
    children: ReactNode;
}

export const TabsProvider: React.FC<TabsProviderProps> = ({ children }) => {
    const [tabs, setTabs] = useState<Tab[]>([]);
    const [activeTabId, setActiveTabId] = useState<string>('');
    const { url } = usePage();

    // Load tabs from sessionStorage
    useEffect(() => {
        const savedTabs = sessionStorage.getItem('admin-tabs');
        if (savedTabs) {
            try {
                setTabs(JSON.parse(savedTabs));
            } catch (error) {
                console.error('Error loading tabs:', error);
            }
        }
    }, []);

    // Save tabs to sessionStorage
    useEffect(() => {
        if (tabs.length > 0) {
            sessionStorage.setItem('admin-tabs', JSON.stringify(tabs));
        }
    }, [tabs]);

    // Auto add current page as tab
    useEffect(() => {
        const currentTab = getTabInfoFromUrl(url);
        if (currentTab) {
            addTab(currentTab);
            setActiveTabId(currentTab.id);
        }
    }, [url]);

    const addTab = (newTab: Tab) => {
        setTabs(prevTabs => {
            const existingIndex = prevTabs.findIndex(tab => tab.id === newTab.id);

            if (existingIndex !== -1) {
                return prevTabs;
            }

            const newTabs = [...prevTabs, { ...newTab, timestamp: Date.now() }];

            // Limit to 10 tabs
            if (newTabs.length > 10) {
                const nonDashboard = newTabs.filter(t => t.id !== 'dashboard');
                if (nonDashboard.length > 0) {
                    const sorted = [...nonDashboard].sort((a, b) => (a.timestamp || 0) - (b.timestamp || 0));
                    return newTabs.filter(t => t.id !== sorted[0].id);
                }
            }

            return newTabs;
        });
    };

    const removeTab = (tabId: string) => {
        if (tabId === 'dashboard') return;

        setTabs(prevTabs => {
            const filtered = prevTabs.filter(tab => tab.id !== tabId);

            if (activeTabId === tabId && filtered.length > 0) {
                const lastTab = filtered[filtered.length - 1];
                router.visit(lastTab.href);
            }

            return filtered;
        });
    };

    const navigateToTab = (tabId: string) => {
        const tab = tabs.find(t => t.id === tabId);
        if (!tab) return;

        setActiveTabId(tabId);
        router.visit(tab.href);
    };

    return (
        <TabsContext.Provider value={{
            tabs,
            activeTabId,
            addTab,
            removeTab,
            navigateToTab,
        }}>
            {children}
        </TabsContext.Provider>
    );
};

function getTabInfoFromUrl(url: string): Tab | null {
    const routeMap: Record<string, Omit<Tab, 'timestamp'>> = {
        '/admin': { id: 'dashboard', label: 'Dashboard', href: '/admin' },
        '/admin/users': { id: 'users', label: 'Người dùng', href: '/admin/users' },
        '/admin/users/ctv': { id: 'users-ctv', label: 'CTV', href: '/admin/users/ctv' },
        '/admin/roles': { id: 'roles', label: 'Vai trò', href: '/admin/roles' },
        '/admin/cardtypes': { id: 'cardtypes', label: 'Loại thẻ', href: '/admin/cardtypes' },
        '/admin/cards': { id: 'cards', label: 'Thẻ cào', href: '/admin/cards' },
        '/admin/transactions': { id: 'transactions', label: 'Giao dịch', href: '/admin/transactions' },
        '/admin/withdrawals': { id: 'withdrawals', label: 'Rút tiền', href: '/admin/withdrawals' },
        '/admin/games/gametypes': { id: 'game-types', label: 'Loại Game', href: '/admin/games/gametypes' },
        '/admin/games/categories': { id: 'game-categories', label: 'Danh mục', href: '/admin/games/categories' },
        '/admin/games/attributes': { id: 'game-attributes', label: 'Thuộc tính', href: '/admin/games/attributes' },
        '/admin/games/category-attributes': { id: 'game-categories-attributes', label: 'Thuộc tính DM', href: '/admin/games/category-attributes' },
        '/admin/games/accounts': { id: 'game-accounts', label: 'Tài khoản', href: '/admin/games/accounts' },
        '/admin/games/accounts/history': { id: 'game-history', label: 'Lịch sử bán', href: '/admin/games/accounts/history' },
        '/admin/services': { id: 'services', label: 'Dịch vụ', href: '/admin/services' },
        '/admin/services/orders': { id: 'service-orders', label: 'Đơn thuê', href: '/admin/services/orders' },
        '/admin/services/orders/receiver': { id: 'don-cua-toi', label: 'Đơn nhận', href: '/admin/services/orders/receiver' },
        '/admin/fields': { id: 'fields', label: 'Trường TT', href: '/admin/fields' },
        '/admin/service-fields': { id: 'input-services', label: 'Trường DV', href: '/admin/service-fields' },
        '/admin/category-services': { id: 'category-services', label: 'DV Danh mục', href: '/admin/category-services' },
        '/admin/category-templates': { id: 'category-templates', label: 'Template', href: '/admin/category-templates' },
        '/admin/randombox': { id: 'random_boxes', label: 'Random Box', href: '/admin/randombox' },
        '/admin/random-nicks': { id: 'random_nicks', label: 'Random Nick', href: '/admin/random-nicks' },
        '/admin/spins': { id: 'spins', label: 'Vòng quay', href: '/admin/spins' },
        '/admin/spin-results': { id: 'spin-results', label: 'LS Spin', href: '/admin/spin-results' },
        '/admin/analytics': { id: 'analytics', label: 'Thống kê', href: '/admin/analytics' },
        '/admin/notifications': { id: 'notifications-list', label: 'Thông báo', href: '/admin/notifications' },
        '/admin/notifications/create': { id: 'notifications-create', label: 'Tạo TB', href: '/admin/notifications/create' },
        '/admin/website': { id: 'website', label: 'Website', href: '/admin/website' },
        '/admin/settings': { id: 'settings', label: 'Cài đặt', href: '/admin/settings' },
    };

    if (routeMap[url]) return routeMap[url];

    for (const [route, tab] of Object.entries(routeMap)) {
        if (url.startsWith(route) && route !== '/admin') {
            return tab;
        }
    }

    return null;
}