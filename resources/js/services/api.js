  import axios from 'axios';
  import { message } from 'antd';

  /* =========================================================
    API CONFIGURATION
    ========================================================= */

  /**
   * You may use either of these values in your frontend .env:
   *
   * VITE_API_URL=http://127.0.0.1:8000
   *
   * or:
   *
   * VITE_API_URL=http://127.0.0.1:8000/api/v1
   *
   * This normalizer removes duplicate /api/v1 paths automatically.
   */
  const rawApiUrl =
    import.meta.env.VITE_API_URL ||
    'http://127.0.0.1:8000';

  const normalizedApiUrl = rawApiUrl
    .trim()
    .replace(/\/+$/, '');

  export const API_ORIGIN = normalizedApiUrl
    .replace(/(?:\/api\/v1)+$/i, '');

  export const API_BASE_URL = `${API_ORIGIN}/api/v1`;

  console.log('🔧 API Configuration:', {
    mode: import.meta.env.MODE,
    apiOrigin: API_ORIGIN,
    apiBaseUrl: API_BASE_URL,
  });

  /* =========================================================
    AXIOS INSTANCE
    ========================================================= */

  const api = axios.create({
    baseURL: API_BASE_URL,
    headers: {
      Accept: 'application/json',
      'X-Requested-With': 'XMLHttpRequest',
    },
    timeout: 30000,
    withCredentials: true, // Add this line
  });

  /* =========================================================
    HELPERS
    ========================================================= */

  export const clearAuth = () => {
    localStorage.removeItem('auth_token');
    localStorage.removeItem('authToken');
    localStorage.removeItem('token');
    localStorage.removeItem('user');
    localStorage.removeItem('userData');
  };

  export const handleApiError = (
    error,
    fallback = 'Something went wrong. Please try again.'
  ) => {
    const validationErrors =
      error?.response?.data?.errors || {};

    const firstValidationError =
      Object.values(validationErrors)
        .flat()
        .find(Boolean);

    const errorMessage =
      error?.response?.data?.message ||
      firstValidationError ||
      error?.message ||
      fallback;

    console.error(
      'API Error:',
      error?.response?.data || error
    );

    return errorMessage;
  };

  export const ensureArray = (payload) => {
    const data = payload?.data ?? payload;

    if (Array.isArray(data)) {
      return data;
    }

    if (Array.isArray(data?.data)) {
      return data.data;
    }

    if (Array.isArray(data?.data?.data)) {
      return data.data.data;
    }

    if (Array.isArray(data?.data?.data?.data)) {
      return data.data.data.data;
    }

    return [];
  };

  export const extractData = (
    response,
    fallback = []
  ) => {
    return (
      response?.data?.data?.data ||
      response?.data?.data ||
      response?.data ||
      fallback
    );
  };

  const cleanData = (data = {}) => {
    return Object.fromEntries(
      Object.entries(data).filter(([, value]) => {
        return (
          value !== null &&
          value !== undefined &&
          value !== ''
        );
      })
    );
  };

  const createFormData = (
    data = {},
    method = 'POST'
  ) => {
    const formData = new FormData();

    if (
      method === 'PUT' ||
      method === 'PATCH'
    ) {
      formData.append('_method', method);
    }

    Object.entries(data).forEach(
      ([key, value]) => {
        if (
          value === null ||
          value === undefined ||
          value === ''
        ) {
          return;
        }

        if (Array.isArray(value)) {
          formData.append(
            key,
            JSON.stringify(value)
          );

          return;
        }

        if (value instanceof File) {
          formData.append(key, value);

          return;
        }

        formData.append(
          key,
          String(value)
        );
      }
    );

    return formData;
  };

  /* =========================================================
    INTERCEPTORS
    ========================================================= */

  api.interceptors.request.use(
    (config) => {
      const token =
        localStorage.getItem('auth_token') ||
        localStorage.getItem('authToken') ||
        localStorage.getItem('token');

      if (token) {
        config.headers.Authorization =
          `Bearer ${token}`;
      }

      if (
        config.data instanceof FormData
      ) {
        delete config.headers['Content-Type'];
      } else {
        config.headers['Content-Type'] =
          'application/json';
      }

      console.log(
        `📤 ${config.method?.toUpperCase()} ${API_BASE_URL}${config.url}`,
        {
          hasToken: Boolean(token),
          data:
            config.data instanceof FormData
              ? 'FormData'
              : config.data,
        }
      );

      return config;
    },
    (error) => {
      console.error(
        'Request Error:',
        error
      );

      return Promise.reject(error);
    }
  );

