// Admin/SpinResults/Index.tsx
import React, { useEffect, useMemo, useState } from 'react';
import { router, usePage } from "@inertiajs/react";
import AdminLayout from "@/Layouts/AdminLayout";
import { PageProps, PaginatedData } from "@/types";
import { Column, DataTable } from "@/Components/Table/DataTable";
import {
    Eye, User, Gift, DollarSign, Gem, FileText,
    Calendar, TrendingUp, Download, Filter
} from 'lucide-react';
import { useToast } from "@/Components/ToastProvider";
import { useTableFilters } from "@/Hooks/useTableFilters";
import { Image, Tag, Badge, Statistic, Card, Row, Col, Button, Avatar, Space } from "antd";
import { formatDate, formatPrice } from '@/Utils/currencyHelper';

// Interfaces
interface ISpinResult {
    id: number;
    user_id: number;
    user?: {
        id: number;
        name: string;
        email: string;
        avatar: string | null;
    };
    spin_id: number;
    spin?: {
        id: number;
        name: string;
        type: 'wheel' | 'flip';
    };
    reward_type: 'text' | 'coin' | 'gem' | 'nick' | 'item';
    reward_type_label: string;
    reward_value: string;
    reward_display: string;
    reward_id: number | null;
    created_at: string;
    created_at_human: string;
}

interface ISpin {
    id: number;
    name: string;
}

interface SpinResultFilters {
    search?: string;
    spin_id?: number;
    user_id?: number;
    reward_type?: string;
    date_from?: string;
    date_to?: string;
}

interface SpinResultPageProps extends PageProps {
    results: PaginatedData<ISpinResult>;
    spins: ISpin[];
    filters: SpinResultFilters;
}

