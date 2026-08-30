// Admin/CategoryTemplates/Index.tsx - Category Templates Management
import React, { useState } from 'react';
import { router, usePage } from "@inertiajs/react";
import AdminLayout from "@/Layouts/AdminLayout";
import { PageProps } from "@/types";
import TemplateFormModal from "./TemplateFormModal";
import {
    Button, Card, Tag, Input, Breadcrumb,
    Empty, Popconfirm, Badge, Collapse, List
} from "antd";
import {
    Search, Plus, Settings, Trash2, FolderOpen,
    FileText, ArrowLeft, Edit, Eye, MessageCircleQuestion,
    CheckCircle, AlertCircle, BookOpen, HelpCircle
} from 'lucide-react';
import { useToast } from "@/Components/ToastProvider";

const { Search: AntSearch } = Input;
const { Panel } = Collapse;

interface IFAQ {
    question: string;
    answer: string;
}

interface ICategoryTemplate {
    id: number;
    category_id: number;
    features: string[];
    requirements: string[];
    instructions: string[];
    faq: IFAQ[];
}

interface ICategoryWithTemplate {
    id: number;
    name: string;
    status: boolean;
    category_template?: ICategoryTemplate;
}

export default function CategoryTemplatesPage() {
    const { categories, selectedCategory: initialSelected } = usePage<
        PageProps & {
            categories: ICategoryWithTemplate[];
            selectedCategory?: ICategoryWithTemplate;
        }
    >().props;

    const [selectedCategory, setSelectedCategory] = useState<ICategoryWithTemplate | null>(
        initialSelected || null
    );
    const [showTemplateModal, setShowTemplateModal] = useState(false);
    const [searchTerm, setSearchTerm] = useState('');

    const toast = useToast();

    // Filter categories based on search
    const filteredCategories = categories.filter(category =>
        category.name.toLowerCase().includes(searchTerm.toLowerCase())
    );

    const handleCategorySelect = (category: ICategoryWithTemplate) => {
        setSelectedCategory(category);
        router.get(`/admin/category-templates?category_id=${category.id}`, {}, {
            preserveState: true,
            preserveScroll: true,
            only: ['selectedCategory']
        });
    };

    const handleCreateTemplate = () => {
        setShowTemplateModal(true);
    };

    const handleEditTemplate = () => {
        setShowTemplateModal(true);
    };

    const handleDeleteTemplate = () => {
        if (!selectedCategory) return;

        router.delete(`/admin/category-templates/destroy`, {
            data: {
                category_id: selectedCategory.id
            },
            onSuccess: () => {
                toast.success(`Đã xoá template của danh mục "${selectedCategory.name}"!`);
                handleRefreshData();
            },
            onError: () => {
                toast.error('Xoá template thất bại. Vui lòng thử lại!');
            }
        });
    };

    const handleRefreshData = () => {
        router.visit(`/admin/category-templates?category_id=${selectedCategory?.id}`, {
            method: 'get'
        });
    };

    const CategoryCard = ({ category }: { category: ICategoryWithTemplate }) => (
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
                    count={category.category_template ? 1 : 0}
                    size="small"
                    style={{ backgroundColor: category.category_template ? '#52c41a' : '#d9d9d9' }}
                />
            </div>
            <div className="mt-2 text-xs text-gray-500">
                {category.category_template ? 'Đã có template' : 'Chưa có template'}
            </div>
        </Card>
    );

    const TemplateContent = ({ category_template }: { category_template: ICategoryTemplate }) => (
        <div className="space-y-4">
            <Collapse defaultActiveKey={['features']} ghost>
                {/* Features */}
                <Panel 
                    header={
                        <div className="flex items-center gap-2">
                            <CheckCircle className="w-4 h-4 text-green-600" />
                            <span className="font-medium">Tính năng nổi bật ({category_template.features?.length || 0})</span>
                        </div>
                    } 
                    key="features"
                >
                    <List
                        size="small"
                        dataSource={category_template.features || []}
                        renderItem={(item, index) => (
                            <List.Item>
                                <div className="flex items-start gap-2">
                                    <span className="text-green-600 font-bold">•</span>
                                    <span>{item}</span>
                                </div>
                            </List.Item>
                        )}
                    />
                </Panel>

                {/* Requirements */}
                <Panel 
                    header={
                        <div className="flex items-center gap-2">
                            <AlertCircle className="w-4 h-4 text-orange-600" />
                            <span className="font-medium">Yêu cầu ({category_template.requirements?.length || 0})</span>
                        </div>
                    } 
                    key="requirements"
                >
                    <List
                        size="small"
                        dataSource={category_template.requirements || []}
                        renderItem={(item, index) => (
                            <List.Item>
                                <div className="flex items-start gap-2">
                                    <span className="text-orange-600 font-bold">•</span>
                                    <span>{item}</span>
                                </div>
                            </List.Item>
                        )}
                    />
                </Panel>

                {/* Instructions */}
                <Panel 
                    header={
                        <div className="flex items-center gap-2">
                            <BookOpen className="w-4 h-4 text-blue-600" />
                            <span className="font-medium">Hướng dẫn sử dụng ({category_template.instructions?.length || 0})</span>
                        </div>
                    } 
                    key="instructions"
                >
                    <List
                        size="small"
                        dataSource={category_template.instructions || []}
                        renderItem={(item, index) => (
                            <List.Item>
                                <div className="flex items-start gap-2">
                                    <span className="bg-blue-600 text-white rounded-full w-5 h-5 flex items-center justify-center text-xs font-bold">
                                        {index + 1}
                                    </span>
                                    <span>{item}</span>
                                </div>
                            </List.Item>
                        )}
                    />
                </Panel>

                {/* FAQ */}
                <Panel 
                    header={
                        <div className="flex items-center gap-2">
                            <HelpCircle className="w-4 h-4 text-purple-600" />
                            <span className="font-medium">Câu hỏi thường gặp ({category_template.faq?.length || 0})</span>
                        </div>
                    } 
                    key="faq"
                >
                    <div className="space-y-3">
                        {category_template.faq?.map((item, index) => (
                            <Card key={index} size="small" className="bg-gray-50">
                                <div className="space-y-2">
                                    <div className="flex items-start gap-2">
                                        <MessageCircleQuestion className="w-4 h-4 text-purple-600 mt-0.5" />
                                        <span className="font-medium text-purple-800">{item.question}</span>
                                    </div>
                                    <div className="ml-6 text-gray-700">{item.answer}</div>
                                </div>
                            </Card>
                        )) || []}
                    </div>
                </Panel>
            </Collapse>
        </div>
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
                <Breadcrumb.Item>Quản lý template danh mục</Breadcrumb.Item>
                {selectedCategory && (
                    <Breadcrumb.Item>{selectedCategory.name}</Breadcrumb.Item>
                )}
            </Breadcrumb>

            {/* Header */}
            <div className="flex items-center justify-between mb-6">
                <div>
                    <h1 className="text-2xl font-bold text-gray-900">Quản lý template danh mục</h1>
                    <p className="text-gray-600 mt-1">Tạo và quản lý template cho từng danh mục dịch vụ</p>
                </div>
                {selectedCategory && (
                    <div className="flex gap-2">
                        {selectedCategory.category_template ? (
                            <>
                                <Button
                                    type="default"
                                    icon={<Edit className="w-4 h-4" />}
                                    onClick={handleEditTemplate}
                                    size="large"
                                >
                                    Chỉnh sửa
                                </Button>
                                <Popconfirm
                                    title="Xoá template"
                                    description={`Bạn có chắc muốn xoá template của danh mục "${selectedCategory.name}"?`}
                                    onConfirm={handleDeleteTemplate}
                                    okText="Xoá"
                                    cancelText="Hủy"
                                    okButtonProps={{ danger: true }}
                                >
                                    <Button
                                        danger
                                        icon={<Trash2 className="w-4 h-4" />}
                                        size="large"
                                    >
                                        Xoá template
                                    </Button>
                                </Popconfirm>
                            </>
                        ) : (
                            <Button
                                type="primary"
                                icon={<Plus className="w-4 h-4" />}
                                onClick={handleCreateTemplate}
                                size="large"
                            >
                                Tạo template
                            </Button>
                        )}
                    </div>
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

                {/* Right Panel - Template */}
                <div className="col-span-8">
                    {selectedCategory ? (
                        <Card
                            title={
                                <div className="flex items-center justify-between">
                                    <div className="flex items-center gap-2">
                                        <FileText className="w-5 h-5 text-blue-600" />
                                        <span>Template "{selectedCategory.name}"</span>
                                        <Badge
                                            count={selectedCategory.category_template ? "Có" : "Chưa có"}
                                            style={{ 
                                                backgroundColor: selectedCategory.category_template ? '#52c41a' : '#d9d9d9',
                                                color: selectedCategory.category_template ? 'white' : '#666'
                                            }}
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
                            {selectedCategory.category_template ? (
                                <div className="max-h-96 overflow-y-auto">
                                    <TemplateContent category_template={selectedCategory.category_template} />
                                </div>
                            ) : (
                                <Empty
                                    description={
                                        <div className="text-center">
                                            <div>Danh mục này chưa có template</div>
                                            <Button
                                                type="primary"
                                                icon={<Plus className="w-4 h-4" />}
                                                onClick={handleCreateTemplate}
                                                className="mt-3"
                                            >
                                                Tạo template đầu tiên
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
                                description="Chọn một danh mục để xem template"
                                image={Empty.PRESENTED_IMAGE_SIMPLE}
                            />
                        </Card>
                    )}
                </div>
            </div>

            {/* Template Modal */}
            {showTemplateModal && selectedCategory && (
                <TemplateFormModal
                    open={showTemplateModal}
                    onClose={() => setShowTemplateModal(false)}
                    category={selectedCategory}
                    onSuccess={handleRefreshData}
                />
            )}
        </div>
    );
}

CategoryTemplatesPage.layout = (page: React.ReactNode) => (
    <AdminLayout title="Category Templates Management" children={page} />
);
