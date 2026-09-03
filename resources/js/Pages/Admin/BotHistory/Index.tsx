import React, { useMemo } from 'react';
import { usePage } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import { PageProps, PaginatedData } from '@/types';
import { Column, DataTable } from '@/Components/Table/DataTable';
import { useTableFilters } from '@/Hooks/useTableFilters';
import {
    Clock, User, Globe, Monitor,
    Plus, Pencil, Trash2, RefreshCw, ChevronRight,
    Database, AlertTriangle,
} from 'lucide-react';

// ─── Types ────────────────────────────────────────────────────────────────────

interface ChangedField {
    old: any;
    new: any;
}

interface EntityInfo {
    name: string | null;
    account_name: string | null;
    server_id: number | null;
    server_name: string | null;
    deleted: boolean;
}

interface IBotHistory {
    id: number;
    entity_type: string;
    entity_id: number;
    action: string;
    source: string;
    category: string | null;
    admin_user_id: number | null;
    transaction_id: number | null;
    transaction_type: string | null;
    old_data: Record<string, any> | null;
    new_data: Record<string, any> | null;
    changed_fields: Record<string, ChangedField> | null;
    note: string | null;
    ip_address: string | null;
    entity_info: EntityInfo | null;
    created_at: string;
    created_at_human?: string;
    admin_user?: { id: number; username: string } | null;
}

interface HistoryFilters {
    search?: string;
    source?: string;
    action?: string;
    entity_type?: string;
    entity_id?: string;
    date_from?: string;
    date_to?: string;
}

interface HistoryPageProps extends PageProps {
    histories: PaginatedData<IBotHistory>;
    filters: HistoryFilters;
    entityTypes: { label: string; value: string }[];
}

// ─── Config ───────────────────────────────────────────────────────────────────

const FIELD_LABELS: Record<string, string> = {
    name: 'Tên',
    type: 'Loại',
    status: 'Trạng thái',
    server_id: 'Server ID',
    server_game_id: 'Login ID',
    map_id: 'Map ID',
    map_name: 'Tên map',
    area_number: 'Khu vực',
    coordinates: 'Tọa độ',
    gold_qty: 'Vàng',
    gold_bar_qty: 'Thỏi vàng',
    gem_qty: 'Gem',
    proxy: 'Proxy',
    account_name: 'Tài khoản',
    updated_by: 'Cập nhật bởi',
    last_synced_at: 'Lần sync cuối',
};

const ACTION_CONFIG: Record<string, {
    color: string; icon: React.ElementType; label: string;
}> = {
    create: { color: 'bg-green-100 text-green-800 border-green-300', icon: Plus, label: 'Tạo mới' },
    update: { color: 'bg-blue-100 text-blue-800 border-blue-300', icon: Pencil, label: 'Cập nhật' },
    delete: { color: 'bg-red-100 text-red-800 border-red-300', icon: Trash2, label: 'Xóa' },
    sync: { color: 'bg-purple-100 text-purple-800 border-purple-300', icon: RefreshCw, label: 'Sync' },
};

const SOURCE_CONFIG: Record<string, {
    color: string; icon: React.ElementType; label: string;
}> = {
    web: { color: 'bg-cyan-100 text-cyan-800', icon: Globe, label: 'Web' },
    app: { color: 'bg-orange-100 text-orange-800', icon: Monitor, label: 'App' },
};

// ─── Helpers ──────────────────────────────────────────────────────────────────

function classBasename(fullClass: string): string {
    const parts = fullClass.split('\\');
    return parts[parts.length - 1] ?? fullClass;
}

const formatValue = (key: string, value: any): string => {
    if (value === null || value === undefined) return '(trống)';
    if (key === 'status') return value ? 'Hoạt động' : 'Tạm dừng';
    if (key === 'gold_qty' || key === 'gold_bar_qty' || key === 'gem_qty')
        return new Intl.NumberFormat('vi-VN').format(Number(value));
    if (key === 'account_password') return '***';
    return String(value);
};

const formatDate = (dateString: string) =>
    new Date(dateString).toLocaleDateString('vi-VN', {
        year: 'numeric', month: 'short', day: 'numeric',
        hour: '2-digit', minute: '2-digit', second: '2-digit',
    });

// ─── Sub-components ───────────────────────────────────────────────────────────

function ActionBadge({ action }: { action: string }) {
    const config = ACTION_CONFIG[action] ?? {
        color: 'bg-gray-100 text-gray-700 border-gray-300',
        icon: RefreshCw,
        label: action,
    };
    const Icon = config.icon;
    return (
        <span className={`inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-semibold border ${config.color}`}>
            <Icon className="w-3 h-3" />
            {config.label}
        </span>
    );
}

