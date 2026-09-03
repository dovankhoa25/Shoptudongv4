// Admin/Attributes/Index.tsx - Attribute Management
import React, { useState, useMemo } from 'react';
import { router, usePage } from "@inertiajs/react";
import AdminLayout from "@/Layouts/AdminLayout";
import { IAttribute } from "@/InterFaces/attribute";
import { PageProps, PaginatedData } from "@/types";
import AttributeModal from "./AttributeModal";
import AddSelectionModal from "./AddSelectionModal";
import { Column, DataTable } from "@/Components/Table/DataTable";
import {
    Settings, TrendingUp, Plus, Tag as TagIcon,
    CheckCircle, XCircle, Edit3, Trash2
} from 'lucide-react';
import { useToast } from "@/Components/ToastProvider";
import { useTableFilters } from "@/Hooks/useTableFilters";
import { Button, Tag, Dropdown, Space } from "antd";

export default function AttributePage() {
    const { attributes, filters: serverFilters } = usePage<
        PageProps & {
            attributes: PaginatedData<IAttribute>;
            filters: { search?: string; status?: boolean };
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
        routeName: 'admin.games.attributes.index',
        initialFilters: serverFilters,
        initialData: attributes,
        debounceMs: 500,
    });

    // Modal states
    const [showModal, setShowModal] = useState(false);
    const [showSelectionModal, setShowSelectionModal] = useState(false);
    const [selectedAttribute, setSelectedAttribute] = useState<IAttribute | null>(null);
    const [modalMode, setModalMode] = useState<'attribute' | 'option'>('attribute');
    const [selectedAttributeId, setSelectedAttributeId] = useState<number | undefined>();

    // Modal handlers
    const handleOpenSelectionModal = () => {
        setShowSelectionModal(true);
    };

    const handleCloseSelectionModal = () => {
        setShowSelectionModal(false);
    };

    const handleSelectAttribute = () => {
        setSelectedAttribute(null);
        setModalMode('attribute');
        setSelectedAttributeId(undefined);
        setShowModal(true);
    };

    const handleSelectOption = () => {
        setSelectedAttribute(null);
        setModalMode('option');
        setSelectedAttributeId(undefined);
        setShowModal(true);
    };

    const handleOpenOptionModal = (attribute: IAttribute) => {
        setSelectedAttribute(attribute);
        setModalMode('option');
        setSelectedAttributeId(attribute.id);
        setShowModal(true);
    };

    const handleCloseModal = () => {
        setShowModal(false);
        setSelectedAttribute(null);
        setModalMode('attribute');
        setSelectedAttributeId(undefined);
    };

    const handleEdit = (attribute: IAttribute) => {
        setSelectedAttribute(attribute);
        setModalMode('attribute');
        setShowModal(true);
    };

    const handleDelete = (attribute: IAttribute) => {
        if (confirm(`Bạn có chắc chắn muốn xóa thuộc tính "${attribute.name}"? Tất cả options của thuộc tính này cũng sẽ bị xóa.`)) {
            router.delete(`/admin/attributes/${attribute.id}`, {
                onSuccess: () => {
                    toast.success(`Thuộc tính "${attribute.name}" đã được xóa thành công!`);
                },
                onError: () => {
                    toast.error('Xóa thuộc tính thất bại. Vui lòng thử lại!');
                }
            });
        }
    };

    // Option management handlers
    const handleToggleOptionStatus = (optionId: number, optionValue: string, currentStatus: boolean) => {
        const newStatus = !currentStatus;
        router.put(`/admin/games/attributes/options/${optionId}`, {
            status: newStatus
        }, {
            onSuccess: () => {
                toast.success(`Option "${optionValue}" đã được ${newStatus ? 'kích hoạt' : 'tạm dừng'}!`);
            },
            onError: () => {
                toast.error('Cập nhật trạng thái option thất bại. Vui lòng thử lại!');
            }
        });
    };

    const handleEditOption = (optionId: number, optionValue: string) => {
        const newValue = prompt(`Nhập giá trị mới cho option:`, optionValue);
        if (newValue && newValue.trim() && newValue.trim() !== optionValue) {
            router.put(`/admin/games/attributes/options/${optionId}`, {
                option_value: newValue.trim()
            }, {
                onSuccess: () => {
                    toast.success(`Option đã được cập nhật từ "${optionValue}" thành "${newValue.trim()}"!`);
                },
                onError: () => {
                    toast.error('Cập nhật option thất bại. Vui lòng thử lại!');
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

    const renderOptionsColumn = (options: any[], record: IAttribute) => {
        if (!options || options.length === 0) {
            return (
                <div className="space-y-2">
                    <div className="text-gray-400 text-xs">Chưa có options</div>
                    <Button
                        size="small"
                        type="link"
                        icon={<Plus className="w-3 h-3" />}
                        onClick={() => handleOpenOptionModal(record)}
                        className="text-blue-500 p-0 h-auto text-xs flex items-center gap-1"
                    >
                        Thêm
                    </Button>
                </div>
            );
        }

        const displayOptions = options.slice(0, 15);
        const remainingCount = options.length - 15;

        return (
            <div className="space-y-2">
                <div className="flex flex-wrap gap-1">
                    {displayOptions.map((option) => (
                        <div
                            key={option.id}
                            className={`px-4 py-1 rounded-full text-xs font-medium text-white relative group ${option.status
                                ? 'bg-green-500 hover:bg-green-600'
                                : 'bg-red-500 hover:bg-red-600'
                                } cursor-pointer transition-colors`}
                            onClick={() => handleToggleOptionStatus(option.id, option.value || option.option_value || '', option.status)}
                            title={`Click để ${option.status ? 'tạm dừng' : 'kích hoạt'} option "${option.value || option.option_value}"`}
                        >
                            {option.value || option.option_value}

                            {/* Edit button hiện khi hover */}
                            <div className="absolute -top-1 -right-1 opacity-0 group-hover:opacity-100 transition-opacity">
                                <Button
                                    size="small"
                                    type="text"
                                    icon={<Edit3 className="w-2 h-2" />}
                                    onClick={(e) => {
                                        e.stopPropagation();
                                        handleEditOption(option.id, option.value || option.option_value || '');
                                    }}
                                    className="w-4 h-4 bg-white text-gray-600 hover:text-blue-600 rounded-full border border-gray-200 shadow-sm"
                                    title="Sửa option"
                                />
                            </div>
                        </div>
                    ))}
                    {remainingCount > 0 && (
                        <div className="px-2 py-1 rounded-full text-xs font-medium bg-gray-400 text-white">
                            +{remainingCount}
                        </div>
                    )}
                </div>
                <Button
                    size="small"
                    type="link"
                    icon={<Plus className="w-3 h-3" />}
                    onClick={() => handleOpenOptionModal(record)}
                    className="text-blue-500 p-0 h-auto text-xs flex items-center gap-1"
                >
                    Thêm option
                </Button>
            </div>
        );
    };

    // Define columns for DataTable
    const columns: Column<IAttribute>[] = useMemo(() => [
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
            title: 'Tên thuộc tính',
            width: 200,
            sortable: true,
            render: (name: string, record: IAttribute) => (
                <div className="flex items-center gap-3">
                    <div className="w-10 h-10 bg-gradient-to-br from-green-100 to-blue-100 rounded-lg flex items-center justify-center">
                        <TagIcon className="w-5 h-5 text-green-600" />
                    </div>
                    <div>
                        <div className="font-semibold text-gray-900 dark:text-green-500">{name}</div>
                        <div className="text-sm text-gray-500">
                            {record.options?.length || 0} options
                        </div>
                    </div>
                </div>
            )
        },
        {
            key: 'options',
            title: 'Options',
            dataIndex: 'options',
            width: 300,
            render: (options: any[], record: IAttribute) => renderOptionsColumn(options, record)
        },
        {
            key: 'status',
            title: 'Trạng thái',
            width: 120,
            align: 'center',
            filters: [
                { text: '✅ Hoạt động', value: true },
                { text: '⏸️ Tạm dừng', value: false }
            ],
            render: (status: boolean) => {
                return (
                    <span className={`inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium ${status
                        ? 'bg-green-100 text-green-800'
                        : 'bg-red-100 text-red-800'
                        }`}>
                        <span className={`w-2 h-2 rounded-full mr-1.5 ${status ? 'bg-green-500' : 'bg-red-500'
                            }`}></span>
                        {status ? 'Hoạt động' : 'Tạm dừng'}
                    </span>
                );
            }
        },
        {
            key: 'options_count',
            title: 'Số options',
            width: 100,
            align: 'center',
            render: (count: number = 0) => (
                <div className="bg-blue-100 text-blue-800 text-center inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium">
                    {count} option{count !== 1 ? 's' : ''}
                </div>
            )
        },
        {
            key: 'created_at',
            title: 'Ngày tạo',
            sortable: true,
            width: 150,
            render: (value: string) => (
                <span className="text-sm text-gray-600">{formatDate(value)}</span>
            )
        }
    ], []);

    return (
        <>
            {/* 🎯 DataTable cho Attributes */}
            <DataTable<IAttribute>
                data={attributes.data}
                columns={columns}
                loading={loading}
                searchValue={filters.search}
                searchPreset="attributes"
                title="Quản lý thuộc tính"
                description="Danh sách tất cả các thuộc tính và options trong hệ thống"
                pagination={{
                    current: attributes.meta.current_page,
                    pageSize: attributes.meta.per_page,
                    total: attributes.meta.total,
                    showSizeChanger: true,
                    pageSizeOptions: ['10', '20', '50', '100'],
                    onChange: handlePageChange,
                }}
                onFiltersChange={setColumnFilters}
                onSearch={handleSearch}
                onAdd={handleOpenSelectionModal}
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
                    addOption: {
                        label: 'Thêm option',
                        icon: Plus,
                        handler: handleOpenOptionModal,
                        className: 'text-green-600 hover:text-green-800'
                    },
                }}
            // searchPlaceholder="Tìm kiếm theo tên thuộc tính..."
            // addButtonText="Thêm mới"
            // emptyText="Chưa có thuộc tính nào"
            // emptyDescription="Hãy thêm thuộc tính đầu tiên để bắt đầu quản lý các đặc điểm sản phẩm"
            />

            {/* Selection Modal */}
            <AddSelectionModal
                open={showSelectionModal}
                onClose={handleCloseSelectionModal}
                onSelectAttribute={handleSelectAttribute}
                onSelectOption={handleSelectOption}
            />

            {/* Main Modal */}
            {showModal && (
                <AttributeModal
                    onClose={handleCloseModal}
                    attribute={selectedAttribute}
                    mode={modalMode}
                    selectedAttributeId={selectedAttributeId}
                />
            )}
        </>
    );
}

AttributePage.layout = (page: React.ReactNode) => (
    <AdminLayout title="Attribute Management" children={page} />
);
