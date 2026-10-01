import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { employeeRequestAPI } from '../services/api';

const list = (res) => {
  const data = res?.data?.data;
  if (Array.isArray(data)) return data;
  if (Array.isArray(data?.data)) return data.data;
  return [];
};

export const employeeRequestKeys = {
  all: ['employee-requests'],
  list: (params = {}) => ['employee-requests', 'list', params],
};

export const useEmployeeRequests = (params = {}) =>
  useQuery({
    queryKey: employeeRequestKeys.list(params),
    queryFn: async () => list(await employeeRequestAPI.getAll(params)),
  });

export const useCreateEmployeeRequest = () => {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: employeeRequestAPI.create,
    onSuccess: () => qc.invalidateQueries({ queryKey: employeeRequestKeys.all }),
  });
};

export const useUpdateEmployeeRequestStatus = () => {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ id, status, adminNotes }) =>
      employeeRequestAPI.updateStatus(id, status, adminNotes),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: employeeRequestKeys.all });
      qc.invalidateQueries({ queryKey: ['schedules'] });
    },
  });
};

export const useCancelEmployeeRequest = () => {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ id, reason }) => employeeRequestAPI.cancel(id, reason),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: employeeRequestKeys.all });
      qc.invalidateQueries({ queryKey: ['schedules'] });
    },
  });
};
