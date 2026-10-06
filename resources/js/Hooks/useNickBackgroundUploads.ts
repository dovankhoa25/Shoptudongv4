import { useEffect, useRef, useState } from 'react';
import axios from 'axios';
import { NickUploadEntry, NickUploadQueue } from '@/Utils/NickUploadQueue';

export function useNickBackgroundUploads(files: File[] | null, enabled: boolean) {
    const [entries, setEntries] = useState<NickUploadEntry[]>([]);
    const queue = useRef<NickUploadQueue>();

    useEffect(() => {
        const current = new NickUploadQueue(async (file, signal, progress) => {
            const body = new FormData();
            body.append('image', file);
            try {
                const response = await axios.post('/admin/games/accounts/media-uploads', body, {
                    signal,
                    onUploadProgress: event => progress(event.total ? Math.min(99, Math.round(event.loaded / event.total * 100)) : 0),
                });
                return response.data.id as string;
            } catch (error) {
                if (axios.isAxiosError(error)) {
                    const errors = error.response?.data?.errors;
                    const detail = errors ? Object.values(errors).flat().join(' ') : error.response?.data?.message;
                    throw new Error(detail || 'Tải ảnh thất bại. Kiểm tra kết nối và thử lại.');
                }
                throw error;
            }
        }, id => {
            void axios.delete(`/admin/games/accounts/media-uploads/${id}`).catch(() => {});
        }, setEntries);
        queue.current = current;
        return () => current.dispose();
    }, []);

    useEffect(() => { queue.current?.setFiles(enabled ? files || [] : []); }, [enabled, files]);

    const complete = !files?.length || (entries.length === files.length
        && entries.every((entry, index) => entry.file === files[index] && entry.status === 'ready'));
    return { entries, complete, retry: () => queue.current?.retry() };
}
