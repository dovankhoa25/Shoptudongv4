// Admin/SpinTickets/Index.tsx
import React, { useEffect, useMemo, useState } from 'react';
import { router, usePage } from "@inertiajs/react";
import AdminLayout from "@/Layouts/AdminLayout";
import { PageProps, PaginatedData } from "@/types";
import { Column, DataTable } from "@/Components/Table/DataTable";
import {
    Eye, Edit3, Trash2, Plus, User, Gift, Ticket,
    Calendar, TrendingUp, Users, Package
} from 'lucide-react';
import { useToast } from "@/Components/ToastProvider";
import { useTableFilters } from "@/Hooks/useTableFilters";
import { Image, Tag, Badge, Statistic, Card, Row, Col, Button, Avatar, Space } from "antd";
import { formatDate } from '@/Utils/currencyHelper';
import SpinTicketModal from './SpinTicketModal';

// Interfaces
interface ISpinTicket {
    id: number;
    user_id: number;
    user?: {
        id: number;
        username: string;
        email: string;
        avatar: string | null;
    };
    spin_id: number;
    spin?: {
        id: number;
        name: string;
        type: 'wheel' | 'flip';
        image_url: string;
    };
    turns_remaining: number;
    created_at: string;
    updated_at: string;
}

interface ISpin {
    id: number;
    name: string;
}

interface SpinTicketFilters {
    search?: string;
    spin_id?: number;
    user_id?: number;
}

interface SpinTicketPageProps extends PageProps {
    tickets: PaginatedData<ISpinTicket>;
    spins: ISpin[];
    filters: SpinTicketFilters;
}

