import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import ChatWorkspace from '@/Features/Chat/ChatWorkspace';
import { Head, usePage } from '@inertiajs/react';

export default function MessagesPage() {
    const { url } = usePage();
    const match = url.match(/[?&]conversation=(\d+)/);
    const initialConversationId = match ? Number(match[1]) : null;

    return (
        <AuthenticatedLayout
            header={<h1 className="text-xl font-semibold leading-tight text-slate-800">Tin nhắn hỗ trợ</h1>}
        >
            <Head title="Tin nhắn" />
            <div className="mx-auto max-w-[96rem] p-3 sm:p-5 lg:p-7">
                <ChatWorkspace
                    mode="customer"
                    baseUrl="/chat"
                    initialConversationId={initialConversationId}
                />
            </div>
        </AuthenticatedLayout>
    );
}
