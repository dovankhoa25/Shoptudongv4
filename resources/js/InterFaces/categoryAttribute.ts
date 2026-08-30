// InterFaces/categoryAttribute.ts
import { ICategory } from "./category";
import { IAttribute } from "./attribute";

export interface ICategoryAttribute {
    id: number;
    category_id: number;
    attribute_id: number;
    created_at: string;
    updated_at: string;

    // Relations
    category?: ICategory;
    attribute?: IAttribute;
}

export interface ICategoryWithAttributes extends ICategory {
    attributes?: IAttribute[];
    attributes_count?: number;
}

export interface IAttributeAssignData {
    category_id: number;
    attribute_ids: number[];
}