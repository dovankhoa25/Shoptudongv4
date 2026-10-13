import { useRef, useState } from 'react';
import axios from 'axios';
import { Button, ConfigProvider, Form, Input, Modal, theme } from 'antd';
import { useTheme } from '@/Providers/ThemeProvider';
import { useToast } from '@/Components/ToastProvider';
import { formatCurrency } from '@/Utils/currencyHelper';
import { BankTopupItem } from './DepositDetailModal';

interface Props {
    topup: BankTopupItem;
    onClose: () => void;
    onResolved: () => void;
}

export default function ResolveBankTopupModal({ topup, onClose, onResolved }: Props) {
    const { darkMode } = useTheme();
    const toast = useToast();
    const [form] = Form.useForm<{ user_id: string }>();
    const [submitting, setSubmitting] = useState(false);
    const inFlight = useRef(false);

    const submit = async ({ user_id }: { user_id: string }) => {
        if (inFlight.current) return;
        inFlight.current = true;
        setSubmitting(true);
        try {
            const { data } = await axios.post('/admin/deposits/bank-topups/' + topup.id + '/resolve', { user_id: user_id.trim() });
            toast.success(data.message || 'Đã xử lý nạp tiền.');
            onResolved();
            onClose();
        } catch (error: unknown) {
            if (axios.isAxiosError(error)) {
                const errors = error.response?.data?.errors;
                if (errors?.user_id) {
                    form.setFields([{ name: 'user_id', errors: errors.user_id }]);
                } else {
                    toast.error(errors ? (Object.values(errors)[0] as string[])?.[0] : error.response?.data?.message || 'Không thể xử lý đơn nạp tiền.');
                }
                if (error.response?.status === 409) {
                    onResolved();
                    onClose();
                }
            } else {
                toast.error('Không thể xử lý đơn nạp tiền. Bạn có thể thử lại.');
            }
        } finally {
            inFlight.current = false;
            setSubmitting(false);
        }
    };

    return (
        <ConfigProvider theme={{ algorithm: darkMode ? theme.darkAlgorithm : theme.defaultAlgorithm }}>
            <Modal open title={'Xử lý nạp tiền #' + topup.id} footer={null} width={560}
                onCancel={submitting ? undefined : onClose} maskClosable={!submitting} closable={!submitting} keyboard={!submitting}>
                <div className="mb-4 space-y-2 rounded-xl bg-slate-50 p-4 dark:bg-slate-800">
                    <p className="text-sm">Số tiền cộng: <strong className="text-emerald-600">{formatCurrency(topup.amount)}</strong></p>
                    <p className="break-all text-xs text-slate-500">ID giao dịch: {topup.provider_transaction_id}</p>
                    <p className="break-words text-sm">Nội dung: {topup.content?.trim() ? topup.content : 'Không có nội dung'}</p>
                    <p className="text-xs text-amber-700 dark:text-amber-300">{topup.review_reason}</p>
                </div>
                <Form form={form} layout="vertical" disabled={submitting} onFinish={submit}>
                    <Form.Item name="user_id" label="User ID nhận tiền"
                        rules={[{ required: true, whitespace: true, message: 'Nhập user ID nhận tiền.' },
                            { pattern: /^[1-9][0-9]*$/, message: 'User ID phải là số nguyên dương.' }]}>
                        <Input inputMode="numeric" maxLength={19} autoFocus placeholder="Ví dụ: 123" />
                    </Form.Item>
                    <p className="mb-4 text-xs text-slate-500">Xác nhận sẽ cộng toàn bộ số tiền của đơn cho user ID này và chuyển đơn sang Hoàn tất. Lịch sử số dư ghi admin thực hiện cùng nội dung “Xử lý nạp tiền ID #{topup.id}”.</p>
                    <div className="flex justify-end gap-3">
                        <Button onClick={onClose} disabled={submitting}>Hủy</Button>
                        <Button type="primary" htmlType="submit" loading={submitting}>Xác nhận cộng tiền</Button>
                    </div>
                </Form>
            </Modal>
        </ConfigProvider>
    );
}
