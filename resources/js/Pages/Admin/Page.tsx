import AdminLayout from '@/Layouts/AdminLayout';
import { Link, usePage } from '@inertiajs/react';
import { LayoutDashboard, ShieldCheck, Users } from 'lucide-react';
import type { PageProps } from '@/types';

interface AdminPageProps extends PageProps {
    auth: {
        user: { username?: string } | null;
        roles: string[];
        permissions: string[];
        is_super_admin: boolean;
    };
}

export default function AdminDashboard() {
    const { auth } = usePage<AdminPageProps>().props;
    const permissions = auth.permissions ?? [];
    const can = (permission: string) => auth.is_super_admin || permissions.includes(permission);

    const modules = [
        can('users.view') && {
            title: 'Quản lý người dùng',
            description: 'Tài khoản, trạng thái, vai trò và quyền truy cập.',
            href: '/admin/users',
            icon: Users,
            color: 'from-violet-500 to-purple-600',
        },
    ].filter(Boolean) as Array<{
        title: string;
        description: string;
        href: string;
        icon: typeof Users;
        color: string;
    }>;

    return (
        <div className="space-y-6">
            <section className="overflow-hidden rounded-2xl border border-slate-200 bg-white p-6 shadow-sm dark:border-slate-700 dark:bg-slate-800">
                <div className="flex items-start justify-between gap-4">
                    <div>
                        <div className="mb-3 inline-flex items-center gap-2 rounded-full bg-blue-50 px-3 py-1 text-sm font-medium text-blue-700 dark:bg-blue-950 dark:text-blue-300">
                            <ShieldCheck size={16} />
                            {auth.roles.join(', ') || 'Chưa có vai trò'}
                        </div>
                        <h1 className="text-2xl font-bold text-slate-900 dark:text-white">
                            Xin chào, {auth.user?.username ?? 'Admin'}
                        </h1>
                        <p className="mt-2 text-slate-600 dark:text-slate-300">
                            Chọn một khu vực bên dưới để bắt đầu quản trị hệ thống.
                        </p>
                    </div>
                    <div className="hidden rounded-2xl bg-gradient-to-br from-blue-500 to-violet-600 p-4 text-white shadow-lg sm:block">
                        <LayoutDashboard size={30} />
                    </div>
                </div>
            </section>

            <section className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                {modules.map(({ title, description, href, icon: Icon, color }) => (
                    <Link
                        key={href}
                        href={href}
                        className="group rounded-2xl border border-slate-200 bg-white p-5 shadow-sm transition hover:-translate-y-1 hover:shadow-lg dark:border-slate-700 dark:bg-slate-800"
                    >
                        <div className={`mb-4 inline-flex rounded-xl bg-gradient-to-br ${color} p-3 text-white shadow-md`}>
                            <Icon size={24} />
                        </div>
                        <h2 className="font-semibold text-slate-900 group-hover:text-blue-600 dark:text-white dark:group-hover:text-blue-400">
                            {title}
                        </h2>
                        <p className="mt-2 text-sm leading-6 text-slate-500 dark:text-slate-400">
                            {description}
                        </p>
                    </Link>
                ))}
            </section>

            {modules.length === 0 && (
                <div className="rounded-2xl border border-amber-200 bg-amber-50 p-5 text-amber-800 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-200">
                    Tài khoản chưa được cấp quyền truy cập module quản trị nào.
                </div>
            )}
        </div>
    );
}

AdminDashboard.layout = (page: React.ReactNode) => (
    <AdminLayout title="Dashboard">{page}</AdminLayout>
);
