// src/hooks/useAttendanceQueries.jsx
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { attendanceAPI, employeeRequestAPI } from '../services/api';
import api from '../services/api';

const unwrapBody = (response) => response?.data ?? response ?? {};

const extractList = (payload) => {
  const body = unwrapBody(payload);

  if (Array.isArray(body)) return body;
  if (Array.isArray(body.data)) return body.data;
  if (Array.isArray(body.data?.data)) return body.data.data;
  if (Array.isArray(body.data?.data?.data)) return body.data.data.data;

  return [];
};

const unwrapMutation = async (promise) => unwrapBody(await promise);

const normalizeVerificationStatus = (value) => {
  const status = String(value || 'pending').toLowerCase();
  if (status === 'approved' || status === 'verified') return 'verified';
  if (status === 'rejected' || status === 'declined') return 'rejected';
  return 'pending';
};

export const normalizeAttendanceLog = (record = {}) => {
  const employee = record.employee || {};
  const person = employee.person || {};
  const department = employee.department || {};
  const attendanceId = record.id ?? record.attendance_id;
  const employeeName =
    record.employee_name ||
    employee.full_name ||
    [employee.first_name || person.first_name, employee.last_name || person.last_name]
      .filter(Boolean)
      .join(' ') ||
    'N/A';

  const timeIn = record.time_in || null;
  const timeOut = record.time_out || null;
  const regularHours = Number(record.regular_hours || 0);
  const overtimeHours = Number(record.overtime_hours || 0);
  const undertimeHours = Number(record.undertime_hours || 0);

  return {
    ...record,
    id: attendanceId,
    attendance_id: attendanceId,
    employee_name: employeeName,
    employee_code: record.employee_code || employee.employee_code || 'N/A',
    department: record.department || department.name || 'N/A',
    verification_status: normalizeVerificationStatus(
      record.verification_status || record.approval_status
    ),
    formatted_date:
      record.formatted_date ||
      (record.attendance_date
        ? new Date(record.attendance_date).toLocaleDateString('en-US', {
            month: 'short',
            day: 'numeric',
            year: 'numeric',
          })
        : 'N/A'),
    formatted_time_in:
      record.formatted_time_in ||
      (timeIn
        ? new Date(timeIn).toLocaleTimeString('en-US', {
            hour: 'numeric',
            minute: '2-digit',
          })
        : null),
    formatted_time_out:
      record.formatted_time_out ||
      (timeOut
        ? new Date(timeOut).toLocaleTimeString('en-US', {
            hour: 'numeric',
            minute: '2-digit',
          })
        : null),
    timestamp: record.timestamp || timeIn || timeOut || record.attendance_date || null,
    selfie_url: record.selfie_url || record.time_in_photo || record.time_out_photo || null,
    location:
      record.location ||
      (record.time_in_latitude != null && record.time_in_longitude != null
        ? { lat: Number(record.time_in_latitude), lng: Number(record.time_in_longitude) }
        : record.time_out_latitude != null && record.time_out_longitude != null
          ? { lat: Number(record.time_out_latitude), lng: Number(record.time_out_longitude) }
          : null),
    regular_hours: regularHours,
    overtime_hours: overtimeHours,
    undertime_hours: undertimeHours,
    late_minutes: Number(record.late_minutes || 0),
    undertime_minutes: Number(record.undertime_minutes || undertimeHours * 60 || 0),
    total_hours: Number(record.total_hours || regularHours + overtimeHours),
    status: record.status || (record.time_in ? (record.time_out ? 'completed' : 'present') : 'absent'),
  };
};

export const expandAttendanceLogs = (records = []) => {
  return records.flatMap((rawRecord) => {
    const record = normalizeAttendanceLog(rawRecord);
    const events = [];

    if (record.time_in) {
      events.push({
        ...record,
        event_id: `${record.id}-IN`,
        type: 'IN',
        timestamp: record.time_in,
        selfie_url: record.time_in_photo || record.selfie_url,
        location:
          record.time_in_latitude != null && record.time_in_longitude != null
            ? { lat: Number(record.time_in_latitude), lng: Number(record.time_in_longitude) }
            : record.location,
      });
    }

    if (record.time_out) {
      events.push({
        ...record,
        event_id: `${record.id}-OUT`,
        type: 'OUT',
        timestamp: record.time_out,
        selfie_url: record.time_out_photo || record.selfie_url,
        location:
          record.time_out_latitude != null && record.time_out_longitude != null
            ? { lat: Number(record.time_out_latitude), lng: Number(record.time_out_longitude) }
            : record.location,
      });
    }

    if (events.length === 0) {
      events.push({
        ...record,
        event_id: `${record.id}-IN`,
        type: record.type || 'IN',
      });
    }

    return events;
  });
};

