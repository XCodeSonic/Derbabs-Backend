// src/hooks/useBookingQuotation.jsx
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { message } from 'antd';
import { bookingAPI, quotationAPI, paymentAPI } from '../services/api';
import api from '../services/api';

// ============================================================
// API RESPONSE NORMALIZATION
// ============================================================

const isObject = (value) => {
    return value !== null && typeof value === 'object' && !Array.isArray(value);
};

const hasOwn = (object, key) => {
    return Object.prototype.hasOwnProperty.call(object, key);
};

const isAxiosResponse = (value) => {
    return (
        isObject(value) &&
        hasOwn(value, 'data') &&
        (
            hasOwn(value, 'status') ||
            hasOwn(value, 'statusText') ||
            hasOwn(value, 'headers') ||
            hasOwn(value, 'config') ||
            hasOwn(value, 'request')
        )
    );
};

const unwrapAxiosResponse = (response) => {
    return isAxiosResponse(response) ? response.data : response;
};

const isApiEnvelope = (value) => {
    if (!isObject(value) || !hasOwn(value, 'data')) {
        return false;
    }
    const keys = Object.keys(value);
    return (
        hasOwn(value, 'success') ||
        hasOwn(value, 'message') ||
        hasOwn(value, 'status') ||
        hasOwn(value, 'error') ||
        keys.length === 1
    );
};

const toNumber = (value, fallback = 0) => {
    const parsed = Number(value);
    return Number.isFinite(parsed) ? parsed : fallback;
};

const createEmptyListEnvelope = () => {
    return {
        data: [],
        total: 0,
        current_page: 1,
        per_page: 0,
        last_page: 1
    };
};

export const normalizeListResponse = (response) => {
    let payload = unwrapAxiosResponse(response);
    let depth = 0;

    while (depth < 8) {
        depth += 1;

        if (Array.isArray(payload)) {
            return {
                data: payload,
                total: payload.length,
                current_page: 1,
                per_page: payload.length,
                last_page: 1
            };
        }

        if (!isObject(payload)) {
            return createEmptyListEnvelope();
        }

        if (Array.isArray(payload.data)) {
            const rows = payload.data;
            return {
                ...payload,
                data: rows,
                total: toNumber(payload.total ?? rows.length, rows.length),
                current_page: toNumber(payload.current_page ?? 1, 1),
                per_page: toNumber(payload.per_page ?? rows.length, rows.length),
                last_page: toNumber(payload.last_page ?? 1, 1)
            };
        }

        if (isApiEnvelope(payload)) {
            payload = payload.data;
            continue;
        }

        if (isObject(payload.data)) {
            payload = payload.data;
            continue;
        }

        return createEmptyListEnvelope();
    }

    return createEmptyListEnvelope();
};

export const normalizeObjectResponse = (response, fallback = {}) => {
    let payload = unwrapAxiosResponse(response);
    let depth = 0;

    while (depth < 8) {
        depth += 1;

        if (!isObject(payload)) {
            return fallback;
        }

        if (isApiEnvelope(payload) && isObject(payload.data)) {
            payload = payload.data;
            continue;
        }

        return payload;
    }

    return fallback;
};

const getResponseMessage = (response, fallback) => {
    const payload = unwrapAxiosResponse(response);
    return payload?.message || payload?.data?.message || response?.message || fallback;
};

const getErrorMessage = (error, fallback) => {
    return error?.response?.data?.message || error?.response?.data?.error || error?.message || fallback;
};

// ============================================================
// QUERY KEYS
// ============================================================
export const bookingKeys = {
    all: ['bookings'],
    lists: () => [...bookingKeys.all, 'list'],
    list: (filters) => [...bookingKeys.lists(), { filters }],
    details: () => [...bookingKeys.all, 'detail'],
    detail: (id) => [...bookingKeys.details(), id],
    statistics: () => [...bookingKeys.all, 'statistics'],
    conflicts: () => [...bookingKeys.all, 'conflicts'],
    paymentSummary: (bookingId) => [...bookingKeys.all, 'payment-summary', bookingId],
    calendar: (params) => [...bookingKeys.all, 'calendar', params],
    recent: (limit) => [...bookingKeys.all, 'recent', limit],
};

export const quotationKeys = {
    all: ['quotations'],
    lists: () => [...quotationKeys.all, 'list'],
    list: (filters) => [...quotationKeys.lists(), { filters }],
    details: () => [...quotationKeys.all, 'detail'],
    detail: (id) => [...quotationKeys.details(), id]
};

