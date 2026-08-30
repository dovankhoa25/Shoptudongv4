// Admin/CarotRecharges/Index.tsx - Quản lý nạp Carot
import React, { useMemo, useState } from 'react';
import { router, usePage } from "@inertiajs/react";
import AdminLayout from "@/Layouts/AdminLayout";
import { PageProps, PaginatedData } from "@/types";
import { formatCurrency, formatNumber } from "@/Utils/currencyHelper";
import { Column, DataTable } from "@/Components/Table/DataTable";
import {
    Coins, CheckCircle2, XCircle, Clock, Ban, User, Server,
    Hash, Calendar, Eye, TrendingUp, Wallet, CalendarDays, CalendarRange
} from 'lucide-react';
import { useToast } from "@/Components/ToastProvider";
import { useTableFilters } from "@/Hooks/useTableFilters";
import { Card, Row, Col, Statistic, Badge, Modal, Table, Segmented } from 'antd';

// ===== Interfaces =====
interface ICarotRechargeUser {
    id: number;
    username: string;
    email: string;
}

interface ICarotRecharge {
    id: number;
    transaction_code: string;
    user: ICarotRechargeUser | null;
    account_name: string;
    server_id: number;
    server_name: string;
    amount: number;
    carot: number;
    status: 'pending' | 'success' | 'failed' | 'cancelled';
    status_label: string;
    message: string | null;
    api_response: any;
    processed_at: string | null;
    created_at: string;
    updated_at: string;
}

interface ICarotRechargeStatistic {
    id: number;
    type: 'daily' | 'monthly' | 'yearly';
    stat_date: string;
    scope: {
        user_id: number | null;
        server_id: number | null;
        server_name: string;
    };
    transactions: {
        total: number;
        success: number;
        failed: number;
    };
    amount: {
        total: number;
        formatted: string;
    };
    carot: {
        total: number;
        formatted: string;
    };
    created_at: string;
    updated_at: string;
}

interface CarotRechargeFilters {
    search?: string;
    status?: string;
    server_id?: string;
    date_from?: string;
    date_to?: string;
    stat_type?: string;
}

interface CarotRechargePageProps extends PageProps {
    recharges: PaginatedData<ICarotRecharge>;
    filters: CarotRechargeFilters;
    stats: {
        total_recharges: number;
        pending_count: number;
        success_count: number;
        failed_count: number;
        cancelled_count: number;
        total_amount: number;
        total_carot: number;
        success_amount: number;
        success_carot: number;
        today_count: number;
        today_amount: number;
    };
    statistics: {
        type: 'daily' | 'monthly' | 'yearly';
        // Laravel bọc ResourceCollection không phân trang trong { data: [...] }
        // nên chấp nhận cả hai dạng để tránh vỡ khi format response thay đổi.
        items: ICarotRechargeStatistic[] | { data: ICarotRechargeStatistic[] };
    };
}

