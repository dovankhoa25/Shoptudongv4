import { useEffect, useState } from 'react';
import axios from 'axios';
import { Button, ConfigProvider, Form, Input, InputNumber, Modal, Switch, theme } from 'antd';
import { useTheme } from '@/Providers/ThemeProvider';
import { useToast } from '@/Components/ToastProvider';

export interface CardTypeItem {
    id: number;
    telco: string;
    discount_rate: number;
    status: boolean;
    cards_count: number;
    created_at: string;
    updated_at: string;
}

interface Props {
    cardType: CardTypeItem | null;
    onClose: () => void;
    onSaved: () => void;
}

interface CardTypeForm {
    telco: string;
    discount_rate: number;
    status: boolean;
}

export default function CardTypeModal({ cardType, onClose, onSaved }: Props) {
    const { darkMode } = useTheme();
    const toast = useToast();
    const [form] = Form.useForm<CardTypeForm>();
    const [submitting, setSubmitting] = useState(false);

    useEffect(() => {
        form.setFieldsValue({
            telco: cardType?.telco ?? '',
            discount_rate: cardType?.discount_rate ?? 0,
            status: cardType?.status ?? true,
        });
    }, [cardType, form]);

    const submit = async (values: CardTypeForm) => {
        setSubmitting(true);

        try {
            const payload = { ...values, telco: values.telco.trim().toLowerCase() };
            if (cardType) {
                await axios.put(`/admin/deposits/card-types/${cardType.id}`, payload);
            } else {
                await axios.post('/admin/deposits/card-types', payload);
            }

            toast.success(cardType ? 'Đã cập nhật loại thẻ.' : 'Đã thêm loại thẻ.');
            onSaved();
            onClose();
        } catch (error: unknown) {
            if (axios.isAxiosError(error) && error.response?.data?.errors) {
                form.setFields(Object.entries(error.response.data.errors).map(([name, errors]) => ({
                    name: name as keyof CardTypeForm,
                    errors: errors as string[],
                })));
            } else {
                toast.error('Không thể lưu loại thẻ.');
            }
        } finally {
            setSubmitting(false);
        }
    };

    return (
        <ConfigProvider theme={{ algorithm: darkMode ? theme.darkAlgorithm : theme.defaultAlgorithm }}>
            <Modal
                open
                title={cardType ? 'Cập nhật loại thẻ' : 'Thêm loại thẻ'}
                onCancel={onClose}
                footer={null}
                destroyOnHidden
            >
                <Form<CardTypeForm> form={form} layout="vertical" onFinish={submit} disabled={submitting}>
                    <Form.Item
                        name="telco"
                        label="Mã nhà mạng"
                        extra="Chỉ dùng chữ thường, số, dấu gạch ngang hoặc gạch dưới."
                        rules={[
                            { required: true, whitespace: true, message: 'Vui lòng nhập mã nhà mạng.' },
                            { pattern: /^[a-z0-9_-]+$/, message: 'Mã nhà mạng không đúng định dạng.' },
                            { max: 30, message: 'Tối đa 30 ký tự.' },
                        ]}
                    >
                        <Input placeholder="viettel" autoFocus />
                    </Form.Item>

                    <Form.Item
                        name="discount_rate"
                        label="Chiết khấu"
                        rules={[
                            { required: true, message: 'Vui lòng nhập mức chiết khấu.' },
                            { type: 'number', min: 0, max: 99.99, message: 'Chiết khấu phải từ 0 đến 99,99%.' },
                        ]}
                    >
                        <InputNumber className="w-full" min={0} max={99.99} precision={2} addonAfter="%" />
                    </Form.Item>

                    <Form.Item name="status" label="Cho phép nạp" valuePropName="checked">
                        <Switch checkedChildren="Đang bật" unCheckedChildren="Đã tắt" />
                    </Form.Item>

                    <div className="flex justify-end gap-3 pt-2">
                        <Button onClick={onClose} disabled={submitting}>Hủy</Button>
                        <Button type="primary" htmlType="submit" loading={submitting}>Lưu loại thẻ</Button>
                    </div>
                </Form>
            </Modal>
        </ConfigProvider>
    );
}
