import React, { useState, useEffect } from 'react';
import { Modal, Tag, Timeline, Empty, Spin, Typography, Space, Badge } from 'antd';
import { ClockCircleOutlined, UserOutlined, GlobalOutlined, MobileOutlined, SyncOutlined, PlusOutlined, EditOutlined, DeleteOutlined } from '@ant-design/icons';
import { ChevronRight } from 'lucide-react';
import { IGemBot } from '@/InterFaces/gembot';

const { Text, Link } = Typography;

// ─── Types ────────────────────────────────────────────────────────────────────

interface ChangedField {
    old: any;
    new: any;
}

interface IGemBotHistory {
    id: number;
    action: string;
    source: string;
    changed_fields: Record<string, ChangedField> | null;
    note: string | null;
    ip_address: string | null;
    created_at: string;
    created_at_human: string;
    admin_user?: { id: number; username: string } | null;
}

interface GemBotHistoryQuickModalProps {
    gemBot: IGemBot | null;
    open: boolean;
    onClose: () => void;
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
    gem_qty: 'Số ngọc',
    proxy: 'Proxy',
    account_name: 'Tài khoản',
    updated_by: 'Cập nhật bởi',
    price: 'Giá',
    is_active: 'Kích hoạt',
};

const ACTION_CONFIG: Record<string, {
    color: string;
    icon: React.ReactNode;
    label: string;
    dotColor: string;
}> = {
    create: { color: 'success', icon: <PlusOutlined />, label: 'Tạo mới', dotColor: '#52c41a' },
    update: { color: 'processing', icon: <EditOutlined />, label: 'Cập nhật', dotColor: '#1677ff' },
    delete: { color: 'error', icon: <DeleteOutlined />, label: 'Xóa', dotColor: '#ff4d4f' },
    sync: { color: 'purple', icon: <SyncOutlined />, label: 'Sync', dotColor: '#722ed1' },
};

const SOURCE_CONFIG: Record<string, {
    color: string;
    icon: React.ReactNode;
    label: string;
}> = {
    web: { color: 'cyan', icon: <GlobalOutlined />, label: 'Web' },
    app: { color: 'orange', icon: <MobileOutlined />, label: 'App' },
};

// ─── Helpers ──────────────────────────────────────────────────────────────────

const formatValue = (key: string, value: any): string => {
    if (value === null || value === undefined) return '(trống)';
    if (key === 'status' || key === 'is_active') return value ? 'Hoạt động' : 'Tạm dừng';
    if (key === 'gem_qty' || key === 'price')
        return new Intl.NumberFormat('vi-VN').format(Number(value));
    if (key === 'account_password') return '***';
    return String(value);
};

// ─── Sub-components ───────────────────────────────────────────────────────────

function ChangedFieldsDiff({ changed }: { changed: Record<string, ChangedField> }) {
    const entries = Object.entries(changed);
    if (entries.length === 0)
        return <Text type="secondary" italic>Không có thay đổi</Text>;

    return (
        <div className="space-y-2">
            {entries.map(([key, diff]) => (
                <div key={key}>
                    <Text type="secondary" className="text-xs">
                        {FIELD_LABELS[key] ?? key}:
                    </Text>
                    <div className="flex items-center gap-2 mt-0.5 flex-wrap">
                        <code className="px-1.5 py-0.5 bg-red-50 text-red-600 rounded border border-red-200 text-xs line-through">
                            {formatValue(key, diff.old)}
                        </code>
                        <ChevronRight className="w-3 h-3 text-gray-400 flex-shrink-0" />
                        <code className="px-1.5 py-0.5 bg-green-50 text-green-700 rounded border border-green-200 text-xs font-semibold">
                            {formatValue(key, diff.new)}
                        </code>
                    </div>
                </div>
            ))}
        </div>
    );
}

