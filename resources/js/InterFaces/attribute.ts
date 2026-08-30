// InterFaces/attribute.ts
export interface IAttributeOption {
    id: number;
    attribute_id: number;
    option_value: string;
    status: boolean;
    created_at: string;
    updated_at: string;
}

export interface IAttribute {
    id: number;
    name: string;
    status: boolean;
    created_at: string;
    updated_at: string;

    // Relations
    options?: IAttributeOption[];
    options_count?: number;
}

export interface IAttributeFormData {
    name: string;
    status: boolean;
}

export interface IAttributeOptionFormData {
    attribute_id: number;
    option_value: string;
    status: boolean;
}

export interface IAttributeFilters {
    search?: string;
    status?: boolean;
}