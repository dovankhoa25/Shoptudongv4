import { useState } from 'react';

interface AvatarUser {
    username?: string | null;
    display_name?: string | null;
    chat_display_name?: string | null;
    avatar?: string | null;
}

interface UserAvatarProps {
    user?: AvatarUser | null;
    className?: string;
}

export default function UserAvatar({ user, className = 'h-9 w-9' }: UserAvatarProps) {
    const avatarSource = typeof user?.avatar === 'string' ? user.avatar.trim() : '';
    const [failedSource, setFailedSource] = useState<string | null>(null);
    const displayName = user?.display_name?.trim()
        || user?.chat_display_name?.trim()
        || user?.username?.trim()
        || '';
    const initial = displayName.charAt(0).toUpperCase() || '?';

    if (avatarSource && failedSource !== avatarSource) {
        return (
            <img
                src={avatarSource}
                alt={displayName ? `Ảnh đại diện của ${displayName}` : 'Ảnh đại diện'}
                className={`${className} shrink-0 rounded-full object-cover ring-1 ring-white/60 dark:ring-slate-700`}
                onError={() => setFailedSource(avatarSource)}
            />
        );
    }

    return (
        <span
            aria-label={displayName ? `Ảnh đại diện của ${displayName}` : 'Chưa có ảnh đại diện'}
            className={`${className} inline-grid shrink-0 place-items-center rounded-full bg-blue-600 font-semibold uppercase text-white shadow-sm`}
        >
            {initial}
        </span>
    );
}
