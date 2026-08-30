// InterFaces/serviceField.ts - Service Field Interface
import { IField } from './field';

export interface IServiceWithFields {
    id: number;
    name: string;
    status: boolean;
    fields?: IField[];
    fields_count?: number;
}

export interface IServiceField {
    id: number;
    service_id: number;
    field_id: number;
    created_at: string;
    updated_at: string;
}