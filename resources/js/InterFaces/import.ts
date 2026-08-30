export interface IImport {
    id: number;
    user_id: number;
    user_name: string;
    server_id: number;
    server_name: string;
    character_name: string;
    amount_vnd: number;
    gold_qty: number;
    gold_bar_qty: number;
    pure_gold_qty: number;
    import_price_at_order: number;
    status: 'pending' | 'processing' | 'completed' | 'cancelled';
    bot_id: number | null;
    bot_name: string | null;
    created_at: string;
}