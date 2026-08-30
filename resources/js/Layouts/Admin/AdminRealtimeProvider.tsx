import React, { useCallback, useEffect, useRef } from 'react';
import { router, usePage } from '@inertiajs/react';
import { useEcho } from '@laravel/echo-react';
import { useToast } from '@/Components/ToastProvider';
import type { PageProps } from '@/types';

interface AdminRealtimeEvent {
    resource: string;
    resource_id: number | string;
    action: 'created' | 'updated' | 'status_updated' | 'deleted' | string;
    status: string | null;
    message: string;
    occurred_at: string;
}

const resourcePaths: Record<string, string[]> = {
    gold_order: ['/admin/orders', '/admin/dashboard'],
    legacy_gold_order: ['/admin/orders', '/admin/dashboard'],
    gold_import: ['/admin/imports', '/admin/dashboard'],
    legacy_gold_import: ['/admin/imports', '/admin/dashboard'],
    gem_order: ['/admin/gem-orders', '/admin/dashboard'],
    service_order: ['/admin/services/orders', '/admin/analytics'],
    nick_order: ['/admin/games/accounts/history', '/admin/analytics'],
    random_order: ['/admin/randombox', '/admin/analytics'],
    withdrawal: ['/admin/withdrawals'],
    card_recharge: ['/admin/cards', '/admin/deposits'],
    bank_deposit: ['/admin/deposits', '/admin/transactions'],
    carot_recharge: ['/admin/carot-recharges'],
    balance_transaction: ['/admin/transactions', '/admin/users'],
};

const recentlyHandledEvents = new Map<string, number>();
const duplicateWindowMs = 3_000;

function isDuplicateEvent(event: AdminRealtimeEvent): boolean {
    const fingerprint = [
        event.resource,
        event.resource_id,
        event.action,
        event.status ?? '',
        event.message,
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

function RealtimeSubscription({ children }: { children: React.ReactNode }) {
    const { url } = usePage();
    const toast = useToast();
    const reloadTimer = useRef<ReturnType<typeof setTimeout> | null>(null);
    const currentPath = url.split('?')[0];

    const handleEvent = useCallback((event: AdminRealtimeEvent) => {
        if (isDuplicateEvent(event)) return;

        const isSuccessful = ['completed', 'success', 'paid'].includes(event.status ?? '');
        const needsAttention = ['cancelled', 'rejected', 'refunded', 'failed'].includes(event.status ?? '');

        if (isSuccessful) {
            toast.success(event.message);
        } else if (needsAttention) {
            toast.warning(event.message);
        } else {
            toast.info(event.message);
        }

        const shouldReload = (resourcePaths[event.resource] ?? [])
            .some(path => currentPath.startsWith(path));

        if (! shouldReload) return;

        if (reloadTimer.current) clearTimeout(reloadTimer.current);
        reloadTimer.current = setTimeout(() => {
            // Inertia reload giữ nguyên state và scroll theo mặc định.
            router.reload();
        }, 300);
    }, [currentPath, toast]);

    useEcho<AdminRealtimeEvent>('Admin.realtime', '.AdminEvent', handleEvent, [handleEvent]);

    useEffect(() => () => {
        if (reloadTimer.current) clearTimeout(reloadTimer.current);
    }, []);

    return <>{children}</>;
}

export default function AdminRealtimeProvider({ children }: { children: React.ReactNode }) {
    const { props } = usePage<PageProps>();
    const roles = Array.isArray(props.auth.roles) ? props.auth.roles : [];
    const canSubscribe = props.auth.is_super_admin || roles.includes('admin');

    if (! canSubscribe) return <>{children}</>;

    return <RealtimeSubscription>{children}</RealtimeSubscription>;
}
