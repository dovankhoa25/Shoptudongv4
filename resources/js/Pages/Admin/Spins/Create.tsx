import { Head, router, usePage } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import type { PageProps } from '@/types';
import SpinModal from './SpinModal';

interface Props extends PageProps { categories: Array<{ id: number; name: string }> }

export default function SpinCreate() {
    const { categories } = usePage<Props>().props;
    return <><Head title="Tạo vòng quay" /><SpinModal open categories={categories} onClose={() => router.visit('/admin/spins')} /></>;
}

SpinCreate.layout = (page: React.ReactNode) => <AdminLayout title="Tạo vòng quay">{page}</AdminLayout>;
