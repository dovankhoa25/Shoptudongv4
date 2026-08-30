// InterFaces/service.ts - Service Interface
export interface IService {
    id: number;
    name: string;
    default_price: number | null;
    original_price: number | null;
    description: string | null;
    status: boolean;
    is_popular: boolean;
    processing_time: string | null;
    warranty: string | null;
    categories?: ICategory[];
    created_at: string;
    updated_at: string;
}

export interface ICategory {
    id: number;
    name: string;
    slug: string;
}