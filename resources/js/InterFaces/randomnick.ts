// @/InterFaces/randomnick.ts
export interface IRandomNick {
    id: number;
    random_box_id: number;
    account: string;
    password: string;
    description: string | null;
    status: 'available' | 'taken' | 'deleted';
    created_at: string;
    created_at_formatted: string;
    updated_at: string;
    deleted_at: string | null;
    
    // Image fields
    image_url: string | null;
    has_own_image: boolean;
    
    // Status formatting
    status_text: string;
    status_color: string;
    
    // Security fields
    account_masked: string;
    password_masked: string;
    
    // Computed fields
    is_available: boolean;
    is_taken: boolean;
    is_deleted: boolean;
    
    // Relationships
    random_box?: {
        id: number;
        name: string;
        price: number;
        price_formatted: string;
    };
}