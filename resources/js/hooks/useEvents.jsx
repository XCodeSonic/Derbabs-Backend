// src/hooks/useEvents.js

import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { message } from 'antd';
import api from '../services/api';

const payload = (response) => response?.data?.data ?? response?.data ?? response;
const apiError = (error, fallback) => error?.response?.data?.message || fallback;

export const eventKeys = {
    all: ['events'],
    lists: () => ['events', 'list'],
    list: (filters = {}) => ['events', 'list', filters],
    detail: (id) => ['events', 'detail', id],
    staff: (id) => ['events', id, 'staff'],
    checklist: (id) => ['events', id, 'checklist'],
    deliveries: (id) => ['events', id, 'deliveries'],
    equipment: (id) => ['events', id, 'equipment'],
    progress: (id) => ['events', id, 'daily-progress'],
    sessions: (id) => ['events', id, 'sessions'],
    liveStatus: (id) => ['events', id, 'live-status'],
    deductions: (id) => ['events', id, 'pending-deductions'],
    statistics: () => ['events', 'statistics'],
    calendar: (params = {}) => ['events', 'calendar', params],
};

const invalidateEvent = (queryClient, eventId) => {
    queryClient.invalidateQueries({ queryKey: eventKeys.all });
    if (eventId) {
        queryClient.invalidateQueries({ queryKey: eventKeys.detail(eventId) });
    }
};

export const useUpcomingEvents = () => {
    return useQuery({
        queryKey: ['upcoming-events'],
        queryFn: () => api.get('/events', { params: { status: 'confirmed', upcoming: true } }),
        select: (response) => response?.data?.data?.data || [],
        staleTime: 2 * 60 * 1000,
    });
};

// ==================== QUERIES ====================

export const useEvents = (filters = {}) => useQuery({
    queryKey: eventKeys.list(filters),
    queryFn: () => api.get('/events', { params: filters }),
    select: payload,
    staleTime: 5 * 60 * 1000,
});

export const useEvent = (id) => useQuery({
    queryKey: eventKeys.detail(id),
    queryFn: () => api.get(`/events/${id}`),
    select: payload,
    enabled: Boolean(id),
});

export const useEventStaff = (id) => useQuery({
    queryKey: eventKeys.staff(id),
    queryFn: () => api.get(`/events/${id}/staff`),
    select: payload,
    enabled: Boolean(id),
});

export const useEventChecklist = (id) => useQuery({
    queryKey: eventKeys.checklist(id),
    queryFn: () => api.get(`/events/${id}/checklist`),
    select: payload,
    enabled: Boolean(id),
});

export const useEventDeliveries = (id) => useQuery({
    queryKey: eventKeys.deliveries(id),
    queryFn: () => api.get(`/events/${id}/deliveries`),
    select: payload,
    enabled: Boolean(id),
});

export const useEventEquipment = (id) => useQuery({
    queryKey: eventKeys.equipment(id),
    queryFn: () => api.get(`/events/${id}/equipment`),
    select: payload,
    enabled: Boolean(id),
});

export const useEventProgress = (id) => useQuery({
    queryKey: eventKeys.progress(id),
    queryFn: () => api.get(`/events/${id}/daily-progress`),
    select: payload,
    enabled: Boolean(id),
});

export const useEventSessions = (id) => useQuery({
    queryKey: eventKeys.sessions(id),
    queryFn: () => api.get(`/events/${id}/sessions`),
    select: payload,
    enabled: Boolean(id),
});

export const useEventLiveStatus = (id) => useQuery({
    queryKey: eventKeys.liveStatus(id),
    queryFn: () => api.get(`/events/${id}/live-status`),
    select: payload,
    enabled: Boolean(id),
    refetchInterval: 30000,
});

export const usePendingDeductions = (id) => useQuery({
    queryKey: eventKeys.deductions(id),
    queryFn: () => api.get(`/events/${id}/pending-deductions`),
    select: payload,
    enabled: Boolean(id),
});

export const useEventStatistics = () => useQuery({
    queryKey: eventKeys.statistics(),
    queryFn: () => api.get('/events/stats'),
    select: payload,
});

export const useEventCalendar = (params = {}) => useQuery({
    queryKey: eventKeys.calendar(params),
    queryFn: () => api.get('/calendar-events', { params }),
    select: payload,
});

// ==================== MUTATIONS ====================

export const useCreateEventFromBooking = () => {
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: (bookingId) => api.post(`/bookings/${bookingId}/create-event`),
        onSuccess: (response) => {
            message.success(response?.data?.message || 'Confirmed event is ready.');
            queryClient.invalidateQueries({ queryKey: eventKeys.all });
            queryClient.invalidateQueries({ queryKey: ['bookings'] });
        },
        onError: (error) => message.error(apiError(error, 'Failed to create event.')),
    });
};

