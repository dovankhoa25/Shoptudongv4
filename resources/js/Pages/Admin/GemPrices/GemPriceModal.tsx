// // Admin/GemPrices/GemPriceModal.tsx - Gem Price Modal với Multiplier
// import React, { useState, useEffect } from 'react';
// import { router } from "@inertiajs/react";
// import { Modal, Form, InputNumber, Switch, Button, message, Select, Alert, Slider } from 'antd';
// import { SaveOutlined } from '@ant-design/icons';
// import { IServer } from '@/InterFaces/server';
// import { IGemPrice } from '@/InterFaces/gemprice';
// import { Gem, Calculator, Hash } from 'lucide-react';

// interface GemPriceModalProps {
//     open: boolean;
//     onClose: () => void;
//     gemPrice: IGemPrice | null;
//     servers: IServer[];
// }

// interface FormData {
//     server_id: number;
//     multiplier: number;
//     status: boolean;
// }

// export default function GemPriceModal({ open, onClose, gemPrice, servers }: GemPriceModalProps) {
//     const [form] = Form.useForm<FormData>();
//     const [loading, setLoading] = useState(false);
//     const [selectedServer, setSelectedServer] = useState<IServer | null>(null);
//     const [testAmount, setTestAmount] = useState<number>(10000);

//     // Initialize form data
//     useEffect(() => {
//         if (open) {
//             if (gemPrice) {
//                 form.setFieldsValue({
//                     server_id: gemPrice.server.id,
//                     multiplier: gemPrice.multiplier,
//                     status: gemPrice.status,
//                 });
//                 setSelectedServer(servers.find(s => s.id === gemPrice.server.id) || null);
//             } else {
//                 form.resetFields();
//                 form.setFieldsValue({
//                     status: true,
//                     multiplier: 13, // Default x13
//                 });
//                 setSelectedServer(null);
//             }
//         }
//     }, [open, gemPrice, form, servers]);

//     const handleSubmit = async (values: FormData) => {
//         setLoading(true);

//         const submitData = {
//             server_id: values.server_id,
//             multiplier: values.multiplier,
//             status: values.status,
//         };

//         const url = gemPrice
//             ? `/admin/gem-prices/${gemPrice.id}`
//             : '/admin/gem-prices';

//         const method = gemPrice ? 'put' : 'post';

//         router[method](url, submitData, {
//             onSuccess: () => {
//                 message.success(
//                     gemPrice
//                         ? 'Hệ số giá đã được cập nhật thành công!'
//                         : 'Hệ số giá đã được tạo thành công!'
//                 );
//                 onClose();
//                 form.resetFields();
//             },
//             onError: (errors) => {
//                 console.error('Submission errors:', errors);

//                 const formErrors = Object.keys(errors).map(key => ({
//                     name: key as keyof FormData,
//                     errors: Array.isArray(errors[key]) ? errors[key] : [errors[key]]
//                 }));

//                 form.setFields(formErrors);

//                 message.error('Có lỗi xảy ra. Vui lòng kiểm tra lại thông tin!');
//             },
//             onFinish: () => {
//                 setLoading(false);
//             }
//         });
//     };

//     const handleCancel = () => {
//         form.resetFields();
//         setSelectedServer(null);
//         setTestAmount(10000);
//         onClose();
//     };

//     const handleServerChange = (serverId: number) => {
//         const server = servers.find(s => s.id === serverId);
//         setSelectedServer(server || null);
//     };

//     // Calculate gems based on current multiplier
//     const calculateGems = (amount: number, multiplier: number) => {
//         return Math.floor((amount / 10000) * multiplier * 10);
//     };

//     const currentMultiplier = Form.useWatch('multiplier', form) || 13;

//     // Common multiplier presets
//     const multiplierPresets = [
//         { value: 10, label: 'x10' },
//         { value: 11, label: 'x11' },
//         { value: 12, label: 'x12' },
//         { value: 13, label: 'x13' },
//         { value: 13.5, label: 'x13.5' },
//         { value: 14, label: 'x14' },
//         { value: 15, label: 'x15' },
//         { value: 16, label: 'x16' },
//     ];