export const paymentKeys = {
    all: ['payments'],
    lists: () => [...paymentKeys.all, 'list'],
    list: (filters) => [...paymentKeys.lists(), { filters }],
    details: () => [...paymentKeys.all, 'detail'],
    detail: (id) => [...paymentKeys.details(), id]
};

export const eventTypeKeys = {
    all: ['event-types'],
    list: () => [...eventTypeKeys.all, 'list']
};

// ============================================================
// CACHE INVALIDATION HELPERS
// ============================================================
const invalidateBookingData = (queryClient) => {
    queryClient.invalidateQueries({ queryKey: bookingKeys.all });
};

const invalidateQuotationData = (queryClient) => {
    queryClient.invalidateQueries({ queryKey: quotationKeys.all });
};

const invalidatePaymentData = (queryClient) => {
    queryClient.invalidateQueries({ queryKey: paymentKeys.all });
};

// ============================================================
// EVENT TYPE QUERIES
// ============================================================
export const useEventTypes = () => {
    return useQuery({
        queryKey: eventTypeKeys.list(),
        queryFn: async () => {
            const response = await bookingAPI.getEventTypes();
            return normalizeListResponse(response);
        },
        staleTime: 60 * 60 * 1000
    });
};

// ============================================================
// CALENDAR QUERIES
// ============================================================
export const useCalendarEvents = (params = {}) => {
    return useQuery({
        queryKey: bookingKeys.calendar(params),
        queryFn: async () => {
            const response = await bookingAPI.getCalendarEvents(params);
            const normalized = normalizeListResponse(response);
            return normalized.data;
        },
        staleTime: 5 * 60 * 1000
    });
};

// ============================================================
// BOOKING QUERIES
// ============================================================
export const useBookings = (filters = {}) => {
    return useQuery({
        queryKey: bookingKeys.list(filters),
        queryFn: async () => {
            const response = await bookingAPI.getBookings(filters);
            return normalizeListResponse(response);
        },
        staleTime: 5 * 60 * 1000,
        placeholderData: (previousData) => previousData
    });
};

export const useBooking = (id) => {
    return useQuery({
        queryKey: bookingKeys.detail(id),
        queryFn: async () => {
            const response = await bookingAPI.getBooking(id);
            return normalizeObjectResponse(response);
        },
        enabled: Boolean(id),
        staleTime: 5 * 60 * 1000
    });
};

export const useBookingStatistics = () => {
    return useQuery({
        queryKey: bookingKeys.statistics(),
        queryFn: async () => {
            const response = await bookingAPI.getStatistics();
            return {
                data: normalizeObjectResponse(response, {
                    total_bookings: 0,
                    pending_approvals: 0,
                    total_revenue: 0,
                    total_paid: 0,
                    total_outstanding: 0,
                    regular_bookings: 0,
                    multi_day_events: 0
                })
            };
        },
        refetchInterval: 30000,
        staleTime: 30000
    });
};

export const usePaymentSummary = (bookingId) => {
    return useQuery({
        queryKey: bookingKeys.paymentSummary(bookingId),
        queryFn: async () => {
            const response = await bookingAPI.getPaymentSummary(bookingId);
            return normalizeObjectResponse(response, {
                booking_id: bookingId,
                total_amount: 0,
                total_paid: 0,
                balance: 0,
                payment_status: 'pending',
                payments: []
            });
        },
        enabled: Boolean(bookingId),
        staleTime: 2 * 60 * 1000
    });
};

// ==================== ADDED: RECENT BOOKINGS QUERY ====================
export const useRecentBookings = (limit = 5) => {
    return useQuery({
        queryKey: bookingKeys.recent(limit),
        queryFn: async () => {
            const response = await bookingAPI.getBookings({ 
                per_page: limit, 
                latest: true 
            });
            const normalized = normalizeListResponse(response);
            return normalized.data || [];
        },
        staleTime: 2 * 60 * 1000,
        refetchInterval: 30000,
    });
};

// ==================== ADDED: UPCOMING BOOKINGS QUERY ====================
export const useUpcomingBookings = (limit = 10) => {
    return useQuery({
        queryKey: ['upcoming-bookings', limit],
        queryFn: async () => {
            const response = await bookingAPI.getBookings({ 
                per_page: limit, 
                status: 'confirmed',
                upcoming: true 
            });
            const normalized = normalizeListResponse(response);
            return normalized.data || [];
        },
        staleTime: 2 * 60 * 1000,
        refetchInterval: 5 * 60 * 1000,
    });
};

