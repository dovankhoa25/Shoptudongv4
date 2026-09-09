'use client';

import { useEffect, useRef, useState, type ReactNode } from 'react';
import { CheckCheck, Smile, X } from 'lucide-react';

type Reader = { id: number | string; username?: string; display_name?: string | null; avatar?: string | null };
const readerName = (user?: Reader | null) => user?.display_name?.trim() || user?.username?.trim() || 'Hỗ trợ viên';

function positionPopover(root: HTMLDivElement | null, height: number) {
    if (!root) return;
    const top = root.closest('[data-chat-scroll]')?.getBoundingClientRect().top ?? 0;
    const below = root.getBoundingClientRect().top - top < height;
    root.style.setProperty('--chat-popover-top', below ? '100%' : 'auto');
    root.style.setProperty('--chat-popover-bottom', below ? 'auto' : '100%');
}
const popoverPosition = { top: 'var(--chat-popover-top, auto)', bottom: 'var(--chat-popover-bottom, 100%)' };

export function ChatAvatar({ user, className = 'h-7 w-7' }: { user?: Reader | null; className?: string }) {
    const source = user?.avatar?.trim();
    const [failed, setFailed] = useState<string | null>(null);
    const name = readerName(user);
    return source && source !== failed ? (
        // eslint-disable-next-line @next/next/no-img-element
        <img src={source} alt={name} loading="lazy" onError={() => setFailed(source)} className={`${className} shrink-0 rounded-full object-cover`} />
    ) : <span aria-label={name} className={`${className} inline-grid shrink-0 place-items-center rounded-full bg-blue-600 text-[10px] font-semibold text-white`}>{name.charAt(0).toUpperCase()}</span>;
}

export function ReadReceipt({ readers, alignRight = true, dark = false }: { readers: Reader[]; alignRight?: boolean; dark?: boolean }) {
    const [open, setOpen] = useState(false);
    const ref = useRef<HTMLDivElement>(null);
    const unique = [...new Map(readers.map(reader => [String(reader.id), reader])).values()];
    useEffect(() => {
        if (!open) return;
        positionPopover(ref.current, 220);
        const outside = (event: PointerEvent) => { if (!ref.current?.contains(event.target as Node)) setOpen(false); };
        const escape = (event: KeyboardEvent) => { if (event.key === 'Escape') setOpen(false); };
        document.addEventListener('pointerdown', outside);
        document.addEventListener('keydown', escape);
        return () => { document.removeEventListener('pointerdown', outside); document.removeEventListener('keydown', escape); };
    }, [open]);
    if (!unique.length) return null;
    return <div ref={ref} className={`relative mt-1 w-fit max-w-full ${alignRight ? 'self-end' : 'self-start'}`}>
        <button type="button" aria-expanded={open} onClick={() => setOpen(value => !value)} className={`inline-flex items-center gap-1 whitespace-nowrap rounded px-1 text-[10px] hover:underline focus-visible:outline focus-visible:outline-2 ${dark ? 'text-emerald-400' : 'text-emerald-600 dark:text-emerald-400'}`}>
            <CheckCheck className="h-3 w-3 shrink-0" /> Đã xem · {unique.length} người
        </button>
        {open && <div role="dialog" aria-label="Người đã xem" style={popoverPosition} className={`absolute z-30 my-1 w-60 max-w-[calc(100vw-4rem)] rounded-xl border p-2 text-left shadow-xl ${alignRight ? 'right-0' : 'left-0'} ${dark ? 'border-gray-700 bg-gray-900 text-gray-100' : 'border-slate-200 bg-white text-slate-800 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100'}`}>
            <div className="mb-1 flex items-center justify-between px-1 text-xs font-semibold">Người đã xem<button type="button" onClick={() => setOpen(false)} aria-label="Đóng danh sách người đã xem" className="grid h-7 w-7 place-items-center rounded hover:bg-slate-500/15"><X className="h-3.5 w-3.5" /></button></div>
            <ul className="max-h-40 overflow-y-auto">{unique.map(reader => <li key={reader.id} className="flex items-center gap-2 rounded px-1 py-1.5 text-xs"><ChatAvatar user={reader} /><span className="min-w-0 break-words">{readerName(reader)}</span></li>)}</ul>
        </div>}
    </div>;
}

