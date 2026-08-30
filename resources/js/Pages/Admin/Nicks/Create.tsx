// Admin/Nicks/Create.tsx - FULLY OPTIMIZED (NO FLICKER)
import React, { useState, useEffect, useCallback, memo } from 'react';
import { router, usePage } from "@inertiajs/react";
import AdminLayout from "@/Layouts/AdminLayout";
import { ICategory } from "@/InterFaces/category";
import { IAttribute } from "@/InterFaces/attribute";
import { INickAttributeCache } from "@/InterFaces/nick";
import { PageProps } from "@/types";
import { useToast } from "@/Components/ToastProvider";
import {
    Card, Form, Input, Select, InputNumber, Button,
    Breadcrumb, Radio, Space, Divider, Tag, Alert,
    message, Drawer
} from "antd";
import {
    ArrowLeft, Save, Crown, DollarSign,
    FolderOpen, Tag as TagIcon, Upload, User, ImageIcon
} from "lucide-react";
import axios from "axios";
import { UploadFull } from '@/Components/Upload/CustomUpload';

const { TextArea } = Input;

// ✅ CRITICAL: Extract ImageSection as separate memoized component OUTSIDE
interface ImageSectionProps {
    imageMode: 'upload' | 'url';
    images: File[] | null;
    imageUrls: string;
    selectedAttributes: { [key: number]: number };
    categoryAttributes: IAttribute[];
    onImageModeChange: (e: any) => void;
    onImagesChange: (files: File[] | null) => void;
    onImageUrlsChange: (e: React.ChangeEvent<HTMLTextAreaElement>) => void;
}

