// Admin/Spins/SpinModal.tsx
import React, { useState, useEffect } from 'react';
import { Modal, Form, Input, Select, InputNumber, Radio, Card, Alert, Button, Spin, Divider } from 'antd';
import { Crown, DollarSign, FolderOpen, Upload, Save, RotateCcw } from 'lucide-react';
import { useToast } from "@/Components/ToastProvider";
import { UploadFull } from '@/Components/Upload/CustomUpload';
import axios from 'axios';
import { router } from '@inertiajs/react';

const { TextArea } = Input;

interface SpinModalProps {
    open: boolean;
    onClose: () => void;
    spinId?: number | null;
    categories: Array<{ id: number; name: string }>;
}

interface ISpinData {
    id: number;
    category_id: number;
    name: string;
    image: string | null;
    image_url: string;
    type: 'wheel' | 'flip';
    price_per_turn: number;
    total_slots: number;
    is_public: boolean;
    sort_order: number;
    description: string | null;
}

const SpinModal: React.FC<SpinModalProps> = ({ open, onClose, spinId, categories }) => {
    const [form] = Form.useForm();
    const [loading, setLoading] = useState(false);
    const [loadingData, setLoadingData] = useState(false);
    const [spinData, setSpinData] = useState<ISpinData | null>(null);
    const [images, setImages] = useState<File[] | null>(null);
    const [imageMode, setImageMode] = useState<'upload' | 'url'>('upload');
    const [imageUrls, setImageUrls] = useState<string>('');
    const [existingImage, setExistingImage] = useState<string | null>(null);
    const toast = useToast();

    const isEditMode = !!spinId;

    // Fetch spin data when editing
    useEffect(() => {
        if (open && spinId) {
            fetchSpinData();
        } else if (open && !spinId) {
            // Reset form for create mode
            form.resetFields();
            setImages(null);
            setImageUrls('');
            setExistingImage(null);
            setImageMode('upload');
        }
    }, [open, spinId]);

    const fetchSpinData = async () => {
        if (!spinId) return;

        setLoadingData(true);
        try {
            const response = await axios.get(`/admin/spins/${spinId}`);
            const spin = response.data;

            setSpinData(spin);
            setExistingImage(spin.image_url);

            // Parse price as number
            const priceValue = typeof spin.price_per_turn === 'string'
                ? parseFloat(spin.price_per_turn)
                : spin.price_per_turn;

            form.setFieldsValue({
                category_id: spin.category_id,
                name: spin.name,
                type: spin.type,
                price_per_turn: priceValue,
                total_slots: spin.total_slots,
                is_public: spin.is_public,
                sort_order: spin.sort_order || 0,
                description: spin.description || '',
            });

        } catch (error) {
            console.error('Error fetching spin data:', error);
            toast.error('Không thể tải thông tin vòng quay!');
            onClose();
        } finally {
            setLoadingData(false);
        }
    };

    const handleSubmit = async (values: any) => {
        setLoading(true);

        try {
            // Prepare form data
            const formData = new FormData();

            if (isEditMode) {
                formData.append('_method', 'PUT');
            }

            formData.append('category_id', values.category_id.toString());
            formData.append('name', values.name);
            formData.append('type', values.type);
            formData.append('price_per_turn', values.price_per_turn.toString());
            formData.append('total_slots', values.total_slots.toString());
            formData.append('is_public', values.is_public ? '1' : '0');
            formData.append('sort_order', (values.sort_order || 0).toString());
            formData.append('description', values.description || '');

            // Handle images based on mode
            if (imageMode === 'upload' && images && images.length > 0) {
                formData.append('image_file', images[0]); // Only single image for spin
            } else if (imageMode === 'url' && imageUrls.trim()) {
                const url = imageUrls.split('\n')[0].trim(); // Get first URL only
                if (url) {
                    formData.append('image_url', url);
                }
            }

            // Submit
            const url = isEditMode ? `/admin/spins/${spinId}` : '/admin/spins';

            await axios.post(url, formData, {
                headers: {
                    'Content-Type': 'multipart/form-data'
                }
            });

            toast.success(isEditMode ? 'Cập nhật vòng quay thành công!' : 'Tạo vòng quay thành công!');
            onClose();
            router.reload(); // Reload page to update list

        } catch (error: any) {
            console.error('Submit error:', error);
            const errorMessage = error.response?.data?.message ||
                (isEditMode ? 'Cập nhật vòng quay thất bại!' : 'Tạo vòng quay thất bại!');
            toast.error(errorMessage);
        } finally {
            setLoading(false);
        }
    };

    const handleClose = () => {
        form.resetFields();
        setImages(null);
        setImageUrls('');
        setExistingImage(null);
        setSpinData(null);
        onClose();
    };

    if (loadingData) {
        return (
            <Modal
                title={isEditMode ? "Chỉnh sửa vòng quay" : "Thêm vòng quay mới"}
                open={open}
                onCancel={handleClose}
                footer={null}
                width={900}
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
                    <RotateCcw className="w-5 h-5 text-purple-500" />
                    <span>{isEditMode ? "Chỉnh sửa vòng quay" : "Thêm vòng quay mới"}</span>
                </div>
            }
            open={open}
            onCancel={handleClose}
            width={900}
            footer={[
                <Button key="cancel" onClick={handleClose} disabled={loading}>
                    Hủy
                </Button>,
                <Button
                    key="submit"
                    type="primary"
                    icon={<Save className="w-4 h-4" />}
                    onClick={() => form.submit()}
                    loading={loading}
                >
                    {isEditMode ? 'Cập nhật' : 'Tạo mới'}
                </Button>
            ]}
            className="spin-modal"
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
                preserve={false}
                initialValues={{
                    type: 'wheel',
                    total_slots: 8,
                    is_public: true,
                    sort_order: 0
                }}
            >
                <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
                    {/* Left Column - Main Info */}
                    <div className="lg:col-span-2 space-y-4">
                        {/* Basic Information */}
                        <Card title="Thông tin cơ bản" size="small">
                            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <Form.Item
                                    label="Danh mục"
                                    name="category_id"
                                    rules={[{ required: true, message: 'Vui lòng chọn danh mục!' }]}
                                >
                                    <Select
                                        placeholder="Chọn danh mục"
                                        showSearch
                                        optionFilterProp="children"
                                        options={categories.map(cat => ({
                                            label: (
                                                <div className="flex items-center gap-2">
                                                    <FolderOpen className="w-4 h-4 text-blue-500" />
                                                    <span>{cat.name}</span>
                                                </div>
                                            ),
                                            value: cat.id
                                        }))}
                                    />
                                </Form.Item>

                                <Form.Item
                                    label="Tên vòng quay"
                                    name="name"
                                    rules={[
                                        { required: true, message: 'Vui lòng nhập tên vòng quay!' },
                                        { min: 3, message: 'Tên vòng quay phải có ít nhất 3 ký tự!' }
                                    ]}
                                >
                                    <Input
                                        placeholder="Nhập tên vòng quay"
                                        prefix={<RotateCcw className="w-4 h-4 text-gray-400" />}
                                    />
                                </Form.Item>

                                <Form.Item
                                    label="Loại vòng quay"
                                    name="type"
                                    rules={[{ required: true, message: 'Vui lòng chọn loại!' }]}
                                >
                                    <Radio.Group>
                                        <Radio value="wheel">
                                            <span className="flex items-center gap-1">
                                                🎡 Vòng quay
                                            </span>
                                        </Radio>
                                        <Radio value="flip">
                                            <span className="flex items-center gap-1">
                                                🪙 Lật xu
                                            </span>
                                        </Radio>
                                    </Radio.Group>
                                </Form.Item>

                                <Form.Item
                                    label="Số ô/mặt"
                                    name="total_slots"
                                    rules={[
                                        { required: true, message: 'Vui lòng nhập số ô!' },
                                        { type: 'number', min: 2, message: 'Phải có ít nhất 2 ô!' },
                                        { type: 'number', max: 24, message: 'Tối đa 24 ô!' }
                                    ]}
                                    help="Vòng quay: 2-24 ô, Lật xu: thường 2 mặt"
                                >
                                    <InputNumber
                                        placeholder="Nhập số ô"
                                        className="w-full"
                                        min={2}
                                        max={24}
                                        step={1}
                                    />
                                </Form.Item>

                                <Form.Item
                                    label="Giá mỗi lượt (VND)"
                                    name="price_per_turn"
                                    rules={[
                                        { required: true, message: 'Vui lòng nhập giá!' },
                                        { type: 'number', min: 0, message: 'Giá phải lớn hơn hoặc bằng 0!' }
                                    ]}
                                >
                                    <InputNumber
                                        placeholder="Nhập giá mỗi lượt"
                                        className="w-full"
                                        formatter={(value) => `${value}`.replace(/\B(?=(\d{3})+(?!\d))/g, ',')}
                                        prefix={<DollarSign className="w-4 h-4 text-gray-400" />}
                                        min={0}
                                        step={1000}
                                        controls={true}
                                        precision={0}
                                    />
                                </Form.Item>

                                <Form.Item
                                    label="Thứ tự hiển thị"
                                    name="sort_order"
                                    help="Số càng nhỏ càng hiển thị trước"
                                >
                                    <InputNumber
                                        placeholder="Thứ tự"
                                        className="w-full"
                                        min={0}
                                        step={1}
                                    />
                                </Form.Item>
                            </div>

                            <Form.Item
                                label="Mô tả"
                                name="description"
                            >
                                <TextArea
                                    placeholder="Nhập mô tả về vòng quay (tùy chọn)"
                                    rows={3}
                                    showCount
                                    maxLength={500}
                                />
                            </Form.Item>

                            <Form.Item
                                label="Trạng thái"
                                name="is_public"
                                valuePropName="checked"
                            >
                                <Radio.Group>
                                    <Radio value={true}>
                                        ✅ Công khai
                                    </Radio>
                                    <Radio value={false}>
                                        ❌ Ẩn
                                    </Radio>
                                </Radio.Group>
                            </Form.Item>
                        </Card>
                    </div>

                    {/* Right Column - Image */}
                    <div className="lg:col-span-1">
                        <Card title="Hình ảnh" size="small">
                            <div className="space-y-4">
                                {/* Show existing image in edit mode */}
                                {isEditMode && existingImage && (
                                    <div className="mb-4">
                                        <div className="text-sm font-medium text-gray-700 mb-2">
                                            Ảnh hiện tại:
                                        </div>
                                        <div className="w-full h-32 rounded-lg overflow-hidden border border-gray-200 bg-gray-100">
                                            <img
                                                src={existingImage}
                                                alt="Current"
                                                className="w-full h-full object-cover"
                                                onError={(e) => {
                                                    const target = e.currentTarget;
                                                    target.src = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';
                                                }}
                                            />
                                        </div>
                                        <Alert
                                            message="Thêm ảnh mới để thay thế ảnh hiện tại"
                                            type="info"
                                            showIcon
                                            className="mt-2"
                                        />
                                        <Divider className="my-3" />
                                    </div>
                                )}

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
                                            maxCount={1}
                                            maxSize={5}
                                        />

                                        {images && images.length > 0 && (
                                            <div className="p-2 bg-blue-50 rounded-lg">
                                                <div className="text-sm text-blue-700 font-medium">
                                                    📸 Đã chọn 1 ảnh
                                                </div>
                                            </div>
                                        )}
                                    </div>
                                )}

                                {/* URL Mode */}
                                {imageMode === 'url' && (
                                    <div className="space-y-3">
                                        <Form.Item
                                            label="URL ảnh"
                                            help="Nhập đường dẫn URL của ảnh"
                                        >
                                            <Input
                                                value={imageUrls}
                                                onChange={(e) => setImageUrls(e.target.value)}
                                                placeholder="https://example.com/image.jpg"
                                            />
                                        </Form.Item>

                                        {imageUrls.trim() && (
                                            <>
                                                <div className="p-2 bg-green-50 rounded-lg">
                                                    <div className="text-sm text-green-700 font-medium">
                                                        🔗 URL đã nhập
                                                    </div>
                                                </div>

                                                {/* Preview URL image */}
                                                <div className="mt-3 p-3 bg-white rounded-lg border border-gray-200">
                                                    <p className="text-sm font-medium text-gray-700 mb-2">Preview:</p>
                                                    <div className="w-full h-32 rounded-lg overflow-hidden border border-gray-200 bg-gray-100">
                                                        <img
                                                            src={imageUrls.trim()}
                                                            alt="Preview"
                                                            className="w-full h-full object-cover"
                                                            onError={(e) => {
                                                                const target = e.currentTarget;
                                                                const parent = target.parentElement!;
                                                                parent.innerHTML = `
                                                                    <div class="w-full h-full bg-red-50 border border-red-200 rounded-lg flex flex-col items-center justify-center p-2">
                                                                        <span class="text-red-400 text-2xl mb-1">❌</span>
                                                                        <span class="text-red-400 text-xs text-center">URL ảnh không hợp lệ</span>
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
                                                    </div>
                                                </div>
                                            </>
                                        )}
                                    </div>
                                )}

                                <div className="text-sm text-gray-500">
                                    <p className="font-medium mb-1">Lưu ý:</p>
                                    <ul className="space-y-1 text-xs">
                                        {imageMode === 'upload' ? (
                                            <>
                                                <li>• Hỗ trợ: JPG, PNG, GIF</li>
                                                <li>• Kích thước tối đa: 5MB</li>
                                                <li>• Chỉ upload 1 ảnh đại diện</li>
                                            </>
                                        ) : (
                                            <>
                                                <li>• Chỉ hỗ trợ URL ảnh hợp lệ</li>
                                                <li>• URL phải trỏ trực tiếp đến file ảnh</li>
                                            </>
                                        )}
                                        <li>• Nên upload ảnh rõ nét, chất lượng cao</li>
                                        <li>• Tỷ lệ khuyến nghị: 1:1 (vuông)</li>
                                    </ul>
                                </div>
                            </div>
                        </Card>
                    </div>
                </div>
            </Form>
        </Modal>
    );
};

export default SpinModal;