function SourceBadge({ source }: { source: string }) {
    const config = SOURCE_CONFIG[source] ?? {
        color: 'bg-gray-100 text-gray-700',
        icon: Globe,
        label: source,
    };
    const Icon = config.icon;
    return (
        <span className={`inline-flex items-center gap-1 px-2 py-0.5 rounded text-xs font-medium ${config.color}`}>
            <Icon className="w-3 h-3" />
            {config.label}
        </span>
    );
}

function EntityBadge({ type, id }: { type: string; id: number }) {
    return (
        <div className="flex items-center gap-1.5">
            <div className="w-6 h-6 rounded bg-indigo-100 flex items-center justify-center flex-shrink-0">
                <Database className="w-3.5 h-3.5 text-indigo-600" />
            </div>
            <div>
                <div className="text-xs font-semibold text-gray-700">
                    {classBasename(type)}
                </div>
                <div className="text-xs text-gray-400 font-mono">#{id}</div>
            </div>
        </div>
    );
}

function EntityInfoCard({ info }: { info: EntityInfo }) {
    return (
        <div className="mt-1.5 space-y-0.5 text-xs">
            {info.deleted && (
                <div className="inline-flex items-center gap-1 px-1.5 py-0.5 rounded bg-red-50 text-red-600 border border-red-200 mb-1">
                    <AlertTriangle className="w-3 h-3" />
                    <span className="font-medium">Đã bị xóa</span>
                </div>
            )}
            {info.name && (
                <div className="font-semibold text-gray-800">{info.name}</div>
            )}
            {info.account_name && (
                <div className="text-gray-500">
                    👤 {info.account_name}
                </div>
            )}
            {info.server_id && (
                <div className="text-gray-400">
                    🖥️{' '}
                    <span className="font-mono">#{info.server_id}</span>
                    {info.server_name && (
                        <span className="ml-1 text-indigo-600 font-medium">
                            {info.server_name}
                        </span>
                    )}
                </div>
            )}
        </div>
    );
}

function ChangedFieldsDiff({ changed }: { changed: Record<string, ChangedField> }) {
    const entries = Object.entries(changed);
    if (entries.length === 0)
        return <span className="text-gray-400 text-xs italic">Không có thay đổi</span>;

    return (
        <div className="space-y-1.5">
            {entries.map(([key, diff]) => (
                <div key={key} className="text-xs">
                    <span className="font-semibold text-gray-500">
                        {FIELD_LABELS[key] ?? key}:
                    </span>
                    <div className="flex items-center gap-1.5 mt-0.5 flex-wrap">
                        <span className="px-1.5 py-0.5 bg-red-50 text-red-700 rounded border border-red-200 line-through font-mono">
                            {formatValue(key, diff.old)}
                        </span>
                        <ChevronRight className="w-3 h-3 text-gray-400 flex-shrink-0" />
                        <span className="px-1.5 py-0.5 bg-green-50 text-green-700 rounded border border-green-200 font-mono font-semibold">
                            {formatValue(key, diff.new)}
                        </span>
                    </div>
                </div>
            ))}
        </div>
    );
}

// ─── Main Page ─────────────────────────────────────────────────────────────────

