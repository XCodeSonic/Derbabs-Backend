// src/components/NotificationBell.jsx
import React from 'react';
import { Badge, Button, Tooltip } from 'antd';
import { BellOutlined } from '@ant-design/icons';
import { useNotificationContext } from '../contexts/NotificationContext';
import { useNavigate } from 'react-router-dom';

const NotificationBell = () => {
    const navigate = useNavigate();
    const { unreadCount, hasUnread, isLoading } = useNotificationContext();
    
    return (
        <Tooltip title={`${unreadCount} unread notification${unreadCount !== 1 ? 's' : ''}`}>
            <Badge count={unreadCount} offset={[-5, 5]} size="small">
                <Button
                    type="text"
                    icon={<BellOutlined style={{ fontSize: 18 }} />}
                    onClick={() => navigate('/notifications')}
                    style={{ 
                        color: hasUnread ? '#1890ff' : undefined,
                        fontWeight: hasUnread ? 'bold' : 'normal'
                    }}
                    loading={isLoading}
                />
            </Badge>
        </Tooltip>
    );
};

export default NotificationBell;