const replacePayloadList = (payload, rows) => {
  const body = unwrapBody(payload);

  if (Array.isArray(body.data)) {
    return { ...body, data: rows };
  }

  if (body.data && typeof body.data === 'object') {
    return { ...body, data: { ...body.data, data: rows } };
  }

  return { ...body, data: rows };
};

// ============================================================
// QUERY KEYS
// ============================================================
export const attendanceKeys = {
  all: ['attendance'],
  lists: () => [...attendanceKeys.all, 'list'],
  list: (filters) => [...attendanceKeys.lists(), filters],
  details: () => [...attendanceKeys.all, 'detail'],
  detail: (id) => [...attendanceKeys.details(), id],
  today: () => [...attendanceKeys.all, 'today'],
  summary: () => [...attendanceKeys.all, 'summary'],
  statistics: (year, month) => [...attendanceKeys.all, 'statistics', year, month],
  pending: () => [...attendanceKeys.all, 'pending'],
  employee: (employeeId) => [...attendanceKeys.all, 'employee', employeeId],
  mobile: () => [...attendanceKeys.all, 'mobile'],
};

// ============================================================
// ATTENDANCE QUERIES
// ============================================================

export const useMobileAttendance = (params = {}) =>
  useQuery({
    queryKey: attendanceKeys.list(params),
    queryFn: async () => {
      const payload = unwrapBody(await attendanceAPI.getAll(params));
      return replacePayloadList(payload, expandAttendanceLogs(extractList(payload)));
    },
    staleTime: 30 * 1000,
    refetchInterval: 30 * 1000,
  });

export const useAttendanceStatistics = (year, month) =>
  useQuery({
    queryKey: attendanceKeys.statistics(year, month),
    queryFn: async () => unwrapBody(await attendanceAPI.getStatistics({ year, month })),
    staleTime: 2 * 60 * 1000,
  });

export const useStatusPanel = (params = {}) =>
  useQuery({
    queryKey: attendanceKeys.pending(params),
    queryFn: async () => {
      const payload = unwrapBody(await attendanceAPI.getNeedsApproval(params));
      return replacePayloadList(payload, extractList(payload).map(normalizeAttendanceLog));
    },
    staleTime: 30 * 1000,
    refetchInterval: 30 * 1000,
  });

export const useStatusPanelSummary = () =>
  useQuery({
    queryKey: attendanceKeys.summary(),
    queryFn: async () => unwrapBody(await attendanceAPI.getSummary()),
    staleTime: 30 * 1000,
    refetchInterval: 30 * 1000,
  });

export const useEmployeesList = () =>
  useQuery({
    queryKey: ['employees-list'],
    queryFn: async () => unwrapBody(await attendanceAPI.getEmployees({ all: true, per_page: 1000 })),
    staleTime: 5 * 60 * 1000,
  });

export const useDepartmentsList = () =>
  useQuery({
    queryKey: ['departments-list'],
    queryFn: async () => unwrapBody(await attendanceAPI.getDepartments({ all: true })),
    staleTime: 5 * 60 * 1000,
  });

// ==================== ADDED: TODAY ATTENDANCE QUERY ====================
export const useTodayAttendance = (params = {}) =>
  useQuery({
    queryKey: attendanceKeys.today(),
    queryFn: async () => {
      const payload = unwrapBody(await attendanceAPI.getToday(params));
      const records = extractList(payload);
      return records.map(normalizeAttendanceLog);
    },
    staleTime: 30 * 1000,
    refetchInterval: 30 * 1000,
  });

// ==================== ADDED: ATTENDANCE BY EMPLOYEE ====================
export const useAttendanceByEmployee = (employeeId, params = {}) =>
  useQuery({
    queryKey: attendanceKeys.employee(employeeId),
    queryFn: async () => {
      const payload = unwrapBody(await attendanceAPI.getByEmployee(employeeId, params));
      const records = extractList(payload);
      return records.map(normalizeAttendanceLog);
    },
    enabled: !!employeeId,
    staleTime: 2 * 60 * 1000,
  });

