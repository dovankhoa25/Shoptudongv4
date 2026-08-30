// Admin/ServiceFields/AssignFieldsModal.tsx - Assign Fields Modal
import { Modal, Select, Form, Checkbox, Button, Tag, Divider } from "antd";
import { useForm } from "@inertiajs/react";
import { IField } from "@/InterFaces/field";
import { IServiceWithFields } from "@/InterFaces/serviceField";
import { useToast } from "@/Components/ToastProvider";
import { useState, useEffect } from "react";
import { Plus, Type, Key } from "lucide-react";
import axios from "axios";

interface IProps {
    open: boolean;
    onClose: () => void;
    service: IServiceWithFields;
    onSuccess: () => void;
}

export default function AssignFieldsModal({ open, onClose, service, onSuccess }: IProps) {
    const [availableFields, setAvailableFields] = useState<IField[]>([]);
    const [loading, setLoading] = useState(false);
    const [selectedFields, setSelectedFields] = useState<number[]>([]);

    const { data, setData, post, processing: formProcessing } = useForm({
        service_id: service.id,
        field_ids: [] as number[],
    });

    const toast = useToast();

    useEffect(() => {
        if (open) {
            fetchAvailableFields();
        }
    }, [open]);

    const fetchAvailableFields = async () => {
        setLoading(true);
        try {
            const response = await axios.get('/admin/fields/getfields');
            const allFields = response.data.data || response.data;

            // Filter out already assigned fields
            const assignedIds = service.fields?.map(field => field.id) || [];
            const available = allFields.filter((field: IField) => !assignedIds.includes(field.id));

            setAvailableFields(available);
        } catch (error) {
            console.error('Error fetching fields:', error);
            toast.error('Không thể tải danh sách trường dữ liệu!');
        } finally {
            setLoading(false);
        }
    };

    const handleSubmit = () => {
        if (selectedFields.length === 0) {
            toast.error('Vui lòng chọn ít nhất một trường dữ liệu!');
            return;
        }

        setData('field_ids', selectedFields);

        post('/admin/service-fields/assign', {
            onSuccess: () => {
                toast.success(`Đã gán ${selectedFields.length} trường dữ liệu cho dịch vụ "${service.name}"!`);
                onSuccess();
                onClose();
                setSelectedFields([]);
            },
            onError: () => {
                toast.error('Gán trường dữ liệu thất bại. Vui lòng thử lại!');
            }
        });
    };

    const handleFieldToggle = (fieldId: number, checked: boolean) => {
        const newSelected = checked
            ? [...selectedFields, fieldId]
            : selectedFields.filter(id => id !== fieldId);

        setSelectedFields(newSelected);
        setData('field_ids', newSelected);
    };

    const handleSelectAll = () => {
        const allIds = availableFields.map(field => field.id);
        setSelectedFields(allIds);
        setData('field_ids', allIds);
    };

    const handleClearAll = () => {
        setSelectedFields([]);
        setData('field_ids', []);
    };

    const renderFieldType = (type: string) => {
        const typeConfig = {
            text: { label: 'Text', color: 'bg-blue-100 text-blue-800', icon: '📝' },
            textarea: { label: 'Textarea', color: 'bg-green-100 text-green-800', icon: '📄' },
            number: { label: 'Number', color: 'bg-purple-100 text-purple-800', icon: '🔢' },
            select: { label: 'Select', color: 'bg-orange-100 text-orange-800', icon: '📋' }
        };

        const config = typeConfig[type as keyof typeof typeConfig] || typeConfig.text;

        return (
            <span className={`inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium ${config.color}`}>
                {config.icon} {config.label}
            </span>
        );
    };

    return (
        <Modal
            open={open}
            onCancel={onClose}
            onOk={handleSubmit}
            okText="Gán trường dữ liệu"
            cancelText="Hủy"
            centered
            title={
                <div className="flex items-center gap-3 text-lg font-semibold text-gray-800">
                    <div className="w-8 h-8 bg-gradient-to-br from-blue-500 to-green-600 rounded-lg flex items-center justify-center">
                        <Plus className="w-4 h-4 text-white" />
                    </div>
                    Gán trường dữ liệu cho "{service.name}"
                </div>
            }
            confirmLoading={formProcessing}
            width={700}
        >
            <div className="space-y-4">
                {/* Service info */}
                <div className="bg-blue-50 p-3 rounded-lg">
                    <div className="text-sm font-medium text-gray-700">Dịch vụ:</div>
                    <div className="text-lg font-semibold text-blue-800">{service.name}</div>
                    <div className="text-sm text-gray-600 flex items-center gap-2">
                        <span>Đã có {service.fields?.length || 0} trường dữ liệu</span>
                        <Tag color={service.status ? 'green' : 'red'} >
                            {service.status ? 'Hoạt động' : 'Tạm dừng'}
                        </Tag>
                    </div>
                </div>

                {/* Available fields */}
                <div>
                    <div className="flex items-center justify-between mb-3">
                        <div className="font-medium text-gray-700">
                            Trường dữ liệu có thể gán ({availableFields.length})
                        </div>
                        <div className="flex gap-2">
                            <Button size="small" onClick={handleSelectAll}>
                                Chọn tất cả
                            </Button>
                            <Button size="small" onClick={handleClearAll}>
                                Bỏ chọn
                            </Button>
                        </div>
                    </div>

                    {loading ? (
                        <div className="text-center py-4 text-gray-500">
                            Đang tải danh sách trường dữ liệu...
                        </div>
                    ) : availableFields.length === 0 ? (
                        <div className="text-center py-4 text-gray-500">
                            Tất cả trường dữ liệu đã được gán cho dịch vụ này
                        </div>
                    ) : (
                        <div className="max-h-64 overflow-y-auto border border-gray-200 rounded-lg p-3">
                            <div className="space-y-3">
                                {availableFields.map((field) => (
                                    <div key={field.id} className="flex items-start gap-3 p-2 hover:bg-gray-50 rounded">
                                        <Checkbox
                                            checked={selectedFields.includes(field.id)}
                                            onChange={(e) => handleFieldToggle(field.id, e.target.checked)}
                                        />
                                        <div className="flex-1">
                                            <div className="flex items-center gap-2 mb-1">
                                                <Type className="w-4 h-4 text-green-600" />
                                                <span className="font-medium text-gray-900">{field.label}</span>
                                                {renderFieldType(field.type)}
                                                {field.required && (
                                                    <Tag color="red" >Bắt buộc</Tag>
                                                )}
                                            </div>
                                            <div className="flex items-center gap-1 text-sm text-gray-500">
                                                <Key className="w-3 h-3" />
                                                <span className="font-mono">{field.field_key}</span>
                                            </div>
                                        </div>
                                    </div>
                                ))}
                            </div>
                        </div>
                    )}
                </div>

                {/* Selected summary */}
                {selectedFields.length > 0 && (
                    <div className="bg-green-50 p-3 rounded-lg">
                        <div className="text-sm font-medium text-green-800">
                            Đã chọn {selectedFields.length} trường dữ liệu
                        </div>
                        <div className="text-xs text-green-600 mt-1">
                            {selectedFields.map(id => {
                                const field = availableFields.find(f => f.id === id);
                                return field?.label;
                            }).join(', ')}
                        </div>
                    </div>
                )}
            </div>
        </Modal>
    );
}