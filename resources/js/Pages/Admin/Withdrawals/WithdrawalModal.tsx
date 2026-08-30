// Components/Modals/WithdrawalModal.tsx
import React, { useState } from 'react';
import { Modal, Form, Input, InputNumber, Button, Alert, Select } from 'antd';
import { Wallet, Building2 } from 'lucide-react';
import { formatCurrency } from "@/Utils/currencyHelper";

const { TextArea } = Input;

interface WithdrawalModalProps {
    open: boolean;
    onClose: () => void;
    onSubmit: (data: any) => void;
    loading?: boolean;
}

interface FormData {
    amount: number | null;
    bank_code: string;
    bank_name: string;
    bank_account_number: string;
    bank_account_name: string;
    note_user: string;
}

// Danh sách ngân hàng Việt Nam (chuẩn VietQR, cập nhật 2025)
const VIETNAM_BANKS = [
    { code: 'VCB', name: 'Vietcombank', fullName: 'Ngân hàng TMCP Ngoại thương Việt Nam' },
    { code: 'TCB', name: 'Techcombank', fullName: 'Ngân hàng TMCP Kỹ thương Việt Nam' },
    { code: 'VPB', name: 'VPBank', fullName: 'Ngân hàng TMCP Việt Nam Thịnh Vượng' },
    { code: 'ACB', name: 'ACB', fullName: 'Ngân hàng TMCP Á Châu' },
    { code: 'VIB', name: 'VIB', fullName: 'Ngân hàng TMCP Quốc tế Việt Nam' },
    { code: 'MBB', name: 'MBBank', fullName: 'Ngân hàng TMCP Quân đội' },
    { code: 'CTG', name: 'VietinBank', fullName: 'Ngân hàng TMCP Công thương Việt Nam' },
    { code: 'BIDV', name: 'BIDV', fullName: 'Ngân hàng TMCP Đầu tư và Phát triển Việt Nam' },
    { code: 'VBA', name: 'Agribank', fullName: 'Ngân hàng Nông nghiệp và Phát triển Nông thôn Việt Nam' },
    { code: 'SHB', name: 'SHB', fullName: 'Ngân hàng TMCP Sài Gòn - Hà Nội' },
    { code: 'TPB', name: 'TPBank', fullName: 'Ngân hàng TMCP Tiên Phong' },
    { code: 'MSB', name: 'MSB', fullName: 'Ngân hàng TMCP Hàng Hải Việt Nam' },
    { code: 'STB', name: 'Sacombank', fullName: 'Ngân hàng TMCP Sài Gòn Thương Tín' },
    { code: 'SCB', name: 'SCB', fullName: 'Ngân hàng TMCP Sài Gòn' },
    { code: 'EIB', name: 'Eximbank', fullName: 'Ngân hàng TMCP Xuất Nhập khẩu Việt Nam' },
    { code: 'OCB', name: 'OCB', fullName: 'Ngân hàng TMCP Phương Đông' },
    { code: 'HDB', name: 'HDBank', fullName: 'Ngân hàng TMCP Phát triển TP.HCM' },
    { code: 'VAB', name: 'VietABank', fullName: 'Ngân hàng TMCP Việt Á' },
    { code: 'NAB', name: 'Nam A Bank', fullName: 'Ngân hàng TMCP Nam Á' },
    { code: 'PGB', name: 'PGBank', fullName: 'Ngân hàng TMCP Xăng dầu Petrolimex' },
    { code: 'BVB', name: 'VietCapitalBank', fullName: 'Ngân hàng TMCP Bản Việt' },
    { code: 'ABB', name: 'ABBank', fullName: 'Ngân hàng TMCP An Bình' },
    { code: 'SEAB', name: 'SeABank', fullName: 'Ngân hàng TMCP Đông Nam Á' },
    { code: 'CAKE', name: 'CAKE by VPBank', fullName: 'Ngân hàng số CAKE by VPBank' },
    { code: 'UBANK', name: 'Ubank by VPBank', fullName: 'Ngân hàng số Ubank by VPBank' },
    { code: 'TIMO', name: 'Timo by VPBank', fullName: 'Ngân hàng số Timo by VPBank' },
    { code: 'VRB', name: 'VRB', fullName: 'Ngân hàng Liên doanh Việt - Nga' },
    { code: 'SGB', name: 'SGB', fullName: 'Ngân hàng TMCP Sài Gòn Công Thương' },
    { code: 'BVBANK', name: 'BaoVietBank', fullName: 'Ngân hàng TMCP Bảo Việt' },
    { code: 'LPB', name: 'LienVietPostBank', fullName: 'Ngân hàng TMCP Bưu điện Liên Việt' },
    { code: 'KLB', name: 'KienLongBank', fullName: 'Ngân hàng TMCP Kiên Long' },
    { code: 'PVCB', name: 'PVcomBank', fullName: 'Ngân hàng TMCP Đại Chúng Việt Nam' },
    { code: 'WVN', name: 'Woori Bank', fullName: 'Ngân hàng TNHH MTV Woori Việt Nam' },
    { code: 'VIETBANK', name: 'VietBank', fullName: 'Ngân hàng TMCP Việt Nam Thương Tín' },
    { code: 'BAB', name: 'BacABank', fullName: 'Ngân hàng TMCP Bắc Á' },
    { code: 'PBVN', name: 'PublicBank', fullName: 'Ngân hàng TNHH MTV Public Việt Nam' },
    { code: 'SHBVN', name: 'Shinhan Bank', fullName: 'Ngân hàng TNHH MTV Shinhan Việt Nam' },
    { code: 'CIMB', name: 'CIMB Bank', fullName: 'Ngân hàng TNHH MTV CIMB Việt Nam' },
    { code: 'CBB', name: 'CBBank', fullName: 'Ngân hàng TMCP Xây dựng Việt Nam' },
    { code: 'DOB', name: 'DongA Bank', fullName: 'Ngân hàng TMCP Đông Á' },
    { code: 'GPB', name: 'GPBank', fullName: 'Ngân hàng TMCP Dầu khí Toàn Cầu' },
    { code: 'IBK', name: 'IBK Bank', fullName: 'Ngân hàng Công nghiệp Hàn Quốc (IBK) - Chi nhánh TP.HCM' },
];