// ==================== ADDED: ATTENDANCE HISTORY ====================
export const useAttendanceHistory = (params = {}) =>
  useQuery({
    queryKey: attendanceKeys.list({ ...params, history: true }),
    queryFn: async () => {
      const payload = unwrapBody(await attendanceAPI.getHistory(params));
      const records = extractList(payload);
      return records.map(normalizeAttendanceLog);
    },
    staleTime: 5 * 60 * 1000,
  });

// ==================== ADDED: ATTENDANCE BY DATE RANGE ====================
export const useAttendanceByDateRange = (startDate, endDate, params = {}) =>
  useQuery({
    queryKey: attendanceKeys.list({ ...params, start_date: startDate, end_date: endDate }),
    queryFn: async () => {
      const payload = unwrapBody(await attendanceAPI.getDateRange({ start_date: startDate, end_date: endDate, ...params }));
      const records = extractList(payload);
      return records.map(normalizeAttendanceLog);
    },
    enabled: !!startDate && !!endDate,
    staleTime: 2 * 60 * 1000,
  });

// ==================== ADDED: ATTENDANCE SUMMARY STATS ====================
export const useAttendanceSummaryStats = () =>
  useQuery({
    queryKey: attendanceKeys.summary(),
    queryFn: async () => {
      const stats = unwrapBody(await attendanceAPI.getSummary());
      return {
        total: stats.total || 0,
        present: stats.present || 0,
        late: stats.late || 0,
        absent: stats.absent || 0,
        pending_approval: stats.pending_approval_count || 0,
        approved_this_month: stats.approved_this_month || 0,
        declined_this_month: stats.declined_this_month || 0,
        approval_rate: stats.approval_rate || 0,
      };
    },
    staleTime: 30 * 1000,
    refetchInterval: 30 * 1000,
  });

// ============================================================
// ATTENDANCE MUTATIONS
// ============================================================

export const useUpdateAttendanceStatus = () => {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: ({ attendanceId, status, notes }) =>
      unwrapMutation(attendanceAPI.updateStatus(attendanceId, status, notes)),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: attendanceKeys.all });
    },
  });
};

export const useApproveStatusPanelRecord = () => {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: ({
      recordId,
      notes,
      overtimeConfirmed,
      removeOvertime,
      overtimeReason,
      approvedOvertimeHours,
    }) =>
      unwrapMutation(
        attendanceAPI.approve(recordId, {
          notes,
          overtime_confirmed: Boolean(overtimeConfirmed),
          remove_overtime: Boolean(removeOvertime),
          overtime_reason: overtimeReason,
          approved_overtime_hours: approvedOvertimeHours,
        })
      ),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: attendanceKeys.all });
    },
  });
};

export const useDeclineStatusPanelRecord = () => {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: ({ recordId, reason }) =>
      unwrapMutation(attendanceAPI.updateStatus(recordId, 'rejected', reason)),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: attendanceKeys.all });
    },
  });
};

export const useUndeclineRecord = () => {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (recordId) => unwrapMutation(attendanceAPI.updateStatus(recordId, 'pending')),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: attendanceKeys.all });
    },
  });
};

export const useUnapproveRecord = () => {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (recordId) => unwrapMutation(attendanceAPI.unverify(recordId)),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: attendanceKeys.all });
    },
  });
};

export const useUnverifyAttendance = () => {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (attendanceId) => unwrapMutation(attendanceAPI.unverify(attendanceId)),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: attendanceKeys.all });
    },
  });
};

// ==================== ADDED: CLOCK IN MUTATION ====================
export const useClockIn = () => {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (data) => unwrapMutation(attendanceAPI.clockIn(data)),
    onSuccess: () => {
      message.success('Time in recorded successfully');
      queryClient.invalidateQueries({ queryKey: attendanceKeys.all });
      queryClient.invalidateQueries({ queryKey: attendanceKeys.today() });
    },
    onError: (error) => {
      message.error(error.response?.data?.message || 'Failed to record time in');
    },
  });
};

