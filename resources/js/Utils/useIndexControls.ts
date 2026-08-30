import { useState, useCallback } from 'react';
import { router, usePage } from '@inertiajs/react';

interface UseIndexControlsOptions {
    /** Tên route Inertia (ví dụ 'admin.users.index') */
    routeName: string;
    /** Key trong page props dùng để partial-reload (ví dụ 'users', 'posts', ...) */
    resourceKey: string;
    /** Tên query param dùng cho search (mặc định: 'search') */
    searchParamKey?: string;
}

export function useIndexControls({
    routeName,
    resourceKey,
    searchParamKey = 'search',
}: UseIndexControlsOptions) {
    // Lấy meta.pagination (có per_page, current_page, total, v.v.) từ props: props[resourceKey].meta
    const page = usePage<any>();
    const meta = page.props[resourceKey]?.meta;

    // searchText quản lý ô tìm kiếm
    const [searchText, setSearchText] = useState<string>('');

    // Khi nhấn Enter hoặc click Search
    const onSearch = useCallback(() => {
        router.get(
            routeName,
            {
                [searchParamKey]: searchText,
                page: 1,
                per_page: meta?.per_page,
            },
            { preserveState: true, replace: true }
        );
    }, [routeName, searchParamKey, searchText, meta?.per_page]);

    // Reset: clear searchText và gọi lại page 1 không có param search
    const onReset = useCallback(() => {
        setSearchText('');
        router.get(
            routeName,
            { page: 1, per_page: meta?.per_page },
            { preserveState: true, replace: true }
        );
    }, [routeName, meta?.per_page]);

    // Partial reload: chỉ fetch lại resourceKey (ví dụ 'users') mà không thay đổi URL hay query
    const onReload = useCallback(() => {
        router.reload({ only: [resourceKey] });
    }, [resourceKey]);

    return {
        searchText,
        setSearchText,
        onSearch,
        onReset,
        onReload,
        pagination: meta, // để dùng nếu cần hiển thị pagination
    };
}
