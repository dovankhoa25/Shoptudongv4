/** Clipboard files only: ordinary text/URLs keep the browser's native paste behaviour. */
export function clipboardImages(data: Pick<DataTransfer, 'items' | 'files'>): File[] {
    const images = Array.from(data.items ?? [])
        .filter(item => item.kind === 'file' && item.type.startsWith('image/'))
        .map(item => item.getAsFile())
        .filter((file): file is File => file !== null);
    return images.length ? images : Array.from(data.files ?? []).filter(file => file.type.startsWith('image/'));
}

type GroupMessage = {
    id: number;
    conversation_id: number;
    sender_kind: string;
    sender?: { id: number | string } | null;
    type: string;
    is_internal: boolean;
    created_at: string;
    seen_by?: unknown[];
};

export function continuesMessageGroup(previous: GroupMessage | undefined, message: GroupMessage): boolean {
    if (!previous || previous.is_internal || message.is_internal
        || !['text', 'image'].includes(previous.type) || !['text', 'image'].includes(message.type)
        || previous.conversation_id !== message.conversation_id
        || previous.sender_kind !== message.sender_kind
        || previous.sender?.id == null || message.sender?.id == null
        || String(previous.sender.id) !== String(message.sender.id)) return false;
    const gap = Date.parse(message.created_at) - Date.parse(previous.created_at);
    return Number.isFinite(gap) && gap >= 0 && gap <= 5 * 60_000;
}

export function latestReadCustomerMessageId(messages: GroupMessage[]): number | null {
    return messages.reduce<number | null>((latest, message) => (
        message.id > 0 && !message.is_internal && message.sender_kind === 'customer'
        && ['text', 'image'].includes(message.type) && (message.seen_by?.length ?? 0) > 0
            ? Math.max(latest ?? 0, message.id) : latest
    ), null);
}