// ==================== ADDED: CLOCK OUT MUTATION ====================
export const useClockOut = () => {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (data) => unwrapMutation(attendanceAPI.clockOut(data)),
    onSuccess: () => {
      message.success('Time out recorded successfully');
      queryClient.invalidateQueries({ queryKey: attendanceKeys.all });
      queryClient.invalidateQueries({ queryKey: attendanceKeys.today() });
    },
    onError: (error) => {
      message.error(error.response?.data?.message || 'Failed to record time out');
    },
  });
};

// ==================== ADDED: MOBILE LOGIN MUTATION ====================
export const useMobileLogin = () => {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (employeeId) => unwrapMutation(attendanceAPI.mobileLogin(employeeId)),
    onSuccess: (data) => {
      message.success('Logged in successfully');
      queryClient.setQueryData(attendanceKeys.mobile(), data);
    },
    onError: (error) => {
      message.error(error.response?.data?.message || 'Failed to log in');
    },
  });
};

// ==================== ADDED: MOBILE LOGOUT MUTATION ====================
export const useMobileLogout = () => {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: () => unwrapMutation(attendanceAPI.logout()),
    onSuccess: () => {
      message.success('Logged out successfully');
      queryClient.removeQueries({ queryKey: attendanceKeys.mobile() });
    },
    onError: (error) => {
      message.error(error.response?.data?.message || 'Failed to log out');
    },
  });
};

// ============================================================
// EMPLOYEE REQUEST QUERIES
// ============================================================

const employeeRequestList = (payload) => extractList(unwrapBody(payload));

export const employeeRequestKeys = {
  all: ['employee-requests'],
  list: (params = {}) => ['employee-requests', 'list', params],
};

export const useEmployeeRequests = (params = {}) =>
  useQuery({
    queryKey: employeeRequestKeys.list(params),
    queryFn: async () => employeeRequestList(await employeeRequestAPI.getAll(params)),
  });

export const useCreateEmployeeRequest = () => {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (data) => unwrapMutation(employeeRequestAPI.create(data)),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: employeeRequestKeys.all }),
  });
};

export const useUpdateEmployeeRequestStatus = () => {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: ({ id, status, adminNotes }) =>
      unwrapMutation(employeeRequestAPI.updateStatus(id, status, adminNotes)),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: employeeRequestKeys.all });
      queryClient.invalidateQueries({ queryKey: attendanceKeys.all });
    },
  });
};

export const useCancelEmployeeRequest = () => {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: ({ id, reason }) => unwrapMutation(employeeRequestAPI.cancel(id, reason)),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: employeeRequestKeys.all });
      queryClient.invalidateQueries({ queryKey: attendanceKeys.all });
    },
  });
};

// ============================================================
// BULK OPERATIONS
// ============================================================

export const useBulkApproveAttendance = () => {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: ({ recordIds, notes, overtimeConfirmed, removeOvertime }) =>
      unwrapMutation(attendanceAPI.bulkApprove(recordIds, notes, overtimeConfirmed, removeOvertime)),
    onSuccess: () => {
      message.success('Attendance records approved successfully');
      queryClient.invalidateQueries({ queryKey: attendanceKeys.all });
    },
    onError: (error) => {
      message.error(error.response?.data?.message || 'Failed to approve attendance records');
    },
  });
};

// Import message for toast notifications
import { message } from 'antd';

// ============================================================
// EXPORTS
// ============================================================
export default {
  // Queries
  useMobileAttendance,
  useAttendanceStatistics,
  useStatusPanel,
  useStatusPanelSummary,
  useEmployeesList,
  useDepartmentsList,
  useTodayAttendance,
  useAttendanceByEmployee,
  useAttendanceHistory,
  useAttendanceByDateRange,
  useAttendanceSummaryStats,
  useEmployeeRequests,
  // Mutations
  useUpdateAttendanceStatus,
  useApproveStatusPanelRecord,
  useDeclineStatusPanelRecord,
  useUndeclineRecord,
  useUnapproveRecord,
  useUnverifyAttendance,
  useClockIn,
  useClockOut,
  useMobileLogin,
  useMobileLogout,
  useCreateEmployeeRequest,
  useUpdateEmployeeRequestStatus,
  useCancelEmployeeRequest,
  useBulkApproveAttendance,
  // Utilities
  normalizeAttendanceLog,
  expandAttendanceLogs,
};