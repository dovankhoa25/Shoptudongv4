// InterFaces/nick.ts
import { ICategory } from "./category";
import { IUser } from "./user";

export interface INickAttributeCache {
    attribute_id: number;
    attribute_name: string;
    option_id: number;
    option_value: string;
}

export interface INick {
    id: number;
    account_name: string;
    account_password: string;
    price: number;
    description?: string;
    image?: string;
    listing_type: 'normal' | 'vip';
    category_id: number;
    user_id: number;
    status: 'not_sold' | 'sold' | 'deleted' | 'return' | 'hide';
    attribute_cache_json?: INickAttributeCache[];
    deleted_at?: string;
    created_at: string;
    updated_at: string;

    // Relations
    category?: ICategory;
    user?: IUser;
}

export interface INickFormData {
    account_name: string;
    account_password: string;
    price: number;
    description?: string;
    image?: string;
    listing_type: 'normal' | 'vip';
    category_id: number;
    status: 'not_sold' | 'sold' | 'deleted' | 'return';
    attribute_cache_json?: INickAttributeCache[];
}

export interface INickFilters {
    search?: string;
    status?: 'not_sold' | 'sold' | 'deleted' | 'return';
    listing_type?: 'normal' | 'vip';
    category_id?: number;
    price_min?: number;
    price_max?: number;
}