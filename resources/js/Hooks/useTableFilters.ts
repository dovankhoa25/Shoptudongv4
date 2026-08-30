import { router } from '@inertiajs/react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';

interface UseTableFiltersOptions {
    routeName: string;
    initialFilters?: object;
    initialData: {
        meta: {
            current_page: number;
            per_page: number;
            total: number;
        };
    };
    debounceMs?: number;
    /**
     * Prefixes every query param this hook reads/sends (e.g. paramPrefix="license_" turns
     * "search"/"page"/"per_page" into "license_search"/"license_page"/"license_per_page").
     * Lets several independently-paginated tables share a single Inertia page/route without
     * their query params colliding. Defaults to '' (unprefixed), matching the original behaviour.
     */
    paramPrefix?: string;
    /**
     * Inertia partial-reload prop keys (passed through as the visit's `only` option). When set,
     * the server only re-evaluates/returns these props instead of the whole page — see
     * ToolManagementController::index(), which wraps each tab's dataset in a closure so the other
     * tabs' queries aren't even run when a single tab is being refreshed.
     */
    only?: string[];
}

type DebouncedFunction<T extends (...args: any[]) => void> =
    ((...args: Parameters<T>) => void) & { cancel: () => void };

function debounce<T extends (...args: any[]) => void>(callback: T, wait: number): DebouncedFunction<T> {
    let timeout: ReturnType<typeof setTimeout> | undefined;
    const debounced = (...args: Parameters<T>) => {
        if (timeout) clearTimeout(timeout);
        timeout = setTimeout(() => callback(...args), wait);
    };
    debounced.cancel = () => {
        if (timeout) clearTimeout(timeout);
    };
    return debounced;
}

export function useTableFilters({
    routeName,
    initialFilters = {},
    initialData,
    debounceMs = 500,
    paramPrefix = '',
    only,
}: UseTableFiltersOptions) {
    const initialFilterValues = initialFilters as Record<string, unknown>;
    const prefixed = useCallback((key: string) => `${paramPrefix}${key}`, [paramPrefix]);
    // Callers pass `only` as an inline array literal (e.g. `only: ['licenses']`), which is a brand new
    // array reference on every render. Depending on that reference directly would make this memo -- and
    // everything chained off it below (requestFilteredData, the auto-search effect, handlePageChange,
    // handleResetFilters) -- recompute on every single render, not just when the filters actually change.
    // That fires a fresh debounced router.get() on every render, which updates props, which re-renders,
    // which fires again: a continuous auto-refresh loop. Depend on the serialized *content* instead so it
    // stays referentially stable across renders when the requested prop keys haven't actually changed.
    const onlyKey = only?.join(',') ?? '';
    const visitOptions = useMemo(() => (only && only.length ? { only } : {}), [onlyKey]);

    const [filters, setFilters] = useState<Record<string, any>>({
        ...initialFilterValues,
        search: typeof initialFilterValues.search === 'string' ? initialFilterValues.search : '',
    });
    const [columnFilters, setColumnFilters] = useState<Record<string, any>>(() =>
        Object.fromEntries(Object.entries(initialFilterValues).filter(([key]) => key !== 'search')),
    );
    const [loading, setLoading] = useState(false);
    const mounted = useRef(false);
    const suppressNextFilterRequest = useRef(false);

    const buildParams = useCallback(() => {
        const params: Record<string, any> = {};
        if (filters.search?.trim()) params[prefixed('search')] = filters.search.trim();

        Object.entries(columnFilters).forEach(([key, value]) => {
            if (Array.isArray(value)) {
                if (value.length) params[prefixed(key)] = value.length === 1 ? value[0] : value;
            } else if (value !== null && value !== undefined && value !== '') {
                params[prefixed(key)] = value;
            }
        });
        return params;
    }, [columnFilters, filters.search, prefixed]);

    const requestFilteredData = useMemo(
        () => debounce((params: Record<string, any>) => {
            router.get(route(routeName), {
                ...params,
                [prefixed('page')]: 1,
                [prefixed('per_page')]: initialData.meta.per_page,
            }, {
                preserveState: true,
                replace: true,
                ...visitOptions,
                onStart: () => setLoading(true),
                onFinish: () => setLoading(false),
            });
        }, debounceMs),
        [debounceMs, initialData.meta.per_page, prefixed, routeName, visitOptions],
    );

    useEffect(() => {
        if (!mounted.current) {
            mounted.current = true;
            return;
        }
        if (suppressNextFilterRequest.current) {
            suppressNextFilterRequest.current = false;
            return;
        }
        requestFilteredData(buildParams());
        return requestFilteredData.cancel;
    }, [buildParams, requestFilteredData]);

    useEffect(() => {
        const removeStartListener = router.on('start', () => setLoading(true));
        const removeFinishListener = router.on('finish', () => setLoading(false));
        return () => {
            removeStartListener();
            removeFinishListener();
        };
    }, []);

    const handleSearch = useCallback((search: string) => {
        setFilters(previous => ({ ...previous, search }));
    }, []);

    const handlePageChange = useCallback((page: number, pageSize: number) => {
        requestFilteredData.cancel();
        router.get(route(routeName), {
            ...buildParams(),
            [prefixed('page')]: page,
            [prefixed('per_page')]: pageSize,
        }, {
            preserveState: true,
            replace: true,
            ...visitOptions,
            onStart: () => setLoading(true),
            onFinish: () => setLoading(false),
        });
    }, [buildParams, prefixed, requestFilteredData, routeName, visitOptions]);

    const handleResetFilters = useCallback(() => {
        requestFilteredData.cancel();
        suppressNextFilterRequest.current = true;
        setFilters({ search: '' });
        setColumnFilters({});
        router.get(route(routeName), {
            [prefixed('page')]: 1,
            [prefixed('per_page')]: initialData.meta.per_page,
        }, {
            preserveState: true,
            replace: true,
            ...visitOptions,
            onStart: () => setLoading(true),
            onFinish: () => setLoading(false),
        });
    }, [initialData.meta.per_page, prefixed, requestFilteredData, routeName, visitOptions]);

    return {
        filters,
        columnFilters,
        loading,
        handleSearch,
        handleResetFilters,
        handlePageChange,
        setColumnFilters,
        setLoading,
    };
}
