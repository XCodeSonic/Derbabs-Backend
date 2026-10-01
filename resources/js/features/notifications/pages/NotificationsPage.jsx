import React, { useState, useEffect, useCallback, useMemo, useRef } from 'react';
import { 
    Badge, Button, Avatar, Empty, Typography, Space, Tag, Tooltip, 
    Checkbox, Input, Select, Pagination, Modal, message, Dropdown, 
    Tabs, Divider, ConfigProvider, theme as antdTheme, Spin, Card, 
    Row, Col, Statistic, Alert, Progress, Switch, Collapse
} from 'antd';
import { 
    BellOutlined, CheckCircleOutlined, CloseCircleOutlined, 
    InfoCircleOutlined, WarningOutlined, DeleteOutlined, CheckOutlined, 
    StarOutlined, StarFilled, MailOutlined, InboxOutlined, 
    ClockCircleOutlined, SearchOutlined, MoreOutlined, EyeOutlined, 
    FlagOutlined, FilterOutlined, ReloadOutlined, SettingOutlined,
    ShoppingOutlined, CalendarOutlined, TeamOutlined, DollarOutlined,
    FileTextOutlined, TruckOutlined, UserOutlined, ToolOutlined,
    LockOutlined, SyncOutlined, ShoppingCartOutlined, ExportOutlined,
    MenuFoldOutlined, MenuUnfoldOutlined, ThunderboltOutlined,
    UpOutlined, DownOutlined
} from '@ant-design/icons';
import api from '../../../services/api';
import dayjs from 'dayjs';
import relativeTime from 'dayjs/plugin/relativeTime';
import '../styles/notification.css';
import { 
    useNotifications, 
    useUnreadCount, 
    useMarkNotificationRead, 
    useDeleteNotification, 
    useMarkAllRead, 
    useToggleStar 
} from '../../../hooks/useNotificationQueries';

dayjs.extend(relativeTime);

const { Text, Title, Paragraph } = Typography;

// Helper to get notification ID
const getNotificationId = (notification) => {
    return notification?.notification_id || notification?.id;
};

// Check if notification is new (less than 5 minutes old)
const isNewNotification = (createdAt) => {
    if (!createdAt) return false;
    const now = dayjs();
    const created = dayjs(createdAt);
    const diffMinutes = now.diff(created, 'minutes');
    return diffMinutes < 5;
};

// Get notification icon based on type
const getNotificationIcon = (type, priority) => {
    const iconStyle = { fontSize: 20 };
    
    if (!type) return <InfoCircleOutlined style={{ ...iconStyle, color: '#1a7ab5' }} />;
    
    if (type.includes('booking') || type === 'booking_request' || type === 'booking_submitted') 
        return <BellOutlined style={{ ...iconStyle, color: '#1a7ab5' }} />;
    if (type === 'booking_approved' || type === 'booking_confirmed') 
        return <CheckCircleOutlined style={{ ...iconStyle, color: '#10b981' }} />;
    if (type === 'booking_rejected' || type === 'booking_cancelled') 
        return <CloseCircleOutlined style={{ ...iconStyle, color: '#ef4444' }} />;
    if (type === 'booking_rescheduled') 
        return <SyncOutlined style={{ ...iconStyle, color: '#f59e0b' }} />;
    
    if (type.includes('inventory') || type === 'low_stock' || type === 'critical_stock') 
        return <WarningOutlined style={{ ...iconStyle, color: '#f59e0b' }} />;
    if (type === 'out_of_stock') 
        return <CloseCircleOutlined style={{ ...iconStyle, color: '#ef4444' }} />;
    if (type === 'purchase_request_generated' || type === 'insufficient_inventory') 
        return <ShoppingCartOutlined style={{ ...iconStyle, color: '#ef4444' }} />;
    
    if (type.includes('equipment')) 
        return <ToolOutlined style={{ ...iconStyle, color: '#1a7ab5' }} />;
    
    if (type === 'order_ready') 
        return <CheckCircleOutlined style={{ ...iconStyle, color: '#10b981' }} />;
    
    if (type.includes('event')) 
        return <CalendarOutlined style={{ ...iconStyle, color: '#f59e0b' }} />;
    
    if (type.includes('schedule') || type.includes('staff')) 
        return <TeamOutlined style={{ ...iconStyle, color: '#1a7ab5' }} />;
    
    if (type.includes('payroll') || type.includes('overtime')) 
        return <DollarOutlined style={{ ...iconStyle, color: '#10b981' }} />;
    
    if (type.includes('payment')) 
        return <DollarOutlined style={{ ...iconStyle, color: '#10b981' }} />;
    
    if (type === 'customer_review') 
        return <StarOutlined style={{ ...iconStyle, color: '#f59e0b' }} />;
    
    if (type === 'failed_login') 
        return <LockOutlined style={{ ...iconStyle, color: '#ef4444' }} />;
    
    return <InfoCircleOutlined style={{ ...iconStyle, color: '#1a7ab5' }} />;
};

