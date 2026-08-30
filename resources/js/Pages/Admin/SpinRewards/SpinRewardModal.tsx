// Admin/SpinRewards/SpinRewardModal.tsx
import React, { useState, useEffect } from 'react';
import { Modal, Form, Input, Select, InputNumber, Radio, Card, Alert, Button, Spin, Divider, Progress } from 'antd';
import { Gift, DollarSign, Gem, FileText, User, Upload, Save, Tag as TagIcon } from 'lucide-react';
import { useToast } from "@/Components/ToastProvider";
import { UploadFull } from '@/Components/Upload/CustomUpload';
import axios from 'axios';
import { router } from '@inertiajs/react';

const { TextArea } = Input;

interface SpinRewardModalProps {
    open: boolean;
    onClose: () => void;
    rewardId?: number | null;
    spin: {
        id: number;
        name: string;
        total_slots: number;
    };
    totalProbability: number;
    remainingProbability: number;
}

interface ISpinRewardData {
    id: number;
    spin_id: number;
    reward_type: 'text' | 'coin' | 'gem' | 'nick' | 'item';
    reward_value: string;
    image: string | null;
    image_url: string;
    probability: number;
}

const SpinRewardModal: React.FC<SpinRewardModalProps> = ({
    open,
    onClose,
    rewardId,
    spin,
    totalProbability,
    remainingProbability
}) => {
    const [form] = Form.useForm();
    const [loading, setLoading] = useState(false);
    const [loadingData, setLoadingData] = useState(false);
    const [rewardData, setRewardData] = useState<ISpinRewardData | null>(null);
    const [images, setImages] = useState<File[] | null>(null);
    const [imageMode, setImageMode] = useState<'upload' | 'url'>('upload');
    const [imageUrls, setImageUrls] = useState<string>('');
    const [existingImage, setExistingImage] = useState<string | null>(null);
    const [selectedRewardType, setSelectedRewardType] = useState<string>('text');
    const toast = useToast();

    const isEditMode = !!rewardId;

    // Calculate available probability
    const availableProbability = isEditMode
        ? remainingProbability + (rewardData?.probability || 0)
        : remainingProbability;

    // Fetch reward data when editing
    useEffect(() => {
        if (open && rewardId) {
            fetchRewardData();
        } else if (open && !rewardId) {
            form.resetFields();
            setImages(null);
            setImageUrls('');
            setExistingImage(null);
            setImageMode('upload');
            setSelectedRewardType('text');
        }
    }, [open, rewardId]);

    const fetchRewardData = async () => {
        if (!rewardId) return;

        setLoadingData(true);
        try {
            const response = await axios.get(`/admin/spins/${spin.id}/rewards/${rewardId}/edit`);
            const reward = response.data.reward;

            setRewardData(reward);
            setExistingImage(reward.image_url);
            setSelectedRewardType(reward.reward_type);

            form.setFieldsValue({
                reward_type: reward.reward_type,
                reward_value: reward.reward_value,
                probability: reward.probability,
            });

        } catch (error) {
            console.error('Error fetching reward data:', error);
            toast.error('Không thể tải thông tin phần thưởng!');
            onClose();
        } finally {
            setLoadingData(false);
        }
    };

    const handleSubmit = async (values: any) => {
        setLoading(true);

        try {
            const formData = new FormData();

            if (isEditMode) {
                formData.append('_method', 'PUT');
            }

            formData.append('reward_type', values.reward_type);
            formData.append('reward_value', values.reward_value);
            formData.append('probability', values.probability.toString());

            // Handle images
            if (imageMode === 'upload' && images && images.length > 0) {
                formData.append('image_file', images[0]);
            } else if (imageMode === 'url' && imageUrls.trim()) {
                const url = imageUrls.split('\n')[0].trim();
                if (url) {
                    formData.append('image_url', url);
                }
            }

            const url = isEditMode
                ? `/admin/spins/${spin.id}/rewards/${rewardId}`
                : `/admin/spins/${spin.id}/rewards`;

            await axios.post(url, formData, {
                headers: {
                    'Content-Type': 'multipart/form-data'
                }
            });

            toast.success(isEditMode ? 'Cập nhật phần thưởng thành công!' : 'Thêm phần thưởng thành công!');
            onClose();
            router.reload();

        } catch (error: any) {
            console.error('Submit error:', error);
            const errorMessage = error.response?.data?.message ||
                (isEditMode ? 'Cập nhật phần thưởng thất bại!' : 'Thêm phần thưởng thất bại!');
            toast.error(errorMessage);

            // Show specific error for probability
            if (error.response?.data?.errors?.probability) {
                toast.error(error.response.data.errors.probability[0]);
            }
        } finally {
            setLoading(false);
        }
    };

    const handleClose = () => {
        form.resetFields();
        setImages(null);
        setImageUrls('');
        setExistingImage(null);
        setRewardData(null);
        setSelectedRewardType('text');
        onClose();
    };

    const getRewardTypeIcon = (type: string) => {
        switch (type) {
            case 'text': return <FileText className="w-4 h-4" />;
            case 'coin': return <DollarSign className="w-4 h-4" />;
            case 'gem': return <Gem className="w-4 h-4" />;
            case 'nick': return <User className="w-4 h-4" />;
            case 'item': return <Gift className="w-4 h-4" />;
            default: return <TagIcon className="w-4 h-4" />;
        }
    };

    const getRewardTypePlaceholder = (type: string) => {
        switch (type) {
            case 'text': return 'Ví dụ: Chúc may mắn lần sau, Cảm ơn bạn đã tham gia';
            case 'coin': return 'Ví dụ: 1000, 5000, 10000';
            case 'gem': return 'Ví dụ: 10, 50, 100';
            case 'nick': return 'Ví dụ: Nick VIP, Nick Thường';
            case 'item': return 'Ví dụ: Giftcode, Voucher 50k';
            default: return 'Nhập giá trị phần thưởng';
        }
    };

    if (loadingData) {
        return (
            <Modal
                title="Chỉnh sửa phần thưởng"
                open={open}
                onCancel={handleClose}
                footer={null}
                width={800}
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
                    <Gift className="w-5 h-5 text-orange-500" />
                    <span>{isEditMode ? "Chỉnh sửa phần thưởng" : "Thêm phần thưởng mới"}</span>
                </div>
            }
            open={open}
            onCancel={handleClose}
            width={800}
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
                    disabled={availableProbability <= 0 && !isEditMode}
                >
                    {isEditMode ? 'Cập nhật' : 'Thêm mới'}
                </Button>
            ]}
            className="spin-reward-modal"
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
                    reward_type: 'text',
                    probability: 0
                }}
            >
                {/* Probability Info */}
                <Alert
                    message={
                        <div>
                            <div className="font-semibold mb-2">Thông tin xác suất:</div>
                            <div className="grid grid-cols-3 gap-4 text-sm">
                                <div>
                                    <div className="text-gray-600">Đã dùng:</div>
                                    <div className="font-semibold text-blue-600">{totalProbability}%</div>
                                </div>
                                <div>
                                    <div className="text-gray-600">Còn lại:</div>
                                    <div className="font-semibold text-green-600">{remainingProbability}%</div>
                                </div>
                                <div>
                                    <div className="text-gray-600">Có thể dùng:</div>
                                    <div className="font-semibold text-orange-600">{availableProbability}%</div>
                                </div>
                            </div>
                            <Progress
                                percent={totalProbability}
                                size="small"
                                strokeColor={totalProbability === 100 ? '#52c41a' : '#1890ff'}
                                className="mt-3"
                            />
                        </div>
                    }
                    type={totalProbability >= 100 && !isEditMode ? "error" : "info"}
                    showIcon
                    className="mb-4"
                />

                <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
                    {/* Left Column - Main Info */}
                    <div className="lg:col-span-2 space-y-4">
                        <Card title="Thông tin phần thưởng" size="small">
                            <Form.Item
                                label="Loại phần thưởng"
                                name="reward_type"
                                rules={[{ required: true, message: 'Vui lòng chọn loại phần thưởng!' }]}
                            >
                                <Select
                                    placeholder="Chọn loại phần thưởng" onChange={(value) => setSelectedRewardType(value)}
                                    options={[
                                        {
                                            label: (
                                                <div className="flex items-center gap-2">
                                                    <FileText className="w-4 h-4" />
                                                    <span>Văn bản</span>
                                                </div>
                                            ),
                                            value: 'text'
                                        },
                                        {
                                            label: (
                                                <div className="flex items-center gap-2">
                                                    <DollarSign className="w-4 h-4" />
                                                    <span>Xu</span>
                                                </div>
                                            ),
                                            value: 'coin'
                                        },
                                        {
                                            label: (
                                                <div className="flex items-center gap-2">
                                                    <Gem className="w-4 h-4" />
                                                    <span>Kim cương</span>
                                                </div>
                                            ),
                                            value: 'gem'
                                        },
                                        {
                                            label: (
                                                <div className="flex items-center gap-2">
                                                    <User className="w-4 h-4" />
                                                    <span>Nick game</span>
                                                </div>
                                            ),
                                            value: 'nick'
                                        },
                                        {
                                            label: (
                                                <div className="flex items-center gap-2">
                                                    <Gift className="w-4 h-4" />
                                                    <span>Vật phẩm</span>
                                                </div>
                                            ),
                                            value: 'item'
                                        }
                                    ]}
                                />
                            </Form.Item>

                            <Form.Item
                                label={
                                    <div className="flex items-center gap-2">
                                        {getRewardTypeIcon(selectedRewardType)}
                                        <span>Giá trị phần thưởng</span>
                                    </div>
                                }
                                name="reward_value"
                                rules={[
                                    { required: true, message: 'Vui lòng nhập giá trị!' },
                                    // ✅ FIXED - Thay đổi validation dựa trên reward_type
                                    ({ getFieldValue }) => ({
                                        validator(_, value) {
                                            const rewardType = getFieldValue('reward_type');

                                            // Nếu là coin hoặc gem, validate như number
                                            if (rewardType === 'coin' || rewardType === 'gem') {
                                                if (value === null || value === undefined || value === '') {
                                                    return Promise.reject(new Error('Vui lòng nhập giá trị!'));
                                                }
                                                if (Number(value) < 0) {
                                                    return Promise.reject(new Error('Giá trị phải lớn hơn hoặc bằng 0!'));
                                                }
                                                return Promise.resolve();
                                            }

                                            // Nếu là text/nick/item, validate như string
                                            if (!value || value.toString().trim().length === 0) {
                                                return Promise.reject(new Error('Vui lòng nhập giá trị!'));
                                            }
                                            if (value.toString().length > 255) {
                                                return Promise.reject(new Error('Giá trị không được quá 255 ký tự!'));
                                            }
                                            return Promise.resolve();
                                        },
                                    }),
                                ]}
                                help={
                                    selectedRewardType === 'coin' || selectedRewardType === 'gem'
                                        ? 'Nhập số tiền/kim cương (chỉ số)'
                                        : null
                                }
                            >
                                {selectedRewardType === 'coin' || selectedRewardType === 'gem' ? (
                                    <InputNumber
                                        placeholder={getRewardTypePlaceholder(selectedRewardType)}
                                        className="w-full"
                                        min={0}
                                        step={selectedRewardType === 'coin' ? 1000 : 10}
                                        formatter={(value) => `${value}`.replace(/\B(?=(\d{3})+(?!\d))/g, ',')}
                                    // parser={(value) => value!.replace(/\$\s?|(,*)/g, '')} // ✅ ADD parser
                                    // ✅ REMOVE prefix vì nó conflict với formatter
                                    />
                                ) : (
                                    <Input
                                        placeholder={getRewardTypePlaceholder(selectedRewardType)}
                                        prefix={getRewardTypeIcon(selectedRewardType)}
                                        maxLength={255}
                                    />
                                )}
                            </Form.Item>

                            <Form.Item
                                label={
                                    <div className="flex items-center justify-between w-full">
                                        <span>Tỷ lệ trúng (%)</span>
                                        <span className="text-xs text-gray-500">
                                            Còn lại: {availableProbability}%
                                        </span>
                                    </div>
                                }
                                name="probability"
                                rules={[
                                    { required: true, message: 'Vui lòng nhập tỷ lệ trúng!' },
                                    { type: 'number', min: 0, message: 'Tỷ lệ phải lớn hơn 0!' },
                                    {
                                        type: 'number',
                                        max: availableProbability,
                                        message: `Tỷ lệ không được vượt quá ${availableProbability}%!`
                                    }
                                ]}
                            >
                                <InputNumber
                                    placeholder="Nhập tỷ lệ trúng"
                                    className="w-full"
                                    min={0}
                                    max={availableProbability}
                                    step={0.1}
                                    precision={2}
                                    suffix="%"
                                    controls={true}
                                />
                            </Form.Item>

                            {/* Probability Helper */}
                            <div className="bg-blue-50 border border-blue-200 rounded-lg p-3">
                                <div className="text-xs text-blue-700 space-y-1">
                                    <div className="font-semibold mb-2">💡 Gợi ý tỷ lệ:</div>
                                    <div className="grid grid-cols-2 gap-2">
                                        <div>• Phần thưởng lớn: 1-5%</div>
                                        <div>• Phần thưởng trung bình: 5-15%</div>
                                        <div>• Phần thưởng nhỏ: 15-30%</div>
                                        <div>• Chúc may mắn: 20-40%</div>
                                    </div>
                                    <Divider className="my-2" />
                                    <div className="text-orange-600">
                                        ⚠️ Tổng tỷ lệ của tất cả phần thưởng phải bằng 100%
                                    </div>
                                </div>
                            </div>
                        </Card>
                    </div>

                    {/* Right Column - Image */}
                    <div className="lg:col-span-1">
                        <Card title="Hình ảnh phần thưởng" size="small">
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
                                            message="Thêm ảnh mới để thay thế"
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
                                                Upload từ máy
                                            </span>
                                        </Radio>
                                        <Radio value="url" className="w-full mt-2">
                                            <span className="flex items-center gap-2">
                                                🔗 Nhập URL
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
                                                                        <span class="text-red-400 text-xs text-center">URL không hợp lệ</span>
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
                                        <li>• Ảnh giúp người chơi dễ nhận biết phần thưởng</li>
                                        <li>• Hỗ trợ: JPG, PNG, GIF</li>
                                        <li>• Kích thước tối đa: 5MB</li>
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

export default SpinRewardModal;