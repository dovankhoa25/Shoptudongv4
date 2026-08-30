// InterFaces/gemprice.ts - GemPrice Interface với Multiplier
export interface IGemPrice {
    id: number;
    server: {
        id: number;
        name: string;
    };
    multiplier: number;
    multiplier_display: string; // x13, x13.5
    gems_per_10k: number; // 130, 135
    gems_per_10k_formatted: string;
    status: boolean;
    status_label: string;
    created_at: string;
    updated_at: string;
    created_at_human: string;
    updated_at_human: string;
}