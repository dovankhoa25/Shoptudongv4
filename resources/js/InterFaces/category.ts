// InterFaces/category.ts
import { IGameType } from "./gametype";

export interface ICategory {
    id: number;
    game_type_id: number;
    name: string;
    slug?: string;
    image_url?: string;
    template: string;
    is_public?: boolean;
    status: 'active' | 'inactive' | 'maintenance';
    sort_order: number;
    games_count?: number;
    created_at: string;
    updated_at: string;

    // Relations
    game_type?: IGameType;
}

export interface ICategoryFormData {
    game_type_id: number | null;
    name: string;
    slug?: string;
    image?: string;
    template: string;
    is_public: boolean;
    status: 'active' | 'inactive' | 'maintenance';
    sort_order: number;
}

export interface ICategoryFilters {
    search?: string;
    status?: 'active' | 'inactive' | 'maintenance';
    is_public?: boolean;
    game_type_id?: number;
}