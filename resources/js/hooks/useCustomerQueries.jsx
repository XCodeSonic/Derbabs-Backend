// src/hooks/useCustomerQueries.js
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { message } from 'antd';
import api from '../services/api';

// ============================================================
// HELPER FUNCTIONS
// ============================================================

const unwrap = (response) => response?.data?.data ?? response?.data ?? response;
const extractList = (response) => {
  const data = unwrap(response);
  if (Array.isArray(data)) return data;
  if (data?.data && Array.isArray(data.data)) return data.data;
  return [];
};

// ============================================================
// QUERY KEYS
// ============================================================

export const customerKeys = {
  all: ['customers'],
  lists: () => [...customerKeys.all, 'list'],
  list: (filters) => [...customerKeys.lists(), filters],
  details: () => [...customerKeys.all, 'detail'],
  detail: (id) => [...customerKeys.details(), id],
  bookings: (customerId) => [...customerKeys.all, 'bookings', customerId],
  payments: (customerId) => [...customerKeys.all, 'payments', customerId],
  reviews: (customerId) => [...customerKeys.all, 'reviews', customerId],
  feedback: () => [...customerKeys.all, 'feedback'],
  messages: () => [...customerKeys.all, 'messages'],
  stats: () => [...customerKeys.all, 'stats'],
  loyalty: (customerId) => [...customerKeys.all, 'loyalty', customerId],
};

// ============================================================
// CUSTOMER QUERIES
// ============================================================

/**
 * Get all customers with pagination and filters
 */
export const useCustomers = (filters = {}) => {
  return useQuery({
    queryKey: customerKeys.list(filters),
    queryFn: () => api.get('/customers', { params: filters }),
    select: (response) => {
      const data = response?.data?.data;
      if (data?.data && Array.isArray(data.data)) {
        return {
          data: data.data,
          total: data.total || 0,
          current_page: data.current_page || 1,
          last_page: data.last_page || 1,
          per_page: data.per_page || 15,
        };
      }
      if (Array.isArray(data)) {
        return {
          data: data,
          total: data.length,
          current_page: 1,
          last_page: 1,
          per_page: data.length,
        };
      }
      return { data: [], total: 0, current_page: 1, last_page: 1, per_page: 15 };
    },
    staleTime: 5 * 60 * 1000,
  });
};

/**
 * Get a single customer by ID
 */
export const useCustomer = (id) => {
  return useQuery({
    queryKey: customerKeys.detail(id),
    queryFn: () => api.get(`/customers/${id}`),
    select: (response) => unwrap(response),
    enabled: !!id,
    staleTime: 5 * 60 * 1000,
  });
};

/**
 * Get customer statistics (total, active, revenue, etc.)
 */
export const useCustomerStats = () => {
  return useQuery({
    queryKey: customerKeys.stats(),
    queryFn: () => api.get('/customers/stats'),
    select: (response) => unwrap(response) || {
      total_customers: 0,
      active_customers: 0,
      total_revenue: 0,
      avg_rating: 0,
      new_this_month: 0,
      loyal_customers: 0,
    },
    staleTime: 5 * 60 * 1000,
  });
};

/**
 * Get all bookings for a specific customer
 */
export const useCustomerBookings = (customerId, params = {}) => {
  return useQuery({
    queryKey: customerKeys.bookings(customerId),
    queryFn: () => api.get(`/customers/${customerId}/bookings`, { params }),
    select: (response) => extractList(response),
    enabled: !!customerId,
    staleTime: 3 * 60 * 1000,
  });
};

/**
 * Get all payments for a specific customer
 */
export const useCustomerPayments = (customerId, params = {}) => {
  return useQuery({
    queryKey: customerKeys.payments(customerId),
    queryFn: () => api.get(`/customers/${customerId}/payments`, { params }),
    select: (response) => extractList(response),
    enabled: !!customerId,
    staleTime: 3 * 60 * 1000,
  });
};

/**
 * Get all reviews for a specific customer
 */
export const useCustomerReviews = (customerId, params = {}) => {
  return useQuery({
    queryKey: customerKeys.reviews(customerId),
    queryFn: () => api.get(`/customers/${customerId}/reviews`, { params }),
    select: (response) => extractList(response),
    enabled: !!customerId,
    staleTime: 3 * 60 * 1000,
  });
};

/**
 * Get all customer feedback (reviews with ratings)
 */
export const useCustomerFeedback = (params = {}) => {
  return useQuery({
    queryKey: customerKeys.feedback(),
    queryFn: () => api.get('/customers-feedback', { params }),
    select: (response) => extractList(response),
    staleTime: 3 * 60 * 1000,
  });
};

/**
 * Get all customer messages/chat threads
 */
export const useCustomerMessages = (params = {}) => {
  return useQuery({
    queryKey: customerKeys.messages(),
    queryFn: () => api.get('/customer-messages', { params }),
    select: (response) => {
      const data = unwrap(response);
      if (data?.data && Array.isArray(data.data)) return data.data;
      if (Array.isArray(data)) return data;
      return [];
    },
    staleTime: 2 * 60 * 1000,
    refetchInterval: 30000,
  });
};

