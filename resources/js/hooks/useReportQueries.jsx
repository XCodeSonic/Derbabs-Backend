// src/hooks/useReportQueries.js
import { useQuery } from '@tanstack/react-query';
import api from '../services/api';

export const useSalesReport = (params = {}) => {
    return useQuery({
        queryKey: ['sales-report', params],
        queryFn: () => api.get('/reports/sales', { params }),
        select: (response) => response?.data?.data,
        staleTime: 5 * 60 * 1000,
    });
};

export const useInventoryReport = () => {
    return useQuery({
        queryKey: ['inventory-report'],
        queryFn: () => api.get('/reports/inventory'),
        select: (response) => response?.data?.data,
        staleTime: 5 * 60 * 1000,
    });
};

export const usePayrollReport = () => {
    return useQuery({
        queryKey: ['payroll-report'],
        queryFn: () => api.get('/reports/payroll'),
        select: (response) => response?.data?.data,
        staleTime: 5 * 60 * 1000,
    });
};

export const useBookingReport = () => {
    return useQuery({
        queryKey: ['booking-report'],
        queryFn: () => api.get('/reports/events'),
        select: (response) => response?.data?.data,
        staleTime: 5 * 60 * 1000,
    });
};

export const useFinancialReport = () => {
    return useQuery({
        queryKey: ['financial-report'],
        queryFn: () => api.get('/reports/financial'),
        select: (response) => response?.data?.data,
        staleTime: 5 * 60 * 1000,
    });
};