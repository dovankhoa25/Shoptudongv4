// resources/js/Layouts/Admin/components/NotificationDropdown.tsx - Fixed z-index
import React from 'react';
import { Dropdown, Badge, Empty } from 'antd';
import { BellOutlined, ClockCircleOutlined } from '@ant-design/icons';
import type { MenuProps } from 'antd';

interface Notification {
    id: string;
    title: string;
    message: string;
    time: string;
    type: string;
    read: boolean;
}

interface NotificationDropdownProps {
    notifications: Notification[];
    compact?: boolean;
}

export default function NotificationDropdown({ notifications, compact = false }: NotificationDropdownProps) {
    const unreadCount = notifications.filter(n => !n.read).length;

    const items: MenuProps['items'] = [
        {
            key: 'header',
            type: 'group',
            label: (
                <div className="py-2 px-3 border-b border-gray-200 dark:border-gray-700">
                    <div className="flex items-center justify-between">
                        <h3 className="font-semibold text-gray-800 dark:text-gray-200 text-sm">
                            Thông báo
                        </h3>
                        {unreadCount > 0 && (
                            <Badge count={unreadCount} />
                        )}
                    </div>
                </div>
            ),
        },
        ...notifications.length > 0
            ? notifications.map(notification => ({
                key: notification.id,
                label: (
                    <div className={`py-2 ${!notification.read ? 'bg-blue-50 dark:bg-blue-900/20' : ''}`}>
                        <div className="flex justify-between items-start mb-1">
                            <h4 className="font-medium text-sm text-gray-800 dark:text-gray-200">
                                {notification.title}
                            </h4>
                            {!notification.read && (
                                <span className="w-2 h-2 bg-blue-500 rounded-full ml-2"></span>
                            )}
                        </div>
                        <p className="text-xs text-gray-600 dark:text-gray-400 mb-1">
                            {notification.message}
                        </p>
                        <div className="flex items-center text-xs text-gray-500 dark:text-gray-500">
                            <ClockCircleOutlined className="mr-1" />
                            {notification.time}
                        </div>
                    </div>
                ),
            }))
            : [
                {
                    key: 'empty',
                    label: (
                        <div className="py-8 text-center">
                            <Empty
                                image={Empty.PRESENTED_IMAGE_SIMPLE}
                                description="Không có thông báo"
                            />
                        </div>
                    ),
                    disabled: true,
                }
            ]
    ];

    return (
        <Dropdown
            menu={{
                items,
                style: { maxHeight: '400px', overflowY: 'auto' }
            }}
            trigger={['click']}
            placement="bottomRight"
            // CRITICAL: Render at body level
            getPopupContainer={() => document.body}
            dropdownRender={(menu) => (
                <div style={{ zIndex: 10000, minWidth: '320px', maxWidth: '400px' }}>
                    {menu}
                </div>
            )}
        >
            <button
                className={`
                    relative rounded-lg transition-all duration-200
                    text-gray-600 dark:text-gray-400 hover:text-blue-500 dark:hover:text-blue-400
                    hover:bg-gray-100 dark:hover:bg-gray-800
                    ${compact ? 'p-1.5' : 'p-2'}
                `}
                onClick={(e) => e.preventDefault()}
            >
                <Badge count={unreadCount} size="small">
                    <BellOutlined style={{ fontSize: compact ? 16 : 18 }} />
                </Badge>
            </button>
        </Dropdown>
    );
}
