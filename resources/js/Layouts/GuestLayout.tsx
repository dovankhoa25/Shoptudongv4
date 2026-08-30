import { PropsWithChildren } from 'react';

type GuestLayoutProps = PropsWithChildren<{
    fullWidth?: boolean;
}>;

export default function Guest({ children, fullWidth = false }: GuestLayoutProps) {
    return (
        <main className="relative min-h-screen overflow-hidden bg-slate-950">
            <div className="absolute inset-0 bg-[radial-gradient(circle_at_top_left,_rgba(37,99,235,0.22),_transparent_34%),radial-gradient(circle_at_bottom_right,_rgba(14,165,233,0.18),_transparent_32%)]" />
            <div className="absolute left-[-8rem] top-[-8rem] h-72 w-72 rounded-full bg-blue-500/10 blur-3xl" />
            <div className="absolute bottom-[-10rem] right-[-6rem] h-80 w-80 rounded-full bg-cyan-400/10 blur-3xl" />

            <div className="relative z-10 flex min-h-screen items-center justify-center px-4 py-6 sm:px-6 lg:px-8">
                {fullWidth ? (
                    children
                ) : (
                    <div className="w-full max-w-md rounded-2xl border border-white/10 bg-white p-6 shadow-2xl shadow-black/30 sm:p-8">
                        {children}
                    </div>
                )}
            </div>
        </main>
    );
}
