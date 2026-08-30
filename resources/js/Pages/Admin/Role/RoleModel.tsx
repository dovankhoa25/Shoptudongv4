import { Modal, Form, Input } from "antd";
import { useForm, router } from "@inertiajs/react";
import { IRole } from "@/InterFaces/role";
import { useToast } from "@/Components/ToastProvider";

interface IProps {
    onClose: () => void;
    role?: IRole | null;
}

export default function RoleModel({ onClose, role }: IProps) {

    const { data, setData, post, put, processing, reset } = useForm({
        name: role?.name || "",
        guard_name: role?.guard_name || "web",
    });

    // const handleSubmit = (e: React.FormEvent) => {
    //     e.preventDefault();
    //     const url = role?.id ? `/admin/roles/${role.id}?_method=PUT` : "/admin/roles";
    //     post(url);

    //     onClose();
    // };
    const toast = useToast();

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();

        // Validate form
        if (!data.name.trim() || !data.guard_name.trim()) {
            return;
        }

        const submitAction = role?.id
            ? put(`/admin/roles/${role.id}`, {
                onSuccess: () => {
                    toast.success(`Quyền "${data.name}" đã được cập nhật thành công!`);
                    onClose();
                },
                onError: (errors) => {
                    toast.error('Cập nhật quyền thất bại. Vui lòng thử lại!');
                }
            })
            : post("/admin/roles", {
                onSuccess: () => {
                    toast.success(`Quyền "${data.name}" đã được tạo thành công!`);
                    onClose();
                    reset();
                },
                onError: (errors) => {
                    toast.error('Tạo quyền thất bại. Vui lòng thử lại!');
                }
            });
    };

    return (
        <Modal
            open
            onCancel={onClose}
            onOk={handleSubmit}
            okText={role?.id ? "Lưu" : "Tạo"}
            cancelText="Huỷ"
            centered
            className="custom-modal bg-blue-500"
            title={<div className="text-primary">{role?.id ? "Sửa" : "Tạo"} danh mục</div>}
            loading={processing}
        >
            <Form className="m-3" layout="vertical" initialValues={data}  >
                <Form.Item
                    label={<div className="font-bold">Tên Quyền</div>}
                    name="name"
                    rules={[{ required: true, message: "Vui lòng không để trống tên!" }]}
                >
                    <Input
                        className="rounded-2xl"
                        placeholder="Nhập name"
                        value={data.name}
                        onChange={(e) => setData("name", e.target.value)}
                    />
                </Form.Item>

                <Form.Item
                    label={<div className="font-bold">Webc</div>}
                    name="guard_name"
                    rules={[{ required: true, message: "Vui lòng không để trống tên!" }]}
                >
                    <Input
                        className="rounded-2xl"
                        placeholder="Nhập guard_name"
                        value={data.guard_name}
                        disabled
                    />
                </Form.Item>

            </Form>

        </ Modal>
    );
}
