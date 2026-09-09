import { useCallback, useEffect, useRef, useState } from 'react';
import axios from 'axios';
import { message } from 'antd';
import { base } from './shared';
import type { Paged } from './types';

/**
 * Fetches one tab's page from its own endpoint.
 *
 * Tabs are only mounted while open, so nothing here runs for a tab the operator never looks at.
 * Stale responses are dropped by request id, which matters when someone types in the search box.
 */
export function usePagedTab<T>(path: string, errorText: string, dataVersion = 0) {
    const [rows, setRows] = useState<Paged<T>>({ data: [], total: 0, page: 1, perPage: 20 });
    const [loading, setLoading] = useState(false);
    const [filters, setFilters] = useState<Record<string, unknown>>({});
    const request = useRef(0);
    const firstLoad = useRef(true);
    const appliedFilters = useRef<Record<string, unknown>>({});

    const load = useCallback(
        async (params: Record<string, unknown> = filters, page = 1, quiet = false) => {
            const id = ++request.current;
            if (!quiet) setLoading(true);
            try {
                const all: Record<string, unknown> = { ...params, page };
                const clean = Object.fromEntries(
                    Object.entries(all).filter(([, v]) => v !== undefined && v !== null && v !== ''),
                );
                const { data } = await axios.get(`${base}${path}`, { params: clean });
                if (id === request.current) setRows(data);
            } catch {
                if (id === request.current && !quiet) message.error(errorText);
            } finally {
                if (id === request.current) setLoading(false);
            }
        },
        [path, errorText, filters],
    );

    // First run is the tab's initial page-1 fetch (a tab only mounts when first opened). Later runs
    // come from dataVersion changing, i.e. an action elsewhere on the page wrote something: refresh
    // the page being viewed so the table cannot disagree with the count in the tab label.
    // Deliberately keyed on dataVersion alone; load/filters/rows are read fresh from this render.
    useEffect(() => {
        if (firstLoad.current) {
            firstLoad.current = false;
            void load({}, 1);
            return;
        }
        void load(appliedFilters.current, rows.page);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [dataVersion]);

    const apply = (next: Record<string, unknown> = filters, page = 1) => {
        appliedFilters.current = next;
        setFilters(next);
        void load(next, page);
    };

    return { rows, loading, filters, setFilters, apply, reload: () => void load(appliedFilters.current, rows.page) };
}
