import React, { FormEvent, useEffect, useState } from 'react';
import { router, useForm, usePage } from '@inertiajs/react';
import {
    Ban,
    CheckCircle2,
    Copy,
    ExternalLink,
    Globe2,
    LogIn,
    Pencil,
    Plus,
    Power,
    Search,
    X,
} from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import { useToast } from '@/Components/ToastProvider';
import type { PageProps } from '@/types';

interface FrontendClient {
    id: string;
    name: string;
    allowed_origins: string[];
    allows_direct_login: boolean;
    active: boolean;
    created_at: string | null;
    updated_at: string | null;
}

interface ClientPaginator {
    data: FrontendClient[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    prev_page_url: string | null;
    next_page_url: string | null;
}

type ClientForm = {
    name: string;
    allowed_origins: string[];
    allows_direct_login: boolean;
    active: boolean;
};

interface FrontendClientPageProps extends PageProps {
    clients: ClientPaginator;
    filters: {
        search?: string;
        status?: 'active' | 'inactive';
    };
    stats: {
        total: number;
        active: number;
        direct_login: number;
    };
    can_manage: boolean;
}

const emptyForm: ClientForm = {
    name: '',
    allowed_origins: [],
    allows_direct_login: true,
    active: true,
};

export default function FrontendClientsPage() {
    const { clients, filters, stats, can_manage, flash } = usePage<FrontendClientPageProps>().props;
    const toast = useToast();
    const [search, setSearch] = useState(filters.search ?? '');
    const [status, setStatus] = useState(filters.status ?? '');
    const [editingClient, setEditingClient] = useState<FrontendClient | null>(null);
    const [modalOpen, setModalOpen] = useState(false);
    const { data, setData, post, put, processing, errors, reset, clearErrors } = useForm<ClientForm>(emptyForm);

    useEffect(() => {
        if (flash?.success) toast.success(flash.success);
        if (flash?.error) toast.error(flash.error);
    }, [flash?.error, flash?.success, toast]);

    const openCreate = () => {
        setEditingClient(null);
        reset();
        clearErrors();
        setData(emptyForm);
        setModalOpen(true);
    };

    const openEdit = (client: FrontendClient) => {
        setEditingClient(client);
        clearErrors();
        setData({
            name: client.name,
            allowed_origins: client.allowed_origins,
            allows_direct_login: client.allows_direct_login,
            active: client.active,
        });
        setModalOpen(true);
    };

    const closeModal = () => {
        setModalOpen(false);
        setEditingClient(null);
        clearErrors();
    };

    const submitFilters = (event: FormEvent) => {
        event.preventDefault();
        router.get('/admin/frontend-clients', {
            search: search || undefined,
            status: status || undefined,
        }, {
            preserveState: true,
            replace: true,
        });
    };

    const submitClient = (event: FormEvent) => {
        event.preventDefault();

        const options = {
            preserveScroll: true,
            onSuccess: closeModal,
        };

        if (editingClient) {
            put(`/admin/frontend-clients/${editingClient.id}`, options);
            return;
        }

        post('/admin/frontend-clients', options);
    };

    const toggleClient = (client: FrontendClient) => {
        const nextActive = !client.active;
        if (!nextActive && !confirm(`Tắt đăng nhập và refresh từ "${client.name}"?`)) return;

        router.patch(`/admin/frontend-clients/${client.id}/status`, {
            active: nextActive,
        }, {
            preserveScroll: true,
        });
    };

    const copyClientId = async (clientId: string) => {
        await navigator.clipboard.writeText(clientId);
        toast.success('Đã sao chép Client ID.');
    };

    const validationErrors = errors as Record<string, string>;
    const originsError = Object.entries(validationErrors)
        .find(([key]) => key === 'allowed_origins' || key.startsWith('allowed_origins.'))?.[1];

    return (
        <div className="space-y-6">
            <section className="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-900">
                <div className="flex flex-col gap-4 border-b border-slate-200 px-6 py-5 dark:border-slate-800 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <div className="flex items-center gap-2">
                            <Globe2 className="h-6 w-6 text-blue-600" />
                            <h1 className="text-xl font-semibold text-slate-900 dark:text-white">Frontend Clients</h1>
                        </div>
                        <p className="mt-1 text-sm text-slate-500 dark:text-slate-400">
                            Quản lý domain được phép đăng nhập, refresh token và gọi API dùng chung.
                        </p>
                    </div>

                    {can_manage && (
                        <button
                            type="button"
                            onClick={openCreate}
                            className="inline-flex items-center justify-center gap-2 rounded-lg bg-blue-600 px-4 py-2.5 text-sm font-medium text-white transition hover:bg-blue-700"
                        >
                            <Plus className="h-4 w-4" />
                            Thêm frontend
                        </button>
                    )}
                </div>

                <div className="grid gap-4 p-6 sm:grid-cols-3">
                    <StatCard icon={Globe2} label="Tổng frontend" value={stats.total} color="blue" />
                    <StatCard icon={CheckCircle2} label="Đang hoạt động" value={stats.active} color="emerald" />
                    <StatCard icon={LogIn} label="Cho phép đăng nhập" value={stats.direct_login} color="violet" />
                </div>
            </section>

            <section className="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-900">
                <form onSubmit={submitFilters} className="flex flex-col gap-3 border-b border-slate-200 p-4 dark:border-slate-800 sm:flex-row">
                    <label className="relative flex-1">
                        <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
                        <input
                            value={search}
                            onChange={(event) => setSearch(event.target.value)}
                            placeholder="Tìm theo tên, Client ID hoặc domain..."
                            className="w-full rounded-lg border border-slate-300 bg-white py-2.5 pl-9 pr-3 text-sm text-slate-900 outline-none transition focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20 dark:border-slate-700 dark:bg-slate-950 dark:text-white"
                        />
                    </label>
                    <select
                        value={status}
                        onChange={(event) => setStatus(event.target.value as '' | 'active' | 'inactive')}
                        className="rounded-lg border border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-900 outline-none focus:border-blue-500 dark:border-slate-700 dark:bg-slate-950 dark:text-white"
                    >
                        <option value="">Tất cả trạng thái</option>
                        <option value="active">Đang hoạt động</option>
                        <option value="inactive">Đã tắt</option>
                    </select>
                    <button type="submit" className="rounded-lg border border-slate-300 px-4 py-2.5 text-sm font-medium text-slate-700 hover:bg-slate-50 dark:border-slate-700 dark:text-slate-200 dark:hover:bg-slate-800">
                        Lọc
                    </button>
                </form>

                <div className="overflow-x-auto">
                    <table className="min-w-full divide-y divide-slate-200 dark:divide-slate-800">
                        <thead className="bg-slate-50 dark:bg-slate-950/60">
                            <tr className="text-left text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">
                                <th className="px-6 py-3">Frontend</th>
                                <th className="px-6 py-3">Allowed origins</th>
                                <th className="px-6 py-3">Đăng nhập</th>
                                <th className="px-6 py-3">Trạng thái</th>
                                <th className="px-6 py-3 text-right">Thao tác</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                            {clients.data.map((client) => (
                                <tr key={client.id} className="align-top hover:bg-slate-50/70 dark:hover:bg-slate-800/40">
                                    <td className="px-6 py-4">
                                        <div className="font-medium text-slate-900 dark:text-white">{client.name}</div>
                                        <button
                                            type="button"
                                            onClick={() => copyClientId(client.id)}
                                            className="mt-1 inline-flex max-w-[280px] items-center gap-1 font-mono text-xs text-slate-500 hover:text-blue-600"
                                            title="Sao chép Client ID"
                                        >
                                            <span className="truncate">{client.id}</span>
                                            <Copy className="h-3.5 w-3.5 shrink-0" />
                                        </button>
                                    </td>
                                    <td className="px-6 py-4">
                                        <div className="flex max-w-xl flex-wrap gap-2">
                                            {client.allowed_origins.map((origin) => (
                                                <span key={origin} className="inline-flex items-center gap-1 rounded-full bg-slate-100 px-2.5 py-1 text-xs text-slate-700 dark:bg-slate-800 dark:text-slate-200">
                                                    <ExternalLink className="h-3 w-3" />
                                                    {origin}
                                                </span>
                                            ))}
                                        </div>
                                    </td>
                                    <td className="px-6 py-4">
                                        {client.allows_direct_login ? (
                                            <Badge active label="Được phép" />
                                        ) : (
                                            <Badge active={false} label="Đã chặn" />
                                        )}
                                    </td>
                                    <td className="px-6 py-4">
                                        <Badge active={client.active} label={client.active ? 'Hoạt động' : 'Đã tắt'} />
                                    </td>
                                    <td className="px-6 py-4">
                                        {can_manage && (
                                            <div className="flex justify-end gap-2">
                                                <button
                                                    type="button"
                                                    onClick={() => openEdit(client)}
                                                    className="rounded-lg border border-slate-300 p-2 text-slate-600 hover:border-blue-300 hover:bg-blue-50 hover:text-blue-600 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-blue-950/30"
                                                    title="Chỉnh sửa"
                                                >
                                                    <Pencil className="h-4 w-4" />
                                                </button>
                                                <button
                                                    type="button"
                                                    onClick={() => toggleClient(client)}
                                                    className={`rounded-lg border p-2 ${client.active
                                                        ? 'border-red-200 text-red-600 hover:bg-red-50 dark:border-red-900 dark:hover:bg-red-950/30'
                                                        : 'border-emerald-200 text-emerald-600 hover:bg-emerald-50 dark:border-emerald-900 dark:hover:bg-emerald-950/30'}`}
                                                    title={client.active ? 'Tắt client' : 'Bật client'}
                                                >
                                                    {client.active ? <Ban className="h-4 w-4" /> : <Power className="h-4 w-4" />}
                                                </button>
                                            </div>
                                        )}
                                    </td>
                                </tr>
                            ))}

                            {clients.data.length === 0 && (
                                <tr>
                                    <td colSpan={5} className="px-6 py-12 text-center text-sm text-slate-500">
                                        Chưa có frontend client phù hợp.
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>

                <div className="flex items-center justify-between border-t border-slate-200 px-6 py-4 text-sm dark:border-slate-800">
                    <span className="text-slate-500">Trang {clients.current_page}/{Math.max(clients.last_page, 1)} · {clients.total} client</span>
                    <div className="flex gap-2">
                        <button
                            type="button"
                            disabled={!clients.prev_page_url}
                            onClick={() => clients.prev_page_url && router.visit(clients.prev_page_url, { preserveState: true })}
                            className="rounded-lg border border-slate-300 px-3 py-1.5 text-slate-700 disabled:cursor-not-allowed disabled:opacity-40 dark:border-slate-700 dark:text-slate-200"
                        >
                            Trước
                        </button>
                        <button
                            type="button"
                            disabled={!clients.next_page_url}
                            onClick={() => clients.next_page_url && router.visit(clients.next_page_url, { preserveState: true })}
                            className="rounded-lg border border-slate-300 px-3 py-1.5 text-slate-700 disabled:cursor-not-allowed disabled:opacity-40 dark:border-slate-700 dark:text-slate-200"
                        >
                            Sau
                        </button>
                    </div>
                </div>
            </section>

            {modalOpen && (
                <div className="fixed inset-0 z-[100] flex items-center justify-center bg-slate-950/60 p-4 backdrop-blur-sm">
                    <form onSubmit={submitClient} className="w-full max-w-xl overflow-hidden rounded-2xl bg-white shadow-2xl dark:bg-slate-900">
                        <div className="flex items-center justify-between border-b border-slate-200 px-6 py-4 dark:border-slate-800">
                            <div>
                                <h2 className="text-lg font-semibold text-slate-900 dark:text-white">
                                    {editingClient ? 'Chỉnh sửa frontend' : 'Thêm frontend'}
                                </h2>
                                <p className="text-sm text-slate-500">Client ID chỉ dùng để nhận diện website, không phải secret.</p>
                            </div>
                            <button type="button" onClick={closeModal} className="rounded-lg p-2 text-slate-400 hover:bg-slate-100 dark:hover:bg-slate-800">
                                <X className="h-5 w-5" />
                            </button>
                        </div>

                        <div className="space-y-5 p-6">
                            <label className="block">
                                <span className="mb-1.5 block text-sm font-medium text-slate-700 dark:text-slate-200">Tên frontend</span>
                                <input
                                    value={data.name}
                                    onChange={(event) => setData('name', event.target.value)}
                                    placeholder="Ví dụ: ShopHHP Web"
                                    className="w-full rounded-lg border border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-900 outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20 dark:border-slate-700 dark:bg-slate-950 dark:text-white"
                                />
                                {errors.name && <span className="mt-1 block text-xs text-red-600">{errors.name}</span>}
                            </label>

                            <label className="block">
                                <span className="mb-1.5 block text-sm font-medium text-slate-700 dark:text-slate-200">Allowed origins</span>
                                <textarea
                                    rows={5}
                                    value={data.allowed_origins.join('\n')}
                                    onChange={(event) => setData('allowed_origins', event.target.value
                                        .split(/\r?\n/)
                                        .map((origin) => origin.trim())
                                        .filter(Boolean))}
                                    placeholder={'https://shophhp.net\nhttps://www.shophhp.net'}
                                    className="w-full rounded-lg border border-slate-300 bg-white px-3 py-2.5 font-mono text-sm text-slate-900 outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20 dark:border-slate-700 dark:bg-slate-950 dark:text-white"
                                />
                                <span className="mt-1 block text-xs text-slate-500">Mỗi origin một dòng, không nhập đường dẫn phía sau domain.</span>
                                {originsError && <span className="mt-1 block text-xs text-red-600">{originsError}</span>}
                            </label>

                            <label className="flex cursor-pointer items-start gap-3 rounded-xl border border-slate-200 p-4 dark:border-slate-800">
                                <input
                                    type="checkbox"
                                    checked={data.allows_direct_login}
                                    onChange={(event) => setData('allows_direct_login', event.target.checked)}
                                    className="mt-0.5 h-4 w-4 rounded border-slate-300 text-blue-600 focus:ring-blue-500"
                                />
                                <span>
                                    <span className="block text-sm font-medium text-slate-800 dark:text-slate-100">Cho phép đăng nhập trực tiếp</span>
                                    <span className="block text-xs text-slate-500">Cho phép BFF của frontend gọi login, đăng ký và refresh token.</span>
                                </span>
                            </label>

                            <label className="flex cursor-pointer items-start gap-3 rounded-xl border border-slate-200 p-4 dark:border-slate-800">
                                <input
                                    type="checkbox"
                                    checked={data.active}
                                    onChange={(event) => setData('active', event.target.checked)}
                                    className="mt-0.5 h-4 w-4 rounded border-slate-300 text-blue-600 focus:ring-blue-500"
                                />
                                <span>
                                    <span className="block text-sm font-medium text-slate-800 dark:text-slate-100">Frontend đang hoạt động</span>
                                    <span className="block text-xs text-slate-500">Khi tắt, domain bị loại khỏi danh sách động và không thể login/refresh.</span>
                                </span>
                            </label>
                        </div>

                        <div className="flex justify-end gap-3 border-t border-slate-200 bg-slate-50 px-6 py-4 dark:border-slate-800 dark:bg-slate-950/50">
                            <button type="button" onClick={closeModal} className="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 dark:border-slate-700 dark:text-slate-200">
                                Hủy
                            </button>
                            <button disabled={processing} type="submit" className="rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-50">
                                {processing ? 'Đang lưu...' : editingClient ? 'Lưu thay đổi' : 'Tạo client'}
                            </button>
                        </div>
                    </form>
                </div>
            )}
        </div>
    );
}

function Badge({ active, label }: { active: boolean; label: string }) {
    return (
        <span className={`inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-medium ${active
            ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950/50 dark:text-emerald-300'
            : 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300'}`}
        >
            <span className={`h-1.5 w-1.5 rounded-full ${active ? 'bg-emerald-500' : 'bg-slate-400'}`} />
            {label}
        </span>
    );
}

function StatCard({
    icon: Icon,
    label,
    value,
    color,
}: {
    icon: typeof Globe2;
    label: string;
    value: number;
    color: 'blue' | 'emerald' | 'violet';
}) {
    const colors = {
        blue: 'bg-blue-50 text-blue-600 dark:bg-blue-950/40 dark:text-blue-300',
        emerald: 'bg-emerald-50 text-emerald-600 dark:bg-emerald-950/40 dark:text-emerald-300',
        violet: 'bg-violet-50 text-violet-600 dark:bg-violet-950/40 dark:text-violet-300',
    };

    return (
        <div className="flex items-center gap-4 rounded-xl border border-slate-200 p-4 dark:border-slate-800">
            <span className={`rounded-xl p-3 ${colors[color]}`}><Icon className="h-5 w-5" /></span>
            <span>
                <span className="block text-2xl font-semibold text-slate-900 dark:text-white">{value}</span>
                <span className="block text-sm text-slate-500">{label}</span>
            </span>
        </div>
    );
}

FrontendClientsPage.layout = (page: React.ReactNode) => (
    <AdminLayout title="Frontend Clients">{page}</AdminLayout>
);