// ==================== ADDED: BOOKING CONFLICTS QUERY ====================
export const useBookingConflicts = (date) => {
    return useQuery({
        queryKey: bookingKeys.conflicts(),
        queryFn: async () => {
            const response = await bookingAPI.checkConflicts({ event_date: date });
            return normalizeObjectResponse(response, { has_conflicts: false, conflicts: [] });
        },
        enabled: Boolean(date),
        staleTime: 5 * 60 * 1000,
    });
};

// ============================================================
// BOOKING MUTATIONS
// ============================================================
export const useConfirmBooking = () => {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: (id) => bookingAPI.confirmBooking(id),
        onSuccess: (response) => {
            message.success(getResponseMessage(response, 'Booking confirmed successfully'));
            invalidateBookingData(queryClient);
            invalidatePaymentData(queryClient);
        },
        onError: (error) => {
            message.error(getErrorMessage(error, 'Failed to confirm booking'));
        }
    });
};

export const useRejectBooking = () => {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: (id) => bookingAPI.rejectBooking(id),
        onSuccess: (response) => {
            message.warning(getResponseMessage(response, 'Booking rejected'));
            invalidateBookingData(queryClient);
        },
        onError: (error) => {
            message.error(getErrorMessage(error, 'Failed to reject booking'));
        }
    });
};

export const useCancelBooking = () => {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: ({ id, data }) => bookingAPI.cancelBooking(id, data),
        onSuccess: (response) => {
            message.success(getResponseMessage(response, 'Booking cancelled successfully'));
            invalidateBookingData(queryClient);
            invalidatePaymentData(queryClient);
        },
        onError: (error) => {
            message.error(getErrorMessage(error, 'Failed to cancel booking'));
        }
    });
};

export const useCancelBookingWithReason = () => {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: ({ id, reason }) => api.post(`/bookings/${id}/cancel-with-reason`, { reason }),
        onSuccess: (response) => {
            message.success(response.data?.message || 'Booking cancelled');
            invalidateBookingData(queryClient);
            invalidatePaymentData(queryClient);
        },
        onError: (error) => {
            message.error(error.response?.data?.message || 'Failed to cancel booking');
        }
    });
};

export const useCompleteBooking = () => {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: (id) => api.post(`/bookings/${id}/complete`),
        onSuccess: (response, id) => {
            message.success(response.data?.message || 'Booking completed successfully');
            invalidateBookingData(queryClient);
            queryClient.invalidateQueries({ queryKey: bookingKeys.statistics() });
        },
        onError: (error) => {
            message.error(error.response?.data?.message || 'Failed to complete booking');
        }
    });
};

export const useRescheduleBooking = () => {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: ({ id, data }) => bookingAPI.rescheduleBooking(id, data),
        onSuccess: (response) => {
            message.success(getResponseMessage(response, 'Booking rescheduled successfully'));
            invalidateBookingData(queryClient);
        },
        onError: (error) => {
            message.error(getErrorMessage(error, 'Failed to reschedule booking'));
        }
    });
};

export const useRequestReschedule = () => {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: ({ id, data }) => bookingAPI.requestReschedule(id, data),
        onSuccess: (response) => {
            message.success(getResponseMessage(response, 'Reschedule request submitted successfully'));
            invalidateBookingData(queryClient);
        },
        onError: (error) => {
            message.error(getErrorMessage(error, 'Failed to submit reschedule request'));
        }
    });
};

export const useApproveReschedule = () => {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: (id) => bookingAPI.approveReschedule(id),
        onSuccess: (response) => {
            message.success(getResponseMessage(response, 'Reschedule request approved'));
            invalidateBookingData(queryClient);
        },
        onError: (error) => {
            message.error(getErrorMessage(error, 'Failed to approve reschedule'));
        }
    });
};

export const useRejectReschedule = () => {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: (id) => bookingAPI.rejectReschedule(id),
        onSuccess: (response) => {
            message.warning(getResponseMessage(response, 'Reschedule request rejected'));
            invalidateBookingData(queryClient);
        },
        onError: (error) => {
            message.error(getErrorMessage(error, 'Failed to reject reschedule'));
        }
    });
};

