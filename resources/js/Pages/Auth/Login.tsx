import InputError from '@/Components/InputError';
import GuestLayout from '@/Layouts/GuestLayout';
import { Head, Link, useForm } from '@inertiajs/react';
import {
    ArrowRight,
    BarChart3,
    CheckCircle2,
    Eye,
    EyeOff,
    Gamepad2,
    LoaderCircle,
    LockKeyhole,
    ShieldCheck,
    UserRound,
    Zap,
} from 'lucide-react';
import { FormEventHandler, useState } from 'react';

const GoogleMark = () => (
    <svg aria-hidden="true" viewBox="0 0 18 18" className="h-5 w-5 shrink-0">
        <path fill="#4285F4" d="M17.64 9.205c0-.638-.057-1.252-.164-1.841H9v3.481h4.844a4.14 4.14 0 0 1-1.797 2.716v2.258h2.909c1.702-1.567 2.684-3.874 2.684-6.614Z" />
        <path fill="#34A853" d="M9 18c2.43 0 4.468-.806 5.956-2.181l-2.909-2.258c-.806.54-1.835.859-3.047.859-2.344 0-4.328-1.585-5.037-3.714H.956v2.332A9 9 0 0 0 9 18Z" />
        <path fill="#FBBC05" d="M3.963 10.706A5.41 5.41 0 0 1 3.682 9c0-.592.102-1.168.281-1.706V4.962H.956A9 9 0 0 0 0 9c0 1.452.347 2.827.956 4.038l3.007-2.332Z" />
        <path fill="#EA4335" d="M9 3.58c1.321 0 2.507.454 3.441 1.345l2.581-2.58C13.464.892 11.426 0 9 0A9 9 0 0 0 .956 4.962l3.007 2.332C4.672 5.165 6.656 3.58 9 3.58Z" />
    </svg>
);

