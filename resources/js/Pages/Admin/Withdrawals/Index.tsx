// Admin/Withdrawals/Index.tsx
import React, { useState, useMemo } from 'react';
import { router, usePage } from "@inertiajs/react";
import AdminLayout from "@/Layouts/AdminLayout";
import { PageProps, PaginatedData } from "@/types";
import { formatCurrency } from "@/Utils/currencyHelper";
import { Column, DataTable } from "@/Components/Table/DataTable";
import {
    Wallet, CheckCircle, XCircle, Clock, User, Building, FileText, Image as ImageIcon
} from 'lucide-react';
import { useToast } from "@/Components/ToastProvider";
import { useTableFilters } from "@/Hooks/useTableFilters";
import { IWithdrawalRequest } from '@/InterFaces/withdrawalRequest';
import WithdrawalModal from './WithdrawalModal';
import ApproveWithdrawalModal from './ApproveWithdrawalModal';
import MarkPaidModal from './MarkPaidModal';
import { Card, Row, Col, Statistic, Badge } from 'antd';
import RejectModal from './RejectModal';
interface WithdrawalFilters {
    search?: string;
    status?: string;
    date_from?: string;
    date_to?: string;
}
interface WithdrawalPageProps extends PageProps {
    withdrawals: PaginatedData<IWithdrawalRequest>;
    filters: {
        search?: string;
        status?: string;
        date_from?: string;
        date_to?: string;
    };
    stats: {
        total_requests: number;
        pending_requests: number;
        approved_requests: number;
        rejected_requests: number;
        paid_requests: number;
        total_amount: number;
        total_fee: number;
        total_net_amount: number;
        paid_amount: number;
        pending_amount: number;
        today_requests: number;
        today_amount: number;
    };
}

