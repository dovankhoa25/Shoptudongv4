// resources/js/Layouts/Admin/Header.tsx - Simple version
import React from 'react';
import { usePage } from '@inertiajs/react';
import { PageProps } from '@/types';
import NotificationDropdown from './components/NotificationDropdown';
import UserDropdown from './components/UserDropdown';
import ThemeToggle from './components/ThemeToggle';

interface HeaderProps {
    title: string;
    onMenuClick: () => void;
    isMenuCollapsed?: boolean;
}

export default function Header({ title, onMenuClick, isMenuCollapsed = false }: HeaderProps) {
    const { props } = usePage<PageProps>();

    const notifications = props.notifications || [];
    const user = props.auth.user;
    const roles = props.auth.roles;

    return (
        <header className="sticky top-0 z-30 p-2 pb-0">
                <div className="backdrop-blur-lg border border-white/20 dark:border-slate-700/50 rounded-xl shadow-lg transition-all duration-300 bg-white/95 dark:bg-slate-900/95 overflow-hidden">
                    <div className="flex items-center justify-between px-3 py-2">
                        <div className="flex items-center space-x-2 flex-1 min-w-0">
                            <button
                                onClick={onMenuClick}
                                className="lg:hidden p-1.5 rounded-lg transition-all duration-200 group hover:bg-slate-100 dark:hover:bg-slate-800 flex-shrink-0"
                            >
                                <div className="relative w-4 h-4">
                                    <span className={`absolute left-0 top-0.5 w-4 h-0.5 transition-all duration-200 transform origin-center ${isMenuCollapsed ? 'rotate-45 translate-y-1.5' : 'rotate-0 translate-y-0'
                                        } bg-slate-500 dark:bg-slate-400 group-hover:bg-blue-500`}></span>
                                    <span className={`absolute left-0 top-2 w-4 h-0.5 transition-all duration-200 ${isMenuCollapsed ? 'opacity-0 scale-0' : 'opacity-100 scale-100'
                                        } bg-slate-500 dark:bg-slate-400 group-hover:bg-blue-500`}></span>
                                    <span className={`absolute left-0 top-3.5 w-4 h-0.5 transition-all duration-200 transform origin-center ${isMenuCollapsed ? '-rotate-45 -translate-y-1.5' : 'rotate-0 translate-y-0'
                                        } bg-slate-500 dark:bg-slate-400 group-hover:bg-blue-500`}></span>
                                </div>
                            </button>

                            <div className="min-w-0 flex-1">
                                <p className="truncate text-sm font-semibold text-slate-800 dark:text-slate-100">{title}</p>
                                <p className="hidden truncate text-xs text-slate-500 dark:text-slate-400 sm:block">NROCHECK Admin</p>
                            </div>
                        </div>

                        <div className="flex items-center space-x-1 flex-shrink-0">
                            <ThemeToggle />
                            <NotificationDropdown notifications={notifications} compact />
                            <UserDropdown user={user} roles={roles} compact />
                        </div>
                    </div>
                </div>
        </header>
    );
}
