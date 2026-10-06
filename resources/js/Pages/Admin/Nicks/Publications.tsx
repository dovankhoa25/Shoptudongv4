import React, { useCallback, useEffect, useRef, useState } from 'react';
import { router, usePage } from '@inertiajs/react';
import axios from 'axios';
import { Alert, Button, Card, Pagination, Popconfirm, Progress, Space, Tag, message } from 'antd';
import AdminLayout from '@/Layouts/AdminLayout';
import { PageProps } from '@/types';

interface Publication {
    uuid: string;
    account_name: string;
    status: 'pending' | 'completed' | 'failed' | 'cancelled';
    nick_id: number | null;
    total: number;
    ready: number;
    processing: number;
    failed: number;
    error: string | null;
    created_at: string;
    errors: { position: number; name: string; message: string }[];
}
interface PublicationPage { data: Publication[]; current_page: number; last_page: number; total: number; per_page: number; }

export default function Publications() {
    const { publications: initial } = usePage<PageProps & { publications: PublicationPage }>().props;
    const [page, setPage] = useState(initial);
    const [busy, setBusy] = useState<string | null>(null);
    const [error, setError] = useState('');
    const controller = useRef<AbortController>();
    const hasPending = page.data.some(item => item.status === 'pending');
    const refresh = useCallback(async (pageNumber: number) => {
        controller.current?.abort();
        const current = new AbortController();
        controller.current = current;
        try {
            const result = await axios.get('/admin/games/accounts/media-publications', {
                params: { page: pageNumber }, signal: current.signal,
            });
            if (!current.signal.aborted) { setPage(result.data.publications); setError(''); }
        } catch (e) {
            if (!axios.isCancel(e)) setError('Chưa cập nhật được tiến độ. Hãy bấm Làm mới để kiểm tra lại.');
        }
    }, []);

    useEffect(() => {
        if (!hasPending || error) return;
        const timer = window.setInterval(() => {
            if (document.visibilityState === 'visible') void refresh(page.current_page);
        }, 5000);
        return () => window.clearInterval(timer);
    }, [hasPending, error, page.current_page, refresh]);
    useEffect(() => () => controller.current?.abort(), []);

    const act = async (item: Publication, action: 'retry' | 'cancel') => {
        setBusy(item.uuid);
        try {
            const base = `/admin/games/accounts/media-publications/${item.uuid}`;
            if (action === 'retry') await axios.post(`${base}/retry`);
            else await axios.delete(base);
            await refresh(page.current_page);
        } catch (e) {
            message.error(axios.isAxiosError(e) ? e.response?.data?.message || 'Thao tác chưa thành công.' : 'Thao tác chưa thành công.');
        } finally { setBusy(null); }
    };

    return <div className="space-y-4 p-4 sm:p-6">
        <div className="flex flex-wrap items-center justify-between gap-3">
            <h1 className="text-xl font-semibold dark:text-white">Đăng nick · Xử lý ảnh</h1>
            <Space wrap>
                <Button onClick={() => router.visit('/admin/games/accounts')}>Danh sách nick</Button>
                <Button onClick={() => router.visit('/admin/games/accounts/create')}>Đăng cách cũ</Button>
                <Button type="primary" onClick={() => router.visit('/admin/games/accounts/create-background')}>Đăng nick mới</Button>
            </Space>
        </div>
        <Alert type="info" showIcon message="Nick chỉ mở bán khi tất cả ảnh đã hoàn tất."
            description="Bạn có thể rời trang và tiếp tục đăng nick khác. Bản đang chờ sẽ bắt đầu ở lượt xử lý tiếp theo, thường trong khoảng một phút nếu hàng đợi trống." />
        {error && <Alert type="warning" showIcon message={error} />}
        <Button onClick={() => void refresh(page.current_page)}>Làm mới</Button>
        {!page.data.length && <Card>Chưa có bản đăng sử dụng xử lý ảnh nền.</Card>}
        {page.data.map(item => <Card key={item.uuid} size="small">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <strong>{item.account_name}</strong>{' '}
                    <Tag color={{ pending: 'blue', completed: 'green', failed: 'red', cancelled: 'default' }[item.status]}>
                        {{ pending: item.processing ? 'Đang xử lý' : 'Chờ xử lý', completed: `Đã mở bán #${item.nick_id}`, failed: 'Cần xử lý', cancelled: 'Đã hủy' }[item.status]}
                    </Tag>
                    <div className="text-xs text-gray-500">{new Date(item.created_at).toLocaleString('vi-VN')}</div>
                </div>
                {['pending', 'failed'].includes(item.status) && <Space>
                    <Button loading={busy === item.uuid} disabled={busy !== null} onClick={() => void act(item, 'retry')}>Thử lại</Button>
                    <Popconfirm title="Hủy bản đăng này?" description="Ảnh tạm sẽ được xóa, nick chưa mở bán."
                        onConfirm={() => act(item, 'cancel')} okText="Hủy bản đăng" cancelText="Giữ lại">
                        <Button danger disabled={busy !== null}>Hủy bản đăng</Button>
                    </Popconfirm>
                </Space>}
            </div>
            {item.status !== 'cancelled' && <div className="mt-3">
                <Progress percent={item.total ? Math.round(item.ready / item.total * 100) : item.status === 'completed' ? 100 : 0}
                    status={item.status === 'failed' ? 'exception' : item.status === 'completed' ? 'success' : 'normal'} />
                <span>{item.ready}/{item.total} ảnh hoàn tất{item.failed ? ` · ${item.failed} ảnh lỗi` : ''}</span>
            </div>}
            {item.error && <Alert className="mt-3" type="error" message={item.error} />}
            {item.errors.map(issue => <div key={issue.position} className="mt-2 text-sm text-red-600">
                Ảnh {issue.position}: {issue.name} — {issue.message}
            </div>)}
        </Card>)}
        {page.last_page > 1 && <Pagination current={page.current_page} total={page.total} pageSize={page.per_page}
            showSizeChanger={false} onChange={number => void refresh(number)} />}
    </div>;
}

Publications.layout = (page: React.ReactNode) => <AdminLayout title="Xử lý ảnh đăng nick">{page}</AdminLayout>;
