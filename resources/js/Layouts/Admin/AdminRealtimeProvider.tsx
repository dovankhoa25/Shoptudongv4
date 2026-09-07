import React, { useCallback, useEffect, useMemo, useRef } from 'react';
import { router, usePage } from '@inertiajs/react';
import { useEcho } from '@laravel/echo-react';
import type { PageProps } from '@/types';

interface AdminRealtimeEvent {
    resource: string;
    resource_id: number | string;
    action: 'created' | 'updated' | 'status_updated' | 'deleted' | string;
    status: string | null;
    message: string;
    occurred_at: string;
}

interface RealtimePathMatch {
    detailId?: string;
}

interface RealtimePageConfig {
    match: (path: string) => RealtimePathMatch | null;
    resources: readonly string[];
    reloadProps:
        | readonly string[]
        | ((event: AdminRealtimeEvent, route: RealtimePathMatch) => readonly string[]);
}

interface ResolvedRealtimePage {
    config: RealtimePageConfig;
    route: RealtimePathMatch;
}

const exact = (expected: string) => (path: string): RealtimePathMatch | null => (
    path === expected ? {} : null
);

const indexOrNumericDetail = (root: string) => (path: string): RealtimePathMatch | null => {
    if (path === root) return {};

    const detail = path.match(new RegExp(`^${root}/(\\d+)$`));

    return detail ? { detailId: detail[1] } : null;
};

const orderReloadProps = (_event: AdminRealtimeEvent, route: RealtimePathMatch) => (
    route.detailId ? ['order'] : ['orders', 'stats']
);

const gemOrderReloadProps = (_event: AdminRealtimeEvent, route: RealtimePathMatch) => (
    route.detailId ? ['order', 'relatedOrders'] : ['orders', 'stats']
);

const depositReloadProps = (event: AdminRealtimeEvent): readonly string[] => {
    if (event.resource === 'card_recharge') {
        return ['cards', 'stats', ...(
            ['created', 'deleted'].includes(event.action) ? ['cardTypes'] : []
        )];
    }

    return ['bankTopups', 'stats', ...(
        ['created', 'deleted'].includes(event.action) ? ['gateways'] : []
    )];
};

const realtimePages: readonly RealtimePageConfig[] = [
    {
        match: indexOrNumericDetail('/admin/orders'),
        resources: ['gold_order', 'legacy_gold_order'],
        reloadProps: orderReloadProps,
    },
    {
        match: indexOrNumericDetail('/admin/imports'),
        resources: ['gold_import', 'legacy_gold_import'],
        reloadProps: orderReloadProps,
    },
    {
        match: indexOrNumericDetail('/admin/gem-orders'),
        resources: ['gem_order'],
        reloadProps: gemOrderReloadProps,
    },
    {
        match: exact('/admin/services/orders'),
        resources: ['service_order'],
        reloadProps: ['service_orders'],
    },
    {
        match: exact('/admin/services/orders/receiver'),
        resources: ['service_order'],
        reloadProps: ['service_orders'],
    },
    {
        match: exact('/admin/games/accounts/history'),
        resources: ['nick_order'],
        reloadProps: ['orders', 'stats'],
    },
    {
        match: exact('/admin/withdrawals'),
        resources: ['withdrawal'],
        reloadProps: ['withdrawals', 'stats'],
    },
    {
        match: exact('/admin/cards'),
        resources: ['card_recharge'],
        reloadProps: ['cards'],
    },
    {
        match: exact('/admin/deposits'),
        resources: ['card_recharge', 'bank_deposit'],
        reloadProps: depositReloadProps,
    },
    {
        match: exact('/admin/carot-recharges'),
        resources: ['carot_recharge'],
        reloadProps: ['recharges', 'stats', 'statistics'],
    },
    {
        match: exact('/admin/transactions'),
        resources: ['balance_transaction', 'bank_deposit'],
        reloadProps: ['transactions'],
    },
    {
        match: exact('/admin/users'),
        resources: ['balance_transaction'],
        reloadProps: ['users'],
    },
    {
        match: exact('/admin/dashboard'),
        resources: ['gold_order', 'legacy_gold_order', 'gold_import', 'legacy_gold_import', 'gem_order'],
        reloadProps: ['servers', 'grandTotal'],
    },
    {
        match: exact('/admin/analytics'),
        resources: ['service_order', 'nick_order', 'random_order'],
        reloadProps: ['analytics', 'availableDates'],
    },
];

const recentlyHandledEvents = new Map<string, number>();
const duplicateWindowMs = 3_000;

function isDuplicateEvent(event: AdminRealtimeEvent): boolean {
    const fingerprint = [
        event.resource,
        event.resource_id,
        event.action,
        event.status ?? '',
        event.message,
        event.occurred_at,
    ].join('|');
    const now = Date.now();
    const handledAt = recentlyHandledEvents.get(fingerprint);

    for (const [key, timestamp] of recentlyHandledEvents) {
        if (now - timestamp > duplicateWindowMs) recentlyHandledEvents.delete(key);
    }

    if (handledAt !== undefined && now - handledAt <= duplicateWindowMs) return true;

    recentlyHandledEvents.set(fingerprint, now);

    return false;
}

function resolveRealtimePage(path: string): ResolvedRealtimePage | null {
    for (const config of realtimePages) {
        const route = config.match(path);

        if (route) return { config, route };
    }

    return null;
}

function RealtimeSubscription({
    children,
    page,
}: {
    children: React.ReactNode;
    page: ResolvedRealtimePage;
}) {
    const reloadTimer = useRef<ReturnType<typeof setTimeout> | null>(null);
    const pendingReloadProps = useRef(new Set<string>());

    const handleEvent = useCallback((event: AdminRealtimeEvent) => {
        if (!page.config.resources.includes(event.resource)) return;
        if (page.route.detailId && page.route.detailId !== String(event.resource_id)) return;
        if (isDuplicateEvent(event)) return;

        const reloadProps = typeof page.config.reloadProps === 'function'
            ? page.config.reloadProps(event, page.route)
            : page.config.reloadProps;

        reloadProps.forEach(prop => pendingReloadProps.current.add(prop));

        // Gộp các event đến gần nhau thành một partial reload, nhưng không trì hoãn
        // vô hạn khi hệ thống đang có nhiều giao dịch liên tục.
        if (reloadTimer.current) return;

        reloadTimer.current = setTimeout(() => {
            reloadTimer.current = null;
            const only = Array.from(pendingReloadProps.current);
            pendingReloadProps.current.clear();

            if (only.length > 0) router.reload({ only });
        }, 300);
    }, [page]);

    useEcho<AdminRealtimeEvent>('Admin.realtime', '.AdminEvent', handleEvent, [handleEvent]);

    useEffect(() => () => {
        if (reloadTimer.current) {
            clearTimeout(reloadTimer.current);
            reloadTimer.current = null;
        }
        pendingReloadProps.current.clear();
    }, [page]);

    return <>{children}</>;
}

export default function AdminRealtimeProvider({ children }: { children: React.ReactNode }) {
    const { props, url } = usePage<PageProps>();
    const roles = Array.isArray(props.auth.roles) ? props.auth.roles : [];
    const canSubscribe = props.auth.is_super_admin || roles.includes('admin');
    const currentPath = url.split('?')[0];
    const page = useMemo(() => resolveRealtimePage(currentPath), [currentPath]);

    if (!canSubscribe || !page) return <>{children}</>;

    return <RealtimeSubscription page={page}>{children}</RealtimeSubscription>;
}
