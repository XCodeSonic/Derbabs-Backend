import React, { useState, useEffect, useMemo, useCallback } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import {
  TeamOutlined, SearchOutlined, DownloadOutlined,
  ClockCircleOutlined, CalendarOutlined, EditOutlined,
  DeleteOutlined, EyeOutlined, PlusOutlined,
  CheckCircleOutlined, WarningOutlined, ReloadOutlined,
  LeftOutlined, RightOutlined, DollarOutlined,
  RiseOutlined, SettingOutlined, HistoryOutlined,
  FileExcelOutlined, CalculatorOutlined, QuestionCircleOutlined,
  CheckOutlined, WalletOutlined, FilePdfOutlined,
  MinusCircleOutlined, SaveOutlined, DeleteOutlined as DeleteIcon,
  UndoOutlined, ThunderboltOutlined, PrinterOutlined,
  MailOutlined, FileTextOutlined
} from '@ant-design/icons';
import { payrollAPI, employeeAPI, departmentAPI, payslipAPI } from '../../../services/api';
import { message, Modal, Spin, Alert, Row, Col, Card, Avatar, Badge, Tag, Button, Input, Statistic, Divider, InputNumber, Switch, Tabs, Space, Tooltip, Checkbox, Dropdown } from 'antd';
import dayjs from 'dayjs';
import '../styles/StaffPayroll.css';

const { TextArea } = Input;
const { TabPane } = Tabs;

const safeApiArray = (response) => {
  if (Array.isArray(response)) return response;
  if (Array.isArray(response?.data)) return response.data;
  if (Array.isArray(response?.data?.data)) return response.data.data;
  if (Array.isArray(response?.data?.data?.data)) return response.data.data.data;
  if (Array.isArray(response?.data?.items)) return response.data.items;
  if (Array.isArray(response?.data?.data?.items)) return response.data.data.items;
  if (Array.isArray(response?.employees)) return response.employees;
  if (Array.isArray(response?.data?.employees)) return response.data.employees;
  if (Array.isArray(response?.data?.data?.employees)) return response.data.data.employees;
  return [];
};

const safeApiObject = (response, fallback = null) => {
  return response?.data?.data || response?.data || response || fallback;
};

// ==================== REACT QUERY KEYS ====================
const payrollQueryKeys = {
  all: ['payroll'],
  list: (params) => [...payrollQueryKeys.all, 'list', params],
  history: (params) => [...payrollQueryKeys.all, 'history', params],
  stats: (params) => [...payrollQueryKeys.all, 'stats', params],
  historyStats: (params) => [...payrollQueryKeys.all, 'historyStats', params],
  eligibleEmployees: (params) => [...payrollQueryKeys.all, 'eligibleEmployees', params],
  preview: (params) => [...payrollQueryKeys.all, 'preview', params],
  payslips: (params) => [...payrollQueryKeys.all, 'payslips', params],
  departments: () => ['departments'],
};

// ==================== REACT QUERY HOOKS ====================
const usePayrollList = (params) => {
  return useQuery({
    queryKey: payrollQueryKeys.list(params),
    queryFn: () => payrollAPI.getAll(params),
    staleTime: 2 * 60 * 1000,
    gcTime: 5 * 60 * 1000,
    refetchOnWindowFocus: false,
    enabled: !!params.start_date && !!params.end_date,
  });
};

const usePayrollHistory = (params) => {
  return useQuery({
    queryKey: payrollQueryKeys.history(params),
    queryFn: () => payrollAPI.getHistory(params),
    staleTime: 2 * 60 * 1000,
    gcTime: 5 * 60 * 1000,
    refetchOnWindowFocus: false,
    enabled: !!params.start_date && !!params.end_date,
  });
};

const usePayrollStats = (params) => {
  return useQuery({
    queryKey: payrollQueryKeys.stats(params),
    queryFn: () => payrollAPI.getStats(params),
    staleTime: 2 * 60 * 1000,
    gcTime: 5 * 60 * 1000,
    enabled: !!params.start_date && !!params.end_date,
  });
};

const usePayrollHistoryStats = (params) => {
  return useQuery({
    queryKey: payrollQueryKeys.historyStats(params),
    queryFn: () => payrollAPI.getHistoryStats(params),
    staleTime: 2 * 60 * 1000,
    gcTime: 5 * 60 * 1000,
    enabled: !!params.start_date && !!params.end_date,
  });
};

const useEligibleEmployees = (params) => {
  return useQuery({
    queryKey: payrollQueryKeys.eligibleEmployees(params),
    queryFn: () => employeeAPI.getEligibleForPayroll(params),
    staleTime: 1 * 60 * 1000,
    gcTime: 3 * 60 * 1000,
    enabled: !!params.start_date && !!params.end_date,
  });
};

const useDepartmentsList = () => {
  return useQuery({
    queryKey: payrollQueryKeys.departments(),
    queryFn: () => departmentAPI.getAll(),
    staleTime: 10 * 60 * 1000,
    gcTime: 30 * 60 * 1000,
  });
};

// ==================== MUTATIONS ====================
const useProcessPayroll = () => {
  const queryClient = useQueryClient();
  
  return useMutation({
    mutationFn: (data) => payrollAPI.processSelected(data),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: payrollQueryKeys.list() });
      queryClient.invalidateQueries({ queryKey: payrollQueryKeys.stats() });
      queryClient.invalidateQueries({ queryKey: payrollQueryKeys.eligibleEmployees() });
      queryClient.invalidateQueries({ queryKey: payrollQueryKeys.preview() });
      message.success('Payroll processed successfully');
    },
    onError: (error) => {
      message.error(error.response?.data?.message || 'Failed to process payroll');
    },
  });
};

const usePreviewPayroll = () => {
  return useMutation({
    mutationFn: (data) => payrollAPI.previewPayroll(data),
    onError: (error) => {
      message.error(error.response?.data?.message || 'Failed to preview payroll');
    },
  });
};

const useUpdatePayroll = () => {
  const queryClient = useQueryClient();
  
  return useMutation({
    mutationFn: ({ id, data }) => payrollAPI.update(id, data),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: payrollQueryKeys.list() });
      queryClient.invalidateQueries({ queryKey: payrollQueryKeys.stats() });
      message.success('Payroll updated successfully');
    },
    onError: (error) => {
      message.error(error.response?.data?.message || 'Failed to update payroll');
    },
  });
};

const useMarkAsPaid = () => {
  const queryClient = useQueryClient();
  
  return useMutation({
    mutationFn: ({ id, data }) => payrollAPI.markAsPaid(id, data),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: payrollQueryKeys.list() });
      queryClient.invalidateQueries({ queryKey: payrollQueryKeys.stats() });
      message.success('Payroll marked as paid successfully');
    },
    onError: (error) => {
      message.error(error.response?.data?.message || 'Failed to mark as paid');
    },
  });
};

const useDeletePayroll = () => {
  const queryClient = useQueryClient();
  
  return useMutation({
    mutationFn: (id) => payrollAPI.delete(id),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: payrollQueryKeys.list() });
      queryClient.invalidateQueries({ queryKey: payrollQueryKeys.stats() });
      queryClient.invalidateQueries({ queryKey: payrollQueryKeys.history() });
      queryClient.invalidateQueries({ queryKey: payrollQueryKeys.historyStats() });
      message.success('Payroll moved to history archive');
    },
    onError: (error) => {
      message.error(error.response?.data?.message || 'Failed to delete payroll');
    },
  });
};

const useRestorePayroll = () => {
  const queryClient = useQueryClient();
  
  return useMutation({
    mutationFn: (id) => payrollAPI.restore(id),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: payrollQueryKeys.list() });
      queryClient.invalidateQueries({ queryKey: payrollQueryKeys.stats() });
      queryClient.invalidateQueries({ queryKey: payrollQueryKeys.history() });
      queryClient.invalidateQueries({ queryKey: payrollQueryKeys.historyStats() });
      message.success('Payroll restored from history');
    },
    onError: (error) => {
      message.error(error.response?.data?.message || 'Failed to restore payroll');
    },
  });
};

const usePermanentDeletePayroll = () => {
  const queryClient = useQueryClient();
  
  return useMutation({
    mutationFn: (id) => payrollAPI.permanentDelete(id),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: payrollQueryKeys.history() });
      queryClient.invalidateQueries({ queryKey: payrollQueryKeys.historyStats() });
      message.success('Payroll permanently deleted');
    },
    onError: (error) => {
      message.error(error.response?.data?.message || 'Failed to permanently delete');
    },
  });
};

const useBulkUpdateDeductions = () => {
  const queryClient = useQueryClient();
  
  return useMutation({
    mutationFn: (data) => payrollAPI.bulkUpdateDeductions(data),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: payrollQueryKeys.list() });
      queryClient.invalidateQueries({ queryKey: payrollQueryKeys.stats() });
      message.success('Bulk deductions applied successfully');
    },
    onError: (error) => {
      message.error(error.response?.data?.message || 'Failed to apply bulk deductions');
    },
  });
};

// ==================== PAYSLIP MUTATIONS ====================
const useGeneratePayslip = () => {
  const queryClient = useQueryClient();
  
  return useMutation({
    mutationFn: (data) => payslipAPI.generate(data),
    onSuccess: (result) => {
      queryClient.invalidateQueries({ queryKey: payrollQueryKeys.payslips });
      message.success('Payslip generated successfully');
    },
    onError: (error) => {
      message.error(error.response?.data?.message || 'Failed to generate payslip');
    },
  });
};

const useBulkGeneratePayslips = () => {
  const queryClient = useQueryClient();
  
  return useMutation({
    mutationFn: (payrollIds) => payslipAPI.bulkGenerate(payrollIds),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: payrollQueryKeys.payslips });
      message.success('Payslips generated successfully');
    },
    onError: (error) => {
      message.error(error.response?.data?.message || 'Failed to generate payslips');
    },
  });
};

const useDownloadPayslip = () => {
  return useMutation({
    mutationFn: (id) => payslipAPI.download(id),
    onError: (error) => {
      message.error(error.response?.data?.message || 'Failed to download payslip');
    },
  });
};

const usePreviewPayslip = () => {
  return useMutation({
    mutationFn: (payrollId) => payslipAPI.preview(payrollId),
    onError: (error) => {
      message.error(error.response?.data?.message || 'Failed to preview payslip');
    },
  });
};

