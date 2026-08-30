// Admin/Bots/Index.tsx - Bot Management
import React, { useState, useMemo } from 'react';
import { router, usePage } from "@inertiajs/react";
import AdminLayout from "@/Layouts/AdminLayout";
import { PageProps, PaginatedData } from "@/types";
import BotModal from "./BotModal";
import { Column, DataTable } from "@/Components/Table/DataTable";
import {
    Settings, Eye, Bot, CheckCircle, XCircle,
    Calendar, Clock, Server, MapPin, Coins, User,
    TrendingUp, Package, Upload, Download,
    Activity, Zap, Shield
} from 'lucide-react';
import { Tag, Badge, Statistic, Card, Row, Col, Button, Dropdown, Menu, Space } from 'antd';
import { useToast } from "@/Components/ToastProvider";
import { useTableFilters } from "@/Hooks/useTableFilters";
import { IBot } from '@/InterFaces/bot';
import { IServer } from '@/InterFaces/server';
import { IServerGameLogin } from '@/InterFaces/servergamelogin';
import BotHistoryQuickModal from './BotHistoryQuickModal';

interface BotFilters {
    search?: string;
    server_id?: number;
    type?: string;
    status?: number;
    min_gold?: number;
    max_gold?: number;
}

interface BotPageProps extends PageProps {
    bots: PaginatedData<IBot>;
    servers: IServer[];
    logins: IServerGameLogin[];
    filters: BotFilters;
    stats: {
        total_bots: number;
        active_bots: number;
        selling_bots: number;
        import_bots: number;
        total_gold: number;
        total_gold_bars: number;
    };
}

