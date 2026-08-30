import { Head, router, usePage } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import type { PageProps } from '@/types';
import type { IGemBot } from '@/InterFaces/gembot';
import type { IServer } from '@/InterFaces/server';
import type { IServerGameLogin } from '@/InterFaces/servergamelogin';
import GemBotModal from './GemBotModal';

interface Props extends PageProps {
    gemBot: IGemBot | { data: IGemBot };
    servers: IServer[];
    logins: IServerGameLogin[];
}

export default function GemBotEdit() {
    const { gemBot: raw, servers, logins } = usePage<Props>().props;
    const gemBot = 'data' in raw ? raw.data : raw;
    return (
        <AdminLayout title={`Sửa bot ngọc #${gemBot.id}`}>
            <Head title={`Sửa bot ngọc #${gemBot.id}`} />
            <GemBotModal open gemBot={gemBot} servers={servers} logins={logins} onClose={() => router.visit('/admin/gem-bots')} />
        </AdminLayout>
    );
}
