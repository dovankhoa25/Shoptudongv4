// resources/js/Layouts/AdminLayout.tsx - With Footer
import React, { useState } from 'react';
import Sidebar from './Admin/Sidebar';
import Header from './Admin/Header';
import Footer from './Admin/Footer';
import { PageProps as InertiaPageProps } from '@/types';
import { ThemeProvider } from '@/Providers/ThemeProvider';
import { TabsProvider } from '@/Contexts/TabsContext';
import AdminRealtimeProvider from './Admin/AdminRealtimeProvider';
import { ConfigProvider, theme as antdTheme } from 'antd';
import { useTheme } from '@/Providers/ThemeProvider';

interface AdminLayoutProps {
    title: string;
    children: React.ReactNode;
}

function AdminLayoutContent({ children, title }: { children: React.ReactNode; title: string }) {
    const [sidebarOpen, setSidebarOpen] = useState(false);
    const { darkMode } = useTheme();

    return (
        <ConfigProvider
            theme={{
                algorithm: darkMode ? antdTheme.darkAlgorithm : antdTheme.defaultAlgorithm,
                token: {
                    zIndexPopupBase: 10000,
                },
            }}
        >
        <div className="admin-shell min-h-screen bg-slate-50 text-slate-900 dark:bg-slate-950 dark:text-slate-100 relative overflow-hidden transition-colors duration-300">
            {/* Background effects - giữ nguyên */}
            <div className="fixed top-0 left-0 w-full h-full overflow-hidden pointer-events-none">
                <div className="absolute -top-40 -right-40 w-96 h-96 rounded-full blur-3xl opacity-30 transition-all duration-1000 bg-gradient-to-br from-blue-300/20 to-indigo-400/20 animate-pulse"></div>
                <div className="absolute -bottom-40 -left-40 w-80 h-80 rounded-full blur-3xl opacity-25 transition-all duration-1000 delay-500 bg-gradient-to-br from-violet-300/20 to-purple-400/20 animate-pulse"></div>
                <div className="absolute top-1/2 left-1/2 transform -translate-x-1/2 -translate-y-1/2 w-64 h-64 rounded-full blur-2xl opacity-20 transition-all duration-1000 delay-1000 bg-gradient-to-r from-emerald-300/15 to-teal-400/15 animate-pulse"></div>
            </div>

            <div className="flex h-screen relative z-10">
                <Sidebar
                    isOpen={sidebarOpen}
                    onClose={() => setSidebarOpen(false)}
                />

                <div className="flex-1 flex flex-col min-w-0">
                    <Header title={title} onMenuClick={() => setSidebarOpen(true)} isMenuCollapsed={!sidebarOpen} />

                    {/* Main content - ĐIỀU CHỈNH PADDING */}
                    <div className="flex-1 flex flex-col overflow-auto">
                        <main className="flex-1 min-w-0 p-2 sm:p-3 lg:p-5">
                            <div className="w-full min-w-0">
                                {children}
                            </div>
                        </main>

                        <Footer />
                    </div>
                </div>
            </div>
        </div>
        </ConfigProvider>
    );
}

export default function AdminLayout({ children, title }: AdminLayoutProps) {
    return (
        <ThemeProvider>
            <TabsProvider>
                <AdminRealtimeProvider>
                    <AdminLayoutContent title={title}>{children}</AdminLayoutContent>
                </AdminRealtimeProvider>
            </TabsProvider>
        </ThemeProvider>
    );
}

export interface DashboardPageProps extends InertiaPageProps {
    stats: { totalUsers: number; totalOrders: number; totalRevenue: number; activeGames: number; };
}

export interface UsersPageProps extends InertiaPageProps {
    users: { data: any[]; meta: { current_page: number; last_page: number; per_page: number; total: number; }; };
}
