import type { ReactNode } from 'react';
import { Head, usePage } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import ChatWorkspace from '@/Features/Chat/ChatWorkspace';

function AdminChatsPage() {
    const { url } = usePage();
    const match = url.match(/[?&]conversation=(\d+)/);
    const initialConversationId = match ? Number(match[1]) : null;

    return (
        <>
            <Head title="Hỗ trợ chat" />
            <ChatWorkspace
                mode="agent"
                baseUrl="/admin/chat"
                initialConversationId={initialConversationId}
            />
        </>
    );
}

AdminChatsPage.layout = (page: ReactNode) => (
    <AdminLayout title="Hỗ trợ khách hàng">{page}</AdminLayout>
);

export default AdminChatsPage;
