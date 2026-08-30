// EditModal.tsx - Fixed Version
import React, { useState, useEffect } from 'react';
import { Modal, Form, Input, Select, InputNumber, Radio, Card, Alert, Divider, Tag, Button, Spin, Tabs } from 'antd';
import { Crown, DollarSign, User, FolderOpen, Tag as TagIcon, Upload, Save, X } from 'lucide-react';
import { useToast } from "@/Components/ToastProvider";
import { ICategory } from "@/InterFaces/category";
import { IAttribute } from "@/InterFaces/attribute";
import { INick, INickAttributeCache } from "@/InterFaces/nick";
import { UploadFull } from '@/Components/Upload/CustomUpload';
import axios from 'axios';
const { TextArea } = Input;

interface EditModalProps {
    onClose: () => void;
    nickId: number | null | undefined;
}

interface NickDetailResponse {
    success: boolean;
    data: {
        nick: INick;
        category: ICategory;
        attribute_options: any[];
        category_attributes: IAttribute[];
        images: string[];
    };
}

const EditModal: React.FC<EditModalProps> = ({ onClose, nickId }) => {
    const [form] = Form.useForm();
    const [loading, setLoading] = useState(false);
    const [loadingData, setLoadingData] = useState(true);
    const [nickData, setNickData] = useState<INick | null>(null);
    const [selectedCategory, setSelectedCategory] = useState<ICategory | null>(null);
    const [categoryAttributes, setCategoryAttributes] = useState<IAttribute[]>([]);
    const [selectedAttributes, setSelectedAttributes] = useState<{ [key: number]: number }>({});
    const [images, setImages] = useState<File[] | null>(null);
    const [imageMode, setImageMode] = useState<'upload' | 'url'>('upload');
    const [imageUrls, setImageUrls] = useState<string>('');
    const [existingImages, setExistingImages] = useState<string[]>([]);
    const toast = useToast();

    // Fetch nick data when modal opens
    useEffect(() => {
        if (nickId) {
            fetchNickData();
        }
    }, [nickId]);

    const fetchNickData = async () => {
        if (!nickId) return;

        setLoadingData(true);
        try {
            const response = await axios.get<NickDetailResponse>(`/admin/games/accounts/detail/${nickId}`);
            const { nick, category, attribute_options, category_attributes, images } = response.data.data;

            setNickData(nick);
            setSelectedCategory(category);
            setCategoryAttributes(category_attributes);

            // 🔧 FIX: Parse price as number and set form values properly
            const priceValue = typeof nick.price === 'string' ? parseFloat(nick.price) : nick.price;

            form.setFieldsValue({
                account_name: nick.account_name,
                account_password: nick.account_password,
                price: priceValue, // ✅ Ensure price is number
                description: nick.description || '', // ✅ Fallback for null/undefined
                listing_type: nick.listing_type,
                category_id: category?.id,
            });

            // Set selected attributes
            const attributeMap: { [key: number]: number } = {};
            attribute_options.forEach((item: any) => {
                if (item.selected_option) {
                    attributeMap[item.attribute_id] = item.selected_option.id;
                }
            });
            setSelectedAttributes(attributeMap);

            // Set existing images
            setExistingImages(images || []);

        } catch (error) {
            console.error('Error fetching nick data:', error);
            toast.error('Không thể tải thông tin nick!');
            onClose();
        } finally {
            setLoadingData(false);
        }
    };

    const handleAttributeChange = (attributeId: number, optionId: number) => {
        setSelectedAttributes(prev => ({
            ...prev,
            [attributeId]: optionId
        }));
    };

    const handleSubmit = async (values: any) => {
        if (!selectedCategory || !nickData) {
            toast.error('Dữ liệu không hợp lệ!');
            return;
        }

        // 🔧 FIX: Validate form values before submit
        if (!values.price || values.price < 1000) {
            toast.error('Giá bán phải ít nhất 1,000 VND!');
            return;
        }

        setLoading(true);
        try {
            // Prepare attribute cache
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

            // Prepare form data
            const formData = new FormData();
            formData.append('_method', 'PUT'); // Laravel method spoofing
            formData.append('account_name', values.account_name);
            formData.append('account_password', values.account_password);
            formData.append('price', values.price.toString()); // ✅ Ensure string conversion
            formData.append('description', values.description || '');
            formData.append('listing_type', values.listing_type);
            formData.append('category_id', selectedCategory.id.toString());
            formData.append('attribute_cache_json', JSON.stringify(attributeCache));

            // Handle images based on mode
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

            // Submit update
            await axios.post(`/admin/games/accounts/${nickData.id}?_method=PUT`, formData, {
                headers: {
                    'Content-Type': 'multipart/form-data'
                }
            });

            toast.success('Cập nhật nick thành công!');
            onClose();

        } catch (error: any) {
            console.error('Update error:', error);
            const errorMessage = error.response?.data?.message || 'Cập nhật nick thất bại. Vui lòng thử lại!';
            toast.error(errorMessage);
        } finally {
            setLoading(false);
        }
    };

    if (loadingData) {
        return (
            <Modal
                title="Chỉnh sửa nick"
                open={true}
                onCancel={onClose}
                footer={null}
                width={1200}
                centered
            >
                <div className="flex justify-center items-center py-20">
                    <Spin size="large" />
                    <span className="ml-3 text-gray-600">Đang tải dữ liệu...</span>
                </div>
            </Modal>
        );
    }

    return (
        <Modal
            title={
                <div className="flex items-center gap-2">
                    <span>Chỉnh sửa nick</span>
                    {nickData && (
                        <Tag color="blue">ID: {nickData.id}</Tag>
                    )}
                </div>
            }
            open={true}
            onCancel={onClose}
            width={1200}
            footer={[
                <Button key="cancel" onClick={onClose} disabled={loading}>
                    Hủy
                </Button>,
                <Button
                    key="submit"
                    type="primary"
                    icon={<Save className="w-4 h-4" />}
                    onClick={() => form.submit()}
                    loading={loading}
                >
                    Cập nhật
                </Button>
            ]}
            className="edit-nick-modal"
            style={{
                top: 20,
                maxHeight: 'calc(100vh - 40px)'
            }}
            styles={{
                body: {
                    maxHeight: 'calc(100vh - 200px)',
                    overflowY: 'auto',
                    overflowX: 'hidden',
                    paddingRight: '4px'
                }
            }}
        >
            <Form
                form={form}
                layout="vertical"
                onFinish={handleSubmit}
                size="large"
                className="mt-4"
                preserve={false} // 🔧 FIX: Prevent form data persistence issues
            >
                <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
                    {/* Left Column - Main Info */}
                    <div className="lg:col-span-2 space-y-4">
                        {/* Basic Information */}
                        <Card title="Thông tin cơ bản" size="small">
                            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <Form.Item
                                    label="Tên tài khoản"
                                    name="account_name"
                                    rules={[
                                        { required: true, message: 'Vui lòng nhập tên tài khoản!' },
                                        { min: 3, message: 'Tên tài khoản phải có ít nhất 3 ký tự!' }
                                    ]}
                                >
                                    <Input
                                        placeholder="Nhập tên tài khoản"
                                        prefix={<User className="w-4 h-4 text-gray-400" />}
                                    />
                                </Form.Item>

                                <Form.Item
                                    label="Mật khẩu"
                                    name="account_password"
                                    rules={[
                                        { required: true, message: 'Vui lòng nhập mật khẩu!' },
                                        { min: 1, message: 'Mật khẩu phải có ít nhất 1 ký tự!' }
                                    ]}
                                >
                                    <Input.Password placeholder="Nhập mật khẩu tài khoản" />
                                </Form.Item>

                                <Form.Item
                                    label="Giá bán (VND)"
                                    name="price"
                                    rules={[
                                        { required: true, message: 'Vui lòng nhập giá bán!' },
                                        { type: 'number', min: 1000, message: 'Giá bán phải ít nhất 1,000 VND!' }
                                    ]}
                                >
                                    <InputNumber
                                        placeholder="Nhập giá bán"
                                        className="w-full"
                                        formatter={(value) => `${value}`.replace(/\B(?=(\d{3})+(?!\d))/g, ',')}
                                        min={1000}
                                        step={1000}
                                        controls={true} // 🔧 FIX: Enable controls
                                        precision={0} // 🔧 FIX: No decimal places
                                    />
                                </Form.Item>

                                <Form.Item
                                    label="Loại tin"
                                    name="listing_type"
                                    rules={[{ required: true, message: 'Vui lòng chọn loại tin!' }]}
                                >
                                    <Radio.Group>
                                        <Radio value="normal">📄 Normal</Radio>
                                        <Radio value="vip">
                                            <span className="flex items-center gap-1">
                                                <Crown className="w-4 h-4 text-yellow-500" />
                                                VIP
                                            </span>
                                        </Radio>
                                    </Radio.Group>
                                </Form.Item>
                            </div>

                            <Form.Item label="Mô tả" name="description">
                                <TextArea
                                    placeholder="Nhập mô tả chi tiết về nick (tùy chọn)"
                                    rows={3}
                                    showCount
                                    maxLength={1000}
                                />
                            </Form.Item>
                        </Card>

                        {/* Category & Attributes */}
                        <Card title="Danh mục & Thuộc tính" size="small">
                            <Form.Item label="Danh mục">
                                <Select
                                    value={selectedCategory?.id}
                                    disabled
                                    placeholder="Danh mục"
                                >
                                    <Select.Option value={selectedCategory?.id}>
                                        <div className="flex items-center gap-2">
                                            <FolderOpen className="w-4 h-4 text-blue-500" />
                                            <span>{selectedCategory?.name}</span>
                                        </div>
                                    </Select.Option>
                                </Select>
                                <div className="text-xs text-gray-500 mt-1">
                                    Không thể thay đổi danh mục khi chỉnh sửa
                                </div>
                            </Form.Item>

                            {/* Dynamic Attributes */}
                            {selectedCategory && categoryAttributes.length > 0 && (
                                <div className="mt-4">
                                    <Divider orientation="left">
                                        <span className="text-gray-700 font-medium">
                                            Thuộc tính của danh mục "{selectedCategory.name}"
                                        </span>
                                    </Divider>
                                    <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                                        {categoryAttributes.map((attribute) => (
                                            <Form.Item
                                                key={attribute.id}
                                                label={
                                                    <div className="flex items-center gap-2">
                                                        <TagIcon className="w-4 h-4 text-green-600" />
                                                        <span>{attribute.name}</span>
                                                        {!attribute.status && (
                                                            <Tag color="red">Tạm dừng</Tag>
                                                        )}
                                                    </div>
                                                }
                                            >
                                                <Select
                                                    placeholder={`Chọn ${attribute.name.toLowerCase()}`}
                                                    value={selectedAttributes[attribute.id] || undefined}
                                                    onChange={(value) => handleAttributeChange(attribute.id, value)}
                                                    allowClear
                                                    options={attribute.options?.map(option => ({
                                                        label: option.option_value || option.option_value,
                                                        value: option.id,
                                                        disabled: !option.status
                                                    }))}
                                                />
                                            </Form.Item>
                                        ))}
                                    </div>
                                </div>
                            )}
                        </Card>
                    </div>

                    {/* Right Column - Images */}
                    <div className="lg:col-span-1">
                        <Card title="Quản lý hình ảnh" size="small">
                            <Tabs
                                defaultActiveKey="current"
                                items={[
                                    {
                                        key: 'current',
                                        label: (
                                            <span className="flex items-center gap-2">
                                                📷 Ảnh hiện tại
                                                {existingImages.length > 0 && (
                                                    <Tag color="blue" className="text-xs">
                                                        {existingImages.length}
                                                    </Tag>
                                                )}
                                            </span>
                                        ),
                                        children: (
                                            <div className="space-y-3">
                                                {existingImages.length > 0 ? (
                                                    <>
                                                        <div className="grid grid-cols-2 gap-2 max-h-96 overflow-y-auto">
                                                            {existingImages.map((imageUrl, index) => (
                                                                <div key={index} className="relative group">
                                                                    <div className="relative w-full h-24 rounded-lg overflow-hidden border border-gray-200 bg-gray-100">
                                                                        <img
                                                                            src={imageUrl}
                                                                            alt={`Current ${index + 1}`}
                                                                            className="w-full h-full object-cover transition-transform group-hover:scale-105"
                                                                            onError={(e) => {
                                                                                const target = e.currentTarget;
                                                                                const parent = target.parentElement!;
                                                                                parent.innerHTML = `
                                                                                    <div class="w-full h-full bg-red-50 border border-red-200 rounded-lg flex flex-col items-center justify-center p-1">
                                                                                        <span class="text-red-400 text-xs mb-1">❌</span>
                                                                                        <span class="text-red-400 text-xs text-center leading-tight">Lỗi ảnh</span>
                                                                                    </div>
                                                                                `;
                                                                            }}
                                                                        />
                                                                        {/* Image index */}
                                                                        <div className="absolute top-1 left-1 bg-black bg-opacity-70 text-white text-xs px-1.5 py-0.5 rounded">
                                                                            {index + 1}
                                                                        </div>
                                                                        {/* Main image indicator */}
                                                                        {index === 0 && (
                                                                            <div className="absolute top-1 right-1 bg-blue-500 text-white text-xs px-1.5 py-0.5 rounded">
                                                                                Main
                                                                            </div>
                                                                        )}
                                                                        {/* Hover overlay */}
                                                                        <div className="absolute inset-0 bg-black bg-opacity-0 group-hover:bg-opacity-20 transition-all duration-200 flex items-center justify-center">
                                                                            <div className="opacity-0 group-hover:opacity-100 transition-opacity">
                                                                                <Button
                                                                                    type="primary"
                                                                                    size="small"
                                                                                    onClick={() => window.open(imageUrl, '_blank')}
                                                                                    className="text-xs"
                                                                                >
                                                                                    Xem
                                                                                </Button>
                                                                            </div>
                                                                        </div>
                                                                    </div>
                                                                </div>
                                                            ))}
                                                        </div>
                                                        <div className="text-xs text-gray-500 space-y-1">
                                                            <p>💡 <strong>Lưu ý:</strong></p>
                                                            <ul className="ml-4 space-y-0.5">
                                                                <li>• Ảnh đầu tiên là ảnh đại diện</li>
                                                                <li>• Click "Xem" để xem ảnh full size</li>
                                                                <li>• Tổng: {existingImages.length} ảnh</li>
                                                            </ul>
                                                        </div>
                                                    </>
                                                ) : (
                                                    <div className="text-center py-8">
                                                        <div className="text-gray-400 text-4xl mb-2">📷</div>
                                                        <p className="text-gray-500 text-sm">Chưa có ảnh nào</p>
                                                    </div>
                                                )}
                                            </div>
                                        )
                                    },
                                    {
                                        key: 'new',
                                        label: (
                                            <span className="flex items-center gap-2">
                                                ✨ Cập nhật ảnh mới
                                                {((imageMode === 'upload' && images && images.length > 0) ||
                                                    (imageMode === 'url' && imageUrls.trim())) && (
                                                        <Tag color="green" className="text-xs">
                                                            {imageMode === 'upload' ? images?.length : imageUrls.split('\n').filter(url => url.trim()).length}
                                                        </Tag>
                                                    )}
                                            </span>
                                        ),
                                        children: (
                                            <div className="space-y-4">
                                                <Alert
                                                    message="Cảnh báo"
                                                    description="Nếu bạn thêm ảnh mới, toàn bộ ảnh cũ sẽ được thay thế."
                                                    type="warning"
                                                    showIcon
                                                    className="mb-4"
                                                />

                                                {/* Image Mode Selection */}
                                                <Form.Item label="Chọn cách thêm ảnh">
                                                    <Radio.Group
                                                        value={imageMode}
                                                        onChange={(e) => {
                                                            setImageMode(e.target.value);
                                                            if (e.target.value === 'upload') {
                                                                setImageUrls('');
                                                            } else {
                                                                setImages(null);
                                                            }
                                                        }}
                                                        className="w-full"
                                                    >
                                                        <Radio value="upload" className="w-full">
                                                            <span className="flex items-center gap-2">
                                                                <Upload className="w-4 h-4 text-blue-500" />
                                                                Upload ảnh từ máy
                                                            </span>
                                                        </Radio>
                                                        <Radio value="url" className="w-full mt-2">
                                                            <span className="flex items-center gap-2">
                                                                🔗 Nhập URL ảnh
                                                            </span>
                                                        </Radio>
                                                    </Radio.Group>
                                                </Form.Item>

                                                {/* Upload Mode */}
                                                {imageMode === 'upload' && (
                                                    <div className="space-y-3">
                                                        <UploadFull
                                                            value={images}
                                                            onChange={setImages}
                                                            maxCount={100}
                                                            maxSize={10}
                                                        />
                                                        {images && images.length > 0 && (
                                                            <div className="p-3 bg-blue-50 rounded-lg">
                                                                <div className="text-sm text-blue-700 font-medium">
                                                                    📸 Đã chọn {images.length} ảnh mới
                                                                </div>
                                                                <div className="text-xs text-blue-600 mt-1">
                                                                    Ảnh này sẽ thay thế toàn bộ ảnh cũ
                                                                </div>
                                                            </div>
                                                        )}
                                                    </div>
                                                )}

                                                {/* URL Mode */}
                                                {imageMode === 'url' && (
                                                    <div className="space-y-3">
                                                        <Form.Item
                                                            label="Danh sách URL ảnh mới"
                                                            help="Mỗi URL một dòng, tối đa 10 ảnh"
                                                        >
                                                            <TextArea
                                                                value={imageUrls}
                                                                onChange={(e) => setImageUrls(e.target.value)}
                                                                placeholder={`https://example.com/image1.jpg\nhttps://example.com/image2.png`}
                                                                rows={4}
                                                                showCount
                                                                maxLength={2000}
                                                            />
                                                        </Form.Item>
                                                        {imageUrls.trim() && (
                                                            <div className="p-3 bg-green-50 rounded-lg">
                                                                <div className="text-sm text-green-700 font-medium">
                                                                    🔗 {imageUrls.split('\n').filter(url => url.trim()).length} URL đã nhập
                                                                </div>
                                                                <div className="text-xs text-green-600 mt-1">
                                                                    URL này sẽ thay thế toàn bộ ảnh cũ
                                                                </div>
                                                            </div>
                                                        )}

                                                        {/* Preview URL images */}
                                                        {imageUrls.trim() && (
                                                            <div className="mt-4 p-3 bg-white rounded-lg border border-gray-200">
                                                                <p className="text-sm font-medium text-gray-700 mb-3">Preview URL:</p>
                                                                <div className="grid grid-cols-2 gap-2 max-h-64 overflow-y-auto">
                                                                    {imageUrls.split('\n')
                                                                        .map(url => url.trim())
                                                                        .filter(url => url.length > 0)
                                                                        .slice(0, 10)
                                                                        .map((url, index) => (
                                                                            <div key={index} className="relative w-full h-20 rounded-lg overflow-hidden border border-gray-200 bg-gray-100">
                                                                                <img
                                                                                    src={url}
                                                                                    alt={`Preview ${index + 1}`}
                                                                                    className="w-full h-full object-cover"
                                                                                    onError={(e) => {
                                                                                        const target = e.currentTarget;
                                                                                        const parent = target.parentElement!;
                                                                                        parent.innerHTML = `
                                                                                            <div class="w-full h-full bg-red-50 border border-red-200 rounded-lg flex flex-col items-center justify-center p-1">
                                                                                                <span class="text-red-400 text-xs mb-1">❌</span>
                                                                                                <span class="text-red-400 text-xs text-center leading-tight">Lỗi ảnh</span>
                                                                                            </div>
                                                                                        `;
                                                                                    }}
                                                                                />
                                                                                <div className="absolute top-1 left-1 bg-black bg-opacity-60 text-white text-xs px-1 rounded">
                                                                                    {index + 1}
                                                                                </div>
                                                                                {index === 0 && (
                                                                                    <div className="absolute top-1 right-1 bg-blue-500 text-white text-xs px-1 rounded">
                                                                                        Main
                                                                                    </div>
                                                                                )}
                                                                            </div>
                                                                        ))
                                                                    }
                                                                </div>
                                                            </div>
                                                        )}
                                                    </div>
                                                )}

                                                <div className="text-sm text-gray-500">
                                                    <p className="font-medium mb-1">Thông tin:</p>
                                                    <ul className="space-y-1 text-xs">
                                                        <li>• Để trống nếu không muốn thay đổi ảnh</li>
                                                        <li>• Ảnh mới sẽ thay thế hoàn toàn ảnh cũ</li>
                                                        <li>• Ảnh đầu tiên sẽ là ảnh đại diện</li>
                                                        <li>• Tối đa 10 ảnh, mỗi ảnh tối đa 5MB</li>
                                                    </ul>
                                                </div>
                                            </div>
                                        )
                                    }
                                ]}
                            />
                        </Card>
                    </div>
                </div>
            </Form>
        </Modal>
    );
};

export default EditModal;