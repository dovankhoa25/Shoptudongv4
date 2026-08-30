// @/Types/nickOrder.ts
export interface INick {
    id: number;
    account_name: string;
    account_password?: string; // Optional vì có thể không trả về trong listing
    price: number;
    image?: string;
    listing_type?: string;
    category?: {
        id: number;
        name: string;
    };
    attribute_cache_json?: string;
}

export interface IUser {
    id: number;
    name: string;
    email?: string;
}

export interface INickOrder {
    id: number;
    nick_id: number;
    buyer_id: number;
    seller_id: number;
    price: number;
    commission?: number;
    status: 'pending' | 'completed' | 'refunded';
    created_at: string;
    updated_at: string;

    // Relations
    nick?: INick;
    buyer?: IUser;
    seller?: IUser;
}