const getPriorityColor = (priority) => {
    const colors = { critical: '#ef4444', high: '#ef4444', medium: '#f59e0b', low: '#10b981' };
    return colors[priority] || '#1a7ab5';
};

const getPriorityBg = (priority) => {
    const colors = { critical: '#fef2f2', high: '#fef2f2', medium: '#fffbeb', low: '#e6f7ec' };
    return colors[priority] || '#e8f4fd';
};

// Notification Item Component with "only once read" protection
const NotificationItem = React.memo(({ notification, onToggleStar, onMarkAsRead, onDelete, selected, onSelect, isDarkMode }) => {
    const [showActions, setShowActions] = useState(false);
    const [isMarkingRead, setIsMarkingRead] = useState(false);
    const hasMarkedReadRef = useRef(false);
    const markTimeoutRef = useRef(null);
    
    const notificationId = getNotificationId(notification);
    const isNew = !notification.read_at && isNewNotification(notification.created_at);
    const isAlreadyRead = notification.read_at !== null;
    
    if (!notificationId) return null;
    
    const priorityColor = getPriorityColor(notification.priority);
    const priorityBg = getPriorityBg(notification.priority);
    
    // Cleanup timeout on unmount
    useEffect(() => {
        return () => {
            if (markTimeoutRef.current) {
                clearTimeout(markTimeoutRef.current);
            }
        };
    }, []);
    
    // Handle click to mark as read - ONLY ONCE
    const handleMarkAsRead = useCallback(async (e) => {
        if (e) e.stopPropagation();
        
        // Prevent multiple attempts
        if (isAlreadyRead || isMarkingRead || hasMarkedReadRef.current) {
            return;
        }
        
        // Set flags immediately
        setIsMarkingRead(true);
        hasMarkedReadRef.current = true;
        
        // Set timeout to reset flag after 3 seconds (in case of failure)
        markTimeoutRef.current = setTimeout(() => {
            setIsMarkingRead(false);
            hasMarkedReadRef.current = false;
        }, 3000);
        
        try {
            await onMarkAsRead(notificationId);
            // Clear the reset timeout on success
            if (markTimeoutRef.current) {
                clearTimeout(markTimeoutRef.current);
            }
        } catch (error) {
            console.error('Failed to mark as read:', error);
            // Reset flags on error so user can try again
            setIsMarkingRead(false);
            hasMarkedReadRef.current = false;
        }
    }, [notificationId, isAlreadyRead, isMarkingRead, onMarkAsRead]);
    
    return (
        <div 
            className={`notification-item ${!notification.read_at ? 'unread' : ''}`}
            data-is-new={isNew ? "true" : "false"}
            style={{ 
                borderBottom: `1px solid ${isDarkMode ? '#1e2340' : '#eef2f8'}`, 
                backgroundColor: !notification.read_at ? (isDarkMode ? '#1a7ab520' : '#fafcff') : (isDarkMode ? '#13172b' : '#ffffff'),
                cursor: (!isAlreadyRead && !isMarkingRead) ? 'pointer' : 'default',
                opacity: isMarkingRead ? 0.7 : 1
            }}
            onMouseEnter={() => setShowActions(true)}
            onMouseLeave={() => setShowActions(false)}
            onClick={handleMarkAsRead}
        >
            <div className="notification-item-content">
                <div className="notification-checkbox">
                    <Checkbox 
                        checked={selected} 
                        onChange={(e) => onSelect(notificationId, e.target.checked)} 
                        onClick={(e) => e.stopPropagation()} 
                    />
                </div>
                
                <div className="notification-star" onClick={(e) => { e.stopPropagation(); onToggleStar(notificationId); }}>
                    {notification.starred ? 
                        <StarFilled style={{ color: '#f59e0b' }} /> : 
                        <StarOutlined style={{ color: '#cbd5e1' }} />
                    }
                </div>
                
                <div className="notification-icon-wrapper">
                    {getNotificationIcon(notification.type, notification.priority)}
                </div>
                
                <div className="notification-details">
                    <div className="notification-header">
                        <div className="notification-sender">
                            <Text strong={!notification.read_at} className="sender-name">
                                {notification.sender_name || 'System'}
                            </Text>
                            <Tag className="priority-tag" style={{ backgroundColor: priorityBg, border: 'none', color: priorityColor }}>
                                <ThunderboltOutlined style={{ fontSize: 10, marginRight: 4 }} />
                                {notification.priority?.toUpperCase() || 'NORMAL'}
                            </Tag>
                            {!notification.read_at && (
                                <>
                                    <Badge status="processing" className="unread-badge-dot" />
                                    {isNew && <span className="new-badge">NEW</span>}
                                    {isMarkingRead && <span style={{ marginLeft: 8, fontSize: 12, color: '#f59e0b' }}>Marking read...</span>}
                                </>
                            )}
                        </div>
                        <Tooltip title={dayjs(notification.created_at).format('YYYY-MM-DD HH:mm:ss')}>
                            <Text className="notification-time">
                                <ClockCircleOutlined style={{ fontSize: 11, marginRight: 4 }} />
                                {dayjs(notification.created_at).fromNow()}
                            </Text>
                        </Tooltip>
                    </div>
                    
                    <Title level={5} className={`notification-title ${!notification.read_at ? 'unread' : ''}`}>
                        {notification.title}
                    </Title>
                    
                    <Paragraph className="notification-message" type="secondary" ellipsis={{ rows: 2 }}>
                        {notification.message}
                    </Paragraph>
                    
                    {notification.action_url && (
                        <div className="notification-action">
                            <Button 
                                type="link" 
                                size="small" 
                                href={notification.action_url}
                                className="action-link"
                                onClick={(e) => e.stopPropagation()}
                            >
                                View Details →
                            </Button>
                        </div>
                    )}
                </div>
                
                {showActions && (
                    <div className="notification-actions">
                        <Dropdown 
                            dropdownRender={() => (
                                <div style={{ padding: 8, background: '#fff', borderRadius: 8, boxShadow: '0 2px 8px rgba(0,0,0,0.15)' }}>
                                    <div 
                                        onClick={handleMarkAsRead} 
                                        style={{ padding: '8px 12px', cursor: 'pointer' }}
                                    >
                                        <EyeOutlined /> {notification.read_at ? 'Mark unread' : 'Mark read'}
                                    </div>
                                    <div 
                                        onClick={(e) => { e.stopPropagation(); onToggleStar(notificationId); }} 
                                        style={{ padding: '8px 12px', cursor: 'pointer' }}
                                    >
                                        {notification.starred ? <StarOutlined /> : <StarFilled style={{ color: '#f59e0b' }} />}
                                        {notification.starred ? 'Unstar' : 'Star'}
                                    </div>
                                    <div style={{ height: 1, background: '#f0f0f0', margin: '4px 0' }} />
                                    <div 
                                        onClick={(e) => { e.stopPropagation(); onDelete(notificationId); }} 
                                        style={{ padding: '8px 12px', cursor: 'pointer', color: '#ff4d4f' }}
                                    >
                                        <DeleteOutlined /> Delete
                                    </div>
                                </div>
                            )} 
                            trigger={['click']}
                        >
                            <Button type="text" icon={<MoreOutlined />} size="small" onClick={(e) => e.stopPropagation()} />
                        </Dropdown>
                    </div>
                )}
            </div>
        </div>
    );
});