export const useRecordPayment = () => {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: ({ id, data }) => bookingAPI.recordPayment(id, data),
        onSuccess: (response, variables) => {
            message.success(getResponseMessage(response, 'Payment recorded successfully'));
            invalidateBookingData(queryClient);
            invalidatePaymentData(queryClient);
            queryClient.invalidateQueries({ queryKey: bookingKeys.paymentSummary(variables.id) });
        },
        onError: (error) => {
            message.error(getErrorMessage(error, 'Failed to record payment'));
        }
    });
};

// ============================================================
// QUOTATION QUERIES
// ============================================================
export const useQuotations = (filters = {}) => {
    return useQuery({
        queryKey: quotationKeys.list(filters),
        queryFn: async () => {
            const response = await quotationAPI.getQuotations(filters);
            return normalizeListResponse(response);
        },
        staleTime: 5 * 60 * 1000,
        placeholderData: (previousData) => previousData
    });
};

export const useQuotation = (id) => {
    return useQuery({
        queryKey: quotationKeys.detail(id),
        queryFn: async () => {
            const response = await quotationAPI.getQuotation(id);
            return normalizeObjectResponse(response);
        },
        enabled: Boolean(id),
        staleTime: 5 * 60 * 1000
    });
};

// ============================================================
// QUOTATION MUTATIONS
// ============================================================
export const useCreateQuotation = () => {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: (data) => quotationAPI.createQuotation(data),
        onSuccess: (response) => {
            message.success(getResponseMessage(response, 'Quotation created successfully'));
            invalidateQuotationData(queryClient);
        },
        onError: (error) => {
            message.error(getErrorMessage(error, 'Failed to create quotation'));
        }
    });
};

export const useApproveQuotation = () => {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: (id) => quotationAPI.approveQuotation(id),
        onSuccess: (response) => {
            message.success(getResponseMessage(response, 'Quotation approved and booking created'));
            invalidateQuotationData(queryClient);
            invalidateBookingData(queryClient);
        },
        onError: (error) => {
            message.error(getErrorMessage(error, 'Failed to approve quotation'));
        }
    });
};

export const useRejectQuotation = () => {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: (id) => quotationAPI.rejectQuotation(id),
        onSuccess: (response) => {
            message.warning(getResponseMessage(response, 'Quotation rejected'));
            invalidateQuotationData(queryClient);
        },
        onError: (error) => {
            message.error(getErrorMessage(error, 'Failed to reject quotation'));
        }
    });
};

export const useSendQuotation = () => {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: (id) => quotationAPI.sendQuotation(id),
        onSuccess: (response) => {
            message.success(getResponseMessage(response, 'Quotation sent successfully'));
            invalidateQuotationData(queryClient);
        },
        onError: (error) => {
            message.error(getErrorMessage(error, 'Failed to send quotation'));
        }
    });
};

export const useUpdateQuotation = () => {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: ({ id, data }) => quotationAPI.updateQuotation(id, data),
        onSuccess: (response, variables) => {
            message.success(getResponseMessage(response, 'Quotation updated successfully'));
            invalidateQuotationData(queryClient);
            queryClient.invalidateQueries({ queryKey: quotationKeys.detail(variables.id) });
        },
        onError: (error) => {
            message.error(getErrorMessage(error, 'Failed to update quotation'));
        }
    });
};

export const useDeleteQuotation = () => {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: (id) => quotationAPI.deleteQuotation(id),
        onSuccess: (response, id) => {
            message.success(getResponseMessage(response, 'Quotation deleted successfully'));
            invalidateQuotationData(queryClient);
            queryClient.removeQueries({ queryKey: quotationKeys.detail(id) });
        },
        onError: (error) => {
            message.error(getErrorMessage(error, 'Failed to delete quotation'));
        }
    });
};

// ============================================================
// PAYMENT QUERIES
// ============================================================
export const usePayments = (filters = {}) => {
    return useQuery({
        queryKey: paymentKeys.list(filters),
        queryFn: async () => {
            const response = await paymentAPI.getPayments(filters);
            return normalizeListResponse(response);
        },
        staleTime: 5 * 60 * 1000,
        placeholderData: (previousData) => previousData
    });
};

export const usePayment = (id) => {
    return useQuery({
        queryKey: paymentKeys.detail(id),
        queryFn: async () => {
            const response = await paymentAPI.getPayment(id);
            return normalizeObjectResponse(response);
        },
        enabled: Boolean(id),
        staleTime: 5 * 60 * 1000
    });
};

