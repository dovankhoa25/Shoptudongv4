// Admin/SpinResults/Show.tsx
import React from 'react';
import { router, usePage } from "@inertiajs/react";
import AdminLayout from "@/Layouts/AdminLayout";
import { PageProps } from "@/types";
import { Card, Descriptions, Tag, Avatar, Button, Timeline } from "antd";
import {
    ArrowLeft, User, Gift, DollarSign, Gem, FileText,
    Calendar, Award
} from 'lucide-react';
import { formatDate, formatPrice } from '@/Utils/currencyHelper';

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
        image_url: string;
    };
    reward_type: 'text' | 'coin' | 'gem' | 'nick' | 'item';
    reward_type_label: string;
    reward_value: string;
    reward_display: string;
    reward_id: number | null;
    created_at: string;
    created_at_human: string;
}

interface SpinResultShowProps extends PageProps {
    result: ISpinResult;
}

export default function SpinResultShow() {
    const { result } = usePage<SpinResultShowProps>().props;

    const handleBack = () => {
        router.visit('/admin/spin-results');
    };

    const getRewardTypeIcon = (type: string) => {
        switch (type) {
            case 'text': return <FileText className="w-5 h-5" />;
            case 'coin': return <DollarSign className="w-5 h-5" />;
            case 'gem': return <Gem className="w-5 h-5" />;
            case 'nick': return <User className="w-5 h-5" />;
            case 'item': return <Gift className="w-5 h-5" />;
            default: return <Gift className="w-5 h-5" />;
        }
    };

    const getRewardTypeColor = (type: string) => {
        switch (type) {
            case 'text': return 'blue';
            case 'coin': return 'gold';
            case 'gem': return 'purple';
            case 'nick': return 'green';
            case 'item': return 'orange';
            default: return 'default';
        }
    };

    return (
        <div className="space-y-6">
            {/* Header */}
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
                            Chi tiết lượt quay #{result.id}
                        </h1>
                        <p className="text-gray-600 mt-1">
                            {result.created_at_human}
                        </p>
                    </div>
                </div>
            </div>

            <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
                {/* User Info */}
                <Card title="Thông tin người chơi" className="shadow-sm">
                    <div className="flex flex-col items-center text-center space-y-4">
                        <Avatar
                            size={80}
                            src={result.user?.avatar}
                            icon={<User className="w-10 h-10" />}
                        />
                        <div>
                            <div className="font-semibold text-lg text-gray-900">
                                {result.user?.name || 'Unknown User'}
                            </div>
                            <div className="text-sm text-gray-500">
                                {result.user?.email || 'N/A'}
                            </div>
                            <Tag color="blue" className="mt-2">
                                ID: {result.user_id}
                            </Tag>
                        </div>
                    </div>
                </Card>

                {/* Spin Info */}
                <Card title="Thông tin vòng quay" className="shadow-sm">
                    <div className="space-y-4">
                        {result.spin?.image_url && (
                            <div className="w-full h-32 rounded-lg overflow-hidden border border-gray-200 bg-gray-100">
                                <img
                                    src={result.spin.image_url}
                                    alt={result.spin.name}
                                    className="w-full h-full object-cover"
                                />
                            </div>
                        )}
                        <Descriptions column={1} size="small">
                            <Descriptions.Item label="Tên">
                                <span className="font-semibold">
                                    {result.spin?.name || 'Unknown'}
                                </span>
                            </Descriptions.Item>
                            <Descriptions.Item label="Loại">
                                {result.spin?.type === 'wheel' ? (
                                    <Tag color="purple">🎡 Vòng quay</Tag>
                                ) : (
                                    <Tag color="orange">🪙 Lật xu</Tag>
                                )}
                            </Descriptions.Item>
                            <Descriptions.Item label="ID">
                                <Tag>#{result.spin_id}</Tag>
                            </Descriptions.Item>
                        </Descriptions>
                    </div>
                </Card>

                {/* Reward Info */}
                <Card title="Phần thưởng nhận được" className="shadow-sm">
                    <div className="space-y-4">
                        <div className="flex items-center justify-center">
                            <div className={`w-20 h-20 rounded-full bg-${getRewardTypeColor(result.reward_type)}-100 flex items-center justify-center`}>
                                <div className={`text-${getRewardTypeColor(result.reward_type)}-600`}>
                                    {getRewardTypeIcon(result.reward_type)}
                                </div>
                            </div>
                        </div>
                        <div className="text-center">
                            <Tag
                                color={getRewardTypeColor(result.reward_type)}
                                className="mb-2"
                            >
                                {result.reward_type_label}
                            </Tag>
                            <div className="text-xl font-bold text-gray-900">
                                {result.reward_display}
                            </div>
                            {result.reward_id && (
                                <div className="text-xs text-gray-500 mt-2">
                                    Reward ID: #{result.reward_id}
                                </div>
                            )}
                        </div>
                    </div>
                </Card>
            </div>

            {/* Timeline */}
            <Card title="Thông tin chi tiết" className="shadow-sm">
                <Descriptions column={2} bordered>
                    <Descriptions.Item label="Mã lượt quay" span={2}>
                        <Tag color="blue">#{result.id}</Tag>
                    </Descriptions.Item>
                    <Descriptions.Item label="Người chơi">
                        {result.user?.name || 'Unknown'}
                    </Descriptions.Item>
                    <Descriptions.Item label="Email">
                        {result.user?.email || 'N/A'}
                    </Descriptions.Item>
                    <Descriptions.Item label="Vòng quay">
                        {result.spin?.name || 'Unknown'}
                    </Descriptions.Item>
                    <Descriptions.Item label="Loại vòng quay">
                        {result.spin?.type === 'wheel' ? 'Vòng quay' : 'Lật xu'}
                    </Descriptions.Item>
                    <Descriptions.Item label="Loại phần thưởng">
                        <Tag color={getRewardTypeColor(result.reward_type)}>
                            {result.reward_type_label}
                        </Tag>
                    </Descriptions.Item>
                    <Descriptions.Item label="Giá trị phần thưởng">
                        <span className="font-semibold">{result.reward_display}</span>
                    </Descriptions.Item>
                    <Descriptions.Item label="Thời gian quay" span={2}>
                        <div className="flex items-center gap-2">
                            <Calendar className="w-4 h-4 text-gray-400" />
                            <span>{formatDate(result.created_at)}</span>
                            <span className="text-gray-500">({result.created_at_human})</span>
                        </div>
                    </Descriptions.Item>
                </Descriptions>
            </Card>
        </div>
    );
}

SpinResultShow.layout = (page: React.ReactNode) => (
    <AdminLayout title="Chi tiết Lượt Quay" children={page} />
);