NotificationItem.displayName = 'NotificationItem';

const NotificationPage = () => {
    const [activeTab, setActiveTab] = useState('all');
    const [selectedIds, setSelectedIds] = useState([]);
    const [filterPriority, setFilterPriority] = useState('all');
    const [filterType, setFilterType] = useState('all');
    const [currentPage, setCurrentPage] = useState(1);
    const [pageSize, setPageSize] = useState(10);
    const [isDarkMode, setIsDarkMode] = useState(false);
    const [filtersVisible, setFiltersVisible] = useState(false);
    const [messageApi, contextHolder] = message.useMessage();

    // Build query params - STABLE reference using useMemo
    const queryParams = useMemo(() => {
        const params = { page: currentPage, per_page: pageSize };
        
        // Handle tab filtering
        if (activeTab === 'unread') {
            params.unread = true;
        } else if (activeTab === 'starred') {
            params.starred = true;
        }
        
        // Handle type filter
        if (filterType !== 'all') {
            params.type = filterType;
        }
        
        // Handle priority filter
        if (filterPriority !== 'all') {
            params.priority = filterPriority;
        }
        
        return params;
    }, [currentPage, pageSize, activeTab, filterType, filterPriority]);

    // Use React Query hooks - ONLY these handle polling
    const { 
        data: notificationsData, 
        isLoading: notificationsLoading,
        refetch: refetchNotifications
    } = useNotifications(queryParams);
    
    const { 
        data: unreadCount = 0, 
        refetch: refetchUnreadCount 
    } = useUnreadCount();

    // Mutations
    const markReadMutation = useMarkNotificationRead();
    const deleteMutation = useDeleteNotification();
    const markAllReadMutation = useMarkAllRead();
    const toggleStarMutation = useToggleStar();

    // Extract data from React Query response
    const notifications = notificationsData?.data || [];
    const total = notificationsData?.total || 0;
    
    // Calculate high priority count from notifications
    const highPriorityCount = useMemo(() => {
        return notifications.filter(n => n.priority === 'high' && !n.read_at).length;
    }, [notifications]);

    // Theme detection - runs once
    useEffect(() => {
        const savedTheme = localStorage.getItem('theme');
        setIsDarkMode(savedTheme === 'dark' || (savedTheme !== 'light' && document.body.classList.contains('dark-mode')));
        const observer = new MutationObserver(() => setIsDarkMode(document.body.classList.contains('dark-mode')));
        observer.observe(document.body, { attributes: true, attributeFilter: ['class'] });
        return () => observer.disconnect();
    }, []);

    // Listen for custom events to refresh data - NO polling here, only on specific events
    useEffect(() => {
        const handleRefresh = () => {
            // Only refresh notifications, not all data
            refetchNotifications();
            refetchUnreadCount();
        };
        
        window.addEventListener('booking-approved', handleRefresh);
        window.addEventListener('new-notification', handleRefresh);
        
        return () => {
            window.removeEventListener('booking-approved', handleRefresh);
            window.removeEventListener('new-notification', handleRefresh);
        };
    }, [refetchNotifications, refetchUnreadCount]);

    // Mark single notification as read - with deduplication
    const markAsRead = useCallback(async (id) => {
        if (!id) return;
        try {
            await markReadMutation.mutateAsync(id);
        } catch (error) {
            // Error already handled in mutation
        }
    }, [markReadMutation]);

    // Delete single notification
    const deleteNotification = useCallback((id) => {
        if (!id) return;
        Modal.confirm({
            title: 'Delete Notification',
            content: 'Are you sure you want to delete this notification?',
            okText: 'Delete',
            okType: 'danger',
            onOk: async () => {
                try {
                    await deleteMutation.mutateAsync(id);
                    setSelectedIds(prev => prev.filter(i => i !== id));
                } catch (error) {
                    // Error already handled in mutation
                }
            }
        });
    }, [deleteMutation]);

    // Mark all as read
    const markAllRead = useCallback(async () => {
        if (unreadCount === 0) {
            messageApi.info('No unread notifications');
            return;
        }
        try {
            await markAllReadMutation.mutateAsync();
        } catch (error) {
            // Error already handled in mutation
        }
    }, [unreadCount, markAllReadMutation, messageApi]);

    // Toggle star
    const toggleStar = useCallback(async (id) => {
        if (!id) return;
        try {
            await toggleStarMutation.mutateAsync(id);
        } catch (error) {
            console.error('Failed to toggle star:', error);
        }
    }, [toggleStarMutation]);

    // Bulk actions
    const markSelectedAsRead = useCallback(async () => {
        if (selectedIds.length === 0) return;
        
        // Use Promise.allSettled to handle partial failures
        const results = await Promise.allSettled(
            selectedIds.map(id => markReadMutation.mutateAsync(id))
        );
        
        const successCount = results.filter(r => r.status === 'fulfilled').length;
        
        if (successCount > 0) {
            setSelectedIds([]);
            messageApi.success(`${successCount} of ${selectedIds.length} notifications marked as read`);
        } else {
            messageApi.error('Failed to mark notifications as read');
        }
    }, [selectedIds, markReadMutation, messageApi]);

    const deleteSelected = useCallback(() => {
        if (selectedIds.length === 0) return;
        Modal.confirm({
            title: 'Delete Notifications',
            content: `Delete ${selectedIds.length} notification(s)?`,
            onOk: async () => {
                const results = await Promise.allSettled(
                    selectedIds.map(id => deleteMutation.mutateAsync(id))
                );
                
                const successCount = results.filter(r => r.status === 'fulfilled').length;
                
                if (successCount > 0) {
                    setSelectedIds([]);
                    messageApi.success(`${successCount} of ${selectedIds.length} notifications deleted`);
                } else {
                    messageApi.error('Failed to delete notifications');
                }
            }
        });
    }, [selectedIds, deleteMutation, messageApi]);

    const handleSelectAll = useCallback((e) => {
        if (e.target.checked) {
            setSelectedIds(notifications.map(n => getNotificationId(n)));
        } else {
            setSelectedIds([]);
        }
    }, [notifications]);

    const handleSelect = useCallback((id, checked) => {
        if (checked) {
            setSelectedIds(prev => [...prev, id]);
        } else {
            setSelectedIds(prev => prev.filter(i => i !== id));
        }
    }, []);

    // Manual refresh handler
    const handleManualRefresh = useCallback(() => {
        refetchNotifications();
        refetchUnreadCount();
        messageApi.success('Refreshed');
    }, [refetchNotifications, refetchUnreadCount, messageApi]);

    const tabItems = [
        { key: 'all', label: 'All', icon: <InboxOutlined />, badge: total },
        { key: 'unread', label: 'Unread', icon: <MailOutlined />, badge: unreadCount },
        { key: 'starred', label: 'Starred', icon: <StarOutlined /> },
        { key: 'action', label: 'Action Required', icon: <FlagOutlined />, badge: highPriorityCount }
    ];

    return (
        <ConfigProvider theme={{ algorithm: isDarkMode ? antdTheme.darkAlgorithm : antdTheme.defaultAlgorithm }}>
            {contextHolder}
            <div className={`notification-page ${isDarkMode ? 'np-dark-mode' : ''}`}>
                {/* Header */}
                <div className="notification-header">
                    <div className="notification-header-left">
                        <div className="notification-logo-icon">
                            <BellOutlined />
                        </div>
                        <div className="notification-header-info">
                            <h1>Notification Center</h1>
                            <span>Stay updated with real-time alerts</span>
                        </div>
                    </div>
                    <div className="notification-header-right">
                        <Tooltip title="Refresh">
                            <Button 
                                icon={<ReloadOutlined />} 
                                onClick={handleManualRefresh} 
                                loading={notificationsLoading} 
                            />
                        </Tooltip>
                        <Tooltip title="Mark all as read">
                            <Button 
                                icon={<CheckOutlined />} 
                                onClick={markAllRead} 
                                disabled={unreadCount === 0} 
                            />
                        </Tooltip>
                        <Tooltip title="Export notifications">
                            <Button icon={<ExportOutlined />} />
                        </Tooltip>
                        <Tooltip title="Show Filters">
                            <Button 
                                type={filtersVisible ? 'primary' : 'default'}
                                icon={<FilterOutlined />} 
                                onClick={() => setFiltersVisible(!filtersVisible)}
                            />
                        </Tooltip>
                    </div>
                </div>

                {/* Stats Cards */}
                <div className="notification-stats-grid">
                    <div className="stat-card">
                        <div className="stat-icon blue"><BellOutlined /></div>
                        <div className="stat-info">
                            <div className="stat-value">{total}</div>
                            <div className="stat-label">Total Notifications</div>
                        </div>
                    </div>
                    <div className="stat-card">
                        <div className="stat-icon orange"><MailOutlined /></div>
                        <div className="stat-info">
                            <div className="stat-value">{unreadCount}</div>
                            <div className="stat-label">Unread</div>
                        </div>
                    </div>
                    <div className="stat-card">
                        <div className="stat-icon green"><StarOutlined /></div>
                        <div className="stat-info">
                            <div className="stat-value">{notifications.filter(n => n.starred).length}</div>
                            <div className="stat-label">Starred</div>
                        </div>
                    </div>
                    <div className="stat-card">
                        <div className="stat-icon red"><FlagOutlined /></div>
                        <div className="stat-info">
                            <div className="stat-value">{highPriorityCount}</div>
                            <div className="stat-label">Action Required</div>
                        </div>
                    </div>
                </div>

                {/* Collapsible Filters Section */}
                {filtersVisible && (
                    <div className="notification-filters-panel">
                        <div className="filters-header">
                            <FilterOutlined />
                            <span>Filter Notifications</span>
                            <Button type="text" size="small" icon={<UpOutlined />} onClick={() => setFiltersVisible(false)} />
                        </div>
                        <div className="filters-body">
                            <div className="filter-group">
                                <div className="filter-label">Notification Type</div>
                                <div className="filter-options">
                                    <div 
                                        className={`filter-chip ${filterType === 'all' ? 'active' : ''}`}
                                        onClick={() => {
                                            setFilterType('all');
                                            setCurrentPage(1);
                                        }}
                                    >
                                        <span>All</span>
                                    </div>
                                    <div 
                                        className={`filter-chip ${filterType === 'booking' ? 'active' : ''}`}
                                        onClick={() => {
                                            setFilterType('booking');
                                            setCurrentPage(1);
                                        }}
                                    >
                                        <CalendarOutlined />
                                        <span>Bookings</span>
                                    </div>
                                    <div 
                                        className={`filter-chip ${filterType === 'inventory' ? 'active' : ''}`}
                                        onClick={() => {
                                            setFilterType('inventory');
                                            setCurrentPage(1);
                                        }}
                                    >
                                        <ShoppingOutlined />
                                        <span>Inventory</span>
                                    </div>
                                    <div 
                                        className={`filter-chip ${filterType === 'equipment' ? 'active' : ''}`}
                                        onClick={() => {
                                            setFilterType('equipment');
                                            setCurrentPage(1);
                                        }}
                                    >
                                        <ToolOutlined />
                                        <span>Equipment</span>
                                    </div>
                                </div>
                            </div>
                            <div className="filter-group">
                                <div className="filter-label">Priority Level</div>
                                <div className="filter-options">
                                    <div 
                                        className={`filter-chip ${filterPriority === 'all' ? 'active' : ''}`} 
                                        onClick={() => {
                                            setFilterPriority('all');
                                            setCurrentPage(1);
                                        }}
                                    >
                                        <span className="priority-dot all" /> All
                                    </div>
                                    <div 
                                        className={`filter-chip ${filterPriority === 'high' ? 'active' : ''}`} 
                                        onClick={() => {
                                            setFilterPriority('high');
                                            setCurrentPage(1);
                                        }}
                                    >
                                        <span className="priority-dot high" /> High
                                    </div>
                                    <div 
                                        className={`filter-chip ${filterPriority === 'medium' ? 'active' : ''}`} 
                                        onClick={() => {
                                            setFilterPriority('medium');
                                            setCurrentPage(1);
                                        }}
                                    >
                                        <span className="priority-dot medium" /> Medium
                                    </div>
                                    <div 
                                        className={`filter-chip ${filterPriority === 'low' ? 'active' : ''}`} 
                                        onClick={() => {
                                            setFilterPriority('low');
                                            setCurrentPage(1);
                                        }}
                                    >
                                        <span className="priority-dot low" /> Low
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                )}

                {/* Main Content */}
                <div className="notification-main-content">
                    {/* Tabs */}
                    <Tabs 
                        activeKey={activeTab} 
                        onChange={(key) => {
                            setActiveTab(key);
                            setCurrentPage(1);
                            setSelectedIds([]);
                        }} 
                        className="notification-tabs"
                        items={tabItems.map(tab => ({ 
                            key: tab.key, 
                            label: (
                                <span className="tab-label">
                                    {tab.icon}
                                    {tab.label}
                                    {tab.badge !== undefined && tab.badge > 0 && (
                                        <Badge count={tab.badge} className="tab-badge" />
                                    )}
                                </span>
                            )
                        }))} 
                    />

                    {/* Action Toolbar */}
                    <div className="notification-action-toolbar">
                        <div className="toolbar-left">
                            <Checkbox 
                                checked={selectedIds.length === notifications.length && notifications.length > 0} 
                                indeterminate={selectedIds.length > 0 && selectedIds.length < notifications.length} 
                                onChange={handleSelectAll}
                                disabled={notifications.length === 0}
                            >
                                Select all
                            </Checkbox>
                            {selectedIds.length > 0 && (
                                <div className="bulk-actions">
                                    <Divider type="vertical" />
                                    <Button size="small" icon={<CheckOutlined />} onClick={markSelectedAsRead}>
                                        Mark read ({selectedIds.length})
                                    </Button>
                                    <Button size="small" danger icon={<DeleteOutlined />} onClick={deleteSelected}>
                                        Delete ({selectedIds.length})
                                    </Button>
                                </div>
                            )}
                        </div>
                        <div className="toolbar-right">
                            <Text type="secondary">
                                <InboxOutlined /> {notifications.length} of {total} notifications
                            </Text>
                        </div>
                    </div>

                    {/* Scrollable Table Container */}
                    <div className="notifications-table-container">
                        {notificationsLoading && notifications.length === 0 ? (
                            <div className="loading-wrapper">
                                <div className="loading-container">
                                    <Spin size="large" />
                                    <p>Loading notifications...</p>
                                </div>
                            </div>
                        ) : notifications.length === 0 ? (
                            <div className="empty-container">
                                <BellOutlined className="empty-icon" />
                                <Empty 
                                    description={
                                        filterPriority !== 'all' || filterType !== 'all' || activeTab !== 'all'
                                            ? "No matching notifications found"
                                            : "No notifications yet"
                                    }
                                />
                            </div>
                        ) : (
                            <>
                                <div className="notifications-list">
                                    {notifications.map(notification => (
                                        <NotificationItem 
                                            key={`notification-${getNotificationId(notification)}`}
                                            notification={notification} 
                                            onToggleStar={toggleStar} 
                                            onMarkAsRead={markAsRead} 
                                            onDelete={deleteNotification} 
                                            selected={selectedIds.includes(getNotificationId(notification))} 
                                            onSelect={handleSelect} 
                                            isDarkMode={isDarkMode} 
                                        />
                                    ))}
                                </div>

                                {/* Pagination */}
                                {total > pageSize && (
                                    <div className="notification-pagination">
                                        <Pagination 
                                            current={currentPage} 
                                            pageSize={pageSize} 
                                            total={total} 
                                            onChange={(page, size) => { 
                                                setCurrentPage(page); 
                                                if (size !== pageSize) setPageSize(size); 
                                                setSelectedIds([]); 
                                            }} 
                                            showSizeChanger 
                                            showTotal={(total) => <span>{total} total items</span>} 
                                            pageSizeOptions={['10', '20', '50', '100']} 
                                        />
                                    </div>
                                )}
                            </>
                        )}
                    </div>
                </div>
            </div>
        </ConfigProvider>
    );
};

export default NotificationPage;