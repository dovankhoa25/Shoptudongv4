import { Head, router, usePage } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import type { PageProps } from '@/types';
import type { IServer } from '@/InterFaces/server';
import type { IServerGameLogin } from '@/InterFaces/servergamelogin';
import GemBotModal from './GemBotModal';

interface Props extends PageProps {
    servers: IServer[];
    logins: IServerGameLogin[];
}

export default function GemBotCreate() {
    const { servers, logins } = usePage<Props>().props;
    return (
        <AdminLayout title="Thêm bot ngọc">
            <Head title="Thêm bot ngọc" />
            <GemBotModal open gemBot={null} servers={servers} logins={logins} onClose={() => router.visit('/admin/gem-bots')} />
        </AdminLayout>
    );
}
