// src/hooks/useStaffQueries.js
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { staffAPI, departmentAPI, positionAPI, salaryGradeAPI } from '../services/api';

export const useEmployees = (filters = {}) =>
  useQuery({
    queryKey: ['employees', filters],
    queryFn: () => staffAPI.getEmployees(filters),
    staleTime: 2 * 60 * 1000,
    gcTime: 5 * 60 * 1000,
  });

export const useEmployeeStats = () =>
  useQuery({
    queryKey: ['employee-stats'],
    queryFn: () => staffAPI.getEmployeeStats(),
    staleTime: 5 * 60 * 1000,
  });

export const useArchivedEmployees = () =>
  useQuery({
    queryKey: ['archived-employees'],
    queryFn: () => staffAPI.getArchivedEmployees(),
    staleTime: 2 * 60 * 1000,
  });

export const useDepartments = () =>
  useQuery({
    queryKey: ['departments'],
    queryFn: () => departmentAPI.getAll(),
    staleTime: 10 * 60 * 1000,
  });

export const usePositions = () =>
  useQuery({
    queryKey: ['positions'],
    queryFn: () => positionAPI.getAll(),
    staleTime: 10 * 60 * 1000,
  });

export const useSalaryGrades = () =>
  useQuery({
    queryKey: ['salary-grades'],
    queryFn: () => salaryGradeAPI.getAll(),
    staleTime: 10 * 60 * 1000,
  });

export const useCreateEmployee = () => {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (data) => staffAPI.createEmployee(data),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['employees'] });
      queryClient.invalidateQueries({ queryKey: ['employee-stats'] });
    },
  });
};

export const useUpdateEmployee = () => {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: ({ id, data }) => staffAPI.updateEmployee(id, data),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['employees'] });
      queryClient.invalidateQueries({ queryKey: ['employee-stats'] });
    },
  });
};

export const useBulkUpdateStatus = () => {
  const queryClient = useQueryClient();
  return useMutation({
    // staffAPI.bulkUpdateStatus accepts one payload object, not two positional arguments.
    mutationFn: ({ ids, status }) => staffAPI.bulkUpdateStatus({ ids, status }),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['employees'] });
      queryClient.invalidateQueries({ queryKey: ['employee-stats'] });
      queryClient.invalidateQueries({ queryKey: ['archived-employees'] });
    },
  });
};

export const useToggleBookmark = () => {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (id) => staffAPI.toggleBookmark(id),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['employees'] }),
  });
};

export const useCreateDepartment = () => {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (data) => departmentAPI.create(data),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['departments'] }),
  });
};

export const useUpdateDepartment = () => {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: ({ id, data }) => departmentAPI.update(id, data),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['departments'] }),
  });
};

export const useDeleteDepartment = () => {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (id) => departmentAPI.delete(id),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['departments'] }),
  });
};

export const useCreatePosition = () => {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (data) => positionAPI.create(data),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['positions'] }),
  });
};

export const useUpdatePosition = () => {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: ({ id, data }) => positionAPI.update(id, data),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['positions'] }),
  });
};

export const useDeletePosition = () => {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (id) => positionAPI.delete(id),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['positions'] }),
  });
};

export const useCreateSalaryGrade = () => {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (data) => salaryGradeAPI.create(data),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['salary-grades'] }),
  });
};

export const useUpdateSalaryGrade = () => {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: ({ id, data }) => salaryGradeAPI.update(id, data),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['salary-grades'] }),
  });
};

export const useDeleteSalaryGrade = () => {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (id) => salaryGradeAPI.delete(id),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['salary-grades'] }),
  });
};