export default function WithdrawalPage() {
    const { withdrawals, filters: serverFilters, stats } = usePage<WithdrawalPageProps>().props;
    const toast = useToast();

    // Table filters hook
    const {
        filters,
        columnFilters,
        loading,
        handleSearch,
        handleResetFilters,
        handlePageChange,
        setColumnFilters,
    } = useTableFilters({
        routeName: 'admin.withdrawals.index',
        initialFilters: serverFilters,
        initialData: withdrawals,
        debounceMs: 500,
    });
    const currentFilters = filters as WithdrawalFilters;
    // Modal states
    const [showCreateModal, setShowCreateModal] = useState(false);
    const [showApproveModal, setShowApproveModal] = useState(false);
    const [showRejectModal, setShowRejectModal] = useState(false);
    const [showMarkPaidModal, setShowMarkPaidModal] = useState(false);
    const [selectedWithdrawal, setSelectedWithdrawal] = useState<IWithdrawalRequest | null>(null);

    // Handlers
    const handleApprove = (withdrawal: IWithdrawalRequest) => {
        setSelectedWithdrawal(withdrawal);
        setShowApproveModal(true);
    };

    const handleReject = (withdrawal: IWithdrawalRequest) => {
        setSelectedWithdrawal(withdrawal);
        setShowRejectModal(true);
    };

    const handleMarkPaid = (withdrawal: IWithdrawalRequest) => {
        setSelectedWithdrawal(withdrawal);
        setShowMarkPaidModal(true);
    };

    const handleView = (withdrawal: IWithdrawalRequest) => {
        setSelectedWithdrawal(withdrawal);
        setShowMarkPaidModal(true); // Hoặc tạo ViewModal riêng
    };

    const formatDate = (dateString: string): string => {
        return new Date(dateString).toLocaleDateString('vi-VN', {
            year: 'numeric',
            month: 'short',
            day: 'numeric',
            hour: '2-digit',
            minute: '2-digit'
        });
    };

    const getStatusConfig = (status: string) => {
        const configs = {
            pending: { color: 'bg-yellow-100 text-yellow-800', text: '⏳ Chờ duyệt' },
            approved: { color: 'bg-blue-100 text-blue-800', text: '✅ Đã duyệt' },
            rejected: { color: 'bg-red-100 text-red-800', text: '❌ Đã từ chối' },
            paid: { color: 'bg-green-100 text-green-800', text: '💰 Đã thanh toán' },
        };
        return configs[status as keyof typeof configs] || configs.pending;
    };

    // Columns definition
    const columns: Column<IWithdrawalRequest>[] = useMemo(() => [
        {
            key: 'id',
            title: 'ID',
            width: 80,
            fixed: 'left',
            render: (value: number) => (
                <span className="font-mono font-bold ">#{value}</span>
            )
        },
        {
            key: 'user',
            title: 'Người dùng',
            width: 200,
            render: (value: IWithdrawalRequest['user']) => (
                <div className="flex items-start gap-2">
                    <User className="w-4 h-4 text-blue-500 mt-1" />
                    <div>
                        <div className="font-medium ">{value.username}</div>
                        <div className="text-xs ">{value.email}</div>
                        {value.roles?.length > 0 && (
                            <div className="flex gap-1 mt-1">
                                {value.roles.map((role, idx) => (
                                    <span key={idx} className="inline-flex items-center px-1.5 py-0.5 rounded text-xs bg-purple-100 text-purple-800">
                                        {role}
                                    </span>
                                ))}
                            </div>
                        )}
                    </div>
                </div>
            )
        },
        {
            key: 'amount',
            title: 'Số tiền yêu cầu',
            width: 150,
            align: 'right',
            sortable: true,
            render: (value: string, record: IWithdrawalRequest) => (
                <div className="text-right space-y-1">
                    <div className="font-bold ">{formatCurrency(value)}</div>
                    {record.fee && parseFloat(record.fee) > 0 && (
                        <>
                            <div className="text-xs text-red-600">Phí: -{formatCurrency(record.fee)}</div>
                            <div className="text-xs text-green-600 font-semibold">Nhận: {formatCurrency(record.net_amount)}</div>
                        </>
                    )}
                </div>
            )
        },
        {
            key: 'bank_name',
            title: 'Ngân hàng',
            width: 220,
            render: (value: string, record: IWithdrawalRequest) => (
                <div className="space-y-1">
                    <div className="flex items-center gap-2">
                        <Building className="w-4 h-4 text-blue-500" />
                        <span className="font-medium text-gray-900">{value}</span>
                    </div>
                    <div className="text-xs ">STK: {record.bank_account_number}</div>
                    <div className="text-xs text-gray-500">{record.bank_account_name}</div>
                </div>
            )
        },
        {
            key: 'status',
            title: 'Trạng thái',
            width: 140,
            align: 'center',
            filters: [
                { text: '⏳ Chờ duyệt', value: 'pending' },
                { text: '✅ Đã duyệt', value: 'approved' },
                { text: '❌ Đã từ chối', value: 'rejected' },
                { text: '💰 Đã thanh toán', value: 'paid' }
            ],
            render: (value: string) => {
                const config = getStatusConfig(value);
                return (
                    <span className={`inline-flex items-center px-2.5 py-1 rounded-full text-xs font-medium ${config.color}`}>
                        {config.text}
                    </span>
                );
            }
        },
        {
            key: 'payment_proof',
            title: 'Chứng từ',
            width: 100,
            align: 'center',
            visible: false,
            render: (value: string) => (
                value ? (
                    <a href={value} target="_blank" rel="noopener noreferrer" className=" hover:text-blue-800">
                        <ImageIcon className="w-5 h-5" />
                    </a>
                ) : (
                    <span className="text-gray-400 text-xs">Chưa có</span>
                )
            )
        },
        {
            key: 'note_user',
            title: 'Ghi chú CTV',
            width: 180,
            visible: false,
            render: (value: string) => (
                <div className="text-sm  max-w-xs truncate">
                    {value ? (
                        <div className="flex items-start gap-1" title={value}>
                            <FileText className="w-3 h-3 text-gray-400 mt-0.5" />
                            <span>{value}</span>
                        </div>
                    ) : (
                        <span className="text-gray-400">-</span>
                    )}
                </div>
            )
        },
        {
            key: 'note',
            title: 'Ghi chú Admin',
            width: 180,
            visible: false,
            render: (value: string) => (
                <div className="text-sm max-w-xs truncate">
                    {value ? (
                        <div className="flex items-start gap-1" title={value}>
                            <FileText className="w-3 h-3 text-red-400 mt-0.5" />
                            <span className="text-red-600">{value}</span>
                        </div>
                    ) : (
                        <span className="text-gray-400">-</span>
                    )}
                </div>
            )
        },
        {
            key: 'approver',
            title: 'Người xử lý',
            width: 140,
            visible: false,
            render: (value: IWithdrawalRequest['approver']) => (
                value ? (
                    <div className="text-sm">
                        <div className="font-medium text-gray-900">{value.username}</div>
                        <div className="text-xs text-gray-500">ID: {value.id}</div>
                    </div>
                ) : (
                    <span className="text-gray-400 text-xs">Chưa xử lý</span>
                )
            )
        },
        {
            key: 'created_at',
            title: 'Ngày tạo',
            width: 160,
            sortable: true,
            render: (value: string) => (
                <span className="text-sm ">{formatDate(value)}</span>
            )
        },
        {
            key: 'updated_at',
            title: 'Ngày tạo',
            width: 160,
            sortable: true,
            render: (value: string) => (
                <span className="text-sm ">{formatDate(value)}</span>
            )
        }
    ], []);

    // Filter options
    const filterOptions = useMemo(() => [
        {
            key: 'status',
            type: 'select' as const,
            label: 'Trạng thái',
            options: [
                { label: 'Chờ duyệt', value: 'pending' },
                { label: 'Đã duyệt', value: 'approved' },
                { label: 'Đã từ chối', value: 'rejected' },
                { label: 'Đã thanh toán', value: 'paid' }
            ],
            value: currentFilters.status || ''
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

    return (
        <>
            {/* Statistics */}
            <div className="mb-6">
                <Row gutter={16}>
                    <Col xs={24} sm={12} md={6}>
                        <Card className="border-l-4 border-blue-500">
                            <Statistic
                                title="Tổng yêu cầu"
                                value={stats.total_requests}
                                prefix={<Wallet className="w-5 h-5 text-blue-500" />}
                            />
                            <div className="text-xs text-gray-500 mt-2">Hôm nay: {stats.today_requests}</div>
                        </Card>
                    </Col>
                    <Col xs={24} sm={12} md={6}>
                        <Card className="border-l-4 border-green-500">
                            <Statistic
                                title="Tổng số tiền"
                                value={stats.total_amount}
                                formatter={(val) => formatCurrency(val.toString())}
                            />
                            <div className="text-xs text-gray-500 mt-2">
                                Phí: {formatCurrency(stats.total_fee.toString())}
                            </div>
                        </Card>
                    </Col>
                    <Col xs={24} sm={12} md={6}>
                        <Card className="border-l-4 border-purple-500">
                            <Statistic
                                title="Đã thanh toán"
                                value={stats.paid_amount}
                                formatter={(val) => formatCurrency(val.toString())}
                            />
                        </Card>
                    </Col>
                    <Col xs={24} sm={12} md={6}>
                        <Card className="border-l-4 border-yellow-500">
                            <div className="space-y-2">
                                <div className="flex justify-between">
                                    <span className="text-yellow-600">Chờ duyệt:</span>
                                    <Badge count={stats.pending_requests} showZero style={{ backgroundColor: '#faad14' }} />
                                </div>
                                <div className="flex justify-between">
                                    <span className="">Đã duyệt:</span>
                                    <Badge count={stats.approved_requests} showZero style={{ backgroundColor: '#1890ff' }} />
                                </div>
                                <div className="flex justify-between">
                                    <span className="text-green-600">Đã trả:</span>
                                    <Badge count={stats.paid_requests} showZero style={{ backgroundColor: '#52c41a' }} />
                                </div>
                            </div>
                        </Card>
                    </Col>
                </Row>
            </div>

            {/* DataTable */}
            <DataTable<IWithdrawalRequest>
                data={withdrawals.data}
                columns={columns}
                loading={loading}
                searchValue={filters.search}
                title="Quản lý yêu cầu rút tiền"
                description="Danh sách tất cả các yêu cầu rút tiền trong hệ thống"
                pagination={{
                    current: withdrawals.meta.current_page,
                    pageSize: withdrawals.meta.per_page,
                    total: withdrawals.meta.total,
                    showSizeChanger: true,
                    pageSizeOptions: ['10', '20', '50', '100'],
                    onChange: handlePageChange,
                }}
                onFiltersChange={setColumnFilters}
                onSearch={handleSearch}
                onAdd={() => setShowCreateModal(true)}
                onReset={handleResetFilters}
                onView={handleView}
                filters={filterOptions}
                searchPlaceholder="Tìm theo user, STK, tên ngân hàng..."
                customActions={{
                    approve: {
                        label: 'Duyệt',
                        icon: CheckCircle,
                        handler: handleApprove,
                        condition: (w) => w.status === 'pending',
                        className: '!text-green-600'
                    },
                    reject: {
                        label: 'Từ chối',
                        icon: XCircle,
                        handler: handleReject,
                        condition: (w) => w.status === 'pending',
                        className: '!text-red-600'
                    },
                    markPaid: {
                        label: 'Đánh dấu đã trả',
                        icon: Wallet,
                        handler: handleMarkPaid,
                        condition: (w) => w.status === 'approved',
                        className: '!'
                    }
                }}
            />

            {/* Modals */}
            <WithdrawalModal
                open={showCreateModal}
                onClose={() => setShowCreateModal(false)}
                onSubmit={(data) => {
                    router.post('/admin/withdrawals', data, {
                        onSuccess: () => {
                            toast.success('Tạo yêu cầu rút tiền thành công');
                            setShowCreateModal(false);
                        },
                        onError: () => toast.error('Có lỗi xảy ra')
                    });
                }}
                loading={false}
            />

            <ApproveWithdrawalModal
                open={showApproveModal}
                onClose={() => {
                    setShowApproveModal(false);
                    setSelectedWithdrawal(null);
                }}
                withdrawal={selectedWithdrawal}
            />

            <RejectModal
                open={showRejectModal}
                onClose={() => {
                    setShowRejectModal(false);
                    setSelectedWithdrawal(null);
                }}
                withdrawal={selectedWithdrawal}
            />

            <MarkPaidModal
                open={showMarkPaidModal}
                onClose={() => {
                    setShowMarkPaidModal(false);
                    setSelectedWithdrawal(null);
                }}
                withdrawal={selectedWithdrawal}
            />
        </>
    );
}

WithdrawalPage.layout = (page: React.ReactNode) => (
    <AdminLayout title="Quản lý rút tiền" children={page} />
);