export default function BotHistoryPage() {
    const { histories, filters: serverFilters, entityTypes } =
        usePage<HistoryPageProps>().props;

    const {
        filters,
        loading,
        handleSearch,
        handleResetFilters,
        handlePageChange,
        setColumnFilters,
    } = useTableFilters({
        routeName: 'admin.bot-history.index',
        initialFilters: serverFilters || {},
        initialData: histories,
        debounceMs: 400,
    });

    const currentFilters = filters as HistoryFilters;

    // ── Columns ────────────────────────────────────────────────────────────────
    const columns: Column<IBotHistory>[] = useMemo(() => [
        {
            key: 'id',
            title: 'ID',
            width: 60,
            align: 'center',
            render: (value: number) => (
                <span className="font-mono text-gray-400 text-xs">#{value}</span>
            ),
        },
        {
            key: 'entity_type',
            title: 'Đối tượng',
            width: 190,
            render: (_: string, record: IBotHistory) => (
                <div>
                    <EntityBadge type={record.entity_type} id={record.entity_id} />
                    {record.entity_info
                        ? <EntityInfoCard info={record.entity_info} />
                        : <span className="text-xs text-gray-300 italic mt-1 block">Không có thông tin</span>
                    }
                </div>
            ),
        },
        {
            key: 'action',
            title: 'Hành động',
            width: 115,
            align: 'center',
            render: (action: string) => <ActionBadge action={action} />,
        },
        {
            key: 'changed_fields',
            title: 'Thay đổi',
            width: 360,
            render: (changed: Record<string, ChangedField> | null, record: IBotHistory) => {
                if (record.action === 'create')
                    return (
                        <span className="text-xs text-green-700 bg-green-50 px-2 py-1 rounded border border-green-200">
                            🆕 Được tạo mới
                        </span>
                    );
                if (record.action === 'delete')
                    return (
                        <span className="text-xs text-red-700 bg-red-50 px-2 py-1 rounded border border-red-200">
                            🗑️ Đã bị xóa
                        </span>
                    );
                if (!changed || Object.keys(changed).length === 0)
                    return (
                        <span className="text-gray-400 text-xs italic">
                            Không có thay đổi field
                        </span>
                    );
                return <ChangedFieldsDiff changed={changed} />;
            },
        },
        {
            key: 'source',
            title: 'Nguồn',
            width: 85,
            align: 'center',
            render: (source: string) => <SourceBadge source={source} />,
        },
        {
            key: 'admin_user',
            title: 'Thực hiện bởi',
            width: 150,
            render: (_: any, record: IBotHistory) => (
                <div className="flex items-center gap-2">
                    <div className="w-7 h-7 rounded-full bg-indigo-100 flex items-center justify-center flex-shrink-0">
                        <User className="w-3.5 h-3.5 text-indigo-600" />
                    </div>
                    <div>
                        <div className="text-sm font-medium text-gray-800">
                            {record.admin_user?.username ?? 'Hệ thống'}
                        </div>
                        {record.ip_address && (
                            <div className="text-xs text-gray-400 font-mono">
                                {record.ip_address}
                            </div>
                        )}
                    </div>
                </div>
            ),
        },
        {
            key: 'note',
            title: 'Ghi chú',
            width: 160,
            render: (note: string | null) =>
                note
                    ? <span className="text-xs text-gray-600 italic">{note}</span>
                    : <span className="text-gray-300 text-xs">—</span>,
        },
        {
            key: 'created_at',
            title: 'Thời gian',
            width: 170,
            sortable: true,
            render: (date: string, record: IBotHistory) => (
                <div className="text-sm">
                    <div className="flex items-center gap-1 text-gray-800">
                        <Clock className="w-3 h-3 text-gray-400 flex-shrink-0" />
                        {formatDate(date)}
                    </div>
                    {record.created_at_human && (
                        <div className="text-xs text-gray-400 mt-0.5">
                            {record.created_at_human}
                        </div>
                    )}
                </div>
            ),
        },
    ], []);

    // ── Filter options ─────────────────────────────────────────────────────────
    const filterOptions = useMemo(() => [
        {
            key: 'entity_type',
            type: 'select' as const,
            label: 'Loại đối tượng',
            options: entityTypes,
        },
        {
            key: 'entity_id',
            type: 'input' as const,
            label: 'Entity ID',
            placeholder: 'VD: 42',
        },
        {
            key: 'action',
            type: 'select' as const,
            label: 'Hành động',
            options: [
                { label: 'Tạo mới', value: 'create' },
                { label: 'Cập nhật', value: 'update' },
                { label: 'Xóa', value: 'delete' },
                { label: 'Sync', value: 'sync' },
            ],
        },
        {
            key: 'source',
            type: 'select' as const,
            label: 'Nguồn',
            options: [
                { label: 'Web', value: 'web' },
                { label: 'App', value: 'app' },
            ],
        },
        {
            key: 'date_from',
            type: 'input' as const,
            label: 'Từ ngày',
            placeholder: 'YYYY-MM-DD',
        },
        {
            key: 'date_to',
            type: 'input' as const,
            label: 'Đến ngày',
            placeholder: 'YYYY-MM-DD',
        },
    ], [entityTypes]);

    // ── Render ─────────────────────────────────────────────────────────────────
    return (
        <DataTable<IBotHistory>
            data={histories.data}
            columns={columns}
            loading={loading}
            searchValue={currentFilters.search || ''}
            searchPreset="botHistory"
            title="Lịch sử thay đổi"
            description="Toàn bộ lịch sử thay đổi của tất cả đối tượng trong hệ thống"
            pagination={{
                current: histories.meta.current_page,
                pageSize: histories.meta.per_page,
                total: histories.meta.total,
                showSizeChanger: true,
                pageSizeOptions: ['10', '20', '50', '100'],
                onChange: handlePageChange,
            }}
            onFiltersChange={setColumnFilters}
            onSearch={handleSearch}
            onReset={handleResetFilters}
            filters={filterOptions}
            searchPlaceholder="Tìm theo ghi chú, IP..."
            emptyText="Chưa có lịch sử nào"
            emptyDescription="Các thay đổi sẽ được ghi lại tại đây"
        />
    );
}

BotHistoryPage.layout = (page: React.ReactNode) => (
    <AdminLayout title="Lịch Sử Thay Đổi" children={page} />
);
