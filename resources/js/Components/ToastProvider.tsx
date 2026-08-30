import React, { useCallback, useMemo } from 'react';
import { App as AntdApp } from 'antd';

type ToastType = 'success' | 'error' | 'warning' | 'info';

interface ToastApi {
    success: (content: React.ReactNode, durationMs?: number) => void;
    error: (content: React.ReactNode, durationMs?: number) => void;
    warning: (content: React.ReactNode, durationMs?: number) => void;
    info: (content: React.ReactNode, durationMs?: number) => void;
}

/**
 * Adapter dùng Ant Design message cho toàn bộ các màn hình cũ.
 * Giữ tên useToast để các call site không phải duy trì hai hệ thống thông báo.
 */
export function useToast(): ToastApi {
    const { message } = AntdApp.useApp();

    const open = useCallback((type: ToastType, content: React.ReactNode, durationMs?: number) => {
        void message.open({
            type,
            content,
            duration: durationMs === undefined ? undefined : durationMs / 1000,
        });
    }, [message]);

    return useMemo(() => ({
        success: (content: React.ReactNode, durationMs?: number) => open('success', content, durationMs),
        error: (content: React.ReactNode, durationMs?: number) => open('error', content, durationMs),
        warning: (content: React.ReactNode, durationMs?: number) => open('warning', content, durationMs),
        info: (content: React.ReactNode, durationMs?: number) => open('info', content, durationMs),
    }), [open]);
}

export function ToastProvider({ children }: { children: React.ReactNode }) {
    return (
        <AntdApp
            component={false}
            message={{
                duration: 4,
                maxCount: 5,
                top: 24,
            }}
        >
            {children}
        </AntdApp>
    );
}