export default function SpinResultPage() {
    const {
        results,
        spins = [],
        filters: serverFilters,
        flash
    } = usePage<SpinResultPageProps>().props;

    const toast = useToast();

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
        routeName: 'admin.spin-results.index',
        initialFilters: serverFilters || {},
        initialData: results,
        debounceMs: 500,
    });

    const currentFilters = filters as SpinResultFilters;

    // Handlers
    const handleView = (result: ISpinResult) => {
        router.visit(`/admin/spin-results/${result.id}`);
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
    const getRewardTypeIcon = (type: string) => {
        switch (type) {
            case 'text':
                return <FileText className="w-4 h-4" />;
            case 'coin':
                return <DollarSign className="w-4 h-4" />;
            case 'gem':
                return <Gem className="w-4 h-4" />;
            case 'nick':
                return <User className="w-4 h-4" />;
            case 'item':
                return <Gift className="w-4 h-4" />;
            default:
                return <Gift className="w-4 h-4" />;
        }
    };

    const getRewardTypeColor = (type: string) => {
        switch (type) {
            case 'text':
                return 'blue';
            case 'coin':
                return 'gold';
            case 'gem':
                return 'purple';
            case 'nick':
                return 'green';
            case 'item':
                return 'orange';
            default:
                return 'default';
        }
    };

    const renderUserInfo = (result: ISpinResult) => (
        <div className="flex items-center gap-3">
            <Avatar
                size={40}
                src={result.user?.avatar}
                icon={<User className="w-5 h-5" />}
                className="flex-shrink-0"
            />
            <div className="flex-1 min-w-0">
                <div className="font-semibold text-gray-900 truncate">
                    {result.user?.name || 'Unknown User'}
                </div>
                <div className="text-xs text-gray-500 truncate">
                    {result.user?.email || 'N/A'}
                </div>
            </div>
        </div>
    );

    const renderSpinInfo = (result: ISpinResult) => (
        <div className="space-y-1">
            <div className="font-medium text-gray-900">
                {result.spin?.name || 'Unknown Spin'}
            </div>
            <div className="flex items-center gap-1">
                {result.spin?.type === 'wheel' ? (
                    <Tag color="purple" className="text-xs">🎡 Vòng quay</Tag>
                ) : (
                    <Tag color="orange" className="text-xs">🪙 Lật xu</Tag>
                )}
            </div>
        </div>
    );

    const renderReward = (result: ISpinResult) => (
        <div className="space-y-1">
            <Tag
                color={getRewardTypeColor(result.reward_type)}
                icon={getRewardTypeIcon(result.reward_type)}
                className="text-xs"
            >
                {result.reward_type_label}
            </Tag>
            <div className="font-semibold text-gray-900 text-sm">
                {result.reward_display}
            </div>
        </div>
    );

    // Define columns for DataTable
    const columns: Column<ISpinResult>[] = useMemo(() => [
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
            title: 'Người chơi',
            width: 220,
            render: (_, record: ISpinResult) => renderUserInfo(record)
        },
        {
            key: 'spin_info',
            title: 'Vòng quay',
            width: 200,
            render: (_, record: ISpinResult) => renderSpinInfo(record)
        },
        {
            key: 'reward',
            title: 'Phần thưởng nhận được',
            width: 250,
            render: (_, record: ISpinResult) => renderReward(record)
        },
        {
            key: 'reward_type',
            title: 'Loại thưởng',
            width: 130,
            align: 'center',
            filters: [
                { text: '📝 Văn bản', value: 'text' },
                { text: '💰 Xu', value: 'coin' },
                { text: '💎 Kim cương', value: 'gem' },
                { text: '👤 Nick game', value: 'nick' },
                { text: '🎁 Vật phẩm', value: 'item' }
            ],
            render: (type: string, record: ISpinResult) => (
                <Tag
                    color={getRewardTypeColor(type)}
                    icon={getRewardTypeIcon(type)}
                >
                    {record.reward_type_label}
                </Tag>
            )
        },
        {
            key: 'created_at',
            title: 'Thời gian',
            width: 180,
            sortable: true,
            render: (date: string, record: ISpinResult) => (
                <div className="text-sm">
                    <div className="text-gray-900 font-medium">
                        {formatDate(date)}
                    </div>
                    <div className="text-xs text-gray-500">
                        {record.created_at_human}
                    </div>
                </div>
            )
        },
        {
            key: 'actions',
            title: 'Thao tác',
            width: 100,
            fixed: 'right',
            align: 'center',
            render: (_, record: ISpinResult) => (
                <Button
                    type="link"
                    size="small"
                    icon={<Eye className="w-3 h-3" />}
                    onClick={() => handleView(record)}
                >
                    Chi tiết
                </Button>
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
        },
        {
            key: 'reward_type',
            type: 'select' as const,
            label: 'Loại phần thưởng',
            options: [
                { label: '📝 Văn bản', value: 'text' },
                { label: '💰 Xu', value: 'coin' },
                { label: '💎 Kim cương', value: 'gem' },
                { label: '👤 Nick game', value: 'nick' },
                { label: '🎁 Vật phẩm', value: 'item' }
            ],
            value: currentFilters.reward_type || ''
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
    ], [spins, currentFilters]);

    return (
        <>
            {/* Statistics Cards */}
            <div className="mb-6">
                <Row gutter={16}>
                    <Col xs={24} sm={12} md={6}>
                        <Card className="border-l-4 border-blue-500">
                            <Statistic
                                title="Tổng lượt quay"
                                value={results.meta?.total || 0}
                                prefix={<Gift className="w-5 h-5 text-blue-500" />}
                            />
                            <div className="text-xs text-gray-500 mt-2">
                                Trên tổng số: {results.meta?.total || 0} lượt
                            </div>
                        </Card>
                    </Col>
                    <Col xs={24} sm={12} md={6}>
                        <Card className="border-l-4 border-gold-500">
                            <Statistic
                                title="Xu đã trao"
                                value={
                                    results.data
                                        .filter(r => r.reward_type === 'coin')
                                        .reduce((sum, r) => sum + Number(r.reward_value || 0), 0)
                                }
                                formatter={(value) => formatPrice(Number(value))}
                                prefix={<DollarSign className="w-5 h-5 text-yellow-500" />}
                            />
                        </Card>
                    </Col>
                    <Col xs={24} sm={12} md={6}>
                        <Card className="border-l-4 border-purple-500">
                            <Statistic
                                title="Kim cương đã trao"
                                value={
                                    results.data
                                        .filter(r => r.reward_type === 'gem')
                                        .reduce((sum, r) => sum + Number(r.reward_value || 0), 0)
                                }
                                prefix={<Gem className="w-5 h-5 text-purple-500" />}
                            />
                        </Card>
                    </Col>
                    <Col xs={24} sm={12} md={6}>
                        <Card className="border-l-4 border-green-500">
                            <div className="space-y-2">
                                <div className="flex justify-between">
                                    <span className="text-gray-600">Văn bản:</span>
                                    <Badge
                                        count={results.data.filter(r => r.reward_type === 'text').length}
                                        showZero
                                        style={{ backgroundColor: '#1890ff' }}
                                    />
                                </div>
                                <div className="flex justify-between">
                                    <span className="text-gray-600">Nick:</span>
                                    <Badge
                                        count={results.data.filter(r => r.reward_type === 'nick').length}
                                        showZero
                                        style={{ backgroundColor: '#52c41a' }}
                                    />
                                </div>
                                <div className="flex justify-between">
                                    <span className="text-gray-600">Vật phẩm:</span>
                                    <Badge
                                        count={results.data.filter(r => r.reward_type === 'item').length}
                                        showZero
                                        style={{ backgroundColor: '#faad14' }}
                                    />
                                </div>
                            </div>
                        </Card>
                    </Col>
                </Row>
            </div>

            {/* Action Buttons */}
            <div className="mb-4">
                <Space>
                </Space>
            </div>

            {/* Data Table */}
            <DataTable<ISpinResult>
                data={results.data}
                columns={columns}
                loading={loading}
                searchValue={currentFilters.search || ''}
                searchPreset="spinResults"
                title="Lịch sử quay thưởng"
                description="Danh sách tất cả các lượt quay trong hệ thống"
                pagination={{
                    current: results.meta.current_page,
                    pageSize: results.meta.per_page,
                    total: results.meta.total,
                    showSizeChanger: true,
                    pageSizeOptions: ['10', '20', '50', '100'],
                    onChange: handlePageChange,
                }}
                onFiltersChange={setColumnFilters}
                onSearch={handleSearch}
                onReset={handleResetFilters}
                filters={filterOptions}
                searchPlaceholder="Tìm theo tên người chơi, email..."
                emptyText="Chưa có lịch sử quay nào"
                emptyDescription="Các lượt quay sẽ xuất hiện ở đây"
                showAddButton={false}
            />
        </>
    );
}

SpinResultPage.layout = (page: React.ReactNode) => (
    <AdminLayout title="Lịch sử Quay Thưởng" children={page} />
);  
