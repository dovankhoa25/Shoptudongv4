import { ChangeEvent, FormEvent, KeyboardEvent, memo, useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { Link, usePage } from '@inertiajs/react';
import { echo } from '@laravel/echo-react';
import { Modal } from 'antd';
import {
    ArrowLeft,
    CheckCheck,
    ChevronDown,
    ChevronRight,
    CircleUserRound,
    Clock3,
    ExternalLink,
    Eye,
    Headphones,
    Inbox,
    ImagePlus,
    LoaderCircle,
    MessageCircle,
    MoreHorizontal,
    Plus,
    RotateCcw,
    Search,
    SendHorizontal,
    ShieldCheck,
    Smile,
    Sparkles,
    UserRoundCheck,
    X,
} from 'lucide-react';
import type { PageProps } from '@/types';
import UserAvatar from '@/Components/UserAvatar';
import type {
    ChatAttachment,
    ChatConversation,
    ChatMessage,
    ChatReaction,
    ChatSeenBy,
    ChatSubject,
    ChatUser,
    PaginatedChatConversations,
} from './types';

interface ChatWorkspaceProps {
    mode: 'customer' | 'agent';
    baseUrl: string;
    compact?: boolean;
    initialConversationId?: number | null;
}

interface ChatConversationRealtimeSummary extends Partial<ChatConversation> {
    id: number;
    status: ChatConversation['status'];
    last_message_at?: string | null;
    customer_id?: number;
    assigned_to_id?: number | null;
}

interface ChatMessageEvent {
    message: ChatMessage;
    conversation?: ChatConversationRealtimeSummary;
}

interface ChatReadEvent {
    conversation_id: number;
    reader: ChatUser & { kind: 'customer' | 'agent' };
    last_read_message_id: number;
    read_at: string;
}

interface ChatReactionEvent {
    message_id: number;
    reactions: Array<Omit<ChatReaction, 'reacted_by_me'> & { reacted_by_me?: boolean }>;
}

interface ChatInboxEvent {
    action: string;
    conversation: ChatConversationRealtimeSummary;
}

interface ChatConversationRealtimeOverlay {
    message: ChatMessage;
    summary?: ChatConversationRealtimeSummary;
}

interface OptimisticReadRollback {
    unreadCount: number;
    listSnapshotVersion: number;
    checkpoint: number;
}

interface ReadRetryState {
    checkpoint: number;
    failures: number;
}

type ChatInboxView = 'active' | 'completed';
type CompletedPeriod = '7d' | '30d' | '90d' | 'all';

interface ChatConversationCounts {
    all?: number;
    active?: number;
    completed?: number;
    waiting_agent?: number;
    waiting_customer?: number;
    resolved?: number;
    closed?: number;
}

interface CompletionUndo {
    conversationId: number;
    conversationTitle: string;
    previousStatus: Extract<ChatConversation['status'], 'waiting_agent' | 'waiting_customer'>;
    completedStatus: Extract<ChatConversation['status'], 'resolved' | 'closed'>;
}

interface PendingChatImage {
    id: string;
    file: File;
    previewUrl: string;
}

interface FailedChatSend {
    body: string;
    images: PendingChatImage[];
    isInternal: boolean;
}

type ChatConversationListResponse = Omit<PaginatedChatConversations, 'meta'> & {
    counts?: ChatConversationCounts;
    unread_counts?: ChatConversationCounts;
    meta?: PaginatedChatConversations['meta'] & {
        counts?: ChatConversationCounts;
    };
};

interface RelatedOrderDetail {
    type: string;
    id: number;
    label: string;
    description?: string | null;
    status?: string | null;
    fields: Array<{ label: string; value: string | number }>;
    created_at?: string | null;
    updated_at?: string | null;
}

type RealtimeConnectionStatus = 'connecting' | 'connected' | 'disconnected';

const CHAT_IMAGE_MAX_COUNT = 4;
const CHAT_IMAGE_MAX_BYTES = 5 * 1024 * 1024;
const CHAT_IMAGE_MIME_TYPES = new Set(['image/jpeg', 'image/png', 'image/webp']);
const MESSAGE_REACTION_EMOJIS = ['👍', '❤️', '😂', '😮', '😢', '🙏', '🎉'];
const COMPOSER_EMOJIS = [
    '😀', '😃', '😄', '😁', '😆', '😅', '😂', '🤣',
    '😊', '😍', '🥰', '😘', '😎', '🤩', '🤔', '😮',
    '😢', '😭', '😡', '🤯', '👍', '👎', '👏', '🙏',
    '❤️', '💜', '💙', '💚', '🔥', '✨', '🎉', '💯',
];

const statusLabels: Record<ChatConversation['status'], string> = {
    waiting_agent: 'Chờ hỗ trợ',
    waiting_customer: 'Chờ khách',
    resolved: 'Đã giải quyết',
    closed: 'Đã đóng',
};

const statusStyles: Record<ChatConversation['status'], string> = {
    waiting_agent: 'bg-amber-50 text-amber-700 ring-amber-200 dark:bg-amber-500/10 dark:text-amber-300 dark:ring-amber-500/20',
    waiting_customer: 'bg-sky-50 text-sky-700 ring-sky-200 dark:bg-sky-500/10 dark:text-sky-300 dark:ring-sky-500/20',
    resolved: 'bg-emerald-50 text-emerald-700 ring-emerald-200 dark:bg-emerald-500/10 dark:text-emerald-300 dark:ring-emerald-500/20',
    closed: 'bg-slate-100 text-slate-600 ring-slate-200 dark:bg-slate-800 dark:text-slate-300 dark:ring-slate-700',
};

function formatTime(value?: string | null): string {
    if (!value) return '';
    const date = new Date(value);
    const today = new Date();
    const sameDay = date.toDateString() === today.toDateString();

    return new Intl.DateTimeFormat('vi-VN', sameDay
        ? { hour: '2-digit', minute: '2-digit' }
        : { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' }).format(date);
}

function errorMessage(error: unknown): string {
    const candidate = error as {
        response?: { data?: { message?: string; errors?: Record<string, string[]> } };
    };
    const errors = candidate.response?.data?.errors;
    if (errors) return Object.values(errors).flat()[0] ?? 'Không thể thực hiện thao tác.';

    return candidate.response?.data?.message ?? 'Kết nối bị gián đoạn. Vui lòng thử lại.';
}

function formatFileSize(bytes: number): string {
    if (bytes < 1024) return `${bytes} B`;
    if (bytes < 1024 * 1024) return `${Math.ceil(bytes / 1024)} KB`;
    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}

function messageAttachments(message?: ChatMessage | null): ChatAttachment[] {
    return Array.isArray(message?.attachments) ? message.attachments : [];
}

function messageReactions(message?: ChatMessage | null): ChatReaction[] {
    return Array.isArray(message?.reactions) ? message.reactions : [];
}

function normalizedReactions(
    reactions: Array<Omit<ChatReaction, 'reacted_by_me'> & { reacted_by_me?: boolean }>,
    currentUserId: number,
): ChatReaction[] {
    return reactions
        .filter(reaction => reaction.count > 0)
        .map(reaction => ({
            ...reaction,
            reacted_by_me: Array.isArray(reaction.user_ids)
                ? reaction.user_ids.includes(currentUserId)
                : reaction.reacted_by_me === true,
        }));
}

function attachmentsWerePurged(message?: ChatMessage | null): boolean {
    return message?.attachments_expired === true || Boolean(message?.metadata?.attachments_purged_at);
}

function conversationMessagePreview(message?: ChatMessage | null): string {
    if (!message) return 'Chưa có tin nhắn';
    const body = message.body?.trim();
    if (body) return body;

    const attachmentCount = messageAttachments(message).length;
    if (attachmentCount > 0) return attachmentCount === 1 ? '📷 Ảnh' : `📷 ${attachmentCount} ảnh`;
    if (attachmentsWerePurged(message)) return '📷 Ảnh đã hết hạn';
    return 'Tin nhắn';
}

function MessageAttachments({
    attachments,
    compact,
    onOpen,
    onLoadError,
}: {
    attachments: ChatAttachment[];
    compact: boolean;
    onOpen: (attachment: ChatAttachment) => void;
    onLoadError?: (attachment: ChatAttachment) => void;
}) {
    if (attachments.length === 0) return null;

    return (
        <div className={`grid gap-1.5 overflow-hidden ${attachments.length === 1 ? 'grid-cols-1' : 'grid-cols-2'}`}>
            {attachments.map((attachment, index) => (
                <button
                    key={attachment.uuid ?? attachment.id}
                    type="button"
                    onClick={() => onOpen(attachment)}
                    className={`group relative overflow-hidden rounded-xl bg-slate-200 text-left focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-400 dark:bg-slate-700 ${attachments.length === 3 && index === 0 ? 'col-span-2' : ''}`}
                    aria-label={`Mở ảnh ${attachment.name || index + 1}`}
                >
                    <img
                        src={attachment.thumbnail_url || attachment.url}
                        alt={attachment.name || 'Ảnh đính kèm'}
                        loading="lazy"
                        onError={() => onLoadError?.(attachment)}
                        className={`${compact ? 'max-h-40 min-h-20' : 'max-h-72 min-h-24'} w-full object-cover transition duration-200 group-hover:scale-[1.02]`}
                    />
                    {attachment.size > 0 && (
                        <span className="absolute bottom-1.5 right-1.5 rounded-md bg-black/60 px-1.5 py-0.5 text-[9px] font-medium text-white backdrop-blur-sm">
                            {formatFileSize(attachment.size)}
                        </span>
                    )}
                </button>
            ))}
        </div>
    );
}

function EmojiPicker({ onSelect }: { onSelect: (emoji: string) => void }) {
    return (
        <div className="grid w-64 grid-cols-8 gap-0.5 rounded-xl border border-slate-200 bg-white p-2 shadow-2xl dark:border-slate-700 dark:bg-slate-900">
            {COMPOSER_EMOJIS.map(emoji => (
                <button
                    key={emoji}
                    type="button"
                    onClick={() => onSelect(emoji)}
                    className="grid h-8 w-8 place-items-center rounded-lg text-lg transition hover:bg-slate-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-400 dark:hover:bg-slate-800"
                    aria-label={`Chèn ${emoji}`}
                >
                    {emoji}
                </button>
            ))}
        </div>
    );
}

function isCanceledRequest(error: unknown): boolean {
    return (error as { code?: string }).code === 'ERR_CANCELED';
}

function isPageActive(): boolean {
    return document.visibilityState === 'visible' && document.hasFocus();
}

function conversationTitle(conversation: ChatConversation, mode: 'customer' | 'agent'): string {
    if (mode === 'agent' && conversation.customer?.username) return conversation.customer.username;
    if (conversation.subject?.label) return conversation.subject.label;
    return 'Hỗ trợ chung';
}

function isMessageMine(message: ChatMessage, currentUserId: number): boolean {
    if (message.sender?.id) return message.sender.id === currentUserId;
    return message.is_mine === true;
}

function applyMessageToConversation(
    conversation: ChatConversation,
    incoming: ChatMessage,
    summary?: ChatConversationRealtimeSummary,
): ChatConversation {
    const previousLastMessage = conversation.last_message;
    const isNewestKnownMessage = !previousLastMessage
        || incoming.delivery_state !== undefined
        || previousLastMessage.id < 0
        || incoming.id > previousLastMessage.id
        || (incoming.id > 0 && incoming.id === previousLastMessage.id)
        || Boolean(incoming.client_message_id
            && incoming.client_message_id === previousLastMessage.client_message_id);
    const isLatestPublicMessage = !incoming.is_internal && isNewestKnownMessage;
    const summaryHasAssignee = summary !== undefined
        && Object.prototype.hasOwnProperty.call(summary, 'assignee');

    return {
        ...conversation,
        status: isLatestPublicMessage
            ? summary?.status ?? (incoming.sender_kind === 'customer' ? 'waiting_agent' : 'waiting_customer')
            : conversation.status,
        assignee: isNewestKnownMessage && summaryHasAssignee
            ? summary?.assignee ?? null
            : conversation.assignee,
        last_message: isLatestPublicMessage ? incoming : conversation.last_message,
        last_message_at: isLatestPublicMessage
            ? summary?.last_message_at ?? incoming.created_at
            : conversation.last_message_at,
        resolved_at: isLatestPublicMessage ? null : conversation.resolved_at,
    };
}

function mergeMessages(current: ChatMessage[], incoming: ChatMessage | ChatMessage[]): ChatMessage[] {
    const next = [...current];

    for (const candidate of Array.isArray(incoming) ? incoming : [incoming]) {
        const matchingIndex = next.findIndex(message => (
            (candidate.id > 0 && message.id === candidate.id)
            || (candidate.client_message_id && message.client_message_id === candidate.client_message_id)
        ));

        if (matchingIndex >= 0) {
            const merged = { ...next[matchingIndex], ...candidate };
            if (candidate.id > 0 && candidate.delivery_state === undefined) {
                delete merged.delivery_state;
                delete merged.delivery_progress;
            }
            next[matchingIndex] = merged;
        } else {
            next.push(candidate);
        }
    }

    return next.sort((left, right) => {
        const timeDifference = new Date(left.created_at).getTime() - new Date(right.created_at).getTime();
        if (timeDifference !== 0 && !Number.isNaN(timeDifference)) return timeDifference;
        if (left.id > 0 && right.id > 0) return left.id - right.id;
        if (left.id > 0) return -1;
        if (right.id > 0) return 1;
        return left.id - right.id;
    });
}

function statusBelongsToView(status: ChatConversation['status'], view: ChatInboxView): boolean {
    return view === 'active'
        ? status === 'waiting_agent' || status === 'waiting_customer'
        : status === 'resolved' || status === 'closed';
}

function sortConversations(conversations: ChatConversation[], view?: ChatInboxView): ChatConversation[] {
    return [...conversations].sort((left, right) => {
        if (view === 'active') {
            const waitingAgentDifference = Number(right.status === 'waiting_agent') - Number(left.status === 'waiting_agent');
            if (waitingAgentDifference !== 0) return waitingAgentDifference;

            const unreadDifference = right.unread_count - left.unread_count;
            if (unreadDifference !== 0) return unreadDifference;
        }

        const leftTime = new Date(view === 'completed'
            ? left.resolved_at ?? left.updated_at ?? left.created_at
            : left.last_message_at ?? left.updated_at ?? left.created_at).getTime();
        const rightTime = new Date(view === 'completed'
            ? right.resolved_at ?? right.updated_at ?? right.created_at
            : right.last_message_at ?? right.updated_at ?? right.created_at).getTime();
        return rightTime - leftTime || right.id - left.id;
    });
}

function Avatar({ user, className = 'h-9 w-9' }: { user?: ChatUser | null; className?: string }) {
    return <UserAvatar user={user} className={className} />;
}

function AssigneePicker({
    agents,
    selected,
    disabled,
    onChange,
}: {
    agents: ChatUser[];
    selected?: ChatUser | null;
    disabled: boolean;
    onChange: (value: string) => void;
}) {
    const [open, setOpen] = useState(false);
    const [query, setQuery] = useState('');
    const containerRef = useRef<HTMLDivElement | null>(null);
    const normalizedQuery = query.trim().toLocaleLowerCase('vi-VN');
    const filteredAgents = useMemo(() => agents.filter(agent => {
        if (!normalizedQuery) return true;
        return agent.username.toLocaleLowerCase('vi-VN').includes(normalizedQuery)
            || agent.roles?.some(role => role.toLocaleLowerCase('vi-VN').includes(normalizedQuery));
    }), [agents, normalizedQuery]);

    useEffect(() => {
        if (!open) return;
        const closeOnOutsideClick = (event: MouseEvent) => {
            if (!containerRef.current?.contains(event.target as Node)) setOpen(false);
        };
        const closeOnEscape = (event: globalThis.KeyboardEvent) => {
            if (event.key === 'Escape') setOpen(false);
        };
        document.addEventListener('mousedown', closeOnOutsideClick);
        document.addEventListener('keydown', closeOnEscape);
        return () => {
            document.removeEventListener('mousedown', closeOnOutsideClick);
            document.removeEventListener('keydown', closeOnEscape);
        };
    }, [open]);

    const selectAgent = (value: string) => {
        onChange(value);
        setOpen(false);
        setQuery('');
    };

    return (
        <div ref={containerRef} className="relative">
            <button
                type="button"
                disabled={disabled}
                onClick={() => setOpen(value => !value)}
                aria-haspopup="listbox"
                aria-expanded={open}
                className="flex w-full items-center gap-2 rounded-xl border border-slate-200 bg-white px-3 py-2 text-left text-sm transition hover:border-indigo-300 disabled:cursor-not-allowed disabled:opacity-60 dark:border-slate-700 dark:bg-slate-900 dark:hover:border-indigo-500"
            >
                <Avatar user={selected} className="h-7 w-7 text-[10px]" />
                <span className="min-w-0 flex-1 truncate text-slate-700 dark:text-slate-200">{selected?.username ?? 'Chưa phân công'}</span>
                <ChevronDown className={`h-4 w-4 shrink-0 text-slate-400 transition ${open ? 'rotate-180' : ''}`} />
            </button>

            {open && (
                <div className="absolute left-0 right-0 z-40 mt-2 min-w-[15rem] overflow-hidden rounded-xl border border-slate-200 bg-white shadow-xl dark:border-slate-700 dark:bg-slate-900">
                    <div className="border-b border-slate-100 p-2 dark:border-slate-800">
                        <label className="relative block">
                            <Search className="pointer-events-none absolute left-2.5 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
                            <input
                                autoFocus
                                value={query}
                                onChange={event => setQuery(event.target.value)}
                                placeholder="Tìm tên hoặc vai trò…"
                                className="w-full rounded-lg border-slate-200 py-2 pl-8 pr-2 text-sm dark:border-slate-700 dark:bg-slate-950"
                            />
                        </label>
                    </div>
                    <div role="listbox" className="max-h-64 overflow-y-auto p-1.5">
                        <button
                            type="button"
                            role="option"
                            aria-selected={!selected}
                            onClick={() => selectAgent('')}
                            className="flex w-full items-center gap-2 rounded-lg px-2 py-2 text-left text-sm text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800"
                        >
                            <Avatar user={null} className="h-7 w-7 text-[10px]" />
                            Chưa phân công
                        </button>
                        {filteredAgents.map(agent => (
                            <button
                                key={agent.id}
                                type="button"
                                role="option"
                                aria-selected={selected?.id === agent.id}
                                onClick={() => selectAgent(String(agent.id))}
                                className={`flex w-full items-center gap-2 rounded-lg px-2 py-2 text-left transition ${selected?.id === agent.id ? 'bg-indigo-50 dark:bg-indigo-500/10' : 'hover:bg-slate-100 dark:hover:bg-slate-800'}`}
                            >
                                <Avatar user={agent} className="h-8 w-8 text-[10px]" />
                                <span className="min-w-0 flex-1">
                                    <strong className="block truncate text-sm font-medium text-slate-800 dark:text-slate-100">{agent.username}</strong>
                                    <span className="block truncate text-[11px] text-slate-500">{agent.roles?.join(', ') || 'Nhân viên hỗ trợ'}</span>
                                </span>
                                {selected?.id === agent.id && <CheckCheck className="h-4 w-4 text-emerald-500" />}
                            </button>
                        ))}
                        {filteredAgents.length === 0 && <p className="px-3 py-5 text-center text-xs text-slate-500">Không tìm thấy người phù hợp.</p>}
                    </div>
                </div>
            )}
        </div>
    );
}

function RelatedOrderModal({ detail, onClose }: { detail: RelatedOrderDetail | null; onClose: () => void }) {
    return (
        <Modal
            open={detail !== null}
            onCancel={onClose}
            footer={null}
            width={620}
            title={detail?.label ?? 'Chi tiết đơn liên quan'}
            destroyOnHidden
        >
            {detail && (
                <div className="pt-2">
                    <div className="mb-4 flex flex-wrap items-center justify-between gap-2 rounded-xl bg-indigo-50 px-4 py-3 dark:bg-indigo-500/10">
                        <div>
                            <p className="text-sm font-semibold text-slate-900 dark:text-white">{detail.description || detail.label}</p>
                            <p className="mt-0.5 text-xs text-slate-500">Mã đơn #{detail.id}</p>
                        </div>
                        {detail.status && <span className="rounded-full bg-white px-2.5 py-1 text-xs font-semibold text-indigo-700 shadow-sm dark:bg-slate-900 dark:text-indigo-300">{detail.status}</span>}
                    </div>
                    <div className="grid gap-3 sm:grid-cols-2">
                        {detail.fields.map(field => (
                            <div key={field.label} className="rounded-xl border border-slate-200 px-3 py-2.5 dark:border-slate-700">
                                <span className="block text-[11px] font-semibold uppercase tracking-wide text-slate-400">{field.label}</span>
                                <span className="mt-1 block break-words text-sm font-medium text-slate-800 dark:text-slate-100">{field.value}</span>
                            </div>
                        ))}
                    </div>
                    {(detail.created_at || detail.updated_at) && (
                        <div className="mt-4 flex flex-wrap gap-x-5 gap-y-1 border-t border-slate-100 pt-3 text-xs text-slate-500 dark:border-slate-800">
                            {detail.created_at && <span>Tạo lúc: {formatTime(detail.created_at)}</span>}
                            {detail.updated_at && <span>Cập nhật: {formatTime(detail.updated_at)}</span>}
                        </div>
                    )}
                </div>
            )}
        </Modal>
    );
}

const PersonalChatSubscription = memo(function PersonalChatSubscription({
    channel,
    onInboxChange,
    onMessage,
    onReaction,
    onRead,
    onSubscribed,
    onSubscriptionError,
}: {
    channel: string;
    onInboxChange: (event: ChatInboxEvent) => void;
    onMessage: (event: ChatMessageEvent) => void;
    onReaction: (event: ChatReactionEvent) => void;
    onRead: (event: ChatReadEvent) => void;
    onSubscribed: () => void;
    onSubscriptionError: () => void;
}) {
    const handlersRef = useRef({ onInboxChange, onMessage, onReaction, onRead, onSubscribed, onSubscriptionError });
    handlersRef.current = { onInboxChange, onMessage, onReaction, onRead, onSubscribed, onSubscriptionError };

    useEffect(() => {
        let active = true;
        const subscription = echo().private(channel);
        const handleInbox = (event: ChatInboxEvent) => {
            if (active) handlersRef.current.onInboxChange(event);
        };
        const handleMessage = (event: ChatMessageEvent) => {
            if (active) handlersRef.current.onMessage(event);
        };
        const handleReaction = (event: ChatReactionEvent) => {
            if (active) handlersRef.current.onReaction(event);
        };
        const handleRead = (event: ChatReadEvent) => {
            if (active) handlersRef.current.onRead(event);
        };
        const handleSubscribed = () => {
            if (active) handlersRef.current.onSubscribed();
        };
        const handleSubscriptionError = () => {
            if (active) handlersRef.current.onSubscriptionError();
        };
        subscription.listen('.ChatInboxUpdated', handleInbox);
        subscription.listen('.ChatMessageSent', handleMessage);
        subscription.listen('.ChatMessageReactionUpdated', handleReaction);
        subscription.listen('.ChatReadUpdated', handleRead);
        subscription.on('pusher:subscription_succeeded', handleSubscribed);
        subscription.on('pusher:subscription_error', handleSubscriptionError);
        if ((subscription as unknown as { subscription?: { subscribed?: boolean } }).subscription?.subscribed) {
            queueMicrotask(() => {
                if (active) handleSubscribed();
            });
        }

        return () => {
            active = false;
            subscription.stopListening('.ChatInboxUpdated', handleInbox);
            subscription.stopListening('.ChatMessageSent', handleMessage);
            subscription.stopListening('.ChatMessageReactionUpdated', handleReaction);
            subscription.stopListening('.ChatReadUpdated', handleRead);
            subscription.stopListening('.pusher:subscription_succeeded', handleSubscribed);
            subscription.stopListening('.pusher:subscription_error', handleSubscriptionError);
            echo().leave(channel);
        };
    }, [channel]);
    return null;
});

export default function ChatWorkspace({
    mode,
    baseUrl,
    compact = false,
    initialConversationId = null,
}: ChatWorkspaceProps) {
    const { props } = usePage<PageProps>();
    const currentUserId = Number(props.auth.user?.id);
    const roles = Array.isArray(props.auth.roles) ? props.auth.roles : [];
    const permissions = Array.isArray(props.auth.permissions) ? props.auth.permissions : [];
    const canViewAllChats = mode === 'agent' && (
        props.auth.is_super_admin
        || roles.includes('admin')
        || permissions.includes('chats.view_all')
    );
    const realtimeChannel = props.auth.realtime_channel;
    const canWriteInternalNote = props.auth.is_super_admin || permissions.includes('chats.manage');
    const canAssignGlobally = canViewAllChats
        && (props.auth.is_super_admin || permissions.includes('chats.assign'));

    const [conversations, setConversations] = useState<ChatConversation[]>([]);
    const [unreadTotal, setUnreadTotal] = useState(0);
    const [selectedId, setSelectedId] = useState<number | null>(initialConversationId);
    const [selected, setSelected] = useState<ChatConversation | null>(null);
    const [messages, setMessages] = useState<ChatMessage[]>([]);
    const [contexts, setContexts] = useState<ChatSubject[]>([]);
    const [agents, setAgents] = useState<ChatUser[]>([]);
    const [search, setSearch] = useState('');
    const [status, setStatus] = useState('');
    const [inboxView, setInboxView] = useState<ChatInboxView>('active');
    const [completedPeriod, setCompletedPeriod] = useState<CompletedPeriod>('all');
    const [conversationCounts, setConversationCounts] = useState<ChatConversationCounts>({});
    const [conversationTotal, setConversationTotal] = useState(0);
    const [completionUndo, setCompletionUndo] = useState<CompletionUndo | null>(null);
    const [assignment, setAssignment] = useState(mode === 'agent' && !canViewAllChats ? 'mine' : '');
    const [creating, setCreating] = useState(false);
    const [loadingList, setLoadingList] = useState(true);
    const [loadingMoreConversations, setLoadingMoreConversations] = useState(false);
    const [conversationPage, setConversationPage] = useState(1);
    const [conversationLastPage, setConversationLastPage] = useState(1);
    const [loadingThread, setLoadingThread] = useState(false);
    const [loadingOlderMessages, setLoadingOlderMessages] = useState(false);
    const [hasMoreMessages, setHasMoreMessages] = useState(false);
    const [sending, setSending] = useState(false);
    const [actionLoading, setActionLoading] = useState(false);
    const [subjectDetailLoading, setSubjectDetailLoading] = useState(false);
    const [relatedOrderDetail, setRelatedOrderDetail] = useState<RelatedOrderDetail | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [draft, setDraft] = useState('');
    const [pendingImages, setPendingImages] = useState<PendingChatImage[]>([]);
    const [emojiPickerOpen, setEmojiPickerOpen] = useState(false);
    const [reactionPickerMessageId, setReactionPickerMessageId] = useState<number | null>(null);
    const [lightboxAttachment, setLightboxAttachment] = useState<ChatAttachment | null>(null);
    const [internalNote, setInternalNote] = useState(false);
    const [realtimeStatus, setRealtimeStatus] = useState<RealtimeConnectionStatus>('connecting');
    const conversationPerPage = compact ? 15 : 30;
    const conversationFilterSignature = useMemo(() => JSON.stringify({
        assignment: mode === 'agent' ? assignment : '',
        baseUrl,
        perPage: conversationPerPage,
        period: mode === 'agent' && inboxView === 'completed' ? completedPeriod : '',
        search,
        status,
        view: mode === 'agent' ? inboxView : '',
    }), [assignment, baseUrl, completedPeriod, conversationPerPage, inboxView, mode, search, status]);
    const messagesEndRef = useRef<HTMLDivElement | null>(null);
    const messageScrollRef = useRef<HTMLDivElement | null>(null);
    const composerRef = useRef<HTMLTextAreaElement | null>(null);
    const imageInputRef = useRef<HTMLInputElement | null>(null);
    const composerToolsRef = useRef<HTMLDivElement | null>(null);
    const preservingHistoryScrollRef = useRef(false);
    const messageNearBottomRef = useRef(true);
    const previousLastMessageKeyRef = useRef<string | null>(null);
    const pendingSendRef = useRef<{ signature: string; clientMessageId: string } | null>(null);
    const failedSendsRef = useRef<Map<string, FailedChatSend>>(new Map());
    const localImageUrlsRef = useRef<Set<string>>(new Set());
    const openRequestRef = useRef(0);
    const selectedIdRef = useRef<number | null>(selectedId);
    const conversationFilterSignatureRef = useRef(conversationFilterSignature);
    const conversationListGenerationRef = useRef(0);
    const conversationListAbortRef = useRef<AbortController | null>(null);
    const conversationMoreGenerationRef = useRef(0);
    const conversationMoreAbortRef = useRef<AbortController | null>(null);
    const olderMessagesGenerationRef = useRef(0);
    const olderMessagesAbortRef = useRef<AbortController | null>(null);
    const conversationsRef = useRef<ChatConversation[]>(conversations);
    const selectedRef = useRef<ChatConversation | null>(selected);
    const messagesRef = useRef<ChatMessage[]>(messages);
    const unreadTotalRef = useRef(unreadTotal);
    const fetchConversationsRef = useRef<(quiet?: boolean) => Promise<void>>(async () => undefined);
    const realtimeRefreshTimerRef = useRef<number | null>(null);
    const readTimersRef = useRef<Map<number, number>>(new Map());
    const pendingReadIdsRef = useRef<Map<number, number>>(new Map());
    const sentReadIdsRef = useRef<Map<number, number>>(new Map());
    const readRetryStatesRef = useRef<Map<number, ReadRetryState>>(new Map());
    const readRollbackSnapshotsRef = useRef<Map<number, OptimisticReadRollback>>(new Map());
    const scheduleMarkReadRef = useRef<(conversationId: number, lastMessageId?: number) => void>(() => undefined);
    const recoveryGenerationRef = useRef(0);
    const recoveryAbortRef = useRef<AbortController | null>(null);
    const recoveryPendingRef = useRef(false);
    const recoveryCheckpointsRef = useRef<Map<number, number>>(new Map());
    const optimisticMessageIdRef = useRef(-1);
    const seenRealtimeMessageIdsRef = useRef<Set<number>>(new Set());
    const deliveredClientMessageIdsRef = useRef<Set<string>>(new Set());
    const latestKnownMessageIdsRef = useRef<Map<number, number>>(new Map());
    const reactionRequestsRef = useRef<Set<string>>(new Set());
    const attachmentRefreshAtRef = useRef<Map<string, number>>(new Map());
    const listSnapshotHighWatermarksRef = useRef<Map<number, number>>(new Map());
    const listSnapshotVersionsRef = useRef<Map<number, number>>(new Map());
    const listSnapshotVersionRef = useRef(0);
    const realtimeConversationOverlaysRef = useRef<Map<number, ChatConversationRealtimeOverlay>>(new Map());
    conversationFilterSignatureRef.current = conversationFilterSignature;

    const commitSelected = useCallback((next: ChatConversation | null) => {
        selectedRef.current = next;
        setSelected(next);
    }, []);

    const clearSelection = useCallback(() => {
        selectedIdRef.current = null;
        openRequestRef.current += 1;
        setSelectedId(null);
    }, []);

    const recordConversationListSnapshot = useCallback((items: ChatConversation[]) => {
        const snapshotVersion = ++listSnapshotVersionRef.current;
        for (const conversation of items) {
            listSnapshotVersionsRef.current.set(conversation.id, snapshotVersion);
            const latestMessageId = conversation.latest_message_id ?? conversation.last_message?.id ?? 0;
            if (latestMessageId <= 0) continue;

            listSnapshotHighWatermarksRef.current.set(conversation.id, Math.max(
                listSnapshotHighWatermarksRef.current.get(conversation.id) ?? 0,
                latestMessageId,
            ));
            latestKnownMessageIdsRef.current.set(conversation.id, Math.max(
                latestKnownMessageIdsRef.current.get(conversation.id) ?? 0,
                latestMessageId,
            ));
        }
    }, []);

    const mergeConversationSnapshot = useCallback((
        conversationId: number,
        snapshot: ChatConversation,
        snapshotMessages: ChatMessage[] = [],
        requestMessageCheckpoint = 0,
    ): ChatConversation => {
        const snapshotLastMessageId = snapshotMessages.reduce(
            (latest, message) => message.id > latest ? message.id : latest,
            Math.max(
                requestMessageCheckpoint,
                snapshot.last_message?.id && snapshot.last_message.id > 0 ? snapshot.last_message.id : 0,
            ),
        );
        const overlay = realtimeConversationOverlaysRef.current.get(conversationId);

        if (!overlay || overlay.message.id <= snapshotLastMessageId) {
            if (overlay) realtimeConversationOverlaysRef.current.delete(conversationId);
            return snapshot;
        }

        const newerMessages = messagesRef.current
            .filter(message => message.conversation_id === conversationId
                && message.id > snapshotLastMessageId)
            .sort((left, right) => left.id - right.id);
        let merged = snapshot;
        for (const message of newerMessages) {
            merged = applyMessageToConversation(merged, message);
        }
        merged = applyMessageToConversation(merged, overlay.message, overlay.summary);

        const trackedConversation = conversationsRef.current.find(item => item.id === conversationId);
        return trackedConversation
            ? { ...merged, unread_count: trackedConversation.unread_count }
            : merged;
    }, []);

    useEffect(() => {
        setRealtimeStatus(realtimeChannel ? 'connecting' : 'disconnected');
    }, [realtimeChannel]);

    useEffect(() => {
        conversationsRef.current = conversations;
    }, [conversations]);

    useEffect(() => {
        messagesRef.current = messages;
    }, [messages]);

    useEffect(() => {
        unreadTotalRef.current = unreadTotal;
    }, [unreadTotal]);

    useEffect(() => {
        if (!completionUndo) return;
        const conversationId = completionUndo.conversationId;
        const timer = window.setTimeout(() => {
            setCompletionUndo(current => current?.conversationId === conversationId ? null : current);
        }, 5000);

        return () => window.clearTimeout(timer);
    }, [completionUndo]);

    useEffect(() => () => {
        openRequestRef.current += 1;
        conversationListGenerationRef.current += 1;
        conversationMoreGenerationRef.current += 1;
        olderMessagesGenerationRef.current += 1;
        conversationListAbortRef.current?.abort();
        conversationMoreAbortRef.current?.abort();
        olderMessagesAbortRef.current?.abort();
        recoveryGenerationRef.current += 1;
        recoveryAbortRef.current?.abort();
        recoveryPendingRef.current = false;
        readTimersRef.current.forEach(timer => window.clearTimeout(timer));
        readTimersRef.current.clear();
        if (realtimeRefreshTimerRef.current !== null) window.clearTimeout(realtimeRefreshTimerRef.current);
        localImageUrlsRef.current.forEach(url => URL.revokeObjectURL(url));
        localImageUrlsRef.current.clear();
        failedSendsRef.current.clear();
        attachmentRefreshAtRef.current.clear();
    }, []);

    useEffect(() => {
        if (!emojiPickerOpen && reactionPickerMessageId === null) return;

        const closePicker = (event: MouseEvent) => {
            if (!composerToolsRef.current?.contains(event.target as Node)) setEmojiPickerOpen(false);
            if (!(event.target as Element).closest?.('[data-reaction-picker]')) setReactionPickerMessageId(null);
        };
        const closeOnEscape = (event: globalThis.KeyboardEvent) => {
            if (event.key === 'Escape') {
                setEmojiPickerOpen(false);
                setReactionPickerMessageId(null);
            }
        };
        document.addEventListener('mousedown', closePicker);
        document.addEventListener('keydown', closeOnEscape);
        return () => {
            document.removeEventListener('mousedown', closePicker);
            document.removeEventListener('keydown', closeOnEscape);
        };
    }, [emojiPickerOpen, reactionPickerMessageId]);

    useEffect(() => {
        selectedIdRef.current = selectedId;
        olderMessagesGenerationRef.current += 1;
        olderMessagesAbortRef.current?.abort();
        olderMessagesAbortRef.current = null;
        recoveryGenerationRef.current += 1;
        recoveryAbortRef.current?.abort();
        recoveryAbortRef.current = null;
        preservingHistoryScrollRef.current = false;
        messageNearBottomRef.current = true;
        previousLastMessageKeyRef.current = null;
        setLoadingOlderMessages(false);
        commitSelected(null);
        messagesRef.current = [];
        setMessages([]);
        setDraft('');
        setPendingImages([]);
        setEmojiPickerOpen(false);
        setReactionPickerMessageId(null);
        setLightboxAttachment(null);
        setInternalNote(false);
        pendingSendRef.current = null;
        failedSendsRef.current.clear();
        attachmentRefreshAtRef.current.clear();
        localImageUrlsRef.current.forEach(url => URL.revokeObjectURL(url));
        localImageUrlsRef.current.clear();
    }, [commitSelected, selectedId]);

    const fetchConversations = useCallback(async (quiet = false) => {
        if (conversationFilterSignature !== conversationFilterSignatureRef.current) return;

        const requestSignature = conversationFilterSignature;
        const requestGeneration = ++conversationListGenerationRef.current;
        const controller = new AbortController();
        conversationListAbortRef.current?.abort();
        conversationListAbortRef.current = controller;
        conversationMoreGenerationRef.current += 1;
        conversationMoreAbortRef.current?.abort();
        conversationMoreAbortRef.current = null;
        setLoadingMoreConversations(false);
        if (!quiet) setLoadingList(true);
        try {
            const response = await window.axios.get<ChatConversationListResponse>(`${baseUrl}/conversations`, {
                params: {
                    search: search || undefined,
                    status: status || undefined,
                    assignment: mode === 'agent' ? assignment || undefined : undefined,
                    view: mode === 'agent' ? inboxView : undefined,
                    period: mode === 'agent' && inboxView === 'completed' ? completedPeriod : undefined,
                    per_page: conversationPerPage,
                },
                signal: controller.signal,
            });
            if (controller.signal.aborted
                || requestGeneration !== conversationListGenerationRef.current
                || requestSignature !== conversationFilterSignatureRef.current) return;
            const visibleConversations = mode === 'agent'
                ? response.data.data.filter(conversation => statusBelongsToView(conversation.status, inboxView))
                : response.data.data;
            const nextConversations = sortConversations(
                visibleConversations,
                mode === 'agent' ? inboxView : undefined,
            );
            recordConversationListSnapshot(nextConversations);
            setConversations(nextConversations);
            conversationsRef.current = nextConversations;
            unreadTotalRef.current = response.data.unread_total;
            setUnreadTotal(response.data.unread_total);
            setConversationCounts(response.data.counts ?? response.data.meta?.counts ?? {});
            setConversationTotal(response.data.meta?.total ?? nextConversations.length);
            setConversationPage(response.data.meta?.current_page ?? 1);
            setConversationLastPage(response.data.meta?.last_page ?? 1);
            setError(null);

            if (!selectedIdRef.current && initialConversationId) setSelectedId(initialConversationId);
        } catch (requestError) {
            if (isCanceledRequest(requestError)
                || requestGeneration !== conversationListGenerationRef.current
                || requestSignature !== conversationFilterSignatureRef.current) return;
            setError(errorMessage(requestError));
        } finally {
            if (requestGeneration === conversationListGenerationRef.current
                && requestSignature === conversationFilterSignatureRef.current) {
                setLoadingList(false);
                if (conversationListAbortRef.current === controller) conversationListAbortRef.current = null;
            }
        }
    }, [assignment, baseUrl, completedPeriod, conversationFilterSignature, conversationPerPage, inboxView, initialConversationId, mode, recordConversationListSnapshot, search, status]);
    fetchConversationsRef.current = fetchConversations;

    useEffect(() => {
        conversationListGenerationRef.current += 1;
        conversationListAbortRef.current?.abort();
        conversationListAbortRef.current = null;
        conversationMoreGenerationRef.current += 1;
        conversationMoreAbortRef.current?.abort();
        conversationMoreAbortRef.current = null;
        setLoadingList(true);
        setLoadingMoreConversations(false);
        const timer = window.setTimeout(() => void fetchConversations(), search ? 250 : 0);
        return () => {
            window.clearTimeout(timer);
            conversationListGenerationRef.current += 1;
            conversationMoreGenerationRef.current += 1;
            conversationListAbortRef.current?.abort();
            conversationMoreAbortRef.current?.abort();
        };
    }, [conversationFilterSignature, fetchConversations, search]);

    const clearUnreadLocally = useCallback((conversationId: number) => {
        const trackedConversation = conversationsRef.current.find(item => item.id === conversationId);
        const unread = trackedConversation?.unread_count ?? 0;
        if (unread > 0) {
            const nextUnreadTotal = Math.max(0, unreadTotalRef.current - unread);
            unreadTotalRef.current = nextUnreadTotal;
            setUnreadTotal(nextUnreadTotal);
        }

        const next = conversationsRef.current.map(item => item.id === conversationId
            ? { ...item, unread_count: 0 }
            : item);
        conversationsRef.current = next;
        setConversations(next);
        if (unread > 0) {
            window.dispatchEvent(new CustomEvent('chat:unread-changed', { detail: { delta: -unread } }));
        }
        return trackedConversation !== undefined;
    }, []);

    const restoreUnreadLocally = useCallback((
        conversationId: number,
        rollback: OptimisticReadRollback,
    ) => {
        const trackedConversation = conversationsRef.current.find(item => item.id === conversationId);
        if (!trackedConversation
            || (listSnapshotVersionsRef.current.get(conversationId) ?? 0) !== rollback.listSnapshotVersion) {
            return false;
        }

        const restoredUnread = trackedConversation.unread_count + rollback.unreadCount;
        const delta = restoredUnread - trackedConversation.unread_count;
        if (delta <= 0) return true;

        const next = conversationsRef.current.map(item => item.id === conversationId
            ? { ...item, unread_count: restoredUnread }
            : item);
        conversationsRef.current = next;
        setConversations(next);
        const nextUnreadTotal = unreadTotalRef.current + delta;
        unreadTotalRef.current = nextUnreadTotal;
        setUnreadTotal(nextUnreadTotal);
        window.dispatchEvent(new CustomEvent('chat:unread-changed', { detail: { delta } }));
        return true;
    }, []);

    const scheduleMarkRead = useCallback((conversationId: number, lastMessageId?: number) => {
        if (!lastMessageId || lastMessageId <= 0) return;

        const sentCheckpoint = sentReadIdsRef.current.get(conversationId) ?? 0;
        const pendingId = Math.max(pendingReadIdsRef.current.get(conversationId) ?? 0, lastMessageId);
        pendingReadIdsRef.current.set(conversationId, pendingId);
        const retryState = readRetryStatesRef.current.get(conversationId);
        if (!retryState || pendingId > retryState.checkpoint) {
            readRetryStatesRef.current.set(conversationId, { checkpoint: pendingId, failures: 0 });
        } else if (retryState.checkpoint === pendingId && retryState.failures >= 4) {
            return;
        }
        if (pendingId <= sentCheckpoint) {
            pendingReadIdsRef.current.delete(conversationId);
            readRetryStatesRef.current.delete(conversationId);
            readRollbackSnapshotsRef.current.delete(conversationId);
            return;
        }
        if (selectedIdRef.current !== conversationId || !isPageActive() || !messageNearBottomRef.current) return;

        const trackedBeforeClear = conversationsRef.current.find(item => item.id === conversationId);
        const listSnapshotVersion = listSnapshotVersionsRef.current.get(conversationId) ?? 0;
        const existingRollback = readRollbackSnapshotsRef.current.get(conversationId);
        if (trackedBeforeClear && trackedBeforeClear.unread_count > 0) {
            readRollbackSnapshotsRef.current.set(conversationId, {
                unreadCount: existingRollback?.listSnapshotVersion === listSnapshotVersion
                    ? existingRollback.unreadCount + trackedBeforeClear.unread_count
                    : trackedBeforeClear.unread_count,
                listSnapshotVersion,
                checkpoint: pendingId,
            });
        } else if (existingRollback) {
            readRollbackSnapshotsRef.current.set(conversationId, {
                ...existingRollback,
                checkpoint: Math.max(existingRollback.checkpoint, pendingId),
            });
        }
        const conversationIsTracked = clearUnreadLocally(conversationId);

        const existingTimer = readTimersRef.current.get(conversationId);
        if (existingTimer !== undefined) window.clearTimeout(existingTimer);

        const timer = window.setTimeout(async () => {
            readTimersRef.current.delete(conversationId);
            const messageId = pendingReadIdsRef.current.get(conversationId) ?? 0;
            if (!messageId || messageId <= (sentReadIdsRef.current.get(conversationId) ?? 0)) {
                pendingReadIdsRef.current.delete(conversationId);
                const rollback = readRollbackSnapshotsRef.current.get(conversationId);
                if (rollback && rollback.checkpoint <= (sentReadIdsRef.current.get(conversationId) ?? 0)) {
                    readRollbackSnapshotsRef.current.delete(conversationId);
                }
                const currentRetryState = readRetryStatesRef.current.get(conversationId);
                if (currentRetryState && currentRetryState.checkpoint <= (sentReadIdsRef.current.get(conversationId) ?? 0)) {
                    readRetryStatesRef.current.delete(conversationId);
                }
                return;
            }
            if (selectedIdRef.current !== conversationId || !isPageActive() || !messageNearBottomRef.current) {
                const rollback = readRollbackSnapshotsRef.current.get(conversationId);
                if (rollback) {
                    restoreUnreadLocally(conversationId, rollback);
                    readRollbackSnapshotsRef.current.delete(conversationId);
                }
                return;
            }
            pendingReadIdsRef.current.delete(conversationId);

            try {
                await window.axios.patch(`${baseUrl}/conversations/${conversationId}/read`, {
                    last_read_message_id: messageId,
                });
                sentReadIdsRef.current.set(conversationId, Math.max(
                    sentReadIdsRef.current.get(conversationId) ?? 0,
                    messageId,
                ));
                const rollback = readRollbackSnapshotsRef.current.get(conversationId);
                if (rollback && rollback.checkpoint <= messageId) {
                    readRollbackSnapshotsRef.current.delete(conversationId);
                }
                const currentRetryState = readRetryStatesRef.current.get(conversationId);
                if (currentRetryState && currentRetryState.checkpoint <= messageId) {
                    readRetryStatesRef.current.delete(conversationId);
                }
                if (!conversationIsTracked) void fetchConversations(true);
            } catch {
                if ((sentReadIdsRef.current.get(conversationId) ?? 0) >= messageId) {
                    readRollbackSnapshotsRef.current.delete(conversationId);
                    readRetryStatesRef.current.delete(conversationId);
                    return;
                }
                const queued = pendingReadIdsRef.current.get(conversationId) ?? 0;
                pendingReadIdsRef.current.set(conversationId, Math.max(queued, messageId));
                const currentRetryState = readRetryStatesRef.current.get(conversationId);
                const isLatestAttempt = !currentRetryState || currentRetryState.checkpoint <= messageId;
                if (!isLatestAttempt) {
                    void fetchConversations(true);
                    return;
                }

                const rollback = readRollbackSnapshotsRef.current.get(conversationId);
                if (rollback) {
                    restoreUnreadLocally(conversationId, rollback);
                    readRollbackSnapshotsRef.current.delete(conversationId);
                }
                const failures = currentRetryState?.checkpoint === messageId
                    ? currentRetryState.failures + 1
                    : 1;
                readRetryStatesRef.current.set(conversationId, { checkpoint: messageId, failures });
                void fetchConversations(true);

                if (failures > 3
                    || selectedIdRef.current !== conversationId
                    || !isPageActive()
                    || !messageNearBottomRef.current
                    || readTimersRef.current.has(conversationId)) return;

                const retryDelay = 500 * (2 ** (failures - 1));
                const retryTimer = window.setTimeout(() => {
                    readTimersRef.current.delete(conversationId);
                    if (selectedIdRef.current !== conversationId
                        || !isPageActive()
                        || !messageNearBottomRef.current) return;
                    const retryMessageId = pendingReadIdsRef.current.get(conversationId) ?? 0;
                    if (retryMessageId > (sentReadIdsRef.current.get(conversationId) ?? 0)) {
                        scheduleMarkReadRef.current(conversationId, retryMessageId);
                    }
                }, retryDelay);
                readTimersRef.current.set(conversationId, retryTimer);
            }
        }, 250);
        readTimersRef.current.set(conversationId, timer);
    }, [baseUrl, clearUnreadLocally, fetchConversations, restoreUnreadLocally]);
    scheduleMarkReadRef.current = scheduleMarkRead;

    const flushPendingRead = useCallback(() => {
        const conversationId = selectedIdRef.current;
        if (!conversationId) return;

        const latestMessageId = messagesRef.current.reduce(
            (highest, message) => message.conversation_id === conversationId && message.id > highest
                ? message.id
                : highest,
            latestKnownMessageIdsRef.current.get(conversationId) ?? 0,
        );
        const pendingMessageId = pendingReadIdsRef.current.get(conversationId) ?? 0;
        const checkpoint = Math.max(latestMessageId, pendingMessageId);
        if (checkpoint > 0) scheduleMarkRead(conversationId, checkpoint);
    }, [scheduleMarkRead]);

    const scheduleConversationRefresh = useCallback(() => {
        conversationListGenerationRef.current += 1;
        conversationListAbortRef.current?.abort();
        conversationListAbortRef.current = null;
        conversationMoreGenerationRef.current += 1;
        conversationMoreAbortRef.current?.abort();
        conversationMoreAbortRef.current = null;
        setLoadingMoreConversations(false);
        if (realtimeRefreshTimerRef.current !== null) window.clearTimeout(realtimeRefreshTimerRef.current);
        realtimeRefreshTimerRef.current = window.setTimeout(() => {
            realtimeRefreshTimerRef.current = null;
            void fetchConversationsRef.current(true);
        }, 100);
    }, []);

    const openConversation = useCallback(async (conversationId: number) => {
        selectedIdRef.current = conversationId;
        const requestId = ++openRequestRef.current;
        setSelectedId(conversationId);
        setLoadingThread(true);
        setError(null);
        try {
            const response = await window.axios.get<{
                data: ChatConversation;
                messages: ChatMessage[];
                has_more: boolean;
            }>(`${baseUrl}/conversations/${conversationId}`);
            if (requestId !== openRequestRef.current || selectedIdRef.current !== conversationId) return;

            // Keep local optimistic messages, but let the fresh server snapshot
            // replace persisted fields such as expiring signed attachment URLs.
            const normalizedMessages = mergeMessages(
                messagesRef.current.filter(message => message.conversation_id === conversationId),
                response.data.messages,
            );
            const responseLastMessageId = response.data.messages.reduce(
                (latest, message) => message.id > latest ? message.id : latest,
                0,
            );
            if (responseLastMessageId > 0) {
                recoveryCheckpointsRef.current.set(conversationId, Math.max(
                    recoveryCheckpointsRef.current.get(conversationId) ?? 0,
                    responseLastMessageId,
                ));
                latestKnownMessageIdsRef.current.set(conversationId, Math.max(
                    latestKnownMessageIdsRef.current.get(conversationId) ?? 0,
                    responseLastMessageId,
                ));
            }
            commitSelected(mergeConversationSnapshot(
                conversationId,
                response.data.data,
                response.data.messages,
            ));
            messagesRef.current = normalizedMessages;
            setMessages(normalizedMessages);
            setHasMoreMessages(response.data.has_more);
            const lastMessage = normalizedMessages.reduce<ChatMessage | null>((latest, message) => (
                message.id > 0 && (!latest || message.id > latest.id) ? message : latest
            ), null);
            if (lastMessage) scheduleMarkRead(conversationId, lastMessage.id);
        } catch (requestError) {
            if (requestId !== openRequestRef.current || selectedIdRef.current !== conversationId) return;

            setError(errorMessage(requestError));
            commitSelected(null);
            messagesRef.current = [];
            setMessages([]);
            setHasMoreMessages(false);
            const statusCode = (requestError as { response?: { status?: number } }).response?.status;
            if (statusCode === 403 || statusCode === 404) {
                selectedIdRef.current = null;
                setSelectedId(null);
            }
        } finally {
            if (requestId === openRequestRef.current) setLoadingThread(false);
        }
    }, [baseUrl, commitSelected, mergeConversationSnapshot, scheduleMarkRead]);

    const refreshExpiredAttachment = useCallback((attachment: ChatAttachment) => {
        const conversationId = selectedIdRef.current;
        if (!conversationId || !attachment.uuid) return;

        const now = Date.now();
        const lastRefreshAt = attachmentRefreshAtRef.current.get(attachment.uuid) ?? 0;
        if (now - lastRefreshAt < 60_000) return;

        attachmentRefreshAtRef.current.set(attachment.uuid, now);
        void openConversation(conversationId);
    }, [openConversation]);

    useEffect(() => {
        if (selectedId) void openConversation(selectedId);
        else {
            commitSelected(null);
            messagesRef.current = [];
            setMessages([]);
            setHasMoreMessages(false);
        }
    }, [commitSelected, openConversation, selectedId]);

    useEffect(() => {
        if (preservingHistoryScrollRef.current) {
            preservingHistoryScrollRef.current = false;
            return;
        }
        if (loadingThread) return;
        const lastMessage = messages.at(-1);
        const lastMessageKey = lastMessage
            ? String(lastMessage.client_message_id ?? lastMessage.id)
            : null;
        const appended = lastMessageKey !== previousLastMessageKeyRef.current;
        previousLastMessageKeyRef.current = lastMessageKey;
        if (!appended || !lastMessage) return;
        if (messageNearBottomRef.current || isMessageMine(lastMessage, currentUserId)) {
            messagesEndRef.current?.scrollIntoView({ block: 'end' });
        }
    }, [currentUserId, loadingThread, messages]);

    const loadMoreConversations = async () => {
        if (conversationFilterSignature !== conversationFilterSignatureRef.current
            || conversationListAbortRef.current
            || conversationMoreAbortRef.current
            || conversationPage >= conversationLastPage) return;

        const requestSignature = conversationFilterSignature;
        const requestGeneration = ++conversationMoreGenerationRef.current;
        const requestPage = conversationPage + 1;
        const controller = new AbortController();
        conversationMoreAbortRef.current = controller;
        setLoadingMoreConversations(true);
        try {
            const response = await window.axios.get<ChatConversationListResponse>(`${baseUrl}/conversations`, {
                params: {
                    search: search || undefined,
                    status: status || undefined,
                    assignment: mode === 'agent' ? assignment || undefined : undefined,
                    view: mode === 'agent' ? inboxView : undefined,
                    period: mode === 'agent' && inboxView === 'completed' ? completedPeriod : undefined,
                    per_page: conversationPerPage,
                    page: requestPage,
                },
                signal: controller.signal,
            });
            if (controller.signal.aborted
                || requestGeneration !== conversationMoreGenerationRef.current
                || requestSignature !== conversationFilterSignatureRef.current) return;
            const visibleConversations = mode === 'agent'
                ? response.data.data.filter(conversation => statusBelongsToView(conversation.status, inboxView))
                : response.data.data;
            recordConversationListSnapshot(visibleConversations);
            setConversations(previous => {
                const snapshotsById = new Map(visibleConversations.map(conversation => [conversation.id, conversation]));
                const existingIds = new Set(previous.map(conversation => conversation.id));
                const next = sortConversations([
                    ...previous.map(conversation => snapshotsById.get(conversation.id) ?? conversation),
                    ...visibleConversations.filter(conversation => !existingIds.has(conversation.id)),
                ], mode === 'agent' ? inboxView : undefined);
                conversationsRef.current = next;
                return next;
            });
            unreadTotalRef.current = response.data.unread_total;
            setUnreadTotal(response.data.unread_total);
            setConversationCounts(response.data.counts ?? response.data.meta?.counts ?? {});
            setConversationTotal(response.data.meta?.total ?? conversationTotal);
            setConversationPage(response.data.meta?.current_page ?? requestPage);
            setConversationLastPage(response.data.meta?.last_page ?? conversationLastPage);
        } catch (requestError) {
            if (isCanceledRequest(requestError)
                || requestGeneration !== conversationMoreGenerationRef.current
                || requestSignature !== conversationFilterSignatureRef.current) return;
            setError(errorMessage(requestError));
        } finally {
            if (requestGeneration === conversationMoreGenerationRef.current
                && requestSignature === conversationFilterSignatureRef.current) {
                setLoadingMoreConversations(false);
                if (conversationMoreAbortRef.current === controller) conversationMoreAbortRef.current = null;
            }
        }
    };

    const loadOlderMessages = async () => {
        const before = messages[0]?.id;
        if (!selectedId || !before || olderMessagesAbortRef.current || !hasMoreMessages) return;

        const conversationId = selectedId;
        const requestGeneration = ++olderMessagesGenerationRef.current;
        const controller = new AbortController();
        olderMessagesAbortRef.current = controller;
        setLoadingOlderMessages(true);
        const scrollContainer = messageScrollRef.current;

        try {
            const response = await window.axios.get<{
                data: ChatMessage[];
                has_more: boolean;
            }>(`${baseUrl}/conversations/${conversationId}/messages`, {
                params: { before, limit: 60 },
                signal: controller.signal,
            });
            if (controller.signal.aborted
                || requestGeneration !== olderMessagesGenerationRef.current
                || selectedIdRef.current !== conversationId) return;

            const olderMessages = response.data.data;
            const previousScrollHeight = scrollContainer?.scrollHeight ?? 0;
            preservingHistoryScrollRef.current = true;
            setMessages(previous => {
                const next = mergeMessages(previous, olderMessages);
                messagesRef.current = next;
                return next;
            });
            setHasMoreMessages(response.data.has_more);
            window.requestAnimationFrame(() => {
                if (scrollContainer) {
                    scrollContainer.scrollTop = scrollContainer.scrollHeight - previousScrollHeight;
                }
            });
        } catch (requestError) {
            if (isCanceledRequest(requestError)
                || requestGeneration !== olderMessagesGenerationRef.current
                || selectedIdRef.current !== conversationId) return;
            setError(errorMessage(requestError));
        } finally {
            if (requestGeneration === olderMessagesGenerationRef.current
                && selectedIdRef.current === conversationId) {
                setLoadingOlderMessages(false);
                if (olderMessagesAbortRef.current === controller) olderMessagesAbortRef.current = null;
            }
        }
    };

    useEffect(() => {
        if (mode !== 'customer') return;
        void window.axios.get<{ data: ChatSubject[] }>(`${baseUrl}/contexts`)
            .then(response => setContexts(response.data.data))
            .catch(() => setContexts([]));
    }, [baseUrl, mode]);

    useEffect(() => {
        if (mode !== 'agent' || !canAssignGlobally) return;
        void window.axios.get<{ data: ChatUser[] }>(`${baseUrl}/agents`)
            .then(response => setAgents(response.data.data))
            .catch(() => setAgents([]));
    }, [baseUrl, canAssignGlobally, mode]);

    const applyUnreadDelta = useCallback((delta: number) => {
        if (!delta) return;
        const next = Math.max(0, unreadTotalRef.current + delta);
        unreadTotalRef.current = next;
        setUnreadTotal(next);
        window.dispatchEvent(new CustomEvent('chat:unread-changed', { detail: { delta } }));
    }, []);

    const matchesActiveFilters = useCallback((summary: ChatConversationRealtimeSummary) => {
        if (mode === 'agent' && !statusBelongsToView(summary.status, inboxView)) return false;
        if (status && summary.status !== status) return false;
        if (mode !== 'agent' || !canViewAllChats) return true;
        if (assignment === 'mine' && summary.assigned_to_id !== undefined) {
            return summary.assigned_to_id === currentUserId;
        }
        if (assignment === 'unassigned' && summary.assigned_to_id !== undefined) {
            return summary.assigned_to_id === null;
        }
        return true;
    }, [assignment, canViewAllChats, currentUserId, inboxView, mode, status]);

    const updateConversationFromMessage = useCallback((
        incoming: ChatMessage,
        summary?: ChatConversationRealtimeSummary,
    ) => {
        if (incoming.id > 0) {
            latestKnownMessageIdsRef.current.set(incoming.conversation_id, Math.max(
                latestKnownMessageIdsRef.current.get(incoming.conversation_id) ?? 0,
                incoming.id,
            ));
            const currentOverlay = realtimeConversationOverlaysRef.current.get(incoming.conversation_id);
            if (!currentOverlay || incoming.id >= currentOverlay.message.id) {
                realtimeConversationOverlaysRef.current.set(incoming.conversation_id, {
                    message: incoming,
                    summary: summary ?? (incoming.id === currentOverlay?.message.id ? currentOverlay.summary : undefined),
                });
                if (realtimeConversationOverlaysRef.current.size > 1000) {
                    const oldestConversationId = realtimeConversationOverlaysRef.current.keys().next().value;
                    if (typeof oldestConversationId === 'number') {
                        realtimeConversationOverlaysRef.current.delete(oldestConversationId);
                    }
                }
            }
        }
        if (incoming.id > 0 && incoming.client_message_id) {
            deliveredClientMessageIdsRef.current.add(incoming.client_message_id);
            if (deliveredClientMessageIdsRef.current.size > 2000) {
                const oldestClientMessageId = deliveredClientMessageIdsRef.current.values().next().value;
                if (typeof oldestClientMessageId === 'string') {
                    deliveredClientMessageIdsRef.current.delete(oldestClientMessageId);
                }
            }
        }
        if (conversationListAbortRef.current || conversationMoreAbortRef.current) scheduleConversationRefresh();
        const existing = conversationsRef.current.find(item => item.id === incoming.conversation_id);
        const currentSelected = selectedIdRef.current === incoming.conversation_id
            && selectedRef.current?.id === incoming.conversation_id
            ? selectedRef.current
            : null;
        const fallbackSummary: ChatConversationRealtimeSummary = summary ?? {
            id: incoming.conversation_id,
            status: incoming.is_internal
                ? existing?.status ?? currentSelected?.status ?? 'waiting_agent'
                : incoming.sender_kind === 'customer' ? 'waiting_agent' : 'waiting_customer',
            last_message_at: incoming.created_at,
        };
        const isSelected = selectedIdRef.current === incoming.conversation_id;

        if (!existing) {
            if (incoming.id > 0) {
                seenRealtimeMessageIdsRef.current.add(incoming.id);
                if (seenRealtimeMessageIdsRef.current.size > 2000) {
                    const oldestId = seenRealtimeMessageIdsRef.current.values().next().value;
                    if (typeof oldestId === 'number') seenRealtimeMessageIdsRef.current.delete(oldestId);
                }
            }
            if (isSelected && currentSelected) {
                commitSelected(applyMessageToConversation(currentSelected, incoming, fallbackSummary));
            }
            // Tin nhắn vẫn được hiển thị trực tiếp bằng realtime. Chỉ tải lại danh sách
            // đã debounce để đối soát số lượng tab và các hội thoại vừa đổi nhóm.
            if ((!incoming.is_internal && incoming.id > 0) || matchesActiveFilters(fallbackSummary)) {
                scheduleConversationRefresh();
            }
            return;
        }

        const previousLastMessage = existing.last_message;
        const alreadySeenRealtime = incoming.id > 0 && seenRealtimeMessageIdsRef.current.has(incoming.id);
        const alreadyAccountedByListSnapshot = incoming.id > 0
            && incoming.id <= (listSnapshotHighWatermarksRef.current.get(incoming.conversation_id) ?? 0);
        if (incoming.id > 0) {
            seenRealtimeMessageIdsRef.current.add(incoming.id);
            if (seenRealtimeMessageIdsRef.current.size > 2000) {
                const oldestId = seenRealtimeMessageIdsRef.current.values().next().value;
                if (typeof oldestId === 'number') seenRealtimeMessageIdsRef.current.delete(oldestId);
            }
        }
        const sameAsPersistedLast = incoming.id > 0 && (
            previousLastMessage?.id === incoming.id
            || Boolean(incoming.client_message_id
                && previousLastMessage?.id && previousLastMessage.id > 0
                && previousLastMessage.client_message_id === incoming.client_message_id)
        );
        const isNewestKnownMessage = !previousLastMessage
            || incoming.delivery_state !== undefined
            || previousLastMessage.id < 0
            || incoming.id > previousLastMessage.id
            || Boolean(incoming.client_message_id
                && incoming.client_message_id === previousLastMessage.client_message_id);
        const isLatestPublicMessage = !incoming.is_internal && (
            isNewestKnownMessage
        );
        const mine = isMessageMine(incoming, currentUserId);
        const isOptimisticMessage = incoming.delivery_state !== undefined;
        const isActivelyViewed = isSelected && isPageActive() && messageNearBottomRef.current;
        const nextUnread = isActivelyViewed
            ? 0
            : mine || alreadySeenRealtime || alreadyAccountedByListSnapshot || sameAsPersistedLast
                ? existing.unread_count
                : existing.unread_count + 1;
        const nextStatus = isLatestPublicMessage ? fallbackSummary.status : existing.status;
        if (!isOptimisticMessage && nextStatus !== existing.status) scheduleConversationRefresh();
        const nextAssignee = fallbackSummary.assignee !== undefined && isNewestKnownMessage
            ? fallbackSummary.assignee
            : fallbackSummary.assigned_to_id === currentUserId && !existing.assignee
                ? {
                    id: currentUserId,
                    username: props.auth.user?.username ?? 'Bạn',
                    avatar: props.auth.user?.avatar ?? null,
                }
                : existing.assignee;
        const effectiveSummary: ChatConversationRealtimeSummary = {
            ...fallbackSummary,
            status: nextStatus,
            assigned_to_id: fallbackSummary.assigned_to_id !== undefined && isNewestKnownMessage
                ? fallbackSummary.assigned_to_id
                : existing.assignee?.id ?? null,
        };
        const nextConversation: ChatConversation = {
            ...existing,
            id: existing.id,
            status: nextStatus,
            assignee: nextAssignee,
            last_message: isLatestPublicMessage ? incoming : existing.last_message,
            last_message_at: isLatestPublicMessage
                ? fallbackSummary.last_message_at ?? incoming.created_at
                : existing.last_message_at,
            unread_count: nextUnread,
        };
        const unreadDelta = nextUnread - existing.unread_count;
        // Giữ hội thoại tại chỗ trong lúc gửi lạc quan để có thể hoàn nguyên đầy đủ nếu POST thất bại.
        const remainsVisible = isOptimisticMessage || matchesActiveFilters(effectiveSummary);
        const nextConversations = remainsVisible
            ? sortConversations([
                nextConversation,
                ...conversationsRef.current.filter(item => item.id !== incoming.conversation_id),
            ], mode === 'agent' ? inboxView : undefined)
            : conversationsRef.current.filter(item => item.id !== incoming.conversation_id);

        conversationsRef.current = nextConversations;
        setConversations(nextConversations);
        applyUnreadDelta(remainsVisible ? unreadDelta : -existing.unread_count);

        if (isSelected && !remainsVisible) clearSelection();
        if (isSelected && currentSelected && remainsVisible) {
            commitSelected(applyMessageToConversation(currentSelected, incoming, {
                ...fallbackSummary,
                status: nextStatus,
                assignee: nextAssignee,
            }));
        }
    }, [applyUnreadDelta, clearSelection, commitSelected, currentUserId, inboxView, matchesActiveFilters, mode, scheduleConversationRefresh]);

    const recoverMissingMessages = useCallback(async () => {
        const conversationId = selectedIdRef.current;
        if (!conversationId) {
            recoveryPendingRef.current = false;
            return;
        }
        if (loadingThread) {
            recoveryPendingRef.current = true;
            return;
        }
        recoveryPendingRef.current = false;

        const lastPersistedMessageId = messagesRef.current.reduce(
            (latest, message) => message.conversation_id === conversationId && message.id > latest
                ? message.id
                : latest,
            0,
        );
        const requestGeneration = ++recoveryGenerationRef.current;
        const controller = new AbortController();
        recoveryAbortRef.current?.abort();
        recoveryAbortRef.current = controller;
        let afterId = recoveryCheckpointsRef.current.get(conversationId) ?? lastPersistedMessageId;
        let latestRecovered: ChatMessage | null = null;

        try {
            while (true) {
                const requestAfterId = afterId;
                const response = await window.axios.get<{
                    data: ChatMessage[];
                    has_more: boolean;
                    next_after?: number | null;
                }>(`${baseUrl}/conversations/${conversationId}/messages`, {
                    params: { after_id: afterId, limit: 100 },
                    signal: controller.signal,
                });
                if (controller.signal.aborted
                    || requestGeneration !== recoveryGenerationRef.current
                    || selectedIdRef.current !== conversationId) return;

                const recovered = response.data.data;
                if (recovered.length > 0) {
                    const batchLastMessage = recovered.reduce<ChatMessage | null>((latest, message) => (
                        message.id > 0 && (!latest || message.id > latest.id) ? message : latest
                    ), null);
                    if (batchLastMessage && (!latestRecovered || batchLastMessage.id > latestRecovered.id)) {
                        latestRecovered = batchLastMessage;
                    }
                    setMessages(previous => {
                        const next = mergeMessages(previous, recovered);
                        messagesRef.current = next;
                        return next;
                    });

                    if (batchLastMessage && batchLastMessage.id > afterId) {
                        afterId = batchLastMessage.id;
                        recoveryCheckpointsRef.current.set(conversationId, Math.max(
                            recoveryCheckpointsRef.current.get(conversationId) ?? 0,
                            afterId,
                        ));
                    }
                }

                if (!response.data.has_more) break;
                const nextAfter = response.data.next_after ?? 0;
                if (!nextAfter || nextAfter <= requestAfterId || recovered.length === 0) {
                    recoveryPendingRef.current = true;
                    break;
                }
                afterId = nextAfter;
                recoveryCheckpointsRef.current.set(conversationId, Math.max(
                    recoveryCheckpointsRef.current.get(conversationId) ?? 0,
                    afterId,
                ));
            }

            if (latestRecovered) {
                updateConversationFromMessage(latestRecovered);
                scheduleMarkRead(conversationId, latestRecovered.id);
            }
        } catch (requestError) {
            if (!isCanceledRequest(requestError)) {
                recoveryPendingRef.current = true;
                // Việc đồng bộ delta sẽ được thử lại ở lần reconnect/hiện tab tiếp theo.
            }
        } finally {
            if (requestGeneration === recoveryGenerationRef.current && recoveryAbortRef.current === controller) {
                recoveryAbortRef.current = null;
            }
        }
    }, [baseUrl, loadingThread, scheduleMarkRead, updateConversationFromMessage]);

    useEffect(() => {
        if (loadingThread || !recoveryPendingRef.current) return;
        recoveryPendingRef.current = false;
        void recoverMissingMessages();
    }, [loadingThread, recoverMissingMessages]);

    const handleRealtimeSubscribed = useCallback(() => {
        setRealtimeStatus('connected');
        scheduleConversationRefresh();
        flushPendingRead();
        void recoverMissingMessages();
    }, [flushPendingRead, recoverMissingMessages, scheduleConversationRefresh]);

    const handleRealtimeSubscriptionError = useCallback(() => {
        setRealtimeStatus('disconnected');
    }, []);

    useEffect(() => {
        type PusherConnection = {
            state?: string;
            bind: (event: string, callback: (payload?: { current?: string }) => void) => void;
            unbind: (event: string, callback: (payload?: { current?: string }) => void) => void;
        };
        const connection = (echo() as unknown as {
            connector?: { pusher?: { connection?: PusherConnection } };
        }).connector?.pusher?.connection;
        if (!connection) {
            setRealtimeStatus('disconnected');
            return;
        }

        const applyConnectionState = (state?: string) => {
            if (state === 'connected') {
                setRealtimeStatus(current => current === 'connected' ? current : 'connecting');
                return;
            }
            setRealtimeStatus(state === 'connecting' || state === 'initialized' || state === 'unavailable'
                ? 'connecting'
                : 'disconnected');
        };
        const handleStateChange = (payload?: { current?: string }) => applyConnectionState(payload?.current);
        const handleConnected = () => applyConnectionState('connected');
        connection.bind('state_change', handleStateChange);
        connection.bind('connected', handleConnected);
        applyConnectionState(connection.state);

        return () => {
            connection.unbind('state_change', handleStateChange);
            connection.unbind('connected', handleConnected);
        };
    }, []);

    useEffect(() => {
        const resumeActiveChat = () => {
            if (!isPageActive()) return;
            void recoverMissingMessages();
            scheduleConversationRefresh();
            flushPendingRead();
        };
        document.addEventListener('visibilitychange', resumeActiveChat);
        window.addEventListener('focus', resumeActiveChat);
        return () => {
            document.removeEventListener('visibilitychange', resumeActiveChat);
            window.removeEventListener('focus', resumeActiveChat);
        };
    }, [flushPendingRead, recoverMissingMessages, scheduleConversationRefresh]);

    const handleRealtimeMessage = useCallback((event: ChatMessageEvent) => {
        const incoming = event.message;
        updateConversationFromMessage(incoming, event.conversation);
        if (selectedIdRef.current === incoming.conversation_id) {
            setMessages(previous => {
                const next = mergeMessages(previous, incoming);
                messagesRef.current = next;
                return next;
            });
            if (!isMessageMine(incoming, currentUserId)) scheduleMarkRead(incoming.conversation_id, incoming.id);
        }
    }, [currentUserId, scheduleMarkRead, updateConversationFromMessage]);

    const handleRealtimeRead = useCallback((event: ChatReadEvent) => {
        if (event.reader.id === currentUserId) {
            sentReadIdsRef.current.set(event.conversation_id, Math.max(
                sentReadIdsRef.current.get(event.conversation_id) ?? 0,
                event.last_read_message_id,
            ));
            const pendingReadId = pendingReadIdsRef.current.get(event.conversation_id) ?? 0;
            if (event.last_read_message_id >= pendingReadId) {
                const timer = readTimersRef.current.get(event.conversation_id);
                if (timer !== undefined) window.clearTimeout(timer);
                readTimersRef.current.delete(event.conversation_id);
                pendingReadIdsRef.current.delete(event.conversation_id);

                const rollback = readRollbackSnapshotsRef.current.get(event.conversation_id);
                if (rollback && rollback.checkpoint <= event.last_read_message_id) {
                    readRollbackSnapshotsRef.current.delete(event.conversation_id);
                }
                const retryState = readRetryStatesRef.current.get(event.conversation_id);
                if (retryState && retryState.checkpoint <= event.last_read_message_id) {
                    readRetryStatesRef.current.delete(event.conversation_id);
                }
            }
            const trackedConversation = conversationsRef.current.find(
                conversation => conversation.id === event.conversation_id,
            );
            const trackedLastMessageId = trackedConversation?.latest_message_id
                ?? trackedConversation?.last_message?.id
                ?? 0;
            const selectedLastMessageId = selectedIdRef.current === event.conversation_id
                ? messagesRef.current.reduce(
                    (highest, message) => message.conversation_id === event.conversation_id
                        && message.id > highest ? message.id : highest,
                    0,
                )
                : 0;
            const latestRelevantMessageId = Math.max(
                latestKnownMessageIdsRef.current.get(event.conversation_id) ?? 0,
                pendingReadIdsRef.current.get(event.conversation_id) ?? 0,
                trackedLastMessageId,
                selectedLastMessageId,
            );
            if (event.last_read_message_id >= latestRelevantMessageId) {
                if (!clearUnreadLocally(event.conversation_id)) scheduleConversationRefresh();
            } else {
                scheduleConversationRefresh();
            }
        }
        if (event.conversation_id !== selectedIdRef.current || event.reader.kind !== 'agent') return;
        setMessages(previous => {
            const next = previous.map(message => {
                if (message.id > event.last_read_message_id) return message;
                const reader: ChatSeenBy = { ...event.reader, read_at: event.read_at };
                const seenBy = message.seen_by.some(item => item.id === reader.id)
                    ? message.seen_by.map(item => item.id === reader.id ? reader : item)
                    : [...message.seen_by, reader];
                return { ...message, seen_by: seenBy };
            });
            messagesRef.current = next;
            return next;
        });
    }, [clearUnreadLocally, currentUserId, scheduleConversationRefresh]);

    const handleRealtimeReaction = useCallback((event: ChatReactionEvent) => {
        setMessages(previous => {
            const next = previous.map(message => message.id === event.message_id
                ? { ...message, reactions: normalizedReactions(event.reactions, currentUserId) }
                : message);
            messagesRef.current = next;
            return next;
        });
    }, [currentUserId]);

    const handleInboxChange = useCallback((event: ChatInboxEvent) => {
        if (event.action === 'message') return;
        const existing = conversationsRef.current.find(item => item.id === event.conversation.id);
        const remainsVisible = matchesActiveFilters(event.conversation);
        if (existing) {
            const patched = { ...existing, ...event.conversation } as ChatConversation;
            const next = remainsVisible
                ? sortConversations(
                    [patched, ...conversationsRef.current.filter(item => item.id !== patched.id)],
                    mode === 'agent' ? inboxView : undefined,
                )
                : conversationsRef.current.filter(item => item.id !== patched.id);
            conversationsRef.current = next;
            setConversations(next);
            if (!remainsVisible) applyUnreadDelta(-existing.unread_count);
        }
        scheduleConversationRefresh();
        if (selectedIdRef.current === event.conversation.id) {
            if (remainsVisible) void openConversation(event.conversation.id);
            else clearSelection();
        }
    }, [applyUnreadDelta, clearSelection, inboxView, matchesActiveFilters, mode, openConversation, scheduleConversationRefresh]);

    const createConversation = async (context?: ChatSubject) => {
        setActionLoading(true);
        setError(null);
        try {
            const response = await window.axios.post<{ data: ChatConversation }>(`${baseUrl}/conversations/resolve`, context ? {
                subject_type: context.type,
                subject_id: context.id,
                source_app: 'backend-web',
                source_url: window.location.href,
            } : {
                category: 'general',
                source_app: 'backend-web',
                source_url: window.location.href,
            });
            setCreating(false);
            await fetchConversations(true);
            const conversationId = response.data.data.id;
            if (selectedIdRef.current === conversationId) {
                await openConversation(conversationId);
            } else {
                selectedIdRef.current = conversationId;
                openRequestRef.current += 1;
                setSelectedId(conversationId);
            }
        } catch (requestError) {
            setError(errorMessage(requestError));
        } finally {
            setActionLoading(false);
        }
    };

    const addImages = (event: ChangeEvent<HTMLInputElement>) => {
        const files = Array.from(event.target.files ?? []);
        event.target.value = '';
        if (files.length === 0) return;

        const remainingSlots = CHAT_IMAGE_MAX_COUNT - pendingImages.length;
        if (remainingSlots <= 0) {
            setError(`Mỗi tin nhắn chỉ được tối đa ${CHAT_IMAGE_MAX_COUNT} ảnh.`);
            return;
        }

        const invalidType = files.find(file => !CHAT_IMAGE_MIME_TYPES.has(file.type));
        if (invalidType) {
            setError('Chỉ hỗ trợ ảnh JPEG, PNG hoặc WebP.');
            return;
        }
        const oversized = files.find(file => file.size > CHAT_IMAGE_MAX_BYTES);
        if (oversized) {
            setError(`Ảnh “${oversized.name}” vượt quá 5 MB.`);
            return;
        }

        const accepted = files.slice(0, remainingSlots).map(file => {
            const previewUrl = URL.createObjectURL(file);
            localImageUrlsRef.current.add(previewUrl);
            return { id: crypto.randomUUID(), file, previewUrl };
        });
        setPendingImages(previous => [...previous, ...accepted]);
        setError(files.length > remainingSlots
            ? `Chỉ đã chọn ${remainingSlots} ảnh còn lại (tối đa ${CHAT_IMAGE_MAX_COUNT} ảnh).`
            : null);
    };

    const removePendingImage = (imageId: string) => {
        const removed = pendingImages.find(image => image.id === imageId);
        setPendingImages(previous => previous.filter(image => image.id !== imageId));
        const retainedByFailedMessage = removed && [...failedSendsRef.current.values()]
            .some(send => send.images.some(image => image.id === imageId));
        if (removed && !retainedByFailedMessage) {
            URL.revokeObjectURL(removed.previewUrl);
            localImageUrlsRef.current.delete(removed.previewUrl);
        }
    };

    const insertEmoji = (emoji: string) => {
        const textarea = composerRef.current;
        const start = textarea?.selectionStart ?? draft.length;
        const end = textarea?.selectionEnd ?? draft.length;
        const nextDraft = `${draft.slice(0, start)}${emoji}${draft.slice(end)}`;
        setDraft(nextDraft);
        setEmojiPickerOpen(false);
        queueMicrotask(() => {
            composerRef.current?.focus();
            composerRef.current?.setSelectionRange(start + emoji.length, start + emoji.length);
        });
    };

    const sendMessage = async (
        event?: FormEvent,
        retryPayload?: FailedChatSend & { clientMessageId: string },
    ) => {
        event?.preventDefault();
        const body = (retryPayload?.body ?? draft).trim();
        const images = retryPayload?.images ?? pendingImages;
        if (!selected || (!body && images.length === 0) || sending) return;
        const conversationId = selected.id;
        const isInternal = retryPayload?.isInternal ?? internalNote;
        const conversationBeforeSend = conversationsRef.current.find(item => item.id === conversationId);
        setSending(true);
        setError(null);
        const imageSignature = images.map(image => `${image.file.name}:${image.file.size}:${image.file.lastModified}`).join('|');
        const signature = `${conversationId}:${isInternal ? 'internal' : 'public'}:${body}:${imageSignature}`;
        const pendingSend = retryPayload
            ? { signature, clientMessageId: retryPayload.clientMessageId }
            : pendingSendRef.current?.signature === signature
                ? pendingSendRef.current
                : { signature, clientMessageId: crypto.randomUUID() };
        pendingSendRef.current = pendingSend;
        const optimisticMessage: ChatMessage = {
            id: optimisticMessageIdRef.current--,
            conversation_id: conversationId,
            sender_kind: mode === 'customer' ? 'customer' : 'agent',
            type: isInternal ? 'internal_note' : images.length > 0 ? 'image' : 'text',
            body,
            client_message_id: pendingSend.clientMessageId,
            is_internal: isInternal,
            is_mine: true,
            sender: {
                id: currentUserId,
                username: props.auth.user?.username ?? 'Bạn',
                avatar: props.auth.user?.avatar ?? null,
            },
            seen_by: [],
            attachments: images.map(image => ({
                id: image.id,
                url: image.previewUrl,
                thumbnail_url: image.previewUrl,
                name: image.file.name,
                mime_type: image.file.type,
                size: image.file.size,
            })),
            reactions: [],
            created_at: new Date().toISOString(),
            delivery_state: 'sending',
            delivery_progress: images.length > 0 ? 0 : undefined,
        };
        setMessages(previous => {
            const next = mergeMessages(previous, optimisticMessage);
            messagesRef.current = next;
            return next;
        });
        updateConversationFromMessage(optimisticMessage, {
            id: conversationId,
            status: isInternal ? selected.status : mode === 'customer' ? 'waiting_agent' : 'waiting_customer',
            last_message_at: optimisticMessage.created_at,
        });
        setDraft(current => retryPayload && current !== body ? current : '');
        setPendingImages(previous => previous.filter(image => !images.some(sentImage => sentImage.id === image.id)));
        setInternalNote(false);
        setEmojiPickerOpen(false);
        try {
            const formData = new FormData();
            formData.append('body', body);
            formData.append('client_message_id', pendingSend.clientMessageId);
            formData.append('is_internal', isInternal ? '1' : '0');
            images.forEach(image => formData.append('images[]', image.file, image.file.name));
            const response = await window.axios.post<{ data: ChatMessage; conversation?: ChatConversation }>(
                `${baseUrl}/conversations/${conversationId}/messages`,
                formData,
                {
                    onUploadProgress: progressEvent => {
                        if (images.length === 0 || !progressEvent.total || selectedIdRef.current !== conversationId) return;
                        const deliveryProgress = Math.min(99, Math.round((progressEvent.loaded / progressEvent.total) * 100));
                        setMessages(previous => {
                            const next = previous.map(message => message.client_message_id === pendingSend.clientMessageId
                                ? { ...message, delivery_progress: deliveryProgress }
                                : message);
                            messagesRef.current = next;
                            return next;
                        });
                    },
                },
            );
            const sent = {
                ...response.data.data,
                attachments: messageAttachments(response.data.data),
                reactions: messageReactions(response.data.data),
            };
            if (selectedIdRef.current === conversationId) {
                setMessages(previous => {
                    const next = mergeMessages(previous, sent);
                    messagesRef.current = next;
                    return next;
                });
            }
            const responseConversation = response.data.conversation;
            const responseSummary: ChatConversationRealtimeSummary = responseConversation
                ? {
                    ...responseConversation,
                    id: conversationId,
                    assigned_to_id: responseConversation.assignee?.id ?? null,
                }
                : {
                    id: conversationId,
                    status: sent.is_internal
                        ? selected.status
                        : mode === 'customer' ? 'waiting_agent' : 'waiting_customer',
                    last_message_at: sent.created_at,
                    ...(mode === 'agent' ? {
                        assigned_to_id: selected.assignee?.id ?? currentUserId,
                        assignee: selected.assignee ?? {
                            id: currentUserId,
                            username: props.auth.user?.username ?? 'Bạn',
                            avatar: props.auth.user?.avatar ?? null,
                        },
                    } : {}),
                };
            updateConversationFromMessage(sent, responseSummary);
            if (responseConversation && selectedIdRef.current === conversationId) {
                commitSelected(mergeConversationSnapshot(conversationId, responseConversation, [sent]));
            }
            images.forEach(image => {
                URL.revokeObjectURL(image.previewUrl);
                localImageUrlsRef.current.delete(image.previewUrl);
            });
            failedSendsRef.current.delete(pendingSend.clientMessageId);
            pendingSendRef.current = null;
        } catch (requestError) {
            const deliveredMessage = deliveredClientMessageIdsRef.current.has(pendingSend.clientMessageId)
                || messagesRef.current.some(message => (
                message.id > 0 && message.client_message_id === pendingSend.clientMessageId
            ));
            if (deliveredMessage) {
                images.forEach(image => {
                    URL.revokeObjectURL(image.previewUrl);
                    localImageUrlsRef.current.delete(image.previewUrl);
                });
                failedSendsRef.current.delete(pendingSend.clientMessageId);
                pendingSendRef.current = null;
                return;
            }
            const failedMessage: ChatMessage = { ...optimisticMessage, delivery_state: 'failed', delivery_progress: undefined };
            failedSendsRef.current.set(pendingSend.clientMessageId, { body, images, isInternal });
            if (selectedIdRef.current === conversationId) {
                setMessages(previous => {
                    const next = previous.map(message => (
                        message.client_message_id === pendingSend.clientMessageId
                            ? failedMessage
                            : message
                    ));
                    messagesRef.current = next;
                    return next;
                });
                setDraft(current => current || body);
                setPendingImages(current => [
                    ...current,
                    ...images.filter(image => !current.some(candidate => candidate.id === image.id)),
                ].slice(0, CHAT_IMAGE_MAX_COUNT));
                setInternalNote(current => current || isInternal);
            }
            if (conversationBeforeSend) {
                const currentConversation = conversationsRef.current.find(item => item.id === conversationId);
                if (currentConversation?.last_message?.id && currentConversation.last_message.id < 0
                    && currentConversation.last_message.client_message_id === pendingSend.clientMessageId) {
                    const unreadDelta = conversationBeforeSend.unread_count - currentConversation.unread_count;
                    const restored = {
                        ...currentConversation,
                        status: conversationBeforeSend.status,
                        last_message: conversationBeforeSend.last_message,
                        last_message_at: conversationBeforeSend.last_message_at,
                        unread_count: conversationBeforeSend.unread_count,
                    };
                    const next = sortConversations([
                        restored,
                        ...conversationsRef.current.filter(item => item.id !== conversationId),
                    ], mode === 'agent' ? inboxView : undefined);
                    conversationsRef.current = next;
                    setConversations(next);
                    applyUnreadDelta(unreadDelta);
                }
                const current = selectedRef.current;
                commitSelected(current?.id === conversationId
                    && current.last_message?.id !== undefined
                    && current.last_message.id < 0
                    && current.last_message.client_message_id === pendingSend.clientMessageId
                    ? {
                        ...current,
                        status: conversationBeforeSend.status,
                        last_message: conversationBeforeSend.last_message,
                        last_message_at: conversationBeforeSend.last_message_at,
                        unread_count: conversationBeforeSend.unread_count,
                    }
                    : current);
            }
            setError(errorMessage(requestError));
        } finally {
            setSending(false);
        }
    };

    const retryFailedMessage = (message: ChatMessage) => {
        if (!message.client_message_id || sending) return;
        const failedSend = failedSendsRef.current.get(message.client_message_id);
        if (!failedSend) {
            setError('Không còn dữ liệu ảnh tạm để thử lại. Vui lòng chọn ảnh lại.');
            return;
        }
        void sendMessage(undefined, { ...failedSend, clientMessageId: message.client_message_id });
    };

    const toggleReaction = async (message: ChatMessage, emoji: string) => {
        if (!selected || message.id <= 0 || message.is_internal) return;
        const requestKey = `${message.id}:${emoji}`;
        if (reactionRequestsRef.current.has(requestKey)) return;
        reactionRequestsRef.current.add(requestKey);
        setReactionPickerMessageId(null);
        setError(null);

        const previousReactions = messageReactions(message);
        const existing = previousReactions.find(reaction => reaction.emoji === emoji);
        const desiredActive = existing?.reacted_by_me !== true;
        const optimisticReactions = existing
            ? !desiredActive
                ? previousReactions
                    .map(reaction => reaction.emoji === emoji
                        ? { ...reaction, count: Math.max(0, reaction.count - 1), reacted_by_me: false }
                        : reaction)
                    .filter(reaction => reaction.count > 0)
                : previousReactions.map(reaction => reaction.emoji === emoji
                    ? { ...reaction, count: reaction.count + 1, reacted_by_me: true }
                    : reaction)
            : [...previousReactions, { emoji, count: 1, reacted_by_me: true }];

        const patchReactions = (reactions: ChatReaction[]) => {
            setMessages(current => {
                const next = current.map(item => item.id === message.id ? { ...item, reactions } : item);
                messagesRef.current = next;
                return next;
            });
        };
        patchReactions(optimisticReactions);

        try {
            const response = await window.axios.post<{
                data: ChatReaction[] | { message_id: number; reactions: ChatReaction[] };
            }>(`${baseUrl}/conversations/${selected.id}/messages/${message.id}/reactions`, {
                emoji,
                active: desiredActive,
            });
            const responseData = response.data.data;
            const reactions = Array.isArray(responseData) ? responseData : responseData.reactions;
            patchReactions(normalizedReactions(reactions, currentUserId));
        } catch (requestError) {
            patchReactions(previousReactions);
            setError(errorMessage(requestError));
        } finally {
            reactionRequestsRef.current.delete(requestKey);
        }
    };

    const handleComposerKeyDown = (event: KeyboardEvent<HTMLTextAreaElement>) => {
        if (event.key === 'Enter' && !event.shiftKey) {
            event.preventDefault();
            void sendMessage();
        }
    };

    const assignConversation = async (value: string) => {
        if (!selected) return;
        const conversationId = selected.id;
        const requestMessageCheckpoint = latestKnownMessageIdsRef.current.get(conversationId) ?? 0;
        setActionLoading(true);
        try {
            const response = await window.axios.patch<{ data: ChatConversation }>(
                `${baseUrl}/conversations/${conversationId}/assign`,
                { assigned_to_id: value ? Number(value) : null },
            );
            if (selectedIdRef.current === conversationId) {
                commitSelected(mergeConversationSnapshot(
                    conversationId,
                    response.data.data,
                    [],
                    requestMessageCheckpoint,
                ));
            }
            await fetchConversations(true);
        } catch (requestError) {
            setError(errorMessage(requestError));
        } finally {
            setActionLoading(false);
        }
    };

    const updateConversationStatus = async (value: ChatConversation['status']) => {
        if (!selected) return;
        const conversationId = selected.id;
        const previousStatus = selected.status;
        const title = conversationTitle(selected, mode);
        const requestFilterSignature = conversationFilterSignature;
        const requestMessageCheckpoint = latestKnownMessageIdsRef.current.get(conversationId) ?? 0;
        setActionLoading(true);
        try {
            const response = await window.axios.patch<{ data: ChatConversation }>(
                `${baseUrl}/conversations/${conversationId}/status`,
                { status: value },
            );
            const patchedConversation = mergeConversationSnapshot(
                conversationId,
                response.data.data,
                [],
                requestMessageCheckpoint,
            );
            const remainsVisible = matchesActiveFilters({
                ...patchedConversation,
                assigned_to_id: patchedConversation.assignee?.id ?? null,
            });
            if (requestFilterSignature === conversationFilterSignatureRef.current) {
                const existing = conversationsRef.current.find(item => item.id === conversationId);
                const nextConversations = remainsVisible
                    ? sortConversations(
                        [patchedConversation, ...conversationsRef.current.filter(item => item.id !== conversationId)],
                        mode === 'agent' ? inboxView : undefined,
                    )
                    : conversationsRef.current.filter(item => item.id !== conversationId);
                conversationsRef.current = nextConversations;
                setConversations(nextConversations);
                if (!remainsVisible && existing) applyUnreadDelta(-existing.unread_count);

                if (selectedIdRef.current === conversationId) {
                    if (remainsVisible) commitSelected(patchedConversation);
                    else clearSelection();
                }
            }
            if ((previousStatus === 'waiting_agent' || previousStatus === 'waiting_customer')
                && (value === 'resolved' || value === 'closed')) {
                setCompletionUndo({
                    conversationId,
                    conversationTitle: title,
                    previousStatus,
                    completedStatus: value,
                });
            }
            scheduleConversationRefresh();
        } catch (requestError) {
            setError(errorMessage(requestError));
        } finally {
            setActionLoading(false);
        }
    };

    const undoCompletedConversation = async () => {
        if (!completionUndo || actionLoading) return;
        const pendingUndo = completionUndo;
        const requestFilterSignature = conversationFilterSignature;
        setCompletionUndo(null);
        setActionLoading(true);
        setError(null);
        try {
            const response = await window.axios.patch<{ data: ChatConversation }>(
                `${baseUrl}/conversations/${pendingUndo.conversationId}/status`,
                { status: pendingUndo.previousStatus },
            );
            const restoredConversation = response.data.data;
            const remainsVisible = matchesActiveFilters({
                ...restoredConversation,
                assigned_to_id: restoredConversation.assignee?.id ?? null,
            });
            if (requestFilterSignature === conversationFilterSignatureRef.current) {
                const nextConversations = remainsVisible
                    ? sortConversations([
                        restoredConversation,
                        ...conversationsRef.current.filter(item => item.id !== restoredConversation.id),
                    ], mode === 'agent' ? inboxView : undefined)
                    : conversationsRef.current.filter(item => item.id !== restoredConversation.id);
                conversationsRef.current = nextConversations;
                setConversations(nextConversations);
                if (selectedIdRef.current === restoredConversation.id) {
                    if (remainsVisible) commitSelected(restoredConversation);
                    else clearSelection();
                }
            }
            scheduleConversationRefresh();
        } catch (requestError) {
            setCompletionUndo(pendingUndo);
            setError(errorMessage(requestError));
        } finally {
            setActionLoading(false);
        }
    };

    const viewRelatedOrder = async () => {
        if (!selected || !selected.subject_type) return;
        const conversationId = selected.id;
        setSubjectDetailLoading(true);
        try {
            const response = await window.axios.get<{ data: RelatedOrderDetail }>(
                `${baseUrl}/conversations/${conversationId}/subject`,
            );
            if (selectedIdRef.current === conversationId) setRelatedOrderDetail(response.data.data);
        } catch (requestError) {
            setError(errorMessage(requestError));
        } finally {
            setSubjectDetailLoading(false);
        }
    };

    const prepareForListFilterChange = () => {
        clearSelection();
        conversationsRef.current = [];
        setConversations([]);
        setConversationPage(1);
        setConversationLastPage(1);
        setLoadingList(true);
    };

    const changeInboxView = (nextView: ChatInboxView) => {
        if (nextView === inboxView) return;
        prepareForListFilterChange();
        setStatus('');
        setInboxView(nextView);
    };

    const countForView = (view: ChatInboxView): number | undefined => {
        const directCount = conversationCounts[view];
        if (typeof directCount === 'number') return directCount;

        const statusKeys: ChatConversation['status'][] = view === 'active'
            ? ['waiting_agent', 'waiting_customer']
            : ['resolved', 'closed'];
        const statusCounts = statusKeys.map(key => conversationCounts[key]);
        if (statusCounts.every((count): count is number => typeof count === 'number')) {
            return statusCounts.reduce((total, count) => total + count, 0);
        }
        if (view === inboxView && !status && !loadingList) return conversationTotal;
        return undefined;
    };

    const conversationGroups = mode === 'agent' && inboxView === 'active' && !status
        ? [
            {
                key: 'waiting_agent',
                label: 'Cần trả lời',
                items: conversations.filter(conversation => conversation.status === 'waiting_agent'),
                labelClassName: 'text-amber-700 dark:text-amber-300',
                countClassName: 'bg-amber-100 text-amber-700 dark:bg-amber-500/15 dark:text-amber-300',
            },
            {
                key: 'waiting_customer',
                label: 'Đang chờ khách',
                items: conversations.filter(conversation => conversation.status === 'waiting_customer'),
                labelClassName: 'text-sky-700 dark:text-sky-300',
                countClassName: 'bg-sky-100 text-sky-700 dark:bg-sky-500/15 dark:text-sky-300',
            },
        ]
        : [{
            key: 'all',
            label: null,
            items: conversations,
            labelClassName: '',
            countClassName: '',
        }];
    const activeConversationCount = countForView('active');
    const completedConversationCount = countForView('completed');

    return (
        <section className={`relative isolate flex min-h-0 overflow-hidden bg-white dark:bg-slate-950 ${compact
            ? 'h-[min(72vh,36rem)] rounded-2xl'
            : 'h-[calc(100vh-9rem)] min-h-[34rem] rounded-2xl border border-slate-200 shadow-sm dark:border-slate-800'}`}>
            {currentUserId > 0 && realtimeChannel && (
                <PersonalChatSubscription
                    channel={realtimeChannel}
                    onInboxChange={handleInboxChange}
                    onMessage={handleRealtimeMessage}
                    onReaction={handleRealtimeReaction}
                    onRead={handleRealtimeRead}
                    onSubscribed={handleRealtimeSubscribed}
                    onSubscriptionError={handleRealtimeSubscriptionError}
                />
            )}

            <aside className={`${selectedId ? 'hidden md:flex' : 'flex'} ${compact && selectedId ? '!hidden' : ''} w-full shrink-0 flex-col border-r border-slate-200 bg-slate-50/80 dark:border-slate-800 dark:bg-slate-900/70 md:w-80`}>
                <div className="border-b border-slate-200 p-4 dark:border-slate-800">
                    <div className="mb-3 flex items-center justify-between">
                        <div>
                            <div className="flex items-center gap-2 text-base font-bold text-slate-900 dark:text-white">
                                <Inbox className="h-4 w-4 text-indigo-500" />
                                Tin nhắn
                                {unreadTotal > 0 && <span className="rounded-full bg-rose-500 px-2 py-0.5 text-xs text-white">{unreadTotal}</span>}
                            </div>
                            <div className="mt-0.5 flex items-center gap-1.5 text-xs text-slate-500">
                                <span className={`h-1.5 w-1.5 rounded-full ${realtimeStatus === 'connected'
                                    ? 'bg-emerald-500'
                                    : realtimeStatus === 'connecting' ? 'animate-pulse bg-amber-400' : 'bg-rose-500'}`} />
                                <span>{realtimeStatus === 'connected'
                                    ? 'Realtime đang hoạt động'
                                    : realtimeStatus === 'connecting' ? 'Đang kết nối realtime…' : 'Mất kết nối, sẽ tự đồng bộ lại'}</span>
                            </div>
                        </div>
                        {mode === 'customer' && (
                            <button
                                type="button"
                                onClick={() => setCreating(value => !value)}
                                className="grid h-9 w-9 place-items-center rounded-xl bg-indigo-600 text-white shadow-sm transition hover:bg-indigo-500"
                                aria-label="Tạo cuộc trò chuyện"
                            >
                                <Plus className="h-4 w-4" />
                            </button>
                        )}
                    </div>

                    {mode === 'agent' && (
                        <>
                            <div role="tablist" aria-label="Nhóm hội thoại" className="mb-3 grid grid-cols-2 gap-1 rounded-xl bg-slate-200/70 p-1 dark:bg-slate-800">
                                <button
                                    type="button"
                                    role="tab"
                                    aria-selected={inboxView === 'active'}
                                    onClick={() => changeInboxView('active')}
                                    className={`flex min-w-0 items-center justify-center gap-1.5 rounded-lg px-2 py-2 text-xs font-semibold transition ${inboxView === 'active'
                                        ? 'bg-white text-indigo-700 shadow-sm dark:bg-slate-900 dark:text-indigo-300'
                                        : 'text-slate-500 hover:text-slate-800 dark:text-slate-400 dark:hover:text-slate-100'}`}
                                >
                                    <span className="truncate">Đang xử lý</span>
                                    {activeConversationCount !== undefined && <span className="rounded-full bg-indigo-100 px-1.5 py-0.5 text-[10px] text-indigo-700 dark:bg-indigo-500/15 dark:text-indigo-300">{activeConversationCount}</span>}
                                </button>
                                <button
                                    type="button"
                                    role="tab"
                                    aria-selected={inboxView === 'completed'}
                                    onClick={() => changeInboxView('completed')}
                                    className={`flex min-w-0 items-center justify-center gap-1.5 rounded-lg px-2 py-2 text-xs font-semibold transition ${inboxView === 'completed'
                                        ? 'bg-white text-emerald-700 shadow-sm dark:bg-slate-900 dark:text-emerald-300'
                                        : 'text-slate-500 hover:text-slate-800 dark:text-slate-400 dark:hover:text-slate-100'}`}
                                >
                                    <span className="truncate">Đã hoàn tất</span>
                                    {completedConversationCount !== undefined && <span className="rounded-full bg-emerald-100 px-1.5 py-0.5 text-[10px] text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300">{completedConversationCount}</span>}
                                </button>
                            </div>

                            <div className="mb-2 grid grid-cols-2 gap-2">
                                <select
                                    value={assignment}
                                    onChange={event => {
                                        prepareForListFilterChange();
                                        setAssignment(event.target.value);
                                    }}
                                    aria-label="Lọc theo người phụ trách"
                                    className="rounded-xl border-slate-200 bg-white py-2 text-sm dark:border-slate-700 dark:bg-slate-900"
                                >
                                    <option value="mine">Của tôi</option>
                                    {canViewAllChats && <option value="unassigned">Chưa phân công</option>}
                                    {canViewAllChats && <option value="">Tất cả</option>}
                                </select>
                                <select
                                    value={status}
                                    onChange={event => {
                                        prepareForListFilterChange();
                                        setStatus(event.target.value);
                                    }}
                                    aria-label="Lọc theo trạng thái"
                                    className="rounded-xl border-slate-200 bg-white py-2 text-sm dark:border-slate-700 dark:bg-slate-900"
                                >
                                    <option value="">{inboxView === 'active' ? 'Tất cả đang xử lý' : 'Tất cả hoàn tất'}</option>
                                    {inboxView === 'active' ? (
                                        <>
                                            <option value="waiting_agent">Chờ hỗ trợ</option>
                                            <option value="waiting_customer">Chờ khách</option>
                                        </>
                                    ) : (
                                        <>
                                            <option value="resolved">Đã giải quyết</option>
                                            <option value="closed">Đã đóng</option>
                                        </>
                                    )}
                                </select>
                            </div>

                            {inboxView === 'completed' && (
                                <select
                                    value={completedPeriod}
                                    onChange={event => {
                                        prepareForListFilterChange();
                                        setCompletedPeriod(event.target.value as CompletedPeriod);
                                    }}
                                    aria-label="Lọc hội thoại hoàn tất theo thời gian"
                                    className="mb-2 w-full rounded-xl border-slate-200 bg-white py-2 text-sm dark:border-slate-700 dark:bg-slate-900"
                                >
                                    <option value="7d">7 ngày gần đây</option>
                                    <option value="30d">30 ngày gần đây</option>
                                    <option value="90d">90 ngày gần đây</option>
                                    <option value="all">Tất cả thời gian</option>
                                </select>
                            )}
                        </>
                    )}

                    <label className="relative block">
                        <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
                        <input
                            value={search}
                            onChange={event => setSearch(event.target.value)}
                            placeholder="Tìm mã chat hoặc khách hàng"
                            className="w-full rounded-xl border-slate-200 bg-white py-2.5 pl-9 pr-3 text-sm placeholder:text-slate-400 focus:border-indigo-400 focus:ring-indigo-400 dark:border-slate-700 dark:bg-slate-950"
                        />
                    </label>
                </div>

                {creating && mode === 'customer' && (
                    <div className="border-b border-indigo-100 bg-indigo-50/80 p-3 dark:border-indigo-500/20 dark:bg-indigo-500/10">
                        <button
                            type="button"
                            disabled={actionLoading}
                            onClick={() => void createConversation()}
                            className="mb-2 flex w-full items-center gap-3 rounded-xl bg-white p-3 text-left shadow-sm ring-1 ring-indigo-100 transition hover:ring-indigo-300 dark:bg-slate-900 dark:ring-indigo-500/20"
                        >
                            <span className="grid h-9 w-9 place-items-center rounded-xl bg-indigo-600 text-white"><Sparkles className="h-4 w-4" /></span>
                            <span><strong className="block text-sm text-slate-900 dark:text-white">Hỏi đáp chung</strong><span className="text-xs text-slate-500">Trao đổi trực tiếp với admin</span></span>
                        </button>
                        {contexts.length > 0 && <p className="mb-2 px-1 text-xs font-semibold uppercase tracking-wide text-slate-500">Hoặc chọn đơn cần hỗ trợ</p>}
                        <div className="max-h-44 space-y-1 overflow-y-auto">
                            {contexts.map(context => (
                                <button
                                    type="button"
                                    key={`${context.type}:${context.id}`}
                                    disabled={actionLoading}
                                    onClick={() => void createConversation(context)}
                                    className="flex w-full items-center justify-between rounded-xl px-3 py-2 text-left text-sm text-slate-700 transition hover:bg-white dark:text-slate-200 dark:hover:bg-slate-900"
                                >
                                    <span className="min-w-0"><strong className="block truncate">{context.label}</strong><span className="block truncate text-xs text-slate-500">{context.description}</span></span>
                                    <ChevronRight className="h-4 w-4 shrink-0" />
                                </button>
                            ))}
                        </div>
                    </div>
                )}

                <div className="flex-1 overflow-y-auto p-2">
                    {loadingList ? (
                        <div className="grid h-40 place-items-center text-slate-400"><LoaderCircle className="h-6 w-6 animate-spin" /></div>
                    ) : conversations.length === 0 ? (
                        <div className="mx-3 mt-8 rounded-2xl border border-dashed border-slate-300 px-4 py-8 text-center dark:border-slate-700">
                            <MessageCircle className="mx-auto h-8 w-8 text-slate-300 dark:text-slate-600" />
                            <p className="mt-3 text-sm font-semibold text-slate-700 dark:text-slate-200">Chưa có cuộc trò chuyện</p>
                            <p className="mt-1 text-xs leading-5 text-slate-500">{mode === 'customer' ? 'Bấm dấu + để bắt đầu nhận hỗ trợ.' : 'Không có hội thoại phù hợp bộ lọc.'}</p>
                        </div>
                    ) : (
                        <>
                            {conversationGroups.map(group => (
                                <div key={group.key}>
                                    {group.label && (
                                        <div className={`mb-1 mt-2 flex items-center justify-between px-3 text-[11px] font-bold uppercase tracking-wide first:mt-0 ${group.labelClassName}`}>
                                            <span>{group.label}</span>
                                            <span className={`rounded-full px-1.5 py-0.5 text-[10px] ${group.countClassName}`}>
                                                {group.key === 'waiting_agent'
                                                    ? conversationCounts.waiting_agent ?? group.items.length
                                                    : conversationCounts.waiting_customer ?? group.items.length}
                                            </span>
                                        </div>
                                    )}
                                    {group.items.map(conversation => (
                                        <button
                                            type="button"
                                            key={conversation.id}
                                            onClick={() => {
                                                if (selectedIdRef.current === conversation.id) {
                                                    void openConversation(conversation.id);
                                                    return;
                                                }
                                                selectedIdRef.current = conversation.id;
                                                openRequestRef.current += 1;
                                                setSelectedId(conversation.id);
                                            }}
                                            className={`mb-1 flex w-full gap-3 rounded-xl p-3 text-left transition ${selectedId === conversation.id
                                                ? 'bg-white shadow-sm ring-1 ring-indigo-100 dark:bg-slate-800 dark:ring-indigo-500/20'
                                                : 'hover:bg-white/80 dark:hover:bg-slate-800/70'}`}
                                        >
                                            <Avatar user={mode === 'agent' ? conversation.customer : conversation.assignee} />
                                            <span className="min-w-0 flex-1">
                                                <span className="flex items-start justify-between gap-2">
                                                    <strong className="truncate text-sm text-slate-900 dark:text-white">{conversationTitle(conversation, mode)}</strong>
                                                    <span className="shrink-0 text-[11px] text-slate-400">{formatTime(mode === 'agent' && inboxView === 'completed'
                                                        ? conversation.resolved_at ?? conversation.updated_at
                                                        : conversation.last_message_at ?? conversation.created_at)}</span>
                                                </span>
                                                {mode === 'agent' && conversation.subject && <span className="block truncate text-xs font-medium text-indigo-600 dark:text-indigo-300">{conversation.subject.label}</span>}
                                                {mode === 'agent' && inboxView === 'completed' && (
                                                    <span className={`mt-1 inline-flex rounded-full px-2 py-0.5 text-[10px] font-semibold ring-1 ring-inset ${statusStyles[conversation.status]}`}>
                                                        {statusLabels[conversation.status]}
                                                    </span>
                                                )}
                                                <span className="mt-1 flex items-center justify-between gap-2">
                                                    <span className="truncate text-xs text-slate-500">{conversationMessagePreview(conversation.last_message)}</span>
                                                    {conversation.unread_count > 0 && <span className="grid min-w-5 place-items-center rounded-full bg-rose-500 px-1.5 py-0.5 text-[11px] font-bold text-white">{conversation.unread_count}</span>}
                                                </span>
                                            </span>
                                        </button>
                                    ))}
                                </div>
                            ))}
                            {conversationPage < conversationLastPage && (
                                <button
                                    type="button"
                                    disabled={loadingList || loadingMoreConversations}
                                    onClick={() => void loadMoreConversations()}
                                    className="mt-2 flex w-full items-center justify-center gap-2 rounded-xl px-3 py-2 text-xs font-semibold text-indigo-600 transition hover:bg-indigo-50 disabled:opacity-50 dark:text-indigo-300 dark:hover:bg-indigo-500/10"
                                >
                                    {loadingMoreConversations && <LoaderCircle className="h-3.5 w-3.5 animate-spin" />}
                                    Xem thêm hội thoại
                                </button>
                            )}
                        </>
                    )}
                </div>
            </aside>

            <main className={`${selectedId ? 'flex' : 'hidden md:flex'} min-w-0 flex-1 flex-col bg-white dark:bg-slate-950`}>
                {!selectedId ? (
                    <div className="grid flex-1 place-items-center p-8 text-center">
                        <div>
                            <span className="mx-auto grid h-16 w-16 place-items-center rounded-2xl bg-gradient-to-br from-indigo-500 to-cyan-500 text-white shadow-lg shadow-indigo-500/20"><Headphones className="h-7 w-7" /></span>
                            <h2 className="mt-5 text-lg font-bold text-slate-900 dark:text-white">Chọn một cuộc trò chuyện</h2>
                            <p className="mt-2 max-w-sm text-sm leading-6 text-slate-500">Tin nhắn, thông tin đơn và trạng thái đã xem sẽ được đồng bộ theo thời gian thực.</p>
                        </div>
                    </div>
                ) : loadingThread ? (
                    <div className="grid flex-1 place-items-center text-slate-400"><LoaderCircle className="h-7 w-7 animate-spin" /></div>
                ) : selected ? (
                    <>
                        <header className="flex min-h-[4.5rem] items-center justify-between gap-3 border-b border-slate-200 px-3 py-3 dark:border-slate-800 sm:px-5">
                            <div className="flex min-w-0 items-center gap-3">
                                <button
                                    type="button"
                                    onClick={() => {
                                        selectedIdRef.current = null;
                                        openRequestRef.current += 1;
                                        setSelectedId(null);
                                    }}
                                    className={`rounded-lg p-2 text-slate-500 hover:bg-slate-100 dark:hover:bg-slate-800 ${compact ? '' : 'md:hidden'}`}
                                    aria-label="Quay lại"
                                >
                                    <ArrowLeft className="h-5 w-5" />
                                </button>
                                <Avatar user={mode === 'agent' ? selected.customer : selected.assignee} />
                                <div className="min-w-0">
                                    <h2 className="truncate text-sm font-bold text-slate-900 dark:text-white sm:text-base">{conversationTitle(selected, mode)}</h2>
                                    <div className="mt-1 flex items-center gap-2">
                                        <span className={`rounded-full px-2 py-0.5 text-[11px] font-medium ring-1 ring-inset ${statusStyles[selected.status]}`}>{statusLabels[selected.status]}</span>
                                        {selected.assignee && <span className="hidden truncate text-xs text-slate-500 sm:inline">{selected.assignee.username} phụ trách</span>}
                                    </div>
                                </div>
                            </div>
                            {compact && (
                                <Link href={mode === 'agent' ? `/admin/chats?conversation=${selected.id}` : `/messages?conversation=${selected.id}`} className="rounded-xl p-2 text-slate-500 transition hover:bg-slate-100 hover:text-indigo-600 dark:hover:bg-slate-800" title="Mở trang tin nhắn"><ExternalLink className="h-4 w-4" /></Link>
                            )}
                        </header>

                        {!compact && mode === 'agent' && (
                            <div className="grid gap-3 border-b border-slate-200 bg-slate-50/70 px-3 py-3 dark:border-slate-800 dark:bg-slate-900/50 sm:grid-cols-2 sm:px-5 xl:hidden">
                                <div>
                                    <label className="mb-1.5 flex items-center gap-1.5 text-[11px] font-semibold uppercase tracking-wide text-slate-500"><UserRoundCheck className="h-3.5 w-3.5" /> Người phụ trách</label>
                                    {selected.permissions.assign ? (
                                        <AssigneePicker
                                            agents={agents}
                                            selected={selected.assignee}
                                            disabled={actionLoading}
                                            onChange={value => void assignConversation(value)}
                                        />
                                    ) : <div className="flex items-center gap-2 rounded-xl bg-white px-3 py-2 text-sm text-slate-700 ring-1 ring-slate-200 dark:bg-slate-900 dark:text-slate-200 dark:ring-slate-700"><Avatar user={selected.assignee} className="h-7 w-7 text-[10px]" /><span className="truncate">{selected.assignee?.username ?? 'Chưa phân công'}</span></div>}
                                </div>

                                <div>
                                    <label className="mb-1.5 flex items-center gap-1.5 text-[11px] font-semibold uppercase tracking-wide text-slate-500"><Clock3 className="h-3.5 w-3.5" /> Trạng thái xử lý</label>
                                    {selected.permissions.manage ? (
                                        <select
                                            value={selected.status}
                                            disabled={actionLoading}
                                            onChange={event => void updateConversationStatus(event.target.value as ChatConversation['status'])}
                                            className="w-full rounded-xl border-slate-200 bg-white py-2 text-sm dark:border-slate-700 dark:bg-slate-900"
                                        >
                                            <option value="waiting_agent">Chờ hỗ trợ</option>
                                            <option value="waiting_customer">Chờ khách</option>
                                            <option value="resolved">Đã giải quyết</option>
                                            <option value="closed">Đã đóng</option>
                                        </select>
                                    ) : <span className={`inline-flex rounded-full px-2.5 py-1 text-xs ring-1 ring-inset ${statusStyles[selected.status]}`}>{statusLabels[selected.status]}</span>}
                                </div>
                            </div>
                        )}

                        {selected.subject && (
                            <div className={`mx-3 mt-3 items-center gap-3 rounded-xl border border-indigo-100 bg-indigo-50/70 px-3 py-2 dark:border-indigo-500/20 dark:bg-indigo-500/10 sm:mx-5 ${!compact && mode === 'agent' ? 'flex xl:hidden' : 'flex'}`}>
                                <span className="grid h-8 w-8 place-items-center rounded-lg bg-indigo-600 text-white"><ShieldCheck className="h-4 w-4" /></span>
                                <div className="min-w-0 flex-1"><strong className="block truncate text-xs text-indigo-950 dark:text-indigo-100">{selected.subject.label}</strong><span className="block truncate text-xs text-indigo-600/80 dark:text-indigo-300/80">{selected.subject.description}</span></div>
                                <div className="flex shrink-0 items-center gap-2">
                                    <span className="rounded-full bg-white px-2 py-1 text-[11px] font-medium text-indigo-600 dark:bg-slate-900 dark:text-indigo-300">{selected.subject.status}</span>
                                    {mode === 'agent' && (
                                        <button
                                            type="button"
                                            disabled={subjectDetailLoading}
                                            onClick={() => void viewRelatedOrder()}
                                            className="inline-flex items-center gap-1 rounded-lg bg-indigo-600 px-2.5 py-1.5 text-[11px] font-semibold text-white transition hover:bg-indigo-500 disabled:opacity-60"
                                        >
                                            {subjectDetailLoading ? <LoaderCircle className="h-3.5 w-3.5 animate-spin" /> : <Eye className="h-3.5 w-3.5" />}
                                            Xem đơn
                                        </button>
                                    )}
                                </div>
                            </div>
                        )}

                        <div
                            ref={messageScrollRef}
                            onScroll={event => {
                                const target = event.currentTarget;
                                const wasNearBottom = messageNearBottomRef.current;
                                const isNearBottom = target.scrollHeight - target.scrollTop - target.clientHeight < 120;
                                messageNearBottomRef.current = isNearBottom;
                                if (!wasNearBottom && isNearBottom && isPageActive() && selectedIdRef.current) {
                                    const lastMessageId = messagesRef.current.reduce(
                                        (highest, message) => message.id > highest ? message.id : highest,
                                        0,
                                    );
                                    if (lastMessageId > 0) scheduleMarkRead(selectedIdRef.current, lastMessageId);
                                }
                            }}
                            className="flex-1 overflow-y-auto px-3 py-4 sm:px-5"
                        >
                            <div className="mx-auto max-w-3xl space-y-3">
                                {hasMoreMessages && (
                                    <button
                                        type="button"
                                        disabled={loadingOlderMessages}
                                        onClick={() => void loadOlderMessages()}
                                        className="mx-auto flex items-center gap-2 rounded-full border border-slate-200 bg-white px-3 py-1.5 text-xs font-semibold text-indigo-600 shadow-sm transition hover:border-indigo-200 hover:bg-indigo-50 disabled:opacity-50 dark:border-slate-700 dark:bg-slate-900 dark:text-indigo-300 dark:hover:bg-indigo-500/10"
                                    >
                                        {loadingOlderMessages && <LoaderCircle className="h-3.5 w-3.5 animate-spin" />}
                                        Xem tin nhắn cũ hơn
                                    </button>
                                )}
                                {messages.length === 0 && (
                                    <div className="py-10 text-center text-sm text-slate-500">Hãy gửi tin nhắn đầu tiên để bắt đầu trao đổi.</div>
                                )}
                                {messages.map(message => {
                                    const authoredByCurrentUser = isMessageMine(message, currentUserId);
                                    const agentMessage = message.sender_kind === 'agent';
                                    const alignRight = mode === 'agent' ? agentMessage : authoredByCurrentUser;
                                    const showAgentIdentity = mode === 'agent' && agentMessage;
                                    const attachments = messageAttachments(message);
                                    const reactions = messageReactions(message);
                                    const canReact = message.id > 0
                                        && !message.is_internal
                                        && selected.permissions.reply
                                        && selected.status !== 'closed';
                                    return (
                                        <article key={message.client_message_id ?? message.id} className={`flex items-end gap-2 ${alignRight ? 'justify-end' : 'justify-start'} ${message.delivery_state === 'failed' ? 'opacity-70' : ''}`}>
                                            <div className={`${compact ? 'max-w-[88%]' : 'max-w-[84%] sm:max-w-[72%]'} ${alignRight ? 'items-end' : 'items-start'} flex min-w-0 flex-col`}>
                                                {(showAgentIdentity || !alignRight) && <span className="mb-1 px-1 text-[11px] font-medium text-slate-500">{message.sender?.username ?? (message.sender_kind === 'system' ? 'Hệ thống' : 'Hỗ trợ')}{showAgentIdentity && authoredByCurrentUser ? ' · Bạn' : ''}</span>}
                                                <div className={`w-full overflow-hidden rounded-2xl text-sm leading-6 shadow-sm ${attachments.length > 0 ? 'p-1.5' : 'px-3.5 py-2.5'} ${message.is_internal
                                                    ? 'border border-amber-200 bg-amber-50 text-amber-900 dark:border-amber-500/20 dark:bg-amber-500/10 dark:text-amber-100'
                                                    : alignRight
                                                        ? 'rounded-br-md bg-gradient-to-br from-indigo-600 to-blue-600 text-white'
                                                        : 'rounded-bl-md border border-slate-200 bg-white text-slate-800 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100'}`}>
                                                    <MessageAttachments
                                                        attachments={attachments}
                                                        compact={compact}
                                                        onOpen={setLightboxAttachment}
                                                        onLoadError={refreshExpiredAttachment}
                                                    />
                                                    <div className={attachments.length > 0 && (message.body || message.is_internal || attachmentsWerePurged(message)) ? 'px-2 pb-1 pt-2' : ''}>
                                                        {message.is_internal && <span className="mb-1 flex items-center gap-1 text-[11px] font-bold uppercase tracking-wide text-amber-600 dark:text-amber-300"><ShieldCheck className="h-3 w-3" /> Ghi chú nội bộ</span>}
                                                        {message.body && <p className="whitespace-pre-wrap break-words">{message.body}</p>}
                                                        {attachmentsWerePurged(message) && attachments.length === 0 && (
                                                            <p className="flex items-center gap-1.5 text-xs italic opacity-75"><ImagePlus className="h-3.5 w-3.5" /> Ảnh đã hết hạn và được dọn tự động.</p>
                                                        )}
                                                    </div>
                                                </div>
                                                {(reactions.length > 0 || canReact) && (
                                                    <div data-reaction-picker className={`relative mt-1 flex max-w-full flex-wrap items-center gap-1 px-1 ${alignRight ? 'justify-end' : 'justify-start'}`}>
                                                        {reactions.map(reaction => (
                                                            <button
                                                                key={reaction.emoji}
                                                                type="button"
                                                                disabled={!canReact}
                                                                onClick={() => void toggleReaction(message, reaction.emoji)}
                                                                className={`inline-flex h-7 items-center gap-1 rounded-full border px-2 text-xs transition disabled:cursor-default ${reaction.reacted_by_me
                                                                    ? 'border-indigo-300 bg-indigo-50 text-indigo-700 dark:border-indigo-500/50 dark:bg-indigo-500/15 dark:text-indigo-200'
                                                                    : 'border-slate-200 bg-white text-slate-600 hover:border-indigo-200 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-300'}`}
                                                                aria-label={`${reaction.reacted_by_me ? 'Bỏ' : 'Thêm'} cảm xúc ${reaction.emoji}`}
                                                            >
                                                                <span>{reaction.emoji}</span><span className="font-semibold">{reaction.count}</span>
                                                            </button>
                                                        ))}
                                                        {canReact && (
                                                            <button
                                                                type="button"
                                                                onClick={() => setReactionPickerMessageId(current => current === message.id ? null : message.id)}
                                                                className="grid h-7 w-7 place-items-center rounded-full border border-transparent text-slate-400 transition hover:border-slate-200 hover:bg-white hover:text-indigo-500 dark:hover:border-slate-700 dark:hover:bg-slate-900"
                                                                aria-label="Thêm cảm xúc"
                                                            >
                                                                <Smile className="h-3.5 w-3.5" />
                                                            </button>
                                                        )}
                                                        {reactionPickerMessageId === message.id && (
                                                            <div className={`absolute bottom-9 z-40 flex gap-0.5 rounded-full border border-slate-200 bg-white p-1.5 shadow-xl dark:border-slate-700 dark:bg-slate-900 ${alignRight ? 'right-0' : 'left-0'}`}>
                                                                {MESSAGE_REACTION_EMOJIS.map(emoji => (
                                                                    <button
                                                                        key={emoji}
                                                                        type="button"
                                                                        onClick={() => void toggleReaction(message, emoji)}
                                                                        className="grid h-8 w-8 place-items-center rounded-full text-lg transition hover:scale-110 hover:bg-slate-100 dark:hover:bg-slate-800"
                                                                        aria-label={`Cảm xúc ${emoji}`}
                                                                    >
                                                                        {emoji}
                                                                    </button>
                                                                ))}
                                                            </div>
                                                        )}
                                                    </div>
                                                )}
                                                <span className="mt-1 flex items-center gap-1 px-1 text-[10px] text-slate-400">
                                                    {formatTime(message.created_at)}
                                                    {message.delivery_state === 'sending' && <><LoaderCircle className="h-3 w-3 animate-spin" /> {typeof message.delivery_progress === 'number' ? `Đang tải ${message.delivery_progress}%` : 'Đang gửi…'}</>}
                                                    {message.delivery_state === 'failed' && (
                                                        <button type="button" onClick={() => retryFailedMessage(message)} className="inline-flex items-center gap-1 font-semibold text-rose-500 hover:text-rose-600">
                                                            <RotateCcw className="h-3 w-3" /> Gửi lại
                                                        </button>
                                                    )}
                                                    {!message.delivery_state && authoredByCurrentUser && message.sender_kind === 'agent' && <CheckCheck className="h-3 w-3" />}
                                                </span>
                                                {message.sender_kind === 'customer' && (message.seen_by ?? []).length > 0 && (
                                                    <span className="mt-0.5 max-w-full truncate px-1 text-[10px] text-emerald-600 dark:text-emerald-400">
                                                        Đã xem bởi {(message.seen_by ?? []).map(agent => agent.username).filter(Boolean).join(', ')}
                                                    </span>
                                                )}
                                            </div>
                                            {showAgentIdentity && <Avatar user={message.sender} className="mb-4 h-7 w-7 text-[10px]" />}
                                        </article>
                                    );
                                })}
                                <div ref={messagesEndRef} />
                            </div>
                        </div>

                        {error && <div className="mx-3 mb-2 rounded-lg bg-rose-50 px-3 py-2 text-xs text-rose-700 dark:bg-rose-500/10 dark:text-rose-300 sm:mx-5">{error}</div>}

                        <form onSubmit={sendMessage} className="border-t border-slate-200 bg-white p-3 dark:border-slate-800 dark:bg-slate-950 sm:p-4">
                            {mode === 'agent' && canWriteInternalNote && (
                                <label className="mb-2 inline-flex cursor-pointer items-center gap-2 text-xs font-medium text-slate-500">
                                    <input type="checkbox" checked={internalNote} onChange={event => setInternalNote(event.target.checked)} className="rounded border-slate-300 text-amber-500 focus:ring-amber-400" />
                                    Ghi chú nội bộ, khách hàng không nhìn thấy
                                </label>
                            )}
                            {pendingImages.length > 0 && (
                                <div className="mb-2 flex gap-2 overflow-x-auto pb-1" aria-label="Ảnh chờ gửi">
                                    {pendingImages.map(image => (
                                        <div key={image.id} className="group relative h-16 w-16 shrink-0 overflow-hidden rounded-xl border border-slate-200 bg-slate-100 dark:border-slate-700 dark:bg-slate-800">
                                            <img src={image.previewUrl} alt={image.file.name} className="h-full w-full object-cover" />
                                            <button
                                                type="button"
                                                onClick={() => removePendingImage(image.id)}
                                                className="absolute right-1 top-1 grid h-5 w-5 place-items-center rounded-full bg-black/65 text-white transition hover:bg-rose-500"
                                                aria-label={`Bỏ ảnh ${image.file.name}`}
                                            >
                                                <X className="h-3 w-3" />
                                            </button>
                                            <span className="absolute inset-x-0 bottom-0 truncate bg-black/55 px-1 py-0.5 text-[8px] text-white">{formatFileSize(image.file.size)}</span>
                                        </div>
                                    ))}
                                    <span className="self-end pb-1 text-[10px] text-slate-400">{pendingImages.length}/{CHAT_IMAGE_MAX_COUNT}</span>
                                </div>
                            )}
                            <div className={`flex items-end gap-2 rounded-2xl border bg-slate-50 p-2 transition focus-within:ring-2 ${internalNote ? 'border-amber-300 focus-within:ring-amber-200 dark:border-amber-500/40' : 'border-slate-200 focus-within:border-indigo-400 focus-within:ring-indigo-100 dark:border-slate-700 dark:focus-within:ring-indigo-500/20'} dark:bg-slate-900`}>
                                <input
                                    ref={imageInputRef}
                                    type="file"
                                    accept="image/jpeg,image/png,image/webp"
                                    multiple
                                    className="hidden"
                                    onChange={addImages}
                                />
                                <div ref={composerToolsRef} className="relative flex shrink-0 items-center gap-0.5 pb-1">
                                    <button
                                        type="button"
                                        disabled={!selected.permissions.reply || selected.status === 'closed' || pendingImages.length >= CHAT_IMAGE_MAX_COUNT}
                                        onClick={() => imageInputRef.current?.click()}
                                        className="grid h-8 w-8 place-items-center rounded-lg text-slate-400 transition hover:bg-slate-200 hover:text-indigo-600 disabled:cursor-not-allowed disabled:opacity-35 dark:hover:bg-slate-800 dark:hover:text-indigo-300"
                                        aria-label="Chọn ảnh"
                                        title="Chọn tối đa 4 ảnh, mỗi ảnh 5 MB"
                                    >
                                        <ImagePlus className="h-4 w-4" />
                                    </button>
                                    <button
                                        type="button"
                                        disabled={!selected.permissions.reply || selected.status === 'closed'}
                                        onClick={() => setEmojiPickerOpen(value => !value)}
                                        className="grid h-8 w-8 place-items-center rounded-lg text-slate-400 transition hover:bg-slate-200 hover:text-indigo-600 disabled:cursor-not-allowed disabled:opacity-35 dark:hover:bg-slate-800 dark:hover:text-indigo-300"
                                        aria-label="Chọn emoji"
                                        aria-expanded={emojiPickerOpen}
                                    >
                                        <Smile className="h-4 w-4" />
                                    </button>
                                    {emojiPickerOpen && (
                                        <div className="absolute bottom-11 left-0 z-50">
                                            <EmojiPicker onSelect={insertEmoji} />
                                        </div>
                                    )}
                                </div>
                                <textarea
                                    ref={composerRef}
                                    value={draft}
                                    onChange={event => setDraft(event.target.value)}
                                    onKeyDown={handleComposerKeyDown}
                                    rows={1}
                                    maxLength={5000}
                                    disabled={!selected.permissions.reply || selected.status === 'closed'}
                                    placeholder={selected.status === 'closed' ? 'Cuộc trò chuyện đã đóng' : internalNote ? 'Viết ghi chú cho đội hỗ trợ…' : 'Nhập tin nhắn…'}
                                    className="max-h-32 min-h-[2.5rem] flex-1 resize-none border-0 bg-transparent px-2 py-2 text-sm leading-6 text-slate-900 shadow-none placeholder:text-slate-400 focus:ring-0 disabled:cursor-not-allowed dark:text-white"
                                />
                                <button
                                    type="submit"
                                    disabled={(!draft.trim() && pendingImages.length === 0) || sending || !selected.permissions.reply || selected.status === 'closed'}
                                    className={`grid h-10 w-10 shrink-0 place-items-center rounded-xl text-white shadow-sm transition disabled:cursor-not-allowed disabled:opacity-40 ${internalNote ? 'bg-amber-500 hover:bg-amber-400' : 'bg-indigo-600 hover:bg-indigo-500'}`}
                                    aria-label="Gửi tin nhắn"
                                >
                                    {sending ? <LoaderCircle className="h-4 w-4 animate-spin" /> : <SendHorizontal className="h-4 w-4" />}
                                </button>
                            </div>
                        </form>
                    </>
                ) : null}
            </main>

            {!compact && mode === 'agent' && selected && (
                <aside className="hidden w-72 shrink-0 flex-col border-l border-slate-200 bg-slate-50/70 p-4 dark:border-slate-800 dark:bg-slate-900/50 xl:flex">
                    <div className="flex items-center gap-3 border-b border-slate-200 pb-4 dark:border-slate-800">
                        <Avatar user={selected.customer} className="h-11 w-11" />
                        <div className="min-w-0"><strong className="block truncate text-sm text-slate-900 dark:text-white">{selected.customer?.username}</strong><span className="text-xs text-slate-500">Khách hàng #{selected.customer?.id}</span></div>
                    </div>

                    <div className="space-y-5 py-5">
                        <div>
                            <label className="mb-1.5 flex items-center gap-1.5 text-xs font-semibold uppercase tracking-wide text-slate-500"><UserRoundCheck className="h-3.5 w-3.5" /> Người phụ trách</label>
                            {selected.permissions.assign ? (
                                <AssigneePicker
                                    agents={agents}
                                    selected={selected.assignee}
                                    disabled={actionLoading}
                                    onChange={value => void assignConversation(value)}
                                />
                            ) : <div className="flex items-center gap-2 rounded-xl bg-white px-3 py-2 text-sm text-slate-700 ring-1 ring-slate-200 dark:bg-slate-900 dark:text-slate-200 dark:ring-slate-700"><Avatar user={selected.assignee} className="h-7 w-7 text-[10px]" /><span className="truncate">{selected.assignee?.username ?? 'Chưa phân công'}</span></div>}
                        </div>

                        <div>
                            <label className="mb-1.5 flex items-center gap-1.5 text-xs font-semibold uppercase tracking-wide text-slate-500"><Clock3 className="h-3.5 w-3.5" /> Trạng thái xử lý</label>
                            {selected.permissions.manage ? (
                                <select
                                    value={selected.status}
                                    disabled={actionLoading}
                                    onChange={event => void updateConversationStatus(event.target.value as ChatConversation['status'])}
                                    className="w-full rounded-xl border-slate-200 bg-white text-sm dark:border-slate-700 dark:bg-slate-900"
                                >
                                    <option value="waiting_agent">Chờ hỗ trợ</option>
                                    <option value="waiting_customer">Chờ khách</option>
                                    <option value="resolved">Đã giải quyết</option>
                                    <option value="closed">Đã đóng</option>
                                </select>
                            ) : <span className={`inline-flex rounded-full px-2.5 py-1 text-xs ring-1 ring-inset ${statusStyles[selected.status]}`}>{statusLabels[selected.status]}</span>}
                        </div>

                        {selected.subject && (
                            <div>
                                <label className="mb-1.5 block text-xs font-semibold uppercase tracking-wide text-slate-500">Đơn liên quan</label>
                                <div className="rounded-xl bg-white p-3 ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-700">
                                    <strong className="block text-sm text-slate-900 dark:text-white">{selected.subject.label}</strong>
                                    <span className="mt-1 block text-xs leading-5 text-slate-500">{selected.subject.description}</span>
                                    <div className="mt-2 flex items-center justify-between gap-2">
                                        <span className="inline-flex rounded-full bg-indigo-50 px-2 py-1 text-[11px] font-medium text-indigo-700 dark:bg-indigo-500/10 dark:text-indigo-300">{selected.subject.status}</span>
                                        <button
                                            type="button"
                                            disabled={subjectDetailLoading}
                                            onClick={() => void viewRelatedOrder()}
                                            className="inline-flex items-center gap-1 rounded-lg bg-indigo-600 px-2.5 py-1.5 text-[11px] font-semibold text-white transition hover:bg-indigo-500 disabled:opacity-60"
                                        >
                                            {subjectDetailLoading ? <LoaderCircle className="h-3.5 w-3.5 animate-spin" /> : <Eye className="h-3.5 w-3.5" />}
                                            Xem đơn
                                        </button>
                                    </div>
                                </div>
                            </div>
                        )}

                        <div>
                            <label className="mb-2 block text-xs font-semibold uppercase tracking-wide text-slate-500">Đã tham gia</label>
                            <div className="space-y-2">
                                {selected.participants.filter(participant => participant.role === 'agent' && !participant.left_at).map(participant => (
                                    <div key={participant.user?.id} className="flex items-center gap-2 text-sm text-slate-700 dark:text-slate-200">
                                        <Avatar user={participant.user} className="h-7 w-7" />
                                        <span className="min-w-0 flex-1 truncate">{participant.user?.username}</span>
                                        {participant.last_read_at && <CheckCheck className="h-3.5 w-3.5 text-emerald-500" />}
                                    </div>
                                ))}
                            </div>
                        </div>
                    </div>
                </aside>
            )}

            {actionLoading && (
                <div className="pointer-events-none absolute inset-0 z-20 grid place-items-center bg-white/30 backdrop-blur-[1px] dark:bg-slate-950/30"><LoaderCircle className="h-6 w-6 animate-spin text-indigo-500" /></div>
            )}
            {completionUndo && (
                <div
                    role="status"
                    className="absolute bottom-4 left-4 right-4 z-30 flex items-center justify-between gap-3 rounded-xl bg-slate-900 px-4 py-3 text-sm text-white shadow-xl dark:bg-white dark:text-slate-900 md:left-1/2 md:right-auto md:min-w-80 md:-translate-x-1/2"
                >
                    <span className="min-w-0 truncate">
                        {statusLabels[completionUndo.completedStatus]}: {completionUndo.conversationTitle}
                    </span>
                    <button
                        type="button"
                        disabled={actionLoading}
                        onClick={() => void undoCompletedConversation()}
                        className="shrink-0 rounded-lg bg-indigo-500 px-3 py-1.5 text-xs font-bold text-white transition hover:bg-indigo-400 disabled:opacity-60"
                    >
                        Hoàn tác
                    </button>
                </div>
            )}
            <Modal
                open={lightboxAttachment !== null}
                onCancel={() => setLightboxAttachment(null)}
                footer={null}
                centered
                width={960}
                title={lightboxAttachment?.name || 'Ảnh đính kèm'}
                destroyOnHidden
            >
                {lightboxAttachment && (
                    <div className="space-y-3 pt-2">
                        <div className="grid max-h-[75vh] place-items-center overflow-auto rounded-xl bg-slate-950 p-2">
                            <img
                                src={lightboxAttachment.url}
                                alt={lightboxAttachment.name || 'Ảnh đính kèm'}
                                onError={() => {
                                    refreshExpiredAttachment(lightboxAttachment);
                                    setLightboxAttachment(null);
                                }}
                                className="max-h-[72vh] max-w-full object-contain"
                            />
                        </div>
                        <div className="flex items-center justify-between gap-3 text-xs text-slate-500">
                            <span>{formatFileSize(lightboxAttachment.size)}</span>
                            <a href={lightboxAttachment.url} target="_blank" rel="noreferrer" className="inline-flex items-center gap-1 font-semibold text-indigo-600 hover:text-indigo-500 dark:text-indigo-300">
                                Mở ảnh gốc <ExternalLink className="h-3.5 w-3.5" />
                            </a>
                        </div>
                    </div>
                )}
            </Modal>
            <RelatedOrderModal detail={relatedOrderDetail} onClose={() => setRelatedOrderDetail(null)} />
        </section>
    );
}
