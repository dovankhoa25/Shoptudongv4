// CTVQuickToggle.tsx
import React from 'react';
import { Button, Tooltip } from 'antd';
import { EyeOff, Eye } from 'lucide-react';
import axios from 'axios';
import { useToast } from '@/Components/ToastProvider';

interface CTVQuickToggleProps {
    nickId: number;
    currentStatus: 'hide' | 'pending';
    onSuccess: () => void;
}

export const CTVQuickToggle: React.FC<CTVQuickToggleProps> = ({
    nickId,
    currentStatus,
    onSuccess
}) => {
    const toast = useToast();
    const [loading, setLoading] = React.useState(false);

    const handleToggle = async () => {
        try {
            setLoading(true);
            const response = await axios.post(`/ctv/nicks/${nickId}/toggle-visibility`);

            toast.success(response.data.message);
            onSuccess();
        } catch (error: any) {
            toast.error(error.response?.data?.message || 'Có lỗi xảy ra');
        } finally {
            setLoading(false);
        }
    };

    return (
        <Tooltip title={currentStatus === 'hide' ? 'Hiện nick' : 'Ẩn nick'}>
            <Button
                size="small"
                icon={currentStatus === 'hide' ? <Eye className="w-3 h-3" /> : <EyeOff className="w-3 h-3" />}
                onClick={handleToggle}
                loading={loading}
            />
        </Tooltip>
    );
};