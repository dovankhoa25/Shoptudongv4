
// Admin/GemBots/Index.tsx - Gem Bot Management
import React, { useState, useMemo } from 'react';
import { router, usePage } from "@inertiajs/react";
import AdminLayout from "@/Layouts/AdminLayout";
import { PageProps, PaginatedData } from "@/types";
import GemBotModal from "./GemBotModal";
import { Column, DataTable } from "@/Components/Table/DataTable";
import {
    Settings, Eye, Bot, CheckCircle, XCircle,
    Calendar, Clock, Server, MapPin, Gem, User,
    TrendingUp, Sparkles, Diamond
} from 'lucide-react';
import { useToast } from "@/Components/ToastProvider";
import { useTableFilters } from "@/Hooks/useTableFilters";
import { IGemBot } from '@/InterFaces/gembot';
import { IServer } from '@/InterFaces/server';
import { IServerGameLogin } from '@/InterFaces/servergamelogin';
import GemBotHistoryQuickModal from './GemBotHistoryQuickModal';

interface GemBotFilters {
    search?: string;
    server_id?: number;
    status?: number;
    min_gems?: number;
    max_gems?: number;
}

interface GemBotPageProps extends PageProps {
    gemBots: PaginatedData<IGemBot>;
    servers: IServer[];
    logins: IServerGameLogin[];
    filters: GemBotFilters;
    stats: {
        total_bots: number;
        active_bots: number;
        total_gems: number;
    };
}