const WithdrawalModal: React.FC<WithdrawalModalProps> = ({
    open,
    onClose,
    onSubmit,
    loading = false
}) => {
    const [form] = Form.useForm<FormData>();
    const [amount, setAmount] = useState<number | null>(null);
    const [selectedBank, setSelectedBank] = useState<string>('');

    const handleSubmit = async () => {
        try {
            const values = await form.validateFields();
            onSubmit(values);
        } catch (error) {
            console.error('Validation failed:', error);
        }
    };

    const handleCancel = () => {
        form.resetFields();
        setAmount(null);
        setSelectedBank('');
        onClose();
    };

    const handleAmountChange = (value: number | null) => {
        setAmount(value);
    };

    const handleBankChange = (value: string) => {
        setSelectedBank(value);
        const bank = VIETNAM_BANKS.find(b => b.code === value);
        if (bank) {
            // Tự động điền tên ngân hàng
            form.setFieldsValue({
                bank_name: bank.name
            });
        }
    };

    const handleReset = () => {
        form.resetFields();
        setAmount(null);
        setSelectedBank('');
    };

    return (
        <Modal
            title={
                <div className="flex items-center gap-2">
                    <Wallet className="w-5 h-5 text-blue-600" />
                    <span>Tạo yêu cầu rút tiền</span>
                </div>
            }
            open={open}
            onCancel={handleCancel}
            width={600}
            footer={[
                <Button
                    key="reset"
                    onClick={handleReset}
                    disabled={loading}
                >
                    Làm mới
                </Button>,
                <Button
                    key="cancel"
                    onClick={handleCancel}
                    disabled={loading}
                >
                    Hủy
                </Button>,
                <Button
                    key="submit"
                    type="primary"
                    loading={loading}
                    onClick={handleSubmit}
                    icon={<Wallet className="w-4 h-4" />}
                >
                    Tạo yêu cầu
                </Button>
            ]}
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
                requiredMark={false}
                className="mt-4"
            >
                {/* Số tiền */}
                <Form.Item
                    label={
                        <span className="font-medium">
                            Số tiền rút <span className="text-red-500">*</span>
                        </span>
                    }
                    name="amount"
                    rules={[
                        { required: true, message: 'Vui lòng nhập số tiền' },
                        {
                            type: 'number',
                            min: 10000,
                            message: 'Số tiền tối thiểu là 10,000 VNĐ'
                        }
                    ]}
                >
                    <InputNumber
                        style={{ width: '100%' }}
                        placeholder="Nhập số tiền (tối thiểu 10,000 VNĐ)"
                        prefix="💰"
                        min={10000}
                        step={10000}
                        formatter={(value) => `${value}`.replace(/\B(?=(\d{3})+(?!\d))/g, ',')}
                        // parser={(value) => value!.replace(/\$\s?|(,*)/g, '')}
                        onChange={handleAmountChange}
                        disabled={loading}
                        size="large"
                    />
                </Form.Item>

                {/* Hiển thị số tiền đã format */}
                {amount && amount >= 10000 && (
                    <div className="mb-4 p-3 bg-gradient-to-r from-green-50 to-emerald-50 border border-green-200 rounded-lg">
                        <div className="flex items-center justify-between">
                            <span className="text-sm text-gray-600">Số tiền rút:</span>
                            <span className="text-lg font-semibold text-green-600">
                                {formatCurrency(amount.toString())}
                            </span>
                        </div>
                    </div>
                )}

                {/* Chọn ngân hàng */}
                <Form.Item
                    label={
                        <span className="font-medium">
                            Chọn ngân hàng <span className="text-red-500">*</span>
                        </span>
                    }
                    name="bank_code"
                    rules={[
                        { required: true, message: 'Vui lòng chọn ngân hàng' }
                    ]}
                >
                    <Select
                        showSearch
                        placeholder="Tìm và chọn ngân hàng"
                        onChange={handleBankChange}
                        disabled={loading}
                        size="large"
                        suffixIcon={<Building2 className="w-4 h-4 text-gray-400" />}
                        filterOption={(input, option) => {
                            const label = option?.label as string || '';
                            return label.toLowerCase().includes(input.toLowerCase());
                        }}
                        options={VIETNAM_BANKS.map(bank => ({
                            value: bank.code,
                            label: `${bank.name} - ${bank.fullName}`, // ✅ Dùng string thay vì JSX
                        }))}
                    />
                </Form.Item>

                {/* Tên ngân hàng (hidden - tự động điền) */}
                <Form.Item
                    name="bank_name"
                    hidden
                >
                    <Input />
                </Form.Item>

                {/* Số tài khoản */}
                <Form.Item
                    label={
                        <span className="font-medium">
                            Số tài khoản <span className="text-red-500">*</span>
                        </span>
                    }
                    name="bank_account_number"
                    rules={[
                        { required: true, message: 'Vui lòng nhập số tài khoản' },
                        {
                            pattern: /^[0-9]+$/,
                            message: 'Số tài khoản chỉ được chứa số'
                        },
                        {
                            min: 6,
                            message: 'Số tài khoản phải có ít nhất 6 chữ số'
                        }
                    ]}
                >
                    <Input
                        placeholder="Nhập số tài khoản ngân hàng"
                        prefix="💳"
                        disabled={loading}
                        size="large"
                        maxLength={20}
                    />
                </Form.Item>

                {/* Tên chủ tài khoản */}
                <Form.Item
                    label={
                        <span className="font-medium">
                            Tên chủ tài khoản <span className="text-red-500">*</span>
                        </span>
                    }
                    name="bank_account_name"
                    rules={[
                        { required: true, message: 'Vui lòng nhập tên chủ tài khoản' },
                        {
                            pattern: /^[A-Z\s]+$/,
                            message: 'Tên chủ tài khoản phải viết HOA không dấu'
                        }
                    ]}
                >
                    <Input
                        placeholder="VD: NGUYEN VAN A"
                        prefix="👤"
                        disabled={loading}
                        size="large"
                        maxLength={100}
                        onChange={(e) => {
                            // Tự động chuyển thành chữ HOA
                            const value = e.target.value.toUpperCase();
                            form.setFieldsValue({ bank_account_name: value });
                        }}
                    />
                </Form.Item>

                {/* Ghi chú */}
                <Form.Item
                    label={<span className="font-medium">Ghi chú (tùy chọn)</span>}
                    name="note_user"
                >
                    <TextArea
                        placeholder="Ghi chú thêm cho yêu cầu rút tiền (nếu có)"
                        rows={3}
                        maxLength={500}
                        showCount
                        disabled={loading}
                    />
                </Form.Item>
            </Form>

            {/* Lưu ý */}
            <Alert
                message="📌 Lưu ý quan trọng"
                description={
                    <ul className="mt-2 space-y-1.5 text-xs">
                        <li className="flex items-start gap-2">
                            <span className="text-blue-500 mt-0.5">•</span>
                            <span>Số tiền rút tối thiểu là <strong>10,000 VNĐ</strong></span>
                        </li>
                        <li className="flex items-start gap-2">
                            <span className="text-blue-500 mt-0.5">•</span>
                            <span>Thông tin ngân hàng phải <strong>chính xác</strong> và <strong>khớp với chủ tài khoản</strong></span>
                        </li>
                        <li className="flex items-start gap-2">
                            <span className="text-blue-500 mt-0.5">•</span>
                            <span>Tên chủ tài khoản phải viết <strong>HOA KHÔNG DẤU</strong></span>
                        </li>
                        <li className="flex items-start gap-2">
                            <span className="text-blue-500 mt-0.5">•</span>
                            <span>Yêu cầu sẽ được xử lý trong <strong>1-3 ngày làm việc</strong></span>
                        </li>
                        <li className="flex items-start gap-2">
                            <span className="text-orange-500 mt-0.5">•</span>
                            <span className="text-orange-600">Vui lòng kiểm tra kỹ thông tin trước khi gửi yêu cầu</span>
                        </li>
                    </ul>
                }
                type="info"
                showIcon
                className="mt-4"
            />
        </Modal>
    );
};

export default WithdrawalModal;