  // src/hooks/useSchedulingQueries.js
  import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
  import { employeeAPI, scheduleAPI } from '../services/api';

  const unwrap = (response) => response?.data ?? response ?? {};

  const list = (response) => {
    const body = unwrap(response);
    if (Array.isArray(body)) return body;
    if (Array.isArray(body.data)) return body.data;
    if (Array.isArray(body.data?.data)) return body.data.data;
    if (Array.isArray(body.data?.data?.data)) return body.data.data.data;
    return [];
  };

  const object = (response) => {
    const body = unwrap(response);
    return body.data ?? body;
  };

  const mutation = async (promise) => unwrap(await promise);

  export const useEmployees = (params = {}) =>
    useQuery({
      queryKey: ['employees', params],
      queryFn: async () => list(await employeeAPI.getAll(params)),
      staleTime: 5 * 60 * 1000,
    });

  export const useShifts = (params = {}) =>
    useQuery({
      queryKey: ['shifts', params],
      queryFn: async () => list(await scheduleAPI.getAll(params)),
      staleTime: 60 * 1000,
    });

  export const useArchivedShifts = () =>
    useQuery({
      queryKey: ['archived-shifts'],
      queryFn: async () => list(await scheduleAPI.getArchived()),
      staleTime: 2 * 60 * 1000,
    });

  export const useTimeOffRequests = () =>
    useQuery({
      queryKey: ['timeoff-requests'],
      queryFn: async () => list(await scheduleAPI.getTimeOffRequests()),
      staleTime: 2 * 60 * 1000,
    });

  export const useShiftStats = (date) =>
    useQuery({
      queryKey: ['shift-stats', date],
      queryFn: async () => object(await scheduleAPI.getStats({ date })),
      staleTime: 60 * 1000,
    });

  export const useEmployeeRequests = () =>
    useQuery({
      queryKey: ['employee-requests'],
      queryFn: async () => list(await scheduleAPI.getEmployeeRequests()),
      staleTime: 2 * 60 * 1000,
    });

  export const useCreateShift = () => {
    const queryClient = useQueryClient();
    return useMutation({
      mutationFn: (data) => mutation(scheduleAPI.create(data)),
      onSuccess: () => {
        queryClient.invalidateQueries({ queryKey: ['shifts'] });
        queryClient.invalidateQueries({ queryKey: ['shift-stats'] });
      },
    });
  };

  export const useUpdateShift = () => {
    const queryClient = useQueryClient();
    return useMutation({
      mutationFn: ({ id, data }) => mutation(scheduleAPI.update(id, data)),
      onSuccess: () => {
        queryClient.invalidateQueries({ queryKey: ['shifts'] });
        queryClient.invalidateQueries({ queryKey: ['shift-stats'] });
      },
    });
  };

  export const useArchiveShift = () => {
    const queryClient = useQueryClient();
    return useMutation({
      mutationFn: (id) => mutation(scheduleAPI.archive(id)),
      onSuccess: () => {
        queryClient.invalidateQueries({ queryKey: ['shifts'] });
        queryClient.invalidateQueries({ queryKey: ['archived-shifts'] });
      },
    });
  };

  export const useRestoreShift = () => {
    const queryClient = useQueryClient();
    return useMutation({
      mutationFn: (id) => mutation(scheduleAPI.restore(id)),
      onSuccess: () => {
        queryClient.invalidateQueries({ queryKey: ['shifts'] });
        queryClient.invalidateQueries({ queryKey: ['archived-shifts'] });
      },
    });
  };

  export const useBulkArchiveShifts = () => {
    const queryClient = useQueryClient();
    return useMutation({
      mutationFn: (ids) => mutation(scheduleAPI.bulkArchive(ids)),
      onSuccess: () => {
        queryClient.invalidateQueries({ queryKey: ['shifts'] });
        queryClient.invalidateQueries({ queryKey: ['archived-shifts'] });
      },
    });
  };

  export const useBulkRestoreShifts = () => {
    const queryClient = useQueryClient();
    return useMutation({
      mutationFn: (ids) => mutation(scheduleAPI.bulkRestore(ids)),
      onSuccess: () => {
        queryClient.invalidateQueries({ queryKey: ['shifts'] });
        queryClient.invalidateQueries({ queryKey: ['archived-shifts'] });
      },
    });
  };
