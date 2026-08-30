export default function Footer() {
    return (
        <footer className="border-t border-slate-200/80 bg-white/60 px-6 py-4 text-center text-xs text-slate-500 backdrop-blur dark:border-slate-700/80 dark:bg-slate-900/60 dark:text-slate-400">
            © {new Date().getFullYear()} NROCHECK · Admin Console
        </footer>
    );
}
