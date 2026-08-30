// Admin/SpinRewards/Index.tsx - Updated
import React, { useEffect, useMemo, useState } from 'react';
import { router, usePage } from "@inertiajs/react";
import AdminLayout from "@/Layouts/AdminLayout";
import { PageProps } from "@/types";
import { Column, DataTable } from "@/Components/Table/DataTable";
import {
    Eye, Edit3, Trash2, Plus, Tag as TagIcon, DollarSign,
    Gem, Gift, FileText, User, Image as ImageIcon, ArrowLeft,
    Crown, TrendingUp, Percent, AlertCircle
} from 'lucide-react';
import { useToast } from "@/Components/ToastProvider";
import { Image, Tag, Badge, Statistic, Card, Row, Col, Button, Menu, Space, Progress, Alert } from "antd";
import { formatDate, formatPrice } from '@/Utils/currencyHelper';
import SpinRewardModal from './SpinRewardModal';

// Interfaces
interface ISpinReward {
    id: number;
    spin_id: number;
    spin?: {
        id: number;
        name: string;
    };
    reward_type: 'text' | 'coin' | 'gem' | 'nick' | 'item';
    reward_type_label: string;
    reward_value: string;
    reward_display: string;
    image: string | null;
    image_url: string;
    probability: number;
    probability_formatted: string;
    created_at: string;
    updated_at: string;
}

interface ISpin {
    id: number;
    category_id: number;
    name: string;
    image: string | null;
    image_url: string;
    type: 'wheel' | 'flip';
    price_per_turn: number;
    total_slots: number;
    is_public: boolean;
}

interface SpinRewardPageProps extends PageProps {
    spin: ISpin;
    rewards: ISpinReward[]; // ✅ Array type
    totalProbability: number;
    remainingProbability: number;
}