// ============================================================
// PAYMENT MUTATIONS
// ============================================================
export const useCreatePayment = () => {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: (data) => paymentAPI.createPayment(data),
        onSuccess: (response, variables) => {
            message.success(getResponseMessage(response, 'Payment recorded successfully'));
            invalidatePaymentData(queryClient);
            invalidateBookingData(queryClient);
            queryClient.invalidateQueries({ queryKey: bookingKeys.paymentSummary(variables.booking_id) });
        },
        onError: (error) => {
            message.error(getErrorMessage(error, 'Failed to record payment'));
        }
    });
};

export const useVerifyPayment = () => {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: ({ id, notes }) => paymentAPI.verifyPayment(id, notes),
        onSuccess: (response, variables) => {
            message.success(getResponseMessage(response, 'Payment verified successfully'));
            invalidatePaymentData(queryClient);
            invalidateBookingData(queryClient);
            queryClient.invalidateQueries({ queryKey: paymentKeys.detail(variables.id) });
        },
        onError: (error) => {
            message.error(getErrorMessage(error, 'Failed to verify payment'));
        }
    });
};

export const useRejectPayment = () => {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: ({ id, reason }) => paymentAPI.rejectPayment(id, reason),
        onSuccess: (response, variables) => {
            message.warning(getResponseMessage(response, 'Payment rejected'));
            invalidatePaymentData(queryClient);
            invalidateBookingData(queryClient);
            queryClient.invalidateQueries({ queryKey: paymentKeys.detail(variables.id) });
        },
        onError: (error) => {
            message.error(getErrorMessage(error, 'Failed to reject payment'));
        }
    });
};

export const useDeletePayment = () => {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: (id) => paymentAPI.deletePayment(id),
        onSuccess: (response, id) => {
            message.success(getResponseMessage(response, 'Payment deleted successfully'));
            invalidatePaymentData(queryClient);
            invalidateBookingData(queryClient);
            queryClient.removeQueries({ queryKey: paymentKeys.detail(id) });
        },
        onError: (error) => {
            message.error(getErrorMessage(error, 'Failed to delete payment'));
        }
    });
};

// ============================================================
// CALENDAR AVAILABILITY
// ============================================================
export const calendarAvailabilityKeys = {
    all: ['booking-calendar-availability'],
    list: (params) => [...calendarAvailabilityKeys.all, 'list', params]
};

export const useCalendarAvailability = (params = {}) => {
    return useQuery({
        queryKey: calendarAvailabilityKeys.list(params),
        queryFn: async () => {
            const response = await bookingAPI.getCalendarAvailability(params);
            return normalizeListResponse(response);
        },
        staleTime: 2 * 60 * 1000
    });
};

export const useSaveCalendarAvailability = () => {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: ({ date, data }) => bookingAPI.saveCalendarAvailability(date, data),
        onSuccess: (response) => {
            message.success(getResponseMessage(response, 'Calendar availability saved successfully'));
            queryClient.invalidateQueries({ queryKey: calendarAvailabilityKeys.all });
        },
        onError: (error) => {
            message.error(getErrorMessage(error, 'Failed to save calendar availability'));
        }
    });
};

export const useDeleteCalendarAvailability = () => {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: (date) => bookingAPI.deleteCalendarAvailability(date),
        onSuccess: (response) => {
            message.success(getResponseMessage(response, 'Calendar availability reset successfully'));
            queryClient.invalidateQueries({ queryKey: calendarAvailabilityKeys.all });
        },
        onError: (error) => {
            message.error(getErrorMessage(error, 'Failed to reset calendar availability'));
        }
    });
};

// ============================================================
// EXPORT ALL ADDITIONAL HOOKS
// ============================================================
export default {
    // Queries
    useEventTypes,
    useCalendarEvents,
    useBookings,
    useBooking,
    useBookingStatistics,
    usePaymentSummary,
    useRecentBookings,
    useUpcomingBookings,
    useBookingConflicts,
    useQuotations,
    useQuotation,
    usePayments,
    usePayment,
    useCalendarAvailability,
    // Mutations
    useConfirmBooking,
    useRejectBooking,
    useCancelBooking,
    useCancelBookingWithReason,
    useCompleteBooking,
    useRescheduleBooking,
    useRequestReschedule,
    useApproveReschedule,
    useRejectReschedule,
    useRecordPayment,
    useCreateQuotation,
    useApproveQuotation,
    useRejectQuotation,
    useSendQuotation,
    useUpdateQuotation,
    useDeleteQuotation,
    useCreatePayment,
    useVerifyPayment,
    useRejectPayment,
    useDeletePayment,
    useSaveCalendarAvailability,
    useDeleteCalendarAvailability,
};