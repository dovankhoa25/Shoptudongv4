export interface User {
    id: number;
    name?: string;
    email?: string;
    balance?: number;
    avatar?: string;
    is_locked?: string;
    locked_reason?: string;
    email_verified_at?: string;
    check_user_demo?: string;
}

// export type PageProps<
//     T extends Record<string, unknown> = Record<string, unknown>,
// > = T & {
//     auth: {
//         user: User;
//     };
// };


export type PageProps<
    T extends Record<string, unknown> = Record<string, unknown>
> = T & {
    auth: {
        user: IUser;
        roles: any;
        permissions: any;
        is_super_admin: boolean;
    };
    flash: {
        success: string | null;
        error: string | null;
        info: string | null;
    };
    notifications?: Notification[];
    ziggy: Config & { location: string };
};

interface Notification {
    id: string;
    title: string;
    message: string;
    time: string;
    type: 'info' | 'success' | 'warning' | 'error';
    read: boolean;
}

export type PaginatedData<T> = {
    data: T[];
    links: {
        first: string;
        last: string;
        prev: string | null;
        next: string | null;
    };

    meta: {
        current_page: number;
        from: number;
        last_page: number;
        path: string;
        per_page: number;
        to: number;
        total: number;

        links: {
            url: null | string;
            label: string;
            active: boolean;
        }[];
    };
};