export default function GemBotPage() {
    const { gemBots, filters: serverFilters, logins, servers, stats } = usePage<GemBotPageProps>().props;

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
        routeName: 'admin.gem-bots.index',
        initialFilters: serverFilters || {},
        initialData: gemBots,
        debounceMs: 500,
    });

    // Cast filters to our specific type
    const currentFilters = filters as GemBotFilters;

    // Modal states
    const [modalOpen, setModalOpen] = useState(false);
    const [selectedGemBot, setSelectedGemBot] = useState<IGemBot | null>(null);


    const [historyGemBot, setHistoryGemBot] = useState<IGemBot | null>(null);
    const [historyModalOpen, setHistoryModalOpen] = useState(false);

    const handleOpenHistory = (gemBot: IGemBot) => {
        setHistoryGemBot(gemBot);
        setHistoryModalOpen(true);
    };


    // Modal handlers
    const handleOpenModal = () => {
        setSelectedGemBot(null);
        setModalOpen(true);
    };

    const handleCloseModal = () => {
        setModalOpen(false);
        setSelectedGemBot(null);
    };

    const handleEdit = (gemBot: IGemBot) => {
        setSelectedGemBot(gemBot);
        setModalOpen(true);
    };

    const handleView = (gemBot: IGemBot) => {
        router.visit(route('admin.gem-bots.show', gemBot.id));
    };

    const handleToggleStatus = (gemBot: IGemBot) => {
        router.patch(`/admin/gem-bots/${gemBot.id}/toggle-status`, {}, {
            onSuccess: () => {
                const status = !gemBot.status ? 'kích hoạt' : 'vô hiệu hóa';
                toast.success(`Bot "${gemBot.name || gemBot.account_name}" đã được ${status}!`);
            },
            onError: () => {
                toast.error('Cập nhật trạng thái thất bại. Vui lòng thử lại!');
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

    const renderBotIcon = () => {
        const iconColor = 'text-purple-600';
        const bgColor = 'from-purple-100 to-pink-100';

        return (
            <div className={`w-10 h-10 bg-gradient-to-br ${bgColor} rounded-lg flex items-center justify-center`}>
                <Diamond className={`w-5 h-5 ${iconColor}`} />
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
    const columns: Column<IGemBot>[] = useMemo(() => [
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
        {
            key: 'name',
            title: 'Tên Bot',
            width: 250,
            sortable: true,
            // render: (name: string, record: IGemBot) => (
            //     <div className="flex items-center gap-3">
            //         {renderBotIcon()}
            //         <div>
            //             <div className="font-semibold text-gray-900">
            //                 {name || 'Chưa đặt tên'}
            //             </div>
            //             <div className="text-sm text-gray-500 flex items-center gap-1">
            //                 <User className="w-3 h-3" />
            //                 {record.account_name}
            //             </div>
            //         </div>
            //     </div>
            // )
            render: (name: string, record: IGemBot) => (
                <div
                    className="flex items-center gap-3 cursor-pointer group"
                    onClick={() => handleOpenHistory(record)}
                >
                    {renderBotIcon()}
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
            key: 'server',
            title: 'Server',
            width: 120,
            render: (server: any, record: IGemBot) => (
                <span className="text-sm text-gray-700 flex items-center gap-1">
                    <Server className="w-3 h-3 text-blue-500" />
                    {server?.name_view || 'N/A'}
                    <div>
                        ----login id : {record?.server_game_id || 'N/A'}

                    </div>
                </span>
            )
        },
        {
            key: 'map_info',
            title: 'Vị trí',
            width: 180,
            render: (mapInfo: any, record: IGemBot) => (
                <div className="text-sm">
                    <div className="font-medium text-gray-900 flex items-center gap-1">
                        <MapPin className="w-3 h-3 text-red-500" />
                        {mapInfo?.map_name || 'Chưa xác định'}
                    </div>
                    <div className="text-xs text-gray-500">
                        ID: {mapInfo?.map_id} | Khu: {mapInfo?.area_number} | tọa độ: {mapInfo?.coordinates}
                    </div>
                </div>
            )
        },
        {
            key: 'proxy',
            title: 'proxy',
            width: 150,
            align: 'right',
            visible: false,
            render: (mapInfo: any, record: IGemBot) => (
                <div className="text-sm">
                    <div className="text-xs text-gray-500">
                        {record.map_info.proxy ?? 'null'}
                    </div>
                </div>
            )
        },
        {
            key: 'gem_qty_formatted',
            title: 'Số ngọc',
            width: 140,
            align: 'right',
            sortable: true,
            render: (gemQtyFormatted: string, record: IGemBot) => (
                <div className="text-right">
                    <div className="text-sm font-medium text-purple-700 flex items-center justify-end gap-1">
                        <Gem className="" />
                        {gemQtyFormatted}
                    </div>
                    {/* {record.last_synced_at && (
                        <div className="text-xs text-gray-500">
                            Sync: {record.last_synced_at_human}
                        </div>
                    )} */}
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
            key: 'status_label',
            title: 'Trạng thái',
            width: 120,
            align: 'center',
            filters: [
                { text: '✅ Hoạt động', value: true },
                { text: '⏸️ Tạm dừng', value: false },
            ],
            render: (statusLabel: string, record: IGemBot) => {
                const statusConfig = {
                    'Hoạt động': {
                        color: 'bg-green-100 text-green-800',
                        icon: CheckCircle,
                        dot: 'bg-green-500'
                    },
                    'Không hoạt động': {
                        color: 'bg-red-100 text-red-800',
                        icon: XCircle,
                        dot: 'bg-red-500'
                    },
                };

                const config = statusConfig[statusLabel as keyof typeof statusConfig] || statusConfig['Hoạt động'];

                return (
                    <span className={`inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium ${config.color}`}>
                        <span className={`w-2 h-2 rounded-full mr-1.5 ${config.dot}`}></span>
                        {statusLabel}
                    </span>
                );
            },
        },
        {
            key: 'created_at',
            title: 'Ngày tạo',
            sortable: true,
            width: 140,
            visible: false,

            render: (value: string) => (
                <span className="text-sm text-gray-600 flex items-center gap-1">
                    <Calendar className="w-3 h-3" />
                    {formatDate(value)}
                </span>
            )
        },
        {
            key: 'last_synced_at',
            title: 'Lần sync cuối',
            sortable: true,
            visible: false,
            width: 140,
            render: (value: string) => (
                <span className="text-sm text-gray-600 flex items-center gap-1">
                    <Clock className="w-3 h-3" />
                    {value ? formatDate(value) : 'Chưa sync'}
                </span>
            )
        }
    ], []);

    // Prepare filter options
    const filterOptions = useMemo(() => [
        {
            key: 'server_id',
            type: 'select' as const,
            label: 'Server',
            options: [
                // { label: 'Tất cả server', value: '' },
                ...servers.map(server => ({
                    label: server.name,
                    value: server.id.toString()
                }))
            ],
            value: currentFilters.server_id?.toString() || ''
        },
        {
            key: 'status',
            type: 'select' as const,
            label: 'Trạng thái',
            options: [
                // { label: 'Tất cả', value: '' },
                { label: 'Hoạt động', value: '1' },
                { label: 'Tạm dừng', value: '0' }
            ],
            value: currentFilters.status?.toString() || ''
        },
        {
            key: 'min_gems',
            type: 'input' as const,
            label: 'Ngọc tối thiểu',
            placeholder: 'VD: 1000',
            value: currentFilters.min_gems?.toString() || ''
        },
        {
            key: 'max_gems',
            type: 'input' as const,
            label: 'Ngọc tối đa',
            placeholder: 'VD: 100000',
            value: currentFilters.max_gems?.toString() || ''
        }
    ], [servers, currentFilters]);

    return (
        <>
            {/* Header with stats */}
            <div className="mb-6 bg-white rounded-lg shadow-sm border border-gray-200 p-6">
                <div className="flex items-center justify-between mb-4">
                    <div className="flex items-center gap-3">
                        <div className="w-12 h-12 bg-gradient-to-br from-purple-100 to-pink-100 rounded-lg flex items-center justify-center">
                            <Diamond className="w-6 h-6 text-purple-500" />
                        </div>
                        <div>
                            <h1 className="text-xl font-semibold text-gray-900">
                                Quản lý Bot Ngọc
                                {currentServer && (
                                    <span className="text-purple-600"> - {currentServer.name}</span>
                                )}
                            </h1>
                            <p className="text-sm text-gray-600">
                                Quản lý bot bán ngọc trong game Ngọc Rồng
                            </p>
                        </div>
                    </div>
                </div>

                {/* Stats */}
                <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div className="bg-blue-50 p-4 rounded-lg border border-blue-200">
                        <div className="flex items-center gap-3">
                            <Bot className="w-8 h-8 text-blue-500" />
                            <div>
                                <p className="text-sm text-blue-600 font-medium">Tổng số bot</p>
                                <p className="text-2xl font-bold text-blue-700">{stats.total_bots}</p>
                            </div>
                        </div>
                    </div>

                    <div className="bg-green-50 p-4 rounded-lg border border-green-200">
                        <div className="flex items-center gap-3">
                            <Sparkles className="w-8 h-8 text-green-500" />
                            <div>
                                <p className="text-sm text-green-600 font-medium">Bot hoạt động</p>
                                <p className="text-2xl font-bold text-green-700">{stats.active_bots}</p>
                            </div>
                        </div>
                    </div>

                    <div className="bg-purple-50 p-4 rounded-lg border border-purple-200">
                        <div className="flex items-center gap-3">
                            <Diamond className="w-8 h-8 text-purple-500" />
                            <div>
                                <p className="text-sm text-purple-600 font-medium">Tổng ngọc</p>
                                <p className="text-2xl font-bold text-purple-700">{formatNumber(stats.total_gems)}</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            {/* 🎯 DataTable cho GemBots */}
            <DataTable<IGemBot>
                data={gemBots.data}
                columns={columns}
                loading={loading}
                searchValue={currentFilters.search || ''}
                title="Danh sách Bot Ngọc"
                description="Quản lý tất cả bot bán ngọc trong hệ thống"
                pagination={{
                    current: gemBots.meta.current_page,
                    pageSize: gemBots.meta.per_page,
                    total: gemBots.meta.total,
                    showSizeChanger: true,
                    pageSizeOptions: ['10', '20', '50', '100'],
                    onChange: handlePageChange,
                }}
                onFiltersChange={setColumnFilters}
                onSearch={handleSearch}
                onAdd={handleOpenModal}
                onReset={handleResetFilters}
                onEdit={handleEdit}
                onView={handleView}
                onDelete={handleToggleStatus} // Use toggle status instead of delete
                customActions={{
                    settings: {
                        label: 'Cài đặt',
                        icon: Settings,
                        handler: handleEdit,
                        className: 'text-blue-600 hover:text-blue-800'
                    },
                    view: {
                        label: 'Xem chi tiết',
                        icon: Eye,
                        handler: handleView,
                        className: 'text-green-600 hover:text-green-800'
                    },
                    toggleStatus: {
                        label: 'Đổi trạng thái',
                        icon: TrendingUp,
                        handler: handleToggleStatus,
                        className: 'text-yellow-600 hover:text-yellow-800'
                    }
                }}
                filters={filterOptions}
                searchPlaceholder="Tìm kiếm theo tên bot, tài khoản..."
                addButtonText="Thêm Bot mới"
                emptyText="Chưa có bot nào"
                emptyDescription="Hãy thêm bot đầu tiên để bắt đầu quản lý"
            />

            {/* Modal */}
            <GemBotModal
                open={modalOpen}
                onClose={handleCloseModal}
                gemBot={selectedGemBot}
                servers={servers}
                logins={logins}
            />

            <GemBotHistoryQuickModal
                open={historyModalOpen}
                gemBot={historyGemBot}
                onClose={() => {
                    setHistoryModalOpen(false);
                    setHistoryGemBot(null);
                }}
            />
        </>
    );
}

GemBotPage.layout = (page: React.ReactNode) => (
    <AdminLayout title="Gem Bot Management" children={page} />
);
