import { useState } from 'react';

interface AvatarUser {
    username?: string | null;
    avatar?: string | null;
}

interface UserAvatarProps {
    user?: AvatarUser | null;
    className?: string;
}

export default function UserAvatar({ user, className = 'h-9 w-9' }: UserAvatarProps) {
    const avatarSource = typeof user?.avatar === 'string' ? user.avatar.trim() : '';
    const [failedSource, setFailedSource] = useState<string | null>(null);
    const username = user?.username?.trim() || '';
    const initial = username.charAt(0).toUpperCase() || '?';

    if (avatarSource && failedSource !== avatarSource) {
        return (
            <img
                src={avatarSource}
                alt={username ? `Ảnh đại diện của ${username}` : 'Ảnh đại diện'}
                className={`${className} shrink-0 rounded-full object-cover ring-1 ring-white/60 dark:ring-slate-700`}
                onError={() => setFailedSource(avatarSource)}
            />
        );
    }

    return (
        <span
            aria-label={username ? `Ảnh đại diện của ${username}` : 'Chưa có ảnh đại diện'}
            className={`${className} inline-grid shrink-0 place-items-center rounded-full bg-blue-600 font-semibold uppercase text-white shadow-sm`}
        >
            {initial}
        </span>
    );
}