export default function BotPage() {
    const { bots, filters: serverFilters, logins, servers, stats } = usePage<BotPageProps>().props;

    const toast = useToast();

    // 🎯 Table filters hook
    const {
        filters,
        columnFilters,
        loading,
        handleSearch,
        handleResetFilters,
        handlePageChange,
        setColumnFilters,
    } = useTableFilters({
        routeName: 'admin.bots.index',
        initialFilters: serverFilters || {},
        initialData: bots,
        debounceMs: 500,
    });

    const currentFilters = filters as BotFilters;

    // Modal states
    const [modalOpen, setModalOpen] = useState(false);
    const [selectedBot, setSelectedBot] = useState<IBot | null>(null);
    const [selectedBots, setSelectedBots] = useState<number[]>([]);
    // thêm state
    const [historyBot, setHistoryBot] = useState<IBot | null>(null);
    const [historyModalOpen, setHistoryModalOpen] = useState(false);

    // handler click tên bot
    const handleOpenHistory = (bot: IBot) => {
        setHistoryBot(bot);
        setHistoryModalOpen(true);
    };
    // Modal handlers
    const handleOpenModal = () => {
        setSelectedBot(null);
        setModalOpen(true);
    };

    const handleCloseModal = () => {
        setModalOpen(false);
        setSelectedBot(null);
    };

    const handleEdit = (bot: IBot) => {
        setSelectedBot(bot);
        setModalOpen(true);
    };

    const handleView = (bot: IBot) => {
        router.visit(`/admin/bots/${bot.id}`);
    };

    const handleToggleStatus = (bot: IBot) => {
        router.patch(`/admin/bots/${bot.id}/toggle-status`, {}, {
            onSuccess: () => {
                const status = !bot.status ? 'kích hoạt' : 'tạm dừng';
                toast.success(`Bot "${bot.name || bot.account_name}" đã được ${status}!`);
            },
            onError: () => {
                toast.error('Cập nhật trạng thái thất bại. Vui lòng thử lại!');
            }
        });
    };

    const handleDelete = (bot: IBot) => {
        if (confirm(`Bạn có chắc chắn muốn xóa bot "${bot.name || bot.account_name}"?`)) {
            router.delete(`/admin/bots/${bot.id}`, {
                onSuccess: () => {
                    toast.success(`Bot "${bot.name || bot.account_name}" đã được xóa thành công!`);
                },
                onError: () => {
                    toast.error('Xóa bot thất bại. Vui lòng thử lại!');
                }
            });
        }
    };

    // Bulk actions
    const handleBulkAction = (action: string) => {
        if (selectedBots.length === 0) {
            toast.warning('Vui lòng chọn ít nhất một bot!');
            return;
        }

        let endpoint = '';
        let successMessage = '';

        switch (action) {
            case 'activate':
                endpoint = '/admin/bots/bulk-activate';
                successMessage = 'Đã kích hoạt';
                break;
            case 'deactivate':
                endpoint = '/admin/bots/bulk-deactivate';
                successMessage = 'Đã tạm dừng';
                break;
            case 'delete':
                if (!confirm(`Bạn có chắc chắn muốn xóa ${selectedBots.length} bot đã chọn?`)) {
                    return;
                }
                endpoint = '/admin/bots/bulk-delete';
                successMessage = 'Đã xóa';
                break;
        }

        router.post(endpoint, {
            bot_ids: selectedBots
        }, {
            onSuccess: () => {
                toast.success(`${successMessage} ${selectedBots.length} bot!`);
                setSelectedBots([]);
            },
            onError: () => {
                toast.error('Có lỗi xảy ra!');
            }
        });
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

    const formatNumber = (number: number): string => {
        if (!number || number === 0) return '0';
        return new Intl.NumberFormat('vi-VN').format(number);
    };


    const renderBotType = (type: string, record: IBot) => {
        const typeConfigs = {
            'selling_main': { color: 'bg-green-100 text-green-800 border-green-300', text: 'Bán chính', icon: '🛒' },
            'selling_sub': { color: 'bg-emerald-100 text-emerald-800 border-emerald-300', text: 'Bán phụ', icon: '🛍️' },
            'import_main': { color: 'bg-blue-100 text-blue-800 border-blue-300', text: 'Nhập chính', icon: '📦' },
            'import_sub': { color: 'bg-cyan-100 text-cyan-800 border-cyan-300', text: 'Nhập phụ', icon: '📋' },
            'auto_sell_bar': { color: 'bg-purple-100 text-purple-800 border-purple-300', text: 'Auto bán thỏi', icon: '🤖' }
        };

        // Sử dụng types array từ record, fallback về parse từ type string
        const types = record.types || (type ? type.split(',').map(t => t.trim()) : []);

        if (types.length === 0) {
            return <span className="text-gray-400 text-xs">Chưa xác định</span>;
        }

        return (
            <div className="flex flex-wrap gap-1">
                {types.map((t, index) => {
                    const config = typeConfigs[t as keyof typeof typeConfigs];
                    if (!config) return null;

                    return (
                        <div
                            key={index}
                            className={`inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium border ${config.color}`}
                        >
                            <span className="mr-1">{config.icon}</span>
                            {config.text}
                        </div>
                    );
                })}
            </div>
        );
    };

    const renderBotIcon = (type: string, record: IBot) => {
        const iconConfigs = {
            'selling_main': { color: 'text-green-600', bgColor: 'from-green-100 to-emerald-100', icon: Download },
            'selling_sub': { color: 'text-emerald-600', bgColor: 'from-emerald-100 to-green-100', icon: Package },
            'import_main': { color: 'text-blue-600', bgColor: 'from-blue-100 to-cyan-100', icon: Upload },
            'import_sub': { color: 'text-cyan-600', bgColor: 'from-cyan-100 to-blue-100', icon: Activity },
            'auto_sell_bar': { color: 'text-purple-600', bgColor: 'from-purple-100 to-pink-100', icon: Zap }
        };

        // Lấy type đầu tiên để hiển thị icon
        const types = record.types || (type ? type.split(',').map(t => t.trim()) : []);
        const primaryType = types[0] || 'selling_main';

        const config = iconConfigs[primaryType as keyof typeof iconConfigs] || iconConfigs['selling_main'];
        const IconComponent = config.icon;

        // Hiển thị badge số lượng types nếu > 1
        const hasMultipleTypes = types.length > 1;

        return (
            <div className="relative">
                <div className={`w-10 h-10 bg-gradient-to-br ${config.bgColor} rounded-lg flex items-center justify-center`}>
                    <IconComponent className={`w-5 h-5 ${config.color}`} />
                </div>
                {hasMultipleTypes && (
                    <span className="absolute -top-1 -right-1 bg-purple-500 text-white text-xs w-4 h-4 rounded-full flex items-center justify-center font-bold">
                        {types.length}
                    </span>
                )}
            </div>
        );
    };

    const renderGoldInfo = (bot: IBot) => {
        return (
            <div className="text-right">
                <div className="font-semibold text-yellow-600">
                    <Coins className="w-3 h-3 inline mr-1" />
                    {bot.gold_qty_formatted || formatNumber(bot.gold_qty)} vàng
                </div>
                {bot.gold_bar_qty > 0 && (
                    <div className="text-xs text-orange-600">
                        {bot.gold_bar_qty_formatted || formatNumber(bot.gold_bar_qty)} thỏi vàng
                    </div>
                )}
            </div>
        );
    };

    // Get current server for display if filtered
    const currentServer = useMemo(() => {
        return currentFilters.server_id
            ? servers.find(server => server.id === currentFilters.server_id)
            : null;
    }, [currentFilters.server_id, servers]);

    // Define columns for DataTable
    const columns: Column<IBot>[] = useMemo(() => [
        {
            key: 'id',
            title: 'ID',
            width: 60,
            align: 'center',
            visible: false,
            render: (value: any) => (
                <span className="font-mono text-gray-600 bg-gray-100 px-2 py-1 rounded text-sm">
                    #{value}
                </span>
            )
        },
        // {
        //     key: 'name',
        //     title: 'Tên Bot',
        //     width: 250,
        //     sortable: true,
        //     render: (name: string, record: IBot) => (
        //         <div className="flex items-center gap-3">
        //             {renderBotIcon(record.type, record)}
        //             <div>
        //                 <div className="font-semibold text-gray-900">
        //                     {name || 'Chưa đặt tên'}
        //                 </div>
        //                 <div className="text-sm text-gray-500 flex items-center gap-1">
        //                     <User className="w-3 h-3" />
        //                     {record.account_name}
        //                 </div>
        //             </div>
        //         </div>
        //     )
        // },

        {
            key: 'name',
            title: 'Tên Bot',
            width: 250,
            sortable: true,
            render: (name: string, record: IBot) => (
                <div
                    className="flex items-center gap-3 cursor-pointer group"
                    onClick={() => handleOpenHistory(record)}
                >
                    {renderBotIcon(record.type, record)}
                    <div>
                        <div className="font-semibold text-gray-900 group-hover:text-blue-600 transition-colors">
                            {name || 'Chưa đặt tên'}
                        </div>
                        <div className="text-sm text-gray-500 flex items-center gap-1">
                            <User className="w-3 h-3" />
                            {record.account_name}
                        </div>
                    </div>
                </div>
            )
        },
        {
            key: 'type',
            title: 'Loại Bot',
            width: 180, // Tăng width để chứa multiple tags
            filters: [
                { text: '🛒 Bán chính', value: 'selling_main' },
                { text: '🛍️ Bán phụ', value: 'selling_sub' },
                { text: '📦 Nhập chính', value: 'import_main' },
                { text: '📋 Nhập phụ', value: 'import_sub' },
            ],
            render: (type: string, record: IBot) => renderBotType(type, record)
        },
        {
            key: 'server',
            title: 'Server',
            width: 120,
            render: (server: any, record: IBot) => (
                <div className="flex items-center gap-1">
                    <Server className="w-3 h-3 text-gray-500" />
                    <span className="text-sm">{server?.name_view || 'N/A'}</span>
                    <div>
                        ----login id : {record?.server_game_id || 'N/A'}

                    </div>
                </div>

            )
        },

        {
            key: 'map_info',
            title: 'Vị trí',
            width: 180,
            render: (mapInfo: any, record: IBot) => (
                <div className="text-sm">
                    <div className="font-medium text-gray-900 flex items-center gap-1">
                        <MapPin className="w-3 h-3 text-red-500" />
                        {record.map_name || 'Chưa xác định'}
                    </div>
                    <div className="text-xs text-gray-500">
                        ID: {record.map_id} | Khu: {record.area_number} | tọa độ: {record.coordinates}
                    </div>
                </div>
            )
        },
        {
            key: 'gold_info',
            title: 'Số lượng vàng',
            width: 150,
            align: 'right',
            render: (_, record: IBot) => renderGoldInfo(record)
        },
        {
            key: 'proxy',
            title: 'proxy',
            width: 150,
            align: 'right',
            visible: false,
            render: (mapInfo: any, record: IBot) => (
                <div className="text-sm">
                    <div className="text-xs text-gray-500">
                        {record.proxy ?? 'null'}
                    </div>
                </div>
            )
        },
        {
            key: 'updated_by',
            title: 'Cập nhật bởi',
            width: 100,
            align: 'center',
            visible: false,
            render: (updatedBy: string) => {
                const config = {
                    'web': { color: 'bg-blue-100 text-blue-800', text: 'Web' },
                    'app': { color: 'bg-green-100 text-green-800', text: 'App' }
                };
                const updateConfig = config[updatedBy as keyof typeof config] || config['web'];

                return (
                    <span className={`inline-flex items-center px-2 py-0.5 rounded text-xs font-medium ${updateConfig.color}`}>
                        {updateConfig.text}
                    </span>
                );
            }
        },
        {
            key: 'status',
            title: 'Trạng thái',
            width: 120,
            align: 'center',
            filters: [
                { text: '✅ Hoạt động', value: true },
                { text: '⏸️ Tạm dừng', value: false },
            ],
            render: (status: boolean | null | undefined) => { // ✅ Handle all cases
                const isActive = Boolean(status); // ✅ Safe conversion
                const config = isActive
                    ? { color: 'bg-green-100 text-green-800 border-green-300', text: 'Hoạt động', dot: 'bg-green-500' }
                    : { color: 'bg-red-100 text-red-800 border-red-300', text: 'Tạm dừng', dot: 'bg-red-500' };

                return (
                    <div className={`inline-flex items-center px-3 py-1 rounded-full text-xs font-medium border ${config.color}`}>
                        <span className={`w-2 h-2 rounded-full mr-1.5 ${config.dot}`}></span>
                        {config.text}
                    </div>
                );
            }
        },
        {
            key: 'created_at',
            title: 'Ngày tạo',
            width: 150,
            sortable: true,
            visible: false,

            render: (date: string, record: IBot) => (
                <div className="text-sm">
                    <div className="text-gray-900">{formatDate(date)}</div>
                    <div className="text-xs text-gray-500">{record.created_at_human}</div>
                </div>
            )
        },
        {
            key: 'actions',
            title: 'Thao tác',
            width: 200,
            fixed: 'right',
            align: 'center',
            render: (_, record: IBot) => {
                const menu = (
                    <Menu>
                        <Menu.Item key="view" icon={<Eye />} onClick={() => handleView(record)}>
                            Xem chi tiết
                        </Menu.Item>

                        <Menu.Item key="edit" icon={<Settings />} onClick={() => handleEdit(record)}>
                            Chỉnh sửa
                        </Menu.Item>

                        <Menu.Item key="toggle" icon={<TrendingUp />} onClick={() => handleToggleStatus(record)}>
                            {record.status ? 'Tạm dừng' : 'Kích hoạt'}
                        </Menu.Item>

                        <Menu.Divider />

                        <Menu.Item key="delete" icon={<XCircle />} onClick={() => handleDelete(record)} danger>
                            Xóa bot
                        </Menu.Item>
                    </Menu>
                );

                return (
                    <Space>
                        <Button
                            type="primary"
                            size="small"
                            icon={<Settings className="w-3 h-3" />}
                            onClick={() => handleEdit(record)}
                        >
                            Sửa
                        </Button>

                        <Dropdown overlay={menu} trigger={['click']}>
                            <Button size="small">
                                Thao tác
                            </Button>
                        </Dropdown>
                    </Space>
                );
            }
        }
    ], []);

    // Filter options
    const filterOptions = useMemo(() => [
        {
            key: 'server_id',
            type: 'select' as const,
            label: 'Server',
            options: [
                ...servers.map(server => ({
                    label: server.name,
                    value: server.id.toString()
                }))
            ],
            value: currentFilters.server_id?.toString() || ''
        },
        {
            key: 'type',
            type: 'select' as const,
            label: 'Loại Bot',
            options: [
                { label: 'Bán chính', value: 'selling_main' },
                { label: 'Bán phụ', value: 'selling_sub' },
                { label: 'Nhập chính', value: 'import_main' },
                { label: 'Nhập phụ', value: 'import_sub' },
                { label: 'Auto bán thỏi', value: 'auto_sell_bar' },
            ],
            value: currentFilters.type || ''
        },
        {
            key: 'status',
            type: 'select' as const,
            label: 'Trạng thái',
            options: [
                { label: 'Hoạt động', value: '1' },
                { label: 'Tạm dừng', value: '0' }
            ],
            value: currentFilters.status?.toString() || ''
        },
        {
            key: 'min_gold',
            type: 'input' as const,
            label: 'Vàng tối thiểu',
            placeholder: 'VD: 1000',
            value: currentFilters.min_gold?.toString() || ''
        },
        {
            key: 'max_gold',
            type: 'input' as const,
            label: 'Vàng tối đa',
            placeholder: 'VD: 100000',
            value: currentFilters.max_gold?.toString() || ''
        }
    ], [servers, currentFilters]);

    return (
        <>
            {/* Statistics Cards */}
            <div className="mb-6">
                <Row gutter={16}>
                    <Col xs={24} sm={12} md={6}>
                        <Card className="border-l-4 border-blue-500">
                            <Statistic
                                title="Tổng Bot"
                                value={stats.total_bots}
                                prefix={<Bot className="w-5 h-5 text-blue-500" />}
                            />
                            <div className="text-xs text-gray-500 mt-2">
                                Hoạt động: {stats.active_bots}
                            </div>
                        </Card>
                    </Col>

                    <Col xs={24} sm={12} md={6}>
                        <Card className="border-l-4 border-green-500">
                            <Statistic
                                title="Bot Bán"
                                value={stats.selling_bots}
                                prefix={<Download className="w-5 h-5 text-green-500" />}
                            />
                        </Card>
                    </Col>

                    <Col xs={24} sm={12} md={6}>
                        <Card className="border-l-4 border-purple-500">
                            <Statistic
                                title="Bot Nhập"
                                value={stats.import_bots}
                                prefix={<Upload className="w-5 h-5 text-purple-500" />}
                            />
                        </Card>
                    </Col>

                    <Col xs={24} sm={12} md={6}>
                        <Card className="border-l-4 border-yellow-500">
                            <Statistic
                                title="Tổng Vàng"
                                value={stats.total_gold}
                                formatter={(value) => formatNumber(Number(value))}
                                prefix={<Coins className="w-5 h-5 text-yellow-500" />}
                            />
                            <div className="text-xs text-gray-500 mt-2">
                                Thỏi: {formatNumber(stats.total_gold_bars)}
                            </div>
                        </Card>
                    </Col>
                </Row>
            </div>

            {/* Data Table */}
            <DataTable<IBot>
                data={bots.data}
                columns={columns}
                loading={loading}
                searchValue={currentFilters.search || ''}
                title="Quản lý Bot"
                description="Danh sách tất cả các bot trong hệ thống"
                pagination={{
                    current: bots.meta.current_page,
                    pageSize: bots.meta.per_page,
                    total: bots.meta.total,
                    showSizeChanger: true,
                    pageSizeOptions: ['10', '20', '50', '100'],
                    onChange: handlePageChange,
                }}
                onFiltersChange={setColumnFilters}
                onSearch={handleSearch}
                onAdd={handleOpenModal}
                onReset={handleResetFilters}
                filters={filterOptions}
                searchPlaceholder="Tìm theo tên bot, tài khoản..."
                addButtonText="Thêm Bot mới"
                emptyText="Chưa có bot nào"
                emptyDescription="Hãy thêm bot đầu tiên để bắt đầu quản lý"
                rowSelection={{
                    selectedRowKeys: selectedBots,
                    onChange: (keys) => setSelectedBots(keys as number[])
                }}
                customActions={
                    selectedBots.length > 0 ? {
                        bulkActivate: {
                            label: 'Kích hoạt',
                            icon: CheckCircle,
                            handler: () => handleBulkAction('activate'),
                            className: 'text-green-600 hover:text-green-800'
                        },
                        bulkDeactivate: {
                            label: 'Tạm dừng',
                            icon: XCircle,
                            handler: () => handleBulkAction('deactivate'),
                            className: 'text-yellow-600 hover:text-yellow-800'
                        },
                        bulkDelete: {
                            label: 'Xóa',
                            icon: XCircle,
                            handler: () => handleBulkAction('delete'),
                            className: 'text-red-600 hover:text-red-800'
                        }
                    } : undefined
                }
            />

            {/* Modal */}
            <BotModal
                open={modalOpen}
                onClose={handleCloseModal}
                bot={selectedBot}
                servers={servers}
                logins={logins}
            />

            <BotHistoryQuickModal
                open={historyModalOpen}
                bot={historyBot}
                onClose={() => {
                    setHistoryModalOpen(false);
                    setHistoryBot(null);
                }}
            />
        </>
    );
}

BotPage.layout = (page: React.ReactNode) => (
    <AdminLayout title="Bot Management" children={page} />
);