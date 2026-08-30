// InterFaces/bot.ts
export interface IBot {
    id: number;
    name: string;
    account_name: string;
    account_password?: string;
    has_password: boolean;
    type: string; // Raw: "selling_main,import_sub"
    types: string[]; // Array: ['selling_main', 'import_sub']
    type_labels: string[]; // Array: ['Bán chính', 'Nhập phụ']
    server?: {
        id: number;
        name: string;
        name_view: string;
    };
    server_id: number;
    server_name?: string;
    server_game_id?: number;
    gold_bar_qty: number;
    gold_bar_qty_formatted: string;
    gold_qty: number;
    gold_qty_formatted: string;
    map_info?: {
        map_name: string;
        map_id: number;
        area_number: number;
        proxy: number;
    };
    map_name?: string;
    map_id?: number;
    area_number?: number;
    coordinates: string;
    proxy: string;
    status: boolean;
    status_label: string;
    status_color: string;
    updated_by?: string;
    last_synced_at?: string;
    last_synced_at_human?: string;
    created_at?: string;
    created_at_human?: string;
    updated_at?: string;
    updated_at_human?: string;
}