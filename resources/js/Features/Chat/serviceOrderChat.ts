import type { PageProps } from '@/types';
import type { ChatConversation } from './types';

interface ServiceOrderChatTarget {
    id: number;
    status: string;
    receiver?: { id: number } | null;
}

type ChatAuth = PageProps['auth'];

export function canMessageServiceOrderCustomer(auth: ChatAuth, order: ServiceOrderChatTarget): boolean {
    const roles = Array.isArray(auth.roles) ? auth.roles : [];
    const permissions = Array.isArray(auth.permissions) ? auth.permissions : [];
    const isAdmin = auth.is_super_admin || roles.includes('admin') || roles.includes('super-admin');
    const canUseChat = auth.is_super_admin
        || (permissions.includes('chats.view') && permissions.includes('chats.reply'));

    if (!canUseChat) return false;
    if (isAdmin) return true;

    const canUseServiceOrders = permissions.includes('service-orders.view')
        || permissions.includes('service-orders.process');

    return canUseServiceOrders
        && order.status === 'approved'
        && Number(order.receiver?.id) === Number(auth.user?.id);
}

export async function resolveServiceOrderConversation(orderId: number): Promise<ChatConversation> {
    const response = await window.axios.post<{ data: ChatConversation }>(
        '/admin/chat/conversations/resolve',
        {
            subject_type: 'service_order',
            subject_id: orderId,
            source_app: 'admin-service-orders',
            source_url: window.location.href,
        },
    );

    return response.data.data;
}

export function chatRequestErrorMessage(error: unknown): string {
    const candidate = error as {
        response?: { data?: { message?: string; errors?: Record<string, string[]> } };
    };
    const validationMessage = Object.values(candidate.response?.data?.errors ?? {}).flat()[0];

    return validationMessage
        ?? candidate.response?.data?.message
        ?? 'Không thể mở cuộc trò chuyện với khách. Vui lòng thử lại.';
}
