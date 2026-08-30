// Admin/Withdrawals/MarkPaidModal.tsx
import React, { useState, useMemo } from 'react';
import { Modal, Input, Upload, Button, Alert, Image, Tabs } from 'antd';
import { Wallet, Upload as UploadIcon, QrCode, Copy, CheckCircle } from 'lucide-react';
import { router } from '@inertiajs/react';
import { useToast } from '@/Components/ToastProvider';
import { formatCurrency } from '@/Utils/currencyHelper';
import { IWithdrawalRequest } from '@/InterFaces/withdrawalRequest';

interface MarkPaidModalProps {
    open: boolean;
    onClose: () => void;
    withdrawal: IWithdrawalRequest | null;
}


const BANK_KEYWORDS: Record<string, string[]> = {
    VCB: ['Vietcombank', 'Ngoại thương', 'VCB'],
    TCB: ['Techcombank', 'Kỹ thương', 'TCB'],
    BIDV: ['BIDV', 'Đầu tư và Phát triển', 'BIDV Bank'],
    CTG: ['VietinBank', 'Công Thương', 'Vietin', 'CTG'],
    AGR: ['Agribank', 'Nông nghiệp', 'Agr', 'Agrib'],
    ACB: ['ACB', 'Á Châu'],
    MB: ['MBBank', 'MB Bank', 'Quân đội', 'MB'],
    VPB: ['VPBank', 'Việt Nam Thịnh Vượng', 'VP Bank'],
    TPB: ['TPBank', 'Tiên Phong', 'TP Bank'],
    STB: ['Sacombank', 'Sài Gòn Thương Tín', 'STB'],
    HDB: ['HDBank', 'Phát triển TP.HCM', 'HD Bank'],
    VIB: ['VIB', 'Quốc tế Việt Nam'],
    SHB: ['SHB', 'Sài Gòn - Hà Nội', 'Sai Gon Ha Noi'],
    EIB: ['Eximbank', 'Xuất Nhập khẩu', 'EIB'],
    MSB: ['MSB', 'Hàng Hải', 'Maritime Bank'],
    OCB: ['OCB', 'Phương Đông'],
    PGB: ['PGBank', 'Xăng dầu Petrolimex', 'PG Bank'],
    ABB: ['ABBank', 'An Bình', 'ABB'],
    SCB: ['SCB', 'Sài Gòn', 'Sai Gon Bank'],
    SEAB: ['SeABank', 'Đông Nam Á', 'SEA Bank'],
    NAB: ['Nam A Bank', 'Nam Á', 'NAB'],
    VAB: ['VietABank', 'Việt Á', 'VietA Bank'],
    LPB: ['LienVietPostBank', 'Bưu điện Liên Việt', 'LPB'],
    BVB: ['BaoVietBank', 'Bảo Việt', 'Bao Viet Bank'],
    PVCB: ['PVcomBank', 'Đại Chúng Việt Nam', 'PVcom'],
    KLB: ['KienLongBank', 'Kiên Long', 'Kien Long Bank'],
    VIETBANK: ['VietBank', 'Việt Nam Thương Tín', 'Viet Bank'],
    BAB: ['BacABank', 'Bắc Á', 'BacA Bank'],
    SGB: ['SGB', 'Sài Gòn Công Thương'],
    BVBANK: ['VietCapitalBank', 'Bản Việt', 'Viet Capital Bank'],
    VRB: ['VRB', 'Việt - Nga', 'Vietnam Russia Bank'],
    CAKE: ['CAKE', 'Cake by VPBank'],
    UBANK: ['Ubank', 'Ubank by VPBank'],
    TIMO: ['Timo', 'Timo by VPBank'],
    WVN: ['Woori Bank', 'Woori', 'WVN'],
    PBVN: ['PublicBank', 'Public Bank', 'PBVN'],
    SHBVN: ['Shinhan Bank', 'Shinhan', 'SHB VN'],
    CIMB: ['CIMB Bank', 'CIMB'],
    CBB: ['CBBank', 'Xây dựng Việt Nam', 'CBB'],
    DOB: ['DongA Bank', 'Đông Á', 'DongA'],
    GPB: ['GPBank', 'Dầu khí Toàn Cầu', 'GP Bank'],
    IBK: ['IBK Bank', 'Công nghiệp Hàn Quốc', 'IBK Việt Nam'],
};


const normalizeText = (text: string) =>
    text.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();

