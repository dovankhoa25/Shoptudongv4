import { Modal, Select, Form, Checkbox, Button, Tag, Divider } from "antd";
import { useForm } from "@inertiajs/react";
import { IAttribute } from "@/InterFaces/attribute";
import { ICategoryWithAttributes } from "@/InterFaces/categoryAttribute";
import { useToast } from "@/Components/ToastProvider";
import { useState, useEffect } from "react";
import { Plus, Tag as TagIcon } from "lucide-react";
import axios from "axios";

interface IProps {
    open: boolean;
    onClose: () => void;
    category: ICategoryWithAttributes;
    onSuccess: () => void;
}

export default function AssignAttributesModal({ open, onClose, category, onSuccess }: IProps) {
    const [availableAttributes, setAvailableAttributes] = useState<IAttribute[]>([]);
    const [loading, setLoading] = useState(false);
    const [selectedAttributes, setSelectedAttributes] = useState<number[]>([]);

    // Không cần useForm nữa vì gửi data trực tiếp
    const [processing, setProcessing] = useState(false);

    const { data, setData, post, processing: formProcessing } = useForm({
        category_id: category.id,
        attribute_ids: [] as number[],
    });

    const toast = useToast();

    useEffect(() => {
        if (open) {
            fetchAvailableAttributes();
        }
    }, [open]);

    const fetchAvailableAttributes = async () => {
        setLoading(true);
        try {
            const response = await axios.get('/admin/games/attributes/getattributes');
            const allAttributes = response.data.data || response.data;

            // Filter out already assigned attributes
            const assignedIds = category.attributes?.map(attr => attr.id) || [];
            const available = allAttributes.filter((attr: IAttribute) => !assignedIds.includes(attr.id));

            setAvailableAttributes(available);
        } catch (error) {
            console.error('Error fetching attributes:', error);
            toast.error('Không thể tải danh sách thuộc tính!');
        } finally {
            setLoading(false);
        }
    };

    const handleSubmit = () => {
        if (selectedAttributes.length === 0) {
            toast.error('Vui lòng chọn ít nhất một thuộc tính!');
            return;
        }

        setData('attribute_ids', selectedAttributes);

        post('/admin/games/category-attributes/assign', {

            onSuccess: () => {
                toast.success(`Đã gán ${selectedAttributes.length} thuộc tính cho danh mục "${category.name}"!`);
                onSuccess();
                onClose();
                setSelectedAttributes([]);
            },
            onError: () => {
                toast.error('Gán thuộc tính thất bại. Vui lòng thử lại!');
            }
        });
    };

    const handleAttributeToggle = (attributeId: number, checked: boolean) => {
        const newSelected = checked
            ? [...selectedAttributes, attributeId]     // Thêm vào
            : selectedAttributes.filter(id => id !== attributeId);  // Bỏ ra

        setSelectedAttributes(newSelected);
        setData('attribute_ids', newSelected);
    };

    const handleSelectAll = () => {
        const allIds = availableAttributes.map(attr => attr.id);
        setSelectedAttributes(allIds);     // Update UI
        setData('attribute_ids', allIds);
    };

    const handleClearAll = () => {
        setSelectedAttributes([]);
        setData('attribute_ids', []);
    };

    return (
        <Modal
            open={open}
            onCancel={onClose}
            onOk={handleSubmit}
            okText="Gán thuộc tính"
            cancelText="Hủy"
            centered
            title={
                <div className="flex items-center gap-3 text-lg font-semibold text-gray-800">
                    <div className="w-8 h-8 bg-gradient-to-br from-blue-500 to-green-600 rounded-lg flex items-center justify-center">
                        <Plus className="w-4 h-4 text-white" />
                    </div>
                    Gán thuộc tính cho "{category.name}"
                </div>
            }
            confirmLoading={formProcessing}
            width={600}
        >
            <div className="space-y-4">
                {/* Category info */}
                <div className="bg-blue-50 p-3 rounded-lg">
                    <div className="text-sm font-medium text-gray-700">Danh mục:</div>
                    <div className="text-lg font-semibold text-blue-800">{category.name}</div>
                    <div className="text-sm text-gray-600">
                        Đã có {category.attributes?.length || 0} thuộc tính
                    </div>
                </div>

                {/* Available attributes */}
                <div>
                    <div className="flex items-center justify-between mb-3">
                        <div className="font-medium text-gray-700">
                            Thuộc tính có thể gán ({availableAttributes.length})
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
                            Đang tải danh sách thuộc tính...
                        </div>
                    ) : availableAttributes.length === 0 ? (
                        <div className="text-center py-4 text-gray-500">
                            Tất cả thuộc tính đã được gán cho danh mục này
                        </div>
                    ) : (
                        <div className="max-h-64 overflow-y-auto border border-gray-200 rounded-lg p-3">
                            <div className="space-y-3">
                                {availableAttributes.map((attribute) => (
                                    <div key={attribute.id} className="flex items-start gap-3 p-2 hover:bg-gray-50 rounded">
                                        <Checkbox
                                            checked={selectedAttributes.includes(attribute.id)}
                                            onChange={(e) => handleAttributeToggle(attribute.id, e.target.checked)}
                                        />
                                        <div className="flex-1">
                                            <div className="flex items-center gap-2">
                                                <TagIcon className="w-4 h-4 text-green-600" />
                                                <span className="font-medium text-gray-900">{attribute.name}</span>
                                                <Tag color={attribute.status ? 'green' : 'red'}>
                                                    {attribute.status ? 'Hoạt động' : 'Tạm dừng'}
                                                </Tag>
                                            </div>
                                            {attribute.options && attribute.options.length > 0 && (
                                                <div className="mt-1 flex flex-wrap gap-1">
                                                    {attribute.options.slice(0, 5).map((option) => (
                                                        <Tag
                                                            key={option.id}
                                                            color={option.status ? 'blue' : 'red'}
                                                        >
                                                            {option.option_value || option.option_value}
                                                        </Tag>
                                                    ))}
                                                    {attribute.options.length > 5 && (
                                                        <Tag color="default">
                                                            +{attribute.options.length - 5}
                                                        </Tag>
                                                    )}
                                                </div>
                                            )}
                                        </div>
                                    </div>
                                ))}
                            </div>
                        </div>
                    )}
                </div>

                {/* Selected summary */}
                {selectedAttributes.length > 0 && (
                    <div className="bg-green-50 p-3 rounded-lg">
                        <div className="text-sm font-medium text-green-800">
                            Đã chọn {selectedAttributes.length} thuộc tính
                        </div>
                        <div className="text-xs text-green-600 mt-1">
                            {selectedAttributes.map(id => {
                                const attr = availableAttributes.find(a => a.id === id);
                                return attr?.name;
                            }).join(', ')}
                        </div>
                    </div>
                )}
            </div>
        </Modal>
    );
}