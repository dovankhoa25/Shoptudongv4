// Admin/CategoryServices/AssignServicesModal.tsx - Assign Services Modal
import { Modal, Checkbox, Button, Tag } from "antd";
import { useForm } from "@inertiajs/react";
import { IService } from "@/InterFaces/service";
import { ICategory } from "@/InterFaces/category";
import { useToast } from "@/Components/ToastProvider";
import { useState } from "react";
import { Plus, Wrench, Hash } from "lucide-react";

interface ICategoryWithServices extends ICategory {
    services?: IService[];
}

interface IProps {
    open: boolean;
    onClose: () => void;
    category: ICategoryWithServices;
    allServices: IService[];
    onSuccess: () => void;
}

export default function AssignServicesModal({ open, onClose, category, allServices, onSuccess }: IProps) {
    const [selectedServices, setSelectedServices] = useState<number[]>([]);

    const { data, setData, post, processing: formProcessing } = useForm({
        category_id: category.id,
        service_ids: [] as number[],
    });

    const toast = useToast();

    // Filter out already assigned services
    const assignedIds = category.services?.map(service => service.id) || [];
    const availableServices = allServices.filter(service => !assignedIds.includes(service.id));

    const handleSubmit = () => {
        if (selectedServices.length === 0) {
            toast.error('Vui lòng chọn ít nhất một dịch vụ!');
            return;
        }

        setData('service_ids', selectedServices);

        post('/admin/category-services/assign', {
            onSuccess: () => {
                toast.success(`Đã gán ${selectedServices.length} dịch vụ cho danh mục "${category.name}"!`);
                onSuccess();
                onClose();
                setSelectedServices([]);
            },
            onError: () => {
                toast.error('Gán dịch vụ thất bại. Vui lòng thử lại!');
            }
        });
    };

    const handleServiceToggle = (serviceId: number, checked: boolean) => {
        const newSelected = checked
            ? [...selectedServices, serviceId]
            : selectedServices.filter(id => id !== serviceId);

        setSelectedServices(newSelected);
        setData('service_ids', newSelected);
    };

    const handleSelectAll = () => {
        const allIds = availableServices.map(service => service.id);
        setSelectedServices(allIds);
        setData('service_ids', allIds);
    };

    const handleClearAll = () => {
        setSelectedServices([]);
        setData('service_ids', []);
    };

    return (
        <Modal
            open={open}
            onCancel={onClose}
            onOk={handleSubmit}
            okText="Gán dịch vụ"
            cancelText="Hủy"
            centered
            title={
                <div className="flex items-center gap-3 text-lg font-semibold text-gray-800">
                    <div className="w-8 h-8 bg-gradient-to-br from-blue-500 to-green-600 rounded-lg flex items-center justify-center">
                        <Plus className="w-4 h-4 text-white" />
                    </div>
                    Gán dịch vụ cho "{category.name}"
                </div>
            }
            confirmLoading={formProcessing}
            width={700}
        >
            <div className="space-y-4">
                {/* Category info */}
                <div className="bg-blue-50 p-3 rounded-lg">
                    <div className="text-sm font-medium text-gray-700">Danh mục:</div>
                    <div className="text-lg font-semibold text-blue-800">{category.name}</div>
                    <div className="text-sm text-gray-600 flex items-center gap-2">
                        <span>Đã có {category.services?.length || 0} dịch vụ</span>
                        <Tag color={category.status ? 'green' : 'red'}>
                            {category.status ? 'Hoạt động' : 'Tạm dừng'}
                        </Tag>
                    </div>
                </div>

                {/* Available services */}
                <div>
                    <div className="flex items-center justify-between mb-3">
                        <div className="font-medium text-gray-700">
                            Dịch vụ có thể gán ({availableServices.length})
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

                    {availableServices.length === 0 ? (
                        <div className="text-center py-4 text-gray-500">
                            Tất cả dịch vụ đã được gán cho danh mục này
                        </div>
                    ) : (
                        <div className="max-h-64 overflow-y-auto border border-gray-200 rounded-lg p-3">
                            <div className="space-y-3">
                                {availableServices.map((service) => (
                                    <div key={service.id} className="flex items-start gap-3 p-2 hover:bg-gray-50 rounded">
                                        <Checkbox
                                            checked={selectedServices.includes(service.id)}
                                            onChange={(e) => handleServiceToggle(service.id, e.target.checked)}
                                        />
                                        <div className="flex-1">
                                            <div className="flex items-center gap-2 mb-1">
                                                <Wrench className="w-4 h-4 text-blue-600" />
                                                <span className="font-medium text-gray-900">{service.name}</span>
                                                <Tag color={service.status ? 'green' : 'red'}>
                                                    {service.status ? 'Hoạt động' : 'Tạm dừng'}
                                                </Tag>
                                            </div>
                                            <div className="flex items-center gap-1 text-sm text-gray-500">
                                                <Hash className="w-3 h-3" />
                                                <span>ID: {service.id}</span>
                                            </div>
                                        </div>
                                    </div>
                                ))}
                            </div>
                        </div>
                    )}
                </div>

                {/* Selected summary */}
                {selectedServices.length > 0 && (
                    <div className="bg-green-50 p-3 rounded-lg">
                        <div className="text-sm font-medium text-green-800">
                            Đã chọn {selectedServices.length} dịch vụ
                        </div>
                        <div className="text-xs text-green-600 mt-1">
                            {selectedServices.map(id => {
                                const service = availableServices.find(s => s.id === id);
                                return service?.name;
                            }).join(', ')}
                        </div>
                    </div>
                )}
            </div>
        </Modal>
    );
}