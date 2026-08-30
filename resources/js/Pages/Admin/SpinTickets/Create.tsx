import { Head, router, usePage } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import type { PageProps } from '@/types';
import SpinTicketModal from './SpinTicketModal';

interface Props extends PageProps { spins: Array<{ id: number; name: string }> }

export default function SpinTicketCreate() {
    const { spins } = usePage<Props>().props;
    return <AdminLayout title="Cấp lượt quay"><Head title="Cấp lượt quay" /><SpinTicketModal open spins={spins} onClose={() => router.visit('/admin/spin-tickets')} /></AdminLayout>;
}
