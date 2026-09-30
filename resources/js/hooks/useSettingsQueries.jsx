// src/hooks/useSettingsQueries.js
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import api from '../services/api';

export const useUsers = () => {
    return useQuery({
        queryKey: ['users'],
        queryFn: () => api.get('/users'),
        select: (response) => response?.data?.data,
        staleTime: 5 * 60 * 1000,
    });
};

export const useRoles = () => {
    return useQuery({
        queryKey: ['roles'],
        queryFn: () => api.get('/roles'),
        select: (response) => response?.data?.data || [],
        staleTime: 10 * 60 * 1000,
    });
};

export const useAuditLogs = (params = {}) => {
    return useQuery({
        queryKey: ['audit-logs', params],
        queryFn: () => api.get('/audit-logs', { params }),
        select: (response) => response?.data?.data || [],
        staleTime: 2 * 60 * 1000,
    });
};

export const useSettings = () => {
    return useQuery({
        queryKey: ['settings'],
        queryFn: () => api.get('/settings'),
        select: (response) => response?.data?.data,
        staleTime: 5 * 60 * 1000,
    });
};

export const useUpdateSettings = () => {
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: ({ section, data }) => api.put(`/settings/${section}`, data),
        onSuccess: () => queryClient.invalidateQueries({ queryKey: ['settings'] }),
    });
};

export const useCreateUser = () => {
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: (data) => api.post('/users', data),
        onSuccess: () => queryClient.invalidateQueries({ queryKey: ['users'] }),
    });
};

export const useUpdateUser = () => {
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: ({ id, data }) => api.put(`/users/${id}`, data),
        onSuccess: () => queryClient.invalidateQueries({ queryKey: ['users'] }),
    });
};

export const useDeleteUser = () => {
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: (id) => api.delete(`/users/${id}`),
        onSuccess: () => queryClient.invalidateQueries({ queryKey: ['users'] }),
    });
};