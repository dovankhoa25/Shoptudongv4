import { memo, useCallback, useEffect, useRef, useState } from 'react';
import { Link, usePage } from '@inertiajs/react';
import { echo } from '@laravel/echo-react';
import { ExternalLink, MessageCircle, Minus, X } from 'lucide-react';
import type { PageProps } from '@/types';
import type { ChatConversation, ChatMessage, ChatUser, PaginatedChatConversations } from './types';
import ChatWorkspace from './ChatWorkspace';

interface ChatBubbleProps {
    mode: 'customer' | 'agent';
    baseUrl: string;
}

interface InboxEvent {
    action: string;
    conversation: Pick<ChatConversation, 'id' | 'status' | 'last_message_at'>;
}

interface MessageEvent {
    message: ChatMessage;
}

interface ReadEvent {
    conversation_id: number;
    reader: ChatUser;
    last_read_message_id: number;
}

const BubbleSubscription = memo(function BubbleSubscription({
    channel,
    onInbox,
    onMessage,
    onRead,
    onSubscribed,
}: {
    channel: string;
    onInbox: (event: InboxEvent) => void;
    onMessage: (event: MessageEvent) => void;
    onRead: (event: ReadEvent) => void;
    onSubscribed: () => void;
}) {
    const handlersRef = useRef({ onInbox, onMessage, onRead, onSubscribed });
    handlersRef.current = { onInbox, onMessage, onRead, onSubscribed };

    useEffect(() => {
        let active = true;
        const subscription = echo().private(channel);
        const handleInbox = (event: InboxEvent) => {
            if (active) handlersRef.current.onInbox(event);
        };
        const handleMessage = (event: MessageEvent) => {
            if (active) handlersRef.current.onMessage(event);
        };
        const handleRead = (event: ReadEvent) => {
            if (active) handlersRef.current.onRead(event);
        };
        const handleSubscribed = () => {
            if (active) handlersRef.current.onSubscribed();
        };
        subscription.listen('.ChatInboxUpdated', handleInbox);
        subscription.listen('.ChatMessageSent', handleMessage);
        subscription.listen('.ChatReadUpdated', handleRead);
        subscription.on('pusher:subscription_succeeded', handleSubscribed);
        if ((subscription as unknown as { subscription?: { subscribed?: boolean } }).subscription?.subscribed) {
            queueMicrotask(() => {
                if (active) handleSubscribed();
            });
        }

        return () => {
            active = false;
            subscription.stopListening('.ChatInboxUpdated', handleInbox);
            subscription.stopListening('.ChatMessageSent', handleMessage);
            subscription.stopListening('.ChatReadUpdated', handleRead);
            subscription.stopListening('.pusher:subscription_succeeded', handleSubscribed);
            echo().leave(channel);
        };
    }, [channel]);
    return null;
});