/**
 * Get loyalty points and tier for a customer
 */
export const useCustomerLoyalty = (customerId) => {
  return useQuery({
    queryKey: customerKeys.loyalty(customerId),
    queryFn: () => api.get(`/customers/${customerId}/loyalty`),
    select: (response) => unwrap(response) || { points: 0, tier: 'bronze', next_tier: 'silver', points_to_next: 500 },
    enabled: !!customerId,
    staleTime: 5 * 60 * 1000,
  });
};

// ============================================================
// CUSTOMER MUTATIONS
// ============================================================

/**
 * Create a new customer
 */
export const useCreateCustomer = () => {
  const queryClient = useQueryClient();
  
  return useMutation({
    mutationFn: (data) => api.post('/customers', data),
    onSuccess: () => {
      message.success('Customer created successfully');
      queryClient.invalidateQueries({ queryKey: customerKeys.all });
      queryClient.invalidateQueries({ queryKey: customerKeys.stats() });
    },
    onError: (error) => {
      message.error(error.response?.data?.message || 'Failed to create customer');
    },
  });
};

/**
 * Update an existing customer
 */
export const useUpdateCustomer = () => {
  const queryClient = useQueryClient();
  
  return useMutation({
    mutationFn: ({ id, data }) => api.put(`/customers/${id}`, data),
    onSuccess: (_, variables) => {
      message.success('Customer updated successfully');
      queryClient.invalidateQueries({ queryKey: customerKeys.all });
      queryClient.invalidateQueries({ queryKey: customerKeys.detail(variables.id) });
      queryClient.invalidateQueries({ queryKey: customerKeys.stats() });
    },
    onError: (error) => {
      message.error(error.response?.data?.message || 'Failed to update customer');
    },
  });
};

/**
 * Delete (soft delete) a customer
 */
export const useDeleteCustomer = () => {
  const queryClient = useQueryClient();
  
  return useMutation({
    mutationFn: (id) => api.delete(`/customers/${id}`),
    onSuccess: () => {
      message.success('Customer deleted successfully');
      queryClient.invalidateQueries({ queryKey: customerKeys.all });
      queryClient.invalidateQueries({ queryKey: customerKeys.stats() });
    },
    onError: (error) => {
      message.error(error.response?.data?.message || 'Failed to delete customer');
    },
  });
};

/**
 * Restore a soft-deleted customer
 */
export const useRestoreCustomer = () => {
  const queryClient = useQueryClient();
  
  return useMutation({
    mutationFn: (id) => api.post(`/customers/${id}/restore`),
    onSuccess: () => {
      message.success('Customer restored successfully');
      queryClient.invalidateQueries({ queryKey: customerKeys.all });
      queryClient.invalidateQueries({ queryKey: customerKeys.stats() });
    },
    onError: (error) => {
      message.error(error.response?.data?.message || 'Failed to restore customer');
    },
  });
};

/**
 * Toggle customer active status
 */
export const useToggleCustomerStatus = () => {
  const queryClient = useQueryClient();
  
  return useMutation({
    mutationFn: (id) => api.post(`/customers/${id}/toggle-status`),
    onSuccess: (_, id) => {
      message.success('Customer status updated');
      queryClient.invalidateQueries({ queryKey: customerKeys.all });
      queryClient.invalidateQueries({ queryKey: customerKeys.detail(id) });
      queryClient.invalidateQueries({ queryKey: customerKeys.stats() });
    },
    onError: (error) => {
      message.error(error.response?.data?.message || 'Failed to update status');
    },
  });
};

// ============================================================
// FEEDBACK & REVIEW MUTATIONS
// ============================================================

/**
 * Respond to customer feedback
 */
export const useRespondToFeedback = () => {
  const queryClient = useQueryClient();
  
  return useMutation({
    mutationFn: ({ id, response }) => api.post(`/feedbacks/${id}/respond`, { response }),
    onSuccess: () => {
      message.success('Response sent to customer');
      queryClient.invalidateQueries({ queryKey: customerKeys.feedback() });
    },
    onError: (error) => {
      message.error(error.response?.data?.message || 'Failed to send response');
    },
  });
};

/**
 * Approve a customer review
 */
export const useApproveReview = () => {
  const queryClient = useQueryClient();
  
  return useMutation({
    mutationFn: (id) => api.post(`/reviews/${id}/approve`),
    onSuccess: () => {
      message.success('Review approved and published');
      queryClient.invalidateQueries({ queryKey: customerKeys.feedback() });
      queryClient.invalidateQueries({ queryKey: ['reviews'] });
    },
    onError: (error) => {
      message.error(error.response?.data?.message || 'Failed to approve review');
    },
  });
};

/**
 * Hide/delete a customer review
 */
