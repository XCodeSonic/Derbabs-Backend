// src/hooks/useNotificationQueries.js - UPDATED with fallback
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { message } from 'antd';
import api from '../services/api';

const markingReadMap = new Map();
const markingReadTimers = new Map();

export const useNotifications = (params = {}) => {
    return useQuery({
        queryKey: ['notifications', params],
        queryFn: () => api.get('/notifications', { params }),
        select: (response) => {
            let notifications = [];
            let total = 0;
            let unreadCount = 0;
            
            const respData = response.data;
            
            if (respData?.data?.data && Array.isArray(respData.data.data)) {
                notifications = respData.data.data;
                total = respData.data.total || 0;
                unreadCount = respData.unread_count || 0;
            } else if (respData?.data && Array.isArray(respData.data)) {
                notifications = respData.data;
                total = respData.total || 0;
                unreadCount = respData.unread_count || 0;
            }
            
            return {
                data: notifications,
                total: total,
                unread_count: unreadCount,
            };
        },
        staleTime: 30 * 1000,
        refetchOnWindowFocus: false,
        keepPreviousData: true,
    });
};

// FIXED: This will work even if backend endpoint is 404
export const useUnreadCount = () => {
    return useQuery({
        queryKey: ['notifications', 'unread-count'],
        queryFn: async () => {
            try {
                // First, try the dedicated endpoint
                const response = await api.get('/notifications/unread-count');
                return response?.data?.data?.count || response?.data?.count || 0;
            } catch (error) {
                console.log('Dedicated endpoint failed, using fallback...');
                
                // Fallback: Get unread count from main notifications endpoint
                try {
                    const fallbackResponse = await api.get('/notifications', {
                        params: { unread: true, per_page: 1 }
                    });
                    
                    // Extract unread_count from response
                    const unreadCount = fallbackResponse?.data?.unread_count || 
                                       fallbackResponse?.data?.data?.total || 
                                       0;
                    
                    console.log(`Unread count from fallback: ${unreadCount}`);
                    return unreadCount;
                } catch (fallbackError) {
                    console.error('Both endpoints failed:', fallbackError);
                    return 0; // Return 0 as default
                }
            }
        },
        staleTime: 30 * 1000,
        refetchOnWindowFocus: false,
        retry: 1,
    });
};

// Rest of your hooks remain the same...
export const useMarkNotificationRead = () => {
    const queryClient = useQueryClient();
    
    return useMutation({
        mutationFn: async (id) => {
            if (markingReadMap.has(id)) {
                console.log(`⚠️ Notification ${id} already being marked as read`);
                return null;
            }
            
            markingReadMap.set(id, true);
            
            if (markingReadTimers.has(id)) {
                clearTimeout(markingReadTimers.get(id));
            }
            
            markingReadTimers.set(id, setTimeout(() => {
                markingReadMap.delete(id);
                markingReadTimers.delete(id);
            }, 3000));
            
            try {
                const response = await api.post(`/notifications/${id}/read`);
                return response;
            } catch (error) {
                markingReadMap.delete(id);
                if (markingReadTimers.has(id)) {
                    clearTimeout(markingReadTimers.get(id));
                    markingReadTimers.delete(id);
                }
                throw error;
            }
        },
        onSuccess: (data, id) => {
            if (data) {
                queryClient.invalidateQueries({ queryKey: ['notifications'] });
                queryClient.invalidateQueries({ queryKey: ['notifications', 'unread-count'] });
                message.success('Notification marked as read');
            }
        },
        onError: (error) => {
            console.error('Failed to mark as read:', error);
            message.error(error?.response?.data?.message || 'Failed to mark as read');
        },
    });
};

export const useDeleteNotification = () => {
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: (id) => api.delete(`/notifications/${id}`),
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['notifications'] });
            queryClient.invalidateQueries({ queryKey: ['notifications', 'unread-count'] });
            message.success('Notification deleted');
        },
        onError: (error) => {
            console.error('Failed to delete:', error);
            message.error(error?.response?.data?.message || 'Failed to delete');
        },
    });
};

export const useMarkAllRead = () => {
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: () => api.post('/notifications/read-all'),
        onSuccess: () => {
            message.success('All notifications marked as read');
            queryClient.invalidateQueries({ queryKey: ['notifications'] });
            queryClient.invalidateQueries({ queryKey: ['notifications', 'unread-count'] });
        },
        onError: (error) => {
            console.error('Failed to mark all as read:', error);
            message.error(error?.response?.data?.message || 'Failed to mark all as read');
        },
    });
};

export const useToggleStar = () => {
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: (id) => api.post(`/notifications/${id}/star`),
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['notifications'] });
        },
        onError: (error) => {
            console.error('Failed to toggle star:', error);
        },
    });
};

export const useClearAllNotifications = () => {
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: () => api.delete('/notifications/clear-all'),
        onSuccess: () => {
            message.success('All notifications cleared');
            queryClient.invalidateQueries({ queryKey: ['notifications'] });
            queryClient.invalidateQueries({ queryKey: ['notifications', 'unread-count'] });
        },
        onError: (error) => {
            console.error('Failed to clear all:', error);
            message.error(error?.response?.data?.message || 'Failed to clear notifications');
        },
    });
};

export const useNotificationsByType = (type) => {
    return useQuery({
        queryKey: ['notifications', 'type', type],
        queryFn: () => api.get(`/notifications/type/${type}`),
        select: (response) => {
            const data = response?.data?.data?.data || response?.data?.data || [];
            return data;
        },
        enabled: !!type,
        staleTime: 30 * 1000,
    });
};

export const useNotificationsByPriority = (priority) => {
    return useQuery({
        queryKey: ['notifications', 'priority', priority],
        queryFn: () => api.get(`/notifications/priority/${priority}`),
        select: (response) => {
            const data = response?.data?.data?.data || response?.data?.data || [];
            return data;
        },
        enabled: !!priority,
        staleTime: 30 * 1000,
    });
};

export const useStarredNotifications = () => {
    return useQuery({
        queryKey: ['notifications', 'starred'],
        queryFn: () => api.get('/notifications/starred'),
        select: (response) => {
            const data = response?.data?.data?.data || response?.data?.data || [];
            return data;
        },
        staleTime: 30 * 1000,
    });
};