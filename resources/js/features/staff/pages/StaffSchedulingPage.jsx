// src/components/Staff/Staff_Scheduling.jsx - COMPLETE FIXED VERSION

import { API_BASE_URL, API_ORIGIN } from '../../../config/env';
import moment from 'moment';
import React, { useState, useEffect, useCallback, useMemo } from 'react';
import { 
  CalendarOutlined, ClockCircleOutlined, TeamOutlined, 
  UserOutlined, DollarOutlined, PlusOutlined, ExportOutlined,
  CloseOutlined, CheckCircleOutlined, WarningOutlined, 
  ScheduleOutlined, BarChartOutlined, BankOutlined, IdcardOutlined, 
  DeleteOutlined, EditOutlined, EyeOutlined, 
  HistoryOutlined, LeftOutlined, RightOutlined, DownOutlined, 
  FileExcelOutlined, FilePdfOutlined, SearchOutlined, TableOutlined, 
  InfoCircleOutlined, PercentageOutlined, MailOutlined, PhoneOutlined, 
  EnvironmentOutlined, LoadingOutlined, InboxOutlined, RollbackOutlined, 
  EnvironmentFilled, CalendarFilled, SaveOutlined, FileTextOutlined,
  ReloadOutlined, PrinterOutlined, CheckSquareOutlined, 
  FilterOutlined, SettingOutlined, TagsOutlined,
  ThunderboltOutlined,
  MessageOutlined, FormOutlined, SwapOutlined, AlertOutlined
} from '@ant-design/icons';
import { 
  useEmployees,
  useShifts, 
  useArchivedShifts,
  useTimeOffRequests,
  useCreateShift,
  useUpdateShift,
  useArchiveShift,
  useRestoreShift,
  useBulkArchiveShifts,
  useBulkRestoreShifts,
  useShiftStats,
  useEmployeeRequests
} from '../../../hooks/useSchedulingQueries';
import '../styles/StaffScheduling.css';

// ==================== HELPER FUNCTIONS ====================

const getAuthToken = () => {
  const token = localStorage.getItem('auth_token');
  if (token) return token;
  const altToken = localStorage.getItem('authToken');
  if (altToken) return altToken;
  return null;
};

const getPositionTitle = (position) => {
  if (!position) return 'N/A';
  if (typeof position === 'string') return position;
  if (typeof position === 'object') {
    return position.title || position.name || 'N/A';
  }
  return 'N/A';
};

const getEmployeeSalaryGrade = (employee) => {
  if (!employee) return 'N/A';
  if (employee.position?.salary_grade?.grade_name) {
    return employee.position.salary_grade.grade_name;
  }
  if (employee.salary_grade) {
    return employee.salary_grade;
  }
  if (employee.position?.salary_grade_id) {
    return `Grade ${employee.position.salary_grade_id}`;
  }
  return 'N/A';
};

const getEmployeeHourlyRate = (employee) => {
  if (!employee) return 0;
  if (employee.hourly_rate && employee.hourly_rate > 0) {
    return employee.hourly_rate;
  }
  if (employee.hourly_rate_override && employee.hourly_rate_override > 0) {
    return employee.hourly_rate_override;
  }
  if (employee.monthly_salary && employee.monthly_salary > 0) {
    return employee.monthly_salary / 160;
  }
  if (employee.daily_rate && employee.daily_rate > 0) {
    return employee.daily_rate / 8;
  }
  if (employee.position?.salary_grade?.hourly_rate) {
    return employee.position.salary_grade.hourly_rate;
  }
  return 0;
};