//     return (
//         <Modal
//             title={
//                 <div className="flex items-center gap-2">
//                     <div className="w-8 h-8 bg-gradient-to-br from-purple-100 to-pink-100 rounded-lg flex items-center justify-center">
//                         <Gem className="w-5 h-5 text-purple-500" />
//                     </div>
//                     <span>{gemPrice ? 'Chỉnh sửa Hệ số Giá' : 'Thêm Hệ số Giá mới'}</span>
//                 </div>
//             }
//             open={open}
//             onCancel={handleCancel}
//             footer={null}
//             width={700}
//             destroyOnClose
//             maskClosable={!loading}
//             closable={!loading}
//         >
//             <Form
//                 form={form}
//                 layout="vertical"
//                 onFinish={handleSubmit}
//                 disabled={loading}
//                 className="mt-4"
//             >
//                 {/* Server Selection */}
//                 <Form.Item
//                     label="Server"
//                     name="server_id"
//                     rules={[
//                         { required: true, message: 'Vui lòng chọn server!' }
//                     ]}
//                 >
//                     <Select
//                         placeholder="Chọn server"
//                         size="large"
//                         showSearch
//                         optionFilterProp="children"
//                         onChange={handleServerChange}
//                         filterOption={(input, option) =>
//                             (option?.children as unknown as string)?.toLowerCase().includes(input.toLowerCase())
//                         }
//                     >
//                         {servers?.map(server => (
//                             <Select.Option key={server.id} value={server.id}>
//                                 {server.name}
//                             </Select.Option>
//                         ))}
//                     </Select>
//                 </Form.Item>

//                 {/* Multiplier Input with Slider */}
//                 <Form.Item
//                     label={
//                         <span className="flex items-center gap-2">
//                             <Hash className="text-purple-600" />
//                             Hệ số nhân (Multiplier)
//                         </span>
//                     }
//                     name="multiplier"
//                     rules={[
//                         { required: true, message: 'Vui lòng nhập hệ số!' },
//                         { type: 'number', min: 1, message: 'Hệ số phải lớn hơn 1!' },
//                         { type: 'number', max: 100, message: 'Hệ số không được vượt quá 100!' }
//                     ]}
//                 >
//                     <div>
//                         <InputNumber
//                             placeholder="Nhập hệ số"
//                             size="large"
//                             style={{ width: '100%' }}
//                             min={1}
//                             max={100}
//                             step={0.5}
//                             precision={1}
//                             addonBefore="x"
//                             formatter={(value) => `${value}`}
//                         />
//                         <Slider
//                             min={1}
//                             max={30}
//                             step={0.1}
//                             value={currentMultiplier}
//                             onChange={(value) => form.setFieldsValue({ multiplier: value })}
//                             marks={{
//                                 1: 'x1',
//                                 10: 'x10',
//                                 15: 'x15',
//                                 20: 'x20',
//                                 30: 'x30'
//                             }}
//                             className="mt-4"
//                         />
//                     </div>
//                 </Form.Item>

//                 {/* Quick presets */}
//                 <div className="mb-4">
//                     <label className="text-sm text-gray-600 mb-2 block">Chọn nhanh:</label>
//                     <div className="flex flex-wrap gap-2">
//                         {multiplierPresets.map(preset => (
//                             <Button
//                                 key={preset.value}
//                                 size="small"
//                                 onClick={() => form.setFieldsValue({ multiplier: preset.value })}
//                                 className={currentMultiplier === preset.value ? 'border-purple-500 text-purple-600' : ''}
//                             >
//                                 {preset.label}
//                             </Button>
//                         ))}
//                     </div>
//                 </div>

//                 {/* Live Calculator */}
//                 <div className="bg-gradient-to-r from-purple-50 to-pink-50 p-4 rounded-lg mb-4 border border-purple-200">
//                     <h4 className="font-medium text-purple-700 mb-3 flex items-center gap-2">
//                         <Calculator className="w-4 h-4" />
//                         Máy tính ngọc (Hệ số x{currentMultiplier})
//                     </h4>

