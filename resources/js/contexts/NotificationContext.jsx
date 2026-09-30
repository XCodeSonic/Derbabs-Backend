import React, { createContext, useContext, useMemo, useCallback } from 'react';
import { useUnreadCount, useNotifications } from '../hooks/useNotificationQueries';

const NotificationContext = createContext();

export const NotificationProvider = ({ children }) => {
    // SINGLE source of truth for unread count
    const { 
        data: unreadCount = 0, 
        refetch: refetchUnreadCount,
        isLoading: unreadLoading 
    } = useUnreadCount();
    
    // For preview (only 1 notification for header)
    const { data: previewData } = useNotifications({ 
        unread: true, 
        per_page: 1 
    });
    
    const previewNotification = previewData?.data?.[0] || null;
    
    const refreshUnreadCount = useCallback(() => {
        refetchUnreadCount();
    }, [refetchUnreadCount]);
    
    const value = useMemo(() => ({
        unreadCount,
        previewNotification,
        refreshUnreadCount,
        hasUnread: unreadCount > 0,
        isLoading: unreadLoading,
    }), [unreadCount, previewNotification, refreshUnreadCount, unreadLoading]);
    
    return (
        <NotificationContext.Provider value={value}>
            {children}
        </NotificationContext.Provider>
    );
};

export const useNotificationContext = () => {
    const context = useContext(NotificationContext);
    if (!context) {
        throw new Error('useNotificationContext must be used within NotificationProvider');
    }
    return context;
};