// Admin/ServiceOrders/Components/ServiceOrdersTable.tsx
import React, { useEffect, useMemo } from 'react';
import { router } from "@inertiajs/react";
import { PaginatedData } from "@/types";
import { Column, DataTable, FilterConfig } from "@/Components/Table/DataTable"; // ✅ IMPORT FilterConfig từ DataTable
import {
    Eye, Clock, Settings, CheckCircle, XCircle,
    Pause, AlertCircle, User, Shield,
    Calendar, Truck, Copy
} from 'lucide-react';
import { useToast } from "@/Components/ToastProvider";
import { useTableFilters } from "@/Hooks/useTableFilters";
import { formatDate, formatPrice } from '@/Utils/currencyHelper';
import { Tooltip, Button } from 'antd';

interface IUser {
    id: number;
    username: string;
}

interface IService {
    id: number;
    name: string;
    processing_time: string;
    warranty: string;
}

interface IServiceOrder {
    id: number;
    service_id: number;
    service_price: number;
    account: string;
    password?: string;
    description: string;
    field_values_json: any;
    status: string;
    user: IUser;
    receiver: IUser;
    service: IService;
    created_at: string;
    updated_at: string;
}

// ✅ XÓA định nghĩa FilterConfig local, dùng từ DataTable

interface ServiceOrdersTableProps {
    data: PaginatedData<IServiceOrder>;
    filters: {
        search?: string;
        status?: string;
        account?: string;
    };
    flash?: {
        success?: string;
        error?: string;
        info?: string;
    };
    title: string;
    description: string;
    routeName: string;
    showPassword?: boolean;
    onView: (order: IServiceOrder) => void;
    customActions?: {
        [key: string]: {
            label: string;
            icon: any;
            handler: (order: IServiceOrder) => void;
            className?: string;
            condition?: (order: IServiceOrder) => boolean;
        };
    };
    filterOptions?: FilterConfig[]; // ✅ SỬ DỤNG FilterConfig TỪ DataTable
    searchPlaceholder?: string;
}

