import { Modal, Form, Input, Switch, Select, Button, Space, Tag, Divider } from "antd";
import { router, useForm } from "@inertiajs/react";
import { IAttribute, IAttributeOption } from "@/InterFaces/attribute";
import { useToast } from "@/Components/ToastProvider";
import { useState } from "react";
import { Settings, Plus, Edit3, Trash2, Tag as TagIcon } from "lucide-react";

interface IProps {
    onClose: () => void;
    attribute?: IAttribute | null;
    mode: 'attribute' | 'option';
    selectedAttributeId?: number;
}

export default function AttributeModal({ onClose, attribute, mode, selectedAttributeId }: IProps) {
    const [showOptions, setShowOptions] = useState(false);

    // Form for attribute
    const attributeForm = useForm({
        name: attribute?.name || "",
        status: attribute?.status ?? true,
    });

    // Form for option
    const optionForm = useForm({
        attribute_id: selectedAttributeId || attribute?.id || 0,
        option_value: "",
        status: true as boolean,
    });

    const toast = useToast();

    const handleSubmitAttribute = (e: React.FormEvent) => {
        e.preventDefault();

        if (!attributeForm.data.name.trim()) {
            toast.error('Vui lòng nhập tên thuộc tính!');
            return;
        }

        const submitAction = attribute?.id
            ? attributeForm.put(`/admin/games/attributes/${attribute.id}`, {
                onSuccess: () => {
                    toast.success(`Thuộc tính "${attributeForm.data.name}" đã được cập nhật thành công!`);
                    onClose();
                },
                onError: (errors) => {
                    toast.error('Cập nhật thuộc tính thất bại. Vui lòng thử lại!');
                    console.error('Update errors:', errors);
                }
            })
            : attributeForm.post("/admin/games/attributes", {
                onSuccess: () => {
                    toast.success(`Thuộc tính "${attributeForm.data.name}" đã được tạo thành công!`);
                    onClose();
                    attributeForm.reset();
                },
                onError: (errors) => {
                    toast.error('Tạo thuộc tính thất bại. Vui lòng thử lại!');
                    console.error('Create errors:', errors);
                }
            });
    };

    const handleSubmitOption = (e: React.FormEvent) => {
        e.preventDefault();

        if (!optionForm.data.option_value.trim()) {
            toast.error('Vui lòng nhập giá trị option!');
            return;
        }

        if (!optionForm.data.attribute_id) {
            toast.error('Vui lòng chọn thuộc tính!');
            return;
        }

        optionForm.post("/admin/games/attributes/options", {
            onSuccess: () => {
                toast.success(`Option "${optionForm.data.option_value}" đã được tạo thành công!`);
                onClose();
                optionForm.reset();
            },
            onError: (errors) => {
                toast.error('Tạo option thất bại. Vui lòng thử lại!');
                console.error('Create errors:', errors);
            }
        });
    };

    const handleDeleteOption = (optionId: number, optionValue: string) => {
        if (confirm(`Bạn có chắc chắn muốn xóa option "${optionValue}"?`)) {
            router.delete(`/admin/games/attributes/options/${optionId}`, {
                onSuccess: () => {
                    toast.success(`Option "${optionValue}" đã được xóa thành công!`);
                },
                onError: () => {
                    toast.error('Xóa option thất bại. Vui lòng thử lại!');
                }
            });
        }
    };

    const renderAttributeForm = () => (
        <Form className="space-y-6" layout="vertical" initialValues={attributeForm.data}>
            <Form.Item
                label={<div className="font-semibold text-gray-700 text-sm">Tên thuộc tính</div>}
                name="name"
                rules={[
                    { required: true, message: "Vui lòng nhập tên thuộc tính!" },
                    { min: 2, message: "Tên thuộc tính phải có ít nhất 2 ký tự!" },
                    { max: 100, message: "Tên thuộc tính không được vượt quá 100 ký tự!" }
                ]}
            >
                <Input
                    placeholder="Nhập tên thuộc tính (VD: Màu sắc, Kích thước...)"
                    value={attributeForm.data.name}
                    onChange={(e) => attributeForm.setData("name", e.target.value)}
                    size="large"
                    className="rounded-xl border-gray-200 hover:border-blue-400 focus:border-blue-500 transition-colors"
                    prefix={<TagIcon className="w-4 h-4 text-gray-400" />}
                />
            </Form.Item>

            <Form.Item
                label={<div className="font-semibold text-gray-700 text-sm">Trạng thái</div>}
                name="status"
                valuePropName="checked"
            >
                <div className="flex items-center gap-3 bg-gray-50 p-3 rounded-xl">
                    <Switch
                        checked={attributeForm.data.status}
                        onChange={(checked: boolean) => attributeForm.setData("status", checked)}
                        checkedChildren="Hoạt động"
                        unCheckedChildren="Tạm dừng"
                        className="shadow-sm"
                        style={{
                            backgroundColor: attributeForm.data.status ? '#10b981' : '#d1d5db'
                        }}
                    />
                    <span className="text-sm text-gray-600">
                        {attributeForm.data.status ? '✅ Thuộc tính đang hoạt động' : '⏸️ Thuộc tính tạm dừng'}
                    </span>
                </div>
            </Form.Item>

            {/* Show existing options if editing */}
            {attribute?.options && attribute.options.length > 0 && (
                <div className="bg-gray-50 p-4 rounded-xl">
                    <div className="flex items-center justify-between mb-3">
                        <h4 className="font-semibold text-gray-700 text-sm">Options hiện có ({attribute.options.length})</h4>
                        <Button
                            size="small"
                            onClick={() => setShowOptions(!showOptions)}
                            className="text-blue-600"
                        >
                            {showOptions ? 'Ẩn' : 'Hiện'} options
                        </Button>
                    </div>

                    {showOptions && (
                        <div className="space-y-2 max-h-32 overflow-y-auto">
                            {attribute.options.map((option) => (
                                <div key={option.id} className="flex items-center justify-between bg-white p-2 rounded-lg">
                                    <div className="flex items-center gap-2">
                                        <Tag color={option.status ? 'green' : 'red'}>
                                            {option.option_value}
                                        </Tag>
                                        {!option.status && <span className="text-xs text-gray-400">(Tạm dừng)</span>}
                                    </div>
                                    <Button
                                        size="small"
                                        danger
                                        ghost
                                        icon={<Trash2 className="w-3 h-3" />}
                                        onClick={() => handleDeleteOption(option.id, option.option_value)}
                                    />
                                </div>
                            ))}
                        </div>
                    )}
                </div>
            )}
        </Form>
    );



    const renderOptionForm = () => (
        <Form className="space-y-6" layout="vertical" initialValues={optionForm.data}>
            <Form.Item
                label={<div className="font-semibold text-gray-700 text-sm">Thuộc tính</div>}
                name="attribute_id"
                rules={[{ required: true, message: "Vui lòng chọn thuộc tính!" }]}
            >
                <Select
                    placeholder="Chọn thuộc tính..."
                    value={optionForm.data.attribute_id}
                    onChange={(value) => optionForm.setData("attribute_id", value)}
                    size="large"
                    className="rounded-xl"
                    disabled={!!selectedAttributeId}
                    options={[
                        {
                            label: attribute?.name || "Thuộc tính được chọn",
                            value: selectedAttributeId || attribute?.id
                        }
                    ]}
                />
            </Form.Item>

            <Form.Item
                label={<div className="font-semibold text-gray-700 text-sm">Giá trị option</div>}
                name="option_value"
                rules={[
                    { required: true, message: "Vui lòng nhập giá trị option!" },
                    { min: 1, message: "Giá trị option phải có ít nhất 1 ký tự!" },
                    { max: 50, message: "Giá trị option không được vượt quá 50 ký tự!" }
                ]}
            >
                <Input
                    placeholder="Nhập giá trị option (VD: Đỏ, Xanh, Size M...)"
                    value={optionForm.data.option_value}
                    onChange={(e) => optionForm.setData("option_value", e.target.value)}
                    size="large"
                    className="rounded-xl border-gray-200 hover:border-blue-400 focus:border-blue-500 transition-colors"
                    prefix={<TagIcon className="w-4 h-4 text-gray-400" />}
                />
            </Form.Item>

            <Form.Item
                label={<div className="font-semibold text-gray-700 text-sm">Trạng thái</div>}
                name="status"
                valuePropName="checked"
            >
                <div className="flex items-center gap-3 bg-gray-50 p-3 rounded-xl">
                    <Switch
                        checked={optionForm.data.status}
                        onChange={(checked: boolean) => optionForm.setData("status", checked)}
                        checkedChildren="Hoạt động"
                        unCheckedChildren="Tạm dừng"
                        className="shadow-sm"
                        style={{
                            backgroundColor: optionForm.data.status ? '#10b981' : '#d1d5db'
                        }}
                    />
                    <span className="text-sm text-gray-600">
                        {optionForm.data.status ? '✅ Option đang hoạt động' : '⏸️ Option tạm dừng'}
                    </span>
                </div>
            </Form.Item>
        </Form>
    );

    const getModalTitle = () => {
        if (mode === 'attribute') {
            return attribute?.id ? "Chỉnh sửa thuộc tính" : "Tạo thuộc tính mới";
        } else {
            return "Thêm option mới";
        }
    };

    const getModalIcon = () => {
        if (mode === 'attribute') {
            return attribute?.id ? <Edit3 className="w-4 h-4 text-white" /> : <Plus className="w-4 h-4 text-white" />;
        } else {
            return <TagIcon className="w-4 h-4 text-white" />;
        }
    };

    const processing = mode === 'attribute' ? attributeForm.processing : optionForm.processing;
    const handleSubmit = mode === 'attribute' ? handleSubmitAttribute : handleSubmitOption;

    return (
        <Modal
            open
            onCancel={onClose}
            onOk={handleSubmit}
            okText={mode === 'attribute' ? (attribute?.id ? "Cập nhật" : "Tạo thuộc tính") : "Tạo option"}
            cancelText="Hủy"
            centered
            className="custom-modal rounded-xl overflow-hidden"
            title={
                <div className="flex items-center gap-3 text-lg font-semibold text-gray-800 bg-gradient-to-r from-green-50 to-blue-50 -mx-6 -mt-4 px-6 py-4 border-b border-gray-100">
                    <div className="w-8 h-8 bg-gradient-to-br from-green-500 to-blue-600 rounded-lg flex items-center justify-center">
                        {getModalIcon()}
                    </div>
                    {getModalTitle()}
                </div>
            }
            confirmLoading={processing}
            width={600}
        >
            {mode === 'attribute' ? renderAttributeForm() : renderOptionForm()}

            <div className="bg-gradient-to-r from-green-50 to-blue-50 p-4 rounded-xl border border-green-100 mt-6">
                <p className="font-semibold text-gray-800 text-sm mb-2">💡 Lưu ý quan trọng:</p>
                <ul className="text-sm text-gray-600 space-y-1">
                    <li className="flex items-start gap-2">
                        <span className="text-green-500 mt-0.5">•</span>
                        <span>{mode === 'attribute'
                            ? 'Thuộc tính là nhóm phân loại (VD: Màu sắc, Kích thước)'
                            : 'Option là giá trị cụ thể của thuộc tính (VD: Đỏ, Xanh)'
                        }</span>
                    </li>
                    <li className="flex items-start gap-2">
                        <span className="text-green-500 mt-0.5">•</span>
                        <span>Trạng thái tạm dừng sẽ ẩn khỏi frontend nhưng vẫn giữ dữ liệu</span>
                    </li>
                    {mode === 'attribute' && (
                        <li className="flex items-start gap-2">
                            <span className="text-green-500 mt-0.5">•</span>
                            <span>Sau khi tạo thuộc tính, bạn có thể thêm options từ bảng danh sách</span>
                        </li>
                    )}
                </ul>
            </div>
        </Modal>
    );
}