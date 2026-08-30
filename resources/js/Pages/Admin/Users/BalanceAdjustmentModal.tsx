import { useState } from 'react';
import axios from 'axios';
import { Button, ConfigProvider, Form, Input, InputNumber, Modal, Radio, message, theme } from 'antd';
import { MinusCircleOutlined, PlusCircleOutlined, WalletOutlined } from '@ant-design/icons';
import { IUser } from '@/InterFaces/user';
import { useTheme } from '@/Providers/ThemeProvider';
import { formatCurrency } from '@/Utils/currencyHelper';

interface Props {
    user: IUser;
    onClose: () => void;
    onAdjusted: () => void;
}

interface BalanceFormValues {
    direction: 'credit' | 'debit';
    amount: number;
    description: string;
}

const createIdempotencyKey = (): string => {
    if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') {
        return crypto.randomUUID();
    }

    return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, character => {
        const random = Math.floor(Math.random() * 16);
        const value = character === 'x' ? random : (random & 0x3) | 0x8;
        return value.toString(16);
    });
};

export default function BalanceAdjustmentModal({ user, onClose, onAdjusted }: Props) {
    const { darkMode } = useTheme();
    const [form] = Form.useForm<BalanceFormValues>();
    const [submitting, setSubmitting] = useState(false);
    const [idempotencyKey] = useState(createIdempotencyKey);
    const direction = Form.useWatch('direction', form) ?? 'credit';
    const amount = Number(Form.useWatch('amount', form) ?? 0);
    const currentBalance = Number(user.balance ?? 0);
    const resultingBalance = direction === 'credit'
        ? currentBalance + amount
        : currentBalance - amount;

    const submit = async (values: BalanceFormValues) => {
        setSubmitting(true);

        try {
            const { data } = await axios.post(`/admin/users/${user.id}/balance`, {
                ...values,
                description: values.description.trim(),
                idempotency_key: idempotencyKey,
            });
            message.success(data.message || 'Điều chỉnh số dư thành công.');
            onAdjusted();
            onClose();
        } catch (error: unknown) {
            if (axios.isAxiosError(error)) {
                const errors = error.response?.data?.errors;
                if (errors) {
                    const editableFields = ['direction', 'amount', 'description'];
                    const fieldErrors = Object.entries(errors)
                        .filter(([name]) => editableFields.includes(name))
                        .map(([name, values]) => ({
                            name: name as keyof BalanceFormValues,
                            errors: values as string[],
                        }));

                    if (fieldErrors.length > 0) {
                        form.setFields(fieldErrors);
                    } else {
                        message.error((Object.values(errors)[0] as string[])?.[0] || 'Không thể điều chỉnh số dư.');
                    }
                } else {
                    message.error(error.response?.data?.message || 'Không thể điều chỉnh số dư.');
                }
            } else {
                message.error('Không thể điều chỉnh số dư.');
            }
        } finally {
            setSubmitting(false);
        }
    };

    return (
        <ConfigProvider theme={{ algorithm: darkMode ? theme.darkAlgorithm : theme.defaultAlgorithm }}>
            <Modal
                open
                footer={null}
                width={560}
                title={`Điều chỉnh số dư: ${user.username}`}
                onCancel={submitting ? undefined : onClose}
                maskClosable={!submitting}
            >
                <div className="mb-5 grid grid-cols-2 gap-3 rounded-xl bg-slate-50 p-4 dark:bg-slate-800/70">
                    <div>
                        <p className="text-xs font-medium uppercase tracking-wide text-slate-500">Số dư hiện tại</p>
                        <p className="mt-1 font-semibold text-slate-900 dark:text-white">{formatCurrency(currentBalance)}</p>
                    </div>
                    <div className="text-right">
                        <p className="text-xs font-medium uppercase tracking-wide text-slate-500">Số dư sau điều chỉnh</p>
                        <p className={`mt-1 font-semibold ${resultingBalance < 0 ? 'text-red-600' : direction === 'credit' ? 'text-emerald-600' : 'text-amber-600'}`}>
                            {formatCurrency(resultingBalance)}
                        </p>
                    </div>
                </div>

                <Form<BalanceFormValues>
                    form={form}
                    layout="vertical"
                    disabled={submitting}
                    initialValues={{ direction: 'credit' }}
                    onFinish={submit}
                >
                    <Form.Item name="direction" label="Thao tác" rules={[{ required: true }]}>
                        <Radio.Group optionType="button" buttonStyle="solid" className="w-full">
                            <Radio.Button value="credit" className="w-1/2 text-center">
                                <PlusCircleOutlined /> Cộng tiền
                            </Radio.Button>
                            <Radio.Button value="debit" className="w-1/2 text-center">
                                <MinusCircleOutlined /> Trừ tiền
                            </Radio.Button>
                        </Radio.Group>
                    </Form.Item>

                    <Form.Item
                        name="amount"
                        label="Số tiền"
                        dependencies={['direction']}
                        rules={[
                            { required: true, message: 'Vui lòng nhập số tiền.' },
                            { type: 'number', min: 1, max: 999999999999, message: 'Số tiền phải từ 1 đến 999.999.999.999 VND.' },
                            ({ getFieldValue }) => ({
                                validator(_, value) {
                                    if (getFieldValue('direction') !== 'debit' || !value || value <= currentBalance) {
                                        return Promise.resolve();
                                    }
                                    return Promise.reject(new Error('Không thể trừ nhiều hơn số dư hiện tại.'));
                                },
                            }),
                        ]}
                    >
                        <InputNumber<number>
                            className="w-full"
                            min={1}
                            max={999999999999}
                            precision={0}
                            prefix={<WalletOutlined />}
                            addonAfter="VND"
                            formatter={value => `${value ?? ''}`.replace(/\B(?=(\d{3})+(?!\d))/g, '.')}
                            parser={value => Number((value || '').replace(/\./g, ''))}
                        />
                    </Form.Item>

                    <Form.Item
                        name="description"
                        label="Lý do điều chỉnh"
                        rules={[
                            { required: true, whitespace: true, message: 'Vui lòng nhập lý do điều chỉnh.' },
                            { min: 3, max: 1000, message: 'Lý do phải từ 3 đến 1000 ký tự.' },
                        ]}
                    >
                        <Input.TextArea rows={4} showCount maxLength={1000} placeholder="Ví dụ: Bù giao dịch nạp tiền #123..." />
                    </Form.Item>

                    <div className="flex justify-end gap-3 pt-2">
                        <Button onClick={onClose} disabled={submitting}>Hủy</Button>
                        <Button
                            type="primary"
                            danger={direction === 'debit'}
                            htmlType="submit"
                            loading={submitting}
                        >
                            Xác nhận {direction === 'credit' ? 'cộng tiền' : 'trừ tiền'}
                        </Button>
                    </div>
                </Form>
            </Modal>
        </ConfigProvider>
    );
}
