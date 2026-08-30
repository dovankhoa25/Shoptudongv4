import { IPermissions } from "./permission";

export interface IRole {
    id: number;
    name?: string;
    guard_name?: string;
    permissions: IPermissions[];
    created_at: string;
    permissions_count?: number;
    users_count?: number;
    can_manage?: boolean;
}