const ImageSection = memo<ImageSectionProps>(({
    imageMode,
    images,
    imageUrls,
    selectedAttributes,
    categoryAttributes,
    onImageModeChange,
    onImagesChange,
    onImageUrlsChange
}) => {
    return (
        <div className="space-y-4">
            {/* Image Mode Selection */}
            <Form.Item label="Chọn cách thêm ảnh">
                <Radio.Group
                    value={imageMode}
                    onChange={onImageModeChange}
                    className="w-full"
                >
                    <Space direction="vertical" className="w-full">
                        <Radio value="upload" className="w-full">
                            <span className="flex items-center gap-2">
                                <Upload className="w-4 h-4 text-blue-500 flex-shrink-0" />
                                <span className="text-sm">Upload ảnh từ máy</span>
                            </span>
                        </Radio>
                        <Radio value="url" className="w-full">
                            <span className="flex items-center gap-2">
                                <span className="flex-shrink-0">🔗</span>
                                <span className="text-sm">Nhập URL ảnh</span>
                            </span>
                        </Radio>
                    </Space>
                </Radio.Group>
            </Form.Item>

            {/* Upload Mode */}
            {imageMode === 'upload' && (
                <div className="space-y-3">
                    <UploadFull
                        value={images}
                        onChange={onImagesChange}
                        maxCount={100}
                        maxSize={5}
                    />

                    {images && images.length > 0 && (
                        <div className="p-3 bg-blue-50 rounded-lg border border-blue-200">
                            <div className="text-sm text-blue-700 font-medium">
                                📸 Đã chọn {images.length} ảnh
                            </div>
                        </div>
                    )}
                </div>
            )}

            {/* URL Mode */}
            {imageMode === 'url' && (
                <div className="space-y-3">
                    <Form.Item
                        label="Danh sách URL ảnh"
                        help="Mỗi URL một dòng"
                        className="mb-0"
                    >
                        <TextArea
                            value={imageUrls}
                            onChange={onImageUrlsChange}
                            placeholder={`https://example.com/image1.jpg\nhttps://example.com/image2.png`}
                            rows={6}
                            showCount
                            maxLength={2000}
                        />
                    </Form.Item>

                    {imageUrls.trim() && (
                        <>
                            <div className="p-3 bg-green-50 rounded-lg border border-green-200">
                                <div className="text-sm text-green-700 font-medium">
                                    🔗 {imageUrls.split('\n').filter(url => url.trim()).length} URL đã nhập
                                </div>
                            </div>

                            {/* Preview URLs */}
                            <div className="p-3 bg-white rounded-lg border border-gray-200">
                                <p className="text-sm font-medium text-gray-700 mb-3">Preview URLs:</p>

                                <div className="grid grid-cols-1 sm:grid-cols-2 gap-2 max-h-64 overflow-y-auto">
                                    {imageUrls.split('\n')
                                        .map(url => url.trim())
                                        .filter(url => url.length > 0)
                                        .slice(0, 100)
                                        .map((url, index) => (
                                            <div
                                                key={index}
                                                className="relative w-full h-24 sm:h-20 rounded-lg overflow-hidden border border-gray-200 bg-gray-100"
                                            >
                                                <img
                                                    src={url}
                                                    alt={`Preview ${index + 1}`}
                                                    className="w-full h-full object-cover"
                                                    onError={(e) => {
                                                        const target = e.currentTarget;
                                                        const parent = target.parentElement!;
                                                        parent.innerHTML = `
                                                            <div class="w-full h-full bg-red-50 border border-red-200 rounded-lg flex flex-col items-center justify-center p-2">
                                                                <span class="text-red-400 text-xs mb-1">❌</span>
                                                                <span class="text-red-400 text-xs text-center">Lỗi ảnh</span>
                                                            </div>
                                                        `;
                                                    }}
                                                    onLoad={(e) => {
                                                        const target = e.currentTarget;
                                                        target.style.border = '2px solid #10b981';
                                                        setTimeout(() => {
                                                            if (target.style) {
                                                                target.style.border = '';
                                                            }
                                                        }, 1000);
                                                    }}
                                                />
                                                <div className="absolute top-1 left-1 bg-black bg-opacity-60 text-white text-xs px-1.5 py-0.5 rounded">
                                                    #{index + 1}
                                                </div>
                                                {index === 0 && (
                                                    <div className="absolute top-1 right-1 bg-blue-500 text-white text-xs px-1.5 py-0.5 rounded">
                                                        Main
                                                    </div>
                                                )}
                                            </div>
                                        ))
                                    }
                                </div>

                                <div className="mt-3 text-xs text-gray-500 space-y-1">
                                    <p className="font-medium">💡 Mẹo:</p>
                                    <p>• Viền xanh = Load thành công</p>
                                    <p>• Khung đỏ = URL lỗi</p>
                                </div>
                            </div>
                        </>
                    )}
                </div>
            )}

            {/* Guidelines */}
            <div className="text-sm text-gray-500 bg-gray-50 p-3 rounded-lg">
                <p className="font-medium mb-2">Lưu ý:</p>
                <ul className="space-y-1 text-xs">
                    {imageMode === 'upload' ? (
                        <>
                            <li>• Hỗ trợ: JPG, PNG, GIF</li>
                            <li>• Kích thước tối đa: 5MB/ảnh</li>
                            <li>• Tối đa 10 ảnh</li>
                        </>
                    ) : (
                        <>
                            <li>• Chỉ URL ảnh hợp lệ</li>
                            <li>• Mỗi URL một dòng</li>
                            <li>• Tối đa 10 URL</li>
                        </>
                    )}
                    <li>• Ảnh đầu tiên là ảnh đại diện</li>
                </ul>
            </div>

            {/* Selected Attributes Preview */}
            {Object.keys(selectedAttributes).length > 0 && (
                <div className="p-3 bg-gray-50 rounded-lg border border-gray-200">
                    <div className="font-medium text-gray-700 mb-2 text-sm">
                        Thuộc tính đã chọn:
                    </div>
                    <div className="space-y-1.5">
                        {Object.entries(selectedAttributes).map(([attributeId, optionId]) => {
                            const attribute = categoryAttributes.find(attr => attr.id === parseInt(attributeId));
                            const option = attribute?.options?.find(opt => opt.id === optionId);

                            return (
                                <div key={attributeId} className="text-xs flex items-start gap-1">
                                    <span className="font-medium text-gray-600 flex-shrink-0">
                                        {attribute?.name}:
                                    </span>
                                    <span className="text-gray-800">
                                        {option?.option_value || option?.option_value}
                                    </span>
                                </div>
                            );
                        })}
                    </div>
                </div>
            )}
        </div>
    );
});

ImageSection.displayName = 'ImageSection';

