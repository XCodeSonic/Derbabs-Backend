// src/hooks/usePayrollQueries.js
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { payrollAPI, payslipAPI } from '../services/api';

export const usePayrollList = (params) => {
  return useQuery({
    queryKey: ['payroll', 'list', params],
    queryFn: () => payrollAPI.getAll(params),
    staleTime: 2 * 60 * 1000,
  });
};

export const usePayrollHistory = (params) => {
  return useQuery({
    queryKey: ['payroll', 'history', params],
    queryFn: () => payrollAPI.getHistory(params),
    staleTime: 2 * 60 * 1000,
  });
};

export const usePayrollStats = (params) => {
  return useQuery({
    queryKey: ['payroll', 'stats', params],
    queryFn: () => payrollAPI.getStats(params),
    staleTime: 2 * 60 * 1000,
  });
};

export const usePayrollHistoryStats = (params) => {
  return useQuery({
    queryKey: ['payroll', 'history-stats', params],
    queryFn: () => payrollAPI.getHistoryStats(params),
    staleTime: 2 * 60 * 1000,
  });
};

export const useProcessPayroll = () => {
  const queryClient = useQueryClient();
  
  return useMutation({
    mutationFn: (data) => payrollAPI.process(data),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['payroll'] });
      queryClient.invalidateQueries({ queryKey: ['payroll', 'stats'] });
    },
  });
};

export const usePreviewPayroll = () => {
  return useMutation({
    mutationFn: (data) => payrollAPI.preview(data),
  });
};

export const useUpdatePayroll = () => {
  const queryClient = useQueryClient();
  
  return useMutation({
    mutationFn: ({ id, data }) => payrollAPI.update(id, data),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['payroll'] });
      queryClient.invalidateQueries({ queryKey: ['payroll', 'stats'] });
    },
  });
};

export const useMarkPayrollAsPaid = () => {
  const queryClient = useQueryClient();
  
  return useMutation({
    mutationFn: ({ id, data }) => payrollAPI.markAsPaid(id, data),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['payroll'] });
      queryClient.invalidateQueries({ queryKey: ['payroll', 'stats'] });
    },
  });
};

export const useDeletePayroll = () => {
  const queryClient = useQueryClient();
  
  return useMutation({
    mutationFn: (id) => payrollAPI.delete(id),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['payroll'] });
      queryClient.invalidateQueries({ queryKey: ['payroll', 'stats'] });
      queryClient.invalidateQueries({ queryKey: ['payroll', 'history'] });
    },
  });
};

export const useRestorePayroll = () => {
  const queryClient = useQueryClient();
  
  return useMutation({
    mutationFn: (id) => payrollAPI.restore(id),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['payroll'] });
      queryClient.invalidateQueries({ queryKey: ['payroll', 'stats'] });
      queryClient.invalidateQueries({ queryKey: ['payroll', 'history'] });
    },
  });
};

export const usePermanentDeletePayroll = () => {
  const queryClient = useQueryClient();
  
  return useMutation({
    mutationFn: (id) => payrollAPI.permanentDelete(id),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['payroll', 'history'] });
    },
  });
};

export const useBulkUpdateDeductions = () => {
  const queryClient = useQueryClient();
  
  return useMutation({
    mutationFn: (data) => payrollAPI.bulkUpdateDeductions(data),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['payroll'] });
      queryClient.invalidateQueries({ queryKey: ['payroll', 'stats'] });
    },
  });
};

export const useGeneratePayslip = () => {
  const queryClient = useQueryClient();
  
  return useMutation({
    mutationFn: (data) => payslipAPI.generate(data),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['payslips'] });
    },
  });
};

export const useBulkGeneratePayslips = () => {
  const queryClient = useQueryClient();
  
  return useMutation({
    mutationFn: (payrollIds) => payslipAPI.bulkGenerate(payrollIds),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['payslips'] });
    },
  });
};

export const useDownloadPayslip = () => {
  return useMutation({
    mutationFn: (id) => payslipAPI.download(id),
  });
};

export const usePreviewPayslip = () => {
  return useMutation({
    mutationFn: (payrollId) => payslipAPI.preview(payrollId),
  });
};