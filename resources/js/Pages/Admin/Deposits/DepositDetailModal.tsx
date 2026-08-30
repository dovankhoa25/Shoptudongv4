import { ConfigProvider, Modal, theme } from 'antd';
import { formatCurrency } from '@/Utils/currencyHelper';
import { useTheme } from '@/Providers/ThemeProvider';

export interface DepositUser {
    id: number;
    username: string;
    email?: string | null;
}

export interface CardDepositItem {
    id: number;
    user: DepositUser | null;
    card_type: { id: number; telco: string } | null;
    declared_value: number;
    value: number | null;
    amount_user: number;
    amount_api: number | null;
    difference: number | null;
    discount_rate_at_time: number;
    code: string;
    serial: string;
    trans_id: string | null;
    status: string;
    loaded_type: boolean;
    note: string | null;
    created_at: string;
    updated_at: string;
}

export interface BankTopupItem {
    id: number;
    user: DepositUser | null;
    provider: string;
    provider_transaction_id: string;
    gateway: string;
    transaction_at: string;
    account_number: string;
    sub_account: string | null;
    payment_code: string;
    content: string | null;
    transfer_type: string;
    amount: number;
    reference_code: string | null;
    accumulated: number | null;
    description: string | null;
    status: string;
    created_at: string;
}

interface Props {
    item: CardDepositItem | BankTopupItem;
    type: 'card' | 'bank';
    onClose: () => void;
}

const Detail = ({ label, value }: { label: string; value: React.ReactNode }) => (
    <div className="rounded-xl border border-slate-200 bg-slate-50/70 p-3 dark:border-slate-700 dark:bg-slate-800/60">
        <p className="text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">{label}</p>
        <div className="mt-1 break-words text-sm font-medium text-slate-900 dark:text-white">{value ?? '—'}</div>
    </div>
);

export default function DepositDetailModal({ item, type, onClose }: Props) {
    const { darkMode } = useTheme();
    const user = item.user;

    return (
        <ConfigProvider theme={{ algorithm: darkMode ? theme.darkAlgorithm : theme.defaultAlgorithm }}>
            <Modal open title={type === 'card' ? `Chi tiết nạp thẻ #${item.id}` : `Chi tiết SePay #${item.id}`} onCancel={onClose} footer={null} width={760}>
                <div className="grid gap-3 sm:grid-cols-2">
                    <Detail label="Người dùng" value={user ? `${user.username} (#${user.id})` : 'Người dùng đã xóa'} />
                    <Detail label="Trạng thái" value={item.status} />

                    {type === 'card' ? (() => {
                        const card = item as CardDepositItem;
                        return <>
                            <Detail label="Nhà mạng" value={card.card_type?.telco.toUpperCase()} />
                            <Detail label="Mã giao dịch đối tác" value={card.trans_id} />
                            <Detail label="Mã thẻ" value={<span className="font-mono">{card.code}</span>} />
                            <Detail label="Serial" value={<span className="font-mono">{card.serial}</span>} />
                            <Detail label="Mệnh giá khai báo" value={formatCurrency(card.declared_value)} />
                            <Detail label="Mệnh giá thực" value={card.value === null ? '—' : formatCurrency(card.value)} />
                            <Detail label="User nhận" value={formatCurrency(card.amount_user)} />
                            <Detail label="Chiết khấu" value={`${card.discount_rate_at_time}%`} />
                            <div className="sm:col-span-2"><Detail label="Ghi chú" value={card.note} /></div>
                        </>;
                    })() : (() => {
                        const bank = item as BankTopupItem;
                        return <>
                            <Detail label="Ngân hàng" value={bank.gateway} />
                            <Detail label="Số tiền" value={formatCurrency(bank.amount)} />
                            <Detail label="ID SePay" value={bank.provider_transaction_id} />
                            <Detail label="Mã tham chiếu" value={bank.reference_code} />
                            <Detail label="Mã thanh toán" value={<span className="font-mono">{bank.payment_code}</span>} />
                            <Detail label="Tài khoản nhận" value={<span className="font-mono">{bank.account_number}</span>} />
                            <Detail label="Thời gian ngân hàng" value={new Date(bank.transaction_at).toLocaleString('vi-VN')} />
                            <Detail label="Số dư ngân hàng" value={bank.accumulated === null ? '—' : formatCurrency(bank.accumulated)} />
                            <div className="sm:col-span-2"><Detail label="Nội dung" value={bank.content} /></div>
                            <div className="sm:col-span-2"><Detail label="Mô tả" value={bank.description} /></div>
                        </>;
                    })()}
                </div>
            </Modal>
        </ConfigProvider>
    );
}
