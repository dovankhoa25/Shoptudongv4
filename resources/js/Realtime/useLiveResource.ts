import { useCallback, useEffect, useRef, useState } from 'react';
import { usePage } from '@inertiajs/react';
import axios from 'axios';
import { useLiveView } from './useLiveView';

type Resource<T> = { key: string; data: T | null; loading: boolean; error: string | null };

/** Initial/manual reads remain usable when realtime registration or sockets fail. */
export function useLiveResource<T>(url: string | null, errorText: string) {
    const auth = usePage().props.auth as any;
    const key = JSON.stringify([auth?.user?.id, auth?.realtime_channel, url]);
    const [resource, setResource] = useState<Resource<T>>({ key, data: null, loading: !!url, error: null });
    const current = useRef(key);
    current.current = key;
    const request = useRef(0);
    const pending = useRef<AbortController | null>(null);
    const message = useRef(errorText);
    message.current = errorText;

    const replace = useCallback((data: T) => {
        if (current.current !== key) return;
        ++request.current;
        pending.current?.abort();
        setResource({ key, data, loading: false, error: null });
    }, [key]);

    const read = useCallback(async () => {
        if (!url || current.current !== key) return;
        const id = ++request.current;
        pending.current?.abort();
        const controller = new AbortController();
        pending.current = controller;
        setResource(previous => ({ key, data: previous.key === key ? previous.data : null, loading: true,
            error: previous.key === key ? previous.error : null }));
        try {
            const response = await axios.get<T>(url, { signal: controller.signal, headers: { Accept: 'application/json' } });
            if (controller.signal.aborted || id !== request.current || current.current !== key) return;
            // A proxy/login error page can arrive with HTTP 200. Never treat HTML as rows.
            if (!response.data || typeof response.data !== 'object') throw new Error('Invalid JSON response');
            setResource({ key, data: response.data, loading: false, error: null });
        } catch (error: any) {
            if (controller.signal.aborted || id !== request.current || current.current !== key) return;
            const status = error.response?.status;
            const detail = status === 401 ? 'Phiên đăng nhập đã hết hạn.'
                : status === 403 ? 'Yêu cầu bị từ chối (403). Kiểm tra quyền truy cập hoặc cấu hình bảo vệ.'
                : status === 429 ? 'Đang có quá nhiều yêu cầu. Vui lòng thử lại sau.' : 'Bấm Làm mới để thử lại.';
            setResource(previous => ({ key, data: [401, 403, 404].includes(status) ? null : previous.key === key ? previous.data : null,
                loading: false, error: `${message.current}. ${detail}` }));
        }
    }, [key, url]);

    useEffect(() => {
        if (url) void read();
        return () => { ++request.current; pending.current?.abort(); };
    }, [read, url]);

    const visible = resource.key === key ? resource : { key, data: null, loading: !!url, error: null };
    // Do not add registration requests when the ordinary, authorized read already failed.
    const live = useLiveView<T>(url && visible.data !== null && !visible.error ? url : null, replace);
    const reload = useCallback(() => {
        if (live.status === 'live') live.sync(); else void read();
    }, [read, live.status, live.sync]);
    return {
        data: url ? visible.data : null,
        loading: !!url && visible.loading,
        error: url ? visible.error : null,
        warning: url && visible.data !== null && ['offline', 'denied'].includes(live.status)
            ? 'Cập nhật trực tiếp đang gián đoạn. Bấm Làm mới để lấy dữ liệu mới nhất.' : null,
        reload,
        replace,
    };
}