const Staff_Payroll_Formal = () => {
  const queryClient = useQueryClient();
  
  // Local state
  const [cutoffType, setCutoffType] = useState('first');
  const [selectedYear, setSelectedYear] = useState(new Date().getFullYear());
  const [selectedMonth, setSelectedMonth] = useState(new Date().getMonth());
  const [selectedFilter, setSelectedFilter] = useState('all');
  const [selectedDepartment, setSelectedDepartment] = useState('all');
  const [selectedStatus, setSelectedStatus] = useState('all');
  const [searchQuery, setSearchQuery] = useState('');
  const [currentPage, setCurrentPage] = useState(1);
  const [historyCurrentPage, setHistoryCurrentPage] = useState(1);
  const [activeTab, setActiveTab] = useState('active');
  const pageSize = 10;
  
  // Employee selection for payroll
  const [employeeSearchQuery, setEmployeeSearchQuery] = useState('');
  const [employeeDepartmentFilter, setEmployeeDepartmentFilter] = useState('all');
  const [selectedEmployeesForPayroll, setSelectedEmployeesForPayroll] = useState([]);
  const [selectAllEmployees, setSelectAllEmployees] = useState(false);
  
  // Bulk selection for payroll records
  const [selectedPayrollIds, setSelectedPayrollIds] = useState([]);
  
  // Modal states
  const [showPayrollModal, setShowPayrollModal] = useState(false);
  const [showEmployeeSelectionModal, setShowEmployeeSelectionModal] = useState(false);
  const [showPreviewModal, setShowPreviewModal] = useState(false);
  const [showEditDeductionsModal, setShowEditDeductionsModal] = useState(false);
  const [showHistoryDetailsModal, setShowHistoryDetailsModal] = useState(false);
  const [showBulkDeductionModal, setShowBulkDeductionModal] = useState(false);
  const [showPayslipModal, setShowPayslipModal] = useState(false);
  const [showPayslipPreviewModal, setShowPayslipPreviewModal] = useState(false);
  const [selectedEmployee, setSelectedEmployee] = useState(null);
  const [selectedPayrollForEdit, setSelectedPayrollForEdit] = useState(null);
  const [selectedHistoryItem, setSelectedHistoryItem] = useState(null);
  const [selectedPayslip, setSelectedPayslip] = useState(null);
  const [payslipPreview, setPayslipPreview] = useState(null);
  const [payrollPreview, setPayrollPreview] = useState(null);
  const [processNotes, setProcessNotes] = useState('');
  
  // Bulk deduction states
  const [bulkDeductionAmount, setBulkDeductionAmount] = useState(0);
  const [bulkDeductionReason, setBulkDeductionReason] = useState('');
  const [bulkDeductionType, setBulkDeductionType] = useState('other');
  const [bulkDeductionCategory, setBulkDeductionCategory] = useState('company');
  
  // Formal deduction states
  const [deductionType, setDeductionType] = useState('cash_advance');
  const [deductionCategory, setDeductionCategory] = useState('loan');
  const [deductionReference, setDeductionReference] = useState('');
  const [deductionDate, setDeductionDate] = useState(dayjs().format('YYYY-MM-DD'));
  const [deductionApprovedBy, setDeductionApprovedBy] = useState('');
  const [manualDeductionAmount, setManualDeductionAmount] = useState(0);
  const [manualDeductionReason, setManualDeductionReason] = useState('');
  const [enableManualDeduction, setEnableManualDeduction] = useState(false);
  const [processingAction, setProcessingAction] = useState(false);

  // Mutations
  const processPayrollMutation = useProcessPayroll();
  const previewPayrollMutation = usePreviewPayroll();
  const updatePayrollMutation = useUpdatePayroll();
  const markAsPaidMutation = useMarkAsPaid();
  const deletePayrollMutation = useDeletePayroll();
  const restorePayrollMutation = useRestorePayroll();
  const permanentDeleteMutation = usePermanentDeletePayroll();
  const bulkUpdateDeductionsMutation = useBulkUpdateDeductions();
  
  // Payslip mutations
  const generatePayslipMutation = useGeneratePayslip();
  const bulkGeneratePayslipsMutation = useBulkGeneratePayslips();
  const downloadPayslipMutation = useDownloadPayslip();
  const previewPayslipMutation = usePreviewPayslip();

  // Helper function to get cutoff dates
  const getCutoffDates = useCallback((year, month, cutoff) => {
    const startDate = new Date(year, month, 1);
    const endDate = new Date(year, month + 1, 0);
    
    if (cutoff === 'first') {
      return {
        start: new Date(year, month, 1),
        end: new Date(year, month, 15, 23, 59, 59),
        label: `${startDate.toLocaleString('default', { month: 'long' })} 1-15, ${year}`,
        shortLabel: '1st Cutoff'
      };
    } else {
      return {
        start: new Date(year, month, 16),
        end: new Date(year, month + 1, 0, 23, 59, 59),
        label: `${startDate.toLocaleString('default', { month: 'long' })} 16-${endDate.getDate()}, ${year}`,
        shortLabel: '2nd Cutoff'
      };
    }
  }, []);

  const currentCutoff = getCutoffDates(selectedYear, selectedMonth, cutoffType);
  const periodLabel = currentCutoff.label;

  // Safe render helper
  const safeRender = useCallback((value, fallback = '—') => {
    if (!value && value !== 0) return fallback;
    if (typeof value === 'string') return value;
    if (typeof value === 'number') return value.toString();
    if (typeof value === 'object') {
      if (value.name) return value.name;
      if (value.title) return value.title;
      return fallback;
    }
    return fallback;
  }, []);

  // Format currency
  const formatCurrency = useCallback((amount) => {
    if (!amount && amount !== 0) return '₱0.00';
    return new Intl.NumberFormat('en-PH', {
      style: 'currency',
      currency: 'PHP',
      minimumFractionDigits: 2
    }).format(amount);
  }, []);

  // Format date
  const formatDate = useCallback((dateString) => {
    if (!dateString) return '—';
    return dayjs(dateString).format('MMM DD, YYYY');
  }, []);

  // ==================== REACT QUERY DATA FETCHING ====================
  const payrollParams = useMemo(() => ({
    page: currentPage,
    per_page: pageSize,
    start_date: currentCutoff.start.toISOString().split('T')[0],
    end_date: currentCutoff.end.toISOString().split('T')[0],
    status: selectedStatus !== 'all' ? selectedStatus : undefined,
    department_id: selectedDepartment !== 'all' ? selectedDepartment : undefined,
    search: searchQuery || undefined
  }), [currentPage, currentCutoff, selectedStatus, selectedDepartment, searchQuery, pageSize]);

  const historyParams = useMemo(() => ({
    page: historyCurrentPage,
    per_page: pageSize,
    start_date: currentCutoff.start.toISOString().split('T')[0],
    end_date: currentCutoff.end.toISOString().split('T')[0],
    department_id: selectedDepartment !== 'all' ? selectedDepartment : undefined,
    search: searchQuery || undefined
  }), [historyCurrentPage, currentCutoff, selectedDepartment, searchQuery, pageSize]);

  const statsParams = useMemo(() => ({
    start_date: currentCutoff.start.toISOString().split('T')[0],
    end_date: currentCutoff.end.toISOString().split('T')[0]
  }), [currentCutoff]);

  const eligibleParams = useMemo(() => ({
    start_date: currentCutoff.start.toISOString().split('T')[0],
    end_date: currentCutoff.end.toISOString().split('T')[0],
    department_id: employeeDepartmentFilter !== 'all' ? employeeDepartmentFilter : undefined
  }), [currentCutoff, employeeDepartmentFilter]);

  // Queries
  const { data: payrollDataRes, isLoading: payrollLoading, refetch: refetchPayroll } = usePayrollList(payrollParams);
  const { data: historyDataRes, isLoading: historyLoading, refetch: refetchHistory } = usePayrollHistory(historyParams);
  const { data: statsRes, refetch: refetchStats } = usePayrollStats(statsParams);
  const { data: historyStatsRes, refetch: refetchHistoryStats } = usePayrollHistoryStats(statsParams);
  const { data: eligibleRes, refetch: refetchEligible } = useEligibleEmployees(eligibleParams);
  const { data: departmentsRes } = useDepartmentsList();

  // Extract data with proper nesting
  const payrollData = safeApiArray(payrollDataRes);
  const payrollMeta = safeApiObject(payrollDataRes, {});
  const totalPages = payrollDataRes?.data?.data?.last_page || payrollDataRes?.data?.last_page || payrollMeta?.last_page || 1;
  
  const payrollHistory = safeApiArray(historyDataRes);
  const historyMeta = safeApiObject(historyDataRes, {});
  const historyTotalPages = historyDataRes?.data?.data?.last_page || historyDataRes?.data?.last_page || historyMeta?.last_page || 1;
  
  const statistics = statsRes?.data?.data?.statistics || statsRes?.data?.statistics || statsRes?.data?.data || null;
  const historyStatistics = historyStatsRes?.data?.data?.statistics || historyStatsRes?.data?.statistics || historyStatsRes?.data?.data || null;
  
  const eligiblePayload = safeApiObject(eligibleRes, {});
  const eligibleEmployees = safeApiArray(eligiblePayload?.employees || eligibleRes);
  const eligibilitySummary = eligiblePayload?.summary || null;
  
  const departments = safeApiArray(departmentsRes);

  // Filter and paginate data
  const filteredData = useMemo(() => {
    if (!Array.isArray(payrollData)) return [];
    return payrollData.filter(item => {
      const deptMatch = selectedDepartment === 'all' || item.employee?.department_id == selectedDepartment;
      const statusMatch = selectedStatus === 'all' || item.status === selectedStatus;
      const filterMatch = selectedFilter === 'all' || item.status === selectedFilter;
      const searchMatch = !searchQuery || 
        (item.employee?.full_name && item.employee.full_name.toLowerCase().includes(searchQuery.toLowerCase())) ||
        (item.employee?.employee_id && item.employee.employee_id.toLowerCase().includes(searchQuery.toLowerCase())) ||
        (item.payroll_number && item.payroll_number.toLowerCase().includes(searchQuery.toLowerCase()));
      return deptMatch && statusMatch && filterMatch && searchMatch;
    });
  }, [payrollData, selectedDepartment, selectedStatus, selectedFilter, searchQuery]);

  const paginatedData = useMemo(() => {
    return filteredData.slice((currentPage - 1) * pageSize, currentPage * pageSize);
  }, [filteredData, currentPage, pageSize]);

  const filteredEligibleEmployees = useMemo(() => {
    return (eligibleEmployees || []).filter(emp => {
      if (employeeSearchQuery) {
        return (emp.full_name && emp.full_name.toLowerCase().includes(employeeSearchQuery.toLowerCase())) ||
               (emp.employee_id && emp.employee_id.toLowerCase().includes(employeeSearchQuery.toLowerCase()));
      }
      if (employeeDepartmentFilter !== 'all') {
        return emp.department_id == employeeDepartmentFilter;
      }
      return true;
    });
  }, [eligibleEmployees, employeeSearchQuery, employeeDepartmentFilter]);
  
  const isAllSelected = useMemo(() => {
    return filteredEligibleEmployees.length > 0 && 
      filteredEligibleEmployees.every(emp => selectedEmployeesForPayroll.includes(emp.id));
  }, [filteredEligibleEmployees, selectedEmployeesForPayroll]);

  // ==================== HANDLERS (ORDERED CORRECTLY) ====================
  
  // 1. Core handlers first (used by other handlers)
  const handleViewPayroll = useCallback((item) => {
    setSelectedEmployee(item);
    setShowPayrollModal(true);
  }, []);

  const handleEditDeductions = useCallback((payroll) => {
    setSelectedPayrollForEdit(payroll);
    setEnableManualDeduction((payroll.manual_deductions || 0) > 0);
    setManualDeductionAmount(payroll.manual_deductions || 0);
    setManualDeductionReason(payroll.manual_deduction_notes || '');
    setDeductionType(payroll.deduction_type || 'cash_advance');
    setDeductionCategory(payroll.deduction_category || 'loan');
    setDeductionReference(payroll.deduction_reference || '');
    setDeductionDate(payroll.deduction_date || dayjs().format('YYYY-MM-DD'));
    setDeductionApprovedBy(payroll.deduction_approved_by || '');
    setShowEditDeductionsModal(true);
  }, []);

  const handleMarkAsPaid = useCallback((payrollId) => {
    Modal.confirm({
      title: 'Confirm Payment',
      content: 'Are you sure you want to mark this payroll as paid?',
      okText: 'Confirm Payment',
      cancelText: 'Cancel',
      onOk: async () => {
        await markAsPaidMutation.mutateAsync({ id: payrollId, data: { payment_method: 'bank_transfer' } });
        setSelectedPayrollIds(prev => prev.filter(id => id !== payrollId));
      }
    });
  }, [markAsPaidMutation]);

  const handleDeletePayroll = useCallback((payrollId) => {
    Modal.confirm({
      title: 'Confirm Deletion',
      content: 'Are you sure you want to delete this payroll record? It will be moved to history.',
      okText: 'Move to History',
      cancelText: 'Cancel',
      okButtonProps: { danger: true },
      onOk: async () => {
        await deletePayrollMutation.mutateAsync(payrollId);
        setSelectedPayrollIds(prev => prev.filter(id => id !== payrollId));
      }
    });
  }, [deletePayrollMutation]);

  const handleRestorePayroll = useCallback((payrollId) => {
    Modal.confirm({
      title: 'Confirm Restoration',
      content: 'Are you sure you want to restore this payroll record?',
      okText: 'Restore',
      cancelText: 'Cancel',
      onOk: async () => {
        await restorePayrollMutation.mutateAsync(payrollId);
      }
    });
  }, [restorePayrollMutation]);

  const handlePermanentDelete = useCallback((payrollId) => {
    Modal.confirm({
      title: 'Confirm Permanent Deletion',
      content: 'Are you sure you want to permanently delete this payroll record? This action cannot be undone.',
      okText: 'Permanently Delete',
      cancelText: 'Cancel',
      okButtonProps: { danger: true },
      onOk: async () => {
        await permanentDeleteMutation.mutateAsync(payrollId);
      }
    });
  }, [permanentDeleteMutation]);

  // 2. Payslip handlers
  const handleGeneratePayslip = useCallback(async (payrollId) => {
    try {
      const result = await generatePayslipMutation.mutateAsync({ payroll_id: payrollId });
      if (result.data?.success) {
        message.success('Payslip generated successfully');
        setSelectedPayslip(result.data.data);
        setShowPayslipModal(true);
      }
    } catch (error) {
      console.error('Generate payslip error:', error);
    }
  }, [generatePayslipMutation]);

  const handleBulkGeneratePayslips = useCallback(async () => {
    if (selectedPayrollIds.length === 0) {
      message.warning('Please select payroll records first');
      return;
    }
    
    Modal.confirm({
      title: 'Bulk Generate Payslips',
      content: `Are you sure you want to generate payslips for ${selectedPayrollIds.length} payroll record(s)?`,
      okText: 'Generate',
      cancelText: 'Cancel',
      onOk: async () => {
        await bulkGeneratePayslipsMutation.mutateAsync(selectedPayrollIds);
        setSelectedPayrollIds([]);
      }
    });
  }, [selectedPayrollIds, bulkGeneratePayslipsMutation]);

  const handlePreviewPayslip = useCallback(async (payrollId) => {
    try {
      const result = await previewPayslipMutation.mutateAsync(payrollId);
      if (result.data?.success) {
        setPayslipPreview(result.data.data);
        setShowPayslipPreviewModal(true);
      }
    } catch (error) {
      console.error('Preview payslip error:', error);
    }
  }, [previewPayslipMutation]);

  const handleDownloadPayslip = useCallback(async (payslipId) => {
    try {
      const result = await downloadPayslipMutation.mutateAsync(payslipId);
      if (result.data?.success) {
        const pdfData = result.data.data;
        if (pdfData.pdf_url) {
          window.open(pdfData.pdf_url, '_blank');
        } else {
          message.success('Payslip ready for download');
        }
      }
    } catch (error) {
      console.error('Download payslip error:', error);
    }
  }, [downloadPayslipMutation]);

  // 3. Action menu (depends on above handlers)
  const getPayrollActionMenu = useCallback((item) => ({
    items: [
      {
        key: 'view',
        label: 'View Details',
        icon: <EyeOutlined />,
        onClick: () => handleViewPayroll(item)
      },
      {
        key: 'payslip',
        label: 'Generate Payslip',
        icon: <FilePdfOutlined />,
        onClick: () => handleGeneratePayslip(item.id)
      },
      {
        key: 'preview-payslip',
        label: 'Preview Payslip',
        icon: <FileTextOutlined />,
        onClick: () => handlePreviewPayslip(item.id)
      },
      {
        key: 'divider-1',
        type: 'divider'
      },
      {
        key: 'edit-deductions',
        label: 'Edit Deductions',
        icon: <CalculatorOutlined />,
        onClick: () => handleEditDeductions(item),
        disabled: item.status === 'paid'
      },
      {
        key: 'mark-paid',
        label: 'Mark as Paid',
        icon: <CheckOutlined />,
        onClick: () => handleMarkAsPaid(item.id),
        disabled: item.status === 'paid'
      },
      {
        key: 'divider-2',
        type: 'divider'
      },
      {
        key: 'delete',
        label: 'Move to History',
        icon: <DeleteOutlined />,
        danger: true,
        onClick: () => handleDeletePayroll(item.id),
        disabled: item.status === 'paid'
      }
    ]
  }), [handleViewPayroll, handleGeneratePayslip, handlePreviewPayslip, handleEditDeductions, handleMarkAsPaid, handleDeletePayroll]);

  // 4. Other handlers
  const handleRefresh = useCallback(async () => {
    await Promise.all([refetchPayroll(), refetchStats(), refetchHistory(), refetchHistoryStats(), refetchEligible()]);
    message.success('Data refreshed successfully');
  }, [refetchPayroll, refetchStats, refetchHistory, refetchHistoryStats, refetchEligible]);

  const handleExport = useCallback(async () => {
    try {
      const response = await payrollAPI.export({
        start_date: currentCutoff.start.toISOString().split('T')[0],
        end_date: currentCutoff.end.toISOString().split('T')[0]
      });
      
      const url = window.URL.createObjectURL(new Blob([response.data]));
      const link = document.createElement('a');
      link.href = url;
      link.setAttribute('download', `payroll_${currentCutoff.label.replace(/[^a-z0-9]/gi, '_')}.csv`);
      document.body.appendChild(link);
      link.click();
      link.remove();
      window.URL.revokeObjectURL(url);
      message.success('Export started');
    } catch (error) {
      message.error('Failed to export payroll');
    }
  }, [currentCutoff]);

  const changeCutoff = useCallback((direction) => {
    if (direction === 'prev') {
      if (cutoffType === 'first') {
        setCutoffType('second');
        let newMonth = selectedMonth - 1;
        let newYear = selectedYear;
        if (newMonth < 0) {
          newMonth = 11;
          newYear--;
        }
        setSelectedMonth(newMonth);
        setSelectedYear(newYear);
      } else {
        setCutoffType('first');
      }
    } else {
      if (cutoffType === 'second') {
        setCutoffType('first');
        let newMonth = selectedMonth + 1;
        let newYear = selectedYear;
        if (newMonth > 11) {
          newMonth = 0;
          newYear++;
        }
        setSelectedMonth(newMonth);
        setSelectedYear(newYear);
      } else {
        setCutoffType('second');
      }
    }
    setCurrentPage(1);
    setHistoryCurrentPage(1);
    setSelectedPayrollIds([]);
  }, [cutoffType, selectedMonth, selectedYear]);

  const goToCurrentPeriod = useCallback(() => {
    const now = new Date();
    setSelectedYear(now.getFullYear());
    setSelectedMonth(now.getMonth());
    setCutoffType(now.getDate() <= 15 ? 'first' : 'second');
    setCurrentPage(1);
    setHistoryCurrentPage(1);
    setSelectedPayrollIds([]);
  }, []);

  const openEmployeeSelectionModal = useCallback(() => {
    setEmployeeSearchQuery('');
    setEmployeeDepartmentFilter('all');
    setSelectedEmployeesForPayroll([]);
    setSelectAllEmployees(false);
    setShowEmployeeSelectionModal(true);
  }, []);

  const handleProcessPayroll = useCallback(async () => {
    if (selectedEmployeesForPayroll.length === 0) {
      message.warning('Please select at least one employee');
      return;
    }
    
    const data = {
      period_start: currentCutoff.start.toISOString().split('T')[0],
      period_end: currentCutoff.end.toISOString().split('T')[0],
      employee_ids: selectedEmployeesForPayroll,
      notes: processNotes
    };
    
    const result = await processPayrollMutation.mutateAsync(data);
    if (result.data?.success) {
      setShowEmployeeSelectionModal(false);
      setSelectedEmployeesForPayroll([]);
      setSelectAllEmployees(false);
      setProcessNotes('');
      await refetchPayroll();
      await refetchStats();
    }
  }, [selectedEmployeesForPayroll, currentCutoff, processNotes, processPayrollMutation, refetchPayroll, refetchStats]);

  const handlePreviewPayroll = useCallback(async () => {
    if (selectedEmployeesForPayroll.length === 0) {
      message.warning('Please select at least one employee');
      return;
    }
    
    const data = {
      employee_ids: selectedEmployeesForPayroll,
      period_start: currentCutoff.start.toISOString().split('T')[0],
      period_end: currentCutoff.end.toISOString().split('T')[0]
    };
    
    const result = await previewPayrollMutation.mutateAsync(data);
    if (result.data?.success) {
      setPayrollPreview(result.data.data);
      setShowPreviewModal(true);
    }
  }, [selectedEmployeesForPayroll, currentCutoff, previewPayrollMutation]);

  const resetDeductionForm = useCallback(() => {
    setEnableManualDeduction(false);
    setManualDeductionAmount(0);
    setManualDeductionReason('');
    setDeductionType('cash_advance');
    setDeductionCategory('loan');
    setDeductionReference('');
    setDeductionDate(dayjs().format('YYYY-MM-DD'));
    setDeductionApprovedBy('');
    setSelectedPayrollForEdit(null);
  }, []);

  const handleSaveManualDeductions = useCallback(async () => {
    if (!selectedPayrollForEdit) return;
    
    const deductionAmount = enableManualDeduction ? manualDeductionAmount : 0;
    
    const data = {
      manual_deductions: deductionAmount,
      manual_deduction_notes: manualDeductionReason,
      deduction_type: deductionType,
      deduction_category: deductionCategory,
      deduction_reference: deductionReference,
      deduction_date: deductionDate,
      deduction_approved_by: deductionApprovedBy || 'System Administrator',
      deduction_status: 'approved'
    };
    
    await updatePayrollMutation.mutateAsync({ id: selectedPayrollForEdit.id, data });
    setShowEditDeductionsModal(false);
    resetDeductionForm();
  }, [selectedPayrollForEdit, enableManualDeduction, manualDeductionAmount, manualDeductionReason, deductionType, deductionCategory, deductionReference, deductionDate, deductionApprovedBy, updatePayrollMutation, resetDeductionForm]);

  const handleBulkMarkAsPaid = useCallback(() => {
    if (selectedPayrollIds.length === 0) {
      message.warning('Please select payroll records first');
      return;
    }
    
    Modal.confirm({
      title: 'Bulk Mark as Paid',
      content: `Are you sure you want to mark ${selectedPayrollIds.length} payroll record(s) as paid?`,
      okText: 'Confirm',
      cancelText: 'Cancel',
      onOk: async () => {
        setProcessingAction(true);
        try {
          for (const id of selectedPayrollIds) {
            await markAsPaidMutation.mutateAsync({ id, data: { payment_method: 'bank_transfer' } });
          }
          message.success(`${selectedPayrollIds.length} record(s) marked as paid`);
          setSelectedPayrollIds([]);
          await refetchPayroll();
          await refetchStats();
        } catch (error) {
          message.error('Failed to mark some records as paid');
        } finally {
          setProcessingAction(false);
        }
      }
    });
  }, [selectedPayrollIds, markAsPaidMutation, refetchPayroll, refetchStats]);

  const handleBulkDeductions = useCallback(async () => {
    if (selectedPayrollIds.length === 0) {
      message.warning('Please select payroll records first');
      return;
    }
    
    if (bulkDeductionAmount <= 0) {
      message.warning('Please enter a valid deduction amount');
      return;
    }
    
    if (!bulkDeductionReason.trim()) {
      message.warning('Please provide a reason for the deduction');
      return;
    }
    
    const data = {
      payroll_ids: selectedPayrollIds,
      manual_deductions: bulkDeductionAmount,
      manual_deduction_notes: bulkDeductionReason,
      deduction_type: bulkDeductionType,
      deduction_category: bulkDeductionCategory,
      deduction_approved_by: 'System Administrator',
      deduction_date: dayjs().format('YYYY-MM-DD')
    };
    
    await bulkUpdateDeductionsMutation.mutateAsync(data);
    setShowBulkDeductionModal(false);
    setSelectedPayrollIds([]);
    setBulkDeductionAmount(0);
    setBulkDeductionReason('');
    setBulkDeductionType('other');
    setBulkDeductionCategory('company');
    await refetchPayroll();
    await refetchStats();
  }, [selectedPayrollIds, bulkDeductionAmount, bulkDeductionReason, bulkDeductionType, bulkDeductionCategory, bulkUpdateDeductionsMutation, refetchPayroll, refetchStats]);

  const handleViewHistory = useCallback((item) => {
    setSelectedHistoryItem(item);
    setShowHistoryDetailsModal(true);
  }, []);

  const handleTabChange = useCallback((key) => {
    setActiveTab(key);
    setSelectedPayrollIds([]);
  }, []);

  const handleSelectAllEmployees = useCallback(() => {
    if (selectAllEmployees) {
      setSelectedEmployeesForPayroll([]);
    } else {
      setSelectedEmployeesForPayroll(filteredEligibleEmployees.map(emp => emp.id));
    }
    setSelectAllEmployees(!selectAllEmployees);
  }, [selectAllEmployees, filteredEligibleEmployees]);

  const handleSelectEmployee = useCallback((employeeId) => {
    setSelectedEmployeesForPayroll(prev => 
      prev.includes(employeeId) 
        ? prev.filter(id => id !== employeeId)
        : [...prev, employeeId]
    );
  }, []);

  const handleSelectPayroll = useCallback((payrollId) => {
    setSelectedPayrollIds(prev => 
      prev.includes(payrollId) 
        ? prev.filter(id => id !== payrollId)
        : [...prev, payrollId]
    );
  }, []);

  const handleSelectAllPayroll = useCallback(() => {
    if (selectedPayrollIds.length === paginatedData.length) {
      setSelectedPayrollIds([]);
    } else {
      setSelectedPayrollIds(paginatedData.map(item => item.id));
    }
  }, [selectedPayrollIds, paginatedData]);

  // Update selectAllEmployees when selection changes
  useEffect(() => {
    setSelectAllEmployees(isAllSelected);
  }, [isAllSelected]);

  // Global refresh function
  useEffect(() => {
    window.refreshPayrollData = () => {
      refetchPayroll();
      refetchStats();
      message.info('Payroll data refreshed', 1.5);
    };
    return () => { delete window.refreshPayrollData; };
  }, [refetchPayroll, refetchStats]);

  const isLoading = payrollLoading || historyLoading;

  // Calculate summary values for display
  const totalPayrollAmount = useMemo(() => {
    return paginatedData.reduce((sum, item) => sum + (item.net_pay || 0), 0);
  }, [paginatedData]);

  return (
    <div className="prf-payroll-container">
      {/* Header */}
      <header className="prf-header">
        <div className="prf-header-left">
          <div className="prf-logo">
            <div className="prf-logo-icon"><DollarOutlined /></div>
            <div className="prf-logo-text">
              <h1>Payroll Management</h1>
              <span>Enterprise Compensation System</span>
            </div>
          </div>
          <nav className="prf-nav">
            <button className="prf-nav-item active"><CalculatorOutlined /> Dashboard</button>
            <button className="prf-nav-item"><HistoryOutlined /> History</button>
          </nav>
        </div>
        <div className="prf-header-right">
          <Tooltip title="Refresh Data">
            <button className="prf-notification-btn" onClick={handleRefresh}><ReloadOutlined /></button>
          </Tooltip>
          <Tooltip title="Help">
            <button className="prf-help-btn"><QuestionCircleOutlined /></button>
          </Tooltip>
        </div>
      </header>

      <main className="prf-main">
        <div className="prf-fixed-top">
          {/* Cutoff Period Navigator */}
          <div className="prf-period-nav">
            <div className="prf-period-left">
              <div className="prf-cutoff-selector">
                <button className={`prf-cutoff-btn ${cutoffType === 'first' ? 'active' : ''}`} onClick={() => setCutoffType('first')}>
                  <span className="cutoff-number">1ˢᵗ</span>
                  <span className="cutoff-range">- 15ᵗʰ</span>
                </button>
                <button className={`prf-cutoff-btn ${cutoffType === 'second' ? 'active' : ''}`} onClick={() => setCutoffType('second')}>
                  <span className="cutoff-number">16ᵗʰ</span>
                  <span className="cutoff-range">- End</span>
                </button>
              </div>
              <div className="prf-period-picker"><CalendarOutlined /><span>{periodLabel}</span></div>
              <div className="prf-period-buttons">
                <button className="prf-period-btn" onClick={() => changeCutoff('prev')}><LeftOutlined /></button>
                <button className="prf-period-btn" onClick={() => changeCutoff('next')}><RightOutlined /></button>
              </div>
              <button className="prf-period-current" onClick={goToCurrentPeriod}>Current Period</button>
            </div>
            <div className="prf-period-right">
              <Button type="primary" icon={<CalculatorOutlined />} onClick={openEmployeeSelectionModal}>
                Process Payroll
              </Button>
              <Button icon={<DownloadOutlined />} onClick={handleExport}>
                Export
              </Button>
              <Button icon={<ReloadOutlined />} onClick={handleRefresh}>
                Refresh
              </Button>
            </div>
          </div>

          {/* Tabs */}
          <Tabs activeKey={activeTab} onChange={handleTabChange} className="prf-payroll-tabs">
            <TabPane tab={<span><DollarOutlined /> Active Payroll</span>} key="active">
              {/* Summary Stats for Active */}
              {statistics && (
                <div className="prf-stats-grid">
                  <div className="prf-stat-card">
                    <div className="prf-stat-icon" style={{ background: '#e6f7e6' }}><DollarOutlined /></div>
                    <div className="prf-stat-content">
                      <span className="prf-stat-label">Total Payroll</span>
                      <h3 className="prf-stat-value">{formatCurrency(statistics.total_payroll_amount || totalPayrollAmount)}</h3>
                      <div className="prf-stat-footer"><span className="prf-stat-trend"><RiseOutlined /> {statistics.paid_count || 0} paid</span></div>
                    </div>
                  </div>
                  <div className="prf-stat-card">
                    <div className="prf-stat-icon" style={{ background: '#e6f0ff' }}><TeamOutlined /></div>
                    <div className="prf-stat-content">
                      <span className="prf-stat-label">Active Employees</span>
                      <h3 className="prf-stat-value">{statistics.total_employees || 0}</h3>
                      <div className="prf-stat-footer"><span className="prf-stat-trend">{statistics.employees_with_payroll || 0} processed</span></div>
                    </div>
                  </div>
                  <div className="prf-stat-card">
                    <div className="prf-stat-icon" style={{ background: '#fff3e6' }}><WalletOutlined /></div>
                    <div className="prf-stat-content">
                      <span className="prf-stat-label">Average Net Pay</span>
                      <h3 className="prf-stat-value">{formatCurrency(statistics.average_net_pay || 0)}</h3>
                      <div className="prf-stat-footer"><span className="prf-stat-trend">per employee</span></div>
                    </div>
                  </div>
                  <div className="prf-stat-card">
                    <div className="prf-stat-icon" style={{ background: '#f0e6ff' }}><ClockCircleOutlined /></div>
                    <div className="prf-stat-content">
                      <span className="prf-stat-label">Pending Amount</span>
                      <h3 className="prf-stat-value">{formatCurrency(statistics.pending_amount || 0)}</h3>
                      <div className="prf-stat-footer"><span className="prf-stat-trend">{statistics.pending_count || 0} employees</span></div>
                    </div>
                  </div>
                </div>
              )}
            </TabPane>
            <TabPane tab={<span><HistoryOutlined /> History Archive</span>} key="history">
              {/* Summary Stats for History */}
              {historyStatistics && (
                <div className="prf-stats-grid">
                  <div className="prf-stat-card">
                    <div className="prf-stat-icon" style={{ background: '#e6f7e6' }}><DeleteIcon /></div>
                    <div className="prf-stat-content">
                      <span className="prf-stat-label">Deleted Records</span>
                      <h3 className="prf-stat-value">{historyStatistics.total_deleted || 0}</h3>
                      <div className="prf-stat-footer"><span className="prf-stat-trend">in archive</span></div>
                    </div>
                  </div>
                  <div className="prf-stat-card">
                    <div className="prf-stat-icon" style={{ background: '#e6f0ff' }}><DollarOutlined /></div>
                    <div className="prf-stat-content">
                      <span className="prf-stat-label">Deleted Amount</span>
                      <h3 className="prf-stat-value">{formatCurrency(historyStatistics.total_deleted_amount || 0)}</h3>
                      <div className="prf-stat-footer"><span className="prf-stat-trend">total value</span></div>
                    </div>
                  </div>
                  <div className="prf-stat-card">
                    <div className="prf-stat-icon" style={{ background: '#fff3e6' }}><TeamOutlined /></div>
                    <div className="prf-stat-content">
                      <span className="prf-stat-label">Affected Employees</span>
                      <h3 className="prf-stat-value">{historyStatistics.affected_employees || 0}</h3>
                      <div className="prf-stat-footer"><span className="prf-stat-trend">unique employees</span></div>
                    </div>
                  </div>
                  <div className="prf-stat-card">
                    <div className="prf-stat-icon" style={{ background: '#f0e6ff' }}><ClockCircleOutlined /></div>
                    <div className="prf-stat-content">
                      <span className="prf-stat-label">Deleted This Period</span>
                      <h3 className="prf-stat-value">{historyStatistics.deleted_this_period || 0}</h3>
                      <div className="prf-stat-footer"><span className="prf-stat-trend">current cutoff</span></div>
                    </div>
                  </div>
                </div>
              )}
            </TabPane>
          </Tabs>

          {/* Filters */}
          {activeTab === 'active' && (
            <div className="prf-filters">
              <div className="prf-filter-tabs">
                <button className={`prf-filter-tab ${selectedFilter === 'all' ? 'active' : ''}`} onClick={() => setSelectedFilter('all')}>All</button>
                <button className={`prf-filter-tab ${selectedFilter === 'paid' ? 'active' : ''}`} onClick={() => setSelectedFilter('paid')}>Paid</button>
                <button className={`prf-filter-tab ${selectedFilter === 'pending' ? 'active' : ''}`} onClick={() => setSelectedFilter('pending')}>Pending</button>
              </div>
              <div className="prf-filter-controls">
                <select className="prf-filter-select" value={selectedDepartment} onChange={(e) => setSelectedDepartment(e.target.value)}>
                  <option value="all">All Departments</option>
                  {departments.map(dept => (<option key={dept.id} value={dept.id}>{safeRender(dept.name)}</option>))}
                </select>
                <select className="prf-filter-select" value={selectedStatus} onChange={(e) => setSelectedStatus(e.target.value)}>
                  <option value="all">All Status</option>
                  <option value="paid">Paid</option>
                  <option value="pending">Pending</option>
                </select>
                <div className="prf-filter-search">
                  <SearchOutlined className="prf-search-icon" />
                  <input type="text" className="prf-search-input" placeholder="Search..." value={searchQuery} onChange={(e) => setSearchQuery(e.target.value)} />
                </div>
                <div className="prf-filter-badge"><ReloadOutlined /> <span>{filteredData.length} records</span></div>
              </div>
            </div>
          )}
        </div>

        {/* Bulk Actions Bar */}
        {activeTab === 'active' && selectedPayrollIds.length > 0 && (
          <div className="prf-bulk-actions-bar">
            <span><ThunderboltOutlined /> {selectedPayrollIds.length} record(s) selected</span>
            <Space>
              <Button 
                type="primary" 
                danger 
                icon={<MinusCircleOutlined />}
                onClick={() => setShowBulkDeductionModal(true)}
                loading={processingAction}
              >
                Bulk Add Deduction
              </Button>
              <Button 
                type="primary" 
                icon={<FilePdfOutlined />}
                onClick={handleBulkGeneratePayslips}
                loading={bulkGeneratePayslipsMutation.isPending}
              >
                Bulk Generate Payslips
              </Button>
              <Button 
                type="primary" 
                icon={<CheckOutlined />}
                onClick={handleBulkMarkAsPaid}
                loading={processingAction}
              >
                Bulk Mark as Paid
              </Button>
              <Button onClick={() => setSelectedPayrollIds([])}>
                Clear Selection
              </Button>
            </Space>
          </div>
        )}

        {/* Payroll Table - Active */}
        {activeTab === 'active' && (
          <div className="prf-scrollable">
            <div className="prf-table-card">
              <div className="prf-table-header">
                <h3 className="prf-table-title"><DollarOutlined /> Payroll Register - {periodLabel}</h3>
                <div className="prf-table-actions">
                  <Button icon={<FileExcelOutlined />} onClick={handleExport}>Excel</Button>
                  <Button type="primary" icon={<PlusOutlined />} onClick={openEmployeeSelectionModal}>Process Payroll</Button>
                </div>
              </div>
              <div className="prf-table-wrapper">
                {payrollLoading ? (
                  <div className="prf-loading"><Spin /><span>Loading...</span></div>
                ) : (
                  <table className="prf-table">
                    <thead>
                      <tr>
                        <th style={{ width: '40px' }}>
                          <input 
                            type="checkbox" 
                            checked={selectedPayrollIds.length === paginatedData.length && paginatedData.length > 0}
                            onChange={handleSelectAllPayroll}
                          />
                        </th>
                        <th>Payroll #</th>
                        <th>Employee</th>
                        <th>Position</th>
                        <th>Regular Hrs</th>
                        <th>OT Hrs</th>
                        <th>Gross Pay</th>
                        <th>Deductions</th>
                        <th>Manual Ded.</th>
                        <th>Net Pay</th>
                        <th>Status</th>
                        <th>Payslip</th>
                        <th>Actions</th>
                      </tr>
                    </thead>
                    <tbody>
                      {paginatedData.map((item) => (
                        <tr key={item.id}>
                          <td>
                            <input 
                              type="checkbox" 
                              checked={selectedPayrollIds.includes(item.id)}
                              onChange={() => handleSelectPayroll(item.id)}
                              disabled={item.status === 'paid'}
                            />
                          </td>
                          <td>{item.payroll_number}</td>
                          <td>
                            <div className="prf-employee-cell">
                              <div className="prf-employee-avatar">{safeRender(item.employee?.full_name, '?').charAt(0)}</div>
                              <div>
                                <div className="prf-employee-name">{safeRender(item.employee?.full_name)}</div>
                                <div className="prf-employee-id">{safeRender(item.employee?.employee_code || item.employee?.employee_id)}</div>
                              </div>
                            </div>
                          </td>
                          <td>
                            <div>{safeRender(item.employee?.position)}</div>
                            <div className="prf-position-dept">{safeRender(item.employee?.department)}</div>
                          </td>
                          <td>{item.regular_hours || 0}h</td>
                          <td className={item.overtime_hours > 0 ? 'prf-text-warning' : ''}>{item.overtime_hours > 0 ? `${item.overtime_hours}h` : '—'}</td>
                          <td className="prf-text-positive">{formatCurrency(item.gross_pay || (item.regular_pay || 0) + (item.overtime_pay || 0))}</td>
                          <td className="prf-text-negative">{formatCurrency(item.total_deductions)}</td>
                          <td>{item.manual_deductions > 0 ? formatCurrency(item.manual_deductions) : '—'}</td>
                          <td className="prf-text-primary">{formatCurrency(item.net_pay)}</td>
                          <td><span className={`prf-status-badge prf-status-${item.status}`}>{item.status}</span></td>
                          <td>
                            <Tooltip title="Generate/View Payslip">
                              <Button 
                                type="link" 
                                icon={<FilePdfOutlined />} 
                                onClick={() => handleGeneratePayslip(item.id)}
                                style={{ color: '#ff4d4f' }}
                              />
                            </Tooltip>
                            </td>
                          <td>
                            <Dropdown menu={getPayrollActionMenu(item)} trigger={['click']}>
                              <Button type="link" icon={<SettingOutlined />} />
                            </Dropdown>
                            </td>
                        </tr>
                      ))}
                      {paginatedData.length === 0 && (
                        <tr><td colSpan="13" style={{ textAlign: 'center', padding: 40 }}>No payroll records found for {periodLabel}</td></tr>
                      )}
                    </tbody>
                  </table>
                )}
              </div>
              {totalPages > 1 && (
                <div className="prf-pagination">
                  <button className="prf-page-btn" onClick={() => setCurrentPage(p => Math.max(1, p - 1))} disabled={currentPage === 1}><LeftOutlined /></button>
                  <span>Page {currentPage} of {totalPages}</span>
                  <button className="prf-page-btn" onClick={() => setCurrentPage(p => Math.min(totalPages, p + 1))} disabled={currentPage === totalPages}><RightOutlined /></button>
                </div>
              )}
            </div>
          </div>
        )}

        {/* History Table */}
        {activeTab === 'history' && (
          <div className="prf-scrollable">
            <div className="prf-table-card">
              <div className="prf-table-header">
                <h3 className="prf-table-title"><HistoryOutlined /> Payroll History Archive</h3>
                <div className="prf-table-actions">
                  <Button icon={<FileExcelOutlined />} onClick={handleExport}>Export Archive</Button>
                </div>
              </div>
              <div className="prf-table-wrapper">
                {historyLoading ? (
                  <div className="prf-loading"><Spin /><span>Loading history...</span></div>
                ) : (
                  <table className="prf-table">
                    <thead>
                      <tr>
                        <th>Payroll #</th>
                        <th>Employee</th>
                        <th>Period</th>
                        <th>Gross Pay</th>
                        <th>Deductions</th>
                        <th>Net Pay</th>
                        <th>Deleted By</th>
                        <th>Deleted At</th>
                        <th>Actions</th>
                      </tr>
                    </thead>
                    <tbody>
                      {payrollHistory.map((item) => (
                        <tr key={item.id}>
                          <td>{item.payroll_number}</td>
                          <td>
                            <div className="prf-employee-cell">
                              <div className="prf-employee-avatar">{safeRender(item.employee?.full_name, '?').charAt(0)}</div>
                              <div>
                                <div className="prf-employee-name">{safeRender(item.employee?.full_name)}</div>
                                <div className="prf-employee-id">{safeRender(item.employee?.employee_code || item.employee?.employee_id)}</div>
                              </div>
                            </div>
                          </td>
                          <td>
                            <div>{formatDate(item.period_start)} - {formatDate(item.period_end)}</div>
                            <div className="prf-position-dept">{item.cutoff_type || (item.period_start && dayjs(item.period_start).date() <= 15 ? '1st Cutoff' : '2nd Cutoff')}</div>
                          </td>
                          <td className="prf-text-positive">{formatCurrency(item.gross_pay || (item.regular_pay || 0) + (item.overtime_pay || 0))}</td>
                          <td className="prf-text-negative">{formatCurrency(item.total_deductions)}</td>
                          <td className="prf-text-primary">{formatCurrency(item.net_pay)}</td>
                          <td>{item.deleted_by || 'System'}</td>
                          <td>{formatDate(item.deleted_at)}</td>
                          <td>
                            <div className="prf-action-buttons">
                              <Tooltip title="View Details">
                                <button className="prf-action-btn" onClick={() => handleViewHistory(item)}><EyeOutlined /></button>
                              </Tooltip>
                              <Tooltip title="Restore">
                                <button className="prf-action-btn prf-action-success" onClick={() => handleRestorePayroll(item.id)}><UndoOutlined /></button>
                              </Tooltip>
                              <Tooltip title="Permanently Delete">
                                <button className="prf-action-btn prf-action-danger" onClick={() => handlePermanentDelete(item.id)}><DeleteIcon /></button>
                              </Tooltip>
                            </div>
                          </td>
                        </tr>
                      ))}
                      {payrollHistory.length === 0 && (
                        <tr><td colSpan="9" style={{ textAlign: 'center', padding: 40 }}>No deleted payroll records found</td></tr>
                      )}
                    </tbody>
                  </table>
                )}
              </div>
              {historyTotalPages > 1 && (
                <div className="prf-pagination">
                  <button className="prf-page-btn" onClick={() => setHistoryCurrentPage(p => Math.max(1, p - 1))} disabled={historyCurrentPage === 1}><LeftOutlined /></button>
                  <span>Page {historyCurrentPage} of {historyTotalPages}</span>
                  <button className="prf-page-btn" onClick={() => setHistoryCurrentPage(p => Math.min(historyTotalPages, p + 1))} disabled={historyCurrentPage === historyTotalPages}><RightOutlined /></button>
                </div>
              )}
            </div>
          </div>
        )}
      </main>

      {/* Employee Selection Modal */}
      <Modal 
        title={`Select Employees - ${periodLabel}`} 
        open={showEmployeeSelectionModal} 
        onCancel={() => setShowEmployeeSelectionModal(false)} 
        width={900}
        footer={[
          <Button key="cancel" onClick={() => setShowEmployeeSelectionModal(false)}>Cancel</Button>,
          <Button key="preview" loading={previewPayrollMutation.isPending} onClick={handlePreviewPayroll} icon={<EyeOutlined />}>Preview</Button>,
          <Button key="process" type="primary" loading={processPayrollMutation.isPending} onClick={handleProcessPayroll} icon={<CalculatorOutlined />}>Process Payroll</Button>
        ]}
      >
        <Alert message="Payroll Period" description={periodLabel} type="info" showIcon style={{ marginBottom: 16 }} />
        {eligibilitySummary && (
          <Row gutter={16} style={{ marginBottom: 16 }}>
            <Col span={8}><Card size="small"><Statistic title="Total Employees" value={eligibilitySummary.total_employees || 0} /></Card></Col>
            <Col span={8}><Card size="small"><Statistic title="Eligible" value={eligibilitySummary.eligible_count || 0} valueStyle={{ color: '#3f8600' }} /></Card></Col>
            <Col span={8}><Card size="small"><Statistic title="Already Processed" value={eligibilitySummary.has_payroll_count || 0} valueStyle={{ color: '#cf1322' }} /></Card></Col>
          </Row>
        )}
        <div className="prf-selection-filters">
          <Input 
            placeholder="Search employee..." 
            value={employeeSearchQuery} 
            onChange={(e) => setEmployeeSearchQuery(e.target.value)} 
            prefix={<SearchOutlined />}
            style={{ width: 250, marginRight: 12 }}
          />
          <select 
            value={employeeDepartmentFilter} 
            onChange={(e) => setEmployeeDepartmentFilter(e.target.value)}
            style={{ marginRight: 12, padding: '4px 8px' }}
          >
            <option value="all">All Departments</option>
            {departments.map(dept => (<option key={dept.id} value={dept.id}>{safeRender(dept.name)}</option>))}
          </select>
          <Button size="small" onClick={handleSelectAllEmployees}>{selectAllEmployees ? 'Deselect All' : 'Select All'}</Button>
          <span style={{ marginLeft: 12 }}>{selectedEmployeesForPayroll.length} selected</span>
        </div>
        <div className="prf-selection-table-wrapper">
          <table className="prf-selection-table">
            <thead>
              <tr>
                <th style={{ width: 40 }}><Checkbox checked={isAllSelected} indeterminate={selectedEmployeesForPayroll.length > 0 && !isAllSelected} onChange={handleSelectAllEmployees} /></th>
                <th>Employee</th>
                <th>Department</th>
                <th>Position</th>
                <th>Hourly Rate</th>
                <th>Attendance</th>
                <th>Status</th>
              </tr>
            </thead>
            <tbody>
              {filteredEligibleEmployees.map((emp) => (
                <tr key={emp.id}>
                  <td>
                    <Checkbox 
                      checked={selectedEmployeesForPayroll.includes(emp.id)} 
                      onChange={() => handleSelectEmployee(emp.id)} 
                      disabled={!emp.eligible} 
                    />
                  </td>
                  <td>
                    <div className="prf-employee-cell">
                      <Avatar size={32}>{safeRender(emp.full_name, '?').charAt(0)}</Avatar>
                      <div>
                        <div>{safeRender(emp.full_name)}</div>
                        <div style={{ fontSize: 12, color: '#999' }}>{safeRender(emp.employee_id)}</div>
                      </div>
                    </div>
                  </td>
                  <td>{safeRender(emp.department)}</td>
                  <td>{safeRender(emp.position)}</td>
                  <td>{formatCurrency(emp.hourly_rate || 0)}/hr</td>
                  <td>
                    <Badge 
                      status={emp.has_attendance ? 'success' : 'warning'} 
                      text={emp.has_attendance ? 'Has Attendance' : 'No Attendance'} 
                    />
                  </td>
                  <td>
                    {emp.has_payroll ? 
                      <Tag color="orange">Processed</Tag> : 
                      emp.has_attendance ? 
                        <Tag color="green">Eligible</Tag> : 
                        <Tag color="red">Not Eligible</Tag>
                    }
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
        <div className="prf-selection-notes">
          <label>Notes (Optional)</label>
          <TextArea rows={2} placeholder="Add notes for this payroll batch..." value={processNotes} onChange={(e) => setProcessNotes(e.target.value)} />
        </div>
      </Modal>

      {/* Preview Modal */}
      <Modal 
        title={`Payroll Preview - ${periodLabel}`} 
        open={showPreviewModal} 
        onCancel={() => setShowPreviewModal(false)} 
        width={900}
        footer={[
          <Button key="cancel" onClick={() => setShowPreviewModal(false)}>Cancel</Button>,
          <Button key="process" type="primary" loading={processPayrollMutation.isPending} onClick={handleProcessPayroll} icon={<CheckOutlined />}>Confirm & Process</Button>
        ]}
      >
        {payrollPreview && (
          <div>
            <Row gutter={16} style={{ marginBottom: 16 }}>
              <Col span={6}><Card size="small"><Statistic title="Total Employees" value={payrollPreview.summary?.total_employees || 0} /></Card></Col>
              <Col span={6}><Card size="small"><Statistic title="Total Regular Hours" value={payrollPreview.summary?.total_regular_hours?.toFixed(2) || 0} suffix="hrs" /></Card></Col>
              <Col span={6}><Card size="small"><Statistic title="Total Overtime Hours" value={payrollPreview.summary?.total_overtime_hours?.toFixed(2) || 0} suffix="hrs" /></Card></Col>
              <Col span={6}><Card size="small"><Statistic title="Total Net Pay" value={formatCurrency(payrollPreview.summary?.total_net_pay || 0)} /></Card></Col>
            </Row>
            <Divider>Employee Breakdown</Divider>
            <div className="prf-preview-table-wrapper">
              <table className="prf-preview-table">
                <thead>
                  <tr>
                    <th>Employee</th>
                    <th>Regular Hrs</th>
                    <th>OT Hrs</th>
                    <th>Gross</th>
                    <th>Deductions</th>
                    <th>Net</th>
                  </tr>
                </thead>
                <tbody>
                  {(payrollPreview.preview || []).map((item, idx) => (
                    <tr key={idx}>
                      <td>
                        <strong>{safeRender(item.employee?.full_name)}</strong>
                        <br />
                        <small style={{ color: '#999' }}>{safeRender(item.employee?.employee_id)}</small>
                      </td>
                      <td>{item.calculation?.regular_hours || 0}h</td>
                      <td>{item.calculation?.overtime_hours > 0 ? `${item.calculation.overtime_hours}h` : '—'}</td>
                      <td>{formatCurrency(item.calculation?.base_pay || 0)}</td>
                      <td>{formatCurrency(item.calculation?.total_deductions || 0)}</td>
                      <td><strong>{formatCurrency(item.calculation?.net_pay || 0)}</strong></td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
            <Alert 
              message="Confirmation Required" 
              description="Please review the preview carefully before confirming. This action will create payroll records that cannot be automatically reversed." 
              type="warning" 
              showIcon 
              style={{ marginTop: 16 }} 
            />
          </div>
        )}
      </Modal>

      {/* ==================== REDESIGNED PAYSLIP MODAL - MATCHING IMAGE FORMAT ==================== */}
      <Modal
  title={<span><FilePdfOutlined style={{ color: '#ff4d4f' }} /> Employee Payslip</span>}
  open={showPayslipModal}
  onCancel={() => setShowPayslipModal(false)}
  width={1000}
  footer={[
    <Button key="close" onClick={() => setShowPayslipModal(false)}>Close</Button>,
    <Button key="print" icon={<PrinterOutlined />} onClick={() => window.print()}>Print</Button>,
    <Button key="download" type="primary" icon={<DownloadOutlined />} onClick={() => selectedPayslip && handleDownloadPayslip(selectedPayslip.id)}>Download PDF</Button>
  ]}
>
  {selectedPayslip && (
    <div className="prf-payslip-container-modern" id="payslip-print-area">
      {/* Header */}
      <div className="payslip-header-modern">
        <div className="company-info">
          <h2>{selectedPayslip.company_name || 'GPA PROVIDENCE CORP'}</h2>
          <p>PAYSLIP</p>
        </div>
        <div className="payslip-meta">
          <div className="meta-row">
            <span className="meta-label">Payment Date:</span>
            <span className="meta-value">{selectedPayslip.paid_at ? formatDate(selectedPayslip.paid_at) : '—'}</span>
          </div>
          <div className="meta-row">
            <span className="meta-label">Payroll Period:</span>
            <span className="meta-value">{formatDate(selectedPayslip.period_start)} - {formatDate(selectedPayslip.period_end)}</span>
          </div>
          <div className="meta-row">
            <span className="meta-label">Bank Account Number:</span>
            <span className="meta-value">—</span>
          </div>
        </div>
      </div>

      {/* Employee Info */}
      <div className="employee-info-modern">
        <div className="info-row">
          <span className="info-label">Employee ID:</span>
          <span className="info-value">{selectedPayslip.employee_code || '—'}</span>
          <span className="info-label">Location:</span>
          <span className="info-value">MAIN</span>
        </div>
        <div className="info-row">
          <span className="info-label">Employee Name:</span>
          <span className="info-value">{selectedPayslip.employee_name || '—'}</span>
          <span className="info-label">Department:</span>
          <span className="info-value">{selectedPayslip.department_name || '—'}</span>
        </div>
      </div>

      {/* WORK DETAIL COSTING TABLE */}
      <div className="work-detail-section">
        <h4>WORK DETAIL COSTING</h4>
        <table className="work-detail-table">
          <thead>
            <tr>
              <th>Date</th>
              <th>ST</th>
              <th>NDP</th>
              <th>Pay</th>
            </tr>
          </thead>
          <tbody>
            {(selectedPayslip.daily_work_details || []).map((day, idx) => (
              <tr key={idx}>
                <td>{day.date ? dayjs(day.date).format('MM/DD') : '—'}</td>
                <td>{day.regular_hours?.toFixed(2) || '0.00'}</td>
                <td>{day.night_diff_hours?.toFixed(2) || '0.00'}</td>
                <td className="text-right">{formatCurrency(day.pay_per_day || 0)}</td>
              </tr>
            ))}
            {/* If no daily details, show empty row */}
            {(!selectedPayslip.daily_work_details || selectedPayslip.daily_work_details.length === 0) && (
              <tr>
                <td colSpan="4" style={{ textAlign: 'center', padding: '20px' }}>No daily work details available</td>
              </tr>
            )}
            <tr className="total-row">
              <td><strong>TOTAL:</strong></td>
              <td><strong>{Number(selectedPayslip.total_regular_hours || 0).toFixed(2)}</strong></td>
              <td><strong>{Number(selectedPayslip.night_differential_hours || 0).toFixed(2)}</strong></td>
              <td className="text-right"><strong>{formatCurrency(selectedPayslip.gross_pay || 0)}</strong></td>
            </tr>
          </tbody>
        </table>
      </div>

      {/* Hourly Rate and Earnings Summary */}
      <div className="earnings-section">
        <div className="earnings-left">
          <div className="rate-row">
            <span className="rate-label">Hourly Rate</span>
            <span className="rate-value">{formatCurrency(selectedPayslip.hourly_rate || 0)}</span>
          </div>
          <table className="earnings-table">
            <tbody>
              <tr>
                <td>Straight Time</td>
                <td className="text-right">{Number(selectedPayslip.total_regular_hours || 0).toFixed(2)} hr(s)</td>
                <td className="text-right">{formatCurrency(selectedPayslip.regular_pay || 0)}</td>
              </tr>
              <tr>
                <td>Night Premium</td>
                <td className="text-right">{Number(selectedPayslip.night_differential_hours || 0).toFixed(2)} hr(s)</td>
                <td className="text-right">{formatCurrency(selectedPayslip.night_differential_pay || 0)}</td>
              </tr>
              <tr className="total-row">
                <td><strong>TOTAL TAXABLE INCOME</strong></td>
                <td></td>
                <td className="text-right"><strong>{formatCurrency(selectedPayslip.gross_pay || 0)}</strong></td>
              </tr>
              <tr>
                <td>ADD</td>
                <td></td>
                <td></td>
              </tr>
              <tr>
                <td style={{ paddingLeft: '20px' }}>Crew Meeting</td>
                <td></td>
                <td className="text-right">{formatCurrency(selectedPayslip.allowances || 0)}</td>
              </tr>
              <tr className="total-row">
                <td><strong>TOTAL NON-TAXABLE INCOME</strong></td>
                <td></td>
                <td className="text-right"><strong>{formatCurrency(selectedPayslip.allowances || 0)}</strong></td>
              </tr>
            </tbody>
          </table>
        </div>

        <div className="deductions-right">
          <table className="deductions-table">
            <tbody>
              <tr>
                <td><strong>DEDUCTIONS</strong></td>
                <td></td>
              </tr>
              <tr>
                <td style={{ paddingLeft: '20px' }}>TAXABLE INCOME</td>
                <td className="text-right">{formatCurrency(selectedPayslip.gross_pay || 0)}</td>
              </tr>
              <tr>
                <td style={{ paddingLeft: '20px' }}>Unsold Cheesy eggdesal</td>
                <td className="text-right">{formatCurrency(selectedPayslip.other_deductions || 0)}</td>
              </tr>
              <tr className="total-row">
                <td><strong>TOTAL DEDUCTIONS</strong></td>
                <td className="text-right"><strong>{formatCurrency((selectedPayslip.other_deductions || 0) + (selectedPayslip.sss_deduction || 0))}</strong></td>
              </tr>
              <tr className="total-row">
                <td><strong>TOTAL TAXABLE INCOME</strong></td>
                <td className="text-right"><strong>{formatCurrency((selectedPayslip.gross_pay || 0) - (selectedPayslip.other_deductions || 0))}</strong></td>
              </tr>
              <tr>
                <td style={{ paddingLeft: '20px' }}>LESS</td>
                <td></td>
              </tr>
              <tr>
                <td style={{ paddingLeft: '40px' }}>SSS</td>
                <td className="text-right">{formatCurrency(selectedPayslip.sss_deduction || 0)}</td>
              </tr>
              <tr className="total-row">
                <td><strong>NET TAXABLE INCOME</strong></td>
                <td className="text-right"><strong>{formatCurrency((selectedPayslip.gross_pay || 0) - (selectedPayslip.other_deductions || 0) - (selectedPayslip.sss_deduction || 0))}</strong></td>
              </tr>
              <tr>
                <td><strong>NET TAXABLE & NON-TAXABLE</strong></td>
                <td className="text-right"><strong>{formatCurrency((selectedPayslip.gross_pay || 0) - (selectedPayslip.other_deductions || 0) - (selectedPayslip.sss_deduction || 0) + (selectedPayslip.allowances || 0))}</strong></td>
              </tr>
              <tr className="net-pay-row">
                <td><strong>NET PAY</strong></td>
                <td className="text-right"><strong style={{ fontSize: 20, color: '#10b981' }}>{formatCurrency(selectedPayslip.net_pay || 0)}</strong></td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      {/* Footer */}
      <div className="payslip-footer-modern">
        <small>This is a computer-generated document. No signature required.</small>
      </div>
    </div>
  )}
</Modal>
      {/* Payslip Preview Modal */}
   <Modal
  title="Payslip Preview"
  open={showPayslipPreviewModal}
  onCancel={() => setShowPayslipPreviewModal(false)}
  width={1000}
  footer={[
    <Button key="close" onClick={() => setShowPayslipPreviewModal(false)}>Close</Button>,
    <Button key="generate" type="primary" icon={<FilePdfOutlined />} onClick={() => {
      if (payslipPreview?.payroll?.id) {
        handleGeneratePayslip(payslipPreview.payroll.id);
        setShowPayslipPreviewModal(false);
      }
    }}>Generate Payslip</Button>
  ]}
>
  {payslipPreview && (
    <div className="prf-payslip-preview">
      <Alert 
        message="Preview Mode" 
        description="This is a preview of the payslip before generation. Click 'Generate Payslip' to create the actual payslip." 
        type="info" 
        showIcon 
        style={{ marginBottom: 16 }} 
      />
      <div className="prf-payslip-container-modern">
        <div className="payslip-header-modern">
          <div><h2>GPA PROVIDENCE CORP</h2><p>PAYSLIP PREVIEW</p></div>
          <div><p>Period: {formatDate(payslipPreview.payroll?.period_start)} - {formatDate(payslipPreview.payroll?.period_end)}</p></div>
        </div>
        <Divider />
        <div className="employee-info-modern">
          <p><strong>Employee:</strong> {payslipPreview.employee?.full_name || '—'}</p>
          <p><strong>Department:</strong> {payslipPreview.employee?.department?.name || '—'}</p>
        </div>
        <h4>WORK DETAIL COSTING</h4>
        <table className="work-detail-table">
          <thead><tr><th>Date</th><th>ST</th><th>NDP</th><th>Pay</th></tr></thead>
          <tbody>
            {(payslipPreview.daily_breakdown || []).map((day, idx) => (
              <tr key={idx}>
                <td>{day.date ? dayjs(day.date).format('MM/DD') : '—'}</td>
                <td>{Number(day.regular_hours || 0).toFixed(2)}</td>
                <td>{Number(day.night_diff_hours || 0).toFixed(2)}</td>
                <td className="text-right">{formatCurrency(day.pay_per_day || 0)}</td>
              </tr>
            ))}
          </tbody>
        </table>
        <div className="earnings-section" style={{ display: 'block' }}>
          <div style={{ marginTop: 16 }}>
            <strong>Gross Pay:</strong> {formatCurrency(payslipPreview.gross_pay || 0)}<br />
            <strong>Total Deductions:</strong> {formatCurrency(payslipPreview.total_deductions || 0)}<br />
            <strong style={{ fontSize: 18, color: '#10b981' }}>NET PAY: {formatCurrency(payslipPreview.net_pay || 0)}</strong>
          </div>
        </div>
      </div>
    </div>
  )}
</Modal>

      {/* Edit Deductions Modal */}
      <Modal
        title={<span><MinusCircleOutlined style={{ color: '#ff4d4f' }} /> Official Deduction Authorization Form</span>}
        open={showEditDeductionsModal}
        onCancel={() => {
          setShowEditDeductionsModal(false);
          resetDeductionForm();
        }}
        width={700}
        footer={[
          <Button key="cancel" onClick={() => setShowEditDeductionsModal(false)}>Cancel</Button>,
          <Button key="save" type="primary" loading={updatePayrollMutation.isPending} onClick={handleSaveManualDeductions} icon={<SaveOutlined />}>Authorize Deduction</Button>
        ]}
      >
        {selectedPayrollForEdit && (
          <div>
            <div className="prf-formal-section">
              <div className="prf-formal-header"><h4>EMPLOYEE INFORMATION</h4></div>
              <div style={{ display: 'flex', alignItems: 'center', marginBottom: 16 }}>
                <Avatar size={48}>{safeRender(selectedPayrollForEdit.employee?.full_name, '?').charAt(0)}</Avatar>
                <div style={{ marginLeft: 16 }}>
                  <h3 style={{ margin: 0 }}>{safeRender(selectedPayrollForEdit.employee?.full_name)}</h3>
                  <p style={{ margin: '4px 0 0', color: '#666' }}>
                    {safeRender(selectedPayrollForEdit.employee?.position)} • {safeRender(selectedPayrollForEdit.employee?.department)}
                  </p>
                  <small>Employee ID: {safeRender(selectedPayrollForEdit.employee?.employee_id || selectedPayrollForEdit.employee?.employee_code)}</small>
                </div>
              </div>
            </div>
            <Divider />
            <div className="prf-formal-section">
              <div className="prf-formal-header"><h4>PAYROLL PERIOD SUMMARY</h4></div>
              <div className="prf-formal-summary">
                <Row gutter={16}>
                  <Col span={12}>
                    <div className="prf-summary-item"><label>Period Covered:</label><span>{periodLabel}</span></div>
                    <div className="prf-summary-item"><label>Regular Hours:</label><span><strong>{selectedPayrollForEdit.regular_hours || 0}</strong> hours</span></div>
                    <div className="prf-summary-item"><label>Overtime Hours:</label><span><strong>{selectedPayrollForEdit.overtime_hours || 0}</strong> hours</span></div>
                  </Col>
                  <Col span={12}>
                    <div className="prf-summary-item"><label>Late Minutes:</label><span><strong>{selectedPayrollForEdit.late_minutes || 0}</strong> minutes</span></div>
                    <div className="prf-summary-item"><label>Undertime:</label><span><strong>{selectedPayrollForEdit.undertime_minutes || 0}</strong> minutes</span></div>
                    <div className="prf-summary-item"><label>Current Net Pay:</label><span><strong style={{ color: '#10b981' }}>{formatCurrency(selectedPayrollForEdit.net_pay)}</strong></span></div>
                  </Col>
                </Row>
              </div>
            </div>
            <Divider />
            <div className="prf-formal-section">
              <div className="prf-formal-header"><h4>DEDUCTION AUTHORIZATION FORM</h4></div>
              <div style={{ marginBottom: 20, padding: 16, background: '#fff2f0', borderRadius: 8 }}>
                <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 16 }}>
                  <strong style={{ fontSize: 16 }}><MinusCircleOutlined /> Apply Deduction Adjustment</strong>
                  <Switch checked={enableManualDeduction} onChange={setEnableManualDeduction} checkedChildren="Enabled" unCheckedChildren="Disabled" />
                </div>
                {enableManualDeduction && (
                  <>
                    <div style={{ marginBottom: 16 }}>
                      <label className="prf-formal-label">Deduction Type *</label>
                      <select className="prf-formal-select" value={deductionType} onChange={(e) => setDeductionType(e.target.value)}>
                        <option value="cash_advance">Cash Advance</option>
                        <option value="salary_loan">Salary Loan</option>
                        <option value="sss_loan">SSS Loan</option>
                        <option value="pagibig_loan">Pag-IBIG Loan</option>
                        <option value="tax_withholding">Tax Withholding Adjustment</option>
                        <option value="penalty">Penalty/Damages</option>
                        <option value="uniform_deduction">Uniform Deduction</option>
                        <option value="other">Other Deductions</option>
                      </select>
                    </div>
                    <div style={{ marginBottom: 16 }}>
                      <label className="prf-formal-label">Deduction Category</label>
                      <select className="prf-formal-select" value={deductionCategory} onChange={(e) => setDeductionCategory(e.target.value)}>
                        <option value="loan">Loan Repayment</option>
                        <option value="government">Government Mandated</option>
                        <option value="company">Company Charge</option>
                        <option value="tax">Tax Adjustment</option>
                        <option value="other">Other</option>
                      </select>
                    </div>
                    <div style={{ marginBottom: 16 }}>
                      <label className="prf-formal-label">Deduction Amount *</label>
                      <InputNumber 
                        style={{ width: '100%', marginTop: 8 }} 
                        min={0} 
                        max={100000} 
                        step={100} 
                        value={manualDeductionAmount} 
                        onChange={(val) => setManualDeductionAmount(val || 0)} 
                        formatter={v => `₱ ${v}`.replace(/\B(?=(\d{3})+(?!\d))/g, ',')} 
                        parser={v => v.replace(/₱\s?|(,*)/g, '')} 
                        placeholder="Enter amount" 
                      />
                    </div>
                    <div style={{ marginBottom: 16 }}>
                      <label className="prf-formal-label">Reference Number (if applicable)</label>
                      <Input placeholder="e.g., Loan Reference, OR Number" value={deductionReference} onChange={(e) => setDeductionReference(e.target.value)} />
                    </div>
                    <div style={{ marginBottom: 16 }}>
                      <label className="prf-formal-label">Effective Date</label>
                      <Input type="date" value={deductionDate} onChange={(e) => setDeductionDate(e.target.value)} />
                    </div>
                    <div style={{ marginBottom: 16 }}>
                      <label className="prf-formal-label">Reason / Justification *</label>
                      <TextArea rows={3} placeholder="Provide detailed reason for this deduction..." value={manualDeductionReason} onChange={(e) => setManualDeductionReason(e.target.value)} />
                    </div>
                    <div>
                      <label className="prf-formal-label">Authorized By</label>
                      <Input placeholder="Name of authorizing personnel" value={deductionApprovedBy} onChange={(e) => setDeductionApprovedBy(e.target.value)} />
                      <small style={{ color: '#999', display: 'block', marginTop: 4 }}>Leave blank to use system default</small>
                    </div>
                  </>
                )}
              </div>
            </div>
            <Divider />
            <div className="prf-formal-section">
              <div className="prf-formal-header"><h4>FINANCIAL IMPACT CALCULATION</h4></div>
              <div style={{ background: '#f5f5f5', padding: 16, borderRadius: 8 }}>
                <Row gutter={16}>
                  <Col span={12}>
                    <div className="prf-summary-item"><label>Original Net Pay:</label><span><strong>{formatCurrency(selectedPayrollForEdit.net_pay)}</strong></span></div>
                  </Col>
                  <Col span={12}>
                    <div className="prf-summary-item"><label>Deduction Amount:</label><span style={{ color: '#ff4d4f' }}><strong>-{formatCurrency(enableManualDeduction ? manualDeductionAmount : 0)}</strong></span></div>
                  </Col>
                </Row>
                <Divider style={{ margin: '12px 0' }} />
                <Row>
                  <Col span={24}>
                    <div className="prf-summary-item"><label style={{ fontSize: 16 }}>Adjusted Net Pay:</label><span><strong style={{ fontSize: 20, color: '#10b981' }}>{formatCurrency((selectedPayrollForEdit.net_pay || 0) - (enableManualDeduction ? manualDeductionAmount : 0))}</strong></span></div>
                  </Col>
                </Row>
              </div>
            </div>
            <Divider />
            <div className="prf-formal-declaration">
              <p><strong>Declaration:</strong> I hereby certify that the information provided above is true and correct. The deduction has been properly authorized and documented in accordance with company policies and applicable labor laws.</p>
            </div>
          </div>
        )}
      </Modal>

      {/* Bulk Deduction Modal */}
      <Modal
        title={<span><MinusCircleOutlined style={{ color: '#ff4d4f' }} /> Bulk Deduction Application</span>}
        open={showBulkDeductionModal}
        onCancel={() => {
          setShowBulkDeductionModal(false);
          setBulkDeductionAmount(0);
          setBulkDeductionReason('');
          setBulkDeductionType('other');
          setBulkDeductionCategory('company');
        }}
        width={550}
        footer={[
          <Button key="cancel" onClick={() => setShowBulkDeductionModal(false)}>Cancel</Button>,
          <Button key="apply" type="primary" danger onClick={handleBulkDeductions} loading={bulkUpdateDeductionsMutation.isPending} icon={<SaveOutlined />}>
            Apply to {selectedPayrollIds.length} Record(s)
          </Button>
        ]}
      >
        <Alert
          message="Bulk Deduction Warning"
          description={`You are about to apply a deduction to ${selectedPayrollIds.length} payroll record(s). This action will affect the net pay of each selected employee.`}
          type="warning"
          showIcon
          style={{ marginBottom: 16 }}
        />
        <div className="prf-formal-section">
          <div className="prf-formal-header"><h4>DEDUCTION DETAILS</h4></div>
          <div style={{ marginBottom: 16 }}>
            <label className="prf-formal-label">Deduction Type *</label>
            <select className="prf-formal-select" value={bulkDeductionType} onChange={(e) => setBulkDeductionType(e.target.value)}>
              <option value="cash_advance">Cash Advance</option>
              <option value="salary_loan">Salary Loan</option>
              <option value="sss_loan">SSS Loan</option>
              <option value="pagibig_loan">Pag-IBIG Loan</option>
              <option value="tax_withholding">Tax Withholding Adjustment</option>
              <option value="penalty">Penalty/Damages</option>
              <option value="uniform_deduction">Uniform Deduction</option>
              <option value="other">Other Deductions</option>
            </select>
          </div>
          <div style={{ marginBottom: 16 }}>
            <label className="prf-formal-label">Deduction Category</label>
            <select className="prf-formal-select" value={bulkDeductionCategory} onChange={(e) => setBulkDeductionCategory(e.target.value)}>
              <option value="loan">Loan Repayment</option>
              <option value="government">Government Mandated</option>
              <option value="company">Company Charge</option>
              <option value="tax">Tax Adjustment</option>
              <option value="other">Other</option>
            </select>
          </div>
          <div style={{ marginBottom: 16 }}>
            <label className="prf-formal-label">Deduction Amount *</label>
            <InputNumber 
              style={{ width: '100%', marginTop: 8 }} 
              min={0} 
              max={100000} 
              step={100} 
              value={bulkDeductionAmount} 
              onChange={(val) => setBulkDeductionAmount(val || 0)} 
              formatter={v => `₱ ${v}`.replace(/\B(?=(\d{3})+(?!\d))/g, ',')} 
              parser={v => v.replace(/₱\s?|(,*)/g, '')} 
              placeholder="Enter amount" 
            />
          </div>
          <div style={{ marginBottom: 16 }}>
            <label className="prf-formal-label">Reason / Justification *</label>
            <TextArea rows={3} placeholder="Provide detailed reason for this bulk deduction..." value={bulkDeductionReason} onChange={(e) => setBulkDeductionReason(e.target.value)} />
          </div>
        </div>
        <Divider />
        <div className="prf-formal-declaration">
          <p><strong>Declaration:</strong> I hereby certify that the information provided above is true and correct. The deduction has been properly authorized and documented in accordance with company policies and applicable labor laws.</p>
        </div>
      </Modal>

      {/* History Details Modal */}
      <Modal
        title="Archived Payroll Details"
        open={showHistoryDetailsModal}
        onCancel={() => setShowHistoryDetailsModal(false)}
        width={700}
        footer={[
          <Button key="close" onClick={() => setShowHistoryDetailsModal(false)}>Close</Button>,
          <Button key="restore" type="primary" onClick={() => {
            setShowHistoryDetailsModal(false);
            if (selectedHistoryItem) handleRestorePayroll(selectedHistoryItem.id);
          }} icon={<UndoOutlined />}>Restore Record</Button>
        ]}
      >
        {selectedHistoryItem && (
          <div>
            <div className="prf-modal-employee" style={{ display: 'flex', alignItems: 'center', gap: 16, marginBottom: 16 }}>
              <Avatar size={64}>{safeRender(selectedHistoryItem.employee?.full_name, '?').charAt(0)}</Avatar>
              <div>
                <h3 style={{ margin: 0 }}>{safeRender(selectedHistoryItem.employee?.full_name)}</h3>
                <p style={{ margin: '4px 0 0', color: '#666' }}>{safeRender(selectedHistoryItem.employee?.position)} • {safeRender(selectedHistoryItem.employee?.department)}</p>
              </div>
              <Tag color="red">Deleted</Tag>
            </div>
            <Divider />
            <div style={{ background: '#f5f5f5', padding: 16, borderRadius: 8 }}>
              <Row>
                <Col span={16}><strong style={{ fontSize: 18 }}>Net Pay</strong></Col>
                <Col span={8} className="text-right"><strong style={{ fontSize: 24, color: '#10b981' }}>{formatCurrency(selectedHistoryItem.net_pay)}</strong></Col>
              </Row>
            </div>
          </div>
        )}
      </Modal>

      {/* Payroll Details Modal */}
      <Modal
        title="Payroll Details"
        open={showPayrollModal}
        onCancel={() => setShowPayrollModal(false)}
        width={700}
        footer={[
          <Button key="close" onClick={() => setShowPayrollModal(false)}>Close</Button>,
          selectedEmployee?.status !== 'paid' && (
            <Button key="edit" onClick={() => { 
              setShowPayrollModal(false);
              handleEditDeductions(selectedEmployee);
            }} icon={<CalculatorOutlined />}>
              Edit Deduction
            </Button>
          ),
          selectedEmployee && (
            <Button key="payslip" type="primary" icon={<FilePdfOutlined />} onClick={() => {
              setShowPayrollModal(false);
              handleGeneratePayslip(selectedEmployee.id);
            }}>
              Generate Payslip
            </Button>
          )
        ]}
      >
        {selectedEmployee && (
          <div>
            <div className="prf-modal-employee" style={{ display: 'flex', alignItems: 'center', gap: 16, marginBottom: 16 }}>
              <Avatar size={64}>{safeRender(selectedEmployee.employee?.full_name, '?').charAt(0)}</Avatar>
              <div>
                <h3 style={{ margin: 0 }}>{safeRender(selectedEmployee.employee?.full_name)}</h3>
                <p style={{ margin: '4px 0 0', color: '#666' }}>{safeRender(selectedEmployee.employee?.position)} • {safeRender(selectedEmployee.employee?.department)}</p>
              </div>
              <span className={`prf-modal-status prf-status-${selectedEmployee.status}`}>{selectedEmployee.status}</span>
            </div>
            <Divider />
            <div style={{ background: '#f5f5f5', padding: 16, borderRadius: 8 }}>
              <Row>
                <Col span={16}><strong style={{ fontSize: 18 }}>Net Pay</strong></Col>
                <Col span={8} className="text-right"><strong style={{ fontSize: 24, color: '#10b981' }}>{formatCurrency(selectedEmployee.net_pay)}</strong></Col>
              </Row>
            </div>
          </div>
        )}
      </Modal>
    </div>
  );
};

export default Staff_Payroll_Formal;