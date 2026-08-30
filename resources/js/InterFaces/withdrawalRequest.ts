// InterFaces/withdrawalRequest.ts
export interface IWithdrawalRequest {
    id: number;
    user_id: number;
    amount: string;
    fee: string;
    net_amount: string;
    status: 'pending' | 'approved' | 'rejected' | 'paid';
    bank_name: string;
    bank_account_number: string;
    bank_account_name: string;
    note_user?: string;
    note?: string;
    approved_by?: number;
    approved_at?: string;
    rejected_at?: string;
    paid_at?: string;
    payment_proof?: string;
    created_at: string;
    updated_at: string;
    user: {
        id: number;
        username: string;
        name?: string;
        email?: string;
        roles: string[];
    };
    approver?: {
        id: number;
        username: string;
        name?: string;
    };
}