export default function NickCreatePage() {
    const { categories } = usePage<
        PageProps & {
            categories: ICategory[];
        }
    >().props;

    const [form] = Form.useForm();
    const [loading, setLoading] = useState(false);
    const [selectedCategory, setSelectedCategory] = useState<ICategory | null>(null);
    const [categoryAttributes, setCategoryAttributes] = useState<IAttribute[]>([]);
    const [loadingAttributes, setLoadingAttributes] = useState(false);
    const [selectedAttributes, setSelectedAttributes] = useState<{ [key: number]: number }>({});

    const [images, setImages] = useState<File[] | null>(null);
    const [imageMode, setImageMode] = useState<'upload' | 'url'>('upload');
    const [imageUrls, setImageUrls] = useState<string>('');
    const [mobileDrawerOpen, setMobileDrawerOpen] = useState(false);

    const toast = useToast();

    useEffect(() => {
        if (selectedCategory) {
            fetchCategoryAttributes(selectedCategory.id);
        } else {
            setCategoryAttributes([]);
            setSelectedAttributes({});
        }
    }, [selectedCategory]);

    const fetchCategoryAttributes = async (categoryId: number) => {
        setLoadingAttributes(true);
        try {
            const response = await axios.get(`/admin/games/categories/${categoryId}/attributes`);
            setCategoryAttributes(response.data.data || response.data);
        } catch (error) {
            message.error('Không thể tải danh sách thuộc tính!');
        } finally {
            setLoadingAttributes(false);
        }
    };

    const handleCategoryChange = (categoryId: number) => {
        const category = categories.find(cat => cat.id === categoryId);
        setSelectedCategory(category || null);
        setSelectedAttributes({});
        form.setFieldsValue({ attributes: {} });
    };

    const handleAttributeChange = (attributeId: number, optionId: number) => {
        setSelectedAttributes(prev => ({
            ...prev,
            [attributeId]: optionId
        }));
    };

    const handleSubmit = async (values: any) => {
        if (!selectedCategory) {
            message.error('Vui lòng chọn danh mục!');
            return;
        }

        setLoading(true);

        try {
            const attributeCache: INickAttributeCache[] = [];

            Object.entries(selectedAttributes).forEach(([attributeId, optionId]) => {
                const attribute = categoryAttributes.find(attr => attr.id === parseInt(attributeId));
                const option = attribute?.options?.find(opt => opt.id === optionId);

                if (attribute && option) {
                    attributeCache.push({
                        attribute_id: attribute.id,
                        attribute_name: attribute.name,
                        option_id: option.id,
                        option_value: option.option_value || option.option_value || ''
                    });
                }
            });

            const formData = new FormData();
            formData.append('account_name', values.account_name);
            formData.append('account_password', values.account_password);
            formData.append('price', values.price.toString());
            formData.append('description', values.description || '');
            formData.append('listing_type', values.listing_type);
            formData.append('category_id', selectedCategory.id.toString());
            formData.append('attribute_cache_json', JSON.stringify(attributeCache));

            if (imageMode === 'upload' && images && images.length > 0) {
                images.forEach((image, index) => {
                    formData.append(`images[${index}]`, image);
                });
            } else if (imageMode === 'url' && imageUrls.trim()) {
                const urls = imageUrls.split('\n')
                    .map(url => url.trim())
                    .filter(url => url.length > 0);

                if (urls.length > 0) {
                    formData.append('image_urls', JSON.stringify(urls));
                }
            }

            router.post('/admin/games/accounts', formData, {
                headers: {
                    'Content-Type': 'multipart/form-data'
                },
                onSuccess: () => {
                    message.success('Nick đã được tạo thành công!');
                },
                onError: (errors) => {
                    message.error('Tạo nick thất bại. Vui lòng kiểm tra lại thông tin!');
                },
                onFinish: () => {
                    setLoading(false);
                }
            });

        } catch (error) {
            message.error('Có lỗi xảy ra. Vui lòng thử lại!');
            setLoading(false);
        }
    };

    const handleBack = () => {
        router.visit('/admin/games/accounts');
    };

    // ✅ CRITICAL: Memoize ALL handlers
    const handleImageModeChange = useCallback((e: any) => {
        setImageMode(e.target.value);
        if (e.target.value === 'upload') {
            setImageUrls('');
        } else {
            setImages(null);
        }
    }, []);

    const handleImagesChange = useCallback((files: File[] | null) => {
        setImages(files);
    }, []);

    const handleImageUrlsChange = useCallback((e: React.ChangeEvent<HTMLTextAreaElement>) => {
        setImageUrls(e.target.value);
    }, []);

    return (
        <div className="p-4 sm:p-6">
            {/* Breadcrumb */}
            <Breadcrumb
                className="mb-4 sm:mb-6"
                items={[
                    {
                        title: (
                            <Button
                                type="link"
                                icon={<ArrowLeft className="w-4 h-4" />}
                                onClick={handleBack}
                                className="p-0 h-auto"
                            >
                                <span className="hidden sm:inline">Danh sách nick</span>
                                <span className="sm:hidden">Quay lại</span>
                            </Button>
                        ),
                    },
                    {
                        title: 'Thêm nick mới',
                    },
                ]}
            />

            {/* Header */}
            <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-6">
                <div>
                    <h1 className="text-xl sm:text-2xl font-bold text-gray-900">Thêm nick mới</h1>
                    <p className="text-sm sm:text-base text-gray-600 mt-1">
                        Tạo nick mới để bán trên hệ thống
                    </p>
                </div>

                <div className="flex gap-2 sm:gap-3">
                    <Button
                        icon={<ImageIcon className="w-4 h-4" />}
                        onClick={() => setMobileDrawerOpen(true)}
                        className="flex-1 sm:flex-none lg:hidden"
                    >
                        Hình ảnh
                    </Button>

                    <Button
                        type="primary"
                        icon={<Save className="w-4 h-4" />}
                        onClick={() => form.submit()}
                        loading={loading}
                        size="large"
                        className="flex-1 sm:flex-none"
                    >
                        Lưu
                    </Button>
                </div>
            </div>

            <Form
                form={form}
                layout="vertical"
                onFinish={handleSubmit}
                className="space-y-6"
            >
                <div className="grid grid-cols-1 lg:grid-cols-3 gap-4 sm:gap-6">
                    {/* Left Column - Main Info */}
                    <div className="lg:col-span-2 space-y-4 sm:space-y-6">
                        {/* Basic Information */}
                        <Card title="Thông tin cơ bản" className="shadow-sm">
                            <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                <Form.Item
                                    label="Tên tài khoản"
                                    name="account_name"
                                    rules={[
                                        { required: true, message: 'Vui lòng nhập tên tài khoản!' },
                                        { min: 3, message: 'Tên tài khoản phải có ít nhất 3 ký tự!' }
                                    ]}
                                    className="mb-4"
                                >
                                    <Input
                                        placeholder="Nhập tên tài khoản"
                                        prefix={<User className="w-4 h-4 text-gray-400" />}
                                        size="large"
                                    />
                                </Form.Item>

                                <Form.Item
                                    label="Mật khẩu"
                                    name="account_password"
                                    rules={[
                                        { required: true, message: 'Vui lòng nhập mật khẩu!' },
                                        { min: 1, message: 'Mật khẩu phải có ít nhất 1 ký tự!' }
                                    ]}
                                    className="mb-4"
                                >
                                    <Input.Password
                                        placeholder="Nhập mật khẩu tài khoản"
                                        size="large"
                                    />
                                </Form.Item>

                                <Form.Item
                                    label="Giá bán (VND)"
                                    name="price"
                                    rules={[
                                        { required: true, message: 'Vui lòng nhập giá bán!' },
                                        { type: 'number', min: 1000, message: 'Giá bán phải ít nhất 1,000 VND!' }
                                    ]}
                                    className="mb-4"
                                >
                                    <InputNumber
                                        placeholder="Nhập giá bán"
                                        className="w-full"
                                        formatter={(value) => `${value}`.replace(/\B(?=(\d{3})+(?!\d))/g, ',')}
                                        addonAfter="VND"
                                        min={1000}
                                        step={1000}
                                        size="large"
                                    />
                                </Form.Item>

                                <Form.Item
                                    label="Loại tin"
                                    name="listing_type"
                                    initialValue="normal"
                                    rules={[{ required: true, message: 'Vui lòng chọn loại tin!' }]}
                                    className="mb-4"
                                >
                                    <Radio.Group size="large">
                                        <Space direction="vertical" className="w-full">
                                            <Radio value="normal">
                                                <span className="flex items-center gap-2">
                                                    <span>📄</span>
                                                    <span className="text-sm">Normal</span>
                                                </span>
                                            </Radio>
                                            <Radio value="vip">
                                                <span className="flex items-center gap-2">
                                                    <Crown className="w-4 h-4 text-yellow-500" />
                                                    <span className="text-sm">VIP</span>
                                                </span>
                                            </Radio>
                                        </Space>
                                    </Radio.Group>
                                </Form.Item>
                            </div>

                            <Form.Item
                                label="Mô tả"
                                name="description"
                                className="mb-0"
                            >
                                <TextArea
                                    placeholder="Nhập mô tả chi tiết về nick (tùy chọn)"
                                    rows={4}
                                    showCount
                                    maxLength={1000}
                                />
                            </Form.Item>
                        </Card>

                        {/* Category Selection */}
                        <Card title="Danh mục & Thuộc tính" className="shadow-sm">
                            <Form.Item
                                label="Danh mục"
                                name="category_id"
                                rules={[{ required: true, message: 'Vui lòng chọn danh mục!' }]}
                            >
                                <Select
                                    placeholder="Chọn danh mục cho nick"
                                    onChange={handleCategoryChange}
                                    showSearch
                                    optionFilterProp="children"
                                    size="large"
                                    options={categories.map(category => ({
                                        label: (
                                            <div className="flex items-center gap-2 py-1">
                                                <FolderOpen className="w-4 h-4 text-blue-500" />
                                                <span>{category.name}</span>
                                            </div>
                                        ),
                                        value: category.id
                                    }))}
                                />
                            </Form.Item>

                            {/* Dynamic Attributes */}
                            {selectedCategory && (
                                <div className="mt-6">
                                    <Divider orientation="left">
                                        <span className="text-sm sm:text-base text-gray-700 font-medium">
                                            Thuộc tính "{selectedCategory.name}"
                                        </span>
                                    </Divider>

                                    {loadingAttributes ? (
                                        <div className="text-center py-4 text-gray-500">
                                            Đang tải thuộc tính...
                                        </div>
                                    ) : categoryAttributes.length === 0 ? (
                                        <Alert
                                            message="Thông báo"
                                            description="Danh mục này chưa có thuộc tính nào."
                                            type="info"
                                            showIcon
                                        />
                                    ) : (
                                        <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                            {categoryAttributes.map((attribute) => (
                                                <Form.Item
                                                    key={attribute.id}
                                                    label={
                                                        <div className="flex items-center gap-2 flex-wrap">
                                                            <TagIcon className="w-4 h-4 text-green-600 flex-shrink-0" />
                                                            <span className="text-sm">{attribute.name}</span>
                                                            {!attribute.status && (
                                                                <Tag color="red" className="text-xs">Tạm dừng</Tag>
                                                            )}
                                                        </div>
                                                    }
                                                    className="mb-4"
                                                >
                                                    <Select
                                                        placeholder={`Chọn ${attribute.name.toLowerCase()}`}
                                                        onChange={(value) => handleAttributeChange(attribute.id, value)}
                                                        allowClear
                                                        size="large"
                                                        options={attribute.options?.map(option => ({
                                                            label: option.option_value || option.option_value,
                                                            value: option.id,
                                                            disabled: !option.status
                                                        }))}
                                                    />
                                                </Form.Item>
                                            ))}
                                        </div>
                                    )}
                                </div>
                            )}
                        </Card>
                    </div>

                    {/* Right Column - Desktop only */}
                    <div className="hidden lg:block lg:col-span-1">
                        <Card
                            title="Hình ảnh nick"
                            className="shadow-sm sticky top-6"
                        >
                            <ImageSection
                                imageMode={imageMode}
                                images={images}
                                imageUrls={imageUrls}
                                selectedAttributes={selectedAttributes}
                                categoryAttributes={categoryAttributes}
                                onImageModeChange={handleImageModeChange}
                                onImagesChange={handleImagesChange}
                                onImageUrlsChange={handleImageUrlsChange}
                            />
                        </Card>
                    </div>
                </div>
            </Form>

            {/* Mobile Drawer for Images */}
            <Drawer
                title="Hình ảnh nick"
                placement="bottom"
                height="80vh"
                onClose={() => setMobileDrawerOpen(false)}
                open={mobileDrawerOpen}
                className="lg:hidden"
            >
                <ImageSection
                    imageMode={imageMode}
                    images={images}
                    imageUrls={imageUrls}
                    selectedAttributes={selectedAttributes}
                    categoryAttributes={categoryAttributes}
                    onImageModeChange={handleImageModeChange}
                    onImagesChange={handleImagesChange}
                    onImageUrlsChange={handleImageUrlsChange}
                />
            </Drawer>
        </div>
    );
}

NickCreatePage.layout = (page: React.ReactNode) => (
    <AdminLayout title="Create Nick" children={page} />
);