const getProfilePhotoUrl = (employee, apiUrl = API_ORIGIN) => {
  if (!employee) return null;
  
  if (employee.profile_photo_url) {
    if (employee.profile_photo_url.startsWith('http')) return employee.profile_photo_url;
    if (employee.profile_photo_url.startsWith('/')) return `${apiUrl}${employee.profile_photo_url}`;
    return `${apiUrl}/storage/${employee.profile_photo_url}`;
  }
  
  if (employee.profile_photo) {
    if (employee.profile_photo.startsWith('http')) return employee.profile_photo;
    if (employee.profile_photo.startsWith('/')) return `${apiUrl}${employee.profile_photo}`;
    const cleanPath = employee.profile_photo.replace(/^storage\//, '');
    return `${apiUrl}/storage/${cleanPath}`;
  }
  
  return null;
};

const formatCurrency = (amount) => {
  const numAmount = Math.max(0, Number(amount) || 0);
  return new Intl.NumberFormat('en-PH', { 
    style: 'currency', 
    currency: 'PHP', 
    minimumFractionDigits: 2, 
    maximumFractionDigits: 2 
  }).format(numAmount);
};

const formatDate = (dateString) => {
  if (!dateString) return '';
  try {
    const date = new Date(dateString);
    return date.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
  } catch {
    return dateString;
  }
};

const formatTo12Hour = (time24) => {
  if (!time24 || time24 === 'null' || time24 === 'undefined' || time24 === '') return '--:-- --';
  try {
    let timeStr = time24;
    if (timeStr.includes('T')) timeStr = timeStr.split('T')[1].split('.')[0];
    const [hours, minutes] = timeStr.split(':');
    if (!hours || isNaN(hours)) return '--:-- --';
    const hour = parseInt(hours);
    const ampm = hour >= 12 ? 'PM' : 'AM';
    const hour12 = hour % 12 || 12;
    const mins = minutes || '00';
    return `${hour12}:${mins} ${ampm}`;
  } catch {
    return '--:-- --';
  }
};

const formatTimeRange = (startTime, endTime) => {
  if (!startTime || !endTime || startTime === 'null' || endTime === 'null' || startTime === '' || endTime === '') {
    return 'Not scheduled';
  }
  const startFormatted = formatTo12Hour(startTime);
  const endFormatted = formatTo12Hour(endTime);
  if (startFormatted === '--:-- --' || endFormatted === '--:-- --') return 'Not scheduled';
  return `${startFormatted} - ${endFormatted}`;
};

const timeToMinutes = (timeStr) => {
  if (!timeStr || timeStr === 'null' || timeStr === 'undefined' || timeStr === '') return 0;
  let timeString = timeStr;
  if (timeString.includes('T')) timeString = timeString.split('T')[1].split('.')[0];
  const parts = timeString.split(':');
  if (parts.length < 2) return 0;
  const hours = parseInt(parts[0]);
  const minutes = parseInt(parts[1]);
  if (isNaN(hours) || isNaN(minutes) || hours < 0 || hours > 23 || minutes < 0 || minutes > 59) return 0;
  return (hours * 60) + minutes;
};

const calculateDurationHours = (startTime, endTime) => {
  if (!startTime || !endTime || startTime === 'null' || endTime === 'null' || startTime === '' || endTime === '') return 0;
  
  const startMinutes = timeToMinutes(startTime);
  let endMinutes = timeToMinutes(endTime);
  
  if (isNaN(startMinutes) || isNaN(endMinutes)) return 0;
  if (startMinutes === 0 && endMinutes === 0) return 0;
  
  if (endMinutes < startMinutes && endMinutes !== 0) endMinutes += 24 * 60;
  
  const durationMinutes = endMinutes - startMinutes;
  if (durationMinutes <= 0) return 0;
  
  return Math.round((durationMinutes / 60) * 100) / 100;
};

const calculateTotalEarnings = (hourlyRate, durationHours) => {
  const rate = Math.max(0, Number(hourlyRate) || 0);
  const hours = Math.max(0, Number(durationHours) || 0);
  if (rate <= 0 || hours <= 0) return 0;
  const earnings = rate * hours;
  return Math.round(earnings * 100) / 100;
};

const isWithin15DayWindow = (date) => {
  const today = new Date();
  today.setHours(0, 0, 0, 0);
  const selectedDate = new Date(date);
  selectedDate.setHours(0, 0, 0, 0);
  const diffDays = (selectedDate - today) / (1000 * 60 * 60 * 24);
  return diffDays >= 0 && diffDays <= 15;
};

// ==================== REQUEST TYPE HELPERS ====================

const getRequestTypeInfo = (type) => {
  const types = {
    dayoff: { label: 'Day Off', color: '#10B981', icon: '☀️', bgColor: '#D1FAE5' },
    restday: { label: 'Rest Day', color: '#8B5CF6', icon: '😴', bgColor: '#EDE9FE' },
    leave: { label: 'Leave', color: '#3B82F6', icon: '✈️', bgColor: '#DBEAFE' },
    swap: { label: 'Shift Swap', color: '#F59E0B', icon: '🔄', bgColor: '#FEF3C7' },
    sick: { label: 'Sick Leave', color: '#EC4899', icon: '🤒', bgColor: '#FCE7F3' },
    vacation: { label: 'Vacation', color: '#06B6D4', icon: '🏖️', bgColor: '#CFFAFE' },
    personal: { label: 'Personal Time', color: '#6366F1', icon: '👤', bgColor: '#E0E7FF' },
    emergency: { label: 'Emergency', color: '#DC2626', icon: '🚨', bgColor: '#FEE2E2' },
    ot: { label: 'Overtime', color: '#EF4444', icon: '⏰', bgColor: '#FEE2E2' }
  };
  return types[type] || { label: type || 'Unknown', color: '#6B7280', icon: '📝', bgColor: '#F3F4F6' };
};

const getRequestStatusColor = (status) => {
  switch (status) {
    case 'approved': return '#10B981';
    case 'rejected': return '#EF4444';
    case 'cancelled': return '#6B7280';
    default: return '#F59E0B';
  }
};

const getRequestStatusBgColor = (status) => {
  switch (status) {
    case 'approved': return '#D1FAE5';
    case 'rejected': return '#FEE2E2';
    case 'cancelled': return '#F3F4F6';
    default: return '#FEF3C7';
  }
};

const getRequestStatusLabel = (status) => {
  switch (status) {
    case 'approved': return 'Approved';
    case 'rejected': return 'Rejected';
    case 'cancelled': return 'Cancelled';
    default: return 'Pending';
  }
};

// ==================== SKELETON LOADING COMPONENTS ====================
const SkeletonStat = () => (
  <div className="sdf-stat-compact skeleton">
    <div className="skeleton-icon"></div>
    <div className="sdf-stat-info">
      <div className="skeleton-text short"></div>
      <div className="skeleton-text medium"></div>
    </div>
  </div>
);

const SkeletonLaborCompact = () => (
  <div className="sdf-labor-compact skeleton">
    <div className="sdf-labor-main">
      <div className="skeleton-icon"></div>
      <div className="sdf-labor-details">
        <div className="skeleton-text short"></div>
        <div className="skeleton-text medium"></div>
        <div className="skeleton-text small"></div>
      </div>
    </div>
  </div>
);

const SkeletonScheduleRow = () => (
  <tr className="skeleton-row">
    <td><div className="skeleton-checkbox"></div></td>
    <td>
      <div className="skeleton-employee-cell">
        <div className="skeleton-avatar"></div>
        <div>
          <div className="skeleton-text"></div>
          <div className="skeleton-text short"></div>
        </div>
      </div>
    </td>
    <td><div className="skeleton-text"></div></td>
    <td><div className="skeleton-text"></div></td>
    <td><div className="skeleton-badge"></div></td>
    <td><div className="skeleton-shift-block">
        <div className="skeleton-text"></div>
        <div className="skeleton-text short"></div>
      </div></td>
    <td><div className="skeleton-text small"></div></td>
    <td><div className="skeleton-text small"></div></td>
    <td><div className="skeleton-icon-btn"></div></td>
  </tr>
);

const SkeletonScheduleTable = () => (
  <div className="skeleton-table-container">
    <table className="skeleton-table">
      <thead>
        <tr>
          {[...Array(9)].map((_, i) => (
            <th key={i} className="skeleton-table-header-cell"></th>
          ))}
        </tr>
      </thead>
      <tbody>
        {[...Array(5)].map((_, i) => (
          <SkeletonScheduleRow key={i} />
        ))}
      </tbody>
    </table>
  </div>
);

// ==================== AVATAR COMPONENT ====================
const EmployeeAvatar = ({ employee, size = 'small' }) => {
  const [imageError, setImageError] = useState(false);
  const photoUrl = employee?.profile_photo_url || getProfilePhotoUrl(employee);
  
  const sizeConfig = {
    small: { width: '32px', height: '32px', fontSize: '14px', borderRadius: '16px' },
    large: { width: '64px', height: '64px', fontSize: '28px', borderRadius: '32px' },
    default: { width: '40px', height: '40px', fontSize: '18px', borderRadius: '20px' }
  };
  
  const config = sizeConfig[size] || sizeConfig.default;
  
  if (photoUrl && !imageError) {
    return (
      <img 
        src={photoUrl} 
        alt={employee?.name || 'Employee'} 
        style={{
          width: config.width,
          height: config.height,
          borderRadius: config.borderRadius,
          objectFit: 'cover',
          border: '2px solid #fff',
          boxShadow: '0 2px 8px rgba(0,0,0,0.08)'
        }}
        onError={() => setImageError(true)}
      />
    );
  }
  
  const initials = employee?.name ? employee.name.charAt(0).toUpperCase() : 'U';
  return (
    <div style={{
      width: config.width,
      height: config.height,
      borderRadius: config.borderRadius,
      background: 'linear-gradient(135deg, #1a1a2e 0%, #16213e 100%)',
      display: 'flex',
      alignItems: 'center',
      justifyContent: 'center',
      color: 'white',
      fontWeight: 'bold',
      fontSize: config.fontSize,
      border: '2px solid #fff',
      boxShadow: '0 2px 8px rgba(0,0,0,0.08)'
    }}>
      {initials}
    </div>
  );
};

// ==================== MAIN COMPONENT ====================
const Staff_Scheduling = () => {
  // State declarations
  const [activeTab, setActiveTab] = useState('schedule');
  const [selectedDepartment, setSelectedDepartment] = useState('all');
  const [selectedDate, setSelectedDate] = useState(new Date());
  const [showModal, setShowModal] = useState(false);
  const [modalMode, setModalMode] = useState('add');
  const [selectedShift, setSelectedShift] = useState(null);
  const [showExportDropdown, setShowExportDropdown] = useState(false);
  const [searchQuery, setSearchQuery] = useState('');
  const [currentDate, setCurrentDate] = useState('');
  const [showDeleteConfirm, setShowDeleteConfirm] = useState(false);
  const [deleteItemId, setDeleteItemId] = useState(null);
  const [notification, setNotification] = useState({ show: false, message: '', type: '' });
  const [selectedEmployeeDetail, setSelectedEmployeeDetail] = useState(null);
  const [showEmployeeModal, setShowEmployeeModal] = useState(false);
  const [showCalculationDetails, setShowCalculationDetails] = useState(false);
  const [showScheduleFileModal, setShowScheduleFileModal] = useState(false);
  const [scheduleFileName, setScheduleFileName] = useState('');
  const [savedSchedules, setSavedSchedules] = useState([]);
  const [showPrintModal, setShowPrintModal] = useState(false);
  const [printSchedule, setPrintSchedule] = useState(null);
  const [selectedBulkIds, setSelectedBulkIds] = useState([]);
  const [overlapError, setOverlapError] = useState(null);
  
  // Schedule Warning State
  const [scheduleWarning, setScheduleWarning] = useState(null);
  
  // Saved Schedules Stats
  const [savedSchedulesStats, setSavedSchedulesStats] = useState({
    totalShifts: 0,
    totalSchedules: 0,
    totalHours: 0,
    totalCost: 0
  });
  
  // Completed Shifts
  const [completedShiftsHistory, setCompletedShiftsHistory] = useState([]);
  const [completedShiftsLoading, setCompletedShiftsLoading] = useState(false);
  
  // Employee Requests stats
  const [employeeRequestsStats, setEmployeeRequestsStats] = useState({
    total: 0,
    pending: 0,
    approved: 0,
    rejected: 0
  });
  
  const [employeeRequests, setEmployeeRequests] = useState([]);
  const [employeeRequestsLoading, setEmployeeRequestsLoading] = useState(false);
  const [selectedRequestFilter, setSelectedRequestFilter] = useState('all');
  const [selectedRequestType, setSelectedRequestType] = useState('all');
  const [showRequestDetailModal, setShowRequestDetailModal] = useState(false);
  const [selectedRequest, setSelectedRequest] = useState(null);
  const [adminNotes, setAdminNotes] = useState('');
  
  // Batch scheduling states
  const [showBatchModal, setShowBatchModal] = useState(false);
  const [selectedEmployees, setSelectedEmployees] = useState([]);
  const [selectAll, setSelectAll] = useState(false);
  const [batchFilters, setBatchFilters] = useState({
    department: 'all',
    employeeType: 'all',
    searchTerm: ''
  });
  const [batchScheduleData, setBatchScheduleData] = useState({
    date: '',
    startTime: '09:00',
    endTime: '17:00',
    shift_type: 'regular',
    placement: '',
    notes: ''
  });
  
  const [formData, setFormData] = useState({
    employeeId: '',
    date: '',
    startTime: '09:00',
    endTime: '17:00',
    shift_type: 'regular',
    placement: '',
    notes: ''
  });

  const API_URL = API_ORIGIN;
  const selectedDateStr = selectedDate.toISOString().split('T')[0];

  // ==================== REACT QUERY HOOKS ====================
  const { data: employeesData = [], isLoading: employeesLoading, refetch: refetchEmployees } = useEmployees({ 
    status: 'active',
    all: true,
    per_page: 1000
  });  
  const { data: shiftsData = [], isLoading: shiftsLoading, refetch: refetchShifts } = useShifts({ date: selectedDateStr });
  const { data: archivedShiftsData = [], isLoading: archivedLoading, refetch: refetchArchived } = useArchivedShifts();
  const { data: requestsData = [], isLoading: requestsLoading, refetch: refetchRequests } = useTimeOffRequests();
  const { data: statsData = {}, isLoading: statsLoading } = useShiftStats(selectedDateStr);
  const { data: employeeRequestsData = [], refetch: refetchEmployeeRequests } = useEmployeeRequests();

  // Mutations
  const createShiftMutation = useCreateShift();
  const updateShiftMutation = useUpdateShift();
  const archiveShiftMutation = useArchiveShift();
  const restoreShiftMutation = useRestoreShift();
  const bulkArchiveMutation = useBulkArchiveShifts();
  const bulkRestoreMutation = useBulkRestoreShifts();

  // Process employees data
  const employees = useMemo(() => {
    if (!employeesData || !Array.isArray(employeesData)) return [];
    return employeesData.map(emp => {
      const fullName = `${emp.first_name || ''} ${emp.last_name || ''}`.trim() || emp.name || 'Unknown';
      
      let profilePhotoUrl = null;
      if (emp.profile_photo_url) {
        profilePhotoUrl = emp.profile_photo_url.startsWith('http') 
          ? emp.profile_photo_url 
          : `${API_URL}${emp.profile_photo_url.startsWith('/') ? '' : '/storage/'}${emp.profile_photo_url}`;
      } else if (emp.profile_photo) {
        if (emp.profile_photo.startsWith('http')) {
          profilePhotoUrl = emp.profile_photo;
        } else {
          const cleanPath = emp.profile_photo.replace(/^storage\//, '');
          profilePhotoUrl = `${API_URL}/storage/${cleanPath}`;
        }
      }
      
      let hourlyRate = getEmployeeHourlyRate(emp);
      
      let salaryGrade = null;
      if (emp.position?.salary_grade?.grade_name) {
        salaryGrade = emp.position.salary_grade.grade_name;
      } else if (emp.salary_grade) {
        salaryGrade = emp.salary_grade;
      }
      
      return {
        ...emp,
        id: emp.id || emp.employee_id,
        employee_id: emp.employee_code || emp.employee_id || emp.id,
        employee_code: emp.employee_code || emp.employee_id || `EMP${String(emp.id).padStart(6, '0')}`,
        name: fullName,
        hourly_rate: hourlyRate,
        daily_rate: emp.daily_rate || (hourlyRate * 8),
        monthly_salary: emp.monthly_salary || emp.position?.salary_grade?.monthly_salary || 0,
        salary_grade: salaryGrade,
        employee_type: emp.employee_type || 'regular',
        status: emp.status || 'active',
        department: emp.department,
        position: emp.position,
        profile_photo_url: profilePhotoUrl
      };
    });
  }, [employeesData, API_URL]);

  // Process shifts data
  const shifts = useMemo(() => {
    if (!shiftsData || !Array.isArray(shiftsData)) return [];
    
    return shiftsData.map(shift => {
      const employeeData = employees.find(e => String(e.id) === String(shift.employee_id));
      const startTime = shift.start_time || shift.startTime;
      const endTime = shift.end_time || shift.endTime;
      const duration = shift.duration_hours || shift.duration || calculateDurationHours(startTime, endTime);
      const hourlyRate = employeeData ? getEmployeeHourlyRate(employeeData) : 0;
      const totalCost = calculateTotalEarnings(hourlyRate, duration);
      
      return {
        id: shift.id,
        employee_id: shift.employee_id,
        employeeId: shift.employee_id,
        employee: employeeData,
        date: shift.work_date || shift.date,
        start_time: startTime,
        end_time: endTime,
        startTime: startTime,
        endTime: endTime,
        shift_type: shift.shift_type || shift.type || 'regular',
        placement: shift.placement || '',
        notes: shift.notes || '',
        total_cost: totalCost,
        totalCost: totalCost,
        duration: duration,
        status: shift.status || 'scheduled',
        hourly_rate: hourlyRate
      };
    });
  }, [shiftsData, employees]);

  // Process archived shifts
  const archivedShifts = useMemo(() => {
    if (!archivedShiftsData || !Array.isArray(archivedShiftsData)) return [];
    return archivedShiftsData.map(shift => ({
      ...shift,
      employee: employees.find(e => String(e.id) === String(shift.employee_id))
    }));
  }, [archivedShiftsData, employees]);

  // Calculate labor costs
  const laborCosts = useMemo(() => {
    let regularHours = 0, oncallHours = 0, regularCost = 0, oncallCost = 0;
    
    shifts.forEach(shift => {
      const duration = shift.duration || calculateDurationHours(shift.start_time, shift.end_time);
      const cost = shift.total_cost || 0;
      
      if (shift.shift_type === 'regular') {
        regularHours += duration;
        regularCost += cost;
      } else {
        oncallHours += duration;
        oncallCost += cost;
      }
    });
    
    const totalHours = regularHours + oncallHours;
    const totalCostSum = regularCost + oncallCost;
    
    return {
      regularHours: Math.max(0, Number(regularHours.toFixed(2))),
      oncallHours: Math.max(0, Number(oncallHours.toFixed(2))),
      totalHours: Math.max(0, Number(totalHours.toFixed(2))),
      regularCost: Math.max(0, regularCost),
      oncallCost: Math.max(0, oncallCost),
      totalCost: Math.max(0, totalCostSum),
      avgRate: totalHours > 0 ? Math.max(0, totalCostSum / totalHours) : 0
    };
  }, [shifts]);

  const updateSavedSchedulesStats = useCallback((schedules) => {
    let totalShifts = 0;
    let totalSchedules = schedules.length;
    let totalHours = 0;
    let totalCost = 0;
    
    schedules.forEach(schedule => {
      if (schedule.shifts) {
        totalShifts += schedule.shifts.length;
        totalHours += schedule.totalHours || 0;
        totalCost += schedule.totalCost || 0;
      }
    });
    
    setSavedSchedulesStats({
      totalShifts,
      totalSchedules,
      totalHours,
      totalCost
    });
  }, []);

  const updateEmployeeRequestsStats = useCallback((requests) => {
    const pending = requests.filter(r => r.status === 'pending').length;
    const approved = requests.filter(r => r.status === 'approved').length;
    const rejected = requests.filter(r => r.status === 'rejected').length;
    
    setEmployeeRequestsStats({
      total: requests.length,
      pending,
      approved,
      rejected
    });
  }, []);

  const autoCompletePastShifts = useCallback(async () => {
    const today = new Date();
    today.setHours(0, 0, 0, 0);
    
    const updatedSchedules = [...savedSchedules];
    let hasChanges = false;
    const newlyCompletedShifts = [];
    
    for (let i = 0; i < updatedSchedules.length; i++) {
      const schedule = updatedSchedules[i];
      const scheduleDate = new Date(schedule.date);
      scheduleDate.setHours(0, 0, 0, 0);
      
      if (scheduleDate < today && !schedule.completed) {
        updatedSchedules[i] = {
          ...schedule,
          completed: true,
          completedAt: new Date().toISOString()
        };
        hasChanges = true;
        
        if (schedule.shifts && schedule.shifts.length > 0) {
          schedule.shifts.forEach(shift => {
            newlyCompletedShifts.push({
              ...shift,
              scheduleId: schedule.id,
              scheduleName: schedule.name,
              completedDate: schedule.date
            });
          });
        }
      }
    }
    
    if (hasChanges) {
      setSavedSchedules(updatedSchedules);
      localStorage.setItem('savedSchedules', JSON.stringify(updatedSchedules));
      updateSavedSchedulesStats(updatedSchedules);
      setCompletedShiftsHistory(prev => [...newlyCompletedShifts, ...prev]);
    }
  }, [savedSchedules, updateSavedSchedulesStats]);

  const loadCompletedShiftsFromSaved = useCallback(() => {
    const today = new Date();
    today.setHours(0, 0, 0, 0);
    
    const completed = [];
    
    savedSchedules.forEach(schedule => {
      const scheduleDate = new Date(schedule.date);
      scheduleDate.setHours(0, 0, 0, 0);
      
      if (scheduleDate < today || schedule.completed) {
        if (schedule.shifts && schedule.shifts.length > 0) {
          schedule.shifts.forEach(shift => {
            completed.push({
              ...shift,
              scheduleId: schedule.id,
              scheduleName: schedule.name,
              completedDate: schedule.date
            });
          });
        }
      }
    });
    
    setCompletedShiftsHistory(completed);
  }, [savedSchedules]);

  const isLoading = employeesLoading || (shiftsLoading && shifts.length === 0);
  const isDateEditable = isWithin15DayWindow(selectedDateStr);

  const showNotification = useCallback((message, type = 'success') => {
    setNotification({ show: true, message, type });
    setTimeout(() => setNotification({ show: false, message: '', type: '' }), 3000);
  }, []);

  const checkScheduleWarning = useCallback(async () => {
    try {
      const token = getAuthToken();
      if (!token) return;
      
      const targetDate = new Date();
      targetDate.setDate(targetDate.getDate() + 2);
      const dateToCheck = targetDate.toISOString().split('T')[0];
      
      const response = await fetch(`${API_BASE_URL}/schedules?date=${dateToCheck}`, {
        headers: { 'Authorization': `Bearer ${token}` }
      });
      const data = await response.json();
      
      const schedulesCount = data.success ? (data.data?.data?.length || data.data?.length || 0) : 0;
      const activeEmployees = employees.length;
      
      if (schedulesCount === 0) {
        setScheduleWarning({
          type: 'danger',
          title: 'No Schedule Created!',
          message: `⚠️ WARNING: No schedule has been created for ${dateToCheck} (2 days from now).`,
          date: dateToCheck
        });
      } else if (schedulesCount < activeEmployees) {
        const percentage = Math.round((schedulesCount / activeEmployees) * 100);
        setScheduleWarning({
          type: 'warning',
          title: 'Incomplete Schedule',
          message: `⚠️ Notice: Only ${schedulesCount} out of ${activeEmployees} employees (${percentage}%) have schedules for ${dateToCheck}.`,
          date: dateToCheck
        });
      } else {
        setScheduleWarning(null);
      }
    } catch (error) {
      console.error('Error checking schedule warning:', error);
    }
  }, [employees.length]);

  // ==================== EMPLOYEE REQUESTS HANDLERS ====================

  const loadEmployeeRequests = useCallback(async () => {
    setEmployeeRequestsLoading(true);
    try {
      const token = getAuthToken();
      if (!token) {
        setEmployeeRequestsLoading(false);
        return;
      }
      
      const params = new URLSearchParams();
      if (selectedRequestFilter !== 'all') params.append('status', selectedRequestFilter);
      if (selectedRequestType !== 'all') params.append('type', selectedRequestType);
      params.append('per_page', '100');
      
      const url = `${API_BASE_URL}/employee-requests${params.toString() ? `?${params.toString()}` : ''}`;
      
      const response = await fetch(url, {
        method: 'GET',
        headers: {
          'Authorization': `Bearer ${token}`,
          'Accept': 'application/json',
          'Content-Type': 'application/json'
        }
      });
      
      const data = await response.json();
      
      if (response.status === 401) {
        setEmployeeRequestsLoading(false);
        return;
      }
      
      if (data.success) {
        const requestsData = data.data?.data || data.data || [];
        
        const requestsWithEmployees = await Promise.all(requestsData.map(async (req) => {
          let employeeData = req.employee;
          
          if (!employeeData && req.employee_id) {
            try {
              const empResponse = await fetch(`${API_BASE_URL}/employees/${req.employee_id}`, {
                headers: { 'Authorization': `Bearer ${token}` }
              });
              const empData = await empResponse.json();
              if (empData.success) {
                employeeData = empData.data;
              }
            } catch (err) {
              console.error(`Failed to fetch employee ${req.employee_id}:`, err);
            }
          }
          
          let swapEmployeeData = req.swap_employee;
          if (req.type === 'swap' && !swapEmployeeData && req.swap_with_employee_id) {
            try {
              const swapResp = await fetch(`${API_BASE_URL}/employees/${req.swap_with_employee_id}`, {
                headers: { 'Authorization': `Bearer ${token}` }
              });
              const swapData = await swapResp.json();
              if (swapData.success) {
                swapEmployeeData = swapData.data;
              }
            } catch (err) {
              console.error(`Failed to fetch swap employee ${req.swap_with_employee_id}:`, err);
            }
          }
          
          return {
            ...req,
            employee: employeeData,
            swap_employee: swapEmployeeData,
            type_color: getRequestTypeInfo(req.type).color,
            employee_name: employeeData ? 
              `${employeeData.first_name || ''} ${employeeData.last_name || ''}`.trim() || employeeData.name : 
              'Unknown Employee',
            employee_code: employeeData?.employee_code || employeeData?.employee_id || 'N/A'
          };
        }));
        
        setEmployeeRequests(requestsWithEmployees);
        updateEmployeeRequestsStats(requestsWithEmployees);
      }
    } catch (error) {
      console.error('Error loading employee requests:', error);
    } finally {
      setEmployeeRequestsLoading(false);
    }
  }, [selectedRequestFilter, selectedRequestType, updateEmployeeRequestsStats]);

  const fetchEmployeeRequestsStats = useCallback(async () => {
    try {
      const token = getAuthToken();
      if (!token) return;
      
      const response = await fetch(`${API_BASE_URL}/employee-requests?per_page=1000`, {
        headers: { 'Authorization': `Bearer ${token}` }
      });
      const data = await response.json();
      
      if (data.success) {
        const requests = data.data?.data || data.data || [];
        updateEmployeeRequestsStats(requests);
        setEmployeeRequests(requests);
      }
    } catch (error) {
      console.error('Error fetching employee requests stats:', error);
    }
  }, [updateEmployeeRequestsStats]);

  const handleRequestAction = async (request, action) => {
    const status = action === 'approve' ? 'approved' : 'rejected';
    
    if (!adminNotes.trim() && status === 'rejected') {
      showNotification('Please provide a reason for rejection', 'error');
      return;
    }
    
    try {
      const token = getAuthToken();
      if (!token) {
        showNotification('Please login again', 'error');
        return;
      }
      
      const url = `${API_BASE_URL}/employee-requests/${request.id}/status`;
      
      const response = await fetch(url, {
        method: 'PUT',
        headers: {
          'Authorization': `Bearer ${token}`,
          'Accept': 'application/json',
          'Content-Type': 'application/json'
        },
        body: JSON.stringify({
          status: status,
          admin_notes: adminNotes.trim() || null
        })
      });
      
      const data = await response.json();
      
      if (response.status === 401) {
        showNotification('Session expired. Please login again.', 'error');
        return;
      }
      
      if (data.success) {
        showNotification(`Request ${status} successfully`, 'success');
        setShowRequestDetailModal(false);
        setAdminNotes('');
        setSelectedRequest(null);
        loadEmployeeRequests();
        fetchEmployeeRequestsStats();
        
        if (activeTab === 'requests') {
          refetchRequests();
        }
      } else {
        showNotification(data.message || 'Failed to update request', 'error');
      }
    } catch (error) {
      console.error('Error updating request:', error);
      showNotification(error.message || 'Failed to update request', 'error');
    }
  };

  const fetchCompletedShiftsHistory = useCallback(async () => {
    setCompletedShiftsLoading(true);
    try {
      loadCompletedShiftsFromSaved();
    } catch (error) {
      console.error('Error fetching completed shifts:', error);
    } finally {
      setCompletedShiftsLoading(false);
    }
  }, [loadCompletedShiftsFromSaved]);

  const navigatePreviousDay = useCallback(() => {
    const newDate = new Date(selectedDate);
    newDate.setDate(selectedDate.getDate() - 1);
    setSelectedDate(newDate);
  }, [selectedDate]);

  const navigateNextDay = useCallback(() => {
    const newDate = new Date(selectedDate);
    newDate.setDate(selectedDate.getDate() + 1);
    setSelectedDate(newDate);
  }, [selectedDate]);

  const goToToday = useCallback(() => {
    setSelectedDate(new Date());
  }, []);

  const refreshSchedule = useCallback(() => {
    refetchShifts();
    refetchEmployees();
    fetchEmployeeRequestsStats();
    checkScheduleWarning();
    autoCompletePastShifts();
    loadCompletedShiftsFromSaved();
    showNotification('Schedule refreshed', 'info');
  }, [refetchShifts, refetchEmployees, showNotification, fetchEmployeeRequestsStats, checkScheduleWarning, autoCompletePastShifts, loadCompletedShiftsFromSaved]);

  const openEditModal = useCallback((shift) => {
    setSelectedShift(shift);
    setFormData({
      employeeId: shift.employee_id || shift.employeeId,
      date: shift.date || shift.work_date || selectedDateStr,
      startTime: shift.start_time || shift.startTime,
      endTime: shift.end_time || shift.endTime,
      shift_type: shift.shift_type || shift.type || 'regular',
      placement: shift.placement || '',
      notes: shift.notes || ''
    });
    setModalMode('edit');
    setShowModal(true);
  }, [selectedDateStr]);

  // ==================== FIXED: CREATE SHIFT HANDLER ====================
  const handleCreateShift = async () => {
    console.log('📝 Creating shift with data:', {
      employeeId: formData.employeeId,
      date: formData.date,
      startTime: formData.startTime,
      endTime: formData.endTime,
      shift_type: formData.shift_type,
      placement: formData.placement,
      notes: formData.notes
    });

    // Validate all required fields
    const errors = [];
    
    if (!formData.employeeId) {
      errors.push('Please select an employee');
    }
    if (!formData.date) {
      errors.push('Please select a date');
    }
    if (!formData.startTime) {
      errors.push('Please select start time');
    }
    if (!formData.endTime) {
      errors.push('Please select end time');
    }
    
    // Validate time range
    if (formData.startTime && formData.endTime) {
      const startMinutes = timeToMinutes(formData.startTime);
      let endMinutes = timeToMinutes(formData.endTime);
      if (endMinutes < startMinutes) endMinutes += 24 * 60;
      
      if (endMinutes <= startMinutes) {
        errors.push('End time must be after start time');
      }
    }
    
    if (errors.length > 0) {
      const errorMessage = errors.join('\n');
      console.log('❌ Validation errors:', errors);
      showNotification(errorMessage, 'error');
      return;
    }
    
    // Check if date is within 15 days
    if (!isWithin15DayWindow(formData.date)) {
      showNotification('Cannot create shift for dates beyond 15 days from today', 'error');
      return;
    }
    
    // Check if employee already has a shift on this date
    const employeeId = parseInt(formData.employeeId);
    const hasExistingShift = shifts.some(s =>
      (s.employee_id === employeeId || s.employeeId === employeeId) &&
      (s.date === formData.date || s.work_date === formData.date)
    );

    if (hasExistingShift) {
      showNotification(`Employee already has a shift on ${formData.date}.`, 'error');
      return;
    }
    
    // Check for overlap (time conflict - same day, overlapping times)
    if (overlapError) {
      showNotification('Cannot create shift: ' + overlapError, 'error');
      return;
    }
    
    try {
      // Prepare payload
      const payload = {
        employee_id: parseInt(formData.employeeId),
        work_date: formData.date,
        start_time: formData.startTime,
        end_time: formData.endTime,
        shift_type: formData.shift_type || 'regular',
        placement: formData.placement?.trim() || '',
        notes: formData.notes?.trim() || ''
      };
      
      console.log('📤 Sending payload:', payload);
      
      const response = await createShiftMutation.mutateAsync(payload);
      console.log('✅ Shift created:', response);
      
      showNotification('Shift created successfully', 'success');
      setShowModal(false);
      
      // Reset form
      setFormData({
        employeeId: '',
        date: selectedDateStr,
        startTime: '09:00',
        endTime: '17:00',
        shift_type: 'regular',
        placement: '',
        notes: ''
      });
      
      // Refresh data
      refetchShifts();
      checkScheduleWarning();
      
    } catch (error) {
      console.error('❌ Create shift error:', error);
      
      if (error.response) {
        console.error('Error response data:', error.response.data);
        console.error('Error response status:', error.response.status);
        
        let errorMsg = error.response.data?.message || 'Failed to create shift.';
        
        // Check for duplicate key error
        if (errorMsg.includes('Duplicate entry') || errorMsg.includes('1062')) {
          errorMsg = 'This employee already has a shift on this date.';
        }
        
        showNotification(errorMsg, 'error');
      } else if (error.request) {
        console.error('No response received:', error.request);
        showNotification('No response from server. Please check your connection.', 'error');
      } else {
        console.error('Error setting up request:', error.message);
        showNotification('Failed to create shift: ' + error.message, 'error');
      }
    }
  };

  // ==================== FIXED: UPDATE SHIFT HANDLER ====================
  const handleUpdateShift = async () => {
    if (!selectedShift) return;
    
    // Validate
    const errors = [];
    if (!formData.employeeId) errors.push('Please select an employee');
    if (!formData.date) errors.push('Please select a date');
    if (!formData.startTime) errors.push('Please select start time');
    if (!formData.endTime) errors.push('Please select end time');
    
    if (errors.length > 0) {
      showNotification(errors.join('\n'), 'error');
      return;
    }
    
    if (!isWithin15DayWindow(formData.date)) {
      showNotification('Cannot update shift for dates beyond 15 days from today', 'error');
      return;
    }
    
    if (overlapError) {
      showNotification(overlapError, 'error');
      return;
    }
    
    try {
      const payload = {
        employee_id: parseInt(formData.employeeId),
        work_date: formData.date,
        start_time: formData.startTime,
        end_time: formData.endTime,
        shift_type: formData.shift_type || 'regular',
        placement: formData.placement?.trim() || '',
        notes: formData.notes?.trim() || ''
      };
      
      console.log('📤 Updating shift with payload:', payload);
      
      await updateShiftMutation.mutateAsync({ id: selectedShift.id, data: payload });
      showNotification('Shift updated successfully', 'success');
      setShowModal(false);
      setSelectedShift(null);
      refetchShifts();
    } catch (error) {
      console.error('❌ Update shift error:', error);
      const errorMsg = error.response?.data?.message || error.message || 'Failed to update shift';
      showNotification(errorMsg, 'error');
    }
  };

  const handleSaveShift = useCallback(() => {
    if (modalMode === 'add') {
      handleCreateShift();
    } else {
      handleUpdateShift();
    }
  }, [modalMode]);

  // ==================== FIXED: BATCH SCHEDULE HANDLER ====================
  const handleBatchSchedule = async () => {
    // Validate inputs
    if (selectedEmployees.length === 0) {
      showNotification('Please select at least one employee', 'error');
      return;
    }

    if (!batchScheduleData.date || !batchScheduleData.startTime || !batchScheduleData.endTime) {
      showNotification('Please fill in all required fields', 'error');
      return;
    }

    if (!isWithin15DayWindow(batchScheduleData.date)) {
      showNotification('Cannot schedule for dates beyond 15 days from today', 'error');
      return;
    }

    // Build a set of employee IDs that already have shifts on this date
    const employeesWithShifts = new Set();
    shifts.forEach(shift => {
      const shiftDate = shift.date || shift.work_date;
      if (shiftDate === batchScheduleData.date) {
        const empId = shift.employee_id || shift.employeeId;
        if (empId) employeesWithShifts.add(empId);
      }
    });

    console.log('📋 Employees with existing shifts on this date:', [...employeesWithShifts]);

    // Filter out employees who already have shifts
    const availableEmployees = selectedEmployees.filter(empId => {
      const hasShift = employeesWithShifts.has(empId);
      if (hasShift) {
        const employee = employees.find(e => e.id === empId);
        console.log(`⏭️ Skipping ${employee?.name || empId} - already has a shift on ${batchScheduleData.date}`);
      }
      return !hasShift;
    });

    // Check if any employees are available
    if (availableEmployees.length === 0) {
      const employeeNames = selectedEmployees.map(id => {
        const emp = employees.find(e => e.id === id);
        return emp?.name || id;
      }).join(', ');
      showNotification(`All selected employees (${employeeNames}) already have shifts on this date.`, 'warning');
      return;
    }

    // Process only available employees
    let successCount = 0;
    let failCount = 0;
    const errors = [];

    for (let i = 0; i < availableEmployees.length; i++) {
      const employeeId = availableEmployees[i];

      try {
        const payload = {
          employee_id: employeeId,
          work_date: batchScheduleData.date,
          start_time: batchScheduleData.startTime,
          end_time: batchScheduleData.endTime,
          shift_type: batchScheduleData.shift_type || 'regular',
          placement: batchScheduleData.placement?.trim() || '',
          notes: batchScheduleData.notes?.trim() || ''
        };

        console.log(`📤 Creating batch shift for employee ${employeeId}:`, payload);
        
        await createShiftMutation.mutateAsync(payload);
        successCount++;
      } catch (error) {
        failCount++;
        const errorMsg = error.response?.data?.message || error.message || 'Unknown error';
        
        // Check if it's a duplicate key error
        if (errorMsg.includes('Duplicate entry') || errorMsg.includes('1062')) {
          errors.push(`Employee ${employeeId}: Already has a shift on this date (skipped)`);
        } else {
          errors.push(`Employee ${employeeId}: ${errorMsg}`);
        }
        console.error(`❌ Failed to create shift for employee ${employeeId}:`, error);
      }
    }
    
    // Show summary message
    const skippedCount = selectedEmployees.length - availableEmployees.length;
    let message = `✅ Successfully created ${successCount} shifts`;
    if (skippedCount > 0) message += `, ⏭️ ${skippedCount} skipped (already scheduled)`;
    if (failCount > 0) message += `, ❌ ${failCount} failed`;
    
    if (successCount > 0) {
      showNotification(message, failCount > 0 ? 'warning' : 'success');
      refetchShifts();
      checkScheduleWarning();
      setShowBatchModal(false);
      setSelectedEmployees([]);
      setSelectAll(false);
    } else if (skippedCount > 0 && failCount === 0) {
      showNotification(`All ${skippedCount} selected employees already have shifts on this date.`, 'warning');
    } else {
      showNotification(`❌ Failed to create shifts. ${errors.join('; ')}`, 'error');
    }
  };

  useEffect(() => {
    if (activeTab === 'requests') {
      loadEmployeeRequests();
    }
    if (activeTab === 'history') {
      fetchCompletedShiftsHistory();
    }
  }, [activeTab, selectedRequestFilter, selectedRequestType, loadEmployeeRequests, fetchCompletedShiftsHistory]);

  const loadSavedSchedules = useCallback(() => {
    const saved = localStorage.getItem('savedSchedules');
    if (saved) {
      const parsed = JSON.parse(saved);
      setSavedSchedules(parsed);
      updateSavedSchedulesStats(parsed);
      autoCompletePastShifts();
    }
    fetchEmployeeRequestsStats();
    checkScheduleWarning();
  }, [updateSavedSchedulesStats, fetchEmployeeRequestsStats, checkScheduleWarning, autoCompletePastShifts]);

  const saveScheduleToHistory = () => {
    if (shifts.length === 0) {
      showNotification('No shifts to save', 'error');
      return;
    }
    
    const fileName = scheduleFileName.trim() || `Schedule_${selectedDateStr}`;
    
    const existingSchedule = savedSchedules.find(s => s.date === selectedDateStr);
    if (existingSchedule) {
      showNotification(`Schedule for ${selectedDateStr} already exists.`, 'error');
      return;
    }
    
    const scheduleData = {
      id: Date.now(),
      name: fileName,
      date: selectedDateStr,
      shifts: shifts.map(shift => ({
        ...shift,
        total_cost: shift.total_cost,
        duration: shift.duration
      })),
      laborCosts: laborCosts,
      createdAt: new Date().toISOString(),
      employeeCount: shifts.length,
      totalHours: laborCosts.totalHours,
      totalCost: laborCosts.totalCost,
      completed: false
    };
    
    const updatedSchedules = [scheduleData, ...savedSchedules];
    setSavedSchedules(updatedSchedules);
    localStorage.setItem('savedSchedules', JSON.stringify(updatedSchedules));
    updateSavedSchedulesStats(updatedSchedules);
    
    setShowScheduleFileModal(false);
    setScheduleFileName('');
    showNotification(`Schedule "${fileName}" saved successfully`, 'success');
    fetchEmployeeRequestsStats();
  };

  const archiveScheduleToArchive = async (schedule) => {
    if (!schedule || !schedule.shifts || schedule.shifts.length === 0) {
      showNotification('No shifts to archive', 'error');
      return;
    }

    const archivedSchedule = {
      ...schedule,
      archivedAt: new Date().toISOString(),
      isArchived: true
    };
    
    const existingArchived = JSON.parse(localStorage.getItem('archivedSchedules') || '[]');
    existingArchived.unshift(archivedSchedule);
    localStorage.setItem('archivedSchedules', JSON.stringify(existingArchived));
    
    const updatedSchedules = savedSchedules.filter(s => s.id !== schedule.id);
    setSavedSchedules(updatedSchedules);
    localStorage.setItem('savedSchedules', JSON.stringify(updatedSchedules));
    updateSavedSchedulesStats(updatedSchedules);
    
    showNotification(`Schedule "${schedule.name}" archived successfully!`, 'success');
    setActiveTab('archived');
  };

  const getArchivedSchedules = useCallback(() => {
    return JSON.parse(localStorage.getItem('archivedSchedules') || '[]');
  }, []);

  const restoreArchivedSchedule = (schedule) => {
    const archivedSchedulesList = JSON.parse(localStorage.getItem('archivedSchedules') || '[]');
    const updatedArchived = archivedSchedulesList.filter(s => s.id !== schedule.id);
    localStorage.setItem('archivedSchedules', JSON.stringify(updatedArchived));
    
    const restoredSchedule = {
      ...schedule,
      isArchived: false,
      restoredAt: new Date().toISOString()
    };
    const updatedSchedules = [restoredSchedule, ...savedSchedules];
    setSavedSchedules(updatedSchedules);
    localStorage.setItem('savedSchedules', JSON.stringify(updatedSchedules));
    updateSavedSchedulesStats(updatedSchedules);
    
    showNotification(`Schedule "${schedule.name}" restored successfully`, 'success');
    setActiveTab('schedule');
  };

  const viewScheduleDetail = (schedule) => {
    setPrintSchedule(schedule);
    setShowPrintModal(true);
  };

  const openSaveScheduleModal = () => {
    if (shifts.length === 0) {
      showNotification('No shifts to save. Please add shifts first.', 'error');
      return;
    }
    setScheduleFileName(`Schedule_${selectedDateStr}`);
    setShowScheduleFileModal(true);
  };

  const handleInputChange = (e) => {
    const { name, value } = e.target;
    setFormData(prev => ({ ...prev, [name]: value }));
  };

  const handleBatchInputChange = (e) => {
    const { name, value } = e.target;
    setBatchScheduleData(prev => ({ ...prev, [name]: value }));
  };

  const getShiftForEmployeeAndDate = (employeeId) => {
    if (!employeeId) return null;
    return shifts.find(s => String(s.employee_id || s.employeeId) === String(employeeId)) || null;
  };

  const calculateShiftTotal = () => {
    if (!formData.employeeId || !formData.startTime || !formData.endTime) return 0;
    const employee = employees.find(e => e.id === parseInt(formData.employeeId));
    if (!employee) return 0;
    const hourlyRate = getEmployeeHourlyRate(employee);
    const duration = calculateDurationHours(formData.startTime, formData.endTime);
    if (isNaN(duration) || duration <= 0) return 0;
    return calculateTotalEarnings(hourlyRate, duration);
  };

  const getShiftBreakdown = () => {
    if (!formData.employeeId || !formData.startTime || !formData.endTime) return null;
    const employee = employees.find(e => e.id === parseInt(formData.employeeId));
    if (!employee) return null;
    const duration = calculateDurationHours(formData.startTime, formData.endTime);
    if (isNaN(duration) || duration <= 0) return null;
    const hourlyRate = getEmployeeHourlyRate(employee);
    const salaryGrade = getEmployeeSalaryGrade(employee);
    return { duration, hourlyRate, salaryGrade, totalPay: hourlyRate * duration };
  };

  const checkOverlap = useCallback(() => {
    if (!formData.employeeId || !formData.date || !formData.startTime || !formData.endTime) return null;
    
    const existingShifts = shifts.filter(s => 
      (s.employee_id === parseInt(formData.employeeId) || s.employeeId === parseInt(formData.employeeId)) &&
      (s.date === formData.date || s.work_date === formData.date) && 
      s.id !== selectedShift?.id
    );
    
    const newStartMinutes = timeToMinutes(formData.startTime);
    let newEndMinutes = timeToMinutes(formData.endTime);
    if (newEndMinutes < newStartMinutes) newEndMinutes += 24 * 60;
    
    for (const shift of existingShifts) {
      const shiftStart = shift.start_time || shift.startTime;
      const shiftEnd = shift.end_time || shift.endTime;
      let shiftStartMinutes = timeToMinutes(shiftStart);
      let shiftEndMinutes = timeToMinutes(shiftEnd);
      
      if (shiftEndMinutes < shiftStartMinutes) shiftEndMinutes += 24 * 60;
      
      if ((newStartMinutes < shiftEndMinutes && newEndMinutes > shiftStartMinutes)) {
        return `Employee already has a shift from ${formatTo12Hour(shiftStart)} to ${formatTo12Hour(shiftEnd)} on this day`;
      }
    }
    
    return null;
  }, [formData.employeeId, formData.date, formData.startTime, formData.endTime, shifts, selectedShift?.id]);

  useEffect(() => {
    if (formData.employeeId && formData.date && formData.startTime && formData.endTime) {
      const error = checkOverlap();
      setOverlapError(error);
    } else {
      setOverlapError(null);
    }
  }, [formData.employeeId, formData.date, formData.startTime, formData.endTime, checkOverlap]);
  
  const getShiftBreakdownDisplay = () => {
    const breakdown = getShiftBreakdown();
    if (!breakdown) return null;
    return (
      <div className="sdf-calculation-details">
        <div className="sdf-calculation-header" onClick={() => setShowCalculationDetails(!showCalculationDetails)}>
          <InfoCircleOutlined /> View Calculation Details
          <span>{showCalculationDetails ? '▼' : '▶'}</span>
        </div>
        {showCalculationDetails && (
          <div className="sdf-calculation-content">
            <div><span>Salary Grade:</span><strong>{breakdown.salaryGrade}</strong></div>
            <div><span>Hourly Rate:</span><strong>{formatCurrency(breakdown.hourlyRate)}/hr</strong></div>
            <div><span>Duration:</span><strong>{breakdown.duration.toFixed(2)} hrs</strong></div>
            <div className="sdf-calculation-total"><span>Total:</span><strong>{formatCurrency(breakdown.totalPay)}</strong></div>
          </div>
        )}
      </div>
    );
  };

  const openBatchModal = () => {
    setSelectedEmployees([]);
    setSelectAll(false);
    setBatchFilters({ department: 'all', employeeType: 'all', searchTerm: '' });
    setBatchScheduleData({
      date: selectedDateStr,
      startTime: '09:00',
      endTime: '17:00',
      shift_type: 'regular',
      placement: '',
      notes: ''
    });
    setShowBatchModal(true);
  };

  const handleSelectEmployee = (employeeId) => {
    setSelectedEmployees(prev => 
      prev.includes(employeeId) 
        ? prev.filter(id => id !== employeeId)
        : [...prev, employeeId]
    );
  };

  const handleSelectAll = () => {
    if (selectAll) {
      setSelectedEmployees([]);
    } else {
      const availableEmployees = getFilteredEmployeesForBatch();
      setSelectedEmployees(availableEmployees.map(emp => emp.id));
    }
    setSelectAll(!selectAll);
  };

  // ==================== FIXED: getFilteredEmployeesForBatch ====================
  const getFilteredEmployeesForBatch = useCallback(() => {
    // Build a set of employee IDs that have shifts on the selected date
    const employeesWithShifts = new Set();
    shifts.forEach(shift => {
      const shiftDate = shift.date || shift.work_date;
      if (shiftDate === batchScheduleData.date) {
        const empId = shift.employee_id || shift.employeeId;
        if (empId) employeesWithShifts.add(empId);
      }
    });

    // Filter employees
    return employees.filter(emp => {
      // Check basic filters
      if (emp.status !== 'active') return false;
      if (batchFilters.department !== 'all' && emp.department?.name !== batchFilters.department) return false;
      if (batchFilters.employeeType !== 'all' && emp.employee_type !== batchFilters.employeeType) return false;
      if (batchFilters.searchTerm && !emp.name.toLowerCase().includes(batchFilters.searchTerm.toLowerCase())) return false;
      
      // Filter out employees who already have a shift on this date
      if (employeesWithShifts.has(emp.id)) return false;
      
      return true;
    });
  }, [employees, batchFilters, shifts, batchScheduleData.date]);

  // ==================== FIXED: filteredEmployeesForBatch using useMemo ====================
  const filteredEmployeesForBatch = useMemo(() => {
    const result = getFilteredEmployeesForBatch();
    console.log('🔍 filteredEmployeesForBatch computed:', {
      count: result.length,
      employees: result.map(e => ({ id: e.id, name: e.name }))
    });
    return result;
  }, [getFilteredEmployeesForBatch]);

  // ==================== FIXED: isAllSelected ====================
  const isAllSelected = useMemo(() => {
    return filteredEmployeesForBatch.length > 0 && 
      selectedEmployees.length === filteredEmployeesForBatch.length && 
      filteredEmployeesForBatch.every(emp => selectedEmployees.includes(emp.id));
  }, [filteredEmployeesForBatch, selectedEmployees]);

  const handleArchiveShift = async () => {
    if (!deleteItemId) return;
    try {
      await archiveShiftMutation.mutateAsync(deleteItemId);
      showNotification('Shift archived successfully', 'success');
      setShowDeleteConfirm(false);
      setDeleteItemId(null);
      refetchShifts();
      refetchArchived();
    } catch (error) {
      console.error('Archive shift error:', error);
      showNotification(error.response?.data?.message || 'Failed to archive shift', 'error');
    }
  };

  const confirmDelete = async () => {
    await handleArchiveShift();
  };

  const handleRestoreShift = async (shiftId) => {
    try {
      await restoreShiftMutation.mutateAsync(shiftId);
      showNotification('Shift restored successfully', 'success');
      refetchArchived();
      refetchShifts();
    } catch (error) {
      console.error('Restore shift error:', error);
      showNotification(error.response?.data?.message || 'Failed to restore shift', 'error');
    }
  };

  const toggleShiftSelection = (shiftId) => {
    setSelectedBulkIds(prev => prev.includes(shiftId) ? prev.filter(id => id !== shiftId) : [...prev, shiftId]);
  };

  const handleBulkArchive = async () => {
    if (selectedBulkIds.length === 0) {
      showNotification('Please select shifts to archive', 'error');
      return;
    }
    try {
      await bulkArchiveMutation.mutateAsync(selectedBulkIds);
      showNotification(`${selectedBulkIds.length} shifts archived successfully`, 'success');
      setSelectedBulkIds([]);
      refetchShifts();
      refetchArchived();
    } catch (error) {
      console.error('Bulk archive error:', error);
      showNotification(error.response?.data?.message || 'Failed to archive shifts', 'error');
    }
  };

  const handleBulkRestore = async () => {
    if (selectedBulkIds.length === 0) {
      showNotification('Please select shifts to restore', 'error');
      return;
    }
    try {
      await bulkRestoreMutation.mutateAsync(selectedBulkIds);
      showNotification(`${selectedBulkIds.length} shifts restored successfully`, 'success');
      setSelectedBulkIds([]);
      refetchArchived();
      refetchShifts();
    } catch (error) {
      console.error('Bulk restore error:', error);
      showNotification(error.response?.data?.message || 'Failed to restore shifts', 'error');
    }
  };

  const exportToExcel = () => {
    if (!shifts.length) {
      showNotification('No data to export', 'info');
      return;
    }
    
    let tableHTML = `<html><head><meta charset="utf-8"><title>Staff Schedule - ${selectedDateStr}</title><style>
      body{font-family: 'Segoe UI', Arial, sans-serif; padding: 20px}
      table{width:100%;border-collapse:collapse;margin-top:20px}
      th,td{border:1px solid #e5e7eb;padding:12px;text-align:left}
      th{background:#f8f9fa;font-weight:600}
      .total-row{background:#f8f9fa;font-weight:bold}
      .header{text-align:center;margin-bottom:30px}
    </style></head><body>
      <div class="header">
        <h2>Staff Schedule Report</h2>
        <p><strong>Date:</strong> ${selectedDate.toLocaleDateString('en-US', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' })}</p>
        <p><strong>Total Shifts:</strong> ${shifts.length} | <strong>Total Hours:</strong> ${laborCosts.totalHours.toFixed(1)} | <strong>Total Cost:</strong> ${formatCurrency(laborCosts.totalCost)}</p>
      </div>
      <table>
        <thead>
          <tr>
            <th>Employee Code</th><th>Employee</th><th>Position</th><th>Salary Grade</th><th>Type</th><th>Time In</th><th>Time Out</th><th>Placement</th><th>Hours</th><th>Total</th>
          </tr>
        </thead>
        <tbody>`;
    shifts.forEach(shift => {
      const duration = shift.duration || calculateDurationHours(shift.start_time, shift.end_time);
      const salaryGrade = getEmployeeSalaryGrade(shift.employee);
      tableHTML += `<tr>
        <td>${shift.employee?.employee_code || 'N/A'}</td>
        <td>${shift.employee?.name || 'Unknown'}</td>
        <td>${getPositionTitle(shift.employee?.position)}</td>
        <td>${salaryGrade}</td>
        <td>${shift.shift_type === 'regular' ? 'Regular' : 'On-Call'}</td>
        <td>${formatTo12Hour(shift.start_time)}</td>
        <td>${formatTo12Hour(shift.end_time)}</td>
        <td>${shift.placement || 'N/A'}</td>
        <td style="text-align:right">${duration.toFixed(2)} hrs</td>
        <td style="text-align:right">${formatCurrency(shift.total_cost)}</td>
      </tr>`;
    });
    tableHTML += `<tr class="total-row">
      <td colspan="8"><strong>Total</strong></td>
      <td style="text-align:right"><strong>${laborCosts.totalHours.toFixed(2)} hrs</strong></td>
      <td style="text-align:right"><strong>${formatCurrency(laborCosts.totalCost)}</strong></td>
    </tr>
    </tbody>
  </table>
  </body></html>`;
    
    const blob = new Blob([tableHTML], { type: 'application/vnd.ms-excel' });
    const link = document.createElement('a');
    link.href = URL.createObjectURL(blob);
    link.download = `staff_schedule_${selectedDateStr}.xls`;
    link.click();
    URL.revokeObjectURL(link.href);
    showNotification('Exported to Excel successfully', 'success');
  };

  const exportToPDF = () => {
    if (!shifts.length) {
      showNotification('No data to export', 'info');
      return;
    }
    const printWindow = window.open('', '_blank');
    if (!printWindow) {
      showNotification('Please allow pop-ups for this site', 'error');
      return;
    }
    printWindow.document.write(`
      <html><head><title>Staff Schedule Report - ${selectedDateStr}</title><style>
        body{font-family: 'Segoe UI', Arial, sans-serif; padding: 20px}
        .header{text-align:center;margin-bottom:30px}
        table{width:100%;border-collapse:collapse;margin-top:20px}
        th,td{border:1px solid #e5e7eb;padding:12px;text-align:left}
        th{background:#f8f9fa;font-weight:600}
        .total-row{background:#f8f9fa;font-weight:bold}
      </style></head><body>
        <div class="header">
          <h1>Staff Schedule Report</h1>
          <p><strong>Date:</strong> ${selectedDate.toLocaleDateString('en-US', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' })}</p>
          <p><strong>Total Shifts:</strong> ${shifts.length} | <strong>Total Hours:</strong> ${laborCosts.totalHours.toFixed(1)} | <strong>Total Cost:</strong> ${formatCurrency(laborCosts.totalCost)}</p>
        </div>
        <table>
          <thead>
            <tr>
              <th>Employee Code</th><th>Employee</th><th>Position</th><th>Salary Grade</th><th>Type</th><th>Time In</th><th>Time Out</th><th>Placement</th><th>Hours</th><th>Total</th>
            </tr>
          </thead>
          <tbody>
            ${shifts.map(shift => {
              const duration = shift.duration || calculateDurationHours(shift.start_time, shift.end_time);
              const salaryGrade = getEmployeeSalaryGrade(shift.employee);
              return `<tr>
                <td>${shift.employee?.employee_code || 'N/A'}</td>
                <td>${shift.employee?.name || 'Unknown'}</td>
                <td>${getPositionTitle(shift.employee?.position)}</td>
                <td>${salaryGrade}</td>
                <td>${shift.shift_type === 'regular' ? 'Regular' : 'On-Call'}</td>
                <td>${formatTo12Hour(shift.start_time)}</td>
                <td>${formatTo12Hour(shift.end_time)}</td>
                <td>${shift.placement || 'N/A'}</td>
                <td style="text-align:right">${duration.toFixed(2)} hrs</td>
                <td style="text-align:right">${formatCurrency(shift.total_cost)}</td>
              </tr>`;
            }).join('')}
            <tr class="total-row">
              <td colspan="8"><strong>Total</strong></td>
              <td style="text-align:right"><strong>${laborCosts.totalHours.toFixed(2)} hrs</strong></td>
              <td style="text-align:right"><strong>${formatCurrency(laborCosts.totalCost)}</strong></td>
            </tr>
          </tbody>
        </table>
        <script>window.onload = function() { window.print(); window.close(); };</script>
      </body></html>
    `);
    printWindow.document.close();
  };

  const handlePrint = () => {
    const printWindow = window.open('', '_blank');
    if (!printWindow) return;
    
    printWindow.document.write(`
      <html><head><title>Schedule Report</title><style>
        body{font-family: 'Segoe UI', Arial, sans-serif; padding: 20px}
        .header{text-align:center;margin-bottom:30px}
        table{width:100%;border-collapse:collapse;margin-top:20px}
        th,td{border:1px solid #e5e7eb;padding:10px;text-align:left}
        th{background:#f8f9fa;font-weight:600}
        .total-row{background:#f8f9fa;font-weight:bold}
      </style></head><body>
        <div class="header">
          <h1>${printSchedule?.name || 'Schedule Report'}</h1>
          <p>Date: ${printSchedule?.date || selectedDateStr}</p>
          <p>Total Shifts: ${printSchedule?.employeeCount || shifts.length} | Total Hours: ${(printSchedule?.totalHours || laborCosts.totalHours).toFixed(1)} | Total Cost: ${formatCurrency(printSchedule?.totalCost || laborCosts.totalCost)}</p>
        </div>
        <table>
          <thead>
            <tr>
              <th>Employee Code</th><th>Employee</th><th>Salary Grade</th><th>Time In</th><th>Time Out</th><th>Placement</th><th>Hours</th><th>Cost</th>
            </tr>
          </thead>
          <tbody>
            ${(printSchedule?.shifts || shifts).map(shift => {
              const salaryGrade = getEmployeeSalaryGrade(shift.employee);
              const duration = shift.duration || calculateDurationHours(shift.start_time, shift.end_time);
              return `<tr>
                <td>${shift.employee?.employee_code || 'N/A'}</td>
                <td>${shift.employee?.name || 'Unknown'}</td>
                <td>${salaryGrade}</td>
                <td>${formatTo12Hour(shift.start_time)}</td>
                <td>${formatTo12Hour(shift.end_time)}</td>
                <td>${shift.placement || 'N/A'}</td>
                <td style="text-align:right">${duration.toFixed(1)}h</td>
                <td style="text-align:right">${formatCurrency(shift.total_cost)}</td>
              </tr>`;
            }).join('')}
          </tbody>
          <tfoot>
            <tr class="total-row">
              <td colspan="5"><strong>Total</strong></td>
              <td style="text-align:right"><strong>${(printSchedule?.totalHours || laborCosts.totalHours).toFixed(1)} hrs</strong></td>
              <td style="text-align:right"><strong>${formatCurrency(printSchedule?.totalCost || laborCosts.totalCost)}</strong></td>
            </tr>
          </tfoot>
        </table>
        <script>window.onload = function() { window.print(); setTimeout(function() { window.close(); }, 500); };</script>
      </body></html>
    `);
    printWindow.document.close();
  };

  useEffect(() => {
    setCurrentDate(new Date().toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' }));
    loadSavedSchedules();
    fetchEmployeeRequestsStats();
    checkScheduleWarning();
    fetchCompletedShiftsHistory();
    autoCompletePastShifts();
    const interval = setInterval(() => {
      autoCompletePastShifts();
      loadCompletedShiftsFromSaved();
    }, 3600000);
    
    return () => clearInterval(interval);
  }, []);

  useEffect(() => {
    if (selectedDate) {
      refetchShifts();
    }
  }, [selectedDate, refetchShifts]);

  const statistics = [
    { 
      label: 'Schedule Created', 
      value: savedSchedulesStats.totalSchedules, 
      icon: <SaveOutlined />, 
      trend: `${savedSchedulesStats.totalShifts} total shifts saved` 
    },
    { 
      label: 'Staff Scheduled', 
      value: new Set(shifts.map(s => s.employee_id)).size, 
      icon: <TeamOutlined />, 
      trend: `${employees.filter(e => e.status === 'active').length} active` 
    },
    { 
      label: 'Employee Requests', 
      value: employeeRequestsStats.total, 
      icon: <FormOutlined />, 
      trend: `${employeeRequestsStats.pending} pending approval` 
    },
    { 
      label: 'Total Hours', 
      value: laborCosts.totalHours.toFixed(1), 
      icon: <ClockCircleOutlined />, 
      trend: `For ${selectedDateStr}` 
    },
  ];

  const archivedSchedulesList = getArchivedSchedules();

  // ==================== RENDER ====================
  return (
    <div className="sdf-scheduling-container">
      {notification.show && (
        <div className={`sdf-notification sdf-notification-${notification.type}`}>
          {notification.type === 'success' && <CheckCircleOutlined />}
          {notification.type === 'error' && <WarningOutlined />}
          {notification.type === 'info' && <InfoCircleOutlined />}
          <span>{notification.message}</span>
        </div>
      )}

      {scheduleWarning && activeTab === 'schedule' && (
        <div className={`sdf-warning-banner sdf-warning-${scheduleWarning.type}`}>
          <AlertOutlined style={{ fontSize: '20px', marginRight: '12px' }} />
          <div className="sdf-warning-content">
            <strong>{scheduleWarning.title}</strong>
            <span>{scheduleWarning.message}</span>
          </div>
          <button className="sdf-warning-close" onClick={() => setScheduleWarning(null)}>
            <CloseOutlined />
          </button>
        </div>
      )}

      <header className="sdf-header">
        <div className="sdf-header-left">
          <div className="sdf-logo">
            <div className="sdf-logo-icon"><ScheduleOutlined /></div>
            <div className="sdf-logo-text">
              <h1>Staff Scheduling System</h1>
              <span>Enterprise Schedule Management</span>
            </div>
          </div>
          <nav className="sdf-nav">
            <button className={`sdf-nav-item ${activeTab === 'schedule' ? 'active' : ''}`} onClick={() => setActiveTab('schedule')}><TableOutlined /> Schedule</button>
            <button className={`sdf-nav-item ${activeTab === 'history' ? 'active' : ''}`} onClick={() => { setActiveTab('history'); fetchCompletedShiftsHistory(); }}><HistoryOutlined /> History</button>
            <button className={`sdf-nav-item ${activeTab === 'archived' ? 'active' : ''}`} onClick={() => { setActiveTab('archived'); }}><InboxOutlined /> Archived</button>
            <button className={`sdf-nav-item ${activeTab === 'requests' ? 'active' : ''}`} onClick={() => setActiveTab('requests')}><ClockCircleOutlined /> Requests</button>
            <button className={`sdf-nav-item ${activeTab === 'employees' ? 'active' : ''}`} onClick={() => setActiveTab('employees')}><TeamOutlined /> Employees</button>
            <button className={`sdf-nav-item ${activeTab === 'costs' ? 'active' : ''}`} onClick={() => setActiveTab('costs')}><DollarOutlined /> Costs</button>
          </nav>
        </div>
        <div className="sdf-header-right">
          <div className="sdf-date"><CalendarOutlined /><span>{currentDate}</span></div>
        </div>
      </header>

      <main className="sdf-main">
        {activeTab === 'schedule' && (
          <>
            <div className="sdf-dashboard-compact">
              <div className="sdf-stats-row">
                {isLoading ? (
                  [...Array(4)].map((_, i) => <SkeletonStat key={i} />)
                ) : (
                  statistics.map((stat, index) => (
                    <div key={index} className="sdf-stat-compact">
                      <div className="sdf-stat-icon-small">{stat.icon}</div>
                      <div className="sdf-stat-info">
                        <span className="sdf-stat-label-small">{stat.label}</span>
                        <div className="sdf-stat-value-row">
                          <strong>{stat.value}</strong>
                          <span className="sdf-stat-trend-small">{stat.trend}</span>
                        </div>
                      </div>
                    </div>
                  ))
                )}
              </div>
              <div className="sdf-labor-compact">
                {isLoading ? (
                  <SkeletonLaborCompact />
                ) : (
                  <>
                    <div className="sdf-labor-main">
                      <div className="sdf-labor-icon"><DollarOutlined /></div>
                      <div className="sdf-labor-details">
                        <span className="sdf-labor-label">Total Labor Cost</span>
                        <span className="sdf-labor-amount">{formatCurrency(laborCosts.totalCost)}</span>
                        <span className="sdf-labor-period">{selectedDateStr}</span>
                      </div>
                    </div>
                    <div className="sdf-labor-divider"></div>
                    <div className="sdf-labor-breakdown-compact">
                      <div><span>Regular</span><strong>{formatCurrency(laborCosts.regularCost)}</strong><small>{laborCosts.regularHours.toFixed(1)}h</small></div>
                      <div><span>On-Call</span><strong>{formatCurrency(laborCosts.oncallCost)}</strong><small>{laborCosts.oncallHours.toFixed(1)}h</small></div>
                    </div>
                  </>
                )}
              </div>
            </div>

            <div className="sdf-action-bar">
              <div className="sdf-action-left">
                <div className="sdf-action-badge"><ScheduleOutlined /> <strong>{shifts.length}</strong> Shifts</div>
                <div className="sdf-action-badge"><TeamOutlined /> <strong>{new Set(shifts.map(s => s.employee_id)).size}</strong> Staff</div>
                <div className="sdf-action-badge"><ClockCircleOutlined /> <strong>{laborCosts.totalHours.toFixed(1)}</strong> Hours</div>
                <div className="sdf-action-badge"><SaveOutlined /> <strong>{savedSchedulesStats.totalSchedules}</strong> Schedule Created</div>
              </div>
              <div className="sdf-action-right">
                <div className="sdf-export-dropdown">
                  <button className="sdf-btn sdf-btn-outline" onClick={() => setShowExportDropdown(!showExportDropdown)}>
                    <><ExportOutlined /> Export <DownOutlined /></>
                  </button>
                  {showExportDropdown && (
                    <div className="sdf-export-menu">
                      <div className="sdf-export-item" onClick={exportToExcel}><FileExcelOutlined /> Excel Format</div>
                      <div className="sdf-export-item" onClick={exportToPDF}><FilePdfOutlined /> PDF Document</div>
                    </div>
                  )}
                </div>
                {selectedBulkIds.length > 0 && (
                  <button className="sdf-btn sdf-btn-warning" onClick={handleBulkArchive}>
                    <DeleteOutlined /> Archive ({selectedBulkIds.length})
                  </button>
                )}
                <button className="sdf-btn sdf-btn-success" onClick={openSaveScheduleModal} disabled={shifts.length === 0}>
                  <SaveOutlined /> Save Schedule
                </button>
                <button className="sdf-btn sdf-btn-primary" onClick={openBatchModal} disabled={!isDateEditable || employees.length === 0}>
                  <ThunderboltOutlined /> Create Schedule
                </button>
              </div>
            </div>

            <div className="sdf-control-bar">
              <div className="sdf-date-navigator">
                <button className="sdf-date-nav-btn" onClick={navigatePreviousDay}><LeftOutlined /></button>
                <div className="sdf-date-display">
                  <div className="sdf-date-display-day">{selectedDate.toLocaleDateString('en-US', { weekday: 'long' })}</div>
                  <div className="sdf-date-display-date">{selectedDate.toLocaleDateString('en-US', { month: 'long', day: 'numeric', year: 'numeric' })}</div>
                  {!isDateEditable && <div className="sdf-date-warning">(Not Editable - Beyond 15 days)</div>}
                </div>
                <button className="sdf-date-nav-btn" onClick={navigateNextDay}><RightOutlined /></button>
                <button className="sdf-today-btn" onClick={goToToday}><CalendarFilled /> Today</button>
                <button className="sdf-refresh-btn" onClick={refreshSchedule}><ReloadOutlined /> Refresh</button>
              </div>
              <div className="sdf-control-group">
                <div className="sdf-search-box">
                  <SearchOutlined />
                  <input type="text" placeholder="Search employee by name, code, or ID..." value={searchQuery} onChange={(e) => setSearchQuery(e.target.value)} />
                </div>
                <select className="sdf-filter-select" value={selectedDepartment} onChange={(e) => setSelectedDepartment(e.target.value)}>
                  <option value="all">All Departments</option>
                  {Array.from(new Set(employees.map(e => e.department?.name))).filter(d => d && d !== 'N/A').map(dept => (
                    <option key={dept} value={dept}>{dept}</option>
                  ))}
                </select>
              </div>
            </div>
          </>
        )}

        {!isLoading && activeTab === 'schedule' && (
          <div className="sdf-schedule-table-container">
            {employees.length === 0 ? (
              <div className="sdf-empty-state">
                <TeamOutlined style={{ fontSize: '64px', color: '#ccc' }} />
                <h3>No Employees Found</h3>
                <p>Please add employees to start scheduling shifts</p>
              </div>
            ) : (
              <div className="sdf-table-wrapper">
                <table className="sdf-schedule-table">
                  <thead>
                    <tr>
                      <th style={{ width: '40px' }}>
                        <input 
                          type="checkbox" 
                          onChange={(e) => setSelectedBulkIds(e.target.checked ? shifts.map(s => s.id) : [])} 
                          checked={selectedBulkIds.length === shifts.length && shifts.length > 0} 
                        />
                      </th>
                      <th>Employee</th>
                      <th>Employee Code</th>
                      <th>Position</th>
                      <th>Salary Grade</th>
                      <th>Type</th>
                      <th>Shift Schedule</th>
                      <th>Hours</th>
                      <th>Total Cost</th>
                      <th>Actions</th>
                    </tr>
                  </thead>
                  <tbody>
                    {employees
                      .filter(e => selectedDepartment === 'all' || e.department?.name === selectedDepartment)
                      .filter(e => 
                        e.name.toLowerCase().includes(searchQuery.toLowerCase()) || 
                        (e.employee_code || '').toLowerCase().includes(searchQuery.toLowerCase()) ||
                        (e.employee_id || '').toLowerCase().includes(searchQuery.toLowerCase())
                      )
                      .sort((a, b) => a.name.localeCompare(b.name))
                      .map(emp => {
                        const shift = shifts.find(s => 
                          String(s.employee_id || s.employeeId) === String(emp.id)
                        );
                        
                        const duration = shift ? Math.max(0, shift.duration || calculateDurationHours(shift.start_time, shift.end_time)) : 0;
                        const totalCost = shift ? Math.max(0, shift.total_cost || 0) : 0;
                        const salaryGrade = getEmployeeSalaryGrade(emp);
                        const hourlyRate = getEmployeeHourlyRate(emp);
                        
                        return (
                          <tr key={emp.id}>
                            <td>
                              <input 
                                type="checkbox" 
                                onChange={() => shift && toggleShiftSelection(shift.id)} 
                                checked={shift ? selectedBulkIds.includes(shift.id) : false} 
                                disabled={!shift} 
                              />
                            </td>
                            <td>
                              <div className="sdf-employee-cell">
                                <EmployeeAvatar employee={emp} size="small" />
                                <div>
                                  <div className="sdf-employee-name">{emp.name}</div>
                                  <div className="sdf-employee-id">{emp.employee_code || emp.employee_id || 'N/A'}</div>
                                </div>
                              </div>
                            </td>
                            <td><span className="sdf-employee-code">{emp.employee_code || emp.employee_id || 'N/A'}</span></td>
                            <td>{getPositionTitle(emp.position)}</td>
                            <td><span className="sdf-salary-grade-badge">{salaryGrade}</span></td>
                            <td><span className={`sdf-type-badge ${emp.employee_type}`}>{emp.employee_type === 'regular' ? 'Regular' : 'On-Call'}</span></td>
                            <td>
                              {shift ? (
                                <div className={`sdf-shift-block ${shift.shift_type || 'regular'}`} onClick={() => { 
                                  setSelectedShift(shift); 
                                  setModalMode('view'); 
                                  setShowModal(true); 
                                }}>
                                  <div className="sdf-shift-time"><ClockCircleOutlined /> {formatTimeRange(shift.start_time, shift.end_time)}</div>
                                  <div className="sdf-shift-placement"><EnvironmentFilled /> {shift.placement || 'Not Assigned'}</div>
                                  <div className="sdf-shift-duration">{duration.toFixed(1)} hours</div>
                                  <div className="sdf-shift-rate">{formatCurrency(hourlyRate)}/hr</div>
                                </div>
                              ) : (
                                <button 
                                  className="sdf-add-shift-btn" 
                                  onClick={() => { 
                                    setFormData({ 
                                      employeeId: emp.id, 
                                      date: selectedDateStr,
                                      startTime: '09:00', 
                                      endTime: '17:00', 
                                      shift_type: 'regular', 
                                      placement: '', 
                                      notes: '' 
                                    }); 
                                    setModalMode('add'); 
                                    setShowModal(true); 
                                  }} 
                                  disabled={!isDateEditable}
                                >
                                  <PlusOutlined /> Add Shift
                                </button>
                              )}
                            </td>
                            <td><span className="sdf-hours-badge">{duration.toFixed(1)} hrs</span></td>
                            <td className="sdf-cost-cell">{formatCurrency(totalCost)}</td>
                            <td>
                              <button 
                                className="sdf-action-icon" 
                                onClick={() => { setSelectedEmployeeDetail(emp); setShowEmployeeModal(true); }} 
                                title="View Employee Profile"
                              >
                                <EyeOutlined />
                              </button>
                              {shift && (
                                <>
                                  <button 
                                    className="sdf-action-icon" 
                                    onClick={() => openEditModal(shift)} 
                                    disabled={!isDateEditable} 
                                    title="Edit Shift"
                                  >
                                    <EditOutlined />
                                  </button>
                                  <button 
                                    className="sdf-action-icon" 
                                    onClick={() => { setDeleteItemId(shift.id); setShowDeleteConfirm(true); }} 
                                    disabled={!isDateEditable} 
                                    title="Archive Shift"
                                  >
                                    <DeleteOutlined />
                                  </button>
                                </>
                              )}
                            </td>
                          </tr>
                        );
                      })}
                  </tbody>
                </table>
              </div>
            )}
          </div>
        )}

        {!isLoading && activeTab === 'history' && (
          <div className="sdf-history-container">
            <div className="sdf-history-header">
              <h2><HistoryOutlined /> Schedule History</h2>
              <p>View all saved schedules and completed shifts</p>
            </div>
            
            <div className="sdf-stats-overview-card">
              <div className="sdf-stats-overview-header">
                <div className="sdf-stats-overview-icon">
                  <SaveOutlined />
                </div>
                <div className="sdf-stats-overview-content">
                  <span className="sdf-stats-overview-label">Total Schedules</span>
                  <div className="sdf-stats-overview-numbers">
                    <span className="sdf-stats-overview-main">{savedSchedulesStats.totalSchedules}</span>
                    <span className="sdf-stats-overview-sub">
                      {savedSchedulesStats.totalShifts} shifts
                    </span>
                  </div>
                </div>
                <button 
                  className="sdf-stats-overview-refresh" 
                  onClick={loadSavedSchedules}
                  title="Refresh"
                >
                  <ReloadOutlined />
                </button>
              </div>
            </div>
            
            {savedSchedules.length > 0 ? (
              <div className="sdf-saved-schedules-list">
                <h3>Saved Schedules</h3>
                {savedSchedules.map(schedule => {
                  const scheduleDate = new Date(schedule.date);
                  const today = new Date();
                  today.setHours(0, 0, 0, 0);
                  const isPast = scheduleDate < today;
                  
                  return (
                    <div key={schedule.id} className={`sdf-saved-schedule-item ${isPast ? 'completed' : ''}`}>
                      <div className="sdf-schedule-info">
                        <FileTextOutlined className="sdf-schedule-icon" />
                        <div>
                          <div className="sdf-schedule-name">{schedule.name}</div>
                          <div className="sdf-schedule-date">{schedule.date} • {schedule.employeeCount} shifts • {schedule.totalHours.toFixed(1)} hrs • {formatCurrency(schedule.totalCost)}</div>
                          {isPast && (
                            <div className="sdf-schedule-completed-badge">
                              <CheckCircleOutlined /> Completed
                            </div>
                          )}
                        </div>
                      </div>
                      <div className="sdf-schedule-actions">
                        <button className="sdf-btn sdf-btn-sm" onClick={() => viewScheduleDetail(schedule)}><EyeOutlined /> View</button>
                        <button className="sdf-btn sdf-btn-sm sdf-btn-warning" onClick={() => archiveScheduleToArchive(schedule)}><InboxOutlined /> Archive</button>
                      </div>
                    </div>
                  );
                })}
              </div>
            ) : (
              <div className="sdf-empty-state">
                <HistoryOutlined /><h3>No Saved Schedules</h3><p>Save schedules from the Schedule tab to see them here</p>
              </div>
            )}
            
            <div className="sdf-completed-shifts-section">
              <div className="sdf-section-header">
                <h3><CheckCircleOutlined /> Completed Shifts (History)</h3>
                <span className="sdf-completed-count">{completedShiftsHistory.length} records</span>
              </div>
              {completedShiftsLoading ? (
                <div className="sdf-completed-shifts-table-wrapper">
                  <div className="skeleton-table-header">
                    {[...Array(12)].map((_, i) => <div key={i} className="skeleton-table-header-cell"></div>)}
                  </div>
                  <div className="skeleton-table-body">
                    {[...Array(5)].map((_, i) => (
                      <div key={i} className="skeleton-row">
                        <div className="skeleton-text small"></div>
                        <div className="skeleton-text"></div>
                        <div className="skeleton-text"></div>
                      </div>
                    ))}
                  </div>
                </div>
              ) : completedShiftsHistory.length > 0 ? (
                <div className="sdf-completed-shifts-table-wrapper">
                  <table className="sdf-completed-shifts-table">
                    <thead>
                      <tr>
                        <th>Schedule Name</th>
                        <th>Employee Code</th>
                        <th>Employee</th>
                        <th>Position</th>
                        <th>Salary Grade</th>
                        <th>Type</th>
                        <th>Date</th>
                        <th>Time In</th>
                        <th>Time Out</th>
                        <th>Placement</th>
                        <th>Duration</th>
                        <th>Total Cost</th>
                      </tr>
                    </thead>
                    <tbody>
                      {completedShiftsHistory.map((shift, idx) => {
                        const duration = shift.duration || calculateDurationHours(shift.start_time, shift.end_time);
                        const salaryGrade = getEmployeeSalaryGrade(shift.employee);
                        return (
                          <tr key={idx} className="sdf-completed-row">
                            <td className="sdf-schedule-name-cell">{shift.scheduleName || 'N/A'}</td>
                            <td><span className="sdf-employee-code">{shift.employee?.employee_code || 'N/A'}</span></td>
                            <td>
                              <div className="sdf-employee-cell-compact">
                                <EmployeeAvatar employee={shift.employee} size="small" />
                                <div>
                                  <div className="sdf-employee-name">{shift.employee?.name || 'Unknown'}</div>
                                </div>
                              </div>
                            </td>
                            <td>{getPositionTitle(shift.employee?.position)}</td>
                            <td><span className="sdf-salary-grade-badge">{salaryGrade}</span></td>
                            <td><span className={`sdf-type-badge ${shift.shift_type}`}>{shift.shift_type === 'regular' ? 'Regular' : 'On-Call'}</span></td>
                            <td className="sdf-date-cell">{formatDate(shift.date)}</td>
                            <td className="sdf-time-cell">{formatTo12Hour(shift.start_time)}</td>
                            <td className="sdf-time-cell">{formatTo12Hour(shift.end_time)}</td>
                            <td>{shift.placement || '—'}</td>
                            <td className="sdf-duration-cell">{duration.toFixed(1)} hrs</td>
                            <td className="sdf-cost-cell">{formatCurrency(shift.total_cost)}</td>
                          </tr>
                        );
                      })}
                    </tbody>
                    <tfoot>
                      <tr className="sdf-completed-footer">
                        <td colSpan="10"><strong>Total</strong></td>
                        <td><strong>{completedShiftsHistory.reduce((sum, shift) => sum + (shift.duration || calculateDurationHours(shift.start_time, shift.end_time)), 0).toFixed(1)} hrs</strong></td>
                        <td><strong>{formatCurrency(completedShiftsHistory.reduce((sum, shift) => sum + (shift.total_cost || 0), 0))}</strong></td>
                      </tr>
                    </tfoot>
                  </table>
                </div>
              ) : (
                <div className="sdf-empty-state-completed">
                  <CheckCircleOutlined style={{ fontSize: '48px', color: '#ccc' }} />
                  <h3>No Completed Shifts</h3>
                  <p>Completed shifts will appear here automatically when schedule dates have passed</p>
                </div>
              )}
            </div>
          </div>
        )}

        {!isLoading && activeTab === 'archived' && (
          <div className="sdf-archived-container">
            <div className="sdf-archived-header">
              <h2><InboxOutlined /> Archived Schedules</h2>
              <p>View and restore archived schedule files</p>
            </div>
            <div className="sdf-archived-table-wrapper">
              {archivedSchedulesList.length > 0 ? (
                <div className="sdf-archived-schedules-list">
                  {archivedSchedulesList.map(schedule => (
                    <div key={schedule.id} className="sdf-archived-schedule-item">
                      <div className="sdf-schedule-info">
                        <FileTextOutlined className="sdf-schedule-icon" />
                        <div>
                          <div className="sdf-schedule-name">{schedule.name}</div>
                          <div className="sdf-schedule-date">{schedule.date} • {schedule.employeeCount} shifts • {schedule.totalHours.toFixed(1)} hrs • {formatCurrency(schedule.totalCost)}</div>
                          <div className="sdf-archived-date">Archived: {formatDate(schedule.archivedAt)}</div>
                        </div>
                      </div>
                      <div className="sdf-schedule-actions">
                        <button className="sdf-btn sdf-btn-sm" onClick={() => viewScheduleDetail(schedule)}><EyeOutlined /> View</button>
                        <button className="sdf-btn sdf-btn-sm sdf-btn-success" onClick={() => restoreArchivedSchedule(schedule)}><RollbackOutlined /> Restore</button>
                      </div>
                    </div>
                  ))}
                </div>
              ) : (
                <div className="sdf-empty-state"><InboxOutlined /><h3>No Archived Schedules</h3><p>Archived schedules will appear here</p></div>
              )}
            </div>
          </div>
        )}

        {!isLoading && activeTab === 'requests' && (
          <div className="sdf-requests-container">
            <div className="sdf-requests-header">
              <h2><ClockCircleOutlined /> Employee Requests</h2>
              <p>Review and manage employee time-off, leave, and shift swap requests from mobile app</p>
            </div>

            <div className="sdf-request-stats-summary">
              <div className="sdf-request-stat-card pending">
                <div className="sdf-request-stat-value">{employeeRequestsStats.pending}</div>
                <div className="sdf-request-stat-label">Pending</div>
              </div>
              <div className="sdf-request-stat-card approved">
                <div className="sdf-request-stat-value">{employeeRequestsStats.approved}</div>
                <div className="sdf-request-stat-label">Approved</div>
              </div>
              <div className="sdf-request-stat-card rejected">
                <div className="sdf-request-stat-value">{employeeRequestsStats.rejected}</div>
                <div className="sdf-request-stat-label">Rejected</div>
              </div>
              <div className="sdf-request-stat-card total">
                <div className="sdf-request-stat-value">{employeeRequestsStats.total}</div>
                <div className="sdf-request-stat-label">Total</div>
              </div>
            </div>

            <div className="sdf-request-filters">
              <div className="sdf-filter-group">
                <label>Status Filter:</label>
                <select value={selectedRequestFilter} onChange={(e) => setSelectedRequestFilter(e.target.value)}>
                  <option value="all">All Status</option>
                  <option value="pending">Pending</option>
                  <option value="approved">Approved</option>
                  <option value="rejected">Rejected</option>
                </select>
              </div>
              <div className="sdf-filter-group">
                <label>Request Type:</label>
                <select value={selectedRequestType} onChange={(e) => setSelectedRequestType(e.target.value)}>
                  <option value="all">All Types</option>
                  <option value="dayoff">Day Off</option>
                  <option value="restday">Rest Day</option>
                  <option value="leave">Leave</option>
                  <option value="swap">Shift Swap</option>
                  <option value="sick">Sick Leave</option>
                  <option value="vacation">Vacation</option>
                  <option value="personal">Personal Time</option>
                  <option value="emergency">Emergency</option>
                </select>
              </div>
              <button className="sdf-refresh-btn" onClick={loadEmployeeRequests}>
                <ReloadOutlined /> Refresh
              </button>
            </div>

            <div className="sdf-requests-grid">
              {employeeRequestsLoading ? (
                [...Array(6)].map((_, i) => (
                  <div key={i} className="sdf-request-card skeleton">
                    <div className="sdf-request-header">
                      <div className="skeleton-avatar"></div>
                      <div><div className="skeleton-text"></div><div className="skeleton-text short"></div></div>
                    </div>
                    <div className="skeleton-badge"></div>
                    <div className="skeleton-text"></div>
                    <div className="skeleton-text short"></div>
                    <div className="skeleton-actions">
                      <div className="skeleton-button"></div>
                      <div className="skeleton-button"></div>
                    </div>
                  </div>
                ))
              ) : employeeRequests.length > 0 ? (
                employeeRequests.map(req => {
                  const typeInfo = getRequestTypeInfo(req.type);
                  const statusColor = getRequestStatusColor(req.status);
                  const statusBgColor = getRequestStatusBgColor(req.status);
                  const statusLabel = getRequestStatusLabel(req.status);
                  
                  return (
                    <div key={req.id} className="sdf-employee-request-card" onClick={() => { setSelectedRequest(req); setAdminNotes(req.admin_notes || ''); setShowRequestDetailModal(true); }}>
                      <div className="sdf-request-card-header">
                        <div className="sdf-request-employee-info">
                          <EmployeeAvatar employee={req.employee} size="small" />
                          <div>
                            <h4>{req.employee_name || req.employee?.name || 'Unknown Employee'}</h4>
                            <p><IdcardOutlined /> {req.employee_code || req.employee?.employee_code || 'N/A'}</p>
                          </div>
                        </div>
                        <div className="sdf-request-type-badge" style={{ backgroundColor: typeInfo.bgColor, color: typeInfo.color }}>
                          <span>{typeInfo.icon}</span>
                          <span>{typeInfo.label}</span>
                        </div>
                      </div>

                      <div className="sdf-request-dates">
                        <div className="sdf-request-date-range">
                          <CalendarOutlined />
                          <span>{formatDate(req.start_date)} - {formatDate(req.end_date)}</span>
                        </div>
                        <div className="sdf-request-duration">
                          {moment(req.end_date).diff(moment(req.start_date), 'days') + 1} day(s)
                        </div>
                      </div>

                      <div className="sdf-request-reason">
                        <MessageOutlined />
                        <span>{req.reason?.length > 100 ? req.reason.substring(0, 100) + '...' : req.reason}</span>
                      </div>

                      {req.type === 'swap' && req.swap_with_employee_id && (
                        <div className="sdf-request-swap-info">
                          <SwapOutlined />
                          <span>Swap with: {req.swap_employee?.name || `Employee ID: ${req.swap_with_employee_id}`}</span>
                          {req.swap_shift_date && <span> on {formatDate(req.swap_shift_date)}</span>}
                        </div>
                      )}

                      <div className="sdf-request-footer">
                        <div className="sdf-request-status" style={{ backgroundColor: statusBgColor, color: statusColor }}>
                          <div className="sdf-status-dot" style={{ backgroundColor: statusColor }}></div>
                          <span>{statusLabel}</span>
                        </div>
                        <div className="sdf-request-date">
                          <ClockCircleOutlined />
                          <span>Requested: {formatDate(req.request_date || req.created_at)}</span>
                        </div>
                      </div>

                      {req.admin_notes && req.status !== 'pending' && (
                        <div className="sdf-request-admin-note">
                          <strong>Admin Note:</strong> {req.admin_notes}
                        </div>
                      )}

                      {req.status === 'pending' && (
                        <div className="sdf-request-actions">
                          <button 
                            className="sdf-request-btn approve" 
                            onClick={(e) => { e.stopPropagation(); handleRequestAction(req, 'approve'); }}
                          >
                            <CheckCircleOutlined /> Approve
                          </button>
                          <button 
                            className="sdf-request-btn reject" 
                            onClick={(e) => { e.stopPropagation(); setSelectedRequest(req); setShowRequestDetailModal(true); }}
                          >
                            <CloseOutlined /> Reject
                          </button>
                        </div>
                      )}
                    </div>
                  );
                })
              ) : (
                <div className="sdf-empty-state">
                  <CheckCircleOutlined style={{ fontSize: '64px', color: '#ccc' }} />
                  <h3>No Requests Found</h3>
                  <p>Employee requests from mobile app will appear here</p>
                </div>
              )}
            </div>
          </div>
        )}

        {!isLoading && activeTab === 'employees' && (
          <div className="sdf-employees-container">
            <div className="sdf-employees-header">
              <h2><TeamOutlined /> Employee Directory</h2>
              <p>{employees.length} total • {employees.filter(e => e.status === 'active').length} active employees</p>
            </div>
            <div className="sdf-employees-grid">
              {employeesLoading ? (
                [...Array(6)].map((_, i) => (
                  <div key={i} className="sdf-employee-card skeleton">
                    <div className="sdf-employee-card-header">
                      <div className="skeleton-avatar large"></div>
                      <div className="skeleton-badge"></div>
                    </div>
                    <div className="sdf-employee-info">
                      <div className="skeleton-text"></div>
                      <div className="skeleton-text short"></div>
                      <div className="skeleton-text"></div>
                      <div className="skeleton-text"></div>
                      <div className="skeleton-stats">
                        <div className="skeleton-text small"></div>
                        <div className="skeleton-text small"></div>
                      </div>
                    </div>
                  </div>
                ))
              ) : (
                employees.filter(e => 
                  e.name.toLowerCase().includes(searchQuery.toLowerCase()) ||
                  (e.employee_code || '').toLowerCase().includes(searchQuery.toLowerCase())
                ).map(emp => {
                  const salaryGrade = getEmployeeSalaryGrade(emp);
                  const hourlyRate = getEmployeeHourlyRate(emp);
                  return (
                    <div key={emp.id} className="sdf-employee-card" onClick={() => { setSelectedEmployeeDetail(emp); setShowEmployeeModal(true); }}>
                      <div className="sdf-employee-card-header">
                        <EmployeeAvatar employee={emp} size="large" />
                        <div className="sdf-employee-status">
                          <span className={`sdf-status-dot ${emp.status}`}></span>
                          <span>{emp.status === 'active' ? 'Active' : emp.status === 'onleave' ? 'On Leave' : emp.status}</span>
                        </div>
                      </div>
                      <div className="sdf-employee-info">
                        <h3>{emp.name}</h3>
                        <p className="sdf-employee-code">{emp.employee_code || emp.employee_id || 'N/A'}</p>
                        <p className="sdf-employee-position">{getPositionTitle(emp.position)}</p>
                        <div className="sdf-employee-details">
                          <div><BankOutlined /> {emp.department?.name || 'N/A'}</div>
                          <div><DollarOutlined /> Salary Grade: {salaryGrade}</div>
                          <div><DollarOutlined /> {formatCurrency(hourlyRate)}/hr</div>
                          <div><MailOutlined /> {emp.email || 'N/A'}</div>
                          <div><PhoneOutlined /> {emp.phone || 'N/A'}</div>
                        </div>
                        <div className="sdf-employee-stats">
                          <div><span>Shifts</span><strong>{shifts.filter(s => s.employee_id === emp.id).length}</strong></div>
                          <div><span>Hours</span><strong>{shifts.filter(s => s.employee_id === emp.id).reduce((t, s) => t + (s.duration || 0), 0).toFixed(1)}h</strong></div>
                        </div>
                      </div>
                    </div>
                  );
                })
              )}
            </div>
          </div>
        )}

        {!isLoading && activeTab === 'costs' && (
          <div className="sdf-costs-container">
            <h2><BarChartOutlined /> Labor Cost Analysis - {selectedDateStr}</h2>
            <div className="sdf-costs-summary">
              {shiftsLoading ? (
                [...Array(3)].map((_, i) => (
                  <div key={i} className="sdf-cost-card skeleton">
                    <div className="skeleton-text"></div>
                    <div className="skeleton-text large"></div>
                    <div className="skeleton-text small"></div>
                  </div>
                ))
              ) : (
                <>
                  <div className="sdf-cost-card">
                    <div><DollarOutlined /> Total Labor Cost</div>
                    <div className="sdf-cost-value primary">{formatCurrency(laborCosts.totalCost)}</div>
                    <div>For {selectedDateStr}</div>
                    <div className="sdf-cost-breakdown">
                      <small>Regular: {formatCurrency(laborCosts.regularCost)}</small>
                      <small>On-Call: {formatCurrency(laborCosts.oncallCost)}</small>
                    </div>
                  </div>
                  <div className="sdf-cost-card">
                    <div><TeamOutlined /> Total Hours</div>
                    <div className="sdf-cost-value success">{laborCosts.totalHours.toFixed(1)}</div>
                    <div>Staff Hours</div>
                    <div className="sdf-cost-breakdown">
                      <small>Regular: {laborCosts.regularHours.toFixed(1)}h</small>
                      <small>On-Call: {laborCosts.oncallHours.toFixed(1)}h</small>
                    </div>
                  </div>
                  <div className="sdf-cost-card">
                    <div><PercentageOutlined /> Average Rate</div>
                    <div className="sdf-cost-value warning">{formatCurrency(laborCosts.avgRate)}</div>
                    <div>Per Hour</div>
                  </div>
                </>
              )}
            </div>
            <div className="sdf-costs-breakdown">
              {shiftsLoading ? (
                <>
                  <div className="sdf-breakdown-card skeleton"><div className="skeleton-text"></div><div><div className="skeleton-text"></div><div className="skeleton-text"></div></div></div>
                  <div className="sdf-breakdown-card skeleton"><div className="skeleton-text"></div><div><div className="skeleton-text"></div><div className="skeleton-text"></div></div></div>
                </>
              ) : (
                <>
                  <div className="sdf-breakdown-card">
                    <h4><CheckCircleOutlined /> Regular Staff</h4>
                    <div>
                      <div><span>Hours:</span><strong>{laborCosts.regularHours.toFixed(1)} hrs</strong></div>
                      <div><span>Cost:</span><strong>{formatCurrency(laborCosts.regularCost)}</strong></div>
                      <div><span>Employees:</span><strong>{new Set(shifts.filter(s => s.shift_type === 'regular').map(s => s.employee_id)).size}</strong></div>
                      <div><span>Avg Rate:</span><strong>{laborCosts.regularHours > 0 ? formatCurrency(laborCosts.regularCost / laborCosts.regularHours) : formatCurrency(0)}/hr</strong></div>
                    </div>
                  </div>
                  <div className="sdf-breakdown-card">
                    <h4><ClockCircleOutlined /> On-Call Staff</h4>
                    <div>
                      <div><span>Hours:</span><strong>{laborCosts.oncallHours.toFixed(1)} hrs</strong></div>
                      <div><span>Cost:</span><strong>{formatCurrency(laborCosts.oncallCost)}</strong></div>
                      <div><span>Employees:</span><strong>{new Set(shifts.filter(s => s.shift_type === 'oncall').map(s => s.employee_id)).size}</strong></div>
                      <div><span>Avg Rate:</span><strong>{laborCosts.oncallHours > 0 ? formatCurrency(laborCosts.oncallCost / laborCosts.oncallHours) : formatCurrency(0)}/hr</strong></div>
                    </div>
                  </div>
                </>
              )}
            </div>
            <div className="sdf-shifts-table-section">
              <h4>Shift Details for {selectedDateStr}</h4>
              <div className="sdf-shifts-table-wrapper">
                {shiftsLoading ? (
                  <SkeletonScheduleTable />
                ) : (
                  <table className="sdf-shifts-table">
                    <thead>
                      <tr>
                        <th>Employee Code</th>
                        <th>Employee</th>
                        <th>Salary Grade</th>
                        <th>Shift</th>
                        <th>Hours</th>
                        <th>Placement</th>
                        <th>Hourly Rate</th>
                        <th>Total</th>
                        <th>Type</th>
                        <th>Actions</th>
                      </tr>
                    </thead>
                    <tbody>
                      {shifts.map((shift, idx) => {
                        const duration = shift.duration || calculateDurationHours(shift.start_time, shift.end_time);
                        const hourlyRate = shift.hourly_rate || (shift.employee ? getEmployeeHourlyRate(shift.employee) : 0);
                        const salaryGrade = getEmployeeSalaryGrade(shift.employee);
                        return (
                          <tr key={idx}>
                            <td><span className="sdf-employee-code">{shift.employee?.employee_code || 'N/A'}</span></td>
                            <td>
                              <div className="sdf-employee-mini">
                                <EmployeeAvatar employee={shift.employee} size="small" />
                                <div>
                                  <strong>{shift.employee?.name || 'Unknown'}</strong>
                                </div>
                              </div>
                            </td>
                            <td><span className="sdf-salary-grade-badge">{salaryGrade}</span></td>
                            <td>{formatTimeRange(shift.start_time, shift.end_time)}</td>
                            <td>{duration.toFixed(1)} hrs</td>
                            <td>{shift.placement || 'N/A'}</td>
                            <td>{formatCurrency(hourlyRate)}/hr</td>
                            <td className="sdf-cost-highlight">{formatCurrency(shift.total_cost)}</td>
                            <td><span className={`sdf-shift-type-badge ${shift.shift_type}`}>{shift.shift_type === 'regular' ? 'Regular' : 'On-Call'}</span></td>
                            <td className="sdf-table-actions">
                              <button className="sdf-action-btn view" onClick={() => { setSelectedShift(shift); setModalMode('view'); setShowModal(true); }}><EyeOutlined /></button>
                              <button className="sdf-action-btn edit" onClick={() => openEditModal(shift)} disabled={!isDateEditable}><EditOutlined /></button>
                              <button className="sdf-action-btn delete" onClick={() => { setDeleteItemId(shift.id); setShowDeleteConfirm(true); }} disabled={!isDateEditable}><DeleteOutlined /></button>
                            </td>
                          </tr>
                        );
                      })}
                    </tbody>
                  </table>
                )}
              </div>
            </div>
          </div>
        )}
      </main>

      {/* Request Detail Modal */}
      {showRequestDetailModal && selectedRequest && (
        <div className="sdf-modal-overlay" onClick={() => setShowRequestDetailModal(false)}>
          <div className="sdf-modal sdf-modal-large" onClick={(e) => e.stopPropagation()}>
            <div className="sdf-modal-header">
              <h2><FormOutlined /> Request Details</h2>
              <button className="sdf-modal-close" onClick={() => setShowRequestDetailModal(false)}><CloseOutlined /></button>
            </div>
            <div className="sdf-modal-body">
              <div className="sdf-request-detail-content">
                <div className="sdf-request-detail-header">
                  <EmployeeAvatar employee={selectedRequest.employee} size="large" />
                  <div>
                    <h3>{selectedRequest.employee_name || selectedRequest.employee?.name || 'Unknown Employee'}</h3>
                    <p><IdcardOutlined /> {selectedRequest.employee_code || selectedRequest.employee?.employee_code || 'N/A'}</p>
                    <div className="sdf-request-detail-type" style={{ backgroundColor: getRequestTypeInfo(selectedRequest.type).bgColor, color: getRequestTypeInfo(selectedRequest.type).color }}>
                      {getRequestTypeInfo(selectedRequest.type).icon} {getRequestTypeInfo(selectedRequest.type).label}
                    </div>
                  </div>
                </div>

                <div className="sdf-request-detail-section">
                  <h4><CalendarOutlined /> Request Period</h4>
                  <div className="sdf-detail-date-range">
                    <div><strong>From:</strong> {formatDate(selectedRequest.start_date)}</div>
                    <div><strong>To:</strong> {formatDate(selectedRequest.end_date)}</div>
                    <div><strong>Duration:</strong> {moment(selectedRequest.end_date).diff(moment(selectedRequest.start_date), 'days') + 1} day(s)</div>
                  </div>
                </div>

                <div className="sdf-request-detail-section">
                  <h4><MessageOutlined /> Reason</h4>
                  <p className="sdf-detail-reason">{selectedRequest.reason}</p>
                </div>

                {selectedRequest.type === 'swap' && selectedRequest.swap_with_employee_id && (
                  <div className="sdf-request-detail-section">
                    <h4><SwapOutlined /> Shift Swap Details</h4>
                    <div className="sdf-detail-swap">
                      <div><strong>Swap With:</strong> {selectedRequest.swap_employee?.name || `Employee ID: ${selectedRequest.swap_with_employee_id}`}</div>
                      {selectedRequest.swap_shift_date && <div><strong>Swap Date:</strong> {formatDate(selectedRequest.swap_shift_date)}</div>}
                    </div>
                  </div>
                )}

                {selectedRequest.status !== 'pending' && selectedRequest.admin_notes && (
                  <div className="sdf-request-detail-section">
                    <h4><InfoCircleOutlined /> Admin Notes</h4>
                    <div className="sdf-detail-admin-note" style={{ backgroundColor: getRequestStatusBgColor(selectedRequest.status) }}>
                      {selectedRequest.admin_notes}
                    </div>
                  </div>
                )}

                {selectedRequest.status === 'pending' && (
                  <div className="sdf-request-detail-section">
                    <h4><MessageOutlined /> Admin Notes (Optional)</h4>
                    <textarea
                      className="sdf-admin-notes-input"
                      rows="3"
                      placeholder="Add notes for the employee (optional)..."
                      value={adminNotes}
                      onChange={(e) => setAdminNotes(e.target.value)}
                    />
                  </div>
                )}

                <div className="sdf-request-detail-status">
                  <div className="sdf-status-label">Current Status:</div>
                  <div className="sdf-status-badge" style={{ backgroundColor: getRequestStatusBgColor(selectedRequest.status), color: getRequestStatusColor(selectedRequest.status) }}>
                    {getRequestStatusLabel(selectedRequest.status)}
                  </div>
                </div>

                {selectedRequest.status === 'pending' && (
                  <div className="sdf-request-detail-actions">
                    <button className="sdf-btn sdf-btn-success" onClick={() => handleRequestAction(selectedRequest, 'approve')}>
                      <CheckCircleOutlined /> Approve Request
                    </button>
                    <button className="sdf-btn sdf-btn-danger" onClick={() => handleRequestAction(selectedRequest, 'reject')}>
                      <CloseOutlined /> Reject Request
                    </button>
                  </div>
                )}
              </div>
            </div>
            <div className="sdf-modal-footer">
              <button className="sdf-btn sdf-btn-secondary" onClick={() => setShowRequestDetailModal(false)}>Close</button>
            </div>
          </div>
        </div>
      )}

      {/* Save Schedule Modal */}
      {showScheduleFileModal && (
        <div className="sdf-modal-overlay" onClick={() => setShowScheduleFileModal(false)}>
          <div className="sdf-modal sdf-modal-small" onClick={(e) => e.stopPropagation()}>
            <div className="sdf-modal-header">
              <h2><SaveOutlined /> Save Schedule</h2>
              <button className="sdf-modal-close" onClick={() => setShowScheduleFileModal(false)}><CloseOutlined /></button>
            </div>
            <div className="sdf-modal-body">
              <div className="sdf-form-group">
                <label>Schedule Name</label>
                <input
                  type="text"
                  value={scheduleFileName}
                  onChange={(e) => setScheduleFileName(e.target.value)}
                  placeholder="Enter schedule name..."
                  className="sdf-form-input"
                  autoFocus
                />
                <small className="sdf-form-hint">This schedule will be saved with {shifts.length} shifts for {selectedDateStr}</small>
              </div>
              <div className="sdf-schedule-preview">
                <p><strong>Preview:</strong></p>
                <ul>
                  <li>📅 Date: {selectedDateStr}</li>
                  <li>👥 Total Shifts: {shifts.length}</li>
                  <li>⏱️ Total Hours: {laborCosts.totalHours.toFixed(1)}</li>
                  <li>💰 Total Cost: {formatCurrency(laborCosts.totalCost)}</li>
                </ul>
              </div>
            </div>
            <div className="sdf-modal-footer">
              <button className="sdf-btn sdf-btn-secondary" onClick={() => setShowScheduleFileModal(false)}>Cancel</button>
              <button className="sdf-btn sdf-btn-primary" onClick={saveScheduleToHistory}>
                <SaveOutlined /> Save Schedule
              </button>
            </div>
          </div>
        </div>
      )}

      {/* Print Modal */}
      {showPrintModal && printSchedule && (
        <div className="sdf-modal-overlay" onClick={() => setShowPrintModal(false)}>
          <div className="sdf-modal sdf-modal-large" onClick={(e) => e.stopPropagation()}>
            <div className="sdf-modal-header">
              <h2><FileTextOutlined /> {printSchedule.name}</h2>
              <button className="sdf-modal-close" onClick={() => setShowPrintModal(false)}><CloseOutlined /></button>
            </div>
            <div className="sdf-modal-body">
              <div id="print-schedule-content">
                <div className="sdf-print-header">
                  <h1>Staff Schedule Report</h1>
                  <p><strong>Schedule Name:</strong> {printSchedule.name}</p>
                  <p><strong>Date:</strong> {printSchedule.date}</p>
                  <p><strong>Created:</strong> {new Date(printSchedule.createdAt).toLocaleString()}</p>
                </div>
                <div className="sdf-schedule-detail-info">
                  <p><strong>Total Shifts:</strong> {printSchedule.employeeCount}</p>
                  <p><strong>Total Hours:</strong> {printSchedule.totalHours.toFixed(1)}</p>
                  <p><strong>Total Cost:</strong> {formatCurrency(printSchedule.totalCost)}</p>
                </div>
                <div className="sdf-schedule-shifts-list">
                  <h4>Shift Details</h4>
                  <table className="sdf-print-table">
                    <thead>
                      <tr>
                        <th>Employee Code</th>
                        <th>Employee</th>
                        <th>Position</th>
                        <th>Time In</th>
                        <th>Time Out</th>
                        <th>Placement</th>
                        <th>Hours</th>
                        <th>Cost</th>
                      </tr>
                    </thead>
                    <tbody>
                      {printSchedule.shifts.map((shift, idx) => {
                        const duration = shift.duration || calculateDurationHours(shift.start_time, shift.end_time);
                        return (
                          <tr key={idx}>
                            <td>{shift.employee?.employee_code || 'N/A'}</td>
                            <td>{shift.employee?.name || 'Unknown'}</td>
                            <td>{getPositionTitle(shift.employee?.position)}</td>
                            <td>{formatTo12Hour(shift.start_time)}</td>
                            <td>{formatTo12Hour(shift.end_time)}</td>
                            <td>{shift.placement || 'N/A'}</td>
                            <td>{duration.toFixed(1)}h</td>
                            <td className="sdf-cost-cell">{formatCurrency(shift.total_cost)}</td>
                          </tr>
                        );
                      })}
                    </tbody>
                    <tfoot>
                      <tr className="sdf-print-total">
                        <td colSpan="6"><strong>Total</strong></td>
                        <td><strong>{printSchedule.totalHours.toFixed(1)} hrs</strong></td>
                        <td><strong>{formatCurrency(printSchedule.totalCost)}</strong></td>
                      </tr>
                    </tfoot>
                  </table>
                </div>
              </div>
            </div>
            <div className="sdf-modal-footer">
              <button className="sdf-btn sdf-btn-secondary" onClick={() => setShowPrintModal(false)}>Close</button>
              <button className="sdf-btn sdf-btn-primary" onClick={handlePrint}>
                <PrinterOutlined /> Print / Save as PDF
              </button>
            </div>
          </div>
        </div>
      )}

      {/* Batch Schedule Modal */}
      {showBatchModal && (
        <div className="sdf-modal-overlay" onClick={() => setShowBatchModal(false)}>
          <div className="sdf-batch-modal" onClick={(e) => e.stopPropagation()}>
            <div className="sdf-batch-modal-header">
              <div className="sdf-batch-modal-title">
                <div className="sdf-batch-title-icon">
                  <ScheduleOutlined />
                </div>
                <div>
                  <h2>Batch Schedule Creation</h2>
                  <p>Assign multiple shifts with identical schedule settings</p>
                </div>
              </div>
              <button className="sdf-batch-modal-close" onClick={() => setShowBatchModal(false)}>
                <CloseOutlined />
              </button>
            </div>

            <div className="sdf-batch-modal-body">
              <div className="sdf-batch-two-columns">
                {/* Left Panel - Shift Configuration */}
                <div className="sdf-batch-left-panel">
                  <div className="sdf-panel-section-title">
                    <SettingOutlined /> Shift Configuration
                  </div>

                  <div className="sdf-batch-form-group">
                    <label><CalendarOutlined /> Schedule Date</label>
                    <input
                      type="date"
                      name="date"
                      value={batchScheduleData.date}
                      onChange={handleBatchInputChange}
                      className="sdf-batch-input"
                    />
                  </div>

                  <div className="sdf-batch-form-row">
                    <div className="sdf-batch-form-group">
                      <label><ClockCircleOutlined /> Start Time</label>
                      <select name="startTime" value={batchScheduleData.startTime} onChange={handleBatchInputChange} className="sdf-batch-select">
                        {Array.from({ length: 48 }, (_, i) => {
                          const hour = Math.floor(i / 2);
                          const minute = i % 2 === 0 ? '00' : '30';
                          const timeStr = `${String(hour).padStart(2, '0')}:${minute}`;
                          return (
                            <option key={timeStr} value={timeStr}>
                              {formatTo12Hour(timeStr)}
                            </option>
                          );
                        })}
                      </select>
                    </div>
                    <div className="sdf-batch-form-group">
                      <label><ClockCircleOutlined /> End Time</label>
                      <select name="endTime" value={batchScheduleData.endTime} onChange={handleBatchInputChange} className="sdf-batch-select">
                        {Array.from({ length: 48 }, (_, i) => {
                          const hour = Math.floor(i / 2);
                          const minute = i % 2 === 0 ? '00' : '30';
                          const timeStr = `${String(hour).padStart(2, '0')}:${minute}`;
                          return (
                            <option key={timeStr} value={timeStr}>
                              {formatTo12Hour(timeStr)}
                            </option>
                          );
                        })}
                      </select>
                    </div>
                  </div>

                  <div className="sdf-batch-form-group">
                    <label><TagsOutlined /> Shift Type</label>
                    <div className="sdf-batch-radio-group">
                      <label className={`sdf-batch-radio-label ${batchScheduleData.shift_type === 'regular' ? 'active' : ''}`}>
                        <input
                          type="radio"
                          name="shift_type"
                          value="regular"
                          checked={batchScheduleData.shift_type === 'regular'}
                          onChange={handleBatchInputChange}
                        />
                        <span>Regular</span>
                      </label>
                      <label className={`sdf-batch-radio-label ${batchScheduleData.shift_type === 'oncall' ? 'active' : ''}`}>
                        <input
                          type="radio"
                          name="shift_type"
                          value="oncall"
                          checked={batchScheduleData.shift_type === 'oncall'}
                          onChange={handleBatchInputChange}
                        />
                        <span>On-Call</span>
                      </label>
                    </div>
                  </div>

                  <div className="sdf-batch-form-group">
                    <label><EnvironmentOutlined /> Work Location</label>
                    <input
                      type="text"
                      name="placement"
                      value={batchScheduleData.placement}
                      onChange={handleBatchInputChange}
                      placeholder="e.g., Main Office, Warehouse, Branch A"
                      className="sdf-batch-input"
                    />
                  </div>

                  <div className="sdf-batch-form-group">
                    <label><FileTextOutlined /> Additional Notes</label>
                    <textarea
                      name="notes"
                      value={batchScheduleData.notes}
                      onChange={handleBatchInputChange}
                      rows="2"
                      placeholder="Optional: Add notes for all shifts..."
                      className="sdf-batch-textarea"
                    />
                  </div>

                  <div className="sdf-batch-summary-card">
                    <div className="sdf-summary-row">
                      <span><ClockCircleOutlined /> Duration:</span>
                      <strong>
                        {(() => {
                          const duration = calculateDurationHours(batchScheduleData.startTime, batchScheduleData.endTime);
                          return !isNaN(duration) ? `${duration.toFixed(1)} hours` : '0 hours';
                        })()}
                      </strong>
                    </div>
                    <div className="sdf-summary-row">
                      <span><TeamOutlined /> Selected Staff:</span>
                      <strong>{selectedEmployees.length} employee{selectedEmployees.length !== 1 ? 's' : ''}</strong>
                    </div>
                    <div className="sdf-summary-row total">
                      <span><DollarOutlined /> Estimated Total Cost:</span>
                      <strong>
                        {(() => {
                          const duration = calculateDurationHours(batchScheduleData.startTime, batchScheduleData.endTime);
                          if (isNaN(duration) || duration <= 0) return formatCurrency(0);
                          const totalHourlyRate = selectedEmployees.reduce((sum, empId) => {
                            const emp = employees.find(e => e.id === empId);
                            return sum + (emp ? getEmployeeHourlyRate(emp) : 0);
                          }, 0);
                          return formatCurrency(totalHourlyRate * duration);
                        })()}
                      </strong>
                    </div>
                  </div>
                </div>

                {/* Right Panel - Employee Selection */}
                <div className="sdf-batch-right-panel">
                  <div className="sdf-panel-section-title">
                    <TeamOutlined /> Employee Selection
                  </div>

                  <div className="sdf-batch-filter-bar">
                    <div className="sdf-batch-search">
                      <SearchOutlined />
                      <input
                        type="text"
                        placeholder="Search by name, code, or ID..."
                        value={batchFilters.searchTerm}
                        onChange={(e) => setBatchFilters(prev => ({ ...prev, searchTerm: e.target.value }))}
                      />
                    </div>
                    <select
                      className="sdf-batch-filter-select"
                      value={batchFilters.department}
                      onChange={(e) => setBatchFilters(prev => ({ ...prev, department: e.target.value }))}
                    >
                      <option value="all">All Departments</option>
                      {Array.from(new Set(employees.map(e => e.department?.name))).filter(d => d && d !== 'N/A').map(dept => (
                        <option key={dept} value={dept}>{dept}</option>
                      ))}
                    </select>
                    <select
                      className="sdf-batch-filter-select"
                      value={batchFilters.employeeType}
                      onChange={(e) => setBatchFilters(prev => ({ ...prev, employeeType: e.target.value }))}
                    >
                      <option value="all">All Types</option>
                      <option value="regular">Regular</option>
                      <option value="oncall">On-Call</option>
                    </select>
                  </div>

                  <div className="sdf-batch-select-all">
                    <label className="sdf-batch-checkbox">
                      <input
                        type="checkbox"
                        checked={isAllSelected}
                        onChange={handleSelectAll}
                      />
                      <span><CheckSquareOutlined /> Select All ({filteredEmployeesForBatch.length} employees)</span>
                    </label>
                  </div>

                  <div className="sdf-batch-employee-list">
                    {filteredEmployeesForBatch.map(emp => {
                      const hasExistingShift = shifts.some(s =>
                        (s.employee_id === emp.id || s.employeeId === emp.id) &&
                        (s.date === batchScheduleData.date || s.work_date === batchScheduleData.date)
                      );
                      
                      return (
                        <label 
                          key={emp.id} 
                          className={`sdf-batch-employee-item ${hasExistingShift ? 'disabled' : ''}`}
                        >
                          <input
                            type="checkbox"
                            checked={selectedEmployees.includes(emp.id)}
                            onChange={() => {
                              if (!hasExistingShift) {
                                handleSelectEmployee(emp.id);
                              }
                            }}
                            disabled={hasExistingShift}
                          />
                          <div className="sdf-batch-employee-avatar">
                            <EmployeeAvatar employee={emp} size="small" />
                          </div>
                          <div className="sdf-batch-employee-info">
                            <div className="sdf-batch-employee-name">{emp.name}</div>
                            <div className="sdf-batch-employee-code">{emp.employee_code || emp.employee_id || 'N/A'}</div>
                            <div className="sdf-batch-employee-meta">
                              <span><BankOutlined /> {getPositionTitle(emp.position)}</span>
                              <span><DollarOutlined /> {getEmployeeSalaryGrade(emp)}</span>
                            </div>
                          </div>
                          {hasExistingShift && (
                            <span className="sdf-batch-warning-badge">
                              <WarningOutlined /> Already Scheduled
                            </span>
                          )}
                        </label>
                      );
                    })}
                    {filteredEmployeesForBatch.length === 0 && (
                      <div className="sdf-batch-empty">
                        <FilterOutlined />
                        <p>
                          {shifts.some(s => s.date === batchScheduleData.date || s.work_date === batchScheduleData.date) 
                            ? 'All employees already have shifts on this date' 
                            : 'No employees match your filters'}
                        </p>
                      </div>
                    )}
                  </div>
                </div>
              </div>
            </div>

            <div className="sdf-batch-modal-footer">
              <button className="sdf-batch-btn-secondary" onClick={() => setShowBatchModal(false)}>
                Cancel
              </button>
              <button
                className="sdf-batch-btn-primary"
                onClick={handleBatchSchedule}
                disabled={selectedEmployees.length === 0 || !batchScheduleData.date || createShiftMutation.isPending}
              >
                {createShiftMutation.isPending ? <LoadingOutlined spin /> : <><PlusOutlined /> Create {selectedEmployees.length} Shift{selectedEmployees.length !== 1 ? 's' : ''}</>}
              </button>
            </div>
          </div>
        </div>
      )}

      {/* Employee Detail Modal */}
      {showEmployeeModal && selectedEmployeeDetail && (
        <div className="sdf-modal-overlay" onClick={() => setShowEmployeeModal(false)}>
          <div className="sdf-modal sdf-modal-large" onClick={(e) => e.stopPropagation()}>
            <div className="sdf-modal-header">
              <h2><UserOutlined /> Employee Profile</h2>
              <button className="sdf-modal-close" onClick={() => setShowEmployeeModal(false)}><CloseOutlined /></button>
            </div>
            <div className="sdf-modal-body">
              <div className="sdf-employee-profile">
                <div className="sdf-profile-header">
                  <EmployeeAvatar employee={selectedEmployeeDetail} size="large" />
                  <div>
                    <h3>{selectedEmployeeDetail.name}</h3>
                    <p className="sdf-employee-code">{selectedEmployeeDetail.employee_code || selectedEmployeeDetail.employee_id || 'N/A'}</p>
                    <p>{getPositionTitle(selectedEmployeeDetail.position)}</p>
                    <div className="sdf-profile-badges">
                      <span className={`sdf-status-badge ${selectedEmployeeDetail.status}`}>
                        {selectedEmployeeDetail.status === 'active' ? 'Active' : selectedEmployeeDetail.status === 'onleave' ? 'On Leave' : selectedEmployeeDetail.status}
                      </span>
                      <span className={`sdf-type-badge ${selectedEmployeeDetail.employee_type}`}>
                        {selectedEmployeeDetail.employee_type === 'regular' ? 'Regular' : selectedEmployeeDetail.employee_type === 'oncall' ? 'On-Call' : 'Contract'}
                      </span>
                    </div>
                  </div>
                </div>
                <div className="sdf-profile-details">
                  <div className="sdf-detail-section">
                    <h4>Contact Information</h4>
                    <div><MailOutlined /> {selectedEmployeeDetail.email || 'N/A'}</div>
                    <div><PhoneOutlined /> {selectedEmployeeDetail.phone || 'N/A'}</div>
                    <div><EnvironmentOutlined /> {selectedEmployeeDetail.location || selectedEmployeeDetail.address || 'Not specified'}</div>
                  </div>
                  <div className="sdf-detail-section">
                    <h4>Employment Details</h4>
                    <div><IdcardOutlined /> Employee Code: {selectedEmployeeDetail.employee_code || selectedEmployeeDetail.employee_id || 'N/A'}</div>
                    <div><BankOutlined /> Department: {selectedEmployeeDetail.department?.name || 'N/A'}</div>
                    <div><DollarOutlined /> Salary Grade: {getEmployeeSalaryGrade(selectedEmployeeDetail)}</div>
                    <div><DollarOutlined /> Hourly Rate: {formatCurrency(getEmployeeHourlyRate(selectedEmployeeDetail))}/hr</div>
                  </div>
                  <div className="sdf-detail-section">
                    <h4>Schedule Summary</h4>
                    <div>📋 Shifts: {shifts.filter(s => s.employee_id === selectedEmployeeDetail.id).length}</div>
                    <div>⏱️ Hours: {shifts.filter(s => s.employee_id === selectedEmployeeDetail.id).reduce((t, s) => t + (s.duration || 0), 0).toFixed(1)} hrs</div>
                    <div>💰 Earnings: {formatCurrency(shifts.filter(s => s.employee_id === selectedEmployeeDetail.id).reduce((t, s) => t + (s.total_cost || 0), 0))}</div>
                  </div>
                </div>
              </div>
            </div>
            <div className="sdf-modal-footer">
              <div className="sdf-btn-group">
                <button className="sdf-btn sdf-btn-secondary" onClick={() => setShowEmployeeModal(false)}>Close</button>
              </div>
            </div>
          </div>
        </div>
      )}

      {/* Shift Modal */}
      {showModal && (
        <div className="sdf-modal-overlay" onClick={() => setShowModal(false)}>
          <div className="sdf-shift-modal" onClick={(e) => e.stopPropagation()}>
            <div className="sdf-shift-modal-header">
              <div className="sdf-shift-modal-title">
                <div className="sdf-shift-title-icon">
                  {modalMode === 'add' && <PlusOutlined />}
                  {modalMode === 'edit' && <EditOutlined />}
                  {modalMode === 'view' && <EyeOutlined />}
                </div>
                <div>
                  <h2>
                    {modalMode === 'add' && 'Create New Shift'}
                    {modalMode === 'edit' && 'Edit Shift'}
                    {modalMode === 'view' && 'Shift Details'}
                  </h2>
                  <p>
                    {modalMode === 'add' && 'Schedule a new shift for an employee'}
                    {modalMode === 'edit' && 'Modify existing shift information'}
                    {modalMode === 'view' && 'View complete shift information'}
                  </p>
                </div>
              </div>
              <button className="sdf-shift-modal-close" onClick={() => setShowModal(false)}>
                <CloseOutlined />
              </button>
            </div>

            <div className="sdf-shift-modal-body">
              {modalMode === 'view' && selectedShift ? (
                /* View Mode */
                <div className="sdf-shift-view-mode">
                  <div className="sdf-shift-employee-section">
                    <EmployeeAvatar employee={selectedShift.employee} size="large" />
                    <div className="sdf-shift-employee-info">
                      <h3>{selectedShift.employee?.name || 'Unknown'}</h3>
                      <div className="sdf-shift-employee-meta">
                        <span className="sdf-employee-code-badge">{selectedShift.employee?.employee_code || 'N/A'}</span>
                        <span className="sdf-employee-position-badge">{getPositionTitle(selectedShift.employee?.position)}</span>
                        <span className={`sdf-shift-type-badge ${selectedShift.shift_type}`}>
                          {selectedShift.shift_type === 'regular' ? 'Regular' : 'On-Call'}
                        </span>
                      </div>
                    </div>
                  </div>

                  <div className="sdf-shift-details-grid">
                    <div className="sdf-detail-card">
                      <div className="sdf-detail-label"><CalendarOutlined /> Date</div>
                      <div className="sdf-detail-value">{selectedShift.date}</div>
                    </div>
                    <div className="sdf-detail-card">
                      <div className="sdf-detail-label"><ClockCircleOutlined /> Time In</div>
                      <div className="sdf-detail-value time">{formatTo12Hour(selectedShift.start_time)}</div>
                    </div>
                    <div className="sdf-detail-card">
                      <div className="sdf-detail-label"><ClockCircleOutlined /> Time Out</div>
                      <div className="sdf-detail-value time">{formatTo12Hour(selectedShift.end_time)}</div>
                    </div>
                    <div className="sdf-detail-card">
                      <div className="sdf-detail-label"><EnvironmentOutlined /> Placement</div>
                      <div className="sdf-detail-value">{selectedShift.placement || 'Not specified'}</div>
                    </div>
                    <div className="sdf-detail-card">
                      <div className="sdf-detail-label"><ScheduleOutlined /> Duration</div>
                      <div className="sdf-detail-value highlight">
                        {(selectedShift.duration || calculateDurationHours(selectedShift.start_time, selectedShift.end_time)).toFixed(2)} hours
                      </div>
                    </div>
                    <div className="sdf-detail-card">
                      <div className="sdf-detail-label"><BankOutlined /> Salary Grade</div>
                      <div className="sdf-detail-value">{getEmployeeSalaryGrade(selectedShift.employee)}</div>
                    </div>
                    <div className="sdf-detail-card">
                      <div className="sdf-detail-label"><DollarOutlined /> Hourly Rate</div>
                      <div className="sdf-detail-value">{formatCurrency(getEmployeeHourlyRate(selectedShift.employee))}/hr</div>
                    </div>
                    <div className="sdf-detail-card">
                      <div className="sdf-detail-label"><DollarOutlined /> Total Cost</div>
                      <div className="sdf-detail-value total-cost">{formatCurrency(selectedShift.total_cost)}</div>
                    </div>
                  </div>

                  {selectedShift.notes && (
                    <div className="sdf-shift-notes-section">
                      <div className="sdf-notes-label"><FileTextOutlined /> Notes</div>
                      <div className="sdf-notes-content">{selectedShift.notes}</div>
                    </div>
                  )}

                  {isDateEditable && (
                    <div className="sdf-shift-actions">
                      <button className="sdf-shift-btn-edit" onClick={() => openEditModal(selectedShift)}>
                        <EditOutlined /> Edit Shift
                      </button>
                      <button className="sdf-shift-btn-archive" onClick={() => { setDeleteItemId(selectedShift.id); setShowModal(false); setShowDeleteConfirm(true); }}>
                        <DeleteOutlined /> Archive Shift
                      </button>
                    </div>
                  )}
                </div>
              ) : (
                /* Add/Edit Mode - Form */
                <form onSubmit={(e) => { e.preventDefault(); handleSaveShift(); }} className="sdf-shift-form">
                  <div className="sdf-form-field">
                    <label><UserOutlined /> Select Employee <span className="sdf-required">*</span></label>
                    <select 
                      name="employeeId" 
                      value={formData.employeeId} 
                      onChange={handleInputChange} 
                      required 
                      disabled={!isDateEditable} 
                      className="sdf-form-select-modern"
                    >
                      <option value="">Choose employee...</option>
                      {employees.filter(e => e.status === 'active').map(emp => (
                        <option key={emp.id} value={emp.id}>
                          {emp.name} ({emp.employee_code || emp.employee_id || 'N/A'}) - {getPositionTitle(emp.position)}
                        </option>
                      ))}
                    </select>
                  </div>

                  <div className="sdf-form-row-modern">
                    <div className="sdf-form-field">
                      <label><CalendarOutlined /> Date <span className="sdf-required">*</span></label>
                      <input 
                        type="date" 
                        name="date" 
                        value={formData.date} 
                        onChange={handleInputChange} 
                        required 
                        disabled={!isDateEditable} 
                        className="sdf-form-input-modern" 
                      />
                    </div>
                    <div className="sdf-form-field">
                      <label><TagsOutlined /> Shift Type</label>
                      <select 
                        name="shift_type" 
                        value={formData.shift_type} 
                        onChange={handleInputChange} 
                        disabled={!isDateEditable} 
                        className="sdf-form-select-modern"
                      >
                        <option value="regular">Regular</option>
                        <option value="oncall">On-Call</option>
                      </select>
                    </div>
                  </div>

                  <div className="sdf-form-row-modern">
                    <div className="sdf-form-field">
                      <label><ClockCircleOutlined /> Time In <span className="sdf-required">*</span></label>
                      <select
                        name="startTime"
                        value={formData.startTime}
                        onChange={handleInputChange}
                        required
                        disabled={!isDateEditable}
                        className="sdf-form-select-modern"
                      >
                        {Array.from({ length: 48 }, (_, i) => {
                          const hour = Math.floor(i / 2);
                          const minute = i % 2 === 0 ? '00' : '30';
                          const timeStr = `${String(hour).padStart(2, '0')}:${minute}`;
                          return (
                            <option key={timeStr} value={timeStr}>
                              {formatTo12Hour(timeStr)}
                            </option>
                          );
                        })}
                      </select>
                    </div>
                    <div className="sdf-form-field">
                      <label><ClockCircleOutlined /> Time Out <span className="sdf-required">*</span></label>
                      <select
                        name="endTime"
                        value={formData.endTime}
                        onChange={handleInputChange}
                        required
                        disabled={!isDateEditable}
                        className="sdf-form-select-modern"
                      >
                        {Array.from({ length: 48 }, (_, i) => {
                          const hour = Math.floor(i / 2);
                          const minute = i % 2 === 0 ? '00' : '30';
                          const timeStr = `${String(hour).padStart(2, '0')}:${minute}`;
                          return (
                            <option key={timeStr} value={timeStr}>
                              {formatTo12Hour(timeStr)}
                            </option>
                          );
                        })}
                      </select>
                    </div>
                  </div>

                  <div className="sdf-form-field">
                    <label><EnvironmentOutlined /> Work Location</label>
                    <input 
                      type="text" 
                      name="placement" 
                      value={formData.placement} 
                      onChange={handleInputChange} 
                      placeholder="e.g., Main Hall, Kitchen, Reception" 
                      disabled={!isDateEditable} 
                      className="sdf-form-input-modern" 
                    />
                  </div>

                  <div className="sdf-form-field">
                    <label><FileTextOutlined /> Notes</label>
                    <textarea 
                      name="notes" 
                      value={formData.notes} 
                      onChange={handleInputChange} 
                      rows="2" 
                      placeholder="Optional: Add any additional notes..." 
                      disabled={!isDateEditable} 
                      className="sdf-form-textarea-modern" 
                    />
                  </div>

                  {overlapError && (
                    <div className="sdf-overlap-warning-modern">
                      <WarningOutlined /> {overlapError}
                    </div>
                  )}

                  {getShiftBreakdownDisplay()}
                  
                  <div className="sdf-shift-summary-modern">
                    <div className="sdf-summary-title">Shift Summary</div>
                    <div className="sdf-summary-grid">
                      <div className="sdf-summary-item">
                        <span>Time In</span>
                        <strong>{formatTo12Hour(formData.startTime)}</strong>
                      </div>
                      <div className="sdf-summary-item">
                        <span>Time Out</span>
                        <strong>{formatTo12Hour(formData.endTime)}</strong>
                      </div>
                      <div className="sdf-summary-item">
                        <span>Duration</span>
                        <strong>
                          {(() => {
                            const duration = calculateDurationHours(formData.startTime, formData.endTime);
                            return !isNaN(duration) ? `${duration.toFixed(2)} hours` : '0.00 hours';
                          })()}
                        </strong>
                      </div>
                      <div className="sdf-summary-item">
                        <span>Hourly Rate</span>
                        <strong>{formData.employeeId ? formatCurrency(getEmployeeHourlyRate(employees.find(e => e.id === parseInt(formData.employeeId)))) : '₱0'}/hr</strong>
                      </div>
                      <div className="sdf-summary-item total">
                        <span>Total Earnings</span>
                        <strong>
                          {(() => {
                            const total = calculateShiftTotal();
                            return !isNaN(total) ? formatCurrency(total) : '₱0.00';
                          })()}
                        </strong>
                      </div>
                    </div>
                  </div>

                  <button 
                    type="submit" 
                    className="sdf-shift-submit-btn" 
                    disabled={createShiftMutation.isPending || updateShiftMutation.isPending || overlapError || !isDateEditable}
                  >
                    {createShiftMutation.isPending || updateShiftMutation.isPending ? <LoadingOutlined spin /> : <PlusOutlined />}
                    {modalMode === 'add' ? 'Create Shift' : 'Save Changes'}
                  </button>
                </form>
              )}
            </div>
          </div>
        </div>
      )}

      {/* Delete Confirm Modal */}
      {showDeleteConfirm && (
        <div className="sdf-modal-overlay" onClick={() => setShowDeleteConfirm(false)}>
          <div className="sdf-modal sdf-modal-small" onClick={(e) => e.stopPropagation()}>
            <div className="sdf-modal-header">
              <h2><WarningOutlined /> Confirm Archive</h2>
              <button className="sdf-modal-close" onClick={() => setShowDeleteConfirm(false)}><CloseOutlined /></button>
            </div>
            <div className="sdf-modal-body">
              <p>Are you sure you want to archive this shift? You can restore it later from the Archived tab.</p>
            </div>
            <div className="sdf-modal-footer">
              <button className="sdf-btn sdf-btn-secondary" onClick={() => setShowDeleteConfirm(false)}>Cancel</button>
              <button className="sdf-btn sdf-btn-warning" onClick={confirmDelete} disabled={archiveShiftMutation.isPending}>
                {archiveShiftMutation.isPending ? <LoadingOutlined spin /> : 'Archive Shift'}
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
};

export default Staff_Scheduling;