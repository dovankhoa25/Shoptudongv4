// Admin/Withdrawals/ApproveWithdrawalModal.tsx
import React, { useState } from 'react';
import { Modal, Input, InputNumber, Radio, Upload, Button, Space, Alert } from 'antd';
import { CheckCircle, Upload as UploadIcon, DollarSign } from 'lucide-react';
import { router } from '@inertiajs/react';
import { useToast } from '@/Components/ToastProvider';
import { formatCurrency } from '@/Utils/currencyHelper';

interface IWithdrawalRequest {
    id: number;
    amount: string;
    user: {
        username: string;
    };
    bank_name: string;
    bank_account_number: string;
    bank_account_name: string;
}

interface ApproveWithdrawalModalProps {
    open: boolean;
    onClose: () => void;
    withdrawal: IWithdrawalRequest | null;
}

export default function ApproveWithdrawalModal({ open, onClose, withdrawal }: ApproveWithdrawalModalProps) {
    const toast = useToast();
    const [loading, setLoading] = useState(false);
    const [feeType, setFeeType] = useState<'amount' | 'percentage'>('percentage');
    const [feeValue, setFeeValue] = useState<number>(0);
    const [note, setNote] = useState('');
    const [fileList, setFileList] = useState<any[]>([]);

    if (!withdrawal) return null;

    const amount = parseFloat(withdrawal.amount);

    // Tính phí và số tiền thực nhận
    const calculatedFee = feeType === 'percentage'
        ? (amount * feeValue) / 100
        : feeValue;

    const netAmount = amount - calculatedFee;

    const handleSubmit = () => {
        if (netAmount <= 0) {
            toast.error('Số tiền thực nhận phải lớn hơn 0');
            return;
        }

        setLoading(true);

        const formData = new FormData();
        formData.append('fee', feeValue.toString());
        formData.append('fee_type', feeType);
        if (note) formData.append('note', note);
        if (fileList.length > 0) {
            formData.append('payment_proof', fileList[0].originFileObj);
        }

        router.post(`/admin/withdrawals/${withdrawal.id}/approve`, formData, {
            onSuccess: () => {
                toast.success('Đã duyệt yêu cầu rút tiền thành công');
                onClose();
                resetForm();
            },
            onError: (errors) => {
                toast.error(errors.message || 'Có lỗi xảy ra khi duyệt yêu cầu');
            },
            onFinish: () => {
                setLoading(false);
            }
        });
    };

    const resetForm = () => {
        setFeeType('percentage');
        setFeeValue(0);
        setNote('');
        setFileList([]);
    };

    const handleCancel = () => {
        resetForm();
        onClose();
    };

    return (
        <Modal
            title={
                <div className="flex items-center gap-3">
                    <div className="p-2 bg-green-100 rounded-lg">
                        <CheckCircle className="w-5 h-5 text-green-600" />
                    </div>
                    <div>
                        <div className="text-lg font-semibold text-gray-900">
                            Duyệt yêu cầu rút tiền
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
                    loading={loading}
                    onClick={handleSubmit}
                    disabled={netAmount <= 0}
                    className="bg-green-600 hover:bg-green-700"
                    icon={<CheckCircle className="w-4 h-4" />}
                >
                    Xác nhận duyệt
                </Button>,
            ]}

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
            <div className="space-y-4">
                {/* Thông tin yêu cầu */}
                <Alert
                    message="Thông tin yêu cầu rút tiền"
                    description={
                        <div className="space-y-2 mt-2">
                            <div className="flex justify-between">
                                <span className="text-gray-600">Số tiền yêu cầu:</span>
                                <span className="font-bold text-blue-600">{formatCurrency(withdrawal.amount)}</span>
                            </div>
                            <div className="flex justify-between">
                                <span className="text-gray-600">Ngân hàng:</span>
                                <span className="font-medium">{withdrawal.bank_name}</span>
                            </div>
                            <div className="flex justify-between">
                                <span className="text-gray-600">Số tài khoản:</span>
                                <span className="font-mono">{withdrawal.bank_account_number}</span>
                            </div>
                            <div className="flex justify-between">
                                <span className="text-gray-600">Tên tài khoản:</span>
                                <span className="font-medium">{withdrawal.bank_account_name}</span>
                            </div>
                        </div>
                    }
                    type="info"
                    showIcon
                />

                {/* Loại phí */}
                <div>
                    <label className="block text-sm font-medium text-gray-700 mb-2">
                        Loại phí
                    </label>
                    <Radio.Group
                        value={feeType}
                        onChange={(e) => {
                            setFeeType(e.target.value);
                            setFeeValue(0);
                        }}
                        className="w-full"
                    >
                        <Space direction="vertical" className="w-full">
                            <Radio value="percentage">Phí theo phần trăm (%)</Radio>
                            <Radio value="amount">Phí cố định (VNĐ)</Radio>
                        </Space>
                    </Radio.Group>
                </div>

                {/* Giá trị phí */}
                <div>
                    <label className="block text-sm font-medium text-gray-700 mb-2">
                        {feeType === 'percentage' ? 'Phần trăm phí' : 'Số tiền phí'}
                    </label>
                    <InputNumber
                        value={feeValue}
                        onChange={(value) => setFeeValue(value || 0)}
                        min={0}
                        max={feeType === 'percentage' ? 100 : amount}
                        prefix={feeType === 'percentage' ? '%' : '₫'}
                        className="w-full"
                        size="large"
                        formatter={(value) => `${value}`.replace(/\B(?=(\d{3})+(?!\d))/g, ',')}
                    // parser={(value) => value!.replace(/\$\s?|(,*)/g, '')}
                    />
                    {feeType === 'percentage' && (
                        <div className="text-xs text-gray-500 mt-1">
                            Phí tương đương: {formatCurrency(calculatedFee.toString())}
                        </div>
                    )}
                </div>

                {/* Tính toán */}
                <div className="bg-gray-50 rounded-lg p-4 space-y-2">
                    <div className="flex justify-between items-center">
                        <span className="text-gray-600">Số tiền yêu cầu:</span>
                        <span className="font-medium">{formatCurrency(amount.toString())}</span>
                    </div>
                    <div className="flex justify-between items-center text-red-600">
                        <span>Phí rút tiền:</span>
                        <span className="font-medium">- {formatCurrency(calculatedFee.toString())}</span>
                    </div>
                    <div className="border-t border-gray-300 pt-2"></div>
                    <div className="flex justify-between items-center">
                        <span className="font-semibold text-gray-900">Số tiền thực nhận:</span>
                        <span className={`font-bold text-lg ${netAmount > 0 ? 'text-green-600' : 'text-red-600'}`}>
                            {formatCurrency(netAmount.toString())}
                        </span>
                    </div>
                </div>

                {netAmount <= 0 && (
                    <Alert
                        message="Cảnh báo"
                        description="Phí rút tiền không được lớn hơn hoặc bằng số tiền yêu cầu"
                        type="error"
                        showIcon
                    />
                )}

                {/* Ghi chú */}
                <div>
                    <label className="block text-sm font-medium text-gray-700 mb-2">
                        Ghi chú (tùy chọn)
                    </label>
                    <Input.TextArea
                        value={note}
                        onChange={(e) => setNote(e.target.value)}
                        rows={3}
                        placeholder="Nhập ghi chú về việc duyệt yêu cầu..."
                        maxLength={500}
                        showCount
                    />
                </div>

                {/* Upload ảnh chứng từ */}
                <div>
                    <label className="block text-sm font-medium text-gray-700 mb-2">
                        Ảnh chứng từ (tùy chọn)
                    </label>
                    <Upload
                        fileList={fileList}
                        onChange={({ fileList }) => setFileList(fileList)}
                        beforeUpload={() => false}
                        accept="image/*"
                        maxCount={1}
                        listType="picture-card"
                    >
                        {fileList.length === 0 && (
                            <div>
                                <UploadIcon className="w-6 h-6 mx-auto mb-2 text-gray-400" />
                                <div className="text-xs text-gray-500">Tải lên ảnh</div>
                            </div>
                        )}
                    </Upload>
                    <div className="text-xs text-gray-500 mt-1">
                        Chấp nhận: JPG, PNG, GIF. Tối đa 5MB.
                    </div>
                </div>
            </div>
        </Modal>
    );
}