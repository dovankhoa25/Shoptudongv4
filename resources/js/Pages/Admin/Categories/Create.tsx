import { Head, router } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import CategoryModal from './CategoryModal';

export default function CategoryCreate() {
    return (
        <>
            <Head title="Tạo danh mục" />
            <CategoryModal onClose={() => router.visit('/admin/games/categories')} />
        </>
    );
}

CategoryCreate.layout = (page: React.ReactNode) => <AdminLayout title="Tạo danh mục">{page}</AdminLayout>;
