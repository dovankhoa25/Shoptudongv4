import type { NroItem } from '@/Components/Nro/NroSnapshot';

export type NickListing = {
    id: number;
    status: string;
    price: string;
    description: string;
    categoryId: number;
    categoryName?: string;
    categorySlug?: string;
    categoryActive: boolean;
    snapshotId?: number;
};

export type PublishConfig = {
    categoryId: number;
    price: number;
    description?: string;
    attributeSelections?: Record<string, number | null>;
};

export type Account = {
    id: number;
    account_name: string;
    ownerUsername?: string;
    deliveryActivity?: { preparing?: boolean; message?: string | null };
    server_index: number;
    server_id: number;
    server_game_id: number;
    delivery_map: number;
    delivery_zone: number;
    delivery_zone_mode: 'auto' | 'fixed';
    wait_minutes: number;
    usage_type: string;
    character_name?: string;
    last_synced_at?: string;
    latest_snapshot_id?: number;
    status: string;
    publishStatus?: string;
    publishError?: string;
    publishConfig?: PublishConfig;
    nick: NickListing | null;
    listingCounts: { total: number; active: number } | null;
};

export type Inventory = {
    id: number;
    quantity: number;
    reserved: number;
    listed: number;
    selectable: number;
    sellable: boolean;
    item: NroItem;
    locations: { location: string; slot: number; quantity: number }[];
};

export type Listing = {
    id: number;
    accountId: number;
    accountName?: string;
    ownerUsername?: string;
    title: string;
    description?: string;
    price: string;
    available: number;
    stockAvailable?: number;
    unavailableReasons?: string[];
    status: string;
    lastOrderStatus?: string;
    items: { item: NroItem; quantity: number }[];
};

export type Order = {
    id: number;
    accountId?: number;
    buyerUsername?: string;
    ownerUsername?: string;
    accountName?: string;
    botName?: string;
    botActivity?: { waitingCount: number; servingOther: boolean; phase?: string | null; message?: string | null; preparing?: boolean; pauseStartedAt?: string | null };
    title: string;
    recipientName: string;
    serverIndex: number;
    serverName?: string;
    price: string;
    status: string;
    message?: string;
    createdAt?: string;
    session?: { status: string; mode: string; expiresAt?: string; retryAt?: string; tradePhase?: string; phaseDeadline?: string; position?: { name: string; mapName: string; zone: number } };
    items: { id: number; quantity: number; delivered: number; item: NroItem }[];
};

export type Job = {
    id: number;
    account_id: number;
    accountName?: string;
    order_id?: number;
    type: string;
    status: string;
    updated_at?: string;
    result_json?: string;
    /** Present only for jobs awaiting reconciliation, so the form can show the real quantities. */
    order?: Order | null;
};

export type WorkerKey = {
    id: number;
    name: string;
    last_used_at?: string;
    revoked_at?: string;
    accepts_delivery: boolean;
};

export type AccountFilters = { q?: string; usage?: string; server?: number; state?: string };

export type Stats = {
    total: number;
    nick: number;
    warehouse: number;
    attention: number;
    reviewJobs: number;
    activeJobs: number;
    openOrders: number;
    listings: number;
    orders: number;
    jobs: number;
    workerOnline: boolean;
};

export type Server = { id: number; name: string; name_view: string };
export type LoginServer = { id: number; name: string };
export type Category = { id: number; name: string };
export type Capabilities = Record<string, boolean>;

/** Shape every tab endpoint returns. */
export type Paged<T> = { data: T[]; total: number; page: number; perPage: number };

export type PageProps = {
    accountStats?: Stats;
    accountFilters?: AccountFilters;
    accountPagination?: { total: number; current: number; pageSize: number };
    salePolicy: { enabled: boolean; ids: number[] };
    shopUrl: string | null;
    capabilities: Capabilities;
    servers: Server[];
    loginServers: LoginServer[];
    accounts: Account[];
    categories: Category[];
    canReconcile: boolean;
};
