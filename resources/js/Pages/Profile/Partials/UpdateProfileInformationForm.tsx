import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import PrimaryButton from '@/Components/PrimaryButton';
import TextInput from '@/Components/TextInput';
import { Transition } from '@headlessui/react';
import { Link, useForm, usePage } from '@inertiajs/react';
import { FormEventHandler } from 'react';
import type { PageProps } from '@/types';

export default function UpdateProfileInformation({
    mustVerifyEmail,
    status,
    className = '',
}: {
    mustVerifyEmail: boolean;
    status?: string;
    className?: string;
}) {
    const user = usePage<PageProps>().props.auth.user;
    const { data, setData, patch, errors, processing, recentlySuccessful } = useForm<{
        username: string;
        email: string;
    }>({
        username: user.username ?? '',
        email: user.email ?? '',
    });

    const submit: FormEventHandler = event => {
        event.preventDefault();
        patch(route('profile.update'), { preserveScroll: true });
    };

    return (
        <section className={className}>
            <header>
                <h2 className="text-lg font-medium text-slate-900 dark:text-white">Thông tin tài khoản</h2>
                <p className="mt-1 text-sm text-slate-600 dark:text-slate-400">
                    Cập nhật tên đăng nhập và địa chỉ email của bạn.
                </p>
            </header>

            <form onSubmit={submit} className="mt-6 space-y-6">
                <div>
                    <InputLabel htmlFor="username" value="Tên đăng nhập" className="dark:text-slate-200" />
                    <TextInput
                        id="username"
                        className="mt-1 block w-full dark:border-slate-600 dark:bg-slate-900 dark:text-white"
                        value={data.username}
                        onChange={event => setData('username', event.target.value)}
                        required
                        isFocused
                        autoComplete="username"
                    />
                    <InputError className="mt-2" message={errors.username} />
                </div>

                <div>
                    <InputLabel htmlFor="email" value="Email" className="dark:text-slate-200" />
                    <TextInput
                        id="email"
                        type="email"
                        className="mt-1 block w-full dark:border-slate-600 dark:bg-slate-900 dark:text-white"
                        value={data.email}
                        onChange={event => setData('email', event.target.value)}
                        autoComplete="email"
                    />
                    <InputError className="mt-2" message={errors.email} />
                </div>

                {mustVerifyEmail && !user.email_verified_at && (
                    <div className="text-sm text-slate-600 dark:text-slate-400">
                        Email chưa được xác minh.{' '}
                        <Link href={route('verification.send')} method="post" as="button" className="font-medium text-blue-600 underline dark:text-blue-400">
                            Gửi lại email xác minh
                        </Link>
                        {status === 'verification-link-sent' && (
                            <p className="mt-2 font-medium text-emerald-600 dark:text-emerald-400">Đã gửi liên kết xác minh mới.</p>
                        )}
                    </div>
                )}

                <div className="flex items-center gap-4">
                    <PrimaryButton disabled={processing}>Lưu thay đổi</PrimaryButton>
                    <Transition show={recentlySuccessful} enter="transition" enterFrom="opacity-0" leave="transition" leaveTo="opacity-0">
                        <p className="text-sm text-slate-600 dark:text-slate-400">Đã lưu.</p>
                    </Transition>
                </div>
            </form>
        </section>
    );
}