function HistoryItemContent({ history }: { history: IGemBotHistory }) {
    const actionConfig = ACTION_CONFIG[history.action] ?? {
        color: 'default',
        icon: <SyncOutlined />,
        label: history.action,
        dotColor: '#d9d9d9',
    };
    const sourceConfig = SOURCE_CONFIG[history.source] ?? {
        color: 'default',
        icon: <GlobalOutlined />,
        label: history.source,
    };

    return (
        <div className="pb-1">
            {/* Badges + time */}
            <Space size={6} wrap className="mb-2">
                <Tag
                    color={actionConfig.color}
                    icon={actionConfig.icon}
                    className="m-0"
                >
                    {actionConfig.label}
                </Tag>
                <Tag
                    color={sourceConfig.color}
                    icon={sourceConfig.icon}
                    className="m-0"
                >
                    {sourceConfig.label}
                </Tag>
                <Text type="secondary" className="text-xs flex items-center gap-1">
                    <ClockCircleOutlined className="text-gray-400" />
                    {history.created_at_human}
                </Text>
            </Space>

            {/* Diff content */}
            <div className="pl-1">
                {history.action === 'create' ? (
                    <Tag color="success" bordered={false}>🆕 Được tạo mới</Tag>
                ) : history.action === 'delete' ? (
                    <Tag color="error" bordered={false}>🗑️ Đã bị xóa</Tag>
                ) : history.changed_fields && Object.keys(history.changed_fields).length > 0 ? (
                    <ChangedFieldsDiff changed={history.changed_fields} />
                ) : (
                    <Text type="secondary" italic className="text-xs">
                        Không có thay đổi field
                    </Text>
                )}
            </div>

            {/* Meta footer */}
            <Space size={12} wrap className="mt-2">
                {history.admin_user && (
                    <Text type="secondary" className="text-xs">
                        <UserOutlined className="mr-1" />
                        {history.admin_user.username}
                    </Text>
                )}
                {history.ip_address && (
                    <Text type="secondary" className="text-xs font-mono">
                        {history.ip_address}
                    </Text>
                )}
                {history.note && (
                    <Text type="secondary" italic className="text-xs">
                        {history.note}
                    </Text>
                )}
            </Space>
        </div>
    );
}

// ─── Main Modal ───────────────────────────────────────────────────────────────

export default function GemBotHistoryQuickModal({
    gemBot,
    open,
    onClose,
}: GemBotHistoryQuickModalProps) {
    const [histories, setHistories] = useState<IGemBotHistory[]>([]);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState<string | null>(null);

    useEffect(() => {
        if (!open || !gemBot) return;

        setHistories([]);
        setError(null);
        setLoading(true);

        fetch(`/admin/gem-bots/${gemBot.id}/history-quick-history?limit=10`, {
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
            credentials: 'same-origin',
        })
            .then(res => {
                if (!res.ok) throw new Error('Không thể tải lịch sử');
                return res.json();
            })
            .then(data => setHistories(data.data ?? data))
            .catch(err => setError(err.message))
            .finally(() => setLoading(false));
    }, [open, gemBot?.id]);

    const entityLabel = gemBot?.name || gemBot?.account_name || '';

    const historyPageUrl = `/admin/bot-history?entity_type=gem_bot&entity_id=${gemBot?.id}`;
    // Build timeline items
    const timelineItems = histories.map(history => {
        const dotColor = ACTION_CONFIG[history.action]?.dotColor ?? '#d9d9d9';
        return {
            dot: (
                <div
                    className="w-3 h-3 rounded-full border-2 border-white mt-0.5"
                    style={{ backgroundColor: dotColor, boxShadow: `0 0 0 2px ${dotColor}33` }}
                />
            ),
            children: <HistoryItemContent history={history} />,
        };
    });

    return (
        <Modal
            open={open}
            onCancel={onClose}
            footer={
                <div className="flex items-center justify-between">
                    <Text type="secondary" className="text-xs">
                        {histories.length > 0 ? `${histories.length} bản ghi` : ''}
                    </Text>
                    <Link
                        href={historyPageUrl}
                        className="text-xs"
                    >
                        Xem toàn bộ lịch sử →
                    </Link>
                </div>
            }
            title={
                <div>
                    <div className="text-base font-semibold">Lịch sử cập nhật — Gem Bot</div>
                    <div className="text-sm text-gray-500 font-normal mt-0.5">
                        {entityLabel} — 10 thay đổi gần nhất
                    </div>
                </div>
            }
            width={640}
            styles={{
                body: {
                    maxHeight: '60vh',
                    overflowY: 'auto',
                    paddingTop: 16,
                }
            }}
            destroyOnHidden
        >
            {/* Loading */}
            {loading && (
                <div className="flex items-center justify-center py-12">
                    <Spin tip="Đang tải..." />
                </div>
            )}

            {/* Error */}
            {error && (
                <div className="flex items-center justify-center py-12">
                    <Text type="danger">{error}</Text>
                </div>
            )}

            {/* Empty */}
            {!loading && !error && histories.length === 0 && (
                <Empty
                    description="Chưa có lịch sử nào"
                    image={Empty.PRESENTED_IMAGE_SIMPLE}
                />
            )}

            {/* Timeline */}
            {!loading && !error && histories.length > 0 && (
                <Timeline
                    items={timelineItems}
                    className="pt-2 pr-2"
                />
            )}
        </Modal>
    );
}