const getBankCode = (bankName: string): string => {
    const lowerName = normalizeText(bankName);
    for (const [code, keywords] of Object.entries(BANK_KEYWORDS)) {
        if (keywords.some(k => lowerName.includes(normalizeText(k)))) {
            return code;
        }
    }
    return 'MB'; // fallback
};


export default function MarkPaidModal({ open, onClose, withdrawal }: MarkPaidModalProps) {
    const toast = useToast();
    const [loading, setLoading] = useState(false);
    const [note, setNote] = useState('');
    const [fileList, setFileList] = useState<any[]>([]);

    // Tạo nội dung chuyển khoản
    const transferContent = withdrawal ? `rut ${withdrawal.id}` : '';

    // Generate VietQR URL
    const qrUrl = useMemo(() => {
        if (!withdrawal) return '';

        const bankCode = getBankCode(withdrawal.bank_name);
        const accountNumber = withdrawal.bank_account_number;
        const amount = withdrawal.net_amount;
        const content = encodeURIComponent(transferContent);

        return `https://img.vietqr.io/image/${bankCode}-${accountNumber}-compact2.png?amount=${amount}&addInfo=${content}&accountName=${encodeURIComponent(withdrawal.bank_account_name)}`;
    }, [withdrawal, transferContent]);

    // Early return sau khi đã gọi tất cả hooks
    if (!withdrawal) return null;

    const handleCopy = (text: string, label: string) => {
        navigator.clipboard.writeText(text);
        toast.success(`Đã copy ${label}`);
    };

    const handleSubmit = () => {
        setLoading(true);

        const formData = new FormData();
        if (note) formData.append('note', note);
        if (fileList.length > 0) {
            formData.append('payment_proof', fileList[0].originFileObj);
        }

        router.post(`/admin/withdrawals/${withdrawal.id}/mark-paid`, formData, {
            onSuccess: () => {
                toast.success('Đã đánh dấu là đã thanh toán');
                onClose();
                resetForm();
            },
            onError: (errors: any) => {
                toast.error(errors.message || 'Có lỗi xảy ra');
            },
            onFinish: () => {
                setLoading(false);
            }
        });
    };

    const resetForm = () => {
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
                    <div className="p-2 bg-blue-100 rounded-lg">
                        <Wallet className="w-5 h-5 text-blue-600" />
                    </div>
                    <div>
                        <div className="text-lg font-semibold text-gray-900">
                            Xác nhận thanh toán
                        </div>
                        <div className="text-sm text-gray-500 font-normal mt-0.5">
                            Yêu cầu #{withdrawal.id} - {withdrawal.user.username}
                        </div>
                    </div>
                </div>
            }
            open={open}
            onCancel={handleCancel}
            width={700}
            footer={[
                <Button key="cancel" onClick={handleCancel} disabled={loading}>
                    Đóng
                </Button>,
                <Button
                    key="submit"
                    type="primary"
                    loading={loading}
                    onClick={handleSubmit}
                    className="bg-blue-600 hover:bg-blue-700"
                    icon={<CheckCircle className="w-4 h-4" />}
                >
                    Xác nhận đã thanh toán
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
            <Tabs
                defaultActiveKey="qr"
                items={[
                    {
                        key: 'qr',
                        label: (
                            <span className="flex items-center gap-2">
                                <QrCode className="w-4 h-4" />
                                Mã QR thanh toán
                            </span>
                        ),
                        children: (
                            <div className="space-y-4">
                                <Alert
                                    message="Thông tin chuyển khoản"
                                    description={
                                        <div className="space-y-3 mt-2">
                                            <div className="flex justify-between items-center bg-white rounded p-2">
                                                <span className="text-gray-600">Ngân hàng:</span>
                                                <div className="flex items-center gap-2">
                                                    <span className="font-bold text-blue-600">{withdrawal.bank_name}</span>
                                                    <Button
                                                        size="small"
                                                        type="text"
                                                        icon={<Copy className="w-3 h-3" />}
                                                        onClick={() => handleCopy(withdrawal.bank_name, 'tên ngân hàng')}
                                                    />
                                                </div>
                                            </div>

                                            <div className="flex justify-between items-center bg-white rounded p-2">
                                                <span className="text-gray-600">Số tài khoản:</span>
                                                <div className="flex items-center gap-2">
                                                    <span className="font-mono font-bold">{withdrawal.bank_account_number}</span>
                                                    <Button
                                                        size="small"
                                                        type="text"
                                                        icon={<Copy className="w-3 h-3" />}
                                                        onClick={() => handleCopy(withdrawal.bank_account_number, 'số tài khoản')}
                                                    />
                                                </div>
                                            </div>

                                            <div className="flex justify-between items-center bg-white rounded p-2">
                                                <span className="text-gray-600">Chủ tài khoản:</span>
                                                <div className="flex items-center gap-2">
                                                    <span className="font-medium">{withdrawal.bank_account_name}</span>
                                                    <Button
                                                        size="small"
                                                        type="text"
                                                        icon={<Copy className="w-3 h-3" />}
                                                        onClick={() => handleCopy(withdrawal.bank_account_name, 'tên chủ TK')}
                                                    />
                                                </div>
                                            </div>

                                            <div className="flex justify-between items-center bg-white rounded p-2">
                                                <span className="text-gray-600">Số tiền:</span>
                                                <div className="flex items-center gap-2">
                                                    <span className="font-bold text-green-600 text-lg">{formatCurrency(withdrawal.net_amount)}</span>
                                                    <Button
                                                        size="small"
                                                        type="text"
                                                        icon={<Copy className="w-3 h-3" />}
                                                        onClick={() => handleCopy(withdrawal.net_amount, 'số tiền')}
                                                    />
                                                </div>
                                            </div>

                                            <div className="flex justify-between items-center bg-white rounded p-2">
                                                <span className="text-gray-600">Nội dung:</span>
                                                <div className="flex items-center gap-2">
                                                    <span className="font-mono font-medium text-orange-600">{transferContent}</span>
                                                    <Button
                                                        size="small"
                                                        type="text"
                                                        icon={<Copy className="w-3 h-3" />}
                                                        onClick={() => handleCopy(transferContent, 'nội dung')}
                                                    />
                                                </div>
                                            </div>

                                            {withdrawal.fee && parseFloat(withdrawal.fee) > 0 && (
                                                <div className="text-xs text-gray-500 bg-yellow-50 p-2 rounded">
                                                    <div>Số tiền yêu cầu: {formatCurrency(withdrawal.amount)}</div>
                                                    <div className="text-red-600">Phí rút: -{formatCurrency(withdrawal.fee)}</div>
                                                    <div className="font-semibold text-green-600">Thực nhận: {formatCurrency(withdrawal.net_amount)}</div>
                                                </div>
                                            )}
                                        </div>
                                    }
                                    type="info"
                                    showIcon
                                />

                                <div className="flex flex-col items-center justify-center bg-gradient-to-br from-blue-50 to-purple-50 rounded-xl p-6">
                                    <div className="bg-white p-4 rounded-lg shadow-lg">
                                        <Image
                                            src={qrUrl}
                                            alt="VietQR Code"
                                            width={300}
                                            height={300}
                                            placeholder={
                                                <div className="w-[300px] h-[300px] flex items-center justify-center bg-gray-100">
                                                    <QrCode className="w-12 h-12 text-gray-400 animate-pulse" />
                                                </div>
                                            }
                                            fallback="/images/qr-placeholder.png"
                                        />
                                    </div>
                                    <div className="mt-4 text-center">
                                        <p className="text-sm text-gray-600">
                                            Quét mã QR để chuyển khoản tự động
                                        </p>
                                        <p className="text-xs text-gray-500 mt-1">
                                            Hỗ trợ tất cả app ngân hàng có VietQR
                                        </p>
                                    </div>
                                </div>
                            </div>
                        ),
                    },
                    {
                        key: 'proof',
                        label: (
                            <span className="flex items-center gap-2">
                                <UploadIcon className="w-4 h-4" />
                                Chứng từ thanh toán
                            </span>
                        ),
                        children: (
                            <div className="space-y-4">
                                <div>
                                    <label className="block text-sm font-medium text-gray-700 mb-2">
                                        Ảnh chứng từ chuyển khoản
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

                                <div>
                                    <label className="block text-sm font-medium text-gray-700 mb-2">
                                        Ghi chú thanh toán (tùy chọn)
                                    </label>
                                    <Input.TextArea
                                        value={note}
                                        onChange={(e) => setNote(e.target.value)}
                                        rows={4}
                                        placeholder="Nhập ghi chú về việc thanh toán..."
                                        maxLength={500}
                                        showCount
                                    />
                                </div>
                            </div>
                        ),
                    },
                ]}
            />
        </Modal>
    );
}