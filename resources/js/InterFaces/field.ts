// InterFaces/field.ts - Field Interface
export interface IField {
    id: number;
    label: string;
    field_key: string;
    type: 'text' | 'textarea' | 'number' | 'select';
    options: string[] | string | null;
    required: boolean;
    services?: IService[];
    created_at: string;
    updated_at: string;
}

export interface IService {
    id: number;
    name: string;
}