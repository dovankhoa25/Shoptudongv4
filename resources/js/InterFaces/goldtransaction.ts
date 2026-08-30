// @/InterFaces/goldtransaction.ts
export interface IGoldTransaction {
    id: number;
    user?: {
        id: number;
        username: string;
        email: string;
    } | null;
    server?: {
        id: number;
        name: string;
    } | null;
    bot?: {
        id: number;
        name: string;
    } | null;
    character_name: string;
    type: string;
    amount_vnd: number;
    amount_vnd_formatted: string;
    gold_qty: number;
    gold_qty_formatted: string;
    gold_bar_qty: number;
    gold_bar_qty_formatted: string;
    pure_gold_qty: number;
    pure_gold_qty_formatted: string;
    price_at_transaction: number;
    price_formatted: string;
    status: 'pending' | 'processing' | 'completed' | 'cancelled' | 'failed';
    status_label: string;
    status_color: string;
    can_refund: boolean;
    can_complete: boolean;
    can_cancel: boolean;
    can_process: boolean;
    updated_by?: number;
    cancel_reason?: string;
    admin_note?: string;
    last_synced_at?: string;
    last_synced_at_human?: string;
    processed_at?: string;
    completed_at?: string;
    cancelled_at?: string;
    created_at: string;
    created_at_human: string;
    updated_at: string;
    updated_at_human: string;
}

// @/InterFaces/bot.ts
export interface IBot {
    id: number;
    name: string;
    server_id?: number;
    status: 'active' | 'inactive';
    type?: string;
    created_at: string;
    updated_at: string;
}