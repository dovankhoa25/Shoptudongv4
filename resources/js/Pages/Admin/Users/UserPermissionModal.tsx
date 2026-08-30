import { useEffect, useMemo, useState } from 'react';
import { Checkbox, ConfigProvider, Empty, Modal, Skeleton, Tag, message, theme } from 'antd';
import axios from 'axios';
import { IUser } from '@/InterFaces/user';
import { IRole } from '@/InterFaces/role';
import { IPermissions } from '@/InterFaces/permission';
import { useTheme } from '@/Providers/ThemeProvider';

interface Props { user: IUser; onClose: () => void; onSaved?: () => void }

export default function UserPermissionModal({ user, onClose, onSaved = () => undefined }: Props) {
    const { darkMode } = useTheme();
    const [roles, setRoles] = useState<IRole[]>([]);
    const [permissions, setPermissions] = useState<IPermissions[]>([]);
    const [checkedRoles, setCheckedRoles] = useState<number[]>([]);
    const [checkedPermissions, setCheckedPermissions] = useState<number[]>([]);
    const [loading, setLoading] = useState(true);
    const [saving, setSaving] = useState(false);

    useEffect(() => {
        let active = true;
        setLoading(true);
        axios.get(`/admin/users/${user.id}/permissions`)
            .then(({ data }) => {
                if (!active) return;
                setRoles(data.all_roles ?? []);
                setPermissions(data.all_permissions ?? []);
                setCheckedRoles(data.user_roles ?? []);
                setCheckedPermissions(data.user_permissions ?? []);
            })
            .catch(error => message.error(error?.response?.data?.message || 'Không thể tải dữ liệu phân quyền.'))
            .finally(() => active && setLoading(false));
        return () => { active = false; };
    }, [user.id]);

    const groupedPermissions = useMemo(() => permissions.reduce<Record<string, IPermissions[]>>((groups, permission) => {
        const group = permission.name?.split('.')[0] || 'other';
        (groups[group] ??= []).push(permission);
        return groups;
    }, {}), [permissions]);

    const submit = async () => {
        setSaving(true);
        try {
            await axios.post(`/admin/users/${user.id}/assign`, { roles: checkedRoles, permissions: checkedPermissions });
            message.success('Đã cập nhật vai trò và quyền.');
            onSaved();
            onClose();
        } catch (error: any) {
            message.error(error?.response?.data?.message || 'Không thể cập nhật phân quyền.');
        } finally {
            setSaving(false);
        }
    };

    return (
        <ConfigProvider theme={{ algorithm: darkMode ? theme.darkAlgorithm : theme.defaultAlgorithm }}>
            <Modal open width={900} title={`Vai trò & quyền: ${user.username}`} onCancel={onClose} onOk={submit}
                okText="Lưu thay đổi" cancelText="Hủy" confirmLoading={saving} okButtonProps={{ disabled: loading }}
                styles={{ body: { maxHeight: '68vh', overflowY: 'auto' } }}>
                {loading ? <Skeleton active paragraph={{ rows: 8 }} /> : (
                    <div className="space-y-6">
                        <section>
                            <h3 className="mb-3 font-semibold text-slate-900 dark:text-white">Vai trò có thể cấp</h3>
                            {roles.length ? (
                                <Checkbox.Group value={checkedRoles} onChange={values => setCheckedRoles(values as number[])} className="grid w-full gap-2 sm:grid-cols-2">
                                    {roles.map(role => <label key={role.id} className="rounded-xl border border-slate-200 p-3 dark:border-slate-700"><Checkbox value={role.id}>{role.name}</Checkbox></label>)}
                                </Checkbox.Group>
                            ) : <Empty image={Empty.PRESENTED_IMAGE_SIMPLE} description="Không có vai trò nào có thể cấp" />}
                        </section>

                        <section>
                            <div className="mb-3 flex items-center justify-between gap-3">
                                <h3 className="font-semibold text-slate-900 dark:text-white">Quyền cấp trực tiếp</h3>
                                <Tag>{checkedPermissions.length} quyền đã chọn</Tag>
                            </div>
                            <Checkbox.Group value={checkedPermissions} onChange={values => setCheckedPermissions(values as number[])} className="w-full space-y-4">
                                {Object.entries(groupedPermissions).map(([group, items]) => (
                                    <div key={group} className="rounded-xl border border-slate-200 p-4 dark:border-slate-700">
                                        <div className="mb-3 text-sm font-semibold uppercase text-slate-500">{group}</div>
                                        <div className="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                                            {items.map(permission => <Checkbox key={permission.id} value={permission.id}>{permission.name}</Checkbox>)}
                                        </div>
                                    </div>
                                ))}
                            </Checkbox.Group>
                        </section>
                    </div>
                )}
            </Modal>
        </ConfigProvider>
    );
}
