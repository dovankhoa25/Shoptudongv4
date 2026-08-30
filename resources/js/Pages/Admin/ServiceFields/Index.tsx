// Admin/ServiceFields/Index.tsx - Service Fields Management
import React, { useState } from 'react';
import { router, usePage } from "@inertiajs/react";
import AdminLayout from "@/Layouts/AdminLayout";
import { IServiceWithFields } from "@/InterFaces/serviceField";
import { IField } from "@/InterFaces/field";
import { PageProps } from "@/types";
import AssignFieldsModal from "./AssignFieldsModal";
import {
    Button, Card, Tag, Input, Breadcrumb,
    Empty, Popconfirm, Badge
} from "antd";
import {
    Search, Plus, Settings, Trash2, Type,
    Wrench, ArrowLeft, Link2, Unlink, Key
} from 'lucide-react';
import { useToast } from "@/Components/ToastProvider";

const { Search: AntSearch } = Input;

export default function ServiceFieldsPage() {
    const { services, selectedService: initialSelected } = usePage<
        PageProps & {
            services: IServiceWithFields[];
            selectedService?: IServiceWithFields;
        }
    >().props;

    const [selectedService, setSelectedService] = useState<IServiceWithFields | null>(
        initialSelected || null
    );
    const [showAssignModal, setShowAssignModal] = useState(false);
    const [searchTerm, setSearchTerm] = useState('');
    const [loading, setLoading] = useState(false);

    const toast = useToast();

    // Filter services based on search
    const filteredServices = services.filter(service =>
        service.name.toLowerCase().includes(searchTerm.toLowerCase())
    );

    const handleServiceSelect = (service: IServiceWithFields) => {
        setSelectedService(service);
        router.get(`/admin/service-fields?service=${service.id}`, {}, {
            preserveState: true,
            preserveScroll: true,
            only: ['selectedService']
        });
    };

    const handleAssignFields = () => {
        setShowAssignModal(true);
    };

    const handleRemoveField = (fieldId: number, fieldLabel: string) => {
        if (!selectedService) return;

        router.delete(`/admin/service-fields/remove`, {
            data: {
                service_id: selectedService.id,
                field_id: fieldId
            },
            onSuccess: () => {
                toast.success(`Đã gỡ trường "${fieldLabel}" khỏi dịch vụ "${selectedService.name}"!`);
                handleRefreshData();
            },
            onError: () => {
                toast.error('Gỡ trường thất bại. Vui lòng thử lại!');
            }
        });
    };

    const handleRefreshData = () => {
        router.visit(`/admin/service-fields?service=${selectedService?.id}`, {
            method: 'get'
        });
    };

    const renderFieldTypeIcon = (type: string) => {
        const typeConfig = {
            text: { icon: Type, color: 'text-blue-600', bg: 'bg-blue-100' },
            textarea: { icon: Type, color: 'text-green-600', bg: 'bg-green-100' },
            number: { icon: Type, color: 'text-purple-600', bg: 'bg-purple-100' },
            select: { icon: Type, color: 'text-orange-600', bg: 'bg-orange-100' }
        };

        const config = typeConfig[type as keyof typeof typeConfig] || typeConfig.text;
        const IconComponent = config.icon;

        return (
            <div className={`w-8 h-8 rounded-lg ${config.bg} flex items-center justify-center`}>
                <IconComponent className={`w-4 h-4 ${config.color}`} />
            </div>
        );
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
            <span className={`inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium ${config.color}`}>
                {config.icon} {config.label}
            </span>
        );
    };

    const ServiceCard = ({ service }: { service: IServiceWithFields }) => (
        <Card
            size="small"
            className={`cursor-pointer transition-all duration-200 ${selectedService?.id === service.id
                ? 'border-blue-500 shadow-md bg-blue-50'
                : 'hover:border-gray-400 hover:shadow-sm'
                }`}
            onClick={() => handleServiceSelect(service)}
        >
            <div className="flex items-center justify-between">
                <div className="flex items-center gap-2">
                    <Wrench className={`w-4 h-4 ${selectedService?.id === service.id ? 'text-blue-600' : 'text-gray-500'
                        }`} />
                    <span className={`font-medium ${selectedService?.id === service.id ? 'text-blue-900' : 'text-gray-900'
                        }`}>
                        {service.name}
                    </span>
                    <Tag color={service.status ? 'green' : 'red'}>
                        {service.status ? 'Hoạt động' : 'Tạm dừng'}
                    </Tag>
                </div>
                <Badge
                    count={service.fields?.length || 0}
                    size="small"
                    style={{ backgroundColor: '#52c41a' }}
                />
            </div>
            <div className="mt-2 text-xs text-gray-500">
                {service.fields?.length || 0} trường dữ liệu
            </div>
        </Card>
    );

    const FieldItem = ({ field }: { field: IField }) => (
        <Card size="small" className="mb-3">
            <div className="flex items-start justify-between">
                <div className="flex-1">
                    <div className="flex items-center gap-2 mb-2">
                        {renderFieldTypeIcon(field.type)}
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

                <Popconfirm
                    title="Gỡ trường dữ liệu"
                    description={`Bạn có chắc muốn gỡ trường "${field.label}" khỏi dịch vụ này?`}
                    onConfirm={() => handleRemoveField(field.id, field.label)}
                    okText="Gỡ"
                    cancelText="Hủy"
                    okButtonProps={{ danger: true }}
                >
                    <Button
                        size="small"
                        type="text"
                        danger
                        icon={<Unlink className="w-3 h-3" />}
                        title="Gỡ trường dữ liệu"
                    />
                </Popconfirm>
            </div>
        </Card>
    );

    return (
        <div className="p-6">
            {/* Breadcrumb */}
            <Breadcrumb className="mb-4">
                <Breadcrumb.Item>
                    <Button
                        type="link"
                        icon={<ArrowLeft className="w-4 h-4" />}
                        onClick={() => router.visit('/admin/services')}
                        className="p-0"
                    >
                        Dịch vụ
                    </Button>
                </Breadcrumb.Item>
                <Breadcrumb.Item>Quản lý trường dữ liệu</Breadcrumb.Item>
                {selectedService && (
                    <Breadcrumb.Item>{selectedService.name}</Breadcrumb.Item>
                )}
            </Breadcrumb>

            {/* Header */}
            <div className="flex items-center justify-between mb-6">
                <div>
                    <h1 className="text-2xl font-bold text-gray-900">Quản lý trường dữ liệu dịch vụ</h1>
                    <p className="text-gray-600 mt-1">Gán và quản lý trường dữ liệu cho từng dịch vụ</p>
                </div>
                {selectedService && (
                    <Button
                        type="primary"
                        icon={<Plus className="w-4 h-4" />}
                        onClick={handleAssignFields}
                        size="large"
                    >
                        Gán trường dữ liệu
                    </Button>
                )}
            </div>

            <div className="grid grid-cols-12 gap-6">
                {/* Left Panel - Services */}
                <div className="col-span-4">
                    <Card title="Danh sách dịch vụ" className="h-full">
                        <div className="mb-4">
                            <AntSearch
                                placeholder="Tìm kiếm dịch vụ..."
                                value={searchTerm}
                                onChange={(e) => setSearchTerm(e.target.value)}
                                allowClear
                            />
                        </div>

                        <div className="space-y-2 max-h-96 overflow-y-auto">
                            {filteredServices.map((service) => (
                                <ServiceCard key={service.id} service={service} />
                            ))}

                            {filteredServices.length === 0 && (
                                <Empty
                                    description="Không tìm thấy dịch vụ nào"
                                    image={Empty.PRESENTED_IMAGE_SIMPLE}
                                />
                            )}
                        </div>
                    </Card>
                </div>

                {/* Right Panel - Fields */}
                <div className="col-span-8">
                    {selectedService ? (
                        <Card
                            title={
                                <div className="flex items-center justify-between">
                                    <div className="flex items-center gap-2">
                                        <Link2 className="w-5 h-5 text-blue-600" />
                                        <span>Trường dữ liệu của "{selectedService.name}"</span>
                                        <Badge
                                            count={selectedService.fields?.length || 0}
                                            style={{ backgroundColor: '#1890ff' }}
                                        />
                                    </div>
                                    <Button
                                        type="text"
                                        icon={<Settings className="w-4 h-4" />}
                                        onClick={handleRefreshData}
                                        title="Làm mới"
                                    />
                                </div>
                            }
                            className="h-full"
                        >
                            {selectedService.fields && selectedService.fields.length > 0 ? (
                                <div className="space-y-3 max-h-96 overflow-y-auto">
                                    {selectedService.fields.map((field) => (
                                        <FieldItem key={field.id} field={field} />
                                    ))}
                                </div>
                            ) : (
                                <Empty
                                    description={
                                        <div className="text-center">
                                            <div>Dịch vụ này chưa có trường dữ liệu nào</div>
                                            <Button
                                                type="primary"
                                                icon={<Plus className="w-4 h-4" />}
                                                onClick={handleAssignFields}
                                                className="mt-3"
                                            >
                                                Gán trường dữ liệu đầu tiên
                                            </Button>
                                        </div>
                                    }
                                    image={Empty.PRESENTED_IMAGE_SIMPLE}
                                />
                            )}
                        </Card>
                    ) : (
                        <Card className="h-full flex items-center justify-center">
                            <Empty
                                description="Chọn một dịch vụ để xem trường dữ liệu"
                                image={Empty.PRESENTED_IMAGE_SIMPLE}
                            />
                        </Card>
                    )}
                </div>
            </div>

            {/* Assign Modal */}
            {showAssignModal && selectedService && (
                <AssignFieldsModal
                    open={showAssignModal}
                    onClose={() => setShowAssignModal(false)}
                    service={selectedService}
                    onSuccess={handleRefreshData}
                />
            )}
        </div>
    );
}

ServiceFieldsPage.layout = (page: React.ReactNode) => (
    <AdminLayout title="Service Fields Management" children={page} />
);