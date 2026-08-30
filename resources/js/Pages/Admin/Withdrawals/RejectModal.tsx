// Admin/Withdrawals/RejectModal.tsx
import React, { useState, useEffect } from 'react';
import { Modal, Input, InputNumber, Alert, Button, Switch } from 'antd';
import { XCircle, DollarSign } from 'lucide-react';
import { router } from '@inertiajs/react';
import { useToast } from '@/Components/ToastProvider';
import { formatCurrency } from '@/Utils/currencyHelper';
import { IWithdrawalRequest } from '@/InterFaces/withdrawalRequest';

interface RejectModalProps {
    open: boolean;
    onClose: () => void;
    withdrawal: IWithdrawalRequest | null;
}

export default function RejectModal({ open, onClose, withdrawal }: RejectModalProps) {
    const toast = useToast();
    const [loading, setLoading] = useState(false);
    const [note, setNote] = useState('');
    const [shouldRefund, setShouldRefund] = useState(true);
    const [refundAmount, setRefundAmount] = useState<number>(0);

    // Reset form khi modal mở/đóng
    useEffect(() => {
        if (open && withdrawal) {
            setRefundAmount(parseFloat(withdrawal.amount));
            setShouldRefund(true);
            setNote('');
        }
    }, [open, withdrawal]);

    if (!withdrawal) return null;

    const amount = parseFloat(withdrawal.amount);

    const handleSubmit = () => {
        if (!note.trim()) {
            toast.error('Vui lòng nhập lý do từ chối');
            return;
        }

        if (shouldRefund && refundAmount <= 0) {
            toast.error('Số tiền hoàn phải lớn hơn 0');
            return;
        }

        if (shouldRefund && refundAmount > amount) {
            toast.error('Số tiền hoàn không được vượt quá số tiền yêu cầu');
            return;
        }

        setLoading(true);

        const formData = {
            note: note.trim(),
            refund_amount: shouldRefund ? refundAmount : 0,
        };

        router.post(`/admin/withdrawals/${withdrawal.id}/reject`, formData, {
            onSuccess: () => {
                toast.success('Đã từ chối yêu cầu rút tiền');
                onClose();
                resetForm();
            },
            onError: (errors: any) => {
                toast.error(errors.message || 'Có lỗi xảy ra khi từ chối yêu cầu');
            },
            onFinish: () => {
                setLoading(false);
            }
        });
    };

    const resetForm = () => {
        setNote('');
        setShouldRefund(true);
        setRefundAmount(0);
    };

    const handleCancel = () => {
        resetForm();
        onClose();
    };

    return (
        <Modal
            title={
                <div className="flex items-center gap-3">
                    <div className="p-2 bg-red-100 rounded-lg">
                        <XCircle className="w-5 h-5 text-red-600" />
                    </div>
                    <div>
                        <div className="text-lg font-semibold text-gray-900">
                            Từ chối yêu cầu rút tiền
                        </div>
                        <div className="text-sm text-gray-500 font-normal mt-0.5">
                            Yêu cầu #{withdrawal.id} - {withdrawal.user.username}
                        </div>
                    </div>
                </div>
            }
            open={open}
            onCancel={handleCancel}
            width={600}
            footer={[
                <Button key="cancel" onClick={handleCancel} disabled={loading}>
                    Hủy
                </Button>,
                <Button
                    key="submit"
                    type="primary"
                    danger
                    loading={loading}
                    onClick={handleSubmit}
                    disabled={!note.trim()}
                    icon={<XCircle className="w-4 h-4" />}
                >
                    Xác nhận từ chối
                </Button>,
            ]}
        >
            <div className="space-y-4">
                {/* Thông tin yêu cầu */}
                <Alert
                    message="Thông tin yêu cầu"
                    description={
                        <div className="space-y-2 mt-2">
                            <div className="flex justify-between">
                                <span className="text-gray-600">Người dùng:</span>
                                <span className="font-medium">{withdrawal.user.username}</span>
                            </div>
                            <div className="flex justify-between">
                                <span className="text-gray-600">Số tiền yêu cầu:</span>
                                <span className="font-bold text-blue-600">{formatCurrency(withdrawal.amount)}</span>
                            </div>
                            <div className="flex justify-between">
                                <span className="text-gray-600">Ngân hàng:</span>
                                <span>{withdrawal.bank_name}</span>
                            </div>
                            <div className="flex justify-between">
                                <span className="text-gray-600">Số tài khoản:</span>
                                <span className="font-mono">{withdrawal.bank_account_number}</span>
                            </div>
                        </div>
                    }
                    type="warning"
                    showIcon
                />

                {/* Lý do từ chối */}
                <div>
                    <label className="block text-sm font-medium text-gray-700 mb-2">
                        Lý do từ chối <span className="text-red-500">*</span>
                    </label>
                    <Input.TextArea
                        value={note}
                        onChange={(e) => setNote(e.target.value)}
                        rows={4}
                        placeholder="Nhập lý do từ chối yêu cầu rút tiền (tối thiểu 10 ký tự)..."
                        maxLength={1000}
                        showCount
                        status={note.trim().length > 0 && note.trim().length < 10 ? 'error' : ''}
                    />
                    {note.trim().length > 0 && note.trim().length < 10 && (
                        <div className="text-xs text-red-500 mt-1">
                            Lý do phải có ít nhất 10 ký tự
                        </div>
                    )}
                </div>

                <div className="border-t border-gray-200 pt-4"></div>

                {/* Toggle hoàn tiền */}
                <div className="flex items-center justify-between bg-gray-50 rounded-lg p-4">
                    <div>
                        <div className="font-medium text-gray-900">Hoàn tiền về tài khoản</div>
                        <div className="text-xs text-gray-500 mt-1">
                            Tự động hoàn tiền vào số dư của người dùng
                        </div>
                    </div>
                    <Switch
                        checked={shouldRefund}
                        onChange={setShouldRefund}
                        checkedChildren="Bật"
                        unCheckedChildren="Tắt"
                    />
                </div>

                {/* Số tiền hoàn */}
                {shouldRefund && (
                    <div>
                        <label className="block text-sm font-medium text-gray-700 mb-2">
                            Số tiền hoàn lại
                        </label>
                        <InputNumber
                            value={refundAmount}
                            onChange={(value) => setRefundAmount(value || 0)}
                            min={0}
                            max={amount}
                            prefix={<DollarSign className="w-4 h-4 text-gray-400" />}
                            className="w-full"
                            size="large"
                            formatter={(value) => `${value}`.replace(/\B(?=(\d{3})+(?!\d))/g, ',')}
                        // parser={(value) => value!.replace(/\$\s?|(,*)/g, '')}
                        />
                        <div className="flex items-center justify-between mt-2">
                            <div className="text-xs text-gray-500">
                                Tối đa: {formatCurrency(amount.toString())}
                            </div>
                            <Button
                                size="small"
                                type="link"
                                onClick={() => setRefundAmount(amount)}
                            >
                                Hoàn toàn bộ
                            </Button>
                        </div>

                        {/* Hiển thị số tiền hoàn */}
                        {refundAmount > 0 && (
                            <Alert
                                message={
                                    <div className="flex items-center justify-between">
                                        <span>Số tiền sẽ hoàn:</span>
                                        <span className="font-bold text-green-600 text-lg">
                                            {formatCurrency(refundAmount.toString())}
                                        </span>
                                    </div>
                                }
                                type="success"
                                showIcon
                                className="mt-3"
                            />
                        )}

                        {/* Cảnh báo nếu hoàn một phần */}
                        {refundAmount > 0 && refundAmount < amount && (
                            <Alert
                                message="Lưu ý"
                                description={`Bạn chỉ hoàn ${formatCurrency(refundAmount.toString())} trong tổng số ${formatCurrency(amount.toString())}. Số tiền còn lại sẽ không được hoàn.`}
                                type="warning"
                                showIcon
                                className="mt-2"
                            />
                        )}
                    </div>
                )}

                {!shouldRefund && (
                    <Alert
                        message="Cảnh báo"
                        description="Bạn đã tắt tính năng hoàn tiền. Số dư của người dùng sẽ không được hoàn lại."
                        type="error"
                        showIcon
                    />
                )}
            </div>
        </Modal>
    );
}