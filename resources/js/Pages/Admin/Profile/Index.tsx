import AdminLayout from '@/Layouts/AdminLayout';
import type { PageProps } from '@/types';
import { Head } from '@inertiajs/react';
import { ShieldCheck, UserRound } from 'lucide-react';
import UpdateProfileInformationForm from '@/Pages/Profile/Partials/UpdateProfileInformationForm';
import UpdatePasswordForm from '@/Pages/Profile/Partials/UpdatePasswordForm';
import DeleteUserForm from '@/Pages/Profile/Partials/DeleteUserForm';

export default function AdminProfile({
    mustVerifyEmail,
    status,
}: PageProps<{ mustVerifyEmail: boolean; status?: string }>) {
    return (
        <>
            <Head title="Hồ sơ quản trị" />
            <div className="space-y-6">
                <section className="overflow-hidden rounded-2xl border border-slate-200 bg-gradient-to-br from-white to-blue-50 p-6 shadow-sm dark:border-slate-700 dark:from-slate-900 dark:to-blue-950/30">
                    <div className="flex items-center gap-4">
                        <span className="grid h-12 w-12 place-items-center rounded-2xl bg-blue-600 text-white shadow-lg shadow-blue-500/20">
                            <UserRound size={24} />
                        </span>
                        <div>
                            <h1 className="text-2xl font-bold text-slate-900 dark:text-white">Hồ sơ quản trị</h1>
                            <p className="mt-1 text-sm text-slate-600 dark:text-slate-400">Thông tin tài khoản, bảo mật và quyền sở hữu tài khoản.</p>
                        </div>
                    </div>
                </section>

                <div className="grid gap-6 xl:grid-cols-2">
                    <section className="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm dark:border-slate-700 dark:bg-slate-900">
                        <UpdateProfileInformationForm mustVerifyEmail={mustVerifyEmail} status={status} />
                    </section>
                    <section className="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm dark:border-slate-700 dark:bg-slate-900">
                        <UpdatePasswordForm />
                    </section>
                </div>

                <section className="rounded-2xl border border-red-200 bg-red-50/60 p-6 shadow-sm dark:border-red-900 dark:bg-red-950/20">
                    <div className="mb-5 flex items-center gap-2 text-red-700 dark:text-red-300">
                        <ShieldCheck size={20} />
                        <span className="font-semibold">Khu vực nguy hiểm</span>
                    </div>
                    <DeleteUserForm />
                </section>
            </div>
        </>
    );
}

AdminProfile.layout = (page: React.ReactNode) => <AdminLayout title="Hồ sơ quản trị">{page}</AdminLayout>;