api.interceptors.response.use(
  (response) => response,
  (error) => {
    console.error('❌ API Error Details:', {
      url: error.config?.url,
      method: error.config?.method,
      status: error.response?.status,
      statusText: error.response?.statusText,
      data: error.response?.data,
      message: error.message,
    });

    // Get the current path
    const currentPath = window.location.pathname;
    const isLoginPage = currentPath.includes('/login');
    const isAuthRoute = error.config?.url?.includes('/auth/');
    const isLoginEndpoint = error.config?.url === '/auth/login' || error.config?.url === '/auth/employee-login';
    const isUserEndpoint = error.config?.url === '/auth/user' || error.config?.url === '/auth/profile';
    
    // Handle 401 Unauthorized
    if (error.response?.status === 401) {
      // For login endpoints - just reject, let the login handler deal with it
      if (isLoginEndpoint) {
        return Promise.reject(error);
      }
      
      // For auth routes (like /auth/user) - reject but don't redirect
      // Let the AuthContext handle this
      if (isAuthRoute || isUserEndpoint) {
        return Promise.reject(error);
      }
      
      // Check if we have a token
      const token = localStorage.getItem('auth_token') || localStorage.getItem('authToken') || localStorage.getItem('token');
      
      // If we're on a protected page and have a token, it might be expired
      if (token && !isLoginPage) {
        // Clear auth and redirect only for non-auth requests
        clearAuth();
        message.error('Session expired. Please log in again.');
        setTimeout(() => {
          window.location.href = '/login';
        }, 1500);
      }
      
      // If we're on login page, just reject
      if (isLoginPage) {
        return Promise.reject(error);
      }
    }

    return Promise.reject(error);
  }
);
  /* =========================================================
    AUTH API
    ========================================================= */

  export const authAPI = {
    login: (data) => {
      return api.post(
        '/auth/login',
        {
          userId:
            data.userId?.trim() ||
            data.username?.trim() ||
            data.email?.trim(),
          password: data.password,
          remember_me:
            data.remember_me ||
            data.rememberMe ||
            false,
        }
      );
    },

    employeeLogin: (data) => {
      return api.post(
        '/auth/employee-login',
        {
          employee_code:
            data.employee_code ||
            data.employeeCode,
          password: data.password,
        }
      );
    },

    mobileEmployeeLogin: (
      employeeCode
    ) => {
      return api.post(
        '/auth/mobile-employee-login',
        {
          employee_code: employeeCode,
        }
      );
    },

    logout: () => {
      return api.post(
        '/auth/logout'
      );
    },

    getUser: () => {
      return api.get(
        '/auth/user'
      );
    },

    updateProfile: (data) => {
      return api.put(
        '/auth/profile',
        cleanData(data)
      );
    },

    changePassword: (data) => {
      return api.put(
        '/auth/change-password',
        cleanData(data)
      );
    },

    forgotPassword: (data) => {
      return api.post(
        '/auth/forgot-password',
        cleanData(data)
      );
    },

    verifyResetOtp: (data) => {
      return api.post(
        '/auth/verify-otp',
        cleanData(data)
      );
    },

    resendResetOtp: (data) => {
      return api.post(
        '/auth/resend-otp',
        cleanData(data)
      );
    },

    resetPassword: (data) => {
      return api.post(
        '/auth/reset-password',
        cleanData(data)
      );
    },
  };

  /* =========================================================
    EMPLOYEES API
    ========================================================= */

  export const employeeAPI = {
    getAll: (params = {}) => {
      return api.get(
        '/employees',
        { params }
      );
    },

    getById: (id) => {
      return api.get(
        `/employees/${id}`
      );
    },

    getAllEmployees: (params = {}) => {
      return api.get(
        '/employees/all',
        { params }
      );
    },

    getAllEmployeesList: (
      params = {}
    ) => {
      return api.get(
        '/employees/all-list',
        { params }
      );
    },

    getActive: () => {
      return api.get(
        '/employees/active'
      );
    },

    getOnLeave: () => {
      return api.get(
        '/employees/on-leave'
      );
    },

    getBirthdays: () => {
      return api.get(
        '/employees/birthdays'
      );
    },

    getArchived: () => {
      return api.get(
        '/employees/archived'
      );
    },

    getStats: () => {
      return api.get(
        '/employees/stats'
      );
    },

    search: (query) => {
      return api.get(
        '/employees/search',
        {
          params: {
            q: query,
          },
        }
      );
    },

    getAttendance: (id) => {
      return api.get(
        `/employees/${id}/attendance`
      );
    },

    getLeaves: (id) => {
      return api.get(
        `/employees/${id}/leaves`
      );
    },

    getPayroll: (id) => {
      return api.get(
        `/employees/${id}/payroll`
      );
    },

    toggleBookmark: (id) => {
      return api.post(
        `/employees/${id}/toggle-bookmark`
      );
    },

    updateStatus: (
      id,
      status
    ) => {
      return api.post(
        `/employees/${id}/update-status`,
        { status }
      );
    },

    restore: (id) => {
      return api.post(
        `/employees/${id}/restore`
      );
    },

    forceDelete: (id) => {
      return api.delete(
        `/employees/${id}/force`
      );
    },

    bulkArchive: (data) => {
      return api.post(
        '/employees/bulk-archive',
        data
      );
    },

    bulkDelete: (data) => {
      return api.post(
        '/employees/bulk-delete',
        data
      );
    },

    bulkUpdateStatus: (data) => {
      return api.post(
        '/employees/bulk-update-status',
        data
      );
    },

    getEligibleForPayroll: (
      params = {}
    ) => {
      return api.get(
        '/employees/eligible-for-payroll',
        { params }
      );
    },

    create: (data) => {
      const payload =
        data instanceof FormData
          ? data
          : createFormData(data);

      return api.post(
        '/employees',
        payload
      );
    },

    update: (
      id,
      data
    ) => {
      const payload =
        data instanceof FormData
          ? data
          : createFormData(
              data,
              'PUT'
            );

      if (
        data instanceof FormData &&
        !data.has('_method')
      ) {
        data.append(
          '_method',
          'PUT'
        );
      }

      return api.post(
        `/employees/${id}`,
        payload
      );
    },

    delete: (id) => {
      return api.delete(
        `/employees/${id}`
      );
    },
  };

  /* =========================================================
    SCHEDULES API
    ========================================================= */

  export const scheduleAPI = {
    getAll: (params = {}) => {
      return api.get(
        '/schedules',
        { params }
      );
    },

    getById: (id) => {
      return api.get(
        `/schedules/${id}`
      );
    },

    getByDate: (date) => {
      return api.get(
        `/schedules/date/${date}`
      );
    },

    getByEmployee: (
      employeeId
    ) => {
      return api.get(
        `/schedules/employee/${employeeId}`
      );
    },

    getWeek: (params = {}) => {
      return api.get(
        '/schedules/week',
        { params }
      );
    },

    getMonth: (params = {}) => {
      return api.get(
        '/schedules/month',
        { params }
      );
    },

    getToday: () => {
      return api.get(
        '/schedules/today'
      );
    },

    getArchived: (params = {}) => {
      return api.get(
        '/schedules/archived',
        { params }
      );
    },

    getStats: (params = {}) => {
      return api.get(
        '/schedules/stats',
        { params }
      );
    },

    getRange: (params = {}) => {
      return api.get(
        '/schedules/range',
        { params }
      );
    },

    getWarnings: () => {
      return api.get(
        '/schedules/warnings'
      );
    },

    getCompletedShifts: (
      params = {}
    ) => {
      return api.get(
        '/schedules/completed-shifts',
        { params }
      );
    },

    getEmployeeSchedule: (
      employeeId
    ) => {
      return api.get(
        `/schedules/employee/${employeeId}`
      );
    },

    getTimeOffRequests: () => {
      return api.get(
        '/employee-requests'
      );
    },

    getEmployeeRequests: () => {
      return api.get(
        '/employee-requests'
      );
    },

    create: (data) => {
      return api.post(
        '/schedules',
        cleanData(data)
      );
    },

    createBulk: (schedules) => {
      return api.post(
        '/schedules/bulk',
        { schedules }
      );
    },

    update: (
      id,
      data
    ) => {
      return api.put(
        `/schedules/${id}`,
        cleanData(data)
      );
    },

    delete: (id) => {
      return api.delete(
        `/schedules/${id}`
      );
    },

    archive: (id) => {
      return api.post(
        `/schedules/${id}/archive`
      );
    },

    restore: (id) => {
      return api.post(
        `/schedules/${id}/restore`
      );
    },

    bulkArchive: (ids) => {
      return api.post(
        '/schedules/bulk-archive',
        { ids }
      );
    },

    bulkRestore: (ids) => {
      return api.post(
        '/schedules/bulk-restore',
        { ids }
      );
    },

    export: (params = {}) => {
      return api.get(
        '/schedules/export',
        { params }
      );
    },
  };

  /* =========================================================
    ATTENDANCE API
    ========================================================= */

  export const attendanceAPI = {
    getAll: (params = {}) => {
      return api.get(
        '/attendance/all',
        { params }
      );
    },

    getToday: (params = {}) => {
      return api.get(
        '/attendance/today',
        { params }
      );
    },

    getSummary: (params = {}) => {
      return api.get(
        '/attendance/summary',
        { params }
      );
    },

    getStatistics: (
      params = {}
    ) => {
      return api.get(
        '/attendance/statistics',
        { params }
      );
    },

    getHistory: (params = {}) => {
      return api.get(
        '/attendance/history',
        { params }
      );
    },

    getDateRange: (params = {}) => {
      return api.get(
        '/attendance/range',
        { params }
      );
    },

    getNeedsApproval: (
      params = {}
    ) => {
      return api.get(
        '/attendance/needs-approval',
        { params }
      );
    },

    getByEmployee: (
      employeeId,
      params = {}
    ) => {
      return api.get(
        `/attendance/employee/${employeeId}`,
        { params }
      );
    },

    getEmployees: (
      params = {}
    ) => {
      return api.get(
        '/employees',
        { params }
      );
    },

    getDepartments: (params = {}) => {
      return api.get(
        '/departments',
        { params }
      );
    },

    mobileLogin: (employeeId) => {
      return api.post(
        '/attendance/login',
        {
          employee_id: employeeId,
        }
      );
    },

    clockIn: (data) => {
      return api.post(
        '/attendance/time-in',
        cleanData(data)
      );
    },

    clockOut: (data) => {
      return api.post(
        '/attendance/time-out',
        cleanData(data)
      );
    },

    logout: () => {
      return api.post(
        '/attendance/logout'
      );
    },

    updateStatus: (
      attendanceId,
      status,
      notes = ''
    ) => {
      const normalizedStatus =
        status === 'verified'
          ? 'APPROVED'
          : status === 'rejected'
            ? 'REJECTED'
            : status === 'pending'
              ? 'PENDING'
              : status;

      return api.put(
        `/attendance/${attendanceId}/status`,
        {
          verification_status:
            normalizedStatus,
          verification_notes:
            notes,
        }
      );
    },

    approve: (
      id,
      data = {}
    ) => {
      const payload =
        typeof data === 'string'
          ? { notes: data }
          : data;

      return api.post(
        `/attendance/${id}/approve`,
        cleanData(payload)
      );
    },

    reject: (
      id,
      notes = ''
    ) => {
      return api.put(
        `/attendance/${id}/status`,
        {
          verification_status:
            'REJECTED',
          verification_notes:
            notes,
        }
      );
    },

    unverify: (id) => {
      return api.post(
        `/attendance/${id}/unverify`
      );
    },

    approveUnscheduled: (
      id,
      adminNotes = ''
    ) => {
      return api.post(
        `/attendance/${id}/approve-unscheduled`,
        {
          admin_notes:
            adminNotes,
        }
      );
    },

    approveOvertime: (
      id,
      notes = '',
      approvedOvertimeHours =
        undefined
    ) => {
      return api.post(
        `/attendance/${id}/approve-overtime`,
        cleanData({
          notes,
          approved_overtime_hours:
            approvedOvertimeHours,
        })
      );
    },

    rejectOvertime: (
      id,
      reason = ''
    ) => {
      return api.post(
        `/attendance/${id}/reject-overtime`,
        { reason }
      );
    },

    decline: (recordId, reason = '') => {
      return api.post(`/daily-attendance/${recordId}/decline`, { reason });
    },

    undecline: (recordId) => {
      return api.post(`/daily-attendance/${recordId}/undecline`);
    },

    unapprove: (recordId) => {
      return api.post(`/daily-attendance/${recordId}/unapprove`);
    },
  };

  export const dailyAttendanceAPI = {
    getAll: (params = {}) => {
      return api.get(
        '/daily-attendance',
        { params }
      );
    },

    getPending: (params = {}) => {
      return api.get(
        '/daily-attendance/pending',
        { params }
      );
    },

    getSummary: () => {
      return api.get(
        '/daily-attendance/summary'
      );
    },

    approve: (
      recordId,
      data = {}
    ) => {
      return api.post(
        `/daily-attendance/${recordId}/approve`,
        cleanData(data)
      );
    },

    decline: (
      recordId,
      reason = ''
    ) => {
      return api.post(
        `/daily-attendance/${recordId}/decline`,
        { reason }
      );
    },

    undecline: (recordId) => {
      return api.post(
        `/daily-attendance/${recordId}/undecline`
      );
    },

    unapprove: (recordId) => {
      return api.post(
        `/daily-attendance/${recordId}/unapprove`
      );
    },

    approveOvertime: (
      recordId,
      data = {}
    ) => {
      return api.post(
        `/daily-attendance/${recordId}/approve-overtime`,
        cleanData(data)
      );
    },

    rejectOvertime: (
      recordId,
      reason = ''
    ) => {
      return api.post(
        `/daily-attendance/${recordId}/reject-overtime`,
        { reason }
      );
    },

    bulkApprove: (
      recordIds,
      notes = '',
      overtimeConfirmed = false,
      removeOvertime = false
    ) => {
      return api.post(
        '/daily-attendance/bulk-approve',
        {
          record_ids:
            recordIds,
          notes,
          overtime_confirmed:
            overtimeConfirmed,
          remove_overtime:
            removeOvertime,
        }
      );
    },
  };

  /* =========================================================
    PAYROLL API
    ========================================================= */

  export const payrollAPI = {
    getAll: (params = {}) => {
      return api.get(
        '/payroll',
        { params }
      );
    },

    getById: (id) => {
      return api.get(
        `/payroll/${id}`
      );
    },

    getHistory: (params = {}) => {
      return api.get(
        '/payroll/history',
        { params }
      );
    },

    getStats: (params = {}) => {
      return api.get(
        '/payroll/stats',
        { params }
      );
    },

    getHistoryStats: (
      params = {}
    ) => {
      return api.get(
        '/payroll/history-stats',
        { params }
      );
    },

    preview: (data) => {
      return api.post(
        '/payroll/preview',
        cleanData(data)
      );
    },

    process: (data) => {
      return api.post(
        '/payroll/process',
        cleanData(data)
      );
    },

    update: (
      id,
      data
    ) => {
      return api.put(
        `/payroll/${id}`,
        cleanData(data)
      );
    },

    approve: (id) => {
      return api.post(
        `/payroll/${id}/approve`
      );
    },

    markAsPaid: (
      id,
      data
    ) => {
      return api.post(
        `/payroll/${id}/mark-paid`,
        cleanData(data)
      );
    },

    delete: (id) => {
      return api.delete(
        `/payroll/${id}`
      );
    },

    restore: (id) => {
      return api.post(
        `/payroll/${id}/restore`
      );
    },

    permanentDelete: (id) => {
      return api.delete(
        `/payroll/${id}/permanent`
      );
    },

    bulkUpdateDeductions: (
      data
    ) => {
      return api.post(
        '/payroll/bulk-deductions',
        cleanData(data)
      );
    },

    summary: (params = {}) => {
      return api.get(
        '/payroll/summary',
        { params }
      );
    },

    processSelected: (data) => {
      return api.post('/payroll/process', cleanData(data));
    },

    previewPayroll: (data) => {
      return api.post('/payroll/preview', cleanData(data));
    },

    export: (params = {}) => {
      return api.get('/payroll/export', { params, responseType: 'blob' });
    },
  };

  export const payslipAPI = {
    getAll: (params = {}) => {
      return api.get(
        '/payslips',
        { params }
      );
    },

    getById: (id) => {
      return api.get(
        `/payslips/${id}`
      );
    },

    getByPayroll: (payrollId) => {
      return api.get(
        `/payroll/${payrollId}/payslip`
      );
    },

    generate: (data) => {
      return api.post(
        '/payslips/generate',
        cleanData(data)
      );
    },

    bulkGenerate: (
      payrollIds
    ) => {
      return api.post(
        '/payslips/bulk-generate',
        {
          payroll_ids:
            payrollIds,
        }
      );
    },

    download: (id) => {
      return api.get(
        `/payslips/${id}/download`,
        {
          responseType:
            'blob',
        }
      );
    },

    email: (id) => {
      return api.post(
        `/payslips/${id}/email`
      );
    },

    preview: (payrollId) => {
      return api.get(
        `/payroll/${payrollId}/payslip`
      );
    },

    print: (id) => {
      return api.get(
        `/payslips/${id}/download`,
        {
          responseType:
            'blob',
        }
      );
    },
  };

  /* =========================================================
    EMPLOYEE REQUESTS API
    ========================================================= */

  export const employeeRequestAPI = {
    getAll: (params = {}) => {
      return api.get(
        '/employee-requests',
        { params }
      );
    },

    getPending: (params = {}) => {
      return api.get(
        '/employee-requests/pending',
        { params }
      );
    },

    getById: (id) => {
      return api.get(
        `/employee-requests/${id}`
      );
    },

    create: (data) => {
      return api.post(
        '/employee-requests',
        cleanData(data)
      );
    },

    update: (
      id,
      data
    ) => {
      return api.put(
        `/employee-requests/${id}`,
        cleanData(data)
      );
    },

    updateStatus: (
      id,
      status,
      adminNotes = ''
    ) => {
      return api.put(
        `/employee-requests/${id}/status`,
        {
          status,
          admin_notes:
            adminNotes,
        }
      );
    },

    approve: (
      id,
      adminNotes = ''
    ) => {
      return api.put(
        `/employee-requests/${id}/status`,
        {
          status:
            'approved',
          admin_notes:
            adminNotes,
        }
      );
    },

    reject: (
      id,
      adminNotes = ''
    ) => {
      return api.put(
        `/employee-requests/${id}/status`,
        {
          status:
            'rejected',
          admin_notes:
            adminNotes,
        }
      );
    },

    cancel: (
      id,
      reason = ''
    ) => {
      return api.post(
        `/employee-requests/${id}/cancel`,
        { reason }
      );
    },

    delete: (id) => {
      return api.delete(
        `/employee-requests/${id}`
      );
    },
  };

  export const timeOffAPI = {
    getAll:
      employeeRequestAPI.getAll,

    getById:
      employeeRequestAPI.getById,

    getPending:
      employeeRequestAPI.getPending,

    getBalance: (employeeId) => {
      return api.get(
        '/employee-requests',
        {
          params: {
            employee_id:
              employeeId,
          },
        }
      );
    },

    getStats: (params = {}) => {
      return employeeRequestAPI.getAll(
        params
      );
    },

    create:
      employeeRequestAPI.create,

    update:
      employeeRequestAPI.update,

    delete:
      employeeRequestAPI.delete,

    approve:
      employeeRequestAPI.approve,

    reject:
      employeeRequestAPI.reject,
  };

  /* =========================================================
    DEPARTMENTS API
    ========================================================= */

  export const departmentAPI = {
    getAll: (params = {}) => {
      return api.get(
        '/departments',
        {
          params: {
            ...params,
            all: true,
          },
        }
      );
    },

    getPaginated: (
      params = {}
    ) => {
      return api.get(
        '/departments',
        { params }
      );
    },

    getById: (id) => {
      return api.get(
        `/departments/${id}`
      );
    },

    create: (data) => {
      return api.post(
        '/departments',
        cleanData(data)
      );
    },

    update: (
      id,
      data
    ) => {
      return api.put(
        `/departments/${id}`,
        cleanData(data)
      );
    },

    delete: (id) => {
      return api.delete(
        `/departments/${id}`
      );
    },

    getStats: () => {
      return api.get(
        '/departments/stats'
      );
    },

    getWithEmployees: () => {
      return api.get(
        '/departments/with-employees'
      );
    },
  };

  /* =========================================================
    POSITIONS API
    ========================================================= */

  export const positionAPI = {
    getAll: (params = {}) => {
      return api.get(
        '/positions',
        {
          params: {
            ...params,
            all: true,
          },
        }
      );
    },

    getPaginated: (
      params = {}
    ) => {
      return api.get(
        '/positions',
        { params }
      );
    },

    getById: (id) => {
      return api.get(
        `/positions/${id}`
      );
    },

    create: (data) => {
      return api.post(
        '/positions',
        cleanData(data)
      );
    },

    update: (
      id,
      data
    ) => {
      return api.put(
        `/positions/${id}`,
        cleanData(data)
      );
    },

    delete: (id) => {
      return api.delete(
        `/positions/${id}`
      );
    },

    getStats: () => {
      return api.get(
        '/positions/stats'
      );
    },

    getBySalaryGrade: (
      salaryGradeId
    ) => {
      return api.get(
        `/positions/by-salary-grade/${salaryGradeId}`
      );
    },
  };

  /* =========================================================
    SALARY GRADES API
    ========================================================= */

  export const salaryGradeAPI = {
    getAll: (params = {}) => {
      return api.get(
        '/salary-grades',
        {
          params: {
            ...params,
            all: true,
          },
        }
      );
    },

    getPaginated: (
      params = {}
    ) => {
      return api.get(
        '/salary-grades',
        { params }
      );
    },

    getById: (id) => {
      return api.get(
        `/salary-grades/${id}`
      );
    },

    create: (data) => {
      return api.post(
        '/salary-grades',
        cleanData(data)
      );
    },

    update: (
      id,
      data
    ) => {
      return api.put(
        `/salary-grades/${id}`,
        cleanData(data)
      );
    },

    delete: (id) => {
      return api.delete(
        `/salary-grades/${id}`
      );
    },

    getStats: () => {
      return api.get(
        '/salary-grades/stats'
      );
    },
  };

  /* =========================================================
    STAFF API ALIAS
    ========================================================= */

  export const staffAPI = {
    getDepartments:
      departmentAPI.getAll,

    getDepartment:
      departmentAPI.getById,

    createDepartment:
      departmentAPI.create,

    updateDepartment:
      departmentAPI.update,

    deleteDepartment:
      departmentAPI.delete,

    getDepartmentStats:
      departmentAPI.getStats,

    getDepartmentsWithEmployees:
      departmentAPI.getWithEmployees,

    getPositions:
      positionAPI.getAll,

    getPosition:
      positionAPI.getById,

    createPosition:
      positionAPI.create,

    updatePosition:
      positionAPI.update,

    deletePosition:
      positionAPI.delete,

    getPositionStats:
      positionAPI.getStats,

    getPositionsBySalaryGrade:
      positionAPI.getBySalaryGrade,

    getSalaryGrades:
      salaryGradeAPI.getAll,

    getSalaryGrade:
      salaryGradeAPI.getById,

    createSalaryGrade:
      salaryGradeAPI.create,

    updateSalaryGrade:
      salaryGradeAPI.update,

    deleteSalaryGrade:
      salaryGradeAPI.delete,

    getSalaryGradeStats:
      salaryGradeAPI.getStats,

    getEmployees: (
      params = {}
    ) => {
      return api.get(
        '/employees',
        { params }
      );
    },

    getEmployee: (id) => {
      return api.get(
        `/employees/${id}`
      );
    },

    getAllEmployees: (
      params = {}
    ) => {
      return api.get(
        '/employees/all',
        { params }
      );
    },

    getAllEmployeesList: (
      params = {}
    ) => {
      return api.get(
        '/employees/all-list',
        { params }
      );
    },

    getActiveEmployees:
      employeeAPI.getActive,

    getOnLeaveEmployees:
      employeeAPI.getOnLeave,

    getArchivedEmployees:
      employeeAPI.getArchived,

    getEmployeeBirthdays:
      employeeAPI.getBirthdays,

    getEmployeeStats:
      employeeAPI.getStats,

    searchEmployees:
      employeeAPI.search,

    createEmployee:
      employeeAPI.create,

    updateEmployee:
      employeeAPI.update,

    deleteEmployee:
      employeeAPI.delete,

    restoreEmployee:
      employeeAPI.restore,

    forceDeleteEmployee:
      employeeAPI.forceDelete,

    updateEmployeeStatus:
      employeeAPI.updateStatus,

    toggleBookmark:
      employeeAPI.toggleBookmark,

    bulkArchive:
      employeeAPI.bulkArchive,

    bulkDelete:
      employeeAPI.bulkDelete,

    bulkUpdateStatus:
      employeeAPI.bulkUpdateStatus,

    getEligibleForPayroll:
      employeeAPI.getEligibleForPayroll,
  };

  /* =========================================================
    MENU API
    ========================================================= */

  export const menuAPI = {
    getPublicMenuItems: (params = {}) => api.get('/public/menu-items', { params }),
    getMenuItems: (params = {}) => api.get('/menu-items', { params }),
    getMenuItem: (id) => api.get(`/menu-items/${id}`),
    createMenuItem: (data) => api.post('/menu-items', data),
    updateMenuItem: ({ id, data }) => {
      if (data instanceof FormData) {
        if (!data.has('_method')) data.append('_method', 'PUT');
        return api.post(`/menu-items/${id}`, data);
      }
      return api.put(`/menu-items/${id}`, data);
    },
    deleteMenuItem: (id) => api.delete(`/menu-items/${id}`),
    toggleAvailability: (id) => api.post(`/menu-items/${id}/toggle-availability`),
    toggleFeatured: (id) => api.post(`/menu-items/${id}/toggle-featured`),
  };

  export const categoryAPI = {
    getPublicCategories: () => api.get('/public/meal-categories'),
    getCategories: (params = {}) => api.get('/meal-categories/manage', {
      params: { ...params, manage: 1 },
    }),
    createCategory: (data) => api.post('/meal-categories', data),
    updateCategory: ({ id, data }) => api.put(`/meal-categories/${id}`, data),
    deleteCategory: (id) => api.delete(`/meal-categories/${id}`),
  };

  export const packageAPI = {
    getPublicPackages: (params = {}) => api.get('/public/packages', { params }),
    getPackages: (params = {}) => api.get('/packages', { params }), // Changed from '/packages/manage'
    getPackage: (id) => api.get(`/packages/${id}`),
    createPackage: (data) => api.post('/packages', data),
    updatePackage: ({ id, data }) => api.put(`/packages/${id}`, data),
    deletePackage: (id) => api.delete(`/packages/${id}`),
  };

  export const promotionAPI = {
    getPublicPromotions: (params = {}) => api.get('/public/promotions', { params }),
    getPromotions: (params = {}) => api.get('/promotions', { params }),
    createPromotion: (data) => api.post('/promotions', data),
    updatePromotion: ({ id, data }) => api.put(`/promotions/${id}`, data),
    deletePromotion: (id) => api.delete(`/promotions/${id}`),
  };

  export const ingredientAPI = {
    getIngredients: (params = {}) => api.get('/ingredients', { params }),
    getLowStock: (params = {}) => api.get('/ingredients/low-stock', { params }),
    getIngredient: (id) => api.get(`/ingredients/${id}`),
    createIngredient: (data) => api.post('/ingredients', data),
    updateIngredient: ({ id, data }) => api.put(`/ingredients/${id}`, data),
    deleteIngredient: (id) => api.delete(`/ingredients/${id}`),
    updateStock: ({ id, currentStock }) => api.put(`/ingredients/${id}/stock`, {
      current_stock: currentStock,
    }),
  };

  export const recipeAPI = {
    getRecipes: () => api.get('/recipes'),
    getRecipe: (menuItemId) => api.get(`/recipes/${encodeURIComponent(menuItemId)}`),
    saveRecipe: (data) => api.post('/recipes', data),
    deleteRecipe: (menuItemId) => api.delete(`/recipes/${encodeURIComponent(menuItemId)}`),
  };

  export const statisticsAPI = {
    getMenuStatistics: () => {
      return api.get(
        '/menu-statistics'
      );
    },
  };

  /* =========================================================
    PRODUCT API
    ========================================================= */

  export const productAPI = {
    getProducts: (
      params = {}
    ) => {
      return api.get(
        '/products',
        { params }
      );
    },

    getProduct: (id) => {
      return api.get(
        `/products/${id}`
      );
    },

    createProduct: (data) => {
      return api.post(
        '/products',
        cleanData(data)
      );
    },

    updateProduct: (
      id,
      data
    ) => {
      return api.put(
        `/products/${id}`,
        cleanData(data)
      );
    },

    deleteProduct: (id) => {
      return api.delete(
        `/products/${id}`
      );
    },

    restoreProduct: (id) => {
      return api.post(
        `/products/${id}/restore`
      );
    },

    getStats: () => {
      return api.get(
        '/products/stats'
      );
    },
  };

  /* =========================================================
    EQUIPMENT API
    ========================================================= */

  export const equipmentAPI = {
    getEquipment: (
      params = {}
    ) => {
      return api.get(
        '/equipment',
        { params }
      );
    },

    getEquipmentItem: (id) => {
      return api.get(
        `/equipment/${id}`
      );
    },

    createEquipment: (data) => {
      return api.post(
        '/equipment',
        cleanData(data)
      );
    },

    updateEquipment: (
      id,
      data
    ) => {
      return api.put(
        `/equipment/${id}`,
        cleanData(data)
      );
    },

    deleteEquipment: (id) => {
      return api.delete(
        `/equipment/${id}`
      );
    },

    restoreEquipment: (id) => {
      return api.post(
        `/equipment/${id}/restore`
      );
    },

    getStats: () => {
      return api.get(
        '/equipment/stats'
      );
    },

    getEquipmentHistory: (id) => {
      return api.get(
        `/equipment/${id}/history`
      );
    },
  };

  /* =========================================================
    EVENT API
    ========================================================= */

  export const eventAPI = {
    getEvents: (
      params = {}
    ) => {
      return api.get(
        '/events',
        { params }
      );
    },

    getEvent: (id) => {
      return api.get(
        `/events/${id}`
      );
    },

    createEvent: (data) => {
      return api.post(
        '/events',
        cleanData(data)
      );
    },

    updateEvent: (
      id,
      data
    ) => {
      return api.put(
        `/events/${id}`,
        cleanData(data)
      );
    },

    deleteEvent: (id) => {
      return api.delete(
        `/events/${id}`
      );
    },

    getStats: () => {
      return api.get(
        '/events/stats'
      );
    },

    returnEquipment: (
      eventCode,
      data
    ) => {
      return api.post(
        `/events/${eventCode}/return-equipment`,
        cleanData(data)
      );
    },

    getEquipment: (eventId) => {
      return api.get(
        `/events/${eventId}/equipment`
      );
    },

    checkoutEquipment: (
      eventId,
      data
    ) => {
      return api.post(
        `/events/${eventId}/equipment/checkout`,
        cleanData(data)
      );
    },

    returnEventEquipment: (
      eventId,
      transactionId,
      data
    ) => {
      return api.post(
        `/events/${eventId}/equipment/${transactionId}/return`,
        cleanData(data)
      );
    },

    assignStaff: (
      eventId,
      data
    ) => {
      return api.post(
        `/events/${eventId}/staff`,
        data
      );
    },

    getStaff: (eventId) => {
      return api.get(
        `/events/${eventId}/staff`
      );
    },

    updateStaffStatus: (
      eventId,
      staffId,
      data
    ) => {
      return api.put(
        `/events/${eventId}/staff/${staffId}`,
        data
      );
    },

    removeStaff: (
      eventId,
      staffId
    ) => {
      return api.delete(
        `/events/${eventId}/staff/${staffId}`
      );
    },

    getChecklist: (eventId) => {
      return api.get(
        `/events/${eventId}/checklist`
      );
    },

    updateChecklistItem: (
      eventId,
      itemId,
      data
    ) => {
      return api.put(
        `/events/${eventId}/checklist/${itemId}`,
        data
      );
    },

    addChecklistItem: (
      eventId,
      data
    ) => {
      return api.post(
        `/events/${eventId}/checklist`,
        data
      );
    },

    deleteChecklistItem: (
      eventId,
      itemId
    ) => {
      return api.delete(
        `/events/${eventId}/checklist/${itemId}`
      );
    },

    getDeliveries: (eventId) => {
      return api.get(
        `/events/${eventId}/deliveries`
      );
    },

    updateDeliveryStatus: (
      eventId,
      deliveryId,
      data
    ) => {
      return api.put(
        `/events/${eventId}/deliveries/${deliveryId}/status`,
        data
      );
    },

    addDelivery: (
      eventId,
      data
    ) => {
      return api.post(
        `/events/${eventId}/deliveries`,
        data
      );
    },

    getDailyProgress: (
      eventId
    ) => {
      return api.get(
        `/events/${eventId}/daily-progress`
      );
    },

    updateDailyProgress: (
      eventId,
      day,
      data
    ) => {
      return api.put(
        `/events/${eventId}/daily-progress/${day}`,
        data
      );
    },

    advanceToNextDay: (
      eventId
    ) => {
      return api.post(
        `/events/${eventId}/advance-day`
      );
    },

    updateAttendance: (
      eventId,
      day,
      data
    ) => {
      return api.put(
        `/events/${eventId}/attendance/${day}`,
        data
      );
    },

    getLiveStatus: (eventId) => {
      return api.get(
        `/events/${eventId}/live-status`
      );
    },

    updateLiveStatus: (
      eventId,
      data
    ) => {
      return api.put(
        `/events/${eventId}/live-status`,
        data
      );
    },

    completeEvent: (eventId) => {
      return api.post(
        `/events/${eventId}/complete`
      );
    },

    track: (
      id,
      data
    ) => {
      return api.post(
        `/events/${id}/tracking`,
        data
      );
    },

    getSessions: (eventId) => {
      return api.get(
        `/events/${eventId}/sessions`
      );
    },

    addSession: (
      eventId,
      data
    ) => {
      return api.post(
        `/events/${eventId}/sessions`,
        data
      );
    },

    updateSessionStatus: (
      eventId,
      sessionId,
      data
    ) => {
      return api.put(
        `/events/${eventId}/sessions/${sessionId}/status`,
        data
      );
    },

    deleteSession: (
      eventId,
      sessionId
    ) => {
      return api.delete(
        `/events/${eventId}/sessions/${sessionId}`
      );
    },
  };

  /* =========================================================
    INVENTORY HISTORY API
    ========================================================= */

  export const historyAPI = {
    getRecent: () => {
      return api.get(
        '/inventory-history/recent'
      );
    },

    getSummary: () => {
      return api.get(
        '/inventory-history/summary'
      );
    },

    getByDateRange: (
      params = {}
    ) => {
      return api.get(
        '/inventory-history/date-range',
        { params }
      );
    },

    getByType: (type) => {
      return api.get(
        `/inventory-history/type/${type}`
      );
    },

    getItemHistory: (
      type,
      id
    ) => {
      return api.get(
        `/inventory-history/item/${type}/${id}`
      );
    },
  };

  /* =========================================================
    DASHBOARD API
    ========================================================= */

  export const dashboardAPI = {
    getStats: () => {
      return api.get(
        '/dashboard/stats'
      );
    },

    getMonthlySummary: () => {
      return api.get(
        '/dashboard/monthly-summary'
      );
    },
  };

  /* =========================================================
    BOOKING API
    ========================================================= */

    export const bookingAPI = {
      getBookings: (
        params = {}
      ) => {
        return api.get(
          '/bookings',
          { params }
        );
      },

      getBooking: (id) => {
        return api.get(
          `/bookings/${id}`
        );
      },

      createBooking: (data) => {
        return api.post(
          '/bookings',
          data
        );
      },

      updateBooking: (
        id,
        data
      ) => {
        return api.put(
          `/bookings/${id}`,
          data
        );
      },

      deleteBooking: (id) => {
        return api.delete(
          `/bookings/${id}`
        );
      },

      confirmBooking: (id) => {
        return api.post(
          `/bookings/${id}/confirm`
        );
      },

      rejectBooking: (id) => {
        return api.post(
          `/bookings/${id}/reject`
        );
      },

      cancelBooking: (
        id,
        data
      ) => {
        return api.post(
          `/bookings/${id}/cancel`,
          data
        );
      },

      rescheduleBooking: (
        id,
        data
      ) => {
        return api.post(
          `/bookings/${id}/reschedule`,
          data
        );
      },

      requestReschedule: (
        id,
        data
      ) => {
        return api.post(
          `/bookings/${id}/request-reschedule`,
          data
        );
      },

      approveReschedule: (id) => {
        return api.post(
          `/bookings/${id}/approve-reschedule`
        );
      },

      rejectReschedule: (id) => {
        return api.post(
          `/bookings/${id}/reject-reschedule`
        );
      },

      recordPayment: (
        id,
        data
      ) => {
        return api.post(
          `/bookings/${id}/record-payment`,
          data
        );
      },

      getPaymentSummary: (
        bookingId
      ) => {
        return api.get(
        `/bookings/${bookingId}/payment-summary`
      );
    },

    checkConflicts: (params = {}) => {
      return api.get(
        '/bookings/check-conflicts',
        { params }
      );
    },

    getStatistics: () => {
      return api.get(
        '/bookings-statistics'
      );
    },

    getCalendarEvents: (
      params = {}
    ) => {
      return api.get(
        '/calendar-events',
        { params }
      );
    },

    getCalendarAvailability: (
      params = {}
    ) => {
      return api.get(
        '/booking-calendar/availability',
        { params }
      );
    },

    saveCalendarAvailability: (
      date,
      data
    ) => {
      return api.put(
        `/booking-calendar/availability/${date}`,
        data
      );
    },

    deleteCalendarAvailability: (
      date
    ) => {
      return api.delete(
        `/booking-calendar/availability/${date}`
      );
    },

    getEventTypes: () => {
      return api.get(
        '/event-types'
      );
    },

    createEvent: (bookingId) => {
      return api.post(
        `/bookings/${bookingId}/create-event`
      );
    },
  };

  /* =========================================================
    QUOTATION API
    ========================================================= */

  export const quotationAPI = {
    getQuotations: (
      params = {}
    ) => {
      return api.get(
        '/quotations',
        { params }
      );
    },

    getQuotation: (id) => {
      return api.get(
        `/quotations/${id}`
      );
    },

    createQuotation: (data) => {
      return api.post(
        '/quotations',
        data
      );
    },

    updateQuotation: (
      id,
      data
    ) => {
      return api.put(
        `/quotations/${id}`,
        data
      );
    },

    deleteQuotation: (id) => {
      return api.delete(
        `/quotations/${id}`
      );
    },

    approveQuotation: (id) => {
      return api.post(
        `/quotations/${id}/approve`
      );
    },

    rejectQuotation: (id) => {
      return api.post(
        `/quotations/${id}/reject`
      );
    },

    sendQuotation: (id) => {
      return api.post(
        `/quotations/${id}/send`
      );
    },
  };

  /* =========================================================
    PAYMENT API
    ========================================================= */

  export const paymentAPI = {
    getPayments: (
      params = {}
    ) => {
      return api.get(
        '/payments',
        { params }
      );
    },

    getPayment: (id) => {
      return api.get(
        `/payments/${id}`
      );
    },

    createPayment: (data) => {
      if (
        data.receipt_file instanceof File
      ) {
        const formData =
          new FormData();

        Object.entries(data).forEach(
          ([key, value]) => {
            if (
              value !== null &&
              value !== undefined
            ) {
              formData.append(
                key,
                value
              );
            }
          }
        );

        return api.post(
          '/payments',
          formData
        );
      }

      return api.post(
        '/payments',
        data
      );
    },

    updatePayment: (
      id,
      data
    ) => {
      return api.put(
        `/payments/${id}`,
        data
      );
    },

    verifyPayment: (
      id,
      notes
    ) => {
      return api.post(
        `/payments/${id}/verify`,
        { notes }
      );
    },

    rejectPayment: (
      id,
      reason
    ) => {
      return api.post(
        `/payments/${id}/reject`,
        { reason }
      );
    },

    deletePayment: (id) => {
      return api.delete(
        `/payments/${id}`
      );
    },

    downloadReceipt: (id) => {
      return api.get(
        `/payments/${id}/download-receipt`,
        {
          responseType:
            'blob',
        }
      );
    },

    refundPayment: (
      id,
      data
    ) => {
      return api.post(
        `/payments/${id}/refund`,
        data
      );
    },

    getPaymentSummary: (
      params = {}
    ) => {
      return api.get(
        '/payments/summary',
        { params }
      );
    },
  };

  /* =========================================================
    INVOICE API
    ========================================================= */

  export const invoiceAPI = {
    getInvoices: (
      params = {}
    ) => {
      return api.get(
        '/invoices',
        { params }
      );
    },

    getInvoice: (id) => {
      return api.get(
        `/invoices/${id}`
      );
    },

    createInvoice: (data) => {
      return api.post(
        '/invoices',
        data
      );
    },

    updateInvoice: (
      id,
      data
    ) => {
      return api.put(
        `/invoices/${id}`,
        data
      );
    },

    deleteInvoice: (id) => {
      return api.delete(
        `/invoices/${id}`
      );
    },

    getDebts: (
      params = {}
    ) => {
      return api.get(
        '/debts',
        { params }
      );
    },

    getInvoicePayments: (id) => {
      return api.get(
        `/invoices/${id}/payments`
      );
    },

    sendReminder: (
      id,
      data
    ) => {
      return api.post(
        `/invoices/${id}/reminder`,
        data
      );
    },

    downloadInvoice: (id) => {
      return api.get(
        `/invoices/${id}/download`,
        {
          responseType:
            'blob',
        }
      );
    },
  };

  /* =========================================================
    FINANCIAL REPORT API
    ========================================================= */

  export const financialReportAPI = {
    getReports: (
      params = {}
    ) => {
      return api.get(
        '/financial-reports',
        { params }
      );
    },

    getSalesReport: (
      params = {}
    ) => {
      return api.get(
        '/financial-reports/sales',
        { params }
      );
    },

    getExpensesReport: (
      params = {}
    ) => {
      return api.get(
        '/financial-reports/expenses',
        { params }
      );
    },

    getProfitLossReport: (
      params = {}
    ) => {
      return api.get(
        '/financial-reports/profit-loss',
        { params }
      );
    },
  };

  /* =========================================================
    SHIFT TYPE API
    ========================================================= */

  export const shiftTypeAPI = {
    getShiftTypes: (
      params = {}
    ) => {
      return api.get(
        '/shift-types',
        { params }
      );
    },

    getShiftType: (id) => {
      return api.get(
        `/shift-types/${id}`
      );
    },

    createShiftType: (data) => {
      return api.post(
        '/shift-types',
        data
      );
    },

    updateShiftType: (
      id,
      data
    ) => {
      return api.put(
        `/shift-types/${id}`,
        data
      );
    },

    deleteShiftType: (id) => {
      return api.delete(
        `/shift-types/${id}`
      );
    },
  };

  /* =========================================================
    ORDER API
    ========================================================= */

  export const orderAPI = {
    getOrders: (
      params = {}
    ) => {
      return api.get(
        '/orders',
        { params }
      );
    },

    getOrder: (id) => {
      return api.get(
        `/orders/${id}`
      );
    },

    createOrder: (data) => {
      return api.post(
        '/orders',
        data
      );
    },

    updateOrder: (
      id,
      data
    ) => {
      return api.put(
        `/orders/${id}`,
        data
      );
    },

    deleteOrder: (id) => {
      return api.delete(
        `/orders/${id}`
      );
    },

    updateStatus: (
      id,
      data
    ) => {
      return api.post(
        `/orders/${id}/status`,
        data
      );
    },

    addToKitchen: (id) => {
      return api.post(
        `/orders/${id}/add-to-kitchen`
      );
    },

    removeFromKitchen: (id) => {
      return api.post(
        `/orders/${id}/remove-from-kitchen`
      );
    },

    getKitchenOrders: () => {
      return api.get(
        '/orders/kitchen-orders'
      );
    },

    updateKitchenTask: (
      orderId,
      data
    ) => {
      return api.put(
        `/orders/${orderId}/kitchen-task`,
        data
      );
    },

    addToDelivery: (id) => {
      return api.post(
        `/orders/${id}/add-to-delivery`
      );
    },

    removeFromDelivery: (id) => {
      return api.post(
        `/orders/${id}/remove-from-delivery`
      );
    },

    getDeliveryOrders: () => {
      return api.get(
        '/orders/delivery-orders'
      );
    },

    updateDeliveryItem: (
      orderId,
      data
    ) => {
      return api.put(
        `/orders/${orderId}/delivery-item`,
        data
      );
    },

    computeIngredients: (id) => {
      return api.post(
        `/orders/${id}/compute-ingredients`
      );
    },

    getIngredientsComputed: (
      id
    ) => {
      return api.get(
        `/orders/${id}/ingredients`
      );
    },

    addToShoppingList: (
      orderId,
      data
    ) => {
      return api.post(
        `/orders/${orderId}/shopping-list`,
        data
      );
    },

    getShoppingList: (
      params = {}
    ) => {
      return api.get(
        '/shopping-list',
        { params }
      );
    },

    markShoppingItemPurchased: (
      itemId
    ) => {
      return api.post(
        `/shopping-list/items/${itemId}/purchased`
      );
    },

    deleteShoppingItem: (
      itemId
    ) => {
      return api.delete(
        `/shopping-list/items/${itemId}`
      );
    },

    bulkMarkPurchased: (
      itemIds
    ) => {
      return api.post(
        '/shopping-list/bulk-purchased',
        {
          item_ids:
            itemIds,
        }
      );
    },

    getPendingPurchasesCount:
      () => {
        return api.get(
          '/shopping-list/pending-count'
        );
      },

    getStatistics: () => {
      return api.get(
        '/orders/stats'
      );
    },

    createFromBooking: (
      bookingId
    ) => {
      return api.post(
        `/bookings/${bookingId}/create-order`
      );
    },
  };

  /* =========================================================
    CALENDAR API
    ========================================================= */

  export const calendarAPI = {
    getEvents: (
      params = {}
    ) => {
      return api.get(
        '/calendar-events',
        { params }
      );
    },
  };

  /* =========================================================
    INVENTORY API
    ========================================================= */

  export const inventoryAPI = {
    getProducts: (
      params = {}
    ) => {
      return api.get(
        '/products',
        { params }
      );
    },

    getProduct: (id) => {
      return api.get(
        `/products/${id}`
      );
    },

    getProductStats: () => {
      return api.get(
        '/products/stats'
      );
    },

    createProduct: (data) => {
      return api.post(
        '/products',
        data
      );
    },

    updateProduct: (
      id,
      data
    ) => {
      return api.put(
        `/products/${id}`,
        data
      );
    },

    deleteProduct: (id) => {
      return api.delete(
        `/products/${id}`
      );
    },

    restoreProduct: (id) => {
      return api.post(
        `/products/${id}/restore`
      );
    },

    getEquipment: (
      params = {}
    ) => {
      return api.get(
        '/equipment',
        { params }
      );
    },

    getEquipmentItem: (id) => {
      return api.get(
        `/equipment/${id}`
      );
    },

    getEquipmentStats: () => {
      return api.get(
        '/equipment/stats'
      );
    },

    getEquipmentHistory: (id) => {
      return api.get(
        `/equipment/${id}/history`
      );
    },

    createEquipment: (data) => {
      return api.post(
        '/equipment',
        data
      );
    },

    updateEquipment: (
      id,
      data
    ) => {
      return api.put(
        `/equipment/${id}`,
        data
      );
    },

    deleteEquipment: (id) => {
      return api.delete(
        `/equipment/${id}`
      );
    },

    restoreEquipment: (id) => {
      return api.post(
        `/equipment/${id}/restore`
      );
    },

    getMovements: (
      params = {}
    ) => {
      return api.get(
        '/inventory/movements',
        { params }
      );
    },

    recordMovement: (data) => {
      return api.post(
        '/inventory/movements',
        data
      );
    },

    getWasteRecords: (
      params = {}
    ) => {
      return api.get(
        '/inventory/waste',
        { params }
      );
    },

    recordWaste: (data) => {
      return api.post(
        '/inventory/waste',
        data
      );
    },

    getPurchaseRequests: (
      params = {}
    ) => {
      return api.get(
        '/inventory/purchase-requests',
        { params }
      );
    },

    createPurchaseRequest: (
      data
    ) => {
      return api.post(
        '/inventory/purchase-requests',
        data
      );
    },

    getSuppliers: (
      params = {}
    ) => {
      return api.get(
        '/suppliers',
        { params }
      );
    },

    createSupplier: (data) => {
      return api.post(
        '/suppliers',
        data
      );
    },

    getEquipmentReservations: (
      params = {}
    ) => {
      return api.get(
        '/equipment/reservations',
        { params }
      );
    },

    createEquipmentReservation: (
      data
    ) => {
      return api.post(
        '/equipment/reservations',
        data
      );
    },

    getDashboardStats: () => {
      return api.get(
        '/inventory/dashboard-stats'
      );
    },

    getInventorySummary: (
      params = {}
    ) => {
      return api.get(
        '/inventory/summary',
        { params }
      );
    },

    getEquipmentWarnings: (
      params = {}
    ) => {
      return api.get(
        '/inventory/equipment-warnings',
        { params }
      );
    },

    getItemHistory: (
      type,
      id
    ) => {
      return api.get(
        `/inventory/history/${type}/${id}`
      );
    },
  };

  /* =========================================================
    COMPATIBILITY ALIASES
    ========================================================= */

  export const schedulingAPI =
    scheduleAPI;

  export const employeeRequestsAPI =
    employeeRequestAPI;

  export default api;