export default function ChatBubble({ mode, baseUrl }: ChatBubbleProps) {
    const { props, url } = usePage<PageProps>();
    const [open, setOpen] = useState(false);
    const [unread, setUnread] = useState(0);
    const roles = Array.isArray(props.auth.roles) ? props.auth.roles : [];
    const permissions = Array.isArray(props.auth.permissions) ? props.auth.permissions : [];
    const currentUserId = Number(props.auth.user?.id);
    const isAdmin = mode === 'agent' && (props.auth.is_super_admin || roles.includes('admin'));
    const channel = props.auth.realtime_channel;
    const fullPageUrl = mode === 'agent' ? '/admin/chats' : '/messages';
    const hidden = url.split('?')[0] === fullPageUrl;
    const unreadRequestGenerationRef = useRef(0);
    const unreadAbortRef = useRef<AbortController | null>(null);
    const unreadRefreshTimerRef = useRef<number | null>(null);
    const seenMessageIdsRef = useRef<Set<number>>(new Set());

    const loadUnread = useCallback(async () => {
        const requestGeneration = ++unreadRequestGenerationRef.current;
        const controller = new AbortController();
        unreadAbortRef.current?.abort();
        unreadAbortRef.current = controller;
        try {
            const response = await window.axios.get<PaginatedChatConversations>(`${baseUrl}/conversations`, {
                params: {
                    per_page: 1,
                    assignment: mode === 'agent' && !isAdmin ? 'mine' : undefined,
                    view: mode === 'agent' ? 'active' : undefined,
                },
                signal: controller.signal,
            });
            if (controller.signal.aborted || requestGeneration !== unreadRequestGenerationRef.current) return;
            setUnread(response.data.unread_total);
        } catch {
            // Bubble là tiện ích phụ, lỗi badge không được làm ảnh hưởng trang hiện tại.
        } finally {
            if (requestGeneration === unreadRequestGenerationRef.current && unreadAbortRef.current === controller) {
                unreadAbortRef.current = null;
            }
        }
    }, [baseUrl, isAdmin, mode]);

    useEffect(() => {
        if (!hidden && !open) void loadUnread();

        return () => {
            unreadAbortRef.current?.abort();
            if (unreadRefreshTimerRef.current !== null) window.clearTimeout(unreadRefreshTimerRef.current);
        };
    }, [hidden, loadUnread, open]);

    const scheduleUnreadRefresh = useCallback(() => {
        unreadRequestGenerationRef.current += 1;
        unreadAbortRef.current?.abort();
        unreadAbortRef.current = null;
        if (unreadRefreshTimerRef.current !== null) window.clearTimeout(unreadRefreshTimerRef.current);
        unreadRefreshTimerRef.current = window.setTimeout(() => {
            unreadRefreshTimerRef.current = null;
            void loadUnread();
        }, 100);
    }, [loadUnread]);

    useEffect(() => {
        const refreshUnread = (event: Event) => {
            if (open || hidden) return;
            const delta = (event as CustomEvent<{ delta?: number }>).detail?.delta;
            if (typeof delta === 'number') {
                setUnread(previous => Math.max(0, previous + delta));
                return;
            }
            scheduleUnreadRefresh();
        };
        window.addEventListener('chat:unread-changed', refreshUnread);

        return () => window.removeEventListener('chat:unread-changed', refreshUnread);
    }, [hidden, open, scheduleUnreadRefresh]);

    const handleInbox = useCallback(() => {
        scheduleUnreadRefresh();
    }, [scheduleUnreadRefresh]);

    const handleMessage = useCallback((event: MessageEvent) => {
        const message = event.message;
        if (seenMessageIdsRef.current.has(message.id)) return;
        seenMessageIdsRef.current.add(message.id);
        if (seenMessageIdsRef.current.size > 500) {
            const oldestId = seenMessageIdsRef.current.values().next().value;
            if (typeof oldestId === 'number') seenMessageIdsRef.current.delete(oldestId);
        }
        const mine = message.sender?.id === currentUserId || (!message.sender && message.is_mine === true);
        if (!mine) {
            if (unreadAbortRef.current) scheduleUnreadRefresh();
            else setUnread(previous => previous + 1);
        }
    }, [currentUserId, scheduleUnreadRefresh]);

    const handleRead = useCallback((event: ReadEvent) => {
        if (event.reader.id === currentUserId) scheduleUnreadRefresh();
    }, [currentUserId, scheduleUnreadRefresh]);

    useEffect(() => {
        if (hidden || open) return;
        type PusherConnection = {
            state?: string;
            bind: (event: string, callback: (payload?: { current?: string }) => void) => void;
            unbind: (event: string, callback: (payload?: { current?: string }) => void) => void;
        };
        const connection = (echo() as unknown as {
            connector?: { pusher?: { connection?: PusherConnection } };
        }).connector?.pusher?.connection;
        if (!connection) return;

        let connectedBefore = connection.state === 'connected';
        let needsRecovery = false;
        const handleStateChange = (payload?: { current?: string }) => {
            if (payload?.current === 'connected') {
                if (needsRecovery) scheduleUnreadRefresh();
                connectedBefore = true;
                needsRecovery = false;
            } else if (connectedBefore) {
                needsRecovery = true;
            }
        };
        connection.bind('state_change', handleStateChange);
        return () => connection.unbind('state_change', handleStateChange);
    }, [hidden, open, scheduleUnreadRefresh]);

    useEffect(() => {
        const handleVisibilityChange = () => {
            if (!hidden && !open && document.visibilityState === 'visible') scheduleUnreadRefresh();
        };
        document.addEventListener('visibilitychange', handleVisibilityChange);
        return () => document.removeEventListener('visibilitychange', handleVisibilityChange);
    }, [hidden, open, scheduleUnreadRefresh]);

    if (hidden || !currentUserId || (mode === 'agent' && !props.auth.is_super_admin && !permissions.includes('chats.view'))) {
        return null;
    }

    return (
        <div className="fixed bottom-4 right-4 z-[70] sm:bottom-6 sm:right-6">
            {!open && channel && (
                <BubbleSubscription
                    channel={channel}
                    onInbox={handleInbox}
                    onMessage={handleMessage}
                    onRead={handleRead}
                    onSubscribed={scheduleUnreadRefresh}
                />
            )}

            {open && (
                <div className="mb-3 w-[calc(100vw-2rem)] overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-2xl shadow-slate-900/20 dark:border-slate-700 dark:bg-slate-950 sm:w-[25rem]">
                    <div className="flex items-center justify-between bg-gradient-to-r from-indigo-600 via-blue-600 to-cyan-500 px-4 py-3 text-white">
                        <div className="flex items-center gap-2">
                            <span className="grid h-8 w-8 place-items-center rounded-xl bg-white/15"><MessageCircle className="h-4 w-4" /></span>
                            <div><strong className="block text-sm">{mode === 'agent' ? 'Hộp thư hỗ trợ' : 'Trung tâm hỗ trợ'}</strong><span className="block text-[11px] text-white/75">Tin nhắn được đồng bộ realtime</span></div>
                        </div>
                        <div className="flex items-center gap-1">
                            <Link href={fullPageUrl} className="rounded-lg p-2 transition hover:bg-white/15" title="Mở trang tin nhắn"><ExternalLink className="h-4 w-4" /></Link>
                            <button type="button" onClick={() => setOpen(false)} className="rounded-lg p-2 transition hover:bg-white/15" aria-label="Thu nhỏ chat"><Minus className="h-4 w-4" /></button>
                        </div>
                    </div>
                    <ChatWorkspace mode={mode} baseUrl={baseUrl} compact />
                </div>
            )}

            <button
                type="button"
                onClick={() => setOpen(value => !value)}
                aria-label={open ? 'Đóng cửa sổ chat' : 'Mở cửa sổ chat'}
                className="relative ml-auto grid h-14 w-14 place-items-center rounded-2xl bg-gradient-to-br from-indigo-600 via-blue-600 to-cyan-500 text-white shadow-xl shadow-indigo-500/30 transition duration-200 hover:-translate-y-0.5 hover:shadow-2xl focus:outline-none focus:ring-4 focus:ring-indigo-200 dark:focus:ring-indigo-500/20"
            >
                {open ? <X className="h-6 w-6" /> : <MessageCircle className="h-6 w-6" />}
                {unread > 0 && !open && (
                    <span className="absolute -right-1.5 -top-1.5 grid min-h-6 min-w-6 place-items-center rounded-full border-2 border-white bg-rose-500 px-1 text-[11px] font-bold text-white dark:border-slate-950">
                        {unread > 99 ? '99+' : unread}
                    </span>
                )}
                {!open && <span className="absolute inset-0 -z-10 animate-ping rounded-2xl bg-indigo-400/30 [animation-duration:2.5s]" />}
            </button>
        </div>
    );
}
