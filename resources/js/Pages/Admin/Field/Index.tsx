// Admin/Field/Index.tsx - Field Management
import React, { useState, useMemo } from 'react';
import { router, usePage } from "@inertiajs/react";
import AdminLayout from "@/Layouts/AdminLayout";
import { IField } from "@/InterFaces/field";
import { PageProps, PaginatedData } from "@/types";
import FieldModal from "./FieldModal";
import { Column, DataTable } from "@/Components/Table/DataTable";
import {
    Settings, Type, Key, CheckCircle, XCircle,
    Calendar, Clock, Hash, Tag, FileText,
    AlignLeft, Hash as NumberIcon, List
} from 'lucide-react';
import { useToast } from "@/Components/ToastProvider";
import { useTableFilters } from "@/Hooks/useTableFilters";

export default function FieldPage() {
    const { fields, filters: serverFilters } = usePage<
        PageProps & {
            fields: PaginatedData<IField>;
            filters: { search?: string; type?: string; required?: string; per_page?: number };
        }
    >().props;

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
        routeName: 'admin.fields.index',
        initialFilters: serverFilters,
        initialData: fields,
        debounceMs: 500,
    });

    // Modal states
    const [modalOpen, setModalOpen] = useState(false);
    const [selectedField, setSelectedField] = useState<IField | null>(null);

    // Modal handlers
    const handleOpenModal = () => {
        setSelectedField(null);
        setModalOpen(true);
    };

    const handleCloseModal = () => {
        setModalOpen(false);
        setSelectedField(null);
    };

    const handleEdit = (field: IField) => {
        setSelectedField(field);
        setModalOpen(true);
    };

    const handleDelete = (field: IField) => {
        if (confirm(`Bạn có chắc chắn muốn xóa trường "${field.label}"?`)) {
            router.delete(`/admin/fields/${field.id}`, {
                onSuccess: () => {
                    toast.success(`Trường "${field.label}" đã được xóa thành công!`);
                },
                onError: () => {
                    toast.error('Xóa trường thất bại. Vui lòng thử lại!');
                }
            });
        }
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

    const renderFieldTypeIcon = (type: string) => {
        const typeConfig = {
            text: { icon: Type, color: 'text-blue-600', bg: 'bg-blue-100' },
            textarea: { icon: AlignLeft, color: 'text-green-600', bg: 'bg-green-100' },
            number: { icon: NumberIcon, color: 'text-purple-600', bg: 'bg-purple-100' },
            select: { icon: List, color: 'text-orange-600', bg: 'bg-orange-100' }
        };

        const config = typeConfig[type as keyof typeof typeConfig] || typeConfig.text;
        const IconComponent = config.icon;

        return (
            <div className={`w-8 h-8 rounded-lg ${config.bg} flex items-center justify-center`}>
                <IconComponent className={`w-4 h-4 ${config.color}`} />
            </div>
        );
    };

    const renderFieldType = (type: string) => {
        const typeConfig = {
            text: {
                label: 'Text',
                color: 'bg-blue-100 text-blue-800',
                icon: '📝'
            },
            textarea: {
                label: 'Textarea',
                color: 'bg-green-100 text-green-800',
                icon: '📄'
            },
            number: {
                label: 'Number',
                color: 'bg-purple-100 text-purple-800',
                icon: '🔢'
            },
            select: {
                label: 'Select',
                color: 'bg-orange-100 text-orange-800',
                icon: '📋'
            }
        };

        const config = typeConfig[type as keyof typeof typeConfig] || typeConfig.text;

        return (
            <span className={`inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium ${config.color}`}>
                {config.icon} {config.label}
            </span>
        );
    };

    const renderOptions = (options: any, type: string) => {
        if (!options || type !== 'select') {
            return (
                <span className="text-gray-400 text-sm">-</span>
            );
        }

        try {
            const parsedOptions = typeof options === 'string' ? JSON.parse(options) : options;

            if (Array.isArray(parsedOptions) && parsedOptions.length > 0) {
                return (
                    <div className="flex flex-wrap gap-1">
                        {parsedOptions.slice(0, 2).map((option, index) => (
                            <span
                                key={index}
                                className="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-gray-100 text-gray-600"
                            >
                                {typeof option === 'object' ? option.label || option.value : option}
                            </span>
                        ))}
                        {parsedOptions.length > 2 && (
                            <span className="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-gray-100 text-gray-600">
                                +{parsedOptions.length - 2}
                            </span>
                        )}
                    </div>
                );
            }
        } catch (e) {
            console.error('Error parsing options:', e);
        }

        return (
            <span className="text-gray-400 text-sm">Không hợp lệ</span>
        );
    };

    const renderServices = (services: any[]) => {
        if (!services || services.length === 0) {
            return (
                <span className="text-gray-400 text-sm">Chưa có dịch vụ</span>
            );
        }

        return (
            <div className="flex flex-wrap gap-1">
                {services.slice(0, 2).map((service, index) => (
                    <span
                        key={index}
                        className="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-blue-100 text-blue-800"
                    >
                        <Tag className="w-3 h-3 mr-1" />
                        {service.name}
                    </span>
                ))}
                {services.length > 2 && (
                    <span className="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-gray-100 text-gray-600">
                        +{services.length - 2}
                    </span>
                )}
            </div>
        );
    };

    // Define columns for DataTable
    const columns: Column<IField>[] = useMemo(() => [
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
            key: 'label',
            title: 'Nhãn trường',
            width: 250,
            sortable: true,
            render: (label: string, record: IField) => (
                <div className="flex items-center gap-3">
                    {renderFieldTypeIcon(record.type)}
                    <div>
                        <div className="font-semibold ">{label}</div>
                        <div className="text-sm text-gray-500 flex items-center gap-1">
                            <Key className="w-3 h-3" />
                            <span className="font-mono">{record.field_key}</span>
                        </div>
                    </div>
                </div>
            )
        },
        {
            key: 'type',
            title: 'Loại trường',
            width: 120,
            filters: [
                { text: '📝 Text', value: 'text' },
                { text: '📄 Textarea', value: 'textarea' },
                { text: '🔢 Number', value: 'number' },
                { text: '📋 Select', value: 'select' }
            ],
            render: (type: string) => renderFieldType(type)
        },
        {
            key: 'options',
            title: 'Tùy chọn',
            width: 200,
            render: (options: any, record: IField) => renderOptions(options, record.type)
        },
        {
            key: 'required',
            title: 'Bắt buộc',
            width: 100,
            align: 'center',
            filters: [
                { text: '✅ Bắt buộc', value: true },
                { text: '⭕ Tùy chọn', value: false }
            ],
            render: (required: boolean) => {
                return (
                    <span className={`inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium ${required
                            ? 'bg-red-100 text-red-800'
                            : 'bg-gray-100 text-gray-600'
                        }`}>
                        <span className={`w-2 h-2 rounded-full mr-1.5 ${required ? 'bg-red-500' : 'bg-gray-400'
                            }`}></span>
                        {required ? 'Bắt buộc' : 'Tùy chọn'}
                    </span>
                );
            },
        },
        {
            key: 'services',
            title: 'Dịch vụ sử dụng',
            width: 200,
            render: (services: any[]) => renderServices(services)
        },
        {
            key: 'created_at',
            title: 'Ngày tạo',
            sortable: true,
            width: 140,
            render: (value: string) => (
                <span className="text-sm text-gray-600 flex items-center gap-1">
                    <Calendar className="w-3 h-3" />
                    {formatDate(value)}
                </span>
            )
        },
        {
            key: 'updated_at',
            title: 'Cập nhật',
            sortable: true,
            visible: false,
            width: 140,
            render: (value: string) => (
                <span className="text-sm text-gray-600 flex items-center gap-1">
                    <Clock className="w-3 h-3" />
                    {formatDate(value)}
                </span>
            )
        }
    ], []);

    return (
        <>
            {/* 🎯 DataTable cho Fields */}
            <DataTable<IField>
                data={fields.data}
                columns={columns}
                loading={loading}
                searchValue={filters.search}
                title="Quản lý trường dữ liệu"
                description="Danh sách tất cả các trường dữ liệu trong hệ thống"
                pagination={{
                    current: fields.meta.current_page,
                    pageSize: fields.meta.per_page,
                    total: fields.meta.total,
                    showSizeChanger: true,
                    pageSizeOptions: ['10', '20', '50', '100'],
                    onChange: handlePageChange,
                }}
                onFiltersChange={setColumnFilters}
                onSearch={handleSearch}
                onAdd={handleOpenModal}
                onReset={handleResetFilters}
                onEdit={handleEdit}
                onDelete={handleDelete}
                customActions={{
                    settings: {
                        label: 'Cài đặt',
                        icon: Settings,
                        handler: handleEdit,
                        className: 'text-blue-600 hover:text-blue-800'
                    },
                }}

            />

            {/* Modal */}
            <FieldModal
                open={modalOpen}
                onClose={handleCloseModal}
                field={selectedField}
            />
        </>
    );
}

FieldPage.layout = (page: React.ReactNode) => (
    <AdminLayout title="Field Management" children={page} />
);
