// resources/js/Layouts/Admin/components/SearchBar.tsx - Compact Version
import React, { useState, useEffect } from 'react';
import { router } from '@inertiajs/react';
import { Search, User, Settings, TrendingUp } from 'lucide-react';

const mockSearchResults = [
    { type: 'user', title: 'Nguyễn Văn A', subtitle: 'Admin • nguyenvana@example.com', href: '/admin/users/1' },
    { type: 'game', title: 'Free Fire VIP', subtitle: 'Game • 1,234 tài khoản có sẵn', href: '/admin/games/1' },
    { type: 'order', title: 'Đơn hàng #12345', subtitle: 'Hoàn thành • ₫150,000', href: '/admin/orders/12345' },
];

export default function SearchBar() {
    const [searchFocused, setSearchFocused] = useState(false);
    const [searchValue, setSearchValue] = useState('');

    useEffect(() => {
        const handleKeyDown = (event: KeyboardEvent) => {
            if ((event.metaKey || event.ctrlKey) && event.key === 'k') {
                event.preventDefault();
                const searchInput = document.getElementById('global-search') as HTMLInputElement;
                searchInput?.focus();
                setSearchFocused(true);
            }
            if (event.key === 'Escape') {
                setSearchFocused(false);
                setSearchValue('');
            }
        };

        document.addEventListener('keydown', handleKeyDown);
        return () => document.removeEventListener('keydown', handleKeyDown);
    }, []);

    const handleSearch = (href: string) => {
        router.visit(href);
        setSearchValue('');
        setSearchFocused(false);
    };

    return (
        <div className="relative flex-1 max-w-sm">
            {/* Compact search container */}
            <div className={`
                flex items-center space-x-2 px-3 py-1.5 rounded-lg transition-all duration-200 border backdrop-blur-sm
                ${searchFocused
                    ? 'bg-white dark:bg-gray-700 border-blue-500 shadow-md ring-1 ring-blue-500/20'
                    : 'bg-gray-100/80 dark:bg-gray-800/80 border-gray-200/50 dark:border-gray-600/50 hover:bg-white dark:hover:bg-gray-700 hover:border-gray-300'
                }
            `}>
                {/* Search Icon */}
                <Search size={14} className={`transition-all duration-200 ${searchFocused
                        ? 'text-blue-500 dark:text-blue-400'
                        : 'text-gray-400 dark:text-gray-500'
                    }`} />

                <input
                    id="global-search"
                    type="text"
                    placeholder="Tìm kiếm..."
                    value={searchValue}
                    onChange={(e) => setSearchValue(e.target.value)}
                    className="flex-1 border-none bg-transparent outline-none shadow-none ring-0 focus:outline-none focus:border-none focus:ring-0 focus:shadow-none transition-all duration-200 text-gray-700 dark:text-gray-200 placeholder:text-gray-400 dark:placeholder:text-gray-500 text-sm"
                    onFocus={() => setSearchFocused(true)}
                    onBlur={() => setTimeout(() => setSearchFocused(false), 200)}
                />

                {/* Compact keyboard shortcuts */}
                <div className="hidden md:flex items-center space-x-0.5">
                    <kbd className={`px-1.5 py-0.5 text-xs rounded border transition-all duration-200 ${searchFocused
                            ? 'bg-blue-100 dark:bg-blue-900/50 text-blue-600 dark:text-blue-400 border-blue-200'
                            : 'bg-gray-200 dark:bg-gray-700 text-gray-500 dark:text-gray-400 border-gray-300'
                        }`}>
                        ⌘
                    </kbd>
                    <kbd className={`px-1.5 py-0.5 text-xs rounded border transition-all duration-200 ${searchFocused
                            ? 'bg-blue-100 dark:bg-blue-900/50 text-blue-600 dark:text-blue-400 border-blue-200'
                            : 'bg-gray-200 dark:bg-gray-700 text-gray-500 dark:text-gray-400 border-gray-300'
                        }`}>
                        K
                    </kbd>
                </div>
            </div>

            {/* Compact search results dropdown */}
            {searchFocused && searchValue && (
                <div className="absolute top-full left-0 right-0 mt-1 backdrop-blur-lg border border-gray-200 dark:border-gray-700 rounded-lg shadow-xl p-2 z-50 transition-all duration-200 bg-white/95 dark:bg-gray-800/95">
                    <div className="flex items-center justify-between mb-2">
                        <p className="text-xs text-gray-600 dark:text-gray-400">
                            "{searchValue}"
                        </p>
                        <span className="text-xs text-gray-500">
                            {mockSearchResults.filter(item =>
                                item.title.toLowerCase().includes(searchValue.toLowerCase()) ||
                                item.subtitle.toLowerCase().includes(searchValue.toLowerCase())
                            ).length}
                        </span>
                    </div>
                    <div className="space-y-1 max-h-48 overflow-y-auto">
                        {mockSearchResults.filter(item =>
                            item.title.toLowerCase().includes(searchValue.toLowerCase()) ||
                            item.subtitle.toLowerCase().includes(searchValue.toLowerCase())
                        ).map((item, index) => (
                            <button
                                key={index}
                                onClick={() => handleSearch(item.href)}
                                className="flex items-center p-2 rounded-md cursor-pointer group transition-all duration-200 w-full text-left hover:bg-gray-100 dark:hover:bg-gray-700/50"
                            >
                                <div className={`w-6 h-6 rounded-md flex items-center justify-center mr-2 ${item.type === 'user' ? 'bg-blue-100 dark:bg-blue-900/50 text-blue-600 dark:text-blue-400' :
                                        item.type === 'game' ? 'bg-purple-100 dark:bg-purple-900/50 text-purple-600 dark:text-purple-400' :
                                            'bg-green-100 dark:bg-green-900/50 text-green-600 dark:text-green-400'
                                    }`}>
                                    {item.type === 'user' ? <User size={12} /> :
                                        item.type === 'game' ? <Settings size={12} /> :
                                            <TrendingUp size={12} />}
                                </div>
                                <div className="flex-1 min-w-0">
                                    <p className="font-medium group-hover:text-blue-500 dark:group-hover:text-blue-400 transition-colors text-gray-700 dark:text-gray-200 text-xs truncate">
                                        {item.title}
                                    </p>
                                    <p className="text-xs text-gray-500 dark:text-gray-400 truncate">
                                        {item.subtitle}
                                    </p>
                                </div>
                            </button>
                        ))}

                        {mockSearchResults.filter(item =>
                            item.title.toLowerCase().includes(searchValue.toLowerCase()) ||
                            item.subtitle.toLowerCase().includes(searchValue.toLowerCase())
                        ).length === 0 && (
                                <div className="text-center py-4 text-gray-500 dark:text-gray-400">
                                    <Search size={16} className="mx-auto mb-1 opacity-50" />
                                    <p className="text-xs">Không tìm thấy</p>
                                </div>
                            )}
                    </div>
                </div>
            )}
        </div>
    );
}