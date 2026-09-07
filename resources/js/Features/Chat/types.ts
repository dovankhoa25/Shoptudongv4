export interface ChatUser {
    id: number;
    username: string;
    display_name?: string | null;
    avatar?: string | null;
    roles?: string[];
}

export interface ChatSubject {
    type: string;
    id: number;
    label: string;
    description?: string | null;
    status?: string | null;
    created_at?: string | null;
}

export interface ChatSeenBy extends ChatUser {
    read_at?: string | null;
}

export interface ChatAttachment {
    id: number | string;
    uuid?: string | null;
    url: string;
    thumbnail_url?: string | null;
    name?: string | null;
    mime_type: string;
    size: number;
    width?: number | null;
    height?: number | null;
}

export interface ChatReaction {
    emoji: string;
    count: number;
    reacted_by_me: boolean;
    user_ids?: number[];
    users?: ChatUser[];
}

export type ChatTipStatus = 'pending' | 'completed' | 'failed' | 'refunded';

export interface ChatTip {
    id: number;
    uuid: string;
    conversation_id: number;
    message_id?: number | null;
    payer?: ChatUser | null;
    recipient?: ChatUser | null;
    amount: number;
    platform_fee: number;
    recipient_amount: number;
    currency: 'VND' | string;
    status: ChatTipStatus;
    note?: string | null;
    completed_at?: string | null;
    refunded_at?: string | null;
    created_at?: string | null;
}

export interface ChatTippingConfig {
    enabled: boolean;
    min_amount: number;
    max_amount: number;
    daily_limit: number;
    remaining_daily_limit: number;
    balance: number;
    recipients: ChatUser[];
}

export interface ChatMessage {
    id: number;
    conversation_id: number;
    sender_kind: 'customer' | 'agent' | 'system';
    type: 'text' | 'image' | 'system' | 'internal_note' | 'tip';
    body: string;
    reply_to_id?: number | null;
    client_message_id?: string | null;
    metadata?: Record<string, unknown> | null;
    is_internal: boolean;
    is_mine?: boolean;
    sender?: ChatUser | null;
    seen_by: ChatSeenBy[];
    attachments: ChatAttachment[];
    attachments_expired?: boolean;
    reactions: ChatReaction[];
    tip?: ChatTip | null;
    edited_at?: string | null;
    created_at: string;
    /** Trạng thái cục bộ, chỉ tồn tại trong lúc client đang gửi/tự thử lại. */
    delivery_state?: 'sending' | 'failed';
    /** Tiến độ upload cục bộ (0-100), không được lưu trên server. */
    delivery_progress?: number;
}

export interface ChatParticipant {
    role: 'customer' | 'agent';
    last_read_message_id?: number | null;
    last_read_at?: string | null;
    joined_at?: string | null;
    left_at?: string | null;
    user?: ChatUser | null;
}

export interface ChatConversation {
    id: number;
    category: string;
    subject_type?: string | null;
    subject_id?: number | null;
    subject?: ChatSubject | null;
    status: 'waiting_agent' | 'waiting_customer' | 'resolved' | 'closed';
    priority: 'normal' | 'high' | 'urgent';
    source_app?: string | null;
    source_url?: string | null;
    customer?: ChatUser | null;
    assignee?: ChatUser | null;
    participants: ChatParticipant[];
    last_message?: ChatMessage | null;
    pinned_note?: ChatMessage | null;
    internal_notes_count?: number;
    latest_message_id?: number | null;
    last_message_at?: string | null;
    unread_count: number;
    resolved_at?: string | null;
    created_at: string;
    updated_at: string;
    permissions: {
        reply: boolean;
        manage: boolean;
        assign: boolean;
    };
    tipping?: ChatTippingConfig;
}

export interface PaginatedChatConversations {
    data: ChatConversation[];
    unread_total: number;
    meta?: {
        current_page: number;
        last_page: number;
        total: number;
    };
}
