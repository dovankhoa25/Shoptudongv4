import { Alert, Button } from 'antd';

export function LiveDataNotice({ error, warning, reload }: { error?: string | null; warning?: string | null; reload: () => void }) {
    if (!error && !warning) return null;
    return <Alert className="my-3" showIcon type={error ? 'error' : 'warning'} message={error || warning}
        action={<Button size="small" onClick={reload}>Làm mới</Button>} />;
}