/** Hover/focus on desktop; long press on touch. No extra row when there are no reactions. */
export function MessageActions({ children, enabled, alignRight, open, onOpenChange, onReact, emojis, dark = false }: {
    children: ReactNode; enabled: boolean; alignRight: boolean; open: boolean;
    onOpenChange: (open: boolean) => void; onReact: (emoji: string) => void;
    emojis: readonly string[]; dark?: boolean;
}) {
    const ref = useRef<HTMLDivElement>(null);
    const timer = useRef<ReturnType<typeof setTimeout> | null>(null);
    const origin = useRef<{ x: number; y: number } | null>(null);
    const held = useRef(false);
    const touch = useRef(false);
    const cancel = () => { if (timer.current) clearTimeout(timer.current); timer.current = null; };
    useEffect(() => () => cancel(), []);
    useEffect(() => { if (!enabled) cancel(); }, [enabled]);
    useEffect(() => {
        if (!open) return;
        positionPopover(ref.current, 54);
        const outside = (event: PointerEvent) => { if (!ref.current?.contains(event.target as Node)) onOpenChange(false); };
        const escape = (event: KeyboardEvent) => { if (event.key === 'Escape') onOpenChange(false); };
        document.addEventListener('pointerdown', outside);
        document.addEventListener('keydown', escape);
        return () => { document.removeEventListener('pointerdown', outside); document.removeEventListener('keydown', escape); };
    }, [open, onOpenChange]);
    return <div ref={ref} data-reaction-picker className="group/message relative w-fit max-w-full"
        onPointerDown={event => {
            if ((event.target as Element).closest('[data-message-actions]')) return;
            cancel(); held.current = false; touch.current = event.pointerType !== 'mouse';
            if (!enabled || !touch.current || !event.isPrimary) return;
            origin.current = { x: event.clientX, y: event.clientY };
            timer.current = setTimeout(() => { timer.current = null; held.current = true; onOpenChange(true); }, 500);
        }}
        onPointerMove={event => { if (origin.current && Math.hypot(event.clientX - origin.current.x, event.clientY - origin.current.y) > 10) cancel(); }}
        onPointerUp={cancel} onPointerCancel={cancel} onPointerLeave={cancel}
        onContextMenu={event => { if (enabled && touch.current) { event.preventDefault(); cancel(); held.current = true; onOpenChange(true); } }}
        onClickCapture={event => { if (held.current && !(event.target as Element).closest('[data-message-actions]')) { event.preventDefault(); event.stopPropagation(); held.current = false; } }}
    >
        {children}
        {enabled && <div data-message-actions className={`absolute top-1/2 z-10 -translate-y-1/2 ${alignRight ? 'right-full' : 'left-full'}`}>
            <button type="button" aria-label="Thả cảm xúc" aria-expanded={open} onClick={() => onOpenChange(!open)}
                className={`grid h-7 w-7 place-items-center rounded-full border transition focus-visible:outline focus-visible:outline-2 ${open ? 'opacity-100' : 'pointer-events-none opacity-0 group-hover/message:pointer-events-auto group-hover/message:opacity-100 group-focus-within/message:pointer-events-auto group-focus-within/message:opacity-100'} ${dark ? 'border-gray-700 bg-gray-900 text-gray-300' : 'border-slate-200 bg-white text-slate-500 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-300'}`}><Smile className="h-3.5 w-3.5" /></button>
        </div>}
        {enabled && open && <div data-message-actions role="group" aria-label="Chọn cảm xúc" style={popoverPosition} className={`absolute z-30 my-1 flex w-max max-w-[calc(100vw-4rem)] flex-wrap gap-0.5 rounded-xl border p-1 shadow-xl ${alignRight ? 'right-0' : 'left-0'} ${dark ? 'border-gray-700 bg-gray-900' : 'border-slate-200 bg-white dark:border-slate-700 dark:bg-slate-900'}`}>
            {emojis.map(emoji => <button key={emoji} type="button" aria-label={`Cảm xúc ${emoji}`} onClick={() => { onReact(emoji); onOpenChange(false); }} className="grid h-8 w-8 place-items-center rounded-lg text-base hover:bg-slate-500/20 focus-visible:outline focus-visible:outline-2">{emoji}</button>)}
            <button type="button" aria-label="Đóng cảm xúc" onClick={() => onOpenChange(false)} className={`grid h-8 w-8 place-items-center rounded-lg hover:bg-slate-500/20 ${dark ? 'text-gray-300' : 'text-slate-500 dark:text-slate-300'}`}><X className="h-3.5 w-3.5" /></button>
        </div>}
    </div>;
}