export default function CarotRechargesPage() {
    const { recharges, filters: serverFilters, stats, statistics } = usePage<CarotRechargePageProps>().props;
    const toast = useToast();

    const {
        filters,
        columnFilters,
        loading,
        handleSearch,
        handleResetFilters,
        handlePageChange,
        setColumnFilters,
    } = useTableFilters({
        routeName: 'admin.carot-recharges.index',
        initialFilters: serverFilters,
        initialData: recharges,
        debounceMs: 500,
    });

    const currentFilters = filters as CarotRechargeFilters;

    const [selectedRecharge, setSelectedRecharge] = useState<ICarotRecharge | null>(null);
    const [showDetailModal, setShowDetailModal] = useState(false);
    const [statType, setStatType] = useState<'daily' | 'monthly' | 'yearly'>(statistics?.type || 'daily');

    // Laravel bọc resource collection không phân trang trong { data: [...] },
    // trong khi resource phân trang trả thẳng mảng qua Inertia -> chuẩn hóa về mảng.
    const statisticItems = useMemo<ICarotRechargeStatistic[]>(() => {
        const items = statistics?.items as any;
        if (Array.isArray(items)) return items;
        if (items && Array.isArray(items.data)) return items.data;
        return [];
    }, [statistics]);

    const handleView = (record: ICarotRecharge) => {
        setSelectedRecharge(record);
        setShowDetailModal(true);
    };

    const handleStatTypeChange = (value: string | number) => {
        const type = value as 'daily' | 'monthly' | 'yearly';
        setStatType(type);
        router.get(route('admin.carot-recharges.index'), {
            ...serverFilters,
            stat_type: type,
        }, {
            preserveState: true,
            preserveScroll: true,
        });
    };

    const formatDate = (dateString: string | null): string => {
        if (!dateString) return '—';
        return new Date(dateString).toLocaleDateString('vi-VN', {
            year: 'numeric',
            month: 'short',
            day: 'numeric',
            hour: '2-digit',
            minute: '2-digit'
        });
    };

    const getStatusConfig = (status: string) => {
        const configs: Record<string, { color: string; icon: any; text: string }> = {
            pending: { color: 'bg-amber-50 text-amber-700 border border-amber-200', icon: Clock, text: 'Đang chờ xử lý' },
            success: { color: 'bg-emerald-50 text-emerald-700 border border-emerald-200', icon: CheckCircle2, text: 'Thành công' },
            failed: { color: 'bg-rose-50 text-rose-700 border border-rose-200', icon: XCircle, text: 'Thất bại' },
            cancelled: { color: 'bg-slate-50 text-slate-600 border border-slate-200', icon: Ban, text: 'Đã hủy' },
        };
        return configs[status] || configs.pending;
    };

    // ===== Columns =====
    const columns: Column<ICarotRecharge>[] = useMemo(() => [
        {
            key: 'id',
            title: 'ID',
            width: 80,
            fixed: 'left',
            render: (value: number) => (
                <span className="font-mono text-xs font-semibold text-slate-400 bg-slate-100 border border-slate-200 px-2 py-1 rounded-md">
                    #{value}
                </span>
            )
        },
        {
            key: 'transaction_code',
            title: 'Mã giao dịch',
            width: 170,
            render: (value: string) => (
                <span className="inline-flex items-center gap-1.5 font-mono text-xs font-medium text-slate-600 bg-slate-50 border border-slate-200 px-2 py-1 rounded-md">
                    <Hash className="w-3 h-3 text-slate-400" />
                    {value || '—'}
                </span>
            )
        },
        {
            key: 'user',
            title: 'Người dùng',
            width: 190,
            render: (user: ICarotRecharge['user']) => {
                if (!user) {
                    return (
                        <span className="inline-flex items-center gap-1.5 text-xs text-slate-400 italic bg-slate-50 border border-slate-200 px-2.5 py-1 rounded-md">
                            Không xác định
                        </span>
                    );
                }
                return (
                    <div className="flex items-center gap-2.5">
                        <div className="w-8 h-8 rounded-full bg-gradient-to-br from-orange-400 to-amber-500 flex items-center justify-center shadow-sm flex-shrink-0 border-2 border-white ring-1 ring-orange-200">
                            <span className="text-white text-xs font-bold uppercase">
                                {user.username?.charAt(0)}
                            </span>
                        </div>
                        <div className="min-w-0">
                            <div className="font-semibold text-sm truncate">{user.username}</div>
                            <div className="text-xs text-slate-400 truncate">{user.email}</div>
                        </div>
                    </div>
                );
            }
        },
        {
            key: 'account_name',
            title: 'Tài khoản nạp',
            width: 160,
            render: (value: string) => (
                <div className="flex items-center gap-1.5 text-sm">
                    <User className="w-3.5 h-3.5 text-slate-400" />
                    <span className="font-medium text-slate-700">{value}</span>
                </div>
            )
        },
        {
            key: 'server_name',
            title: 'Server',
            width: 120,
            render: (value: string) => (
                <span className="inline-flex items-center gap-1.5 text-xs font-semibold text-indigo-700 bg-indigo-50 border border-indigo-200 px-2.5 py-1 rounded-full">
                    <Server className="w-3 h-3" />
                    {value}
                </span>
            )
        },
        {
            key: 'amount',
            title: 'Số tiền',
            width: 150,
            align: 'right',
            sortable: true,
            render: (value: number) => (
                <span className="font-semibold text-sm text-slate-800">{formatCurrency(value)}</span>
            )
        },
        {
            key: 'carot',
            title: 'Carot',
            width: 130,
            align: 'right',
            sortable: true,
            render: (value: number) => (
                <div className="inline-flex items-center gap-1.5 justify-end px-2.5 py-1 rounded-lg font-semibold text-sm bg-amber-50 text-amber-700 border border-amber-200">
                    <Coins className="w-3.5 h-3.5" />
                    {formatNumber(value)}
                </div>
            )
        },
        {
            key: 'status',
            title: 'Trạng thái',
            width: 160,
            align: 'center',
            filters: [
                { text: '⏳ Đang chờ xử lý', value: 'pending' },
                { text: '✅ Thành công', value: 'success' },
                { text: '❌ Thất bại', value: 'failed' },
                { text: '🚫 Đã hủy', value: 'cancelled' }
            ],
            render: (value: string, record: ICarotRecharge) => {
                const config = getStatusConfig(value);
                const IconComponent = config.icon;
                return (
                    <span className={`inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-semibold ${config.color}`}>
                        <IconComponent className="w-3 h-3" />
                        {record.status_label || config.text}
                    </span>
                );
            }
        },
        {
            key: 'message',
            title: 'Ghi chú',
            width: 220,
            visible: false,
            render: (value: string | null) => (
                value ? (
                    <p className="text-sm text-slate-600 truncate max-w-xs" title={value}>{value}</p>
                ) : (
                    <span className="text-xs text-slate-400 italic">— Không có</span>
                )
            )
        },
        {
            key: 'processed_at',
            title: 'Thời gian xử lý',
            width: 160,
            visible: false,
            render: (value: string | null) => (
                <div className="flex items-center gap-1.5 text-xs text-slate-500">
                    <Calendar className="w-3 h-3 text-slate-400" />
                    {formatDate(value)}
                </div>
            )
        },
        {
            key: 'created_at',
            title: 'Ngày tạo',
            width: 160,
            sortable: true,
            render: (value: string) => (
                <div className="flex items-center gap-1.5 text-xs text-slate-500 bg-slate-50 border border-slate-200 px-2.5 py-1.5 rounded-lg w-fit">
                    <Calendar className="w-3 h-3 text-slate-400 flex-shrink-0" />
                    <span className="font-medium">{formatDate(value)}</span>
                </div>
            )
        }
    ], []);

    // ===== Filters =====
    const filterOptions = useMemo(() => [
        {
            key: 'status',
            type: 'select' as const,
            label: 'Trạng thái',
            options: [
                { label: 'Đang chờ xử lý', value: 'pending' },
                { label: 'Thành công', value: 'success' },
                { label: 'Thất bại', value: 'failed' },
                { label: 'Đã hủy', value: 'cancelled' }
            ],
            value: currentFilters.status || ''
        },
        {
            key: 'server_id',
            type: 'input' as const,
            label: 'Server ID',
            placeholder: 'Nhập ID server...',
            value: currentFilters.server_id || ''
        },
        {
            key: 'date_from',
            type: 'date' as const,
            label: 'Từ ngày',
            value: currentFilters.date_from || ''
        },
        {
            key: 'date_to',
            type: 'date' as const,
            label: 'Đến ngày',
            value: currentFilters.date_to || ''
        }
    ], [currentFilters]);

    // ===== Statistics table =====
    const statisticColumns = [
        {
            title: statType === 'daily' ? 'Ngày' : statType === 'monthly' ? 'Tháng' : 'Năm',
            dataIndex: 'stat_date',
            key: 'stat_date',
            render: (value: string) => (
                <span className="font-medium text-slate-700">
                    {statType === 'daily'
                        ? new Date(value).toLocaleDateString('vi-VN')
                        : statType === 'monthly'
                            ? new Date(value).toLocaleDateString('vi-VN', { month: '2-digit', year: 'numeric' })
                            : new Date(value).toLocaleDateString('vi-VN', { year: 'numeric' })}
                </span>
            )
        },
        {
            title: 'Server',
            dataIndex: 'scope',
            key: 'server',
            render: (scope: ICarotRechargeStatistic['scope']) => (
                <span className="text-xs text-slate-500">{scope?.server_name || 'Tất cả server'}</span>
            )
        },
        {
            title: 'Tổng GD',
            dataIndex: 'transactions',
            key: 'total',
            align: 'right' as const,
            render: (t: ICarotRechargeStatistic['transactions']) => <span className="font-semibold">{formatNumber(t.total)}</span>
        },
        {
            title: 'Thành công',
            dataIndex: 'transactions',
            key: 'success',
            align: 'right' as const,
            render: (t: ICarotRechargeStatistic['transactions']) => <span className="text-emerald-600 font-semibold">{formatNumber(t.success)}</span>
        },
        {
            title: 'Thất bại',
            dataIndex: 'transactions',
            key: 'failed',
            align: 'right' as const,
            render: (t: ICarotRechargeStatistic['transactions']) => <span className="text-rose-600 font-semibold">{formatNumber(t.failed)}</span>
        },
        {
            title: 'Tổng tiền',
            dataIndex: 'amount',
            key: 'amount',
            align: 'right' as const,
            render: (a: ICarotRechargeStatistic['amount']) => <span className="font-semibold">{a.formatted}</span>
        },
        {
            title: 'Tổng Carot',
            dataIndex: 'carot',
            key: 'carot',
            align: 'right' as const,
            render: (c: ICarotRechargeStatistic['carot']) => (
                <span className="inline-flex items-center gap-1 text-amber-700 font-semibold">
                    <Coins className="w-3.5 h-3.5" />
                    {c.formatted}
                </span>
            )
        },
    ];

    return (
        <>
            {/* Statistics Cards */}
            <div className="mb-6">
                <Row gutter={16}>
                    <Col xs={24} sm={12} md={6}>
                        <Card className="border-l-4 border-blue-500">
                            <Statistic
                                title="Tổng lượt nạp"
                                value={stats.total_recharges}
                                prefix={<Wallet className="w-5 h-5 text-blue-500" />}
                            />
                            <div className="text-xs text-gray-500 mt-2">Hôm nay: {stats.today_count}</div>
                        </Card>
                    </Col>
                    <Col xs={24} sm={12} md={6}>
                        <Card className="border-l-4 border-emerald-500">
                            <Statistic
                                title="Tổng tiền nạp"
                                value={stats.total_amount}
                                formatter={(val) => formatCurrency(Number(val))}
                            />
                            <div className="text-xs text-gray-500 mt-2">
                                Thành công: {formatCurrency(stats.success_amount)}
                            </div>
                        </Card>
                    </Col>
                    <Col xs={24} sm={12} md={6}>
                        <Card className="border-l-4 border-amber-500">
                            <Statistic
                                title="Tổng Carot đã nạp"
                                value={stats.total_carot}
                                formatter={(val) => formatNumber(Number(val))}
                                prefix={<Coins className="w-5 h-5 text-amber-500" />}
                            />
                            <div className="text-xs text-gray-500 mt-2">
                                Thành công: {formatNumber(stats.success_carot)}
                            </div>
                        </Card>
                    </Col>
                    <Col xs={24} sm={12} md={6}>
                        <Card className="border-l-4 border-yellow-500">
                            <div className="space-y-2">
                                <div className="flex justify-between">
                                    <span className="text-yellow-600">Đang chờ:</span>
                                    <Badge count={stats.pending_count} showZero style={{ backgroundColor: '#faad14' }} />
                                </div>
                                <div className="flex justify-between">
                                    <span className="text-green-600">Thành công:</span>
                                    <Badge count={stats.success_count} showZero style={{ backgroundColor: '#52c41a' }} />
                                </div>
                                <div className="flex justify-between">
                                    <span className="text-red-600">Thất bại:</span>
                                    <Badge count={stats.failed_count} showZero style={{ backgroundColor: '#ff4d4f' }} />
                                </div>
                                <div className="flex justify-between">
                                    <span className="text-gray-500">Đã hủy:</span>
                                    <Badge count={stats.cancelled_count} showZero style={{ backgroundColor: '#8c8c8c' }} />
                                </div>
                            </div>
                        </Card>
                    </Col>
                </Row>
            </div>

            {/* Statistics chart/table */}
            <div className="bg-white rounded-xl border border-slate-200 shadow-sm mb-6 overflow-hidden">
                <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 p-4 border-b border-slate-100">
                    <div className="flex items-center gap-2">
                        <TrendingUp className="w-4 h-4 text-blue-500" />
                        <h3 className="font-semibold text-slate-800">Thống kê nạp Carot</h3>
                    </div>
                    <Segmented
                        value={statType}
                        onChange={handleStatTypeChange}
                        options={[
                            { label: 'Theo ngày', value: 'daily', icon: <CalendarDays className="w-3.5 h-3.5" /> },
                            { label: 'Theo tháng', value: 'monthly', icon: <Calendar className="w-3.5 h-3.5" /> },
                            { label: 'Theo năm', value: 'yearly', icon: <CalendarRange className="w-3.5 h-3.5" /> },
                        ]}
                    />
                </div>
                <Table
                    dataSource={statisticItems}
                    columns={statisticColumns}
                    rowKey="id"
                    size="small"
                    pagination={false}
                    scroll={{ x: true, y: 320 }}
                    locale={{ emptyText: 'Chưa có dữ liệu thống kê' }}
                />
            </div>

            {/* DataTable */}
            <DataTable<ICarotRecharge>
                data={recharges.data}
                columns={columns}
                loading={loading}
                searchValue={currentFilters.search || ''}
                title="Quản lý nạp Carot"
                description="Danh sách tất cả các giao dịch nạp Carot trong hệ thống"
                pagination={{
                    current: recharges.meta.current_page,
                    pageSize: recharges.meta.per_page,
                    total: recharges.meta.total,
                    showSizeChanger: true,
                    pageSizeOptions: ['20', '50', '100', '200'],
                    onChange: handlePageChange,
                }}
                onFiltersChange={setColumnFilters}
                onSearch={handleSearch}
                onReset={handleResetFilters}
                onView={handleView}
                filters={filterOptions}
                searchPlaceholder="Tìm theo tài khoản, mã GD, user..."
                emptyText="Chưa có giao dịch nạp Carot nào"
                emptyDescription="Các giao dịch nạp Carot sẽ xuất hiện ở đây"
                showAddButton={false}
                customActions={{
                    view: {
                        label: 'Xem chi tiết',
                        icon: Eye,
                        handler: handleView,
                        className: 'text-blue-600 hover:text-blue-800'
                    }
                }}
            />

            {/* Detail Modal */}
            <Modal
                title={`Chi tiết giao dịch #${selectedRecharge?.id ?? ''}`}
                open={showDetailModal}
                onCancel={() => {
                    setShowDetailModal(false);
                    setSelectedRecharge(null);
                }}
                footer={null}
                width={600}
            >
                {selectedRecharge && (
                    <div className="space-y-3">
                        <div className="grid grid-cols-2 gap-3 text-sm">
                            <div>
                                <div className="text-slate-400 text-xs">Mã giao dịch</div>
                                <div className="font-mono font-medium">{selectedRecharge.transaction_code || '—'}</div>
                            </div>
                            <div>
                                <div className="text-slate-400 text-xs">Trạng thái</div>
                                <div className="font-medium">{selectedRecharge.status_label}</div>
                            </div>
                            <div>
                                <div className="text-slate-400 text-xs">Người dùng</div>
                                <div className="font-medium">{selectedRecharge.user?.username || 'Không xác định'}</div>
                            </div>
                            <div>
                                <div className="text-slate-400 text-xs">Tài khoản nạp</div>
                                <div className="font-medium">{selectedRecharge.account_name}</div>
                            </div>
                            <div>
                                <div className="text-slate-400 text-xs">Server</div>
                                <div className="font-medium">{selectedRecharge.server_name}</div>
                            </div>
                            <div>
                                <div className="text-slate-400 text-xs">Số tiền</div>
                                <div className="font-medium">{formatCurrency(selectedRecharge.amount)}</div>
                            </div>
                            <div>
                                <div className="text-slate-400 text-xs">Carot</div>
                                <div className="font-medium">{formatNumber(selectedRecharge.carot)}</div>
                            </div>
                            <div>
                                <div className="text-slate-400 text-xs">Thời gian xử lý</div>
                                <div className="font-medium">{formatDate(selectedRecharge.processed_at)}</div>
                            </div>
                        </div>
                        {selectedRecharge.message && (
                            <div>
                                <div className="text-slate-400 text-xs mb-1">Ghi chú</div>
                                <div className="text-sm bg-slate-50 border border-slate-200 rounded-lg p-3">{selectedRecharge.message}</div>
                            </div>
                        )}
                        {selectedRecharge.api_response && (
                            <div>
                                <div className="text-slate-400 text-xs mb-1">Phản hồi API</div>
                                <pre className="text-xs bg-slate-900 text-slate-100 rounded-lg p-3 overflow-auto max-h-56">
                                    {JSON.stringify(selectedRecharge.api_response, null, 2)}
                                </pre>
                            </div>
                        )}
                    </div>
                )}
            </Modal>
        </>
    );
}

CarotRechargesPage.layout = (page: React.ReactNode) => (
    <AdminLayout title="Quản lý nạp Carot" children={page} />
);
