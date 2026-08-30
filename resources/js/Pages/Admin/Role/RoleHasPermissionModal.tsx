import { IPermissions } from '@/InterFaces/permission';
import { IRole } from '@/InterFaces/role';
import { useTheme } from '@/Providers/ThemeProvider';
import { Alert, Button, Checkbox, ConfigProvider, Empty, Input, Modal, Skeleton, Tag, message, theme } from 'antd';
import axios from 'axios';
import { useEffect, useMemo, useState } from 'react';

interface Props {
    role: IRole;
    onClose: () => void;
    onSaved?: () => void;
}

type PermissionResponse = {
    all_permissions?: IPermissions[];
    role_permissions?: number[];
    locked_permissions?: IPermissions[];
};

const errorMessage = (error: unknown, fallback: string): string => {
    if (!axios.isAxiosError(error)) return fallback;

    const response = error.response?.data as {
        message?: string;
        errors?: Record<string, string[]>;
    } | undefined;
    const validationMessage = response?.errors
        ? Object.values(response.errors).flat()[0]
        : undefined;

    return validationMessage || response?.message || fallback;
};

const RoleHasPermissionModal = ({ role, onClose, onSaved = () => undefined }: Props) => {
    const { darkMode } = useTheme();
    const [allPermissions, setAllPermissions] = useState<IPermissions[]>([]);
    const [lockedPermissions, setLockedPermissions] = useState<IPermissions[]>([]);
    const [checkedPermissions, setCheckedPermissions] = useState<number[]>([]);
    const [loading, setLoading] = useState(true);
    const [saving, setSaving] = useState(false);
    const [search, setSearch] = useState('');

    useEffect(() => {
        const controller = new AbortController();

        const fetchData = async () => {
            try {
                setLoading(true);
                const { data } = await axios.get<PermissionResponse>(
                    `/admin/roles/${role.id}/permissions`,
                    { signal: controller.signal },
                );
                setAllPermissions(data.all_permissions ?? []);
                setLockedPermissions(data.locked_permissions ?? []);
                setCheckedPermissions((data.role_permissions ?? []).map(Number));
            } catch (error) {
                if (!axios.isCancel(error)) {
                    message.error(errorMessage(error, 'Không thể tải danh sách quyền.'));
                }
            } finally {
                if (!controller.signal.aborted) setLoading(false);
            }
        };

        fetchData();

        return () => controller.abort();
    }, [role.id]);

    const groupedPermissions = useMemo(() => {
        const keyword = search.trim().toLocaleLowerCase('vi');

        return allPermissions
            .filter(permission => !keyword || (permission.name ?? '').toLocaleLowerCase('vi').includes(keyword))
            .reduce<Record<string, IPermissions[]>>((groups, permission) => {
                const group = permission.name?.split('.')[0] || 'other';
                (groups[group] ??= []).push(permission);
                return groups;
            }, {});
    }, [allPermissions, search]);

    const setGroupChecked = (ids: number[], checked: boolean) => {
        setCheckedPermissions(current => {
            const next = new Set(current);
            ids.forEach(id => checked ? next.add(id) : next.delete(id));
            return Array.from(next);
        });
    };

    const handleSubmit = async () => {
        if (loading || saving) return;

        setSaving(true);
        try {
            const { data } = await axios.post(`/admin/roles/${role.id}/permissions/update`, {
                permissions: checkedPermissions,
            });
            setCheckedPermissions((data.role_permissions ?? checkedPermissions).map(Number));
            message.success(data.message ?? 'Đã cập nhật quyền cho vai trò.');
            onSaved();
            onClose();
        } catch (error) {
            message.error(errorMessage(error, 'Không thể cập nhật quyền cho vai trò.'));
        } finally {
            setSaving(false);
        }
    };

    return (
        <ConfigProvider theme={{ algorithm: darkMode ? theme.darkAlgorithm : theme.defaultAlgorithm }}>
            <Modal
                title={`Gán quyền cho vai trò: ${role.name ?? `#${role.id}`}`}
                open
                onCancel={onClose}
                onOk={handleSubmit}
                okText="Lưu thay đổi"
                cancelText="Huỷ"
                confirmLoading={saving}
                okButtonProps={{ disabled: loading }}
                width={1000}
                styles={{ body: { maxHeight: '68vh', overflowY: 'auto' } }}
            >
                {loading ? <Skeleton active paragraph={{ rows: 9 }} /> : (
                    <div className="space-y-4">
                        <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                            <Input.Search
                                allowClear
                                value={search}
                                onChange={event => setSearch(event.target.value)}
                                placeholder="Tìm quyền, ví dụ: users.view"
                                className="sm:max-w-md"
                            />
                            <div className="flex flex-wrap items-center gap-2">
                                <Tag color="blue">{checkedPermissions.length} quyền đã chọn</Tag>
                                <Button size="small" onClick={() => setCheckedPermissions(allPermissions.map(item => item.id))}>
                                    Chọn tất cả
                                </Button>
                                <Button size="small" onClick={() => setCheckedPermissions([])}>
                                    Bỏ tất cả
                                </Button>
                            </div>
                        </div>

                        {lockedPermissions.length > 0 && (
                            <Alert
                                type="info"
                                showIcon
                                message={`${lockedPermissions.length} quyền được giữ nguyên`}
                                description="Tài khoản của bạn không có quyền thay đổi các quyền này nên hệ thống sẽ không gỡ chúng khỏi vai trò."
                            />
                        )}

                        {Object.keys(groupedPermissions).length === 0 ? (
                            <Empty image={Empty.PRESENTED_IMAGE_SIMPLE} description="Không tìm thấy quyền phù hợp" />
                        ) : (
                            <Checkbox.Group
                                value={checkedPermissions}
                                onChange={values => setCheckedPermissions(values.map(Number))}
                                className="w-full space-y-4"
                                disabled={saving}
                            >
                                {Object.entries(groupedPermissions).map(([group, permissions]) => {
                                    const ids = permissions.map(permission => permission.id);
                                    const selectedCount = ids.filter(id => checkedPermissions.includes(id)).length;
                                    const allSelected = selectedCount === ids.length;

                                    return (
                                        <section key={group} className="rounded-xl border border-slate-200 p-4 dark:border-slate-700">
                                            <div className="mb-3 flex items-center justify-between gap-3">
                                                <div>
                                                    <h3 className="font-semibold uppercase text-slate-700 dark:text-slate-200">{group}</h3>
                                                    <span className="text-xs text-slate-500">{selectedCount}/{ids.length} quyền</span>
                                                </div>
                                                <Button size="small" onClick={() => setGroupChecked(ids, !allSelected)}>
                                                    {allSelected ? 'Bỏ chọn nhóm' : 'Chọn nhóm'}
                                                </Button>
                                            </div>
                                            <div className="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                                                {permissions.map(permission => (
                                                    <label key={permission.id} className="rounded-lg border border-slate-200 p-3 transition hover:border-blue-400 dark:border-slate-700">
                                                        <Checkbox value={permission.id}>{permission.name}</Checkbox>
                                                    </label>
                                                ))}
                                            </div>
                                        </section>
                                    );
                                })}
                            </Checkbox.Group>
                        )}
                    </div>
                )}
            </Modal>
        </ConfigProvider>
    );
};

export default RoleHasPermissionModal;
