import { useEffect, useRef, useState } from 'react';
import { Modal } from 'antd';
import { LoaderCircle, MessageCircle, Search } from 'lucide-react';
import UserAvatar from '@/Components/UserAvatar';
import type { ChatConversation, ChatUser } from './types';

interface StartCustomerChatModalProps {
    open: boolean;
    baseUrl: string;
    onClose: () => void;
    onResolved: (conversation: ChatConversation, enteredActive: boolean) => void | Promise<void>;
}

function requestErrorMessage(error: unknown): string {
    const candidate = error as {
        response?: { data?: { message?: string; errors?: Record<string, string[]> } };
    };
    const validationMessage = Object.values(candidate.response?.data?.errors ?? {}).flat()[0];

    return validationMessage
        ?? candidate.response?.data?.message
        ?? 'Không thể bắt đầu cuộc trò chuyện. Vui lòng thử lại.';
}

export default function StartCustomerChatModal({
    open,
    baseUrl,
    onClose,
    onResolved,
}: StartCustomerChatModalProps) {
    const [query, setQuery] = useState('');
    const [customers, setCustomers] = useState<ChatUser[]>([]);
    const [loading, setLoading] = useState(false);
    const [startingCustomerId, setStartingCustomerId] = useState<number | null>(null);
    const [error, setError] = useState<string | null>(null);
    const searchRequestRef = useRef(0);
    const searchAbortRef = useRef<AbortController | null>(null);

    useEffect(() => {
        if (!open) {
            searchRequestRef.current += 1;
            searchAbortRef.current?.abort();
            searchAbortRef.current = null;
            setQuery('');
            setCustomers([]);
            setLoading(false);
            setStartingCustomerId(null);
            setError(null);
            return;
        }

        const search = query.trim();
        const canSearch = search.length >= 2 || /^\d+$/.test(search);
        if (!canSearch) {
            searchRequestRef.current += 1;
            searchAbortRef.current?.abort();
            searchAbortRef.current = null;
            setCustomers([]);
            setLoading(false);
            setError(null);
            return;
        }

        const requestId = ++searchRequestRef.current;
        searchAbortRef.current?.abort();
        searchAbortRef.current = null;
        setCustomers([]);
        setLoading(true);
        setError(null);
        const timer = window.setTimeout(async () => {
            const controller = new AbortController();
            searchAbortRef.current = controller;

            try {
                const response = await window.axios.get<{ data: ChatUser[] }>(`${baseUrl}/customers`, {
                    params: { search, limit: 20 },
                    signal: controller.signal,
                });
                if (controller.signal.aborted || requestId !== searchRequestRef.current) return;
                setCustomers(response.data.data);
            } catch (requestError) {
                if (controller.signal.aborted || requestId !== searchRequestRef.current) return;
                setCustomers([]);
                setError(requestErrorMessage(requestError));
            } finally {
                if (requestId === searchRequestRef.current) setLoading(false);
                if (searchAbortRef.current === controller) searchAbortRef.current = null;
            }
        }, 300);

        return () => window.clearTimeout(timer);
    }, [baseUrl, open, query]);

    const startConversation = async (customer: ChatUser) => {
        if (startingCustomerId !== null) return;
        setStartingCustomerId(customer.id);
        setError(null);

        try {
            const response = await window.axios.post<{ data: ChatConversation; entered_active: boolean }>(
                `${baseUrl}/conversations/resolve`,
                {
                    customer_id: customer.id,
                    source_app: 'admin-chat',
                    source_url: window.location.href,
                },
            );
            await onResolved(response.data.data, response.data.entered_active);
        } catch (requestError) {
            setError(requestErrorMessage(requestError));
        } finally {
            setStartingCustomerId(null);
        }
    };

    const search = query.trim();
    const hasSearch = search.length >= 2 || /^\d+$/.test(search);

    return (
        <Modal
            open={open}
            onCancel={startingCustomerId === null ? onClose : undefined}
            footer={null}
            width={520}
            title="Nhắn tin chủ động cho khách"
            destroyOnHidden
            maskClosable={startingCustomerId === null}
            closable={startingCustomerId === null}
        >
            <p className="mb-4 text-sm leading-6 text-slate-500">
                Tìm theo tên đăng nhập, email hoặc ID. Hệ thống sẽ mở lại hội thoại phù hợp thay vì tạo trùng.
            </p>

            <label className="relative block">
                <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
                <input
                    autoFocus
                    value={query}
                    onChange={event => setQuery(event.target.value)}
                    placeholder="Nhập tên, email hoặc ID khách hàng"
                    className="w-full rounded-xl border-slate-300 py-2.5 pl-9 pr-10 text-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-slate-700 dark:bg-slate-900"
                />
                {loading && <LoaderCircle className="absolute right-3 top-1/2 h-4 w-4 -translate-y-1/2 animate-spin text-indigo-500" />}
            </label>

            {error && (
                <p className="mt-3 rounded-xl bg-rose-50 px-3 py-2 text-sm text-rose-700 dark:bg-rose-500/10 dark:text-rose-300">
                    {error}
                </p>
            )}

            <div className="mt-4 max-h-80 space-y-1 overflow-y-auto">
                {!hasSearch && (
                    <div className="rounded-xl border border-dashed border-slate-300 px-4 py-8 text-center text-sm text-slate-500 dark:border-slate-700">
                        Nhập ít nhất 2 ký tự để tìm khách hàng.
                    </div>
                )}
                {hasSearch && !loading && customers.length === 0 && !error && (
                    <div className="rounded-xl border border-dashed border-slate-300 px-4 py-8 text-center text-sm text-slate-500 dark:border-slate-700">
                        Không tìm thấy khách hàng phù hợp.
                    </div>
                )}
                {customers.map(customer => (
                    <button
                        key={customer.id}
                        type="button"
                        disabled={startingCustomerId !== null}
                        onClick={() => void startConversation(customer)}
                        className="flex w-full items-center gap-3 rounded-xl px-3 py-2.5 text-left transition hover:bg-indigo-50 disabled:cursor-wait disabled:opacity-60 dark:hover:bg-indigo-500/10"
                    >
                        <UserAvatar user={customer} className="h-10 w-10 shrink-0 text-sm" />
                        <span className="min-w-0 flex-1">
                            <strong className="block truncate text-sm text-slate-900 dark:text-white">{customer.username}</strong>
                            <span className="text-xs text-slate-500">ID: {customer.id}</span>
                        </span>
                        {startingCustomerId === customer.id
                            ? <LoaderCircle className="h-4 w-4 animate-spin text-indigo-500" />
                            : <MessageCircle className="h-4 w-4 text-indigo-500" />}
                    </button>
                ))}
            </div>
        </Modal>
    );
}
