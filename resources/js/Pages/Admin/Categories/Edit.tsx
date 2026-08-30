import { Head, router, usePage } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import type { PageProps } from '@/types';
import type { ICategory } from '@/InterFaces/category';
import CategoryModal from './CategoryModal';

interface Props extends PageProps {
    category: ICategory | { data: ICategory };
}

export default function CategoryEdit() {
    const raw = usePage<Props>().props.category;
    const category = 'data' in raw ? raw.data : raw;

    return (
        <AdminLayout title={`Sửa danh mục ${category.name}`}>
            <Head title={`Sửa danh mục ${category.name}`} />
            <CategoryModal category={category} onClose={() => router.visit('/admin/games/categories')} />
        </AdminLayout>
    );
}
