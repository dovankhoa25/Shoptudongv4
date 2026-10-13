// InterFaces/gemprice.ts - GemPrice Interface với Multiplier
export interface IGemPriceQuote {
    requested_amount: number;
    charged_amount: number;
    unused_amount: number;
    ticket_qty: number;
    gem_qty: number;
}

export interface IGemPrice {
    id: number;
    server: {
        id: number;
        name: string;
    };
    multiplier: number;
    gift_ticket_multiplier: number | string | null;
    min_amount: number;
    multiplier_display: string; // x13, x13.5
    gems_per_10k: number; // 130, 135
    gift_ticket_gems_per_10k: number;
    gift_ticket_quote_per_10k?: IGemPriceQuote;
    consignment_quote_per_10k?: IGemPriceQuote;
    gems_per_10k_formatted: string;
    status: boolean;
    status_label: string;
    created_at: string;
    updated_at: string;
    created_at_human: string;
    updated_at_human: string;
}
