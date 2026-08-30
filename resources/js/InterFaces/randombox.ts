// @/InterFaces/randombox.ts
export interface IRandomBox {
    id: number;
    category_id: number;
    name: string;
    price: number;
    price_formatted: string;
    image: string | null;
    image_url: string | null;
    is_public: boolean;
    sort_order: number;
    created_at: string;
    created_at_formatted: string;
    updated_at: string;
    
    // Relationships
    category?: ICategory;
    
    // Computed fields
    total_nicks?: number;
    available_nicks?: number;
    status_text: string;
    status_color: string;
}

// @/InterFaces/category.ts (nếu chưa có)
export interface ICategory {
    id: number;
    name: string;
    slug: string;
    image: string | null;
    template: string | null;
    is_public: boolean;
    status: 'active' | 'inactive' | 'maintenance';
    sort_order: number;
    created_at: string;
    updated_at: string;
}