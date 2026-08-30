// Admin/CategoryAttributes/Index.tsx - Category Attributes Management
import React, { useState } from 'react';
import { router, usePage } from "@inertiajs/react";
import AdminLayout from "@/Layouts/AdminLayout";
import { ICategoryWithAttributes } from "@/InterFaces/categoryAttribute";
import { IAttribute } from "@/InterFaces/attribute";
import { PageProps } from "@/types";
import AssignAttributesModal from "./AssignAttributesModal";
import {
    Button, Card, Tag, Input, Breadcrumb,
    Empty, Popconfirm, Badge
} from "antd";
import {
    Search, Plus, Settings, Trash2, Tag as TagIcon,
    FolderOpen, ArrowLeft, Link2, Unlink
} from 'lucide-react';
import { useToast } from "@/Components/ToastProvider";

const { Search: AntSearch } = Input;

export default function CategoryAttributesPage() {
    const { categories, selectedCategory: initialSelected } = usePage<
        PageProps & {
            categories: ICategoryWithAttributes[];
            selectedCategory?: ICategoryWithAttributes;
        }
    >().props;

    const [selectedCategory, setSelectedCategory] = useState<ICategoryWithAttributes | null>(
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

    const handleCategorySelect = (category: ICategoryWithAttributes) => {
        setSelectedCategory(category);
        router.get(`/admin/games/category-attributes?category=${category.id}`, {}, {
            preserveState: true,
            preserveScroll: true,
            only: ['selectedCategory']
        });
    };

    const handleAssignAttributes = () => {
        setShowAssignModal(true);
    };

    const handleRemoveAttribute = (attributeId: number, attributeName: string) => {
        if (!selectedCategory) return;

        router.delete(`/admin/games/category-attributes/remove`, {
            data: {
                category_id: selectedCategory.id,
                attribute_id: attributeId
            },
            onSuccess: () => {
                toast.success(`Đã gỡ thuộc tính "${attributeName}" khỏi danh mục "${selectedCategory.name}"!`);
                // Refresh selected category data
                // handleCategorySelect(selectedCategory);
                handleRefreshData();
            },
            onError: () => {
                toast.error('Gỡ thuộc tính thất bại. Vui lòng thử lại!');
            }
        });
    };

    const handleRefreshData = () => {
        router.visit(`/admin/games/category-attributes?category=${selectedCategory?.id}`, {
            method: 'get'
        });
    };

    const CategoryCard = ({ category }: { category: ICategoryWithAttributes }) => (
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
                </div>
                <Badge
                    count={category.attributes_count || 0}
                    size="small"
                    style={{ backgroundColor: '#52c41a' }}
                />
            </div>
            <div className="mt-2 text-xs text-gray-500">
                {category.attributes_count || 0} thuộc tính
            </div>
        </Card>
    );

    const AttributeItem = ({ attribute }: { attribute: IAttribute }) => (
        <Card size="small" className="mb-3">
            <div className="flex items-start justify-between">
                <div className="flex-1">
                    <div className="flex items-center gap-2 mb-2">
                        <TagIcon className="w-4 h-4 text-green-600" />
                        <span className="font-medium text-gray-900">{attribute.name}</span>
                        <Tag color={attribute.status ? 'green' : 'red'} >
                            {attribute.status ? 'Hoạt động' : 'Tạm dừng'}
                        </Tag>
                    </div>

                    {/* Options display */}
                    {attribute.options && attribute.options.length > 0 && (
                        <div className="flex flex-wrap gap-1">
                            {attribute.options.slice(0, 8).map((option) => (
                                <Tag
                                    key={option.id}
                                    color={option.status ? 'blue' : 'red'}
                                >
                                    {option.option_value || option.option_value}
                                </Tag>
                            ))}
                            {attribute.options.length > 8 && (
                                <Tag color="default">
                                    +{attribute.options.length - 8}
                                </Tag>
                            )}
                        </div>
                    )}

                    {(!attribute.options || attribute.options.length === 0) && (
                        <div className="text-xs text-gray-400">Chưa có options</div>
                    )}
                </div>

                <Popconfirm
                    title="Gỡ thuộc tính"
                    description={`Bạn có chắc muốn gỡ thuộc tính "${attribute.name}" khỏi danh mục này?`}
                    onConfirm={() => handleRemoveAttribute(attribute.id, attribute.name)}
                    okText="Gỡ"
                    cancelText="Hủy"
                    okButtonProps={{ danger: true }}
                >
                    <Button
                        size="small"
                        type="text"
                        danger
                        icon={<Unlink className="w-3 h-3" />}
                        title="Gỡ thuộc tính"
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
                <Breadcrumb.Item>Quản lý thuộc tính</Breadcrumb.Item>
                {selectedCategory && (
                    <Breadcrumb.Item>{selectedCategory.name}</Breadcrumb.Item>
                )}
            </Breadcrumb>

            {/* Header */}
            <div className="flex items-center justify-between mb-6">
                <div>
                    <h1 className="text-2xl font-bold text-gray-900">Quản lý thuộc tính danh mục</h1>
                    <p className="text-gray-600 mt-1">Gán và quản lý thuộc tính cho từng danh mục</p>
                </div>
                {selectedCategory && (
                    <Button
                        type="primary"
                        icon={<Plus className="w-4 h-4" />}
                        onClick={handleAssignAttributes}
                        size="large"
                    >
                        Gán thuộc tính
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

                {/* Right Panel - Attributes */}
                <div className="col-span-8">
                    {selectedCategory ? (
                        <Card
                            title={
                                <div className="flex items-center justify-between">
                                    <div className="flex items-center gap-2">
                                        <Link2 className="w-5 h-5 text-blue-600" />
                                        <span>Thuộc tính của "{selectedCategory.name}"</span>
                                        <Badge
                                            count={selectedCategory.attributes?.length || 0}
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
                            {selectedCategory.attributes && selectedCategory.attributes.length > 0 ? (
                                <div className="space-y-3 max-h-96 overflow-y-auto">
                                    {selectedCategory.attributes.map((attribute) => (
                                        <AttributeItem key={attribute.id} attribute={attribute} />
                                    ))}
                                </div>
                            ) : (
                                <Empty
                                    description={
                                        <div className="text-center">
                                            <div>Danh mục này chưa có thuộc tính nào</div>
                                            <Button
                                                type="primary"
                                                icon={<Plus className="w-4 h-4" />}
                                                onClick={handleAssignAttributes}
                                                className="mt-3"
                                            >
                                                Gán thuộc tính đầu tiên
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
                                description="Chọn một danh mục để xem thuộc tính"
                                image={Empty.PRESENTED_IMAGE_SIMPLE}
                            />
                        </Card>
                    )}
                </div>
            </div>

            {/* Assign Modal */}
            {showAssignModal && selectedCategory && (
                <AssignAttributesModal
                    open={showAssignModal}
                    onClose={() => setShowAssignModal(false)}
                    category={selectedCategory}
                    onSuccess={handleRefreshData}
                />
            )}
        </div>
    );
}

CategoryAttributesPage.layout = (page: React.ReactNode) => (
    <AdminLayout title="Category Attributes Management" children={page} />
);