export default function SpinTicketPage() {
    const {
        tickets,
        spins = [],
        filters: serverFilters,
        flash
    } = usePage<SpinTicketPageProps>().props;

    const toast = useToast();

    const [showModal, setShowModal] = useState(false);
    const [selectedTicketId, setSelectedTicketId] = useState<number | null>(null);

    // 🎯 Sử dụng custom hook cho table filters
    const {
        filters,
        columnFilters,
        loading,
        handleSearch,
        handleResetFilters,
        handlePageChange,
        setColumnFilters,
    } = useTableFilters({
        routeName: 'admin.spin-tickets.index',
        initialFilters: serverFilters || {},
        initialData: tickets,
        debounceMs: 500,
    });

    const currentFilters = filters as SpinTicketFilters;

    // Handlers
    const handleAdd = () => {
        setSelectedTicketId(null);
        setShowModal(true);
    };

    const handleEdit = (ticket: ISpinTicket) => {
        setSelectedTicketId(ticket.id);
        setShowModal(true);
    };

    const handleCloseModal = () => {
        setShowModal(false);
        setSelectedTicketId(null);
    };

    const handleDelete = (ticket: ISpinTicket) => {
        if (confirm(`Bạn có chắc chắn muốn xóa lượt quay của "${ticket.user?.username}"?`)) {
            router.delete(`/admin/spin-tickets/${ticket.id}`, {
                onSuccess: () => {
                    toast.success('Lượt quay đã được xóa!');
                },
                onError: (errors) => {
                    toast.error('Xóa lượt quay thất bại!');
                }
            });
        }
    };

    // Flash messages
    useEffect(() => {
        if (flash?.success) {
            toast.success(flash.success);
        }
        if (flash?.error) {
            toast.error(flash.error);
        }
        if (flash?.info) {
            toast.info(flash.info);
        }
    }, [flash]);

    // Render functions
    const renderUserInfo = (ticket: ISpinTicket) => (
        <div className="flex items-center gap-3">
            <Avatar
                size={40}
                src={ticket.user?.avatar}
                icon={<User className="w-5 h-5" />}
                className="flex-shrink-0"
            />
            <div className="flex-1 min-w-0">
                <div className="font-semibold text-gray-900 truncate">
                    {ticket.user?.username || 'Unknown User'}
                </div>
                <div className="text-xs text-gray-500 truncate">
                    {ticket.user?.email || 'N/A'}
                </div>
            </div>
        </div>
    );

    const renderSpinInfo = (ticket: ISpinTicket) => (
        <div className="flex items-start gap-3">
            <div className="w-12 h-12 rounded-lg overflow-hidden border border-gray-200 bg-gray-100 flex-shrink-0">
                {ticket.spin?.image_url ? (
                    <img
                        src={ticket.spin.image_url}
                        alt={ticket.spin.name}
                        className="w-full h-full object-cover"
                    />
                ) : (
                    <div className="w-full h-full flex items-center justify-center">
                        <Gift className="w-5 h-5 text-gray-400" />
                    </div>
                )}
            </div>
            <div className="flex-1 min-w-0">
                <div className="font-medium text-gray-900 truncate">
                    {ticket.spin?.name || 'Unknown Spin'}
                </div>
                <div className="flex items-center gap-1 mt-1">
                    {ticket.spin?.type === 'wheel' ? (
                        <Tag color="purple" className="text-xs">🎡 Vòng quay</Tag>
                    ) : (
                        <Tag color="orange" className="text-xs">🪙 Lật xu</Tag>
                    )}
                </div>
            </div>
        </div>
    );

    const renderTurnsRemaining = (turns: number) => {
        const getColor = () => {
            if (turns === 0) return 'error';
            if (turns <= 5) return 'warning';
            return 'success';
        };

        return (
            <div className="text-center">
                <Badge
                    count={turns}
                    showZero
                    overflowCount={999}
                    style={{
                        backgroundColor: turns === 0 ? '#ff4d4f' : turns <= 5 ? '#faad14' : '#52c41a'
                    }}
                    className="text-lg"
                />
                <div className="text-xs text-gray-500 mt-1">
                    {turns === 0 ? 'Hết lượt' : turns <= 5 ? 'Sắp hết' : 'Còn nhiều'}
                </div>
            </div>
        );
    };

    // Define columns for DataTable
    const columns: Column<ISpinTicket>[] = useMemo(() => [
        {
            key: 'id',
            title: 'ID',
            width: 80,
            fixed: 'left',
            render: (id: number) => (
                <span className="font-mono font-bold text-blue-600">
                    #{id}
                </span>
            )
        },
        {
            key: 'user_info',
            title: 'Người dùng',
            width: 220,
            render: (_, record: ISpinTicket) => renderUserInfo(record)
        },
        {
            key: 'spin_info',
            title: 'Vòng quay',
            width: 250,
            render: (_, record: ISpinTicket) => renderSpinInfo(record)
        },
        {
            key: 'turns_remaining',
            title: 'Lượt còn lại',
            width: 150,
            align: 'center',
            sortable: true,
            render: (turns: number) => renderTurnsRemaining(turns)
        },
        {
            key: 'created_at',
            title: 'Ngày tạo',
            width: 150,
            sortable: true,
            render: (date: string) => (
                <div className="text-sm">
                    <div className="text-gray-900">{formatDate(date)}</div>
                </div>
            )
        },
        {
            key: 'updated_at',
            title: 'Cập nhật',
            width: 150,
            sortable: true,
            render: (date: string) => (
                <div className="text-sm">
                    <div className="text-gray-900">{formatDate(date)}</div>
                </div>
            )
        },
        {
            key: 'actions',
            title: 'Thao tác',
            width: 150,
            fixed: 'right',
            align: 'center',
            render: (_, record: ISpinTicket) => (
                <Space>
                    <Button
                        type="link"
                        size="small"
                        icon={<Edit3 className="w-3 h-3" />}
                        onClick={() => handleEdit(record)}
                    >
                        Sửa
                    </Button>
                    <Button
                        type="link"
                        size="small"
                        danger
                        icon={<Trash2 className="w-3 h-3" />}
                        onClick={() => handleDelete(record)}
                    >
                        Xóa
                    </Button>
                </Space>
            )
        }
    ], []);

    // Filter options
    const filterOptions = useMemo(() => [
        {
            key: 'spin_id',
            type: 'select' as const,
            label: 'Vòng quay',
            options: [
                ...spins.map(spin => ({
                    label: spin.name,
                    value: spin.id.toString()
                }))
            ],
            value: currentFilters.spin_id?.toString() || ''
        }
    ], [spins, currentFilters]);

    return (
        <>
            {/* Statistics Cards */}
            <div className="mb-6">
                <Row gutter={16}>
                    <Col xs={24} sm={12} md={6}>
                        <Card className="border-l-4 border-blue-500">
                            <Statistic
                                title="Tổng người có lượt"
                                value={tickets.meta?.total || 0}
                                prefix={<Users className="w-5 h-5 text-blue-500" />}
                            />
                        </Card>
                    </Col>
                    <Col xs={24} sm={12} md={6}>
                        <Card className="border-l-4 border-green-500">
                            <Statistic
                                title="Tổng lượt còn lại"
                                value={tickets.data.reduce((sum, t) => sum + t.turns_remaining, 0)}
                                prefix={<Ticket className="w-5 h-5 text-green-500" />}
                            />
                        </Card>
                    </Col>
                    <Col xs={24} sm={12} md={6}>
                        <Card className="border-l-4 border-orange-500">
                            <Statistic
                                title="Lượt trung bình/người"
                                value={tickets.data.length > 0
                                    ? Math.round(tickets.data.reduce((sum, t) => sum + t.turns_remaining, 0) / tickets.data.length)
                                    : 0
                                }
                                prefix={<TrendingUp className="w-5 h-5 text-orange-500" />}
                            />
                        </Card>
                    </Col>
                    <Col xs={24} sm={12} md={6}>
                        <Card className="border-l-4 border-red-500">
                            <div className="space-y-2">
                                <div className="flex justify-between">
                                    <span className="text-gray-600">Hết lượt:</span>
                                    <Badge
                                        count={tickets.data.filter(t => t.turns_remaining === 0).length}
                                        showZero
                                        style={{ backgroundColor: '#ff4d4f' }}
                                    />
                                </div>
                                <div className="flex justify-between">
                                    <span className="text-gray-600">Sắp hết:</span>
                                    <Badge
                                        count={tickets.data.filter(t => t.turns_remaining > 0 && t.turns_remaining <= 5).length}
                                        showZero
                                        style={{ backgroundColor: '#faad14' }}
                                    />
                                </div>
                                <div className="flex justify-between">
                                    <span className="text-gray-600">Còn nhiều:</span>
                                    <Badge
                                        count={tickets.data.filter(t => t.turns_remaining > 5).length}
                                        showZero
                                        style={{ backgroundColor: '#52c41a' }}
                                    />
                                </div>
                            </div>
                        </Card>
                    </Col>
                </Row>
            </div>

            {/* Data Table */}
            <DataTable<ISpinTicket>
                data={tickets.data}
                columns={columns}
                loading={loading}
                searchValue={currentFilters.search || ''}
                title="Quản lý Lượt Quay"
                description="Danh sách người dùng có lượt quay"
                pagination={{
                    current: tickets.meta.current_page,
                    pageSize: tickets.meta.per_page,
                    total: tickets.meta.total,
                    showSizeChanger: true,
                    pageSizeOptions: ['10', '20', '50', '100'],
                    onChange: handlePageChange,
                }}
                onFiltersChange={setColumnFilters}
                onSearch={handleSearch}
                onAdd={handleAdd}
                onReset={handleResetFilters}
                filters={filterOptions}
                searchPlaceholder="Tìm theo tên người dùng, email..."
                emptyText="Chưa có lượt quay nào"
                emptyDescription="Các lượt quay sẽ xuất hiện ở đây"
                addButtonText="Cấp lượt quay"
            />

            {/* Spin Ticket Modal */}
            {showModal && (
                <SpinTicketModal
                    open={showModal}
                    onClose={handleCloseModal}
                    ticketId={selectedTicketId}
                    spins={spins}
                />
            )}
        </>
    );
}

SpinTicketPage.layout = (page: React.ReactNode) => (
    <AdminLayout title="Quản lý Lượt Quay" children={page} />
);