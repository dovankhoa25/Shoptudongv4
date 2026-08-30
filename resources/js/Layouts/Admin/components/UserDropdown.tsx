// resources/js/Layouts/Admin/components/UserDropdown.tsx - Fixed z-index
import React from 'react';
import { router } from '@inertiajs/react';
import { Dropdown, Badge, Avatar } from 'antd';
import { UserOutlined, LogoutOutlined } from '@ant-design/icons';
import type { MenuProps } from 'antd';
import { formatNumber } from '@/Utils/currencyHelper';

interface UserDropdownProps {
    user: any;
    roles: any;
    compact?: boolean;
}

export default function UserDropdown({ user, roles, compact = false }: UserDropdownProps) {
    const handleLogout = () => {
        router.post('/logout');
    };

    const items: MenuProps['items'] = [
        {
            key: 'user-info',
            type: 'group',
            label: (
                <div className="py-2 px-1 min-w-[280px]">
                    <div className="flex items-center space-x-3 mb-3">
                        <Avatar
                            size={48}
                            src={user?.avatar}
                            icon={<UserOutlined />}
                            className="bg-gradient-to-r from-blue-500 to-purple-600"
                        />
                        <div className="flex-1 min-w-0">
                            <p className="font-semibold text-gray-700 dark:text-green-500 truncate text-sm">
                                {user?.username}
                            </p>
                            <p className="text-xs text-gray-500 dark:text-gray-400 truncate">
                                {user?.email}
                            </p>
                            <p className="text-xs text-blue-600 dark:text-blue-400 font-medium mt-1">
                                {formatNumber(user?.balance)} VNĐ
                            </p>
                        </div>
                    </div>

                </div>
            ),
        },
        { type: 'divider' },
        {
            key: 'profile',
            icon: <UserOutlined />,
            label: 'Thông tin cá nhân',
            onClick: () => router.visit('/profile'),
        },
        { type: 'divider' },
        {
            key: 'logout',
            icon: <LogoutOutlined />,
            label: 'Đăng xuất',
            danger: true,
            onClick: handleLogout,
        },
    ];

    return (
        <Dropdown
            menu={{ items }}
            trigger={['click']}
            placement="bottomRight"
            // CRITICAL: This makes dropdown render at body level, not inside header
            getPopupContainer={() => document.body}
            dropdownRender={(menu) => (
                <div className="ant-dropdown-menu-wrapper" style={{ zIndex: 10000 }}>
                    {menu}
                </div>
            )}
        >
            <button
                className={`
                    flex items-center rounded-lg transition-all duration-200 hover:bg-gray-100 dark:hover:bg-gray-800
                    ${compact ? 'space-x-1.5 p-1.5' : 'space-x-2 p-2'}
                `}
                onClick={(e) => e.preventDefault()}
            >
                <Badge dot={false}>
                    <Avatar
                        size={compact ? 28 : 32}
                        src={user?.avatar}
                        icon={<UserOutlined />}
                        className="bg-gradient-to-r from-blue-500 to-purple-600"
                    />
                </Badge>
                {!compact && (
                    <div className="hidden md:block text-left">
                        <p className="text-xs font-medium text-gray-700 dark:text-gray-200 truncate max-w-24">
                            {user?.username}
                        </p>
                        <p className="text-xs text-gray-500 dark:text-gray-400 truncate max-w-24">
                            {roles}
                        </p>
                    </div>
                )}
            </button>
        </Dropdown>
    );
}