export const useUpdateEvent = () => {
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: ({ id, data }) => api.put(`/events/${id}`, data),
        onSuccess: (response, { id }) => {
            message.success(response?.data?.message || 'Event updated.');
            invalidateEvent(queryClient, id);
        },
        onError: (error) => message.error(apiError(error, 'Failed to update event.')),
    });
};

export const useDeleteEvent = () => {
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: (id) => api.delete(`/events/${id}`),
        onSuccess: () => {
            message.success('Event cancelled.');
            queryClient.invalidateQueries({ queryKey: eventKeys.all });
        },
        onError: (error) => message.error(apiError(error, 'Failed to cancel event.')),
    });
};

// ==================== STAFF MUTATIONS ====================

export const useAssignStaff = () => {
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: ({ eventId, data }) => api.post(`/events/${eventId}/staff`, data),
        onSuccess: (response, { eventId }) => {
            message.success(response?.data?.message || 'Staff assigned.');
            queryClient.invalidateQueries({ queryKey: eventKeys.staff(eventId) });
        },
        onError: (error) => message.error(apiError(error, 'Failed to assign staff.')),
    });
};

export const useUpdateStaffStatus = () => {
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: ({ eventId, staffId, data }) => api.put(`/events/${eventId}/staff/${staffId}`, data),
        onSuccess: (response, { eventId }) => {
            message.success(response?.data?.message || 'Staff status updated.');
            queryClient.invalidateQueries({ queryKey: eventKeys.staff(eventId) });
        },
        onError: (error) => message.error(apiError(error, 'Failed to update staff status.')),
    });
};

export const useRemoveStaff = () => {
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: ({ eventId, staffId }) => api.delete(`/events/${eventId}/staff/${staffId}`),
        onSuccess: (response, { eventId }) => {
            message.success(response?.data?.message || 'Staff removed.');
            queryClient.invalidateQueries({ queryKey: eventKeys.staff(eventId) });
        },
        onError: (error) => message.error(apiError(error, 'Failed to remove staff.')),
    });
};

// ==================== CHECKLIST MUTATIONS ====================

export const useAddChecklistItem = () => {
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: ({ eventId, data }) => api.post(`/events/${eventId}/checklist`, data),
        onSuccess: (response, { eventId }) => {
            message.success(response?.data?.message || 'Checklist item added.');
            queryClient.invalidateQueries({ queryKey: eventKeys.checklist(eventId) });
        },
        onError: (error) => message.error(apiError(error, 'Failed to add checklist item.')),
    });
};

export const useUpdateChecklistItem = () => {
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: ({ eventId, itemId, data }) => api.put(`/events/${eventId}/checklist/${itemId}`, data),
        onSuccess: (response, { eventId }) => {
            message.success(response?.data?.message || 'Checklist updated.');
            queryClient.invalidateQueries({ queryKey: eventKeys.checklist(eventId) });
        },
        onError: (error) => message.error(apiError(error, 'Failed to update checklist.')),
    });
};

export const useDeleteChecklistItem = () => {
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: ({ eventId, itemId }) => api.delete(`/events/${eventId}/checklist/${itemId}`),
        onSuccess: (response, { eventId }) => {
            message.success(response?.data?.message || 'Checklist item deleted.');
            queryClient.invalidateQueries({ queryKey: eventKeys.checklist(eventId) });
        },
        onError: (error) => message.error(apiError(error, 'Failed to delete checklist item.')),
    });
};

// ==================== DELIVERY MUTATIONS ====================

export const useAddDelivery = () => {
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: ({ eventId, data }) => api.post(`/events/${eventId}/deliveries`, data),
        onSuccess: (response, { eventId }) => {
            message.success(response?.data?.message || 'Delivery added.');
            queryClient.invalidateQueries({ queryKey: eventKeys.deliveries(eventId) });
        },
        onError: (error) => message.error(apiError(error, 'Failed to add delivery.')),
    });
};

export const useUpdateDeliveryStatus = () => {
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: ({ eventId, deliveryId, data }) => api.put(`/events/${eventId}/deliveries/${deliveryId}/status`, data),
        onSuccess: (response, { eventId }) => {
            message.success(response?.data?.message || 'Delivery status updated.');
            queryClient.invalidateQueries({ queryKey: eventKeys.deliveries(eventId) });
        },
        onError: (error) => message.error(apiError(error, 'Failed to update delivery status.')),
    });
};

// ==================== EQUIPMENT MUTATIONS ====================

export const useCheckoutEquipment = () => {
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: ({ eventId, data }) => api.post(`/events/${eventId}/equipment/checkout`, data),
        onSuccess: (response, { eventId }) => {
            message.success(response?.data?.message || 'Equipment checked out.');
            queryClient.invalidateQueries({ queryKey: eventKeys.equipment(eventId) });
        },
        onError: (error) => message.error(apiError(error, 'Failed to checkout equipment.')),
    });
};

