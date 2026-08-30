import { Head, router, usePage } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import type { PageProps } from '@/types';
import SpinTicketModal from './SpinTicketModal';

interface Props extends PageProps {
    ticket: { id: number } | { data: { id: number } };
    spins: Array<{ id: number; name: string }>;
}

export default function SpinTicketEdit() {
    const { ticket: raw, spins } = usePage<Props>().props;
    const ticket = 'data' in raw ? raw.data : raw;
    return <AdminLayout title={`Sửa lượt quay #${ticket.id}`}><Head title={`Sửa lượt quay #${ticket.id}`} /><SpinTicketModal open ticketId={ticket.id} spins={spins} onClose={() => router.visit('/admin/spin-tickets')} /></AdminLayout>;
}
