// InterFaces/gembot.ts - GemBot Interface
export interface IGemBot {
    id: number;
    name: string | null;
    name_view: string | null;
    account_name: string;
    has_password: boolean;
    server: {
        id: number;
        name: string;
    };
    gem_qty: number;
    server_game_id: number;
    gem_qty_formatted: string;
    map_info: {
        map_name: string | null;
        map_id: string;
        area_number: string;
        coordinates: string;
        proxy: string;
    };
    status: boolean;
    status_label: string;
    updated_by: 'web' | 'app';
    last_synced_at: string | null;
    last_synced_at_human: string | null;
    created_at: string;
    updated_at: string;
}