//                     <div className="space-y-3">
//                         <div>
//                             <label className="text-sm text-gray-600">Nhập số tiền VND để test:</label>
//                             <InputNumber
//                                 value={testAmount}
//                                 onChange={(value) => setTestAmount(value || 0)}
//                                 formatter={(value) => `${value}`.replace(/\B(?=(\d{3})+(?!\d))/g, ',')}
//                                 parser={(value) => value!.replace(/\$\s?|(,*)/g, '') as unknown as number}
//                                 style={{ width: '100%' }}
//                                 size="large"
//                                 min={1000}
//                                 step={1000}
//                                 addonAfter="VND"
//                             />
//                         </div>

//                         <div className="bg-white p-3 rounded-lg">
//                             <div className="flex justify-between items-center">
//                                 <span className="text-gray-600">Số ngọc nhận được:</span>
//                                 <span className="text-2xl font-bold text-purple-600">
//                                     {calculateGems(testAmount, currentMultiplier).toLocaleString()} ngọc
//                                 </span>
//                             </div>
//                         </div>

//                         <div className="text-xs text-gray-500">
//                             Công thức: ({testAmount.toLocaleString()} / 10,000) × {currentMultiplier} × 10 = {calculateGems(testAmount, currentMultiplier).toLocaleString()} ngọc
//                         </div>
//                     </div>
//                 </div>

//                 {/* Status */}
//                 <Form.Item
//                     label="Trạng thái"
//                     name="status"
//                     valuePropName="checked"
//                     help={
//                         <Alert
//                             message="Lưu ý: Khi kích hoạt hệ số mới, các hệ số khác của server này sẽ tự động tắt"
//                             type="info"
//                             showIcon
//                             className="mt-2"
//                         />
//                     }
//                 >
//                     <Switch
//                         checkedChildren="Đang áp dụng"
//                         unCheckedChildren="Không áp dụng"
//                         size="default"
//                     />
//                 </Form.Item>

//                 {/* Submit Buttons */}
//                 <Form.Item className="mb-0 pt-4 border-t">
//                     <div className="flex gap-3 justify-end">
//                         <Button
//                             size="large"
//                             onClick={handleCancel}
//                             disabled={loading}
//                         >
//                             Hủy
//                         </Button>
//                         <Button
//                             type="primary"
//                             size="large"
//                             htmlType="submit"
//                             loading={loading}
//                             icon={<SaveOutlined />}
//                             className="bg-gradient-to-r from-purple-600 to-pink-600 border-0"
//                         >
//                             {gemPrice ? 'Cập nhật' : 'Tạo mới'}
//                         </Button>
//                     </div>
//                 </Form.Item>
//             </Form>
//         </Modal>
//     );
// }
// Admin/GemPrices/GemPriceModal.tsx - Gem Price Modal với Multiplier (Fixed)
import React, { useState, useEffect } from 'react';
import { router } from "@inertiajs/react";
import { Modal, Form, InputNumber, Switch, Button, message, Select, Alert } from 'antd';
import { SaveOutlined } from '@ant-design/icons';
import { IServer } from '@/InterFaces/server';
import { IGemPrice } from '@/InterFaces/gemprice';
import { Gem, Calculator, Hash } from 'lucide-react';

interface GemPriceModalProps {
    open: boolean;
    onClose: () => void;
    gemPrice: IGemPrice | null;
    servers: IServer[];
}

interface FormData {
    server_id: number;
    multiplier: number;
    status: boolean;
}

