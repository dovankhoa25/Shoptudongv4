import { Modal, Form, Input, Select } from "antd";
import { useForm } from "@inertiajs/react";
import { IRandomNick } from "@/InterFaces/randomnick";
import { IRandomBox } from "@/InterFaces/randombox";
import { useToast } from "@/Components/ToastProvider";
import { CustomUpload } from "@/Components/Upload/CustomUpload";
import { useState } from 'react';
import { User, Lock, FileText, Package } from 'lucide-react';

const { TextArea } = Input;

interface IProps {
    onClose: () => void;
    randomNick?: IRandomNick | null;
    randomBoxes?: IRandomBox[];
}

export default function RandomNickModal({ onClose, randomNick, randomBoxes = [] }: IProps) {
    const [imageFile, setImageFile] = useState<File | null>(null);
    const [previewImage, setPreviewImage] = useState<string | null>(
        randomNick?.image_url || null
    );

    const { data, setData, post, processing, reset } = useForm({
        random_box_id: randomNick?.random_box_id || "",
        account: randomNick?.account || "",
        password: randomNick?.password || "",
        description: randomNick?.description || "",
        image: null as File | null,
        status: randomNick?.status || "available",
    });

    const toast = useToast();

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();

        // Validate form
        if (!data.random_box_id) {
            toast.error('Vui lòng chọn hộp random!');
            return;
        }

        if (!data.account.trim()) {
            toast.error('Vui lòng nhập tài khoản!');
            return;
        }

        if (!data.password.trim()) {
            toast.error('Vui lòng nhập mật khẩu!');
            return;
        }

        // Prepare form data
        const formData = new FormData();
        formData.append('random_box_id', data.random_box_id.toString());
        formData.append('account', data.account);
        formData.append('password', data.password);
        formData.append('status', data.status);

        if (data.description.trim()) {
            formData.append('description', data.description);
        }

        if (imageFile) {
            formData.append('image', imageFile);
        }

        // Prepare submission options
        const submitOptions = {
            data: formData,
            headers: {
                'Content-Type': 'multipart/form-data'
            },
            onSuccess: () => {
                toast.success(`Nick "${data.account}" đã được ${randomNick?.id ? 'cập nhật' : 'tạo'} thành công!`);
                onClose();
                if (!randomNick?.id) reset();
            },
            onError: (errors: any) => {
                console.error('Submit errors:', errors);
                toast.error(`${randomNick?.id ? 'Cập nhật' : 'Tạo'} nick thất bại. Vui lòng thử lại!`);
            }
        };

        // Submit based on action type
        if (randomNick?.id) {
            post(`/admin/random-nicks/${randomNick.id}?_method=PUT`, submitOptions);
        } else {
            post("/admin/random-nicks", submitOptions);
        }
    };

    const handleImageChange = (file: File | null) => {
        setImageFile(file);
        setData('image', file);

        // Create preview
        if (file) {
            const reader = new FileReader();
            reader.onload = (e) => {
                setPreviewImage(e.target?.result as string);
            };
            reader.readAsDataURL(file);
        } else {
            setPreviewImage(randomNick?.image_url || null);
        }
    };

    const selectedRandomBox = data.random_box_id
        ? randomBoxes.find(box => box.id.toString() === data.random_box_id)
        : null;

    const statusOptions = [
        { label: '✅ Có sẵn', value: 'available' },
        { label: '⚠️ Đã bán', value: 'taken' },
        { label: '❌ Đã xóa', value: 'deleted' },
    ];

    return (
        <Modal
            open
            onCancel={onClose}
            onOk={handleSubmit}
            okText={randomNick?.id ? "Cập nhật" : "Tạo mới"}
            cancelText="Hủy"
            centered
            className="custom-modal"
            title={
                <div className="flex items-center gap-3 text-lg font-semibold text-gray-800">
                    <div className="w-8 h-8 bg-gradient-to-br from-blue-500 to-purple-600 rounded-lg flex items-center justify-center">
                        <User className="w-4 h-4 text-white" />
                    </div>
                    {randomNick?.id ? "Chỉnh sửa nick random" : "Tạo nick random mới"}
                </div>
            }
            confirmLoading={processing}
            width={700}
        >
            <Form className="mt-4" layout="vertical" initialValues={data}>
                {/* Random Box Selection */}
                <Form.Item
                    label={
                        <div className="flex items-center gap-2 font-medium text-gray-700">
                            <Package className="w-4 h-4" />
                            Hộp random
                        </div>
                    }
                    name="random_box_id"
                    rules={[{ required: true, message: "Vui lòng chọn hộp random!" }]}
                >
                    <Select
                        size="large"
                        placeholder="Chọn hộp random"
                        value={data.random_box_id}
                        onChange={(value) => setData("random_box_id", value)}
                        showSearch
                        filterOption={(input, option) =>
                            (option?.label ?? '').toLowerCase().includes(input.toLowerCase())
                        }
                        options={randomBoxes.map(box => ({
                            label: `${box.name} (${box.category?.name || 'Chưa phân loại'}) - ${box.price}`,
                            value: box.id.toString()
                        }))}
                        notFoundContent={randomBoxes.length === 0 ? "Chưa có hộp random nào" : "Không tìm thấy"}
                    />
                </Form.Item>

                {/* Selected Random Box Info */}
                {selectedRandomBox && (
                    <div className="mb-6 p-4 bg-blue-50 rounded-lg border border-blue-200">
                        <h3 className="font-medium text-blue-800 mb-2">Thông tin hộp random</h3>
                        <div className="flex flex-wrap items-center gap-4 text-sm">
                            <span className="text-blue-700">
                                <strong>Tên:</strong> {selectedRandomBox.name}
                            </span>
                            <span className="text-blue-700">
                                <strong>Giá:</strong> {selectedRandomBox.price_formatted}
                            </span>
                            <span className="text-blue-700">
                                <strong>Danh mục:</strong> {selectedRandomBox.category?.name || 'Chưa phân loại'}
                            </span>
                        </div>
                    </div>
                )}

                <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
                    {/* Cột trái - Thông tin nick */}
                    <div className="space-y-4">
                        <Form.Item
                            label={<div className="font-medium text-gray-700">Tài khoản</div>}
                            name="account"
                            rules={[
                                { required: true, message: "Vui lòng nhập tài khoản!" },
                                { min: 2, message: "Tài khoản phải có ít nhất 2 ký tự!" },
                                { max: 255, message: "Tài khoản không được vượt quá 255 ký tự!" }
                            ]}
                        >
                            <Input
                                size="large"
                                placeholder="Nhập tài khoản game"
                                value={data.account}
                                onChange={(e) => setData("account", e.target.value)}
                                prefix={<User className="w-4 h-4 text-gray-400" />}
                            />
                        </Form.Item>

                        <Form.Item
                            label={<div className="font-medium text-gray-700">Mật khẩu</div>}
                            name="password"
                            rules={[
                                { required: true, message: "Vui lòng nhập mật khẩu!" },
                                { min: 1, message: "Mật khẩu không được để trống!" },
                                { max: 255, message: "Mật khẩu không được vượt quá 255 ký tự!" }
                            ]}
                        >
                            <Input.Password
                                size="large"
                                placeholder="Nhập mật khẩu"
                                value={data.password}
                                onChange={(e) => setData("password", e.target.value)}
                                prefix={<Lock className="w-4 h-4 text-gray-400" />}
                            />
                        </Form.Item>

                        <Form.Item
                            label={<div className="font-medium text-gray-700">Trạng thái</div>}
                            name="status"
                        >
                            <Select
                                size="large"
                                placeholder="Chọn trạng thái"
                                value={data.status}
                                onChange={(value) => setData("status", value)}
                                options={statusOptions}
                            />
                        </Form.Item>

                        <Form.Item
                            label={<div className="font-medium text-gray-700">Mô tả nick</div>}
                            name="description"
                        >
                            <TextArea
                                placeholder="Mô tả chi tiết về nick (rank, tướng, skin, v.v.)"
                                value={data.description}
                                onChange={(e) => setData("description", e.target.value)}
                                rows={4}
                                maxLength={1000}
                                showCount
                            />
                        </Form.Item>
                    </div>

                    {/* Cột phải - Upload hình ảnh */}
                    <div className="space-y-4">
                        <Form.Item
                            label={<div className="font-medium text-gray-700">Hình ảnh nick (tùy chọn)</div>}
                            name="image"
                        >
                            <div className="space-y-3">
                                <CustomUpload
                                    value={imageFile}
                                    onChange={handleImageChange}
                                    maxSize={2}
                                />
                                <div className="text-xs text-gray-500">
                                    Hỗ trợ: JPG, PNG, GIF. Kích thước tối đa: 2MB
                                </div>
                            </div>
                        </Form.Item>

                        {/* Preview */}
                        {previewImage && (
                            <div className="border border-gray-200 rounded-lg p-4 bg-gray-50">
                                <p className="text-sm font-medium text-gray-700 mb-3">Preview:</p>
                                <div className="w-32 h-32 rounded-lg overflow-hidden border border-gray-200 bg-white mx-auto">
                                    <img
                                        src={previewImage}
                                        alt="Preview"
                                        className="w-full h-full object-cover"
                                        onError={(e) => {
                                            e.currentTarget.style.display = 'none';
                                            e.currentTarget.parentElement!.innerHTML = `
                                                <div class="w-full h-full bg-red-50 border border-red-200 rounded-lg flex items-center justify-center">
                                                    <span class="text-red-400 text-xs">Lỗi ảnh</span>
                                                </div>
                                            `;
                                        }}
                                    />
                                </div>
                            </div>
                        )}

                        {/* Fallback info */}
                        {!previewImage && selectedRandomBox?.image_url && (
                            <div className="border border-gray-200 rounded-lg p-4 bg-gray-50">
                                <p className="text-sm font-medium text-gray-700 mb-3">Ảnh từ hộp random:</p>
                                <div className="w-32 h-32 rounded-lg overflow-hidden border border-gray-200 bg-white mx-auto">
                                    <img
                                        src={selectedRandomBox.image_url}
                                        alt="Random box"
                                        className="w-full h-full object-cover opacity-75"
                                    />
                                </div>
                                <p className="text-xs text-gray-500 text-center mt-2">
                                    Nick sẽ dùng ảnh này nếu không upload ảnh riêng
                                </p>
                            </div>
                        )}

                        {/* Empty state when no randomBoxes */}
                        {randomBoxes.length === 0 && (
                            <div className="p-4 bg-yellow-50 rounded-lg border border-yellow-200">
                                <div className="flex items-center gap-2 text-yellow-800 mb-2">
                                    <Package className="w-4 h-4" />
                                    <span className="font-medium">Chưa có hộp random</span>
                                </div>
                                <p className="text-sm text-yellow-700">
                                    Bạn cần tạo ít nhất một hộp random trước khi thêm nick.
                                </p>
                            </div>
                        )}
                    </div>
                </div>

                <div className="text-sm text-gray-500 mt-6 p-4 bg-gray-50 rounded-lg">
                    <p><strong>💡 Lưu ý:</strong></p>
                    <ul className="list-disc list-inside space-y-1 mt-2">
                        <li>Tài khoản và mật khẩu là thông tin bắt buộc</li>
                        <li>Mô tả chi tiết giúp người mua hiểu rõ giá trị của nick</li>
                        <li>Hình ảnh riêng sẽ được ưu tiên hiển thị</li>
                        <li>Nếu không có ảnh riêng, sẽ dùng ảnh của hộp random</li>
                        <li>Nick có trạng thái "Có sẵn" mới có thể được bán</li>
                    </ul>
                </div>
            </Form>
        </Modal>
    );
}