export default function Login({
    status,
    canResetPassword,
    socialError,
}: {
    status?: string;
    canResetPassword: boolean;
    socialError?: string;
}) {
    const [showPassword, setShowPassword] = useState(false);
    const { data, setData, post, processing, errors, reset } = useForm({
        username: '',
        password: '',
        remember: false as boolean,
    });

    const submit: FormEventHandler = (event) => {
        event.preventDefault();

        post(route('login'), {
            onFinish: () => reset('password'),
        });
    };

    return (
        <GuestLayout fullWidth>
            <Head title="Đăng nhập quản trị" />

            <section className="grid w-full max-w-5xl overflow-hidden rounded-[28px] border border-white/10 bg-white shadow-2xl shadow-black/40 lg:grid-cols-[0.9fr_1.1fr]">
                <aside className="relative hidden min-h-[650px] overflow-hidden bg-gradient-to-br from-blue-700 via-blue-600 to-cyan-500 p-10 text-white lg:flex lg:flex-col">
                    <div
                        className="absolute inset-0 opacity-10"
                        style={{
                            backgroundImage:
                                'radial-gradient(circle at 1px 1px, white 1px, transparent 0)',
                            backgroundSize: '24px 24px',
                        }}
                    />
                    <div className="absolute -right-24 -top-20 h-72 w-72 rounded-full border-[48px] border-white/10" />
                    <div className="absolute -bottom-20 -left-20 h-64 w-64 rounded-full bg-cyan-300/20 blur-2xl" />

                    <div className="relative flex items-center gap-3">
                        <div className="flex h-12 w-12 items-center justify-center rounded-2xl border border-white/20 bg-white/15 shadow-lg backdrop-blur-sm">
                            <Gamepad2 aria-hidden="true" className="h-6 w-6" />
                        </div>
                        <div>
                            <p className="text-lg font-bold tracking-wide">WEGAME</p>
                            <p className="text-xs font-medium text-blue-100">
                                ADMIN CENTER
                            </p>
                        </div>
                    </div>

                    <div className="relative my-auto py-12">
                        <span className="mb-5 inline-flex rounded-full border border-white/20 bg-white/10 px-3 py-1 text-xs font-semibold tracking-wide text-blue-50 backdrop-blur-sm">
                            HỆ THỐNG QUẢN TRỊ
                        </span>
                        <h1 className="max-w-md text-4xl font-bold leading-tight tracking-tight">
                            Vận hành cửa hàng game
                            <span className="block text-cyan-100">
                                nhanh và hiệu quả.
                            </span>
                        </h1>
                        <p className="mt-5 max-w-sm text-sm leading-7 text-blue-100">
                            Theo dõi người dùng, đơn hàng và doanh thu tập trung
                            trong một không gian quản trị bảo mật.
                        </p>

                        <div className="mt-9 grid grid-cols-3 gap-3">
                            {[
                                { icon: BarChart3, label: 'Thống kê' },
                                { icon: Zap, label: 'Tức thời' },
                                { icon: ShieldCheck, label: 'Bảo mật' },
                            ].map(({ icon: Icon, label }) => (
                                <div
                                    key={label}
                                    className="rounded-2xl border border-white/15 bg-white/10 p-3 backdrop-blur-sm"
                                >
                                    <Icon
                                        aria-hidden="true"
                                        className="mb-2 h-5 w-5 text-cyan-100"
                                    />
                                    <span className="text-xs font-semibold text-white">
                                        {label}
                                    </span>
                                </div>
                            ))}
                        </div>
                    </div>

                    <p className="relative text-xs text-blue-100/80">
                        Chỉ dành cho tài khoản đã được cấp quyền truy cập.
                    </p>
                </aside>

                <div className="flex min-h-[650px] items-center bg-white px-6 py-10 sm:px-12 lg:px-16">
                    <div className="mx-auto w-full max-w-md">
                        <div className="mb-9 flex items-center gap-3 lg:hidden">
                            <div className="flex h-11 w-11 items-center justify-center rounded-xl bg-gradient-to-br from-blue-600 to-cyan-500 text-white shadow-lg shadow-blue-500/20">
                                <Gamepad2 aria-hidden="true" className="h-6 w-6" />
                            </div>
                            <div>
                                <p className="font-bold tracking-wide text-slate-900">
                                    WEGAME
                                </p>
                                <p className="text-[11px] font-semibold text-slate-500">
                                    ADMIN CENTER
                                </p>
                            </div>
                        </div>

                        <div className="mb-8">
                            <p className="mb-2 text-sm font-semibold text-blue-600">
                                Chào mừng trở lại
                            </p>
                            <h2 className="text-3xl font-bold tracking-tight text-slate-900">
                                Đăng nhập quản trị
                            </h2>
                            <p className="mt-3 text-sm leading-6 text-slate-500">
                                Nhập thông tin tài khoản để tiếp tục vào hệ thống.
                            </p>
                        </div>

                        {status && (
                            <div
                                role="status"
                                className="mb-6 flex items-start gap-3 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-700"
                            >
                                <CheckCircle2
                                    aria-hidden="true"
                                    className="mt-0.5 h-4 w-4 shrink-0"
                                />
                                <span>{status}</span>
                            </div>
                        )}

                        {socialError && (
                            <div
                                role="alert"
                                className="mb-6 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-medium text-rose-700"
                            >
                                {socialError}
                            </div>
                        )}

                        <form className="space-y-5" onSubmit={submit}>
                            <div>
                                <label
                                    htmlFor="username"
                                    className="mb-2 block text-sm font-semibold text-slate-700"
                                >
                                    Tên đăng nhập hoặc email
                                </label>
                                <div className="group relative">
                                    <UserRound
                                        aria-hidden="true"
                                        className="pointer-events-none absolute left-4 top-1/2 h-5 w-5 -translate-y-1/2 text-slate-400 transition-colors group-focus-within:text-blue-600"
                                    />
                                    <input
                                        id="username"
                                        type="text"
                                        name="username"
                                        value={data.username}
                                        className={`block w-full rounded-xl border bg-slate-50 py-3.5 pl-12 pr-4 text-base text-slate-900 outline-none transition placeholder:text-slate-400 focus:bg-white focus:ring-4 sm:text-sm ${
                                            errors.username
                                                ? 'border-rose-400 focus:border-rose-500 focus:ring-rose-100'
                                                : 'border-slate-200 hover:border-slate-300 focus:border-blue-500 focus:ring-blue-100'
                                        }`}
                                        autoComplete="username"
                                        autoFocus
                                        required
                                        placeholder="Nhập tên đăng nhập"
                                        onChange={(event) =>
                                            setData(
                                                'username',
                                                event.target.value,
                                            )
                                        }
                                    />
                                </div>
                                <InputError
                                    message={errors.username}
                                    className="mt-2"
                                />
                            </div>

                            <div>
                                <label
                                    htmlFor="password"
                                    className="mb-2 block text-sm font-semibold text-slate-700"
                                >
                                    Mật khẩu
                                </label>
                                <div className="group relative">
                                    <LockKeyhole
                                        aria-hidden="true"
                                        className="pointer-events-none absolute left-4 top-1/2 h-5 w-5 -translate-y-1/2 text-slate-400 transition-colors group-focus-within:text-blue-600"
                                    />
                                    <input
                                        id="password"
                                        type={showPassword ? 'text' : 'password'}
                                        name="password"
                                        value={data.password}
                                        className={`block w-full rounded-xl border bg-slate-50 py-3.5 pl-12 pr-12 text-base text-slate-900 outline-none transition placeholder:text-slate-400 focus:bg-white focus:ring-4 sm:text-sm ${
                                            errors.password
                                                ? 'border-rose-400 focus:border-rose-500 focus:ring-rose-100'
                                                : 'border-slate-200 hover:border-slate-300 focus:border-blue-500 focus:ring-blue-100'
                                        }`}
                                        autoComplete="current-password"
                                        required
                                        placeholder="Nhập mật khẩu"
                                        onChange={(event) =>
                                            setData(
                                                'password',
                                                event.target.value,
                                            )
                                        }
                                    />
                                    <button
                                        type="button"
                                        aria-label={
                                            showPassword
                                                ? 'Ẩn mật khẩu'
                                                : 'Hiện mật khẩu'
                                        }
                                        aria-pressed={showPassword}
                                        className="absolute right-3 top-1/2 -translate-y-1/2 rounded-lg p-1.5 text-slate-400 transition hover:bg-slate-100 hover:text-slate-700 focus:outline-none focus:ring-2 focus:ring-blue-500"
                                        onClick={() =>
                                            setShowPassword(
                                                (visible) => !visible,
                                            )
                                        }
                                    >
                                        {showPassword ? (
                                            <EyeOff
                                                aria-hidden="true"
                                                className="h-5 w-5"
                                            />
                                        ) : (
                                            <Eye
                                                aria-hidden="true"
                                                className="h-5 w-5"
                                            />
                                        )}
                                    </button>
                                </div>
                                <InputError
                                    message={errors.password}
                                    className="mt-2"
                                />
                            </div>

                            <div className="flex items-center justify-between gap-4">
                                <label className="flex cursor-pointer items-center gap-2.5 text-sm text-slate-600">
                                    <input
                                        name="remember"
                                        type="checkbox"
                                        checked={data.remember}
                                        className="h-4 w-4 rounded border-slate-300 text-blue-600 focus:ring-blue-500"
                                        onChange={(event) =>
                                            setData(
                                                'remember',
                                                event.target.checked,
                                            )
                                        }
                                    />
                                    <span>Ghi nhớ đăng nhập</span>
                                </label>

                                {canResetPassword && (
                                    <Link
                                        href={route('password.request')}
                                        className="shrink-0 rounded text-sm font-semibold text-blue-600 transition hover:text-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2"
                                    >
                                        Quên mật khẩu?
                                    </Link>
                                )}
                            </div>

                            <button
                                type="submit"
                                disabled={processing}
                                className="group flex w-full items-center justify-center gap-2 rounded-xl bg-gradient-to-r from-blue-600 to-cyan-500 px-5 py-3.5 text-sm font-bold text-white shadow-lg shadow-blue-500/20 transition hover:-translate-y-0.5 hover:shadow-xl hover:shadow-blue-500/25 focus:outline-none focus:ring-4 focus:ring-blue-200 disabled:cursor-not-allowed disabled:opacity-70 disabled:hover:translate-y-0"
                            >
                                {processing ? (
                                    <>
                                        <LoaderCircle
                                            aria-hidden="true"
                                            className="h-5 w-5 animate-spin"
                                        />
                                        Đang đăng nhập...
                                    </>
                                ) : (
                                    <>
                                        Đăng nhập
                                        <ArrowRight
                                            aria-hidden="true"
                                            className="h-5 w-5 transition-transform group-hover:translate-x-1"
                                        />
                                    </>
                                )}
                            </button>
                        </form>

                        <div className="my-6 flex items-center gap-3" aria-hidden="true">
                            <div className="h-px flex-1 bg-slate-200" />
                            <span className="text-xs font-semibold uppercase tracking-wider text-slate-400">
                                hoặc tiếp tục bằng
                            </span>
                            <div className="h-px flex-1 bg-slate-200" />
                        </div>

                        <a
                            href={route('social.google.redirect')}
                            className="flex w-full items-center justify-center gap-3 rounded-xl border border-slate-200 bg-white px-5 py-3.5 text-sm font-bold text-slate-700 shadow-sm transition hover:-translate-y-0.5 hover:border-slate-300 hover:bg-slate-50 hover:shadow-md focus:outline-none focus:ring-4 focus:ring-blue-100"
                        >
                            <GoogleMark />
                            Đăng nhập quản trị bằng Google
                        </a>

                        <div className="mt-8 flex items-center justify-center gap-2 border-t border-slate-100 pt-6 text-xs text-slate-400">
                            <ShieldCheck
                                aria-hidden="true"
                                className="h-4 w-4 text-emerald-500"
                            />
                            <span>Phiên đăng nhập được mã hóa và bảo vệ.</span>
                        </div>
                    </div>
                </div>
            </section>
        </GuestLayout>
    );
}
