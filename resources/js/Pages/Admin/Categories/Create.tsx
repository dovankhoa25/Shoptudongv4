import { Head, router } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import CategoryModal from './CategoryModal';

export default function CategoryCreate() {
    return (
        <AdminLayout title="Tạo danh mục">
            <Head title="Tạo danh mục" />
            <CategoryModal onClose={() => router.visit('/admin/games/categories')} />
        </AdminLayout>
    );
}