export const useHideReview = () => {
  const queryClient = useQueryClient();
  
  return useMutation({
    mutationFn: (id) => api.delete(`/reviews/${id}`),
    onSuccess: () => {
      message.success('Review hidden');
      queryClient.invalidateQueries({ queryKey: customerKeys.feedback() });
      queryClient.invalidateQueries({ queryKey: ['reviews'] });
    },
    onError: (error) => {
      message.error(error.response?.data?.message || 'Failed to hide review');
    },
  });
};

/**
 * Feature/unfeature a review
 */
export const useFeatureReview = () => {
  const queryClient = useQueryClient();
  
  return useMutation({
    mutationFn: ({ id, featured }) => api.post(`/reviews/${id}/feature`, { featured }),
    onSuccess: (_, variables) => {
      message.success(variables.featured ? 'Added to featured' : 'Removed from featured');
      queryClient.invalidateQueries({ queryKey: customerKeys.feedback() });
      queryClient.invalidateQueries({ queryKey: ['reviews'] });
    },
    onError: (error) => {
      message.error(error.response?.data?.message || 'Failed to update featured status');
    },
  });
};

// ============================================================
// MESSAGE MUTATIONS
// ============================================================

/**
 * Send a message to a customer
 */
export const useSendCustomerMessage = () => {
  const queryClient = useQueryClient();
  
  return useMutation({
    mutationFn: ({ customer_id, message: msg }) => api.post('/customer-messages', { customer_id, message: msg }),
    onSuccess: () => {
      message.success('Message sent successfully');
      queryClient.invalidateQueries({ queryKey: customerKeys.messages() });
    },
    onError: (error) => {
      message.error(error.response?.data?.message || 'Failed to send message');
    },
  });
};

/**
 * Mark a message as read
 */
export const useMarkMessageRead = () => {
  const queryClient = useQueryClient();
  
  return useMutation({
    mutationFn: (messageId) => api.post(`/customer-messages/${messageId}/read`),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: customerKeys.messages() });
    },
    onError: (error) => {
      message.error(error.response?.data?.message || 'Failed to mark message as read');
    },
  });
};

// ============================================================
// LOYALTY MUTATIONS
// ============================================================

/**
 * Add loyalty points to a customer
 */
export const useAddLoyaltyPoints = () => {
  const queryClient = useQueryClient();
  
  return useMutation({
    mutationFn: ({ customerId, points, reason }) => api.post(`/customers/${customerId}/loyalty/add`, { points, reason }),
    onSuccess: (_, variables) => {
      message.success(`${variables.points} points added`);
      queryClient.invalidateQueries({ queryKey: customerKeys.loyalty(variables.customerId) });
      queryClient.invalidateQueries({ queryKey: customerKeys.all });
    },
    onError: (error) => {
      message.error(error.response?.data?.message || 'Failed to add points');
    },
  });
};

/**
 * Redeem loyalty points
 */
export const useRedeemLoyaltyPoints = () => {
  const queryClient = useQueryClient();
  
  return useMutation({
    mutationFn: ({ customerId, points, bookingId }) => api.post(`/customers/${customerId}/loyalty/redeem`, { points, booking_id: bookingId }),
    onSuccess: (_, variables) => {
      message.success(`${variables.points} points redeemed`);
      queryClient.invalidateQueries({ queryKey: customerKeys.loyalty(variables.customerId) });
      queryClient.invalidateQueries({ queryKey: customerKeys.all });
    },
    onError: (error) => {
      message.error(error.response?.data?.message || 'Failed to redeem points');
    },
  });
};

// ============================================================
// EXPORT FUNCTIONS
// ============================================================

/**
 * Export customers to CSV/Excel
 */
export const useExportCustomers = () => {
  return useMutation({
    mutationFn: (filters = {}) => api.get('/customers/export', { 
      params: filters, 
      responseType: 'blob' 
    }),
    onSuccess: (response) => {
      const url = window.URL.createObjectURL(new Blob([response.data]));
      const link = document.createElement('a');
      link.href = url;
      link.setAttribute('download', `customers_${new Date().toISOString().split('T')[0]}.xlsx`);
      document.body.appendChild(link);
      link.click();
      link.remove();
      message.success('Customers exported successfully');
    },
    onError: (error) => {
      message.error(error.response?.data?.message || 'Failed to export customers');
    },
  });
};

// ============================================================
// DEFAULT EXPORT
// ============================================================

export default {
  // Queries
  useCustomers,
  useCustomer,
  useCustomerStats,
  useCustomerBookings,
  useCustomerPayments,
  useCustomerReviews,
  useCustomerFeedback,
  useCustomerMessages,
  useCustomerLoyalty,
  // Mutations
  useCreateCustomer,
  useUpdateCustomer,
  useDeleteCustomer,
  useRestoreCustomer,
  useToggleCustomerStatus,
  useRespondToFeedback,
  useApproveReview,
  useHideReview,
  useFeatureReview,
  useSendCustomerMessage,
  useMarkMessageRead,
  useAddLoyaltyPoints,
  useRedeemLoyaltyPoints,
  useExportCustomers,
  // Keys
  customerKeys,
};