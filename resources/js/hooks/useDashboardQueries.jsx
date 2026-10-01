// src/hooks/useDashboardQueries.js
import { useQuery } from '@tanstack/react-query';
import api from '../services/api';

export const useDashboardStats = () => {
    return useQuery({
        queryKey: ['dashboard-stats'],
        queryFn: () => api.get('/dashboard'),
        select: (response) => response?.data?.data,
        staleTime: 2 * 60 * 1000,
        refetchInterval: 30000,
    });
};