export default function GemPriceModal({ open, onClose, gemPrice, servers }: GemPriceModalProps) {
    const [form] = Form.useForm<FormData>();
    const [loading, setLoading] = useState(false);
    const [selectedServer, setSelectedServer] = useState<IServer | null>(null);
    const [testAmount, setTestAmount] = useState<number>(10000);

    // Initialize form data
    useEffect(() => {
        if (open) {
            // Reset form errors first
            form.resetFields();

            if (gemPrice) {
                // Set values immediately for update mode
                const serverFound = servers.find(s => s.id === gemPrice.server.id);
                setSelectedServer(serverFound || null);

                form.setFieldsValue({
                    server_id: gemPrice.server.id,
                    multiplier: gemPrice.multiplier,
                    status: gemPrice.status,
                });
            } else {
                setSelectedServer(null);
                form.setFieldsValue({
                    status: true,
                    multiplier: 13, // Default x13
                });
            }
        }
    }, [open, gemPrice, form, servers]);

    const handleSubmit = async (values: FormData) => {
        setLoading(true);

        const submitData = {
            server_id: values.server_id,
            multiplier: values.multiplier,
            status: values.status,
        };

        const url = gemPrice
            ? `/admin/gem-prices/${gemPrice.id}`
            : '/admin/gem-prices';

        const method = gemPrice ? 'put' : 'post';

        router[method](url, submitData, {
            onSuccess: () => {
                message.success(
                    gemPrice
                        ? 'Hệ số giá đã được cập nhật thành công!'
                        : 'Hệ số giá đã được tạo thành công!'
                );
                onClose();
                form.resetFields();
            },
            onError: (errors) => {
                console.error('Submission errors:', errors);

                const formErrors = Object.keys(errors).map(key => ({
                    name: key as keyof FormData,
                    errors: Array.isArray(errors[key]) ? errors[key] : [errors[key]]
                }));

                form.setFields(formErrors);

                message.error('Có lỗi xảy ra. Vui lòng kiểm tra lại thông tin!');
            },
            onFinish: () => {
                setLoading(false);
            }
        });
    };

    const handleCancel = () => {
        form.resetFields();
        setSelectedServer(null);
        setTestAmount(10000);
        onClose();
    };

    const handleServerChange = (serverId: number) => {
        const server = servers.find(s => s.id === serverId);
        setSelectedServer(server || null);
    };

    // Calculate gems based on current multiplier
    const calculateGems = (amount: number, multiplier: number) => {
        return Math.floor((amount / 10000) * multiplier * 10);
    };

    const currentMultiplier = Form.useWatch('multiplier', form) || 13;

    // Common multiplier presets
    const multiplierPresets = [
        { value: 10, label: 'x10' },
        { value: 11, label: 'x11' },
        { value: 12, label: 'x12' },
        { value: 13, label: 'x13' },
        { value: 13.5, label: 'x13.5' },
        { value: 14, label: 'x14' },
        { value: 15, label: 'x15' },
        { value: 16, label: 'x16' },
    ];

    return (
        <Modal
            title={
                <div className="flex items-center gap-2">
                    <div className="w-8 h-8 bg-gradient-to-br from-purple-100 to-pink-100 rounded-lg flex items-center justify-center">
                        <Gem className="w-5 h-5 text-purple-500" />
                    </div>
                    <span>{gemPrice ? 'Chỉnh sửa Hệ số Giá' : 'Thêm Hệ số Giá mới'}</span>
                </div>
            }
            open={open}
            onCancel={handleCancel}
            footer={null}
            width={700}
            destroyOnClose
            maskClosable={!loading}
            closable={!loading}
        >
            <Form
                form={form}
                layout="vertical"
                onFinish={handleSubmit}
                disabled={loading}
                className="mt-4"
                validateTrigger="onSubmit"
            >
                {/* Server Selection */}
                <Form.Item
                    label="Server"
                    name="server_id"
                    rules={[
                        { required: true, message: 'Vui lòng chọn server!' }
                    ]}
                >
                    <Select
                        placeholder="Chọn server"
                        size="large"
                        showSearch
                        optionFilterProp="children"
                        onChange={handleServerChange}
                        filterOption={(input, option) =>
                            (option?.children as unknown as string)?.toLowerCase().includes(input.toLowerCase())
                        }
                    >
                        {servers?.map(server => (
                            <Select.Option key={server.id} value={server.id}>
                                {server.name}
                            </Select.Option>
                        ))}
                    </Select>
                </Form.Item>

                {/* Multiplier Input */}
                <Form.Item
                    label={
                        <span className="flex items-center gap-2">
                            <Hash className="w-4 h-4 text-purple-600" />
                            Hệ số nhân (Multiplier)
                        </span>
                    }
                    name="multiplier"
                    rules={[
                        { required: true, message: 'Vui lòng nhập hệ số!' },
                        {
                            validator: (_, value) => {
                                if (!value) return Promise.reject();
                                if (value < 1) return Promise.reject('Hệ số phải lớn hơn hoặc bằng 1!');
                                if (value > 100) return Promise.reject('Hệ số không được vượt quá 100!');
                                return Promise.resolve();
                            }
                        }
                    ]}
                    validateTrigger="onChange"
                >
                    <InputNumber
                        placeholder="Nhập hệ số (ví dụ: 13, 13.5)"
                        size="large"
                        style={{ width: '100%' }}
                        min={1}
                        max={100}
                        step={0.5}
                        precision={1}
                        addonBefore="x"
                    />
                </Form.Item>

                {/* Quick presets */}
                <div className="mb-4">
                    <label className="text-sm text-gray-600 mb-2 block">Chọn nhanh:</label>
                    <div className="flex flex-wrap gap-2">
                        {multiplierPresets.map(preset => (
                            <Button
                                key={preset.value}
                                size="small"
                                onClick={() => form.setFieldsValue({ multiplier: preset.value })}
                                className={currentMultiplier === preset.value ? 'border-purple-500 text-purple-600' : ''}
                            >
                                {preset.label}
                            </Button>
                        ))}
                    </div>
                </div>

                {/* Live Calculator */}
                <div className="bg-gradient-to-r from-purple-50 to-pink-50 p-4 rounded-lg mb-4 border border-purple-200">
                    <h4 className="font-medium text-purple-700 mb-3 flex items-center gap-2">
                        <Calculator className="w-4 h-4" />
                        Máy tính ngọc (Hệ số x{currentMultiplier})
                    </h4>

                    <div className="space-y-3">
                        <div>
                            <label className="text-sm text-gray-600">Nhập số tiền VND để test:</label>
                            <InputNumber
                                value={testAmount}
                                onChange={(value) => setTestAmount(value || 0)}
                                formatter={(value) => `${value}`.replace(/\B(?=(\d{3})+(?!\d))/g, ',')}
                                parser={(value) => value!.replace(/\$\s?|(,*)/g, '') as unknown as number}
                                style={{ width: '100%' }}
                                size="large"
                                min={1000}
                                step={1000}
                                addonAfter="VND"
                            />
                        </div>

                        <div className="bg-white p-3 rounded-lg">
                            <div className="flex justify-between items-center">
                                <span className="text-gray-600">Số ngọc nhận được:</span>
                                <span className="text-2xl font-bold text-purple-600">
                                    {calculateGems(testAmount, currentMultiplier).toLocaleString()} ngọc
                                </span>
                            </div>
                        </div>

                        <div className="text-xs text-gray-500">
                            Công thức: ({testAmount.toLocaleString()} / 10,000) × {currentMultiplier} × 10 = {calculateGems(testAmount, currentMultiplier).toLocaleString()} ngọc
                        </div>
                    </div>
                </div>

                {/* Status */}
                <Form.Item
                    label="Trạng thái"
                    name="status"
                    valuePropName="checked"
                    help={
                        <Alert
                            message="Lưu ý: Khi kích hoạt hệ số mới, các hệ số khác của server này sẽ tự động tắt"
                            type="info"
                            showIcon
                            className="mt-2"
                        />
                    }
                >
                    <Switch
                        checkedChildren="Đang áp dụng"
                        unCheckedChildren="Không áp dụng"
                        size="default"
                    />
                </Form.Item>

                {/* Submit Buttons */}
                <Form.Item className="mb-0 pt-4 border-t">
                    <div className="flex gap-3 justify-end">
                        <Button
                            size="large"
                            onClick={handleCancel}
                            disabled={loading}
                        >
                            Hủy
                        </Button>
                        <Button
                            type="primary"
                            size="large"
                            htmlType="submit"
                            loading={loading}
                            icon={<SaveOutlined />}
                            className="bg-gradient-to-r from-purple-600 to-pink-600 border-0"
                        >
                            {gemPrice ? 'Cập nhật' : 'Tạo mới'}
                        </Button>
                    </div>
                </Form.Item>
            </Form>
        </Modal>
    );
}