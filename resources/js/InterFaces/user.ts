import { IRole } from "./role";

export interface IUser {
    id: number;
    username?: string;
    email?: string;
    balance?: string;
    avatar?: string;
    status?: 'active' | 'locked' | 'banned' | 'pending' | 'deleted';
    provider?: string;
    provider_id?: number;
    is_locked?: boolean;
    locked_reason?: string;
    locked_until?: string;
    role?: string;
    email_verified_at?: string;
    created_at: string;
    updated_at: string;
    roles?: IRole[];
    can?: {
        update: boolean;
        lock: boolean;
        manage_roles: boolean;
        adjust_balance: boolean;
    };
     categories?: Array<{
        id: number;
        name: string;
        slug: string;
        can_post: boolean;
    }>;
}

