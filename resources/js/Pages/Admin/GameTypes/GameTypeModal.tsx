import { Modal, Form, Input, InputNumber } from "antd";
import { useForm } from "@inertiajs/react";
import { IGameType } from "@/InterFaces/gametype";
import { useToast } from "@/Components/ToastProvider";

interface IProps {
    onClose: () => void;
    gameType?: IGameType | null;
}

export default function GameTypeModal({ onClose, gameType }: IProps) {

    const { data, setData, post, put, processing, reset } = useForm({
        name: gameType?.name || "",
        // slug: gameType?.slug || "",
        icon: gameType?.icon || "",
        sort_order: gameType?.sort_order || 0,
    });

    const toast = useToast();

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();

        // Validate form
        if (!data.name.trim()) {
            toast.error('Vui lòng nhập tên loại game!');
            return;
        }

        // if (!data.slug.trim()) {
        //     toast.error('Vui lòng nhập slug!');
        //     return;
        // }

        const submitAction = gameType?.id
            ? put(`/admin/games/gametypes/${gameType.id}`, {
                onSuccess: () => {
                    toast.success(`Loại game "${data.name}" đã được cập nhật thành công!`);
                    onClose();
                },
                onError: (errors) => {
                    toast.error('Cập nhật loại game thất bại. Vui lòng thử lại!');
                    console.error('Update errors:', errors);
                }
            })
            : post("/admin/games/gametypes", {
                onSuccess: () => {
                    toast.success(`Loại game "${data.name}" đã được tạo thành công!`);
                    onClose();
                    reset();
                },
                onError: (errors) => {
                    toast.error('Tạo loại game thất bại. Vui lòng thử lại!');
                    console.error('Create errors:', errors);
                }
            });
    };

    // Auto generate slug from name
    const handleNameChange = (e: React.ChangeEvent<HTMLInputElement>) => {
        const name = e.target.value;
        setData("name", name);

        // // Auto generate slug if creating new record
        // if (!gameType?.id) {
        //     const slug = name
        //         .toLowerCase()
        //         .trim()
        //         .replace(/[àáạảãâầấậẩẫăằắặẳẵ]/g, 'a')
        //         .replace(/[èéẹẻẽêềếệểễ]/g, 'e')
        //         .replace(/[ìíịỉĩ]/g, 'i')
        //         .replace(/[òóọỏõôồốộổỗơờớợởỡ]/g, 'o')
        //         .replace(/[ùúụủũưừứựửữ]/g, 'u')
        //         .replace(/[ỳýỵỷỹ]/g, 'y')
        //         .replace(/đ/g, 'd')
        //         .replace(/[^a-z0-9\s-]/g, '')
        //         .replace(/\s+/g, '-')
        //         .replace(/-+/g, '-')
        //         .replace(/^-|-$/g, '');
        //     setData("slug", slug);
        // }
    };

    return (
        <Modal
            open
            onCancel={onClose}
            onOk={handleSubmit}
            okText={gameType?.id ? "Cập nhật" : "Tạo mới"}
            cancelText="Hủy"
            centered
            className="custom-modal"
            title={
                <div className="text-lg font-semibold text-gray-800">
                    {gameType?.id ? "Chỉnh sửa loại game" : "Tạo loại game mới"}
                </div>
            }
            confirmLoading={processing}
            width={600}
        >
            <Form className="mt-4" layout="vertical" initialValues={data}>
                <Form.Item
                    label={<div className="font-medium text-gray-700">Tên loại game</div>}
                    name="name"
                    rules={[
                        { required: true, message: "Vui lòng nhập tên loại game!" },
                        { min: 2, message: "Tên loại game phải có ít nhất 2 ký tự!" },
                        { max: 100, message: "Tên loại game không được vượt quá 100 ký tự!" }
                    ]}
                >
                    <Input
                        className="rounded-lg"
                        placeholder="Nhập tên loại game (VD: Bắn súng, Thể thao, RPG...)"
                        value={data.name}
                        onChange={handleNameChange}
                        size="large"
                    />
                </Form.Item>

                {/* <Form.Item
                    label={<div className="font-medium text-gray-700">Slug (URL thân thiện)</div>}
                    name="slug"
                    rules={[
                        { required: true, message: "Vui lòng nhập slug!" },
                        {
                            pattern: /^[a-z0-9-]+$/,
                            message: "Slug chỉ được chứa chữ cái thường, số và dấu gạch ngang!"
                        }
                    ]}
                >
                    <Input
                        className="rounded-lg"
                        placeholder="Slug sẽ được tạo tự động từ tên"
                        value={data.slug}
                        onChange={(e) => setData("slug", e.target.value)}
                        size="large"
                    />
                </Form.Item> */}

                <Form.Item
                    label={<div className="font-medium text-gray-700">Icon (Class CSS hoặc tên icon)</div>}
                    name="icon"
                    rules={[
                        { max: 50, message: "Icon không được vượt quá 50 ký tự!" }
                    ]}
                >
                    <Input
                        className="rounded-lg"
                        placeholder="VD: fas fa-gamepad, lucide-gamepad2, hoặc để trống"
                        value={data.icon}
                        onChange={(e) => setData("icon", e.target.value)}
                        size="large"
                    />
                </Form.Item>

                <Form.Item
                    label={<div className="font-medium text-gray-700">Thứ tự sắp xếp</div>}
                    name="sort_order"
                    rules={[
                        { type: 'number', min: 0, message: "Thứ tự phải là số không âm!" }
                    ]}
                >
                    <InputNumber
                        className="rounded-lg w-full"
                        placeholder="Nhập số thứ tự (0 = hiển thị đầu tiên)"
                        value={data.sort_order}
                        onChange={(value) => setData("sort_order", value || 0)}
                        min={0}
                        max={9999}
                        size="large"
                    />
                </Form.Item>

                <div className="text-sm text-gray-500 mt-4 p-3 bg-gray-50 rounded-lg">
                    <p><strong>Lưu ý:</strong></p>
                    <ul className="list-disc list-inside space-y-1 mt-2">
                        <li>Slug sẽ được tạo tự động từ tên khi tạo mới</li>
                        <li>Icon có thể sử dụng FontAwesome, Lucide hoặc các icon library khác</li>
                        <li>Thứ tự sắp xếp: số nhỏ hơn sẽ hiển thị trước</li>
                    </ul>
                </div>
            </Form>
        </Modal>
    );
}