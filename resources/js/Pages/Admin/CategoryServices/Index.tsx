// Admin/CategoryServices/Index.tsx - Category Services Management
import React, { useState } from 'react';
import { router, usePage } from "@inertiajs/react";
import AdminLayout from "@/Layouts/AdminLayout";
import { IService } from "@/InterFaces/service";
import { ICategory } from "@/InterFaces/category";
import { PageProps } from "@/types";
import AssignServicesModal from "./AssignServicesModal";
import {
    Button, Card, Tag, Input, Breadcrumb,
    Empty, Popconfirm, Badge
} from "antd";
import {
    Search, Plus, Settings, Trash2, FolderOpen,
    Wrench, ArrowLeft, Link2, Unlink, Hash
} from 'lucide-react';
import { useToast } from "@/Components/ToastProvider";

const { Search: AntSearch } = Input;

interface ICategoryWithServices extends ICategory {
    services?: IService[];
}

export default function CategoryServicesPage() {
    const { categories, selectedCategory: initialSelected, allServices } = usePage<
        PageProps & {
            categories: ICategoryWithServices[];
            selectedCategory?: ICategoryWithServices;
            allServices: IService[];
        }
    >().props;

    const [selectedCategory, setSelectedCategory] = useState<ICategoryWithServices | null>(
        initialSelected || null
    );
    const [showAssignModal, setShowAssignModal] = useState(false);
    const [searchTerm, setSearchTerm] = useState('');
    const [loading, setLoading] = useState(false);

    const toast = useToast();

    // Filter categories based on search
    const filteredCategories = categories.filter(category =>
        category.name.toLowerCase().includes(searchTerm.toLowerCase())
    );

    const handleCategorySelect = (category: ICategoryWithServices) => {
        setSelectedCategory(category);
        router.get(`/admin/category-services?category=${category.id}`, {}, {
            preserveState: true,
            preserveScroll: true,
            only: ['selectedCategory']
        });
    };

    const handleAssignServices = () => {
        setShowAssignModal(true);
    };

    const handleRemoveService = (serviceId: number, serviceName: string) => {
        if (!selectedCategory) return;

        router.delete(`/admin/category-services/remove`, {
            data: {
                category_id: selectedCategory.id,
                service_id: serviceId
            },
            onSuccess: () => {
                toast.success(`Đã gỡ dịch vụ "${serviceName}" khỏi danh mục "${selectedCategory.name}"!`);
                handleRefreshData();
            },
            onError: () => {
                toast.error('Gỡ dịch vụ thất bại. Vui lòng thử lại!');
            }
        });
    };

    const handleRefreshData = () => {
        router.visit(`/admin/category-services?category=${selectedCategory?.id}`, {
            method: 'get'
        });
    };

    const renderServiceIcon = () => {
        return (
            <div className="w-8 h-8 rounded-lg bg-blue-100 flex items-center justify-center">
                <Wrench className="w-4 h-4 text-blue-600" />
            </div>
        );
    };

    const CategoryCard = ({ category }: { category: ICategoryWithServices }) => (
        <Card
            size="small"
            className={`cursor-pointer transition-all duration-200 ${selectedCategory?.id === category.id
                ? 'border-blue-500 shadow-md bg-blue-50'
                : 'hover:border-gray-400 hover:shadow-sm'
                }`}
            onClick={() => handleCategorySelect(category)}
        >
            <div className="flex items-center justify-between">
                <div className="flex items-center gap-2">
                    <FolderOpen className={`w-4 h-4 ${selectedCategory?.id === category.id ? 'text-blue-600' : 'text-gray-500'
                        }`} />
                    <span className={`font-medium ${selectedCategory?.id === category.id ? 'text-blue-900' : 'text-gray-900'
                        }`}>
                        {category.name}
                    </span>
                    <Tag color={category.status ? 'green' : 'red'}>
                        {category.status ? 'Hoạt động' : 'Tạm dừng'}
                    </Tag>
                </div>
                <Badge
                    count={category.services?.length || 0}
                    size="small"
                    style={{ backgroundColor: '#52c41a' }}
                />
            </div>
            <div className="mt-2 text-xs text-gray-500">
                {category.services?.length || 0} dịch vụ
            </div>
        </Card>
    );

    const ServiceItem = ({ service }: { service: IService }) => (
        <Card size="small" className="mb-3">
            <div className="flex items-start justify-between">
                <div className="flex-1">
                    <div className="flex items-center gap-2 mb-2">
                        {renderServiceIcon()}
                        <span className="font-medium text-gray-900">{service.name}</span>
                        <Tag color={service.status ? 'green' : 'red'}>
                            {service.status ? 'Hoạt động' : 'Tạm dừng'}
                        </Tag>
                    </div>

                    <div className="flex items-center gap-1 text-sm text-gray-500">
                        <Hash className="w-3 h-3" />
                        <span>ID: {service.id}</span>
                    </div>
                </div>

                <Popconfirm
                    title="Gỡ dịch vụ"
                    description={`Bạn có chắc muốn gỡ dịch vụ "${service.name}" khỏi danh mục này?`}
                    onConfirm={() => handleRemoveService(service.id, service.name)}
                    okText="Gỡ"
                    cancelText="Hủy"
                    okButtonProps={{ danger: true }}
                >
                    <Button
                        size="small"
                        type="text"
                        danger
                        icon={<Unlink className="w-3 h-3" />}
                        title="Gỡ dịch vụ"
                    />
                </Popconfirm>
            </div>
        </Card>
    );

    return (
        <div className="p-6">
            {/* Breadcrumb */}
            <Breadcrumb className="mb-4">
                <Breadcrumb.Item>
                    <Button
                        type="link"
                        icon={<ArrowLeft className="w-4 h-4" />}
                        onClick={() => router.visit('/admin/games/categories')}
                        className="p-0"
                    >
                        Danh mục
                    </Button>
                </Breadcrumb.Item>
                <Breadcrumb.Item>Quản lý dịch vụ danh mục</Breadcrumb.Item>
                {selectedCategory && (
                    <Breadcrumb.Item>{selectedCategory.name}</Breadcrumb.Item>
                )}
            </Breadcrumb>

            {/* Header */}
            <div className="flex items-center justify-between mb-6">
                <div>
                    <h1 className="text-2xl font-bold text-gray-900">Quản lý dịch vụ danh mục</h1>
                    <p className="text-gray-600 mt-1">Gán và quản lý dịch vụ cho từng danh mục</p>
                </div>
                {selectedCategory && (
                    <Button
                        type="primary"
                        icon={<Plus className="w-4 h-4" />}
                        onClick={handleAssignServices}
                        size="large"
                    >
                        Gán dịch vụ
                    </Button>
                )}
            </div>

            <div className="grid grid-cols-12 gap-6">
                {/* Left Panel - Categories */}
                <div className="col-span-4">
                    <Card title="Danh sách danh mục" className="h-full">
                        <div className="mb-4">
                            <AntSearch
                                placeholder="Tìm kiếm danh mục..."
                                value={searchTerm}
                                onChange={(e) => setSearchTerm(e.target.value)}
                                allowClear
                            />
                        </div>

                        <div className="space-y-2 max-h-96 overflow-y-auto">
                            {filteredCategories.map((category) => (
                                <CategoryCard key={category.id} category={category} />
                            ))}

                            {filteredCategories.length === 0 && (
                                <Empty
                                    description="Không tìm thấy danh mục nào"
                                    image={Empty.PRESENTED_IMAGE_SIMPLE}
                                />
                            )}
                        </div>
                    </Card>
                </div>

                {/* Right Panel - Services */}
                <div className="col-span-8">
                    {selectedCategory ? (
                        <Card
                            title={
                                <div className="flex items-center justify-between">
                                    <div className="flex items-center gap-2">
                                        <Link2 className="w-5 h-5 text-blue-600" />
                                        <span>Dịch vụ của "{selectedCategory.name}"</span>
                                        <Badge
                                            count={selectedCategory.services?.length || 0}
                                            style={{ backgroundColor: '#1890ff' }}
                                        />
                                    </div>
                                    <Button
                                        type="text"
                                        icon={<Settings className="w-4 h-4" />}
                                        onClick={handleRefreshData}
                                        title="Làm mới"
                                    />
                                </div>
                            }
                            className="h-full"
                        >
                            {selectedCategory.services && selectedCategory.services.length > 0 ? (
                                <div className="space-y-3 max-h-96 overflow-y-auto">
                                    {selectedCategory.services.map((service) => (
                                        <ServiceItem key={service.id} service={service} />
                                    ))}
                                </div>
                            ) : (
                                <Empty
                                    description={
                                        <div className="text-center">
                                            <div>Danh mục này chưa có dịch vụ nào</div>
                                            <Button
                                                type="primary"
                                                icon={<Plus className="w-4 h-4" />}
                                                onClick={handleAssignServices}
                                                className="mt-3"
                                            >
                                                Gán dịch vụ đầu tiên
                                            </Button>
                                        </div>
                                    }
                                    image={Empty.PRESENTED_IMAGE_SIMPLE}
                                />
                            )}
                        </Card>
                    ) : (
                        <Card className="h-full flex items-center justify-center">
                            <Empty
                                description="Chọn một danh mục để xem dịch vụ"
                                image={Empty.PRESENTED_IMAGE_SIMPLE}
                            />
                        </Card>
                    )}
                </div>
            </div>

            {/* Assign Modal */}
            {showAssignModal && selectedCategory && (
                <AssignServicesModal
                    open={showAssignModal}
                    onClose={() => setShowAssignModal(false)}
                    category={selectedCategory}
                    allServices={allServices}
                    onSuccess={handleRefreshData}
                />
            )}
        </div>
    );
}

CategoryServicesPage.layout = (page: React.ReactNode) => (
    <AdminLayout title="Category Services Management" children={page} />
);
