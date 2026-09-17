import { Head, router, usePage } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import type { PageProps } from '@/types';
import SpinModal from './SpinModal';

interface Props extends PageProps {
    spin: { id: number } | { data: { id: number } };
    categories: Array<{ id: number; name: string }>;
}

export default function SpinEdit() {
    const { spin: raw, categories } = usePage<Props>().props;
    const spin = 'data' in raw ? raw.data : raw;
    return <><Head title={`Sửa vòng quay #${spin.id}`} /><SpinModal open spinId={spin.id} categories={categories} onClose={() => router.visit('/admin/spins')} /></>;
}

SpinEdit.layout = (page: React.ReactElement<Props>) => {
    const raw = page.props.spin;
    const value = 'data' in raw ? raw.data : raw;
    return <AdminLayout title={`Sửa vòng quay #${value.id}`}>{page}</AdminLayout>;
};
