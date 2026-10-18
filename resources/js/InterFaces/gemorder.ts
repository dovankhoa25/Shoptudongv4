export interface IGemOrder {
    id: number;
    user: {
        id: number;
        username: string;
        email: string;
    };
    server: {
        id: number;
        name: string;
    };
    character_name: string;
    delivery_method?: 'consignment' | 'gift_ticket';
    delivery_method_label?: string;
    bot_id?: number | null;
    has_delivery_checkpoint?: boolean;
    delivery_phase?: string | null;
    delivery_phase_label?: string | null;
    bot_gem_spent_qty?: number;
    delivered_gem_qty?: number;
    tickets_used_qty?: number;
    equipment_delivered?: boolean;
    amount_vnd: number;
    amount_vnd_formatted: string;
    gem_qty: number;
    gem_qty_formatted: string;
    price_at_transaction: number;
    price_formatted: string;
    multiplier_display?: string;
    status: 'pending' | 'processing' | 'completed' | 'cancelled' | 'refunded';
    status_label: string;
    status_color: string;
    can_refund: boolean;
    can_complete: boolean;
    can_cancel: boolean;
    can_update_status: boolean;
    is_timeout_cancellation: boolean;
    updated_by: 'web' | 'app';
    last_synced_at: string | null;
    last_synced_at_human: string | null;
    cancel_requested_at: string | null;
    cancel_requested_at_human: string | null;
    refunded_at: string | null;
    refunded_at_human: string | null;
    created_at: string;
    created_at_human: string;
    updated_at: string;
    updated_at_human: string;
}
