import { Modal, Form, Input, Select, Switch, InputNumber, Tabs } from "antd";
import { useForm } from "@inertiajs/react";
import { ICategory } from "@/InterFaces/category";
import { IGameType } from "@/InterFaces/gametype";
import { useToast } from "@/Components/ToastProvider";
import { useState, useEffect } from "react";
import { FolderOpen, Gamepad2, Image as ImageIcon, Upload as UploadIcon, Link } from "lucide-react";
import { CustomUpload } from "@/Components/Upload/CustomUpload";
import axios from "axios";

interface IProps {
    onClose: () => void;
    category?: ICategory | null;
}

export default function CategoryModal({ onClose, category }: IProps) {
    const [gameTypes, setGameTypes] = useState<IGameType[]>([]);
    const [loadingGameTypes, setLoadingGameTypes] = useState(false);
    const [imageType, setImageType] = useState<'url' | 'file'>('url');

    const { data, setData, post, put, processing, reset } = useForm({
        game_type_id: category?.game_type_id || null,
        name: category?.name || "",
        image_url: category?.image_url || "",
        image_file: null as File | null,
        template: category?.template || "default",
        is_public: category?.is_public ?? true,
        status: category?.status || "active",
        sort_order: category?.sort_order || 0,
    });

    const toast = useToast();

    // Load game types when component mounts
    useEffect(() => {
        const fetchGameTypes = async () => {
            setLoadingGameTypes(true);
            try {
                const response = await axios.get('/admin/games/categories-game-types');
                setGameTypes(response.data.data || response.data);
            } catch (error) {
                console.error('Error fetching game types:', error);
                toast.error('Không thể tải danh sách loại game!');
            } finally {
                setLoadingGameTypes(false);
            }
        };

        fetchGameTypes();
    }, []);

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();

        // Validate form
        if (!data.name.trim()) {
            toast.error('Vui lòng nhập tên danh mục!');
            return;
        }

        if (!data.game_type_id) {
            toast.error('Vui lòng chọn loại game!');
            return;
        }

        // Prepare data for submission
        const submitData = new FormData();
        submitData.append('game_type_id', data.game_type_id.toString());
        submitData.append('name', data.name);
        submitData.append('template', data.template);
        submitData.append('is_public', data.is_public ? '1' : '0');
        submitData.append('status', data.status);
        submitData.append('sort_order', data.sort_order.toString());

        // Handle image based on type
        if (imageType === 'file' && data.image_file) {
            submitData.append('image', data.image_file);
        } else if (imageType === 'url' && data.image_url.trim()) {
            submitData.append('image_url', data.image_url);
        }

        // Add method for PUT requests
        // if (category?.id) {
        //     submitData.append('_method', 'PUT');
        // }

        const submitOptions = {
            data: submitData,
            headers: {
                'Content-Type': 'multipart/form-data'
            },
            onSuccess: () => {
                toast.success(`Danh mục "${data.name}" đã được ${category?.id ? 'cập nhật' : 'tạo'} thành công!`);
                onClose();
                if (!category?.id) reset();
            },
            onError: (errors: any) => {
                console.error('Submit errors:', errors);
                toast.error(`${category?.id ? 'Cập nhật' : 'Tạo'} danh mục thất bại. Vui lòng thử lại!`);
            }
        };

        if (category?.id) {
            post(`/admin/games/categories/${category.id}?_method=PUT`, submitOptions);
        } else {
            post("/admin/games/categories", submitOptions);
        }
    };

    const handleNameChange = (e: React.ChangeEvent<HTMLInputElement>) => {
        const name = e.target.value;
        setData("name", name);
    };

    const templateOptions = [
        { label: '🎮 Mặc định', value: 'default' },
        { label: '📱 Vòng Quay', value: 'spin' },
        { label: '🖥️ Random', value: 'random' },
        { label: '🎯 Dịch Vụ', value: 'service' },
        // { label: '🎲 Casino', value: 'casino' },
        // { label: '⚽ Sports', value: 'sports' },
    ];

    const statusOptions = [
        { label: '✅ Hoạt động', value: 'active' },
        { label: '⏸️ Tạm dừng', value: 'inactive' },
        { label: '🚧 Bảo trì', value: 'maintenance' },
    ];

    return (
        <Modal
            open
            onCancel={onClose}
            onOk={handleSubmit}
            okText={category?.id ? "Cập nhật" : "Tạo mới"}
            cancelText="Hủy"
            centered
            className="custom-modal rounded-xl overflow-hidden"
            title={
                <div className="flex items-center gap-3 text-lg font-semibold text-gray-800 bg-gradient-to-r from-blue-50 to-purple-50 -mx-6 -mt-4 px-6 py-4 border-b border-gray-100">
                    <div className="w-8 h-8 bg-gradient-to-br from-blue-500 to-purple-600 rounded-lg flex items-center justify-center">
                        <FolderOpen className="w-4 h-4 text-white" />
                    </div>
                    {category?.id ? "Chỉnh sửa danh mục" : "Tạo danh mục mới"}
                </div>
            }
            confirmLoading={processing}
            width={850}
        >
            <Form className="space-y-6" layout="vertical" initialValues={data}>
                <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
                    {/* Game Type Selection */}
                    <Form.Item
                        label={<div className="font-semibold text-gray-700 text-sm">Loại game</div>}
                        name="game_type_id"
                        rules={[{ required: true, message: "Vui lòng chọn loại game!" }]}
                        className="col-span-2"
                    >
                        <Select
                            placeholder="Chọn loại game..."
                            value={data.game_type_id}
                            onChange={(value) => setData("game_type_id", value)}
                            loading={loadingGameTypes}
                            size="large"
                            showSearch
                            optionFilterProp="children"
                            filterOption={(input, option) =>
                                (option?.searchText ?? '').toLowerCase().includes(input.toLowerCase())
                            }
                            className="rounded-xl"
                            style={{ borderRadius: '12px' }}
                            options={gameTypes.map(gameType => ({
                                label: (
                                    <div className="flex items-center gap-2 py-1">
                                        <Gamepad2 className="w-4 h-4 text-blue-500" />
                                        <span className="font-medium">{gameType.name}</span>
                                    </div>
                                ),
                                value: gameType.id,
                                searchText: gameType.name // Add this for filtering
                            }))}
                        />
                    </Form.Item>

                    {/* Category Name */}
                    <Form.Item
                        label={<div className="font-semibold text-gray-700 text-sm">Tên danh mục</div>}
                        name="name"
                        rules={[
                            { required: true, message: "Vui lòng nhập tên danh mục!" },
                            { min: 2, message: "Tên danh mục phải có ít nhất 2 ký tự!" },
                            { max: 100, message: "Tên danh mục không được vượt quá 100 ký tự!" }
                        ]}
                    >
                        <Input
                            placeholder="Nhập tên danh mục..."
                            value={data.name}
                            onChange={handleNameChange}
                            size="large"
                            className="rounded-xl border-gray-200 hover:border-blue-400 focus:border-blue-500 transition-colors"
                            prefix={<FolderOpen className="w-4 h-4 text-gray-400" />}
                        />
                    </Form.Item>

                    {/* Template */}
                    <Form.Item
                        label={<div className="font-semibold text-gray-700 text-sm">Template</div>}
                        name="template"
                    >
                        <Select
                            placeholder="Chọn template hiển thị..."
                            value={data.template}
                            onChange={(value) => setData("template", value)}
                            options={templateOptions}
                            size="large"
                            className="rounded-xl"
                            style={{ borderRadius: '12px' }}
                        />
                    </Form.Item>

                    {/* Status */}
                    <Form.Item
                        label={<div className="font-semibold text-gray-700 text-sm">Trạng thái</div>}
                        name="status"
                    >
                        <Select
                            placeholder="Chọn trạng thái..."
                            value={data.status}
                            onChange={(value) => setData("status", value)}
                            options={statusOptions}
                            size="large"
                            className="rounded-xl"
                            style={{ borderRadius: '12px' }}
                        />
                    </Form.Item>

                    {/* Sort Order */}
                    <Form.Item
                        label={<div className="font-semibold text-gray-700 text-sm">Thứ tự sắp xếp</div>}
                        name="sort_order"
                        rules={[{ type: 'number', min: 0, message: "Thứ tự phải là số không âm!" }]}
                    >
                        <InputNumber
                            className="w-full rounded-xl border-gray-200 hover:border-blue-400 focus:border-blue-500"
                            placeholder="Nhập số thứ tự..."
                            value={data.sort_order}
                            onChange={(value) => setData("sort_order", value || 0)}
                            min={0}
                            max={9999}
                            size="large"
                            controls={{
                                upIcon: <span className="text-blue-500">+</span>,
                                downIcon: <span className="text-blue-500">-</span>
                            }}
                        />
                    </Form.Item>

                    {/* Is Public Switch */}
                    <Form.Item
                        label={<div className="font-semibold text-gray-700 text-sm">Hiển thị công khai</div>}
                        name="is_public"
                        valuePropName="checked"
                    >
                        <div className="flex items-center gap-3 bg-gray-50 p-3 rounded-xl">
                            <Switch
                                checked={data.is_public}
                                onChange={(checked: boolean) => setData("is_public", checked)}
                                checkedChildren="Công khai"
                                unCheckedChildren="Riêng tư"
                                className="shadow-sm"
                                style={{
                                    backgroundColor: data.is_public ? '#3b82f6' : '#d1d5db'
                                }}
                            />
                            <span className="text-sm text-gray-600">
                                {data.is_public ? '🌐 Danh mục sẽ hiển thị công khai' : '🔒 Chỉ admin mới thấy được'}
                            </span>
                        </div>
                    </Form.Item>
                </div>

                {/* Image Section with Tabs */}
                <Form.Item
                    label={<div className="font-semibold text-gray-700 text-sm">Ảnh đại diện</div>}
                >
                    <div className="border border-gray-200 rounded-xl p-4 bg-gray-50">
                        <Tabs
                            activeKey={imageType}
                            onChange={(key) => setImageType(key as 'url' | 'file')}
                            size="small"
                            className="mb-4"
                            items={[
                                {
                                    key: 'url',
                                    label: (
                                        <span className="flex items-center gap-2">
                                            <Link className="w-4 h-4" />
                                            Nhập URL
                                        </span>
                                    ),
                                    children: (
                                        <Input
                                            placeholder="https://example.com/image.jpg"
                                            value={data.image_url}
                                            onChange={(e) => setData("image_url", e.target.value)}
                                            size="large"
                                            className="rounded-xl border-gray-200 hover:border-blue-400 focus:border-blue-500 transition-colors"
                                            prefix={<ImageIcon className="w-4 h-4 text-gray-400" />}
                                        />
                                    )
                                },
                                {
                                    key: 'file',
                                    label: (
                                        <span className="flex items-center gap-2">
                                            <UploadIcon className="w-4 h-4" />
                                            Upload file
                                        </span>
                                    ),
                                    children: (
                                        <div className="space-y-3">
                                            <CustomUpload
                                                value={data.image_file}
                                                onChange={(file) => setData("image_file", file)}
                                                maxSize={50}
                                            />
                                            <div className="text-xs text-gray-500">
                                                Hỗ trợ: JPG, PNG, GIF. Kích thước tối đa: 50MB
                                            </div>
                                        </div>
                                    )
                                }
                            ]}
                        />

                        {/* Preview Section */}
                        {((imageType === 'url' && data.image_url) || (imageType === 'file' && data.image_file)) && (
                            <div className="mt-4 p-3 bg-white rounded-lg border border-gray-200">
                                <p className="text-sm font-medium text-gray-700 mb-2">Preview:</p>
                                <div className="w-24 h-24 rounded-lg overflow-hidden border border-gray-200 bg-gray-100">
                                    {imageType === 'url' && data.image_url ? (
                                        <img
                                            src={data.image_url}
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
                                    ) : imageType === 'file' && data.image_file ? (
                                        <img
                                            src={URL.createObjectURL(data.image_file)}
                                            alt="Preview"
                                            className="w-full h-full object-cover"
                                        />
                                    ) : null}
                                </div>
                            </div>
                        )}
                    </div>
                </Form.Item>

                <div className="bg-gradient-to-r from-blue-50 to-purple-50 p-4 rounded-xl border border-blue-100">
                    <p className="font-semibold text-gray-800 text-sm mb-2">💡 Lưu ý quan trọng:</p>
                    <ul className="text-sm text-gray-600 space-y-1">
                        <li className="flex items-start gap-2">
                            <span className="text-blue-500 mt-0.5">•</span>
                            <span>Template quyết định cách hiển thị danh mục trên frontend</span>
                        </li>
                        <li className="flex items-start gap-2">
                            <span className="text-blue-500 mt-0.5">•</span>
                            <span>Thứ tự sắp xếp: số nhỏ hơn sẽ hiển thị trước</span>
                        </li>
                        <li className="flex items-start gap-2">
                            <span className="text-blue-500 mt-0.5">•</span>
                            <span>Ảnh upload sẽ được lưu trên server, URL sẽ link trực tiếp</span>
                        </li>
                        <li className="flex items-start gap-2">
                            <span className="text-blue-500 mt-0.5">•</span>
                            <span>Danh mục riêng tư chỉ admin mới thấy được</span>
                        </li>
                    </ul>
                </div>
            </Form>
        </Modal>
    );
}