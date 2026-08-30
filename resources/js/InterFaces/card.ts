export interface ICard {
    id: number;
    declared_value: string;
    value: string;
    amount_user: string;
    amount_api: string;
    discount_rate_at_time: string;
    code: string;
    serial: string;
    status: string;
    loaded_type: string;
    note?: string;
    user: {
        id: number;
        name: string;
    };
    card_type: {
        id: number;
        name: string;
    };
    created_at: string;
}