export const useReturnEquipment = () => {
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: ({ eventId, transactionId, data }) => api.post(`/events/${eventId}/equipment/${transactionId}/return`, data),
        onSuccess: (response, { eventId }) => {
            message.success(response?.data?.message || 'Equipment returned.');
            queryClient.invalidateQueries({ queryKey: eventKeys.equipment(eventId) });
        },
        onError: (error) => message.error(apiError(error, 'Failed to return equipment.')),
    });
};

// ==================== PROGRESS MUTATIONS ====================

export const useUpdateDailyProgress = () => {
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: ({ eventId, dayNumber, data }) => api.put(`/events/${eventId}/daily-progress/${dayNumber}`, data),
        onSuccess: (response, { eventId }) => {
            message.success(response?.data?.message || 'Daily progress updated.');
            queryClient.invalidateQueries({ queryKey: eventKeys.progress(eventId) });
            queryClient.invalidateQueries({ queryKey: eventKeys.liveStatus(eventId) });
        },
        onError: (error) => message.error(apiError(error, 'Failed to update daily progress.')),
    });
};

export const useAdvanceToNextDay = () => {
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: (eventId) => api.post(`/events/${eventId}/advance-day`),
        onSuccess: (response, eventId) => {
            message.success(response?.data?.message || 'Advanced to next day.');
            invalidateEvent(queryClient, eventId);
            queryClient.invalidateQueries({ queryKey: eventKeys.progress(eventId) });
        },
        onError: (error) => message.error(apiError(error, 'Failed to advance day.')),
    });
};

export const useUpdateAttendance = () => {
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: ({ eventId, day, present }) => api.put(`/events/${eventId}/attendance/${day}`, { present }),
        onSuccess: (response, { eventId }) => {
            message.success(response?.data?.message || 'Attendance updated.');
            queryClient.invalidateQueries({ queryKey: eventKeys.progress(eventId) });
        },
        onError: (error) => message.error(apiError(error, 'Failed to update attendance.')),
    });
};

// ==================== SESSION MUTATIONS ====================

export const useAddSession = () => {
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: ({ eventId, data }) => api.post(`/events/${eventId}/sessions`, data),
        onSuccess: (response, { eventId }) => {
            message.success(response?.data?.message || 'Session added.');
            queryClient.invalidateQueries({ queryKey: eventKeys.sessions(eventId) });
        },
        onError: (error) => message.error(apiError(error, 'Failed to add session.')),
    });
};

export const useUpdateSessionStatus = () => {
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: ({ eventId, sessionId, data }) => api.put(`/events/${eventId}/sessions/${sessionId}/status`, data),
        onSuccess: (response, { eventId }) => {
            message.success(response?.data?.message || 'Session updated.');
            queryClient.invalidateQueries({ queryKey: eventKeys.sessions(eventId) });
        },
        onError: (error) => message.error(apiError(error, 'Failed to update session.')),
    });
};

export const useDeleteSession = () => {
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: ({ eventId, sessionId }) => api.delete(`/events/${eventId}/sessions/${sessionId}`),
        onSuccess: (response, { eventId }) => {
            message.success(response?.data?.message || 'Session deleted.');
            queryClient.invalidateQueries({ queryKey: eventKeys.sessions(eventId) });
        },
        onError: (error) => message.error(apiError(error, 'Failed to delete session.')),
    });
};

// ==================== LIVE STATUS MUTATIONS ====================

export const useUpdateLiveStatus = () => {
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: ({ eventId, data }) => api.put(`/events/${eventId}/live-status`, data),
        onSuccess: (response, { eventId }) => {
            message.success(response?.data?.message || 'Live status updated.');
            queryClient.invalidateQueries({ queryKey: eventKeys.liveStatus(eventId) });
        },
        onError: (error) => message.error(apiError(error, 'Failed to update live status.')),
    });
};

export const useConfirmDeductions = () => {
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: ({ eventId, deductions }) => api.post(`/events/${eventId}/confirm-deductions`, { deductions }),
        onSuccess: (response, { eventId }) => {
            message.success(response?.data?.message || 'Inventory deductions confirmed.');
            queryClient.invalidateQueries({ queryKey: eventKeys.deductions(eventId) });
            queryClient.invalidateQueries({ queryKey: ['inventory'] });
            queryClient.invalidateQueries({ queryKey: ['products'] });
        },
        onError: (error) => message.error(apiError(error, 'Failed to confirm deductions.')),
    });
};

export const useCompleteEvent = () => {
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: (eventId) => api.post(`/events/${eventId}/complete`),
        onSuccess: (response, eventId) => {
            message.success(response?.data?.message || 'Event completed.');
            invalidateEvent(queryClient, eventId);
        },
        onError: (error) => message.error(apiError(error, 'Failed to complete event.')),
    });
};