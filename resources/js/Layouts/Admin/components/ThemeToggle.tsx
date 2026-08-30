// resources/js/Layouts/Admin/components/ThemeToggle.tsx
import React from 'react';
import { useTheme } from '@/Providers/ThemeProvider';
import { Sun, Moon, Loader2 } from 'lucide-react';

export default function ThemeToggleSimple() {
    const { darkMode, toggleDarkMode, isLoading } = useTheme();

    // ✅ Show loading state briefly
    if (isLoading) {
        return (
            <div className="p-2 sm:p-2.5 rounded-xl bg-gray-100 dark:bg-gray-800">
                <Loader2 size={18} className="sm:w-5 sm:h-5 text-gray-400 animate-spin" />
            </div>
        );
    }

    return (
        <button
            onClick={toggleDarkMode}
            className="p-2 sm:p-2.5 rounded-xl bg-gray-100 dark:bg-gray-800 hover:bg-gray-200 dark:hover:bg-gray-700 transition-all duration-200 group"
            title={darkMode ? 'Chuyển sang chế độ sáng' : 'Chuyển sang chế độ tối'}
            aria-label={darkMode ? 'Chuyển sang chế độ sáng' : 'Chuyển sang chế độ tối'}
        >
            {darkMode ? (
                <Sun
                    size={18}
                    className="sm:w-5 sm:h-5 text-yellow-500 group-hover:text-yellow-400 transition-colors"
                    aria-hidden="true"
                />
            ) : (
                <Moon
                    size={18}
                    className="sm:w-5 sm:h-5 text-gray-600 dark:text-gray-400 group-hover:text-blue-500 transition-colors"
                    aria-hidden="true"
                />
            )}
        </button>
    );
}