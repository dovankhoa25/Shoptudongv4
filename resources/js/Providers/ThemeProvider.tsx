// resources/js/Providers/ThemeProvider.tsx
import React, { createContext, useContext, useState, useEffect, ReactNode } from 'react';

interface ThemeContextType {
    darkMode: boolean;
    toggleDarkMode: () => void;
    theme: 'light' | 'dark';
    isLoading: boolean;
}

const ThemeContext = createContext<ThemeContextType | undefined>(undefined);

interface ThemeProviderProps {
    children: ReactNode;
    storageKey?: string;
    defaultTheme?: 'light' | 'dark' | 'system';
}

export function ThemeProvider({
    children,
    storageKey = 'admin-theme',
    defaultTheme = 'system'
}: ThemeProviderProps) {
    const [darkMode, setDarkMode] = useState(false);
    const [mounted, setMounted] = useState(false);
    const [isLoading, setIsLoading] = useState(true);

    // ✅ FIX 1: Prevent FOUC - Load theme IMMEDIATELY
    useEffect(() => {
        // Run synchronously to prevent flash
        const initializeTheme = () => {
            try {
                // Try to get saved theme from localStorage
                const savedTheme = localStorage.getItem(storageKey);

                let shouldBeDark = false;

                if (savedTheme === 'dark' || savedTheme === 'light') {
                    // Use saved preference
                    shouldBeDark = savedTheme === 'dark';
                } else if (defaultTheme === 'system') {
                    // Use system preference
                    shouldBeDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
                } else {
                    // Use default theme
                    shouldBeDark = defaultTheme === 'dark';
                }

                setDarkMode(shouldBeDark);

                // Apply immediately to prevent flash
                if (shouldBeDark) {
                    document.documentElement.classList.add('dark');
                } else {
                    document.documentElement.classList.remove('dark');
                }
            } catch (error) {
                // ✅ FIX 2: Handle localStorage errors (privacy mode, disabled storage)
                console.warn('Theme: localStorage not available, using default theme', error);
                const shouldBeDark = defaultTheme === 'dark' ||
                    (defaultTheme === 'system' && window.matchMedia('(prefers-color-scheme: dark)').matches);
                setDarkMode(shouldBeDark);
                document.documentElement.classList.toggle('dark', shouldBeDark);
            } finally {
                setIsLoading(false);
                setMounted(true);
            }
        };

        initializeTheme();
    }, [storageKey, defaultTheme]);

    // ✅ FIX 3: Listen to system theme changes
    useEffect(() => {
        if (!mounted) return;

        try {
            const savedTheme = localStorage.getItem(storageKey);

            // Only listen if user hasn't set preference
            if (savedTheme !== 'dark' && savedTheme !== 'light') {
                const mediaQuery = window.matchMedia('(prefers-color-scheme: dark)');

                const handleChange = (e: MediaQueryListEvent) => {
                    setDarkMode(e.matches);
                    document.documentElement.classList.toggle('dark', e.matches);
                };

                mediaQuery.addEventListener('change', handleChange);
                return () => mediaQuery.removeEventListener('change', handleChange);
            }
        } catch (error) {
            console.warn('Theme: Could not listen to system theme changes', error);
        }
    }, [mounted, storageKey]);

    // Toggle theme
    const toggleDarkMode = () => {
        const newMode = !darkMode;
        setDarkMode(newMode);

        // Apply class immediately
        document.documentElement.classList.toggle('dark', newMode);

        // ✅ FIX 4: Try-catch for localStorage
        try {
            localStorage.setItem(storageKey, newMode ? 'dark' : 'light');
        } catch (error) {
            console.warn('Theme: Could not save to localStorage', error);
            // Continue working even if storage fails
        }
    };

    // ✅ FIX 5: Don't block rendering, but prevent hydration mismatch
    const value: ThemeContextType = {
        darkMode,
        toggleDarkMode,
        theme: darkMode ? 'dark' : 'light',
        isLoading
    };

    return (
        <ThemeContext.Provider value={value}>
            {children}
        </ThemeContext.Provider>
    );
}

export function useTheme() {
    const context = useContext(ThemeContext);
    if (!context) {
        throw new Error('useTheme must be used within ThemeProvider');
    }
    return context;
}

// ✅ BONUS: Hook to check if theme is ready
export function useThemeReady() {
    const { isLoading } = useTheme();
    return !isLoading;
}