export default function SpinRewardPage() {
    const {
        spin,
        rewards = [],
        totalProbability = 0,
        remainingProbability = 100,
        flash
    } = usePage<SpinRewardPageProps>().props;

    const toast = useToast();

    const [showModal, setShowModal] = useState(false);
    const [selectedRewardId, setSelectedRewardId] = useState<number | null>(null);

    // ✅ Ensure rewards is always an array
    const rewardsList = useMemo(() => {
        if (!rewards) return [];
        if (Array.isArray(rewards)) return rewards;
        // If rewards is an object with data property
        if (typeof rewards === 'object' && 'data' in rewards) {
            return (rewards as any).data || [];
        }
        return [];
    }, [rewards]);

    // Handlers
    const handleAdd = () => {
        if (totalProbability >= 100) {
            toast.error('Tổng xác suất đã đạt 100%. Không thể thêm phần thưởng mới!');
            return;
        }
        setSelectedRewardId(null);
        setShowModal(true);
    };

    const handleEdit = (reward: ISpinReward) => {
        setSelectedRewardId(reward.id);
        setShowModal(true);
    };

    const handleCloseModal = () => {
        setShowModal(false);
        setSelectedRewardId(null);
    };

    const handleDelete = (reward: ISpinReward) => {
        if (confirm(`Bạn có chắc chắn muốn xóa phần thưởng "${reward.reward_display}"?`)) {
            router.delete(`/admin/spins/${spin.id}/rewards/${reward.id}`, {
                onSuccess: () => {
                    toast.success('Phần thưởng đã được xóa!');
                },
                onError: (errors) => {
                    toast.error('Xóa phần thưởng thất bại!');
                }
            });
        }
    };

    const handleBack = () => {
        router.visit('/admin/spins');
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
                return <TagIcon className="w-4 h-4" />;
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

    const renderRewardInfo = (reward: ISpinReward) => (
        <div className="flex items-start gap-3">
            <div className="w-14 h-14 rounded-lg overflow-hidden border border-gray-200 bg-gray-100 flex-shrink-0">
                {reward.image_url ? (
                    <Image
                        src={reward.image_url}
                        alt={reward.reward_value}
                        className="w-full h-full object-cover"
                        fallback="data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII="
                    />
                ) : (
                    <div className="w-full h-full flex items-center justify-center">
                        {getRewardTypeIcon(reward.reward_type)}
                    </div>
                )}
            </div>
            <div className="flex-1 min-w-0">
                <div className="flex items-center gap-2 mb-1">
                    <Tag color={getRewardTypeColor(reward.reward_type)} className="text-xs">
                        {reward.reward_type_label}
                    </Tag>
                </div>
                <div className="text-sm font-semibold text-gray-900 mb-1">
                    {reward.reward_display}
                </div>
                <div className="text-xs text-gray-600">
                    Giá trị: {reward.reward_value}
                </div>
            </div>
        </div>
    );

    const renderProbability = (probability: number) => {
        const getColor = (prob: number) => {
            if (prob >= 20) return '#52c41a'; // green
            if (prob >= 10) return '#faad14'; // yellow
            if (prob >= 5) return '#ff7a45';  // orange
            return '#ff4d4f'; // red
        };

        return (
            <div className="space-y-1">
                <Progress
                    percent={probability}
                    size="small"
                    strokeColor={getColor(probability)}
                    format={(percent) => `${percent}%`}
                />
                <div className="text-xs text-gray-500 text-center">
                    Tỷ lệ trúng
                </div>
            </div>
        );
    };

    // Define columns for DataTable
    const columns: Column<ISpinReward>[] = useMemo(() => [
        {
            key: 'id',
            title: 'ID',
            width: 80,
            render: (id: number) => (
                <span className="font-mono font-bold text-blue-600">
                    #{id}
                </span>
            )
        },
        {
            key: 'reward_info',
            title: 'Thông tin phần thưởng',
            width: 300,
            render: (_, record: ISpinReward) => renderRewardInfo(record)
        },
        {
            key: 'reward_type',
            title: 'Loại',
            width: 120,
            align: 'center',
            filters: [
                { text: '📝 Văn bản', value: 'text' },
                { text: '💰 Xu', value: 'coin' },
                { text: '💎 Kim cương', value: 'gem' },
                { text: '👤 Nick game', value: 'nick' },
                { text: '🎁 Vật phẩm', value: 'item' }
            ],
            render: (type: string, record: ISpinReward) => (
                <Tag
                    color={getRewardTypeColor(type)}
                    icon={getRewardTypeIcon(type)}
                    className="text-sm"
                >
                    {record.reward_type_label}
                </Tag>
            )
        },
        {
            key: 'probability',
            title: 'Tỷ lệ trúng',
            width: 200,
            align: 'center',
            sortable: true,
            render: (probability: number) => renderProbability(probability)
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
            key: 'actions',
            title: 'Thao tác',
            width: 150,
            fixed: 'right',
            align: 'center',
            render: (_, record: ISpinReward) => (
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

    return (
        <div className="space-y-6">
            {/* Header with Back Button */}
            <div className="flex items-center justify-between">
                <div className="flex items-center gap-4">
                    <Button
                        icon={<ArrowLeft className="w-4 h-4" />}
                        onClick={handleBack}
                    >
                        Quay lại
                    </Button>
                    <div>
                        <h1 className="text-2xl font-bold text-gray-900">
                            Quản lý phần thưởng
                        </h1>
                        <p className="text-gray-600 mt-1">
                            Vòng quay: <span className="font-semibold">{spin.name}</span>
                        </p>
                    </div>
                </div>
            </div>

            {/* Spin Info Card */}
            <Card className="shadow-sm">
                <div className="flex items-start gap-4">
                    <div className="w-20 h-20 rounded-lg overflow-hidden border border-gray-200 bg-gray-100 flex-shrink-0">
                        {spin.image_url ? (
                            <img
                                src={spin.image_url}
                                alt={spin.name}
                                className="w-full h-full object-cover"
                            />
                        ) : (
                            <div className="w-full h-full flex items-center justify-center">
                                <ImageIcon className="w-8 h-8 text-gray-400" />
                            </div>
                        )}
                    </div>
                    <div className="flex-1">
                        <h3 className="text-lg font-semibold text-gray-900 mb-2">
                            {spin.name}
                        </h3>
                        <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
                            <div>
                                <div className="text-xs text-gray-500 mb-1">Loại</div>
                                <Tag color={spin.type === 'wheel' ? 'purple' : 'orange'}>
                                    {spin.type === 'wheel' ? '🎡 Vòng quay' : '🪙 Lật xu'}
                                </Tag>
                            </div>
                            <div>
                                <div className="text-xs text-gray-500 mb-1">Số ô</div>
                                <div className="font-semibold">{spin.total_slots} ô</div>
                            </div>
                            <div>
                                <div className="text-xs text-gray-500 mb-1">Giá/lượt</div>
                                <div className="font-semibold text-green-600">
                                    {formatPrice(spin.price_per_turn)}
                                </div>
                            </div>
                            <div>
                                <div className="text-xs text-gray-500 mb-1">Trạng thái</div>
                                <Tag color={spin.is_public ? 'success' : 'default'}>
                                    {spin.is_public ? '✅ Công khai' : '❌ Ẩn'}
                                </Tag>
                            </div>
                        </div>
                    </div>
                </div>
            </Card>

            {/* Probability Statistics */}
            <Row gutter={16}>
                <Col xs={24} sm={8}>
                    <Card className="border-l-4 border-blue-500">
                        <Statistic
                            title="Tổng phần thưởng"
                            value={rewardsList.length}
                            prefix={<Gift className="w-5 h-5 text-blue-500" />}
                        />
                    </Card>
                </Col>
                <Col xs={24} sm={8}>
                    <Card className="border-l-4 border-green-500">
                        <Statistic
                            title="Xác suất đã dùng"
                            value={totalProbability}
                            suffix="%"
                            prefix={<TrendingUp className="w-5 h-5 text-green-500" />}
                        />
                        <Progress
                            percent={totalProbability}
                            size="small"
                            strokeColor={totalProbability === 100 ? '#52c41a' : '#1890ff'}
                            showInfo={false}
                            className="mt-2"
                        />
                    </Card>
                </Col>
                <Col xs={24} sm={8}>
                    <Card className={`border-l-4 ${remainingProbability > 0 ? 'border-orange-500' : 'border-gray-300'}`}>
                        <Statistic
                            title="Xác suất còn lại"
                            value={remainingProbability}
                            suffix="%"
                            prefix={<Percent className="w-5 h-5 text-orange-500" />}
                            valueStyle={{ color: remainingProbability > 0 ? '#faad14' : '#52c41a' }}
                        />
                    </Card>
                </Col>
            </Row>

            {/* Warning Alerts */}
            {remainingProbability < 0 && (
                <Alert
                    message="Cảnh báo: Tổng xác suất vượt quá 100%"
                    description="Vui lòng điều chỉnh lại xác suất của các phần thưởng."
                    type="error"
                    showIcon
                    icon={<AlertCircle className="w-5 h-5" />}
                />
            )}

            {totalProbability < 100 && totalProbability > 0 && (
                <Alert
                    message={`Còn ${remainingProbability}% xác suất chưa phân bổ`}
                    description="Bạn có thể thêm phần thưởng mới hoặc điều chỉnh xác suất các phần thưởng hiện có."
                    type="warning"
                    showIcon
                />
            )}

            {rewardsList.length === 0 && (
                <Alert
                    message="Chưa có phần thưởng nào"
                    description="Vui lòng thêm phần thưởng cho vòng quay này."
                    type="info"
                    showIcon
                />
            )}

            {/* Data Table */}
            <Card>
                <DataTable<ISpinReward>
                    data={rewardsList}
                    columns={columns}
                    loading={false}
                    title="Danh sách phần thưởng"
                    description={`Tổng ${rewardsList.length} phần thưởng - Xác suất: ${totalProbability}%`}
                    // pagination={false}
                    onAdd={handleAdd}
                    addButtonText="Thêm phần thưởng"
                    searchPlaceholder="Tìm theo tên phần thưởng..."
                    emptyText="Chưa có phần thưởng nào"
                    emptyDescription="Thêm phần thưởng đầu tiên cho vòng quay này"
                    showSearch={false}
                />
            </Card>

            {/* Spin Reward Modal */}
            {showModal && (
                <SpinRewardModal
                    open={showModal}
                    onClose={handleCloseModal}
                    rewardId={selectedRewardId}
                    spin={spin}
                    totalProbability={totalProbability}
                    remainingProbability={remainingProbability}
                />
            )}
        </div>
    );
}

SpinRewardPage.layout = (page: React.ReactNode) => (
    <AdminLayout title="Quản lý Phần Thưởng" children={page} />
);