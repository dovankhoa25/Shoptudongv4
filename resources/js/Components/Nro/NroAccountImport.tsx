import { useState } from 'react';
import axios from 'axios';
import { Alert, Button, Collapse, Input, Modal, Space, Table, Tag } from 'antd';

type Reference = { id: number; name: string; name_view?: string };
type Row = { line: number; status: 'valid' | 'invalid' | 'created'; message: string; username?: string; serverName?: string; loginServerName?: string; usageType?: string; categoryName?: string; price?: number; accountId?: number };
type Result = { rows: Row[]; valid: number; invalid: number };
export default function NroAccountImport({ open, onClose, onImported, servers, loginServers, categories }: { open: boolean; onClose: () => void; onImported: () => void; servers: Reference[]; loginServers: Reference[]; categories: Reference[] }) {
    const [text, setText] = useState('');
    const [result, setResult] = useState<Result | null>(null);
    const [previewed, setPreviewed] = useState(false);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const change = (value: string) => { setText(value); setResult(null); setPreviewed(false); setError(''); };
    const run = async (mode: 'preview' | 'import') => {
        setBusy(true); setError('');
        try {
            const { data } = await axios.post<Result>('/admin/nro-shop/accounts/import', { text, mode });
            setResult(data); setPreviewed(mode === 'preview');
            if (mode === 'import') {
                const created = new Set(data.rows.filter(r => r.status === 'created').map(r => r.line));
                setText(text.split(/\r\n|\n|\r/).filter((_, i) => !created.has(i + 1)).join('\n'));
                onImported();
            }
        } catch (e) { setError(axios.isAxiosError(e) ? e.response?.data?.message || 'Không gửi được danh sách. Hãy kiểm tra lại.' : 'Không đọc được dữ liệu.'); }
        finally { setBusy(false); }
    };
    const example = `tai_khoan_mau|mat_khau_mau|${servers[0]?.id || 'ID_SVVIEW'}|${loginServers[0]?.id || 'ID_SVLOGIN'}|0|${categories[0]?.id || 'ID_DANHMUC'}|150000|Mô tả nick|\nacc_kho_mau|mat_khau_mau|${servers[0]?.id || 'ID_SVVIEW'}|${loginServers[0]?.id || 'ID_SVLOGIN'}|1||||`;
    return <Modal title="Thêm danh sách acc" width={1000} open={open} onCancel={onClose} closable={!busy} maskClosable={!busy} footer={null}>
        <p className="mb-2 text-sm">Dán danh sách hoặc chọn file TXT UTF-8. Mỗi dòng một acc, tối đa 200 acc/lần.</p>
        <div className="mb-3 overflow-x-auto rounded-lg bg-slate-100 p-3 text-xs dark:bg-slate-900"><code>tk|mk|svview|svlogin|loại|danh mục|giá bán|mô tả|url image</code><p className="mt-2">0 = bán nguyên nick · 1 = kho đồ. Kho đồ chỉ cần 5 cột đầu. Nick cần danh mục và giá; mô tả/link ảnh có thể để trống.</p><p className="mt-1">Server và danh mục: dùng ID hoặc tên khớp duy nhất. Nếu nội dung có dấu |, bọc cả cột bằng dấu nháy kép.</p></div>
        <Collapse size="small" className="mb-3" items={[{ key: 'refs', label: 'Tra ID server và danh mục được phép đăng', children: <div className="grid gap-3 sm:grid-cols-3">{[['Server hiển thị', servers], ['Server đăng nhập', loginServers], ['Danh mục nick', categories]].map(([title, values]) => <div key={String(title)}><strong className="text-xs">{String(title)}</strong><div className="mt-2 max-h-40 overflow-auto text-xs">{(values as Reference[]).map(v => <p className="mb-1" key={v.id}><b>#{v.id}</b> · {v.name_view || v.name}</p>)}</div></div>)}</div> }]} />
        <Space wrap className="mb-3">
            <label className="cursor-pointer rounded border border-slate-500 px-3 py-1.5 text-xs">Chọn file TXT<input type="file" accept=".txt,text/plain" className="sr-only" disabled={busy} onChange={async e => {
                const file = e.target.files?.[0]; e.target.value = ''; if (!file) return;
                if (!file.name.toLowerCase().endsWith('.txt') || file.size > 1024 * 1024) { setError('Chọn file .txt không quá 1 MB.'); return; }
                try { const value = await file.text(); if (value.includes('\uFFFD')) { setError('File không phải UTF-8. Lưu lại dưới dạng UTF-8 rồi chọn lại.'); return; } change(value.replace(/^\uFEFF/, '')); } catch { setError('Không đọc được file TXT.'); }
            }} /></label>
            <Button size="small" disabled={busy} onClick={() => { const url = URL.createObjectURL(new Blob(['\uFEFF' + example], { type: 'text/plain;charset=utf-8' })); const a = document.createElement('a'); a.href = url; a.download = 'mau-acc-nro.txt'; a.click(); setTimeout(() => URL.revokeObjectURL(url), 1000); }}>Tải mẫu TXT</Button>
            <span className="text-xs text-slate-500">Đăng kí mặc định: Ảo nếu danh mục có option tương ứng.</span>
        </Space>
        <Input.TextArea aria-label="Danh sách acc" rows={6} value={text} disabled={busy} spellCheck={false} autoComplete="off" onChange={e => change(e.target.value)} placeholder={example} className="font-mono text-xs" />
        <p className="my-2 text-xs text-slate-500">{text.split(/\r\n|\n|\r/).filter(line => line.trim()).length} dòng · Mật khẩu không xuất hiện trong bảng kiểm tra. Dòng đã thêm sẽ được bỏ khỏi ô nhập để tránh gửi lại.</p>
        {error && <Alert className="mb-3" type="error" showIcon message={error} />}
        {result && <><Alert className="my-3" showIcon type={result.invalid ? 'warning' : 'success'} message={`${result.valid} ${previewed ? 'dòng hợp lệ' : 'acc đã thêm'} · ${result.invalid} dòng lỗi`} description={result.invalid ? 'Sửa các dòng lỗi rồi kiểm tra lại. Bạn vẫn có thể thêm các dòng hợp lệ.' : undefined} />
            <Table<Row> size="small" rowKey="line" dataSource={result.rows} scroll={{ x: 750, y: 260 }} pagination={{ pageSize: 10, showSizeChanger: false }} columns={[
                { title: 'Dòng', dataIndex: 'line', width: 55 }, { title: 'Tài khoản', dataIndex: 'username', width: 140 },
                { title: 'Server', render: (_, row) => <div className="text-xs">{row.serverName}<p className="text-slate-500">{row.loginServerName}</p></div> },
                { title: 'Đăng bán', render: (_, row) => row.usageType ? <div className="text-xs">{row.usageType === 'nick' ? row.categoryName : 'Kho đồ'}{row.price != null && <p>{row.price.toLocaleString('vi-VN')}đ</p>}</div> : null },
                { title: 'Kết quả', render: (_, row) => <div className="text-xs"><Tag color={row.status === 'invalid' ? 'red' : 'green'}>{row.status === 'created' ? `Đã thêm #${row.accountId}` : row.status === 'valid' ? 'Hợp lệ' : 'Lỗi'}</Tag>{row.message}</div> },
            ]} /></>}
        <div className="mt-4 flex flex-wrap justify-end gap-2"><Button disabled={busy} onClick={onClose}>Đóng</Button><Button loading={busy} disabled={!text.trim()} onClick={() => run('preview')}>Kiểm tra danh sách</Button><Button type="primary" loading={busy} disabled={!previewed || !result?.valid} onClick={() => run('import')}>Thêm {previewed ? result?.valid || '' : ''} acc hợp lệ</Button></div>
    </Modal>;
}
