import { Modal, Form, Input, InputNumber, Select, Upload, Switch, message } from "antd";
import { useForm } from "@inertiajs/react";
import { IRandomBox } from "@/InterFaces/randombox";
import { ICategory } from "@/InterFaces/category";
import { useToast } from "@/Components/ToastProvider";
import { InboxOutlined } from '@ant-design/icons';
import { useState } from 'react';

const { Dragger } = Upload;

interface IProps {
    onClose: () => void;
    randomBox?: IRandomBox | null;
    categories: ICategory[];
}

export default function RandomBoxModal({ onClose, randomBox, categories }: IProps) {
    const [imageFile, setImageFile] = useState<File | null>(null);
    const [previewImage, setPreviewImage] = useState<string | null>(
        randomBox?.image_url || null
    );

    const { data, setData, post, processing, reset } = useForm({
        category_id: randomBox?.category_id || "",
        name: randomBox?.name || "",
        price: randomBox?.price || 0,
        image: null as File | null,
        is_public: randomBox?.is_public ?? true,
        sort_order: randomBox?.sort_order || 0,
    });

    const toast = useToast();

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();

        // Validate form
        if (!data.name.trim()) {
            toast.error('Vui lòng nhập tên hộp random!');
            return;
        }

        if (!data.category_id) {
            toast.error('Vui lòng chọn danh mục!');
            return;
        }

        if (data.price <= 0) {
            toast.error('Giá phải lớn hơn 0!');
            return;
        }

        // Prepare form data
        const formData = new FormData();
        formData.append('category_id', data.category_id.toString());
        formData.append('name', data.name);
        formData.append('price', data.price.toString());
        formData.append('is_public', data.is_public ? '1' : '0');
        formData.append('sort_order', data.sort_order.toString());

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
                toast.success(`Hộp random "${data.name}" đã được ${randomBox?.id ? 'cập nhật' : 'tạo'} thành công!`);
                onClose();
                if (!randomBox?.id) reset();
            },
            onError: (errors: any) => {
                console.error('Submit errors:', errors);
                toast.error(`${randomBox?.id ? 'Cập nhật' : 'Tạo'} hộp random thất bại. Vui lòng thử lại!`);
            }
        };

        // Submit based on action type
        if (randomBox?.id) {
            post(`/admin/randombox/${randomBox.id}?_method=PUT`, submitOptions);
        } else {
            post("/admin/randombox", submitOptions);
        }
    };

    const handleImageUpload = {
        beforeUpload: (file: File) => {
            const isImage = file.type.startsWith('image/');
            if (!isImage) {
                message.error('Chỉ được upload file hình ảnh!');
                return false;
            }

            const isLt2M = file.size / 1024 / 1024 < 2;
            if (!isLt2M) {
                message.error('Kích thước hình ảnh phải nhỏ hơn 2MB!');
                return false;
            }

            // Set file and preview
            setImageFile(file);
            setData('image', file);

            // Create preview
            const reader = new FileReader();
            reader.onload = (e) => {
                setPreviewImage(e.target?.result as string);
            };
            reader.readAsDataURL(file);

            return false; // Prevent auto upload
        },
        onRemove: () => {
            setImageFile(null);
            setData('image', null);
            setPreviewImage(randomBox?.image_url || null);
        },
        fileList: imageFile ? [
            {
                uid: '-1',
                name: imageFile.name,
                status: 'done' as const,
                // ❌ BỎ url: previewImage - đây là nguyên nhân gây lỗi
            }
        ] : [],
    };

    return (
        <Modal
            open
            onCancel={onClose}
            onOk={handleSubmit}
            okText={randomBox?.id ? "Cập nhật" : "Tạo mới"}
            cancelText="Hủy"
            centered
            className="custom-modal"
            title={
                <div className="text-lg font-semibold text-gray-800">
                    {randomBox?.id ? "Chỉnh sửa hộp random" : "Tạo hộp random mới"}
                </div>
            }
            confirmLoading={processing}
            width={700}
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
            <Form className="mt-4" layout="vertical" initialValues={data}>
                <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                    {/* Cột trái */}
                    <div className="space-y-4">
                        <Form.Item
                            label={<div className="font-medium text-gray-700">Danh mục</div>}
                            name="category_id"
                            rules={[
                                { required: true, message: "Vui lòng chọn danh mục!" }
                            ]}
                        >
                            <Select
                                size="large"
                                placeholder="Chọn danh mục"
                                value={data.category_id}
                                onChange={(value) => setData("category_id", value)}
                                showSearch
                                filterOption={(input, option) =>
                                    (option?.label ?? '').toLowerCase().includes(input.toLowerCase())
                                }
                                options={categories.map(cat => ({
                                    label: cat.name,
                                    value: cat.id
                                }))}
                            />
                        </Form.Item>

                        <Form.Item
                            label={<div className="font-medium text-gray-700">Tên hộp random</div>}
                            name="name"
                            rules={[
                                { required: true, message: "Vui lòng nhập tên hộp random!" },
                                { min: 2, message: "Tên hộp random phải có ít nhất 2 ký tự!" },
                                { max: 255, message: "Tên hộp random không được vượt quá 255 ký tự!" }
                            ]}
                        >
                            <Input
                                size="large"
                                placeholder="Nhập tên hộp random (VD: Hộp Random Nick Liên Quân VIP)"
                                value={data.name}
                                onChange={(e) => setData("name", e.target.value)}
                            />
                        </Form.Item>

                        <Form.Item
                            label={<div className="font-medium text-gray-700">Giá (VNĐ)</div>}
                            name="price"
                            rules={[
                                { required: true, message: "Vui lòng nhập giá!" },
                                { type: 'number', min: 1, message: "Giá phải lớn hơn 0!" }
                            ]}
                        >
                            <InputNumber
                                size="large"
                                className="w-full"
                                placeholder="Nhập giá hộp random"
                                value={data.price}
                                onChange={(value) => setData("price", value || 0)}
                                min={0}
                                max={999999999}
                                formatter={(value) => `${value}`.replace(/\B(?=(\d{3})+(?!\d))/g, ',')}
                                // parser={(value) => value!.replace(/\$\s?|(,*)/g, '')}
                                addonAfter="VNĐ"
                            />
                        </Form.Item>

                        <div className="grid grid-cols-2 gap-4">
                            <Form.Item
                                label={<div className="font-medium text-gray-700">Thứ tự sắp xếp</div>}
                                name="sort_order"
                            >
                                <InputNumber
                                    size="large"
                                    className="w-full"
                                    placeholder="Thứ tự"
                                    value={data.sort_order}
                                    onChange={(value) => setData("sort_order", value || 0)}
                                    min={0}
                                    max={9999}
                                />
                            </Form.Item>

                            <Form.Item
                                label={<div className="font-medium text-gray-700">Trạng thái</div>}
                                name="is_public"
                            >
                                <div className="flex items-center gap-2 mt-2">
                                    <Switch
                                        checked={data.is_public}
                                        onChange={(checked) => setData("is_public", checked)}
                                        size="default"
                                    />
                                    <span className="text-sm text-gray-600">
                                        {data.is_public ? 'Công khai' : 'Ẩn'}
                                    </span>
                                </div>
                            </Form.Item>
                        </div>
                    </div>

                    {/* Cột phải - Upload hình ảnh */}
                    <div>
                        <Form.Item
                            label={<div className="font-medium text-gray-700">Hình ảnh hộp random</div>}
                            name="image"
                        >
                            <Dragger {...handleImageUpload} className="w-full">
                                {previewImage ? (
                                    <div className="p-4">
                                        <img
                                            src={previewImage}
                                            alt="Preview"
                                            className="max-w-full max-h-40 mx-auto rounded-lg object-cover"
                                        />
                                        <p className="text-sm text-gray-500 mt-2">
                                            Click hoặc kéo thả để thay đổi hình ảnh
                                        </p>
                                    </div>
                                ) : (
                                    <>
                                        <p className="ant-upload-drag-icon">
                                            <InboxOutlined />
                                        </p>
                                        <p className="ant-upload-text">
                                            Click hoặc kéo thả hình ảnh vào đây
                                        </p>
                                        <p className="ant-upload-hint">
                                            Hỗ trợ: JPG, PNG, GIF. Tối đa 2MB.
                                        </p>
                                    </>
                                )}
                            </Dragger>
                        </Form.Item>
                    </div>
                </div>

                <div className="text-sm text-gray-500 mt-6 p-4 bg-gray-50 rounded-lg">
                    <p><strong>Lưu ý:</strong></p>
                    <ul className="list-disc list-inside space-y-1 mt-2">
                        <li>Hộp random sẽ chứa các nick để bán theo cơ chế ngẫu nhiên</li>
                        <li>Giá áp dụng cho mỗi lần mua random từ hộp này</li>
                        <li>Thứ tự sắp xếp: số nhỏ hơn sẽ hiển thị trước</li>
                        <li>Hình ảnh nên có tỷ lệ vuông (1:1) để hiển thị đẹp nhất</li>
                        <li>Sau khi tạo hộp, bạn có thể thêm nick vào hộp này</li>
                    </ul>
                </div>
            </Form>
        </Modal>
    );
}