export default function ServiceOrdersTable({
    data,
    filters: serverFilters,
    flash,
    title,
    description,
    routeName,
    showPassword = false,
    onView,
    customActions = {},
    filterOptions = [],
    searchPlaceholder = "Tìm kiếm..."
}: ServiceOrdersTableProps) {
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
        routeName,
        initialFilters: serverFilters,
        initialData: data,
        debounceMs: 500,
    });

    // Status configuration
    const statusConfig = {
        pending: {
            label: 'Chờ xử lý',
            color: 'bg-orange-100 text-orange-800',
            icon: Clock,
            dot: 'bg-orange-500'
        },
        approved: {
            label: 'Đang xử lý',
            color: 'bg-blue-100 text-blue-800',
            icon: Settings,
            dot: 'bg-blue-500'
        },
        completed: {
            label: 'Hoàn thành',
            color: 'bg-green-100 text-green-800',
            icon: CheckCircle,
            dot: 'bg-green-500'
        },
        rejected: {
            label: 'Đã hủy',
            color: 'bg-red-100 text-red-800',
            icon: XCircle,
            dot: 'bg-red-500'
        },

    };

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

    const copyToClipboard = (text: string) => {
        navigator.clipboard.writeText(text).then(() => {
            toast.success('Đã sao chép!');
        });
    };

    // Render functions
    const renderOrderInfo = (order: IServiceOrder) => (
        <div className="flex items-start gap-3">
            <div className="w-12 h-12 rounded-lg bg-blue-100 flex items-center justify-center flex-shrink-0">
                <Truck className="w-6 h-6 text-blue-600" />
            </div>
            <div className="flex-1 min-w-0">
                <div className="flex items-center gap-2 mb-1">
                    <span className="font-mono text-sm font-bold text-blue-600 bg-blue-100 px-2 py-0.5 rounded">
                        #{order.id}
                    </span>
                </div>
                <div className="text-sm  mb-1 truncate">
                    Dịch vụ: {order.service?.name || 'Chưa xác định'}
                </div>
                <div className="text-sm  mb-1 truncate flex items-center gap-1">
                    <span>Tài khoản: {order.account}</span>
                    <Tooltip title="Sao chép tài khoản">
                        <Button
                            className='text-green-600'
                            type="text"
                            size="small"
                            icon={<Copy className="w-3 h-3 " />}
                            onClick={() => copyToClipboard(order.account)}
                        />
                    </Tooltip>
                </div>
                {showPassword && order.password && (
                    <div className="text-sm  mb-1 truncate flex items-center gap-1">
                        <span>Mật khẩu: ••••••••</span>
                        <Tooltip title="Sao chép mật khẩu">
                            <Button
                                className='text-green-600'
                                type="text"
                                size="small"
                                icon={<Copy className="w-3 h-3" />}
                                onClick={() => copyToClipboard(order.password || '')}
                            />
                        </Tooltip>
                    </div>
                )}
                <div className="text-sm font-medium text-green-600">
                    Giá: {formatPrice(order.service_price)}
                </div>
            </div>
        </div>
    );

    const renderServiceInfo = (service: IService) => (
        <div className="space-y-1">
            <div className="font-medium text-gray-900 truncate">
                {service?.name || 'Chưa xác định'}
            </div>
            <div className="flex items-center gap-2 text-xs text-gray-500">
                <Clock className="w-3 h-3" />
                <span>{service?.processing_time || 'N/A'}</span>
            </div>
            <div className="flex items-center gap-2 text-xs text-gray-500">
                <Shield className="w-3 h-3" />
                <span>{service?.warranty || 'N/A'}</span>
            </div>
        </div>
    );

    const renderUser = (user: IUser, isReceiver: boolean = false) => (
        <div className="flex items-center gap-2">
            <div className={`w-8 h-8 rounded-full flex items-center justify-center ${isReceiver ? 'bg-blue-100' : 'bg-green-100'
                }`}>
                <User className={`w-4 h-4 ${isReceiver ? 'text-blue-600' : 'text-green-600'
                    }`} />
            </div>
            <div>
                <div className="font-medium text-sm truncate">{user?.username || 'N/A'}</div>
                <div className="text-xs text-gray-500">ID: {user?.id}</div>
            </div>
        </div>
    );

    const renderStatus = (status: string) => {
        const config = statusConfig[status as keyof typeof statusConfig] || {
            label: status,
            color: 'bg-gray-100 text-gray-800',
            icon: AlertCircle,
            dot: 'bg-gray-500'
        };

        return (
            <span className={`inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium ${config.color}`}>
                <span className={`w-2 h-2 rounded-full mr-1.5 ${config.dot}`}></span>
                {config.label}
            </span>
        );
    };

    const renderFieldValues = (fieldValues: any) => {
        try {
            let values = typeof fieldValues === 'string' ? JSON.parse(fieldValues) : fieldValues;

            if (!values) {
                return <span className="text-gray-400 text-xs">Không có dữ liệu</span>;
            }

            if (Array.isArray(values)) {
                if (values.length === 0) {
                    return <span className="text-gray-400 text-xs">Không có dữ liệu</span>;
                }

                const displayCount = Math.min(3, values.length);
                const remainingCount = values.length - displayCount;

                return (
                    <div className="space-y-1 max-w-xs">
                        {values.slice(0, displayCount).map((item, index) => (
                            <div key={index} className="flex items-center justify-between bg-gray-50 px-2 py-1 rounded text-xs">
                                <span className="font-medium text-gray-700 truncate" title={item.label || item.key}>
                                    {(item.label || item.key).replace(':', '').trim()}:
                                </span>
                                <span className="font-mono text-gray-600 bg-blue-100 px-1 rounded ml-1 truncate" title={String(item.value)}>
                                    {String(item.value)}
                                </span>
                            </div>
                        ))}
                        {remainingCount > 0 && (
                            <div className="text-xs text-gray-500 text-center bg-gray-100 px-2 py-1 rounded">
                                +{remainingCount} trường khác
                            </div>
                        )}
                    </div>
                );
            }

            if (typeof values === 'object' && Object.keys(values).length === 0) {
                return <span className="text-gray-400 text-xs">Không có dữ liệu</span>;
            }

            const displayCount = Math.min(3, Object.keys(values).length);
            const remainingCount = Object.keys(values).length - displayCount;

            return (
                <div className="space-y-1 max-w-xs">
                    {Object.entries(values).slice(0, displayCount).map(([key, val], index) => (
                        <div key={index} className="flex items-center justify-between bg-gray-50 px-2 py-1 rounded text-xs">
                            <span className="font-medium text-gray-700 truncate" title={key}>{key}:</span>
                            <span className="font-mono text-gray-600 bg-blue-100 px-1 rounded ml-1 truncate" title={String(val)}>
                                {String(val)}
                            </span>
                        </div>
                    ))}
                    {remainingCount > 0 && (
                        <div className="text-xs text-gray-500 text-center bg-gray-100 px-2 py-1 rounded">
                            +{remainingCount} trường khác
                        </div>
                    )}
                </div>
            );
        } catch (error) {
            return (
                <span className="font-mono text-red-500 bg-red-50 px-2 py-1 rounded text-xs">
                    Dữ liệu không hợp lệ
                </span>
            );
        }
    };

    // Define columns for DataTable
    const columns: Column<IServiceOrder>[] = useMemo(() => [
        {
            key: 'id',
            title: 'ID',
            width: 60,
            visible: false,
            align: 'center',
            render: (value: any) => (
                <span className="font-mono text-blue-600 bg-blue-100 px-2 py-1 rounded text-sm font-bold">
                    #{value}
                </span>
            )
        },
        {
            key: 'order_info',
            title: 'Thông tin đơn hàng',
            width: showPassword ? 160 : 140,
            render: (_, record: IServiceOrder) => renderOrderInfo(record)
        },
        {
            key: 'service',
            title: 'Dịch vụ',
            visible: false,
            width: 150,
            render: (service: IService) => renderServiceInfo(service)
        },
        {
            key: 'user',
            title: 'Khách hàng',
            width: 60,
            render: (user: IUser) => renderUser(user)
        },
        {
            key: 'receiver',
            title: 'Người nhận',
            width: 60,
            render: (receiver: IUser) => renderUser(receiver, true)
        },
        {
            key: 'field_values_json',
            title: 'Thông tin trường',
            width: 160,
            render: (value: any) => renderFieldValues(value)
        },
        {
            key: 'status',
            title: 'Trạng thái',
            width: 80,
            align: 'center',
            filters: [
                { text: '🟠 Chờ xử lý', value: 'pending' },
                { text: '🔵 Đang xử lý', value: 'processing' },
                { text: '✅ Hoàn thành', value: 'completed' },
                { text: '❌ Đã hủy', value: 'cancelled' },
                { text: '⏸️ Tạm dừng', value: 'paused' }
            ],
            render: (status: string) => renderStatus(status)
        },
        {
            key: 'description',
            title: 'Mô tả',
            width: 150,
            visible: false,
            render: (value: string) => (
                <div className="text-sm  truncate max-w-xs" title={value}>
                    {value || 'Không có mô tả'}
                </div>
            )
        },
        {
            key: 'created_at',
            title: 'Ngày tạo',
            sortable: true,
            width: 120,
            visible: false,
            render: (value: string) => (
                <div className="flex items-center gap-1">
                    <Calendar className="w-3 h-3 " />
                    <span className="text-sm ">{formatDate(value)}</span>
                </div>
            )
        }
    ], [data, showPassword]);

    return (
        <DataTable<IServiceOrder>
            data={data.data}
            columns={columns}
            loading={loading}
            searchValue={filters.search}
            searchPreset="serviceOrders"
            title={title}
            description={description}
            pagination={{
                current: data.meta.current_page,
                pageSize: data.meta.per_page,
                total: data.meta.total,
                showSizeChanger: true,
                pageSizeOptions: ['10', '20', '50', '100'],
                onChange: handlePageChange,
            }}
            onFiltersChange={setColumnFilters}
            onSearch={handleSearch}
            onReset={handleResetFilters}
            onView={onView}
            customActions={customActions}
            filters={filterOptions}
            searchPlaceholder={searchPlaceholder}
        />
    );
}
