// Admin/Field/FieldModal.tsx - Field Modal Component with Ant Design
import React, { useState, useEffect } from 'react';
import { router } from "@inertiajs/react";
import { 
    Modal, Form, Input, Select, Switch, Button, message, 
    Space, Card, Typography, Divider, Tag 
} from 'antd';
import { 
    SaveOutlined, PlusOutlined, DeleteOutlined, 
} from '@ant-design/icons';
import { IField } from "@/InterFaces/field";
import { KeyIcon, SettingsIcon, TypeIcon } from 'lucide-react';

const { TextArea } = Input;
const { Option } = Select;
const { Text } = Typography;

interface FieldModalProps {
    open: boolean;
    onClose: () => void;
    field: IField | null;
}

interface FormData {
    label: string;
    field_key: string;
    type: 'text' | 'textarea' | 'number' | 'select';
    options: string[];
    required: boolean;
}

export default function FieldModal({ open, onClose, field }: FieldModalProps) {
    const [form] = Form.useForm<FormData>();
    const [loading, setLoading] = useState(false);
    const [fieldType, setFieldType] = useState<string>('text');

    // Initialize form data
    useEffect(() => {
        if (open) {
            if (field) {
                let parsedOptions: string[] = [];
                
                if (field.options && field.type === 'select') {
                    try {
                        const options = typeof field.options === 'string' 
                            ? JSON.parse(field.options) 
                            : field.options;
                        
                        if (Array.isArray(options)) {
                            parsedOptions = options;
                        }
                    } catch (e) {
                        console.error('Error parsing options:', e);
                    }
                }

                form.setFieldsValue({
                    label: field.label,
                    field_key: field.field_key,
                    type: field.type,
                    options: parsedOptions,
                    required: field.required,
                });
                setFieldType(field.type);
            } else {
                form.resetFields();
                form.setFieldsValue({
                    type: 'text',
                    required: true,
                    options: [],
                });
                setFieldType('text');
            }
        }
    }, [open, field, form]);

    // Auto generate field_key from label
    const handleLabelChange = (e: React.ChangeEvent<HTMLInputElement>) => {
        const label = e.target.value;
        const fieldKey = label
            .toLowerCase()
            .replace(/[^a-z0-9\s]/g, '')
            .replace(/\s+/g, '_')
            .replace(/_{2,}/g, '_')
            .replace(/^_|_$/g, '');
        
        form.setFieldValue('field_key', fieldKey);
    };

    const handleTypeChange = (value: string) => {
        setFieldType(value);
        
        // Clear options when changing from select to other types
        if (value !== 'select') {
            form.setFieldValue('options', []);
        } else {
            // Add default option for select type
            form.setFieldValue('options', ['Tùy chọn 1']);
        }
    };

    const handleSubmit = async (values: FormData) => {
        setLoading(true);

        const submitData = {
            label: values.label.trim(),
            field_key: values.field_key.trim(),
            type: values.type,
            options: values.type === 'select' ? values.options : null,
            required: values.required,
        };

        const url = field 
            ? `/admin/fields/${field.id}`
            : '/admin/fields';

        const method = field ? 'put' : 'post';

        router[method](url, submitData, {
            onSuccess: () => {
                message.success(
                    field 
                        ? `Trường "${values.label}" đã được cập nhật thành công!`
                        : `Trường "${values.label}" đã được tạo thành công!`
                );
                onClose();
                form.resetFields();
            },
            onError: (errors) => {
                console.error('Submission errors:', errors);
                
                // Set form errors
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
        onClose();
    };

    return (
        <Modal
            title={
                <div className="flex items-center gap-2">
                    <SettingsIcon className="text-blue-600" />
                    {field ? 'Chỉnh sửa trường' : 'Thêm trường mới'}
                </div>
            }
            open={open}
            onCancel={handleCancel}
            footer={null}
            width={700}
            destroyOnClose
            maskClosable={!loading}
            closable={!loading}
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
            <Form
                form={form}
                layout="vertical"
                onFinish={handleSubmit}
                disabled={loading}
                className="mt-4"
            >
                <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                    {/* Label */}
                    <Form.Item
                        label="Nhãn trường"
                        name="label"
                        rules={[
                            { required: true, message: 'Vui lòng nhập nhãn trường!' },
                            { min: 2, message: 'Nhãn trường phải có ít nhất 2 ký tự!' },
                            { max: 255, message: 'Nhãn trường không được vượt quá 255 ký tự!' }
                        ]}
                    >
                        <Input
                            placeholder="Nhập nhãn hiển thị"
                            size="large"
                            onChange={handleLabelChange}
                            prefix={<TypeIcon />}
                        />
                    </Form.Item>

                    {/* Field Key */}
                    <Form.Item
                        label="Khóa trường"
                        name="field_key"
                        rules={[
                            { required: true, message: 'Vui lòng nhập khóa trường!' },
                            { pattern: /^[a-z0-9_]+$/, message: 'Khóa trường chỉ được chứa chữ thường, số và dấu gạch dưới!' }
                        ]}
                    >
                        <Input
                            placeholder="auto_generated_key"
                            size="large"
                            prefix={<KeyIcon />}
                        />
                    </Form.Item>
                </div>

                <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                    {/* Type */}
                    <Form.Item
                        label="Loại trường"
                        name="type"
                        rules={[
                            { required: true, message: 'Vui lòng chọn loại trường!' }
                        ]}
                    >
                        <Select
                            placeholder="Chọn loại trường"
                            size="large"
                            onChange={handleTypeChange}
                        >
                            <Option value="text">
                                <Space>
                                    <span>📝</span>
                                    <span>Text - Văn bản ngắn</span>
                                </Space>
                            </Option>
                            <Option value="textarea">
                                <Space>
                                    <span>📄</span>
                                    <span>Textarea - Văn bản dài</span>
                                </Space>
                            </Option>
                            <Option value="number">
                                <Space>
                                    <span>🔢</span>
                                    <span>Number - Số</span>
                                </Space>
                            </Option>
                            <Option value="select">
                                <Space>
                                    <span>📋</span>
                                    <span>Select - Lựa chọn</span>
                                </Space>
                            </Option>
                        </Select>
                    </Form.Item>

                    {/* Required */}
                    <Form.Item
                        label="Trạng thái"
                        name="required"
                        valuePropName="checked"
                    >
                        <Switch
                            checkedChildren="Bắt buộc"
                            unCheckedChildren="Tùy chọn"
                            size="default"
                        />
                    </Form.Item>
                </div>

                {/* Options for Select type */}
                {fieldType === 'select' && (
                    <Card 
                        title="Tùy chọn" 
                        size="small" 
                        className="mb-4"
                        extra={<Tag color="orange">Chỉ áp dụng cho loại Select</Tag>}
                    >
                        <Form.List name="options">
                            {(fields, { add, remove }) => (
                                <>
                                    {fields.map(({ key, name, ...restField }) => (
                                        <Space key={key} style={{ display: 'flex', marginBottom: 8 }} align="baseline">
                                            <Form.Item
                                                {...restField}
                                                name={name}
                                                rules={[{ required: true, message: 'Vui lòng nhập tùy chọn!' }]}
                                                style={{ margin: 0, flex: 1 }}
                                            >
                                                <Input 
                                                    placeholder="Nhập tùy chọn (VD: Bronze, Silver, Gold)" 
                                                    size="large"
                                                />
                                            </Form.Item>
                                            <Button 
                                                type="text" 
                                                danger 
                                                icon={<DeleteOutlined />} 
                                                onClick={() => remove(name)}
                                                disabled={fields.length === 1}
                                            />
                                        </Space>
                                    ))}
                                    <Form.Item>
                                        <Button 
                                            type="dashed" 
                                            onClick={() => add()} 
                                            block 
                                            icon={<PlusOutlined />}
                                        >
                                            Thêm tùy chọn
                                        </Button>
                                    </Form.Item>
                                </>
                            )}
                        </Form.List>
                    </Card>
                )}

                {/* Submit Buttons */}
                <Form.Item className="mb-0 pt-4">
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
                        >
                            {field ? 'Cập nhật' : 'Tạo mới'}
                        </Button>
                    </div>
                </Form.Item>
            </Form>
        </Modal>
    );
}