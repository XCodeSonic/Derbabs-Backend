// src/components/Staff_Management.jsx - CORRECTED VERSION

import { API_ORIGIN } from '../../../config/env';
import React, { useState, useEffect, useCallback, useMemo } from 'react';
import {
    SearchOutlined, PlusOutlined, TeamOutlined, ClockCircleOutlined,
    CalendarOutlined, EditOutlined, DeleteOutlined, EyeOutlined,
    MailOutlined, PhoneOutlined, BankOutlined, IdcardOutlined,
    UploadOutlined, StarOutlined, FilterOutlined, ReloadOutlined,
    CloseOutlined, CheckCircleOutlined, WarningOutlined, CoffeeOutlined,
    SafetyOutlined, HeartOutlined, TrophyOutlined, BookOutlined,
    FileTextOutlined, PrinterOutlined, PhoneFilled, MailFilled,
    PauseCircleOutlined, InboxOutlined, BarChartOutlined,
    SortAscendingOutlined, SortDescendingOutlined, ColumnHeightOutlined,
    FullscreenOutlined, UserOutlined, BuildOutlined, HistoryOutlined,
    RestOutlined, LoadingOutlined, ExclamationCircleOutlined,
    DownOutlined, DollarOutlined, CheckSquareOutlined
} from '@ant-design/icons';
import PeopleIcon from '@mui/icons-material/People';
import { staffAPI, ensureArray } from '../../../services/api';
import {
    useEmployees,
    useDepartments,
    usePositions,
    useSalaryGrades,
    useEmployeeStats,
    useArchivedEmployees,
    useCreateEmployee,
    useUpdateEmployee,
    useBulkUpdateStatus,
    useToggleBookmark,
    useCreateDepartment,
    useUpdateDepartment,
    useDeleteDepartment,
    useCreatePosition,
    useUpdatePosition,
    useDeletePosition,
    useCreateSalaryGrade,
    useUpdateSalaryGrade,
    useDeleteSalaryGrade,
} from '../../../hooks/useStaffQueries';
import '../styles/StaffManagement.css';

const StaffManagement = () => {
    // ==================== STATE MANAGEMENT ====================
    const [searchQuery, setSearchQuery] = useState('');
    const [selectedDepartment, setSelectedDepartment] = useState('all');
    const [selectedStatus, setSelectedStatus] = useState('all');
    const [selectedTab, setSelectedTab] = useState('all');
    const [showArchive, setShowArchive] = useState(false);
    const [selectedEmployees, setSelectedEmployees] = useState([]);
    const [isSelectMode, setIsSelectMode] = useState(false);
    const [sortConfig, setSortConfig] = useState({ key: 'first_name', direction: 'asc' });
    const [visibleColumns, setVisibleColumns] = useState({
        employee: true, department: true, position: true, status: true,
        salary_grade: true, hourly_rate: true, contact: true, actions: true
    });
    const [showColumnSelector, setShowColumnSelector] = useState(false);
    const [showSortMenu, setShowSortMenu] = useState(false);
    const [currentPage, setCurrentPage] = useState(1);
    const [currentDate, setCurrentDate] = useState('');
    const [showFullscreen, setShowFullscreen] = useState(false);
    const [showNotification, setShowNotification] = useState(false);
    const [notificationMessage, setNotificationMessage] = useState('');
    const [notificationType, setNotificationType] = useState('success');
    const [showSuccessAnimation, setShowSuccessAnimation] = useState(false);
    const [showStatusDropdown, setShowStatusDropdown] = useState(null);

    // Modal states
    const [showModal, setShowModal] = useState(false);
    const [modalMode, setModalMode] = useState('add');
    const [selectedEmployee, setSelectedEmployee] = useState(null);
    const [showDeleteConfirm, setShowDeleteConfirm] = useState(false);
    const [employeeToDelete, setEmployeeToDelete] = useState(null);
    const [showPrintModal, setShowPrintModal] = useState(false);
    const [showPositionModal, setShowPositionModal] = useState(false);
    const [showDepartmentModal, setShowDepartmentModal] = useState(false);
    const [showSalaryGradeModal, setShowSalaryGradeModal] = useState(false);
    const [showComplianceModal, setShowComplianceModal] = useState(false);
    const [showBenefitsModal, setShowBenefitsModal] = useState(false);
    const [showReportsModal, setShowReportsModal] = useState(false);
    const [showStatusFilterModal, setShowStatusFilterModal] = useState(false);

    const [formData, setFormData] = useState({
        first_name: '', last_name: '', middle_name: '', email: '', phone: '',
        birth_date: '', gender: '', address: '', city: '', state: '', postal_code: '', country: 'Philippines',
        department_id: '', position_id: '', employee_type: 'regular', status: 'active',
        hire_date: '', hourly_rate_override: '', bank_name: '', bank_account: '',
        allowances: '', other_deductions: '', sss: '', philhealth: '', pagibig: '', tin: '',
        emergency_contact: '', emergency_relation: '', emergency_phone: '',
        skills: '', certifications: '', achievements: '', notes: '',
    });

    const [positionFormData, setPositionFormData] = useState({
        title: '', department_id: '', salary_grade_id: '', description: '',
        employment_type: 'full-time', max_hours_per_week: '40', required_skills: '', status: 'active'
    });
    const [departmentFormData, setDepartmentFormData] = useState({
        name: '', code: '', description: '', manager_id: '', status: 'active'
    });
    const [salaryGradeFormData, setSalaryGradeFormData] = useState({
        grade_name: '', hourly_rate: '', description: '', status: 'active'
    });

    const [imageFile, setImageFile] = useState(null);
    const [imagePreview, setImagePreview] = useState(null);
    const [formErrors, setFormErrors] = useState({});
    const [touchedFields, setTouchedFields] = useState({});
    const [formStep, setFormStep] = useState(1);
    const [isSubmitting, setIsSubmitting] = useState(false);
    const [uploadProgress, setUploadProgress] = useState(0);
    const [positionFormMode, setPositionFormMode] = useState('add');
    const [selectedPosition, setSelectedPosition] = useState(null);
    const [departmentFormMode, setDepartmentFormMode] = useState('add');
    const [selectedDepartmentForEdit, setSelectedDepartmentForEdit] = useState(null);
    const [salaryGradeFormMode, setSalaryGradeFormMode] = useState('add');
    const [selectedSalaryGrade, setSelectedSalaryGrade] = useState(null);
    const [positionFormErrors, setPositionFormErrors] = useState({});
    const [departmentFormErrors, setDepartmentFormErrors] = useState({});
    const [salaryGradeFormErrors, setSalaryGradeFormErrors] = useState({});
    const [selectedPositionDetails, setSelectedPositionDetails] = useState({
        salary_grade: '', hourly_rate: 0, salary_grade_id: null
    });

    const [complianceData, setComplianceData] = useState([]);
    const [benefitsData, setBenefitsData] = useState([]);
    const [reportsData, setReportsData] = useState([]);

    const pageSize = 5;
    const API_URL = API_ORIGIN;

    const [isDarkMode, setIsDarkMode] = useState(() => {
        const savedTheme = localStorage.getItem('theme');
        if (savedTheme === 'dark') return true;
        if (savedTheme === 'light') return false;
        return document.body.classList.contains('dark-mode');
    });

    // Theme detection
    useEffect(() => {
        const updateTheme = () => {
            const isDark = document.body.classList.contains('dark-mode');
            setIsDarkMode(isDark);
        };
        
        const observer = new MutationObserver(() => {
            updateTheme();
        });
        
        observer.observe(document.body, { attributes: true, attributeFilter: ['class'] });
        
        const handleThemeChange = (e) => {
            setIsDarkMode(e.detail.isDark);
        };
        
        const handleStorageChange = (e) => {
            if (e.key === 'theme') {
                const isDark = e.newValue === 'dark';
                setIsDarkMode(isDark);
            }
        };
        
        window.addEventListener('themeChange', handleThemeChange);
        window.addEventListener('storage', handleStorageChange);
        
        return () => {
            observer.disconnect();
            window.removeEventListener('themeChange', handleThemeChange);
            window.removeEventListener('storage', handleStorageChange);
        };
    }, []);

    // Options arrays
    const employmentTypeOptions = [
        { value: 'regular', label: 'Regular' },
        { value: 'oncall', label: 'On-call' },
        { value: 'probationary', label: 'Probationary' },
        { value: 'contract', label: 'Contract' },
        { value: 'intern', label: 'Intern' }
    ];

    const genderOptions = [
        { value: 'male', label: 'Male' },
        { value: 'female', label: 'Female' },
        { value: 'other', label: 'Other' },
        { value: 'prefer_not_to_say', label: 'Prefer not to say' }
    ];

    const statusOptions = [
        { value: 'active', label: 'Active', icon: '●', color: '#10b981', bgColor: '#d1fae5', textColor: '#065f46' },
        { value: 'onleave', label: 'On Leave', icon: '●', color: '#f59e0b', bgColor: '#fed7aa', textColor: '#92400e' },
        { value: 'inactive', label: 'Inactive', icon: '●', color: '#6b7280', bgColor: '#f3f4f6', textColor: '#374151' },
        { value: 'terminated', label: 'Terminated', icon: '●', color: '#ef4444', bgColor: '#fee2e2', textColor: '#991b1b' }
    ];

    const positionEmploymentTypes = [
        { value: 'full-time', label: 'Full Time' },
        { value: 'part-time', label: 'Part Time' },
        { value: 'contract', label: 'Contract' },
        { value: 'internship', label: 'Internship' },
        { value: 'temporary', label: 'Temporary' },
        { value: 'seasonal', label: 'Seasonal' }
    ];

   // ==================== REACT QUERY HOOKS ====================
const employeeFilters = useMemo(() => {
    const filters = { 
        page: currentPage, 
        per_page: pageSize,
        show_archive: selectedTab === 'inactive' || selectedTab === 'terminated'
    };
    if (selectedDepartment !== 'all') filters.department_id = selectedDepartment;
    if (selectedStatus !== 'all') filters.status = selectedStatus;
    if (selectedTab !== 'all' && selectedTab !== 'inactive' && selectedTab !== 'terminated') {
        filters.status = selectedTab;
    }
    if (searchQuery) filters.search = searchQuery;
    return filters;
}, [selectedDepartment, selectedStatus, selectedTab, searchQuery, currentPage, pageSize]);

const {
    data: employeesResponse,
    isLoading: employeesLoading,
    refetch: refetchEmployees
} = useEmployees(employeeFilters);

const { data: departmentsResponse, isLoading: departmentsLoading, refetch: refetchDepartments } = useDepartments();
const { data: positionsResponse, isLoading: positionsLoading, refetch: refetchPositions } = usePositions();
const { data: salaryGradesResponse, isLoading: salaryGradesLoading, refetch: refetchSalaryGrades } = useSalaryGrades();
const { data: stats, isLoading: statsLoading, refetch: refetchStats } = useEmployeeStats();
const { data: archivedEmployeesResponse, isLoading: archivedLoading, refetch: refetchArchived } = useArchivedEmployees();

// FIXED: Extract data properly from responses with fallbacks to empty arrays
const employees = React.useMemo(() => {
    if (!employeesResponse) return [];
    if (Array.isArray(employeesResponse)) return employeesResponse;
    if (employeesResponse.data && Array.isArray(employeesResponse.data)) return employeesResponse.data;
    if (employeesResponse.data && employeesResponse.data.data && Array.isArray(employeesResponse.data.data)) return employeesResponse.data.data;
    return [];
}, [employeesResponse]);

const pagination = React.useMemo(() => {
    if (!employeesResponse) return null;
    if (employeesResponse.pagination) return employeesResponse.pagination;
    if (employeesResponse.data && employeesResponse.data.pagination) return employeesResponse.data.pagination;
    return null;
}, [employeesResponse]);

// Fix: Extract departments array from response
const departments = React.useMemo(() => {
    if (!departmentsResponse) return [];
    if (Array.isArray(departmentsResponse)) return departmentsResponse;
    if (departmentsResponse.data && Array.isArray(departmentsResponse.data)) return departmentsResponse.data;
    if (departmentsResponse.data && departmentsResponse.data.data && Array.isArray(departmentsResponse.data.data)) return departmentsResponse.data.data;
    return [];
}, [departmentsResponse]);

// Fix: Extract positions array from response
const positions = React.useMemo(() => {
    if (!positionsResponse) return [];
    if (Array.isArray(positionsResponse)) return positionsResponse;
    if (positionsResponse.data && Array.isArray(positionsResponse.data)) return positionsResponse.data;
    if (positionsResponse.data && positionsResponse.data.data && Array.isArray(positionsResponse.data.data)) return positionsResponse.data.data;
    return [];
}, [positionsResponse]);

// Fix: Extract salary grades array from response
const salaryGrades = React.useMemo(() => {
    if (!salaryGradesResponse) return [];
    if (Array.isArray(salaryGradesResponse)) return salaryGradesResponse;
    if (salaryGradesResponse.data && Array.isArray(salaryGradesResponse.data)) return salaryGradesResponse.data;
    if (salaryGradesResponse.data && salaryGradesResponse.data.data && Array.isArray(salaryGradesResponse.data.data)) return salaryGradesResponse.data.data;
    return [];
}, [salaryGradesResponse]);

// Fix: Extract archived employees array
const archivedEmployees = React.useMemo(() => {
    if (!archivedEmployeesResponse) return [];
    if (Array.isArray(archivedEmployeesResponse)) return archivedEmployeesResponse;
    if (archivedEmployeesResponse.data && Array.isArray(archivedEmployeesResponse.data)) return archivedEmployeesResponse.data;
    if (archivedEmployeesResponse.data && archivedEmployeesResponse.data.data && Array.isArray(archivedEmployeesResponse.data.data)) return archivedEmployeesResponse.data.data;
    return [];
}, [archivedEmployeesResponse]);

const isLoading = employeesLoading && employees.length === 0;

    // Mutations
    const createEmployeeMutation = useCreateEmployee();
    const updateEmployeeMutation = useUpdateEmployee();
    const bulkUpdateStatusMutation = useBulkUpdateStatus();
    const toggleBookmarkMutation = useToggleBookmark();
    const createDepartmentMutation = useCreateDepartment();
    const updateDepartmentMutation = useUpdateDepartment();
    const deleteDepartmentMutation = useDeleteDepartment();
    const createPositionMutation = useCreatePosition();
    const updatePositionMutation = useUpdatePosition();
    const deletePositionMutation = useDeletePosition();
    const createSalaryGradeMutation = useCreateSalaryGrade();
    const updateSalaryGradeMutation = useUpdateSalaryGrade();
    const deleteSalaryGradeMutation = useDeleteSalaryGrade();

    // ==================== HELPER FUNCTIONS ====================
    const getImageUrl = useCallback((photoPath) => {
        if (!photoPath) return null;
        if (photoPath.startsWith('http')) return photoPath;
        if (photoPath.startsWith('/')) return `${API_URL}${photoPath}`;
        const cleanPath = photoPath.replace(/^storage\//, '');
        return `${API_URL}/storage/${cleanPath}`;
    }, [API_URL]);

    const getEmployeeSalaryGrade = useCallback((employee) => {
        if (employee.position?.salary_grade?.grade_name) {
            return employee.position.salary_grade.grade_name;
        }
        if (employee.position?.salary_grade_id) {
            const grade = salaryGrades.find(sg => sg.id === employee.position.salary_grade_id);
            return grade ? grade.grade_name : 'N/A';
        }
        return 'N/A';
    }, [salaryGrades]);

    const showNotificationMessage = useCallback((message, type = 'success') => {
        setNotificationMessage(message);
        setNotificationType(type);
        setShowNotification(true);
        setTimeout(() => setShowNotification(false), 3000);
    }, []);

    const handleBlur = (fieldName) => {
        setTouchedFields(prev => ({ ...prev, [fieldName]: true }));
    };

    const validateTextOnly = (value, fieldName) => {
        if (value && /\d/.test(value)) {
            return `${fieldName} cannot contain numbers`;
        }
        return null;
    };

    const validateBirthDate = (date) => {
        if (!date) return true;
        const selectedDate = new Date(date);
        const today = new Date();
        today.setHours(0, 0, 0, 0);
        return selectedDate <= today;
    };

    // Filter positions when department changes
    const filteredPositions = useMemo(() => {
        if (formData.department_id && positions.length > 0) {
            return positions.filter(pos => pos.department_id === parseInt(formData.department_id));
        }
        return [];
    }, [formData.department_id, positions]);

    // Handle position selection
    const handlePositionChange = useCallback(async (positionId) => {
        setFormData(prev => ({ ...prev, position_id: positionId }));

        if (positionId) {
            const position = positions.find(p => p.id === parseInt(positionId));
            if (position && position.salary_grade) {
                setSelectedPositionDetails({
                    salary_grade: position.salary_grade.grade_name,
                    hourly_rate: position.salary_grade.hourly_rate || 0,
                    salary_grade_id: position.salary_grade.id
                });
            } else if (position && position.salary_grade_id) {
                const salaryGrade = salaryGrades.find(sg => sg.id === position.salary_grade_id);
                if (salaryGrade) {
                    setSelectedPositionDetails({
                        salary_grade: salaryGrade.grade_name,
                        hourly_rate: salaryGrade.hourly_rate || 0,
                        salary_grade_id: salaryGrade.id
                    });
                }
            }
        } else {
            setSelectedPositionDetails({
                salary_grade: '',
                hourly_rate: 0,
                salary_grade_id: null
            });
        }
    }, [positions, salaryGrades]);

    // Validate functions (keep as is - they're fine)
    const validateForm = () => {
        const errors = {};
        if (!formData.first_name?.trim()) errors.first_name = 'First name is required';
        if (!formData.last_name?.trim()) errors.last_name = 'Last name is required';
        if (!formData.email?.trim()) errors.email = 'Email is required';
        if (!formData.phone?.trim()) errors.phone = 'Phone is required';
        if (!formData.department_id) errors.department_id = 'Department is required';
        if (!formData.position_id) errors.position_id = 'Position is required';
        if (!formData.hire_date) errors.hire_date = 'Hire date is required';

        const textOnlyFields = [
            { field: 'first_name', label: 'First name' },
            { field: 'last_name', label: 'Last name' },
            { field: 'middle_name', label: 'Middle name' },
            { field: 'city', label: 'City' },
            { field: 'state', label: 'State/Province' },
            { field: 'country', label: 'Country' },
            { field: 'emergency_contact', label: 'Emergency contact' },
            { field: 'bank_name', label: 'Bank name' }
        ];

        textOnlyFields.forEach(({ field, label }) => {
            const error = validateTextOnly(formData[field], label);
            if (error) errors[field] = error;
        });

        if (formData.birth_date && !validateBirthDate(formData.birth_date)) {
            errors.birth_date = 'Birth date cannot be in the future';
        }

        if (formData.email && !/^[^\s@]+@([^\s@]+\.)+[^\s@]+$/.test(formData.email)) {
            errors.email = 'Please enter a valid email address';
        }

        if (formData.phone && !/^[0-9+\-\s()]{10,20}$/.test(formData.phone)) {
            errors.phone = 'Please enter a valid phone number';
        }

        if (formData.hire_date) {
            const hireDate = new Date(formData.hire_date);
            const today = new Date();
            if (hireDate > today) {
                errors.hire_date = 'Hire date cannot be in the future';
            }
        }

        setFormErrors(errors);
        return Object.keys(errors).length === 0;
    };

    const validateStep1 = () => {
        const errors = {};
        if (!formData.first_name?.trim()) errors.first_name = 'First name is required';
        if (!formData.last_name?.trim()) errors.last_name = 'Last name is required';
        if (!formData.email?.trim()) errors.email = 'Email is required';
        if (!formData.phone?.trim()) errors.phone = 'Phone is required';
        if (!formData.position_id) errors.position_id = 'Position is required';

        const nameError = validateTextOnly(formData.first_name, 'First name');
        if (nameError) errors.first_name = nameError;
        const lastNameError = validateTextOnly(formData.last_name, 'Last name');
        if (lastNameError) errors.last_name = lastNameError;
        const middleNameError = validateTextOnly(formData.middle_name, 'Middle name');
        if (middleNameError) errors.middle_name = middleNameError;

        if (formData.birth_date && !validateBirthDate(formData.birth_date)) {
            errors.birth_date = 'Birth date cannot be in the future';
        }

        setFormErrors(errors);
        return Object.keys(errors).length === 0;
    };

    const validateStep2 = () => {
        const errors = {};
        if (!formData.department_id) errors.department_id = 'Department is required';
        if (!formData.hire_date) errors.hire_date = 'Hire date is required';

        if (formData.hire_date) {
            const hireDate = new Date(formData.hire_date);
            const today = new Date();
            if (hireDate > today) {
                errors.hire_date = 'Hire date cannot be in the future';
            }
        }

        setFormErrors(errors);
        return Object.keys(errors).length === 0;
    };

    const validatePositionForm = () => {
        const errors = {};
        if (!positionFormData.title?.trim()) errors.title = 'Position title is required';
        if (!positionFormData.department_id) errors.department_id = 'Department is required';
        if (!positionFormData.salary_grade_id) errors.salary_grade_id = 'Salary grade is required';
        if (positionFormData.title && /\d/.test(positionFormData.title)) {
            errors.title = 'Position title cannot contain numbers';
        }
        setPositionFormErrors(errors);
        return Object.keys(errors).length === 0;
    };

    const validateDepartmentForm = () => {
        const errors = {};
        if (!departmentFormData.name?.trim()) errors.name = 'Department name is required';
        if (!departmentFormData.code?.trim()) errors.code = 'Department code is required';
        if (departmentFormData.name && /\d/.test(departmentFormData.name)) {
            errors.name = 'Department name cannot contain numbers';
        }
        setDepartmentFormErrors(errors);
        return Object.keys(errors).length === 0;
    };

    const validateSalaryGradeForm = () => {
        const errors = {};
        if (!salaryGradeFormData.grade_name?.trim()) errors.grade_name = 'Grade name is required';
        if (!salaryGradeFormData.hourly_rate || salaryGradeFormData.hourly_rate <= 0) {
            errors.hourly_rate = 'Hourly rate must be greater than 0';
        }
        setSalaryGradeFormErrors(errors);
        return Object.keys(errors).length === 0;
    };

    // Reset functions
    const resetForm = () => {
        setFormData({
            first_name: '', last_name: '', middle_name: '', position_id: '', email: '',
            phone: '', address: '', city: '', state: '', postal_code: '', country: 'Philippines',
            department_id: '', employee_type: 'regular', status: 'active',
            hire_date: '', hourly_rate_override: '', emergency_contact: '', emergency_relation: '',
            emergency_phone: '', sss: '', philhealth: '', pagibig: '', tin: '', skills: '',
            certifications: '', achievements: '', notes: '', profile_photo: null,
            bank_name: '', bank_account: '', birth_date: '', gender: '',
        });
        setSelectedPositionDetails({ salary_grade: '', hourly_rate: 0, salary_grade_id: null });
        setFormErrors({});
        setTouchedFields({});
        setImageFile(null);
        setImagePreview(null);
        setFormStep(1);
        setIsSubmitting(false);
        setUploadProgress(0);
    };

    const resetPositionForm = () => {
        setPositionFormData({
            title: '', department_id: '', salary_grade_id: '', description: '',
            employment_type: 'full-time', max_hours_per_week: '40', required_skills: '', status: 'active'
        });
        setPositionFormErrors({});
    };

    const resetDepartmentForm = () => {
        setDepartmentFormData({
            name: '', code: '', description: '', manager_id: '', status: 'active'
        });
        setDepartmentFormErrors({});
    };

    const resetSalaryGradeForm = () => {
        setSalaryGradeFormData({
            grade_name: '', hourly_rate: '', description: '', status: 'active'
        });
        setSalaryGradeFormErrors({});
    };

    const closeModal = () => {
        setShowModal(false);
        resetForm();
        setSelectedEmployee(null);
        setModalMode('add');
    };

    // Employee CRUD
    const handleAddEmployee = () => {
        resetForm();
        setSelectedEmployee(null);
        setModalMode('add');
        setFormStep(1);
        setShowModal(true);
    };

    const handleEditEmployee = (employee) => {
        setSelectedEmployee(employee);
        setModalMode('edit');
        setFormStep(1);
        setFormData({
            first_name: employee.first_name || '',
            last_name: employee.last_name || '',
            middle_name: employee.middle_name || '',
            position_id: employee.position?.id || employee.position_id || '',
            email: employee.email || '',
            phone: employee.phone || '',
            address: employee.address || '',
            city: employee.city || '',
            state: employee.state || '',
            postal_code: employee.postal_code || '',
            country: employee.country || 'Philippines',
            department_id: employee.department?.id || employee.department_id || '',
            employee_type: employee.employee_type || 'regular',
            status: employee.status || 'active',
            hire_date: employee.hire_date || '',
            hourly_rate_override: employee.hourly_rate_override || '',
            emergency_contact: employee.emergency_contact_name || employee.emergency_contact || '',
            emergency_relation: employee.emergency_contact_relation || employee.emergency_relation || '',
            emergency_phone: employee.emergency_contact_phone || employee.emergency_phone || '',
            sss: employee.sss_number || employee.sss || '',
            philhealth: employee.philhealth_number || employee.philhealth || '',
            pagibig: employee.pagibig_number || employee.pagibig || '',
            tin: employee.tin_number || employee.tin || '',
            skills: Array.isArray(employee.skills) ? employee.skills.join(', ') : (employee.skills || ''),
            certifications: Array.isArray(employee.certifications) ? employee.certifications.join(', ') : (employee.certifications || ''),
            achievements: Array.isArray(employee.achievements) ? employee.achievements.join(', ') : (employee.achievements || ''),
            notes: employee.notes || '',
            profile_photo: null,
            bank_name: employee.bank_name || '',
            bank_account: employee.bank_account_number || employee.bank_account || '',
            birth_date: employee.birth_date || '',
            gender: employee.gender || ''
        });

        if (employee.position) {
            const pos = employee.position;
            const salaryGrade = pos.salary_grade;
            if (salaryGrade) {
                setSelectedPositionDetails({
                    salary_grade: salaryGrade.grade_name,
                    hourly_rate: salaryGrade.hourly_rate || 0,
                    salary_grade_id: salaryGrade.id
                });
            } else if (pos.salary_grade_id) {
                const sg = salaryGrades.find(s => s.id === pos.salary_grade_id);
                if (sg) {
                    setSelectedPositionDetails({
                        salary_grade: sg.grade_name,
                        hourly_rate: sg.hourly_rate || 0,
                        salary_grade_id: sg.id
                    });
                }
            }
        }

        setImagePreview(getImageUrl(employee.profile_photo_url || employee.profile_photo));
        setImageFile(null);
        setFormErrors({});
        setTouchedFields({});
        setShowModal(true);
    };

    const handleViewEmployee = (employee) => {
        setSelectedEmployee(employee);
        setModalMode('view');
        setShowModal(true);
    };

    const handleSaveEmployee = async () => {
        if (!validateForm()) {
            showNotificationMessage('Please fill in all required fields', 'error');
            return;
        }

        setIsSubmitting(true);
        const formDataToSend = new FormData();

        const fieldMapping = {
            first_name: formData.first_name,
            last_name: formData.last_name,
            middle_name: formData.middle_name || '',
            email: formData.email,
            phone: formData.phone || '',
            birth_date: formData.birth_date || '',
            gender: formData.gender || '',
            address: formData.address || '',
            city: formData.city || '',
            state: formData.state || '',
            postal_code: formData.postal_code || '',
            country: formData.country || 'Philippines',
            department_id: formData.department_id,
            position_id: formData.position_id,
            employee_type: formData.employee_type || 'regular',
            status: formData.status || 'active',
            hire_date: formData.hire_date,
            hourly_rate_override: formData.hourly_rate_override || '',
            bank_name: formData.bank_name || '',
            bank_account_number: formData.bank_account || '',
            allowances: formData.allowances || 0,
            other_deductions: formData.other_deductions || 0,
            sss_number: formData.sss || '',
            philhealth_number: formData.philhealth || '',
            pagibig_number: formData.pagibig || '',
            tin_number: formData.tin || '',
            emergency_contact_name: formData.emergency_contact || '',
            emergency_contact_relation: formData.emergency_relation || '',
            emergency_contact_phone: formData.emergency_phone || '',
            notes: formData.notes || '',
        };

        Object.keys(fieldMapping).forEach(key => {
            const value = fieldMapping[key];
            if (value !== null && value !== undefined && value !== '') {
                formDataToSend.append(key, value);
            }
        });

        if (formData.skills && formData.skills.trim()) {
            const skillsArray = formData.skills.split(',').map(s => s.trim()).filter(s => s);
            formDataToSend.append('skills', JSON.stringify(skillsArray));
        } else {
            formDataToSend.append('skills', JSON.stringify([]));
        }

        if (formData.certifications && formData.certifications.trim()) {
            const certsArray = formData.certifications.split(',').map(s => s.trim()).filter(s => s);
            formDataToSend.append('certifications', JSON.stringify(certsArray));
        } else {
            formDataToSend.append('certifications', JSON.stringify([]));
        }

        if (formData.achievements && formData.achievements.trim()) {
            const achievementsArray = formData.achievements.split(',').map(s => s.trim()).filter(s => s);
            formDataToSend.append('achievements', JSON.stringify(achievementsArray));
        } else {
            formDataToSend.append('achievements', JSON.stringify([]));
        }

        if (imageFile) {
            formDataToSend.append('profile_photo', imageFile);
        }

        try {
            if (modalMode === 'add') {
                await createEmployeeMutation.mutateAsync(formDataToSend);
                showNotificationMessage('Staff member added successfully', 'success');
                setShowSuccessAnimation(true);
                setTimeout(() => setShowSuccessAnimation(false), 2000);
                closeModal();
                await refetchEmployees();
                await refetchStats();
            } else if (selectedEmployee) {
                await updateEmployeeMutation.mutateAsync({
                    id: selectedEmployee.id || selectedEmployee.employee_id,
                    data: formDataToSend
                });
                showNotificationMessage('Staff member updated successfully', 'success');
                closeModal();
                await refetchEmployees();
                await refetchStats();
            }
            setCurrentPage(1);
        } catch (err) {
            console.error('Save error:', err);
            if (err.response?.data?.errors) {
                const errors = err.response.data.errors;
                const errorMessages = Object.keys(errors).map(field =>
                    `${field}: ${errors[field].join(', ')}`
                ).join('\n');
                showNotificationMessage(`Validation failed:\n${errorMessages}`, 'error');
            } else if (err.response?.data?.message) {
                const backendDebug = err.response?.data?.debug
                    ? `\nDetails: ${err.response.data.debug}`
                    : '';
                showNotificationMessage(`${err.response.data.message}${backendDebug}`, 'error');
            } else {
                showNotificationMessage('Operation failed. Check console for details.', 'error');
            }
        } finally {
            setIsSubmitting(false);
        }
    };

    // Archive functions
    const handleArchiveClick = (employee) => {
        setEmployeeToDelete(employee);
        setShowDeleteConfirm(true);
    };

    const confirmArchive = async () => {
        if (employeeToDelete) {
            try {
                await bulkUpdateStatusMutation.mutateAsync({ 
                    ids: [employeeToDelete.id || employeeToDelete.employee_id], 
                    status: 'inactive' 
                });
                showNotificationMessage('Staff member archived successfully', 'info');
                setShowDeleteConfirm(false);
                setEmployeeToDelete(null);
                await refetchEmployees();
                await refetchArchived();
                await refetchStats();
                setSelectedEmployees(prev => prev.filter(id => id !== (employeeToDelete.id || employeeToDelete.employee_id)));
                setCurrentPage(1);
            } catch (err) {
                console.error('Archive error:', err);
                showNotificationMessage('Failed to archive employee: ' + (err.response?.data?.message || err.message), 'error');
            }
        }
    };

    const restoreEmployee = async (id) => {
        try {
            await staffAPI.restoreEmployee(id);
            showNotificationMessage('Staff member restored successfully', 'success');
            await refetchEmployees();
            await refetchArchived();
            await refetchStats();
            setCurrentPage(1);
        } catch (err) {
            console.error('Restore error:', err);
            showNotificationMessage('Failed to restore employee: ' + (err.response?.data?.message || err.message), 'error');
        }
    };

    const permanentlyDeleteEmployee = async (id) => {
        if (window.confirm('Are you ABSOLUTELY sure? This action cannot be undone!')) {
            try {
                await staffAPI.deleteEmployee(id);
                showNotificationMessage('Employee permanently deleted', 'warning');
                await refetchEmployees();
                await refetchArchived();
                await refetchStats();
                setCurrentPage(1);
            } catch (err) {
                console.error('Delete error:', err);
                showNotificationMessage('Failed to delete employee: ' + (err.response?.data?.message || err.message), 'error');
            }
        }
    };

    // Bookmark function
    const handleBookmark = async (id) => {
        try {
            await toggleBookmarkMutation.mutateAsync(id);
            showNotificationMessage('Bookmark toggled', 'success');
            await refetchEmployees();
        } catch (err) {
            console.error('Bookmark error:', err);
            showNotificationMessage('Failed to update bookmark', 'error');
        }
    };

    // Status update function
    const handleUpdateStatus = async (employeeId, newStatus) => {
        try {
            await bulkUpdateStatusMutation.mutateAsync({ ids: [employeeId], status: newStatus });
            const statusLabel = statusOptions.find(s => s.value === newStatus)?.label || newStatus;
            showNotificationMessage(`Status updated to ${statusLabel}`, 'success');
            await refetchEmployees();
            await refetchArchived();
            await refetchStats();
            setCurrentPage(1);
        } catch (err) {
            console.error('Status update error:', err);
            showNotificationMessage('Failed to update status: ' + (err.response?.data?.message || err.message), 'error');
        }
        setShowStatusDropdown(null);
    };

    // Bulk operations
    const toggleSelectMode = () => {
        setIsSelectMode(!isSelectMode);
        setSelectedEmployees([]);
    };

    const handleSelectAll = () => {
        if (selectedEmployees.length === paginatedEmployees.length) {
            setSelectedEmployees([]);
        } else {
            setSelectedEmployees(paginatedEmployees.map(emp => emp.id || emp.employee_id));
        }
    };

    const handleSelectEmployee = (employeeId) => {
        setSelectedEmployees(prev =>
            prev.includes(employeeId) ? prev.filter(id => id !== employeeId) : [...prev, employeeId]
        );
    };

    const handleBulkArchive = async () => {
        if (selectedEmployees.length === 0) {
            showNotificationMessage('No employees selected', 'error');
            return;
        }
        try {
            await bulkUpdateStatusMutation.mutateAsync({ ids: selectedEmployees, status: 'inactive' });
            showNotificationMessage(`${selectedEmployees.length} staff member(s) archived`, 'info');
            setSelectedEmployees([]);
            setIsSelectMode(false);
            await refetchEmployees();
            await refetchArchived();
            await refetchStats();
            setCurrentPage(1);
        } catch (err) {
            console.error('Bulk archive error:', err);
            showNotificationMessage('Failed to archive employees: ' + (err.response?.data?.message || err.message), 'error');
        }
    };

    const handleBulkStatusUpdate = async (status) => {
        if (selectedEmployees.length === 0) {
            showNotificationMessage('No employees selected', 'error');
            return;
        }
        try {
            await bulkUpdateStatusMutation.mutateAsync({ ids: selectedEmployees, status });
            const statusLabel = statusOptions.find(s => s.value === status)?.label || status;
            showNotificationMessage(`Status updated to ${statusLabel} for ${selectedEmployees.length} staff members`, 'success');
            setSelectedEmployees([]);
            setIsSelectMode(false);
            await refetchEmployees();
            await refetchArchived();
            await refetchStats();
            setCurrentPage(1);
        } catch (err) {
            console.error('Bulk status update error:', err);
            showNotificationMessage('Failed to update status: ' + (err.response?.data?.message || err.message), 'error');
        }
    };

    // Position CRUD
    const handleAddPosition = () => {
        setPositionFormMode('add');
        resetPositionForm();
        setSelectedPosition(null);
        setShowPositionModal(true);
    };

    const handleEditPosition = (position) => {
        setPositionFormMode('edit');
        setSelectedPosition(position);
        setPositionFormData({
            title: position.title || '',
            department_id: position.department_id || '',
            salary_grade_id: position.salary_grade_id || '',
            description: position.description || '',
            employment_type: position.employment_type || 'full-time',
            max_hours_per_week: position.max_hours_per_week || '40',
            required_skills: Array.isArray(position.required_skills) ? position.required_skills.join(', ') : (position.required_skills || ''),
            status: position.status || 'active'
        });
        setPositionFormErrors({});
        setShowPositionModal(true);
    };

    const handleSavePosition = async () => {
        if (!validatePositionForm()) {
            showNotificationMessage('Please fill in all required fields', 'error');
            return;
        }

        setIsSubmitting(true);

        const data = {
            title: positionFormData.title.trim(),
            department_id: parseInt(positionFormData.department_id),
            salary_grade_id: parseInt(positionFormData.salary_grade_id),
            description: positionFormData.description || '',
            employment_type: positionFormData.employment_type,
            max_hours_per_week: parseInt(positionFormData.max_hours_per_week),
            status: positionFormData.status
        };

        if (positionFormData.required_skills && positionFormData.required_skills.trim()) {
            const skillsArray = positionFormData.required_skills
                .split(',')
                .map(s => s.trim())
                .filter(s => s);
            data.required_skills = skillsArray;
        } else {
            data.required_skills = [];
        }

        console.log('Saving position data:', data);

        try {
            if (positionFormMode === 'add') {
                await createPositionMutation.mutateAsync(data);
                showNotificationMessage('Position created successfully', 'success');
            } else if (selectedPosition) {
                await updatePositionMutation.mutateAsync({ id: selectedPosition.id, data });
                showNotificationMessage('Position updated successfully', 'success');
            }
            setShowPositionModal(false);
            resetPositionForm();
            await refetchPositions();
        } catch (err) {
            console.error('Save position error:', err);
            if (err.response?.data?.errors) {
                const errors = err.response.data.errors;
                const errorMessages = Object.keys(errors).map(field => 
                    `${field}: ${errors[field].join(', ')}`
                ).join('\n');
                showNotificationMessage(`Validation failed:\n${errorMessages}`, 'error');
            } else {
                showNotificationMessage(err.response?.data?.message || 'Failed to save position', 'error');
            }
        } finally {
            setIsSubmitting(false);
        }
    };

    const deletePosition = async (id) => {
        if (!window.confirm('Are you sure you want to delete this position?')) return;
        try {
            await deletePositionMutation.mutateAsync(id);
            showNotificationMessage('Position deleted successfully', 'success');
            await refetchPositions();
        } catch (err) {
            showNotificationMessage('Failed to delete position', 'error');
        }
    };

    // Department CRUD
    const handleAddDepartment = () => {
        setDepartmentFormMode('add');
        resetDepartmentForm();
        setSelectedDepartmentForEdit(null);
        setShowDepartmentModal(true);
    };

    const handleEditDepartment = (department) => {
        setDepartmentFormMode('edit');
        setSelectedDepartmentForEdit(department);
        setDepartmentFormData({
            name: department.name || '',
            code: department.code || '',
            description: department.description || '',
            manager_id: department.manager_id || '',
            status: department.status || 'active'
        });
        setDepartmentFormErrors({});
        setShowDepartmentModal(true);
    };

    const handleSaveDepartment = async () => {
        if (!validateDepartmentForm()) {
            showNotificationMessage('Please fill in all required fields', 'error');
            return;
        }

        setIsSubmitting(true);

        const data = {
            name: departmentFormData.name.trim(),
            code: departmentFormData.code.trim().toUpperCase(),
            description: departmentFormData.description || '',
            manager_id: departmentFormData.manager_id ? parseInt(departmentFormData.manager_id) : null,
            status: departmentFormData.status || 'active'
        };

        try {
            if (departmentFormMode === 'add') {
                await createDepartmentMutation.mutateAsync(data);
                showNotificationMessage('Department created successfully', 'success');
            } else if (selectedDepartmentForEdit) {
                await updateDepartmentMutation.mutateAsync({
                    id: selectedDepartmentForEdit.id,
                    data
                });
                showNotificationMessage('Department updated successfully', 'success');
            }
            setShowDepartmentModal(false);
            resetDepartmentForm();
            await refetchDepartments();
        } catch (err) {
            console.error('Save department error:', err);
            const errorMessage = err.response?.data?.message || err.response?.data?.errors || 'Failed to save department';
            showNotificationMessage(typeof errorMessage === 'string' ? errorMessage : 'Validation failed. Please check your input.', 'error');
        } finally {
            setIsSubmitting(false);
        }
    };

    const deleteDepartment = async (id) => {
        if (!window.confirm('Are you sure you want to delete this department?')) return;
        try {
            await deleteDepartmentMutation.mutateAsync(id);
            showNotificationMessage('Department deleted successfully', 'success');
            await refetchDepartments();
        } catch (err) {
            showNotificationMessage('Failed to delete department', 'error');
        }
    };

    // Salary Grade CRUD
    const handleAddSalaryGrade = () => {
        setSalaryGradeFormMode('add');
        resetSalaryGradeForm();
        setSelectedSalaryGrade(null);
        setShowSalaryGradeModal(true);
    };

    const handleEditSalaryGrade = (grade) => {
        setSalaryGradeFormMode('edit');
        setSelectedSalaryGrade(grade);
        setSalaryGradeFormData({
            grade_name: grade.grade_name || '',
            hourly_rate: grade.hourly_rate || '',
            description: grade.description || '',
            status: grade.status || 'active'
        });
        setSalaryGradeFormErrors({});
        setShowSalaryGradeModal(true);
    };

    const handleSaveSalaryGrade = async () => {
        if (!validateSalaryGradeForm()) {
            showNotificationMessage('Please fill in all required fields', 'error');
            return;
        }

        setIsSubmitting(true);

        const data = {
            grade_name: salaryGradeFormData.grade_name.trim().toUpperCase(),
            hourly_rate: parseFloat(salaryGradeFormData.hourly_rate),
            description: salaryGradeFormData.description || '',
            status: salaryGradeFormData.status
        };

        try {
            if (salaryGradeFormMode === 'add') {
                await createSalaryGradeMutation.mutateAsync(data);
                showNotificationMessage('Salary grade created successfully', 'success');
            } else if (selectedSalaryGrade) {
                await updateSalaryGradeMutation.mutateAsync({ id: selectedSalaryGrade.id, data });
                showNotificationMessage('Salary grade updated successfully', 'success');
            }
            setShowSalaryGradeModal(false);
            resetSalaryGradeForm();
            await refetchSalaryGrades();
        } catch (err) {
            console.error('Save salary grade error:', err);
            showNotificationMessage(err.response?.data?.message || 'Failed to save salary grade', 'error');
        } finally {
            setIsSubmitting(false);
        }
    };

    const deleteSalaryGrade = async (id) => {
        if (!window.confirm('Are you sure you want to delete this salary grade?')) return;
        try {
            await deleteSalaryGradeMutation.mutateAsync(id);
            showNotificationMessage('Salary grade deleted successfully', 'success');
            await refetchSalaryGrades();
        } catch (err) {
            showNotificationMessage('Failed to delete salary grade', 'error');
        }
    };

    // Compliance, Benefits, Reports Data
    const fetchComplianceData = () => {
        const mockCompliance = employees.map(emp => ({
            id: emp.id,
            name: `${emp.first_name} ${emp.last_name}`,
            sss: emp.sss_number || emp.sss || 'Not Provided',
            philhealth: emp.philhealth_number || emp.philhealth || 'Not Provided',
            pagibig: emp.pagibig_number || emp.pagibig || 'Not Provided',
            tin: emp.tin_number || emp.tin || 'Not Provided',
            status: (emp.sss_number || emp.sss) &&
                (emp.philhealth_number || emp.philhealth) &&
                (emp.pagibig_number || emp.pagibig) &&
                (emp.tin_number || emp.tin) ? 'Compliant' : 'Missing Documents',
            last_updated: new Date().toLocaleDateString()
        }));
        setComplianceData(mockCompliance);
        setShowComplianceModal(true);
    };

    const fetchBenefitsData = () => {
        const mockBenefits = employees.map(emp => ({
            id: emp.id,
            name: `${emp.first_name} ${emp.last_name}`,
            health_insurance: emp.employee_type === 'regular' ? 'Enrolled' : 'Not Eligible',
            paid_leaves: emp.status === 'active' ? '15 days/year' : 'N/A',
            thirteenth_month: emp.status === 'active' ? 'Eligible' : 'Not Eligible',
            overtime_pay: emp.employee_type === 'regular' ? '1.5x rate' : '1.25x rate'
        }));
        setBenefitsData(mockBenefits);
        setShowBenefitsModal(true);
    };

    const fetchReportsData = () => {
        const activeCount = employees.filter(e => e.status === 'active').length;
        const onLeaveCount = employees.filter(e => e.status === 'onleave').length;
        const inactiveCount = employees.filter(e => e.status === 'inactive').length;
        const terminatedCount = employees.filter(e => e.status === 'terminated').length;
        
        const departmentStats = departments.map(dept => ({
            name: dept.name,
            count: employees.filter(e => e.department_id === dept.id).length,
            active: employees.filter(e => e.department_id === dept.id && e.status === 'active').length
        }));

        setReportsData({
            summary: {
                total: employees.length,
                active: activeCount,
                onLeave: onLeaveCount,
                inactive: inactiveCount,
                terminated: terminatedCount,
                archived: archivedEmployees.length,
                departments: departments.length,
                positions: positions.length,
                salaryGrades: salaryGrades.length
            },
            departmentStats,
            generated_on: new Date().toLocaleString()
        });
        setShowReportsModal(true);
    };

    // Input handlers
    const handleInputChange = (e) => {
        const { name, value, type, files } = e.target;

        if (type === 'file') {
            const file = files[0];
            if (file) {
                const allowedTypes = ['image/jpeg', 'image/png', 'image/jpg'];
                if (!allowedTypes.includes(file.type)) {
                    showNotificationMessage('Only JPG, PNG images are allowed', 'error');
                    return;
                }
                if (file.size > 2 * 1024 * 1024) {
                    showNotificationMessage('Image size must be less than 2MB', 'error');
                    return;
                }
                const reader = new FileReader();
                reader.onloadend = () => setImagePreview(reader.result);
                reader.readAsDataURL(file);
                setImageFile(file);
                setFormData(prev => ({ ...prev, [name]: file }));

                setUploadProgress(0);
                const interval = setInterval(() => {
                    setUploadProgress(prev => {
                        if (prev >= 100) {
                            clearInterval(interval);
                            return 100;
                        }
                        return prev + 10;
                    });
                }, 100);
            }
        } else {
            setFormData(prev => ({ ...prev, [name]: value }));
            if (formErrors[name]) {
                setFormErrors(prev => {
                    const newErrors = { ...prev };
                    delete newErrors[name];
                    return newErrors;
                });
            }
        }
    };

    const handlePositionFormChange = (e) => {
        const { name, value } = e.target;
        setPositionFormData(prev => ({ ...prev, [name]: value }));
        if (positionFormErrors[name]) {
            setPositionFormErrors(prev => {
                const newErrors = { ...prev };
                delete newErrors[name];
                return newErrors;
            });
        }
    };

    const handleDepartmentFormChange = (e) => {
        const { name, value } = e.target;
        setDepartmentFormData(prev => ({ ...prev, [name]: value }));
        if (departmentFormErrors[name]) {
            setDepartmentFormErrors(prev => {
                const newErrors = { ...prev };
                delete newErrors[name];
                return newErrors;
            });
        }
    };

    const handleSalaryGradeFormChange = (e) => {
        const { name, value } = e.target;
        setSalaryGradeFormData(prev => ({ ...prev, [name]: value }));
        if (salaryGradeFormErrors[name]) {
            setSalaryGradeFormErrors(prev => {
                const newErrors = { ...prev };
                delete newErrors[name];
                return newErrors;
            });
        }
    };

    const handleNextStep = () => {
        if (formStep === 1) {
            if (validateStep1()) {
                setFormStep(prev => prev + 1);
            } else {
                showNotificationMessage('Please fill in all required fields in Personal Information', 'error');
            }
        } else if (formStep === 2) {
            if (validateStep2()) {
                setFormStep(prev => prev + 1);
            } else {
                showNotificationMessage('Please fill in all required fields in Employment Details', 'error');
            }
        } else {
            setFormStep(prev => prev + 1);
        }
    };

    const handlePrevStep = () => setFormStep(prev => prev - 1);

    // Print functions (keep as is - they're fine)
    const handlePrintAll = () => {
        setShowPrintModal(false);
        const printWindow = window.open('', '_blank');
        if (!printWindow) {
            showNotificationMessage('Please allow pop-ups to print', 'error');
            return;
        }

        const currentDateObj = new Date().toLocaleString();
        const companyName = "Catering Staff Management System";
        let tableRows = '';
        const employeesToPrint = filteredEmployees;

        employeesToPrint.forEach(emp => {
            const positionName = emp.position ? (typeof emp.position === 'object' ? (emp.position.title || emp.position.name || 'N/A') : emp.position) : 'N/A';
            const departmentName = emp.department ? (typeof emp.department === 'object' ? (emp.department.name || 'N/A') : emp.department) : 'N/A';
            const statusLabel = statusOptions.find(s => s.value === emp.status)?.label || emp.status || 'Active';
            const hourlyRate = emp.hourly_rate || emp.calculated_hourly_rate || 0;
            const salaryGrade = getEmployeeSalaryGrade(emp);

            tableRows += `
                <tr>
                    <td>${emp.employee_code || emp.id || 'N/A'}</td>
                    <td>${emp.first_name || ''} ${emp.last_name || ''}</td>
                    <td>${departmentName}</td>
                    <td>${positionName}</td>
                    <td>${salaryGrade}</td>
                    <td>${statusLabel}</td>
                    <td>₱${hourlyRate.toLocaleString()}/hr</td>
                    <td>${emp.email || 'N/A'}</td>
                    <td>${emp.phone || 'N/A'}</td>
                </tr>
            `;
        });

        const printHtml = `
            <!DOCTYPE html>
            <html>
            <head>
                <title>Staff Directory Report</title>
                <style>
                    * { margin: 0; padding: 0; box-sizing: border-box; }
                    body { font-family: 'Segoe UI', sans-serif; padding: 40px; background: white; }
                    .print-container { max-width: 1200px; margin: 0 auto; }
                    .header { text-align: center; margin-bottom: 30px; padding-bottom: 20px; border-bottom: 2px solid #2563eb; }
                    .header h1 { font-size: 24px; color: #1e3a8a; }
                    .report-info { display: flex; justify-content: space-between; margin-bottom: 24px; padding: 12px; background: #f3f4f6; border-radius: 8px; }
                    table { width: 100%; border-collapse: collapse; }
                    th { background: #1e3a8a; color: white; padding: 12px; text-align: left; }
                    td { padding: 10px; border-bottom: 1px solid #e5e7eb; }
                    .footer { margin-top: 30px; padding-top: 20px; text-align: center; border-top: 1px solid #e5e7eb; }
                    @media print { body { padding: 20px; } }
                </style>
            </head>
            <body>
                <div class="print-container">
                    <div class="header">
                        <h1>${companyName}</h1>
                        <p>Staff Directory Report</p>
                    </div>
                    <div class="report-info">
                        <span>Generated: <strong>${currentDateObj}</strong></span>
                        <span>Total Staff: <strong>${employeesToPrint.length}</strong></span>
                        <span>Active: <strong>${employeesToPrint.filter(e => e.status === 'active').length}</strong></span>
                    </div>
                    <table>
                        <thead>
                            <tr>
                                <th>ID</th><th>Name</th><th>Department</th><th>Position</th>
                                <th>Salary Grade</th><th>Status</th><th>Hourly Rate</th><th>Email</th><th>Phone</th>
                            </tr>
                        </thead>
                        <tbody>${tableRows}</tbody>
                    </table>
                    <div class="footer">
                        <p>&copy; ${new Date().getFullYear()} Catering Staff Management System. All rights reserved.</p>
                    </div>
                </div>
            </body>
            </html>
        `;

        printWindow.document.write(printHtml);
        printWindow.document.close();
        printWindow.focus();
        printWindow.print();
    };

    const handlePrintProfile = (employee) => {
        const printWindow = window.open('', '_blank');
        if (!printWindow) {
            showNotificationMessage('Please allow pop-ups to print', 'error');
            return;
        }

        const currentDateObj = new Date().toLocaleString();
        const companyName = "Catering Staff Management System";
        const positionName = employee.position ? (typeof employee.position === 'object' ? (employee.position.title || employee.position.name || 'N/A') : employee.position) : 'N/A';
        const departmentName = employee.department ? (typeof employee.department === 'object' ? (employee.department.name || 'N/A') : employee.department) : 'N/A';
        const statusLabel = statusOptions.find(s => s.value === employee.status)?.label || employee.status || 'Active';
        const hourlyRate = employee.hourly_rate || employee.calculated_hourly_rate || 0;
        const salaryGrade = getEmployeeSalaryGrade(employee);

        const printHtml = `
            <!DOCTYPE html>
            <html>
            <head>
                <title>Employee Profile - ${employee.first_name} ${employee.last_name}</title>
                <style>
                    * { margin: 0; padding: 0; box-sizing: border-box; }
                    body { font-family: 'Segoe UI', sans-serif; padding: 40px; background: white; }
                    .print-container { max-width: 800px; margin: 0 auto; }
                    .header { text-align: center; margin-bottom: 30px; padding-bottom: 20px; border-bottom: 2px solid #2563eb; }
                    .header h1 { font-size: 24px; color: #1e3a8a; }
                    .profile-section { margin-bottom: 24px; border: 1px solid #e5e7eb; border-radius: 8px; overflow: hidden; }
                    .profile-section h3 { background: #f3f4f6; padding: 12px 16px; margin: 0; color: #1e3a8a; }
                    .profile-section .content { padding: 16px; display: grid; grid-template-columns: repeat(2, 1fr); gap: 12px; }
                    .profile-section .content .field { display: flex; }
                    .profile-section .content .field .label { font-weight: bold; width: 140px; color: #6b7280; }
                    .profile-section .content .field .value { flex: 1; }
                    .footer { margin-top: 30px; padding-top: 20px; text-align: center; border-top: 1px solid #e5e7eb; }
                    @media print { body { padding: 20px; } }
                </style>
            </head>
            <body>
                <div class="print-container">
                    <div class="header">
                        <h1>${companyName}</h1>
                        <p>Employee Profile</p>
                    </div>
                    <div class="profile-section">
                        <h3>Personal Information</h3>
                        <div class="content">
                            <div class="field"><span class="label">Full Name:</span><span class="value">${employee.first_name} ${employee.last_name}</span></div>
                            <div class="field"><span class="label">Employee ID:</span><span class="value">${employee.employee_code || employee.id}</span></div>
                            <div class="field"><span class="label">Email:</span><span class="value">${employee.email || 'N/A'}</span></div>
                            <div class="field"><span class="label">Phone:</span><span class="value">${employee.phone || 'N/A'}</span></div>
                            <div class="field"><span class="label">Birth Date:</span><span class="value">${employee.birth_date || 'N/A'}</span></div>
                            <div class="field"><span class="label">Gender:</span><span class="value">${employee.gender || 'N/A'}</span></div>
                            <div class="field"><span class="label">Address:</span><span class="value">${employee.address || 'N/A'}</span></div>
                        </div>
                    </div>
                    <div class="profile-section">
                        <h3>Employment Details</h3>
                        <div class="content">
                            <div class="field"><span class="label">Department:</span><span class="value">${departmentName}</span></div>
                            <div class="field"><span class="label">Position:</span><span class="value">${positionName}</span></div>
                            <div class="field"><span class="label">Salary Grade:</span><span class="value">${salaryGrade}</span></div>
                            <div class="field"><span class="label">Hourly Rate:</span><span class="value">₱${hourlyRate.toLocaleString()}/hr</span></div>
                            <div class="field"><span class="label">Employee Type:</span><span class="value">${employee.employee_type || 'Regular'}</span></div>
                            <div class="field"><span class="label">Status:</span><span class="value">${statusLabel}</span></div>
                            <div class="field"><span class="label">Hire Date:</span><span class="value">${employee.hire_date || 'N/A'}</span></div>
                        </div>
                    </div>
                    <div class="profile-section">
                        <h3>Government IDs</h3>
                        <div class="content">
                            <div class="field"><span class="label">SSS:</span><span class="value">${employee.sss_number || employee.sss || 'N/A'}</span></div>
                            <div class="field"><span class="label">PhilHealth:</span><span class="value">${employee.philhealth_number || employee.philhealth || 'N/A'}</span></div>
                            <div class="field"><span class="label">Pag-IBIG:</span><span class="value">${employee.pagibig_number || employee.pagibig || 'N/A'}</span></div>
                            <div class="field"><span class="label">TIN:</span><span class="value">${employee.tin_number || employee.tin || 'N/A'}</span></div>
                        </div>
                    </div>
                    <div class="footer">
                        <p>Report generated on ${currentDateObj}</p>
                    </div>
                </div>
            </body>
            </html>
        `;

        printWindow.document.write(printHtml);
        printWindow.document.close();
        printWindow.focus();
        printWindow.print();
    };

    const handleSort = (key) => {
        setSortConfig(prev => ({
            key,
            direction: prev.key === key && prev.direction === 'asc' ? 'desc' : 'asc'
        }));
        setShowSortMenu(false);
    };

    const toggleFullscreen = () => {
        if (!document.fullscreenElement) {
            document.documentElement.requestFullscreen();
            setShowFullscreen(true);
        } else {
            document.exitFullscreen();
            setShowFullscreen(false);
        }
    };

    // Filter and sort employees
    const filteredEmployees = useMemo(() => {
        return employees;
    }, [employees]);

    const sortedEmployees = useMemo(() => {
        if (sortConfig.key && filteredEmployees.length > 0) {
            return [...filteredEmployees].sort((a, b) => {
                let aValue, bValue;
                if (sortConfig.key === 'name') {
                    aValue = `${a.first_name || ''} ${a.last_name || ''}`.toLowerCase();
                    bValue = `${b.first_name || ''} ${b.last_name || ''}`.toLowerCase();
                } else if (sortConfig.key === 'department') {
                    aValue = a.department?.name || '';
                    bValue = b.department?.name || '';
                } else if (sortConfig.key === 'position') {
                    aValue = a.position?.title || '';
                    bValue = b.position?.title || '';
                } else if (sortConfig.key === 'salary_grade') {
                    aValue = getEmployeeSalaryGrade(a);
                    bValue = getEmployeeSalaryGrade(b);
                } else if (sortConfig.key === 'status') {
                    aValue = a.status || '';
                    bValue = b.status || '';
                } else if (sortConfig.key === 'hourly_rate') {
                    aValue = a.hourly_rate || a.calculated_hourly_rate || 0;
                    bValue = b.hourly_rate || b.calculated_hourly_rate || 0;
                } else {
                    aValue = a[sortConfig.key] || '';
                    bValue = b[sortConfig.key] || '';
                }
                if (aValue < bValue) return sortConfig.direction === 'asc' ? -1 : 1;
                if (aValue > bValue) return sortConfig.direction === 'asc' ? 1 : -1;
                return 0;
            });
        }
        return filteredEmployees;
    }, [filteredEmployees, sortConfig, getEmployeeSalaryGrade]);

    const paginatedEmployees = sortedEmployees;
    const totalPages = pagination?.last_page || Math.ceil(filteredEmployees.length / pageSize);

    const getRowNumber = (index) => {
        return ((pagination?.current_page || currentPage) - 1) * pageSize + index + 1;
    };

    // Pagination handlers
    const goToPage = (page) => {
        setCurrentPage(page);
        setSelectedEmployees([]);
    };

    const nextPage = () => {
        if (currentPage < totalPages) {
            setCurrentPage(currentPage + 1);
            setSelectedEmployees([]);
        }
    };

    const prevPage = () => {
        if (currentPage > 1) {
            setCurrentPage(currentPage - 1);
            setSelectedEmployees([]);
        }
    };

    // Statistics
    const statistics = [
        { label: 'Total Staff', value: stats?.total || employees.length, icon: <TeamOutlined />, change: `${stats?.by_employee_type?.find(t => t.employee_type === 'regular')?.total || 0} regular` },
        { label: 'Active', value: stats?.active || employees.filter(e => e?.status === 'active').length, icon: <CheckCircleOutlined />, change: 'On Duty' },
        { label: 'On Leave', value: stats?.onleave || employees.filter(e => e?.status === 'onleave').length, icon: <ClockCircleOutlined />, change: 'This week' },
        { label: 'Inactive', value: stats?.inactive || 0, icon: <PauseCircleOutlined />, change: 'Archived' },
        { label: 'Archived', value: archivedEmployees.length, icon: <InboxOutlined />, change: 'Soft deleted' },
    ];

    const quickActions = [
        { icon: <DollarOutlined />, label: 'Salary Grades', action: handleAddSalaryGrade },
        { icon: <SafetyOutlined />, label: 'Compliance', action: fetchComplianceData },
        { icon: <HeartOutlined />, label: 'Benefits', action: fetchBenefitsData },
        { icon: <FileTextOutlined />, label: 'Reports', action: fetchReportsData },
        { icon: <BuildOutlined />, label: 'Positions', action: handleAddPosition },
        { icon: <BankOutlined />, label: 'Departments', action: handleAddDepartment },
        { icon: <HistoryOutlined />, label: 'Archive', action: () => setShowArchive(!showArchive) },
        { icon: <PrinterOutlined />, label: 'Print All', action: () => setShowPrintModal(true) },
        { icon: isSelectMode ? <CloseOutlined /> : <CheckSquareOutlined />, label: isSelectMode ? 'Cancel Select' : 'Select Mode', action: toggleSelectMode }
    ];

    // Set current date
    useEffect(() => {
        const date = new Date();
        setCurrentDate(date.toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' }));
    }, []);

    // Status Filter Modal Component
    const StatusFilterModal = () => (
        <div className="catering-modal-overlay" onClick={() => setShowStatusFilterModal(false)}>
            <div className="catering-modal catering-small-modal" onClick={(e) => e.stopPropagation()}>
                <div className="catering-modal-header">
                    <h2><FilterOutlined /> Filter by Status</h2>
                    <button className="catering-modal-close" onClick={() => setShowStatusFilterModal(false)}><CloseOutlined /></button>
                </div>
                <div className="catering-modal-body">
                    <div className="catering-status-filter-options">
                        <button 
                            className={`catering-status-filter-option ${selectedTab === 'all' ? 'active' : ''}`}
                            onClick={() => { setSelectedTab('all'); setSelectedStatus('all'); setShowStatusFilterModal(false); setCurrentPage(1); }}
                        >
                            <CheckCircleOutlined /> All Staff
                        </button>
                        <button 
                            className={`catering-status-filter-option ${selectedTab === 'active' ? 'active' : ''}`}
                            onClick={() => { setSelectedTab('active'); setSelectedStatus('active'); setShowStatusFilterModal(false); setCurrentPage(1); }}
                        >
                            <CheckCircleOutlined /> Active
                        </button>
                        <button 
                            className={`catering-status-filter-option ${selectedTab === 'onleave' ? 'active' : ''}`}
                            onClick={() => { setSelectedTab('onleave'); setSelectedStatus('onleave'); setShowStatusFilterModal(false); setCurrentPage(1); }}
                        >
                            <ClockCircleOutlined /> On Leave
                        </button>
                        <button 
                            className={`catering-status-filter-option ${selectedTab === 'inactive' ? 'active' : ''}`}
                            onClick={() => { setSelectedTab('inactive'); setSelectedStatus('inactive'); setShowStatusFilterModal(false); setCurrentPage(1); }}
                        >
                            <PauseCircleOutlined /> Inactive (Archived)
                        </button>
                        <button 
                            className={`catering-status-filter-option ${selectedTab === 'terminated' ? 'active' : ''}`}
                            onClick={() => { setSelectedTab('terminated'); setSelectedStatus('terminated'); setShowStatusFilterModal(false); setCurrentPage(1); }}
                        >
                            <WarningOutlined /> Terminated
                        </button>
                        <button 
                            className={`catering-status-filter-option ${selectedTab === 'bookmarked' ? 'active' : ''}`}
                            onClick={() => { setSelectedTab('bookmarked'); setSelectedStatus('all'); setShowStatusFilterModal(false); setCurrentPage(1); }}
                        >
                            <StarOutlined /> Bookmarked
                        </button>
                    </div>
                </div>
            </div>
        </div>
    );

    
    // ==================== MODAL RENDER FUNCTIONS ====================

    // Status Controls Component
    const StatusControls = ({ employee }) => (
        <div className="catering-status-controls">
            <h3><ClockCircleOutlined /> Staff Status</h3>
            <div className="catering-status-buttons">
                <button
                    className={`catering-status-btn ${employee.status === 'active' ? 'active' : ''}`}
                    onClick={() => handleUpdateStatus(employee.id || employee.employee_id, 'active')}
                >
                    <CheckCircleOutlined /> Active
                </button>
                <button
                    className={`catering-status-btn ${employee.status === 'onleave' ? 'onleave' : ''}`}
                    onClick={() => handleUpdateStatus(employee.id || employee.employee_id, 'onleave')}
                >
                    <PauseCircleOutlined /> On Leave
                </button>
                <button
                    className={`catering-status-btn ${employee.status === 'inactive' ? 'inactive' : ''}`}
                    onClick={() => handleUpdateStatus(employee.id || employee.employee_id, 'inactive')}
                >
                    <InboxOutlined /> Inactive
                </button>
                <button
                    className={`catering-status-btn ${employee.status === 'terminated' ? 'terminated' : ''}`}
                    onClick={() => handleUpdateStatus(employee.id || employee.employee_id, 'terminated')}
                >
                    <WarningOutlined /> Terminated
                </button>
            </div>
            <p className="catering-status-note">
                Current Status: <strong>
                    {statusOptions.find(s => s.value === employee.status)?.label || employee.status || 'Active'}
                </strong>
            </p>
        </div>
    );

    // Notification Component
    const Notification = () => (
        <div className={`catering-notification ${notificationType}`}>
            <div className="catering-notification-icon">
                {notificationType === 'success' && <CheckCircleOutlined />}
                {notificationType === 'error' && <WarningOutlined />}
                {notificationType === 'info' && <ExclamationCircleOutlined />}
            </div>
            <div className="catering-notification-message">{notificationMessage}</div>
            <button className="catering-notification-close" onClick={() => setShowNotification(false)}><CloseOutlined /></button>
        </div>
    );

    // Salary Grade Modal
    const renderSalaryGradeModal = () => (
        <div className="catering-modal-overlay" onClick={() => setShowSalaryGradeModal(false)}>
            <div className="catering-modal catering-medium-modal" onClick={(e) => e.stopPropagation()}>
                <div className="catering-modal-header">
                    <h2>{salaryGradeFormMode === 'add' ? 'Add New Salary Grade' : 'Edit Salary Grade'}</h2>
                    <button className="catering-modal-close" onClick={() => setShowSalaryGradeModal(false)}><CloseOutlined /></button>
                </div>
                <div className="catering-modal-body">
                    <form className="catering-form" onSubmit={(e) => e.preventDefault()}>
                        <div className="catering-form-section">
                            <h3><DollarOutlined /> Salary Grade Details</h3>
                            <div className="catering-form-row">
                                <div className="catering-form-group">
                                    <label>Grade Name *</label>
                                    <input
                                        type="text"
                                        name="grade_name"
                                        className={`catering-form-input ${salaryGradeFormErrors.grade_name ? 'error' : ''}`}
                                        value={salaryGradeFormData.grade_name}
                                        onChange={handleSalaryGradeFormChange}
                                        placeholder="e.g., A, B, C, or Grade 1, Grade 2"
                                    />
                                    {salaryGradeFormErrors.grade_name && <span className="catering-form-error">{salaryGradeFormErrors.grade_name}</span>}
                                </div>
                                <div className="catering-form-group">
                                    <label>Hourly Rate (₱) *</label>
                                    <input
                                        type="number"
                                        name="hourly_rate"
                                        className={`catering-form-input ${salaryGradeFormErrors.hourly_rate ? 'error' : ''}`}
                                        value={salaryGradeFormData.hourly_rate}
                                        onChange={handleSalaryGradeFormChange}
                                        min="0"
                                        step="10"
                                        placeholder="e.g., 125.00"
                                    />
                                    {salaryGradeFormErrors.hourly_rate && <span className="catering-form-error">{salaryGradeFormErrors.hourly_rate}</span>}
                                </div>
                            </div>
                            <div className="catering-form-group">
                                <label>Description</label>
                                <textarea
                                    name="description"
                                    className="catering-form-textarea"
                                    value={salaryGradeFormData.description}
                                    onChange={handleSalaryGradeFormChange}
                                    rows="3"
                                    placeholder="Optional description of this salary grade"
                                />
                            </div>
                            <div className="catering-form-group">
                                <label>Status</label>
                                <select name="status" className="catering-form-select" value={salaryGradeFormData.status} onChange={handleSalaryGradeFormChange}>
                                    <option value="active">Active</option>
                                    <option value="inactive">Inactive</option>
                                </select>
                            </div>
                        </div>

                        {salaryGrades.length > 0 && (
                            <div className="catering-form-section">
                                <h3><DollarOutlined /> Existing Salary Grades</h3>
                                <div className="catering-salary-grades-list">
                                    {salaryGrades.map(grade => (
                                        <div key={grade.id} className="catering-salary-grade-item">
                                            <div className="catering-salary-grade-info">
                                                <h4>Grade {grade.grade_name}</h4>
                                                <p>₱{(grade.hourly_rate || 0).toLocaleString()}/hour</p>
                                                <small>Monthly: ₱{((grade.hourly_rate || 0) * 160).toLocaleString()}</small>
                                                <small className={grade.status === 'active' ? 'status-active' : 'status-inactive'}>{grade.status}</small>
                                            </div>
                                            <div className="catering-salary-grade-actions">
                                                <button className="catering-action-btn edit" onClick={() => handleEditSalaryGrade(grade)}><EditOutlined /></button>
                                                <button className="catering-action-btn delete" onClick={() => deleteSalaryGrade(grade.id)}><DeleteOutlined /></button>
                                            </div>
                                        </div>
                                    ))}
                                </div>
                            </div>
                        )}
                    </form>
                </div>
                <div className="catering-modal-footer">
                    <button className="catering-btn" onClick={() => setShowSalaryGradeModal(false)}>Cancel</button>
                    <button className="catering-btn catering-btn-primary" onClick={handleSaveSalaryGrade} disabled={isSubmitting}>
                        {isSubmitting ? <LoadingOutlined spin /> : (salaryGradeFormMode === 'add' ? 'Add Salary Grade' : 'Save Changes')}
                    </button>
                </div>
            </div>
        </div>
    );

    // Position Modal
    const renderPositionModal = () => (
        <div className="catering-modal-overlay" onClick={() => setShowPositionModal(false)}>
            <div className="catering-modal catering-medium-modal" onClick={(e) => e.stopPropagation()}>
                <div className="catering-modal-header">
                    <h2>{positionFormMode === 'add' ? 'Add New Position' : 'Edit Position'}</h2>
                    <button className="catering-modal-close" onClick={() => setShowPositionModal(false)}><CloseOutlined /></button>
                </div>
                <div className="catering-modal-body">
                    <form className="catering-form" onSubmit={(e) => e.preventDefault()}>
                        <div className="catering-form-section">
                            <h3><BuildOutlined /> Position Details</h3>
                            <div className="catering-form-row">
                                <div className="catering-form-group">
                                    <label>Position Title *</label>
                                    <input
                                        type="text"
                                        name="title"
                                        className={`catering-form-input ${positionFormErrors.title ? 'error' : ''}`}
                                        value={positionFormData.title}
                                        onChange={handlePositionFormChange}
                                    />
                                    {positionFormErrors.title && <span className="catering-form-error">{positionFormErrors.title}</span>}
                                </div>
                                <div className="catering-form-group">
                                    <label>Department *</label>
                                    <select
                                        name="department_id"
                                        className={`catering-form-select ${positionFormErrors.department_id ? 'error' : ''}`}
                                        value={positionFormData.department_id}
                                        onChange={handlePositionFormChange}
                                    >
                                        <option value="">Select Department</option>
                                        {departments.map(dept => <option key={dept.id} value={dept.id}>{dept.name}</option>)}
                                    </select>
                                    {positionFormErrors.department_id && <span className="catering-form-error">{positionFormErrors.department_id}</span>}
                                </div>
                            </div>
                            <div className="catering-form-group">
                                <label>Salary Grade *</label>
                                <select
                                    name="salary_grade_id"
                                    className={`catering-form-select ${positionFormErrors.salary_grade_id ? 'error' : ''}`}
                                    value={positionFormData.salary_grade_id}
                                    onChange={handlePositionFormChange}
                                >
                                    <option value="">Select Salary Grade</option>
                                    {salaryGrades.filter(g => g.status === 'active').map(grade => (
                                        <option key={grade.id} value={grade.id}>
                                            Grade {grade.grade_name} - ₱{(grade.hourly_rate || 0).toLocaleString()}/hour
                                        </option>
                                    ))}
                                </select>
                                {positionFormErrors.salary_grade_id && <span className="catering-form-error">{positionFormErrors.salary_grade_id}</span>}
                            </div>
                            <div className="catering-form-group">
                                <label>Description</label>
                                <textarea
                                    name="description"
                                    className="catering-form-textarea"
                                    value={positionFormData.description}
                                    onChange={handlePositionFormChange}
                                    rows="3"
                                />
                            </div>
                            <div className="catering-form-row">
                                <div className="catering-form-group">
                                    <label>Employment Type</label>
                                    <select
                                        name="employment_type"
                                        className="catering-form-select"
                                        value={positionFormData.employment_type}
                                        onChange={handlePositionFormChange}
                                    >
                                        {positionEmploymentTypes.map(option => (
                                            <option key={option.value} value={option.value}>{option.label}</option>
                                        ))}
                                    </select>
                                </div>
                                <div className="catering-form-group">
                                    <label>Max Hours/Week</label>
                                    <input
                                        type="number"
                                        name="max_hours_per_week"
                                        className="catering-form-input"
                                        value={positionFormData.max_hours_per_week}
                                        onChange={handlePositionFormChange}
                                        min="1"
                                        max="168"
                                    />
                                </div>
                            </div>
                            <div className="catering-form-group">
                                <label>Required Skills (comma separated)</label>
                                <input
                                    type="text"
                                    name="required_skills"
                                    className="catering-form-input"
                                    value={positionFormData.required_skills}
                                    onChange={handlePositionFormChange}
                                    placeholder="e.g., Leadership, Communication, Project Management"
                                />
                            </div>
                            <div className="catering-form-group">
                                <label>Status</label>
                                <select
                                    name="status"
                                    className="catering-form-select"
                                    value={positionFormData.status}
                                    onChange={handlePositionFormChange}
                                >
                                    <option value="active">Active</option>
                                    <option value="inactive">Inactive</option>
                                </select>
                            </div>
                        </div>

                        {positions.length > 0 && (
                            <div className="catering-form-section">
                                <h3><BuildOutlined /> Existing Positions</h3>
                                <div className="catering-positions-list">
                                    {positions.map(pos => {
                                        let titleString = '';
                                        if (typeof pos.title === 'string') {
                                            titleString = pos.title;
                                        } else if (pos.title && typeof pos.title === 'object') {
                                            titleString = pos.title.title || pos.title.name || `Position ${pos.id}`;
                                        } else if (typeof pos.name === 'string') {
                                            titleString = pos.name;
                                        } else {
                                            titleString = `Position ${pos.id}`;
                                        }

                                        let deptName = '';
                                        if (pos.department) {
                                            if (typeof pos.department === 'string') {
                                                deptName = pos.department;
                                            } else if (typeof pos.department === 'object') {
                                                deptName = pos.department.name || `Department ${pos.department_id}`;
                                            }
                                        } else if (pos.department_id) {
                                            const foundDept = departments.find(d => d.id === pos.department_id);
                                            deptName = foundDept?.name || `Department ID: ${pos.department_id}`;
                                        } else {
                                            deptName = 'No Department';
                                        }

                                        let salaryInfo = '';
                                        if (pos.salary_grade) {
                                            salaryInfo = `Grade ${pos.salary_grade.grade_name} - ₱${(pos.salary_grade.hourly_rate || 0).toLocaleString()}/hour`;
                                        } else if (pos.salary_grade_id) {
                                            const sg = salaryGrades.find(s => s.id === pos.salary_grade_id);
                                            if (sg) {
                                                salaryInfo = `Grade ${sg.grade_name} - ₱${(sg.hourly_rate || 0).toLocaleString()}/hour`;
                                            }
                                        }

                                        return (
                                            <div key={pos.id} className="catering-position-item">
                                                <div className="catering-position-info">
                                                    <h4>{titleString}</h4>
                                                    <p>{deptName}</p>
                                                    <small>{salaryInfo}</small>
                                                </div>
                                                <div className="catering-position-actions">
                                                    <button className="catering-action-btn edit" onClick={() => handleEditPosition(pos)}><EditOutlined /></button>
                                                    <button className="catering-action-btn delete" onClick={() => deletePosition(pos.id)}><DeleteOutlined /></button>
                                                </div>
                                            </div>
                                        );
                                    })}
                                </div>
                            </div>
                        )}
                    </form>
                </div>
                <div className="catering-modal-footer">
                    <button className="catering-btn" onClick={() => setShowPositionModal(false)}>Cancel</button>
                    <button className="catering-btn catering-btn-primary" onClick={handleSavePosition} disabled={isSubmitting}>
                        {isSubmitting ? <LoadingOutlined spin /> : (positionFormMode === 'add' ? 'Add Position' : 'Save Changes')}
                    </button>
                </div>
            </div>
        </div>
    );

    // Department Modal
    const renderDepartmentModal = () => (
        <div className="catering-modal-overlay" onClick={() => setShowDepartmentModal(false)}>
            <div className="catering-modal catering-medium-modal" onClick={(e) => e.stopPropagation()}>
                <div className="catering-modal-header">
                    <h2>{departmentFormMode === 'add' ? 'Add New Department' : 'Edit Department'}</h2>
                    <button className="catering-modal-close" onClick={() => setShowDepartmentModal(false)}><CloseOutlined /></button>
                </div>
                <div className="catering-modal-body">
                    <form className="catering-form" onSubmit={(e) => e.preventDefault()}>
                        <div className="catering-form-section">
                            <h3><BankOutlined /> Department Details</h3>
                            <div className="catering-form-row">
                                <div className="catering-form-group">
                                    <label>Department Name *</label>
                                    <input
                                        type="text"
                                        name="name"
                                        className={`catering-form-input ${departmentFormErrors.name ? 'error' : ''}`}
                                        value={departmentFormData.name}
                                        onChange={handleDepartmentFormChange}
                                    />
                                    {departmentFormErrors.name && <span className="catering-form-error">{departmentFormErrors.name}</span>}
                                </div>
                                <div className="catering-form-group">
                                    <label>Department Code *</label>
                                    <input
                                        type="text"
                                        name="code"
                                        className={`catering-form-input ${departmentFormErrors.code ? 'error' : ''}`}
                                        value={departmentFormData.code}
                                        onChange={handleDepartmentFormChange}
                                        placeholder="e.g., HR, IT, SALES"
                                    />
                                    {departmentFormErrors.code && <span className="catering-form-error">{departmentFormErrors.code}</span>}
                                </div>
                            </div>
                            <div className="catering-form-group">
                                <label>Description</label>
                                <textarea name="description" className="catering-form-textarea" value={departmentFormData.description} onChange={handleDepartmentFormChange} rows="3" />
                            </div>
                            <div className="catering-form-group">
                                <label>Department Manager</label>
                                <select name="manager_id" className="catering-form-select" value={departmentFormData.manager_id} onChange={handleDepartmentFormChange}>
                                    <option value="">Select Manager</option>
                                    {employees.filter(e => e.status === 'active').map(emp => (
                                        <option key={emp.id} value={emp.id}>{emp.first_name} {emp.last_name}</option>
                                    ))}
                                </select>
                            </div>
                            <div className="catering-form-group">
                                <label>Status</label>
                                <select name="status" className="catering-form-select" value={departmentFormData.status} onChange={handleDepartmentFormChange}>
                                    <option value="active">Active</option>
                                    <option value="inactive">Inactive</option>
                                </select>
                            </div>
                        </div>

                        {departments.length > 0 && (
                            <div className="catering-form-section">
                                <h3><BankOutlined /> Existing Departments</h3>
                                <div className="catering-departments-list">
                                    {departments.map(dept => (
                                        <div key={dept.id} className="catering-department-item">
                                            <div className="catering-department-info">
                                                <h4>{dept.name}</h4>
                                                <p>Code: {dept.code}</p>
                                                <small>{dept.employees?.length || 0} employees</small>
                                            </div>
                                            <div className="catering-department-actions">
                                                <button className="catering-action-btn edit" onClick={() => handleEditDepartment(dept)}><EditOutlined /></button>
                                                <button className="catering-action-btn delete" onClick={() => deleteDepartment(dept.id)}><DeleteOutlined /></button>
                                            </div>
                                        </div>
                                    ))}
                                </div>
                            </div>
                        )}
                    </form>
                </div>
                <div className="catering-modal-footer">
                    <button className="catering-btn" onClick={() => setShowDepartmentModal(false)}>Cancel</button>
                    <button className="catering-btn catering-btn-primary" onClick={handleSaveDepartment} disabled={isSubmitting}>
                        {isSubmitting ? <LoadingOutlined spin /> : (departmentFormMode === 'add' ? 'Add Department' : 'Save Changes')}
                    </button>
                </div>
            </div>
        </div>
    );

    // Compliance Modal
    const renderComplianceModal = () => (
        <div className="catering-modal-overlay" onClick={() => setShowComplianceModal(false)}>
            <div className="catering-modal catering-large-modal" onClick={(e) => e.stopPropagation()}>
                <div className="catering-modal-header">
                    <h2><SafetyOutlined /> Staff Compliance Overview</h2>
                    <button className="catering-modal-close" onClick={() => setShowComplianceModal(false)}><CloseOutlined /></button>
                </div>
                <div className="catering-modal-body">
                    <div className="catering-compliance-summary">
                        <div className="catering-compliance-stat">
                            <span>✅ Compliant</span>
                            <strong>{complianceData.filter(c => c.status === 'Compliant').length}</strong>
                        </div>
                        <div className="catering-compliance-stat warning">
                            <span>⚠️ Missing Documents</span>
                            <strong>{complianceData.filter(c => c.status === 'Missing Documents').length}</strong>
                        </div>
                        <div className="catering-compliance-stat total">
                            <span>📋 Total Employees</span>
                            <strong>{complianceData.length}</strong>
                        </div>
                    </div>

                    <div className="catering-compliance-table-container">
                        <table className="catering-compliance-table">
                            <thead>
                                <tr><th>Employee</th><th>SSS</th><th>PhilHealth</th><th>Pag-IBIG</th><th>TIN</th><th>Status</th><th>Last Updated</th></tr>
                            </thead>
                            <tbody>
                                {complianceData.map(emp => (
                                    <tr key={emp.id}>
                                        <td><strong>{emp.name}</strong></td>
                                        <td className={emp.sss === 'Not Provided' ? 'missing' : 'provided'}>
                                            {emp.sss === 'Not Provided' ? '❌ Not Provided' : '✅ ' + emp.sss}
                                        </td>
                                        <td className={emp.philhealth === 'Not Provided' ? 'missing' : 'provided'}>
                                            {emp.philhealth === 'Not Provided' ? '❌ Not Provided' : '✅ ' + emp.philhealth}
                                        </td>
                                        <td className={emp.pagibig === 'Not Provided' ? 'missing' : 'provided'}>
                                            {emp.pagibig === 'Not Provided' ? '❌ Not Provided' : '✅ ' + emp.pagibig}
                                        </td>
                                        <td className={emp.tin === 'Not Provided' ? 'missing' : 'provided'}>
                                            {emp.tin === 'Not Provided' ? '❌ Not Provided' : '✅ ' + emp.tin}
                                        </td>
                                        <td><span className={`compliance-status ${emp.status === 'Compliant' ? 'compliant' : 'missing'}`}>{emp.status === 'Compliant' ? '✓ Compliant' : '⚠ Missing'}</span></td>
                                        <td>{emp.last_updated}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>

                    <div className="catering-compliance-legend">
                        <h4>Legend:</h4>
                        <div className="catering-legend-items">
                            <span className="catering-legend-item"><span className="catering-legend-color compliant"></span> Compliant - All IDs provided</span>
                            <span className="catering-legend-item"><span className="catering-legend-color missing"></span> Missing - One or more IDs missing</span>
                        </div>
                    </div>
                </div>
                <div className="catering-modal-footer">
                    <button className="catering-btn" onClick={() => setShowComplianceModal(false)}>Close</button>
                    <button className="catering-btn catering-btn-primary" onClick={() => { setShowComplianceModal(false); showNotificationMessage('Compliance report exported', 'success'); }}>
                        <FileTextOutlined /> Export Report
                    </button>
                </div>
            </div>
        </div>
    );

    // Benefits Modal
    const renderBenefitsModal = () => (
        <div className="catering-modal-overlay" onClick={() => setShowBenefitsModal(false)}>
            <div className="catering-modal catering-large-modal" onClick={(e) => e.stopPropagation()}>
                <div className="catering-modal-header">
                    <h2><HeartOutlined /> Employee Benefits Summary</h2>
                    <button className="catering-modal-close" onClick={() => setShowBenefitsModal(false)}><CloseOutlined /></button>
                </div>
                <div className="catering-modal-body">
                    <div className="catering-benefits-summary">
                        <div className="catering-benefits-card"><h4>Health Insurance</h4><p><strong>{benefitsData.filter(b => b.health_insurance === 'Enrolled').length}</strong> enrolled</p><small>{benefitsData.filter(b => b.health_insurance === 'Not Eligible').length} not eligible</small></div>
                        <div className="catering-benefits-card"><h4>Paid Leaves</h4><p>15 days/year for regular employees</p></div>
                        <div className="catering-benefits-card"><h4>13th Month Pay</h4><p><strong>{benefitsData.filter(b => b.thirteenth_month === 'Eligible').length}</strong> eligible</p></div>
                    </div>
                    <div className="catering-benefits-table-container">
                        <table className="catering-benefits-table">
                            <thead>
                                <tr><th>Employee</th><th>Health Insurance</th><th>Paid Leaves</th><th>13th Month</th><th>Overtime Pay</th></tr>
                            </thead>
                            <tbody>
                                {benefitsData.map(emp => (
                                    <tr key={emp.id}>
                                        <td><strong>{emp.name}</strong></td>
                                        <td><span className={`benefit-status ${emp.health_insurance === 'Enrolled' ? 'enrolled' : 'not-eligible'}`}>{emp.health_insurance}</span></td>
                                        <td>{emp.paid_leaves}</td>
                                        <td><span className={`benefit-status ${emp.thirteenth_month === 'Eligible' ? 'eligible' : 'not-eligible'}`}>{emp.thirteenth_month}</span></td>
                                        <td>{emp.overtime_pay}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </div>
                <div className="catering-modal-footer">
                    <button className="catering-btn" onClick={() => setShowBenefitsModal(false)}>Close</button>
                    <button className="catering-btn catering-btn-primary" onClick={() => { setShowBenefitsModal(false); showNotificationMessage('Benefits summary exported', 'success'); }}>Export Summary</button>
                </div>
            </div>
        </div>
    );

    // Reports Modal
    const renderReportsModal = () => (
        <div className="catering-modal-overlay" onClick={() => setShowReportsModal(false)}>
            <div className="catering-modal catering-large-modal" onClick={(e) => e.stopPropagation()}>
                <div className="catering-modal-header">
                    <h2><BarChartOutlined /> Staff Reports Dashboard</h2>
                    <button className="catering-modal-close" onClick={() => setShowReportsModal(false)}><CloseOutlined /></button>
                </div>
                <div className="catering-modal-body">
                    <div className="catering-reports-summary">
                        <div className="catering-report-card"><h4>Total Staff</h4><p className="report-number">{reportsData.summary?.total || 0}</p></div>
                        <div className="catering-report-card success"><h4>Active Staff</h4><p className="report-number">{reportsData.summary?.active || 0}</p></div>
                        <div className="catering-report-card warning"><h4>On Leave</h4><p className="report-number">{reportsData.summary?.onLeave || 0}</p></div>
                        <div className="catering-report-card info"><h4>Inactive</h4><p className="report-number">{reportsData.summary?.inactive || 0}</p></div>
                        <div className="catering-report-card danger"><h4>Terminated</h4><p className="report-number">{reportsData.summary?.terminated || 0}</p></div>
                    </div>
                    <div className="catering-reports-details">
                        <div className="catering-reports-section">
                            <h4>Departments Overview</h4>
                            <table className="catering-report-table">
                                <thead>
                                    <tr><th>Department</th><th>Total Staff</th><th>Active</th><th>Utilization</th></tr>
                                </thead>
                                <tbody>
                                                                     {reportsData.departmentStats?.map((dept, i) => (
                                        <tr key={i}>
                                            <td>{dept.name}</td>
                                            <td>{dept.count}</td>
                                            <td>{dept.active}</td>
                                            <td>
                                                <div className="progress-bar">
                                                    <div className="progress-fill" style={{ width: `${dept.count ? (dept.active / dept.count) * 100 : 0}%` }}></div>
                                                </div>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                        <div className="catering-reports-section">
                            <h4>Organization Summary</h4>
                            <p><strong>Departments:</strong> {reportsData.summary?.departments || 0}</p>
                            <p><strong>Positions:</strong> {reportsData.summary?.positions || 0}</p>
                            <p><strong>Salary Grades:</strong> {reportsData.summary?.salaryGrades || 0}</p>
                            <p><strong>Report Generated:</strong> {reportsData.generated_on}</p>
                        </div>
                    </div>
                </div>
                <div className="catering-modal-footer">
                    <button className="catering-btn" onClick={() => setShowReportsModal(false)}>Close</button>
                    <button className="catering-btn catering-btn-primary" onClick={() => { setShowReportsModal(false); showNotificationMessage('Report exported', 'success'); }}>Export Report</button>
                </div>
            </div>
        </div>
    );

    // Employee Modal (View/Add/Edit)
    const renderEmployeeModal = () => (
        <div className="catering-modal-overlay" onClick={() => modalMode === 'view' && closeModal()}>
            <div className={`catering-modal ${modalMode === 'view' ? 'catering-profile-modal' : ''}`} onClick={(e) => e.stopPropagation()}>
                <div className="catering-modal-header">
                    <h2>
                        {modalMode === 'add' && 'Add New Staff Member'}
                        {modalMode === 'edit' && 'Edit Staff Information'}
                        {modalMode === 'view' && 'Staff Profile'}
                    </h2>
                    {modalMode !== 'view' && (
                        <div className="catering-modal-progress">
                            <span className={formStep >= 1 ? 'active' : ''}>1. Personal</span>
                            <span className={formStep >= 2 ? 'active' : ''}>2. Employment</span>
                            <span className={formStep >= 3 ? 'active' : ''}>3. Government</span>
                            <span className={formStep >= 4 ? 'active' : ''}>4. Skills & Emergency</span>
                        </div>
                    )}
                    <button className="catering-modal-close" onClick={closeModal}><CloseOutlined /></button>
                </div>

                <div className="catering-modal-body">
                    {modalMode === 'view' && selectedEmployee ? (
                        <div className="catering-profile">
                            <div className="catering-profile-header">
                                <div className="catering-profile-avatar">
                                    {selectedEmployee.profile_photo_url ? (
                                        <img src={selectedEmployee.profile_photo_url} alt={selectedEmployee.first_name} style={{ width: '120px', height: '120px', borderRadius: '50%', objectFit: 'cover', border: '4px solid white', boxShadow: '0 4px 12px rgba(0,0,0,0.1)' }} />
                                    ) : (
                                        <div className="catering-profile-avatar-placeholder">
                                            {selectedEmployee.first_name?.[0]?.toUpperCase() || ''}{selectedEmployee.last_name?.[0]?.toUpperCase() || ''}
                                        </div>
                                    )}
                                    {selectedEmployee.is_bookmarked && <StarOutlined className="catering-profile-bookmark" />}
                                </div>
                                <h2 className="catering-profile-name">{selectedEmployee.first_name} {selectedEmployee.last_name}</h2>
                                <p className="catering-profile-title">{selectedEmployee.position?.title || 'N/A'}</p>
                                <div className="catering-profile-badges">
                                    <span className="catering-profile-badge"><BankOutlined /> {selectedEmployee.department?.name || 'N/A'}</span>
                                    <span className="catering-profile-badge"><DollarOutlined /> Grade: {getEmployeeSalaryGrade(selectedEmployee)}</span>
                                </div>
                            </div>

                            <StatusControls employee={selectedEmployee} />

                            <div className="catering-profile-sections">
                                <div className="catering-profile-section">
                                    <h3><UserOutlined /> Personal Information</h3>
                                    <div className="catering-profile-grid">
                                        <div><label>Email</label><span>{selectedEmployee.email}</span></div>
                                        <div><label>Phone</label><span>{selectedEmployee.phone}</span></div>
                                        <div><label>Address</label><span>{selectedEmployee.address}</span></div>
                                        <div><label>Emergency Contact</label><span>{selectedEmployee.emergency_contact_name || selectedEmployee.emergency_contact} ({selectedEmployee.emergency_contact_relation || selectedEmployee.emergency_relation})<br/>{selectedEmployee.emergency_contact_phone || selectedEmployee.emergency_phone}</span></div>
                                    </div>
                                </div>

                                <div className="catering-profile-section">
                                    <h3><BankOutlined /> Employment Details</h3>
                                    <div className="catering-profile-grid">
                                        <div><label>Hire Date</label><span>{selectedEmployee.hire_date}</span></div>
                                        <div><label>Employee Type</label><span>{selectedEmployee.employee_type}</span></div>
                                        <div><label>Hourly Rate</label><span>₱{(selectedEmployee.hourly_rate || selectedEmployee.calculated_hourly_rate || 0).toLocaleString()}/hour</span></div>
                                        <div><label>Bank Account</label><span>{selectedEmployee.bank_name} - {selectedEmployee.bank_account_number || selectedEmployee.bank_account}</span></div>
                                    </div>
                                </div>

                                <div className="catering-profile-section">
                                    <h3><SafetyOutlined /> Government IDs</h3>
                                    <div className="catering-profile-grid">
                                        <div><label>SSS</label><span>{selectedEmployee.sss_number || selectedEmployee.sss || 'N/A'}</span></div>
                                        <div><label>PhilHealth</label><span>{selectedEmployee.philhealth_number || selectedEmployee.philhealth || 'N/A'}</span></div>
                                        <div><label>Pag-IBIG</label><span>{selectedEmployee.pagibig_number || selectedEmployee.pagibig || 'N/A'}</span></div>
                                        <div><label>TIN</label><span>{selectedEmployee.tin_number || selectedEmployee.tin || 'N/A'}</span></div>
                                    </div>
                                </div>

                                <div className="catering-profile-section">
                                    <h3><BookOutlined /> Skills & Certifications</h3>
                                    <div className="catering-profile-skills">
                                        {Array.isArray(selectedEmployee.skills) && selectedEmployee.skills.map((skill, i) => (
                                            <span key={i} className="catering-skill-tag highlight">{skill}</span>
                                        ))}
                                    </div>
                                    <div className="catering-profile-certifications">
                                        {Array.isArray(selectedEmployee.certifications) && selectedEmployee.certifications.map((cert, i) => (
                                            <span key={i} className="catering-skill-tag"><TrophyOutlined /> {cert}</span>
                                        ))}
                                    </div>
                                </div>
                            </div>
                        </div>
                    ) : (
                        <form className="catering-form" onSubmit={(e) => e.preventDefault()}>
                            {formStep === 1 && (
                                <>
                                    <div className="catering-upload-area" onClick={() => document.getElementById('profile-photo').click()}>
                                        {imagePreview ? (
                                            <img src={imagePreview} alt="Preview" className="catering-upload-preview" />
                                        ) : (
                                            <>
                                                <UploadOutlined className="catering-upload-icon" />
                                                <p>Click to upload photo</p>
                                                <p className="catering-upload-hint">Supported: JPG, PNG. Max size: 2MB</p>
                                            </>
                                        )}
                                        <input type="file" id="profile-photo" name="profile_photo" accept="image/jpeg,image/png,image/jpg" onChange={handleInputChange} style={{ display: 'none' }} />
                                    </div>

                                    {uploadProgress > 0 && uploadProgress < 100 && (
                                        <div className="catering-upload-progress">
                                            <div className="catering-progress-bar"><div className="catering-progress-fill" style={{ width: `${uploadProgress}%` }} /></div>
                                            <span>{uploadProgress}% uploaded</span>
                                        </div>
                                    )}

                                    <div className="catering-form-section">
                                        <h3><UserOutlined /> Personal Information</h3>
                                        <div className="catering-form-row">
                                            <div className="catering-form-group">
                                                <label>First Name *</label>
                                                <input type="text" name="first_name" className={`catering-form-input ${formErrors.first_name && touchedFields.first_name ? 'error' : ''}`} value={formData.first_name} onChange={handleInputChange} onBlur={() => handleBlur('first_name')} />
                                                {formErrors.first_name && touchedFields.first_name && <span className="catering-form-error">{formErrors.first_name}</span>}
                                            </div>
                                            <div className="catering-form-group">
                                                <label>Last Name *</label>
                                                <input type="text" name="last_name" className={`catering-form-input ${formErrors.last_name && touchedFields.last_name ? 'error' : ''}`} value={formData.last_name} onChange={handleInputChange} onBlur={() => handleBlur('last_name')} />
                                                {formErrors.last_name && touchedFields.last_name && <span className="catering-form-error">{formErrors.last_name}</span>}
                                            </div>
                                        </div>

                                        <div className="catering-form-row">
                                            <div className="catering-form-group">
                                                <label>Middle Name</label>
                                                <input type="text" name="middle_name" className="catering-form-input" value={formData.middle_name} onChange={handleInputChange} />
                                            </div>
                                            <div className="catering-form-group">
                                                <label>Gender</label>
                                                <select name="gender" className="catering-form-select" value={formData.gender} onChange={handleInputChange}>
                                                    <option value="">Select gender</option>
                                                    {genderOptions.map(option => (<option key={option.value} value={option.value}>{option.label}</option>))}
                                                </select>
                                            </div>
                                        </div>

                                        <div className="catering-form-row">
                                            <div className="catering-form-group">
                                                <label>Birth Date</label>
                                                <input type="date" name="birth_date" className={`catering-form-input ${formErrors.birth_date ? 'error' : ''}`} value={formData.birth_date} onChange={handleInputChange} max={new Date().toISOString().split('T')[0]} />
                                                {formErrors.birth_date && <span className="catering-form-error">{formErrors.birth_date}</span>}
                                            </div>
                                            <div className="catering-form-group">
                                                <label>Position *</label>
                                                <select name="position_id" className={`catering-form-select ${formErrors.position_id && touchedFields.position_id ? 'error' : ''}`} value={formData.position_id} onChange={(e) => { handleInputChange(e); handlePositionChange(e.target.value); }} onBlur={() => handleBlur('position_id')}>
                                                    <option value="">Select position</option>
                                                    {(filteredPositions.length > 0 ? filteredPositions : positions).map(pos => {
                                                        let titleString = '';
                                                        if (typeof pos.title === 'string') titleString = pos.title;
                                                        else if (pos.title && typeof pos.title === 'object') titleString = pos.title.title || pos.title.name || `Position ${pos.id}`;
                                                        else if (typeof pos.name === 'string') titleString = pos.name;
                                                        else titleString = `Position ${pos.id}`;
                                                        return (<option key={pos.id} value={pos.id}>{titleString}</option>);
                                                    })}
                                                </select>
                                                {formErrors.position_id && touchedFields.position_id && <span className="catering-form-error">{formErrors.position_id}</span>}
                                            </div>
                                        </div>

                                        <div className="catering-form-row">
                                            <div className="catering-form-group">
                                                <label>Email *</label>
                                                <input type="email" name="email" className={`catering-form-input ${formErrors.email && touchedFields.email ? 'error' : ''}`} value={formData.email} onChange={handleInputChange} onBlur={() => handleBlur('email')} />
                                                {formErrors.email && touchedFields.email && <span className="catering-form-error">{formErrors.email}</span>}
                                            </div>
                                            <div className="catering-form-group">
                                                <label>Phone *</label>
                                                <input type="tel" name="phone" className={`catering-form-input ${formErrors.phone && touchedFields.phone ? 'error' : ''}`} value={formData.phone} onChange={handleInputChange} onBlur={() => handleBlur('phone')} />
                                                {formErrors.phone && touchedFields.phone && <span className="catering-form-error">{formErrors.phone}</span>}
                                            </div>
                                        </div>

                                        <div className="catering-form-group">
                                            <label>Address</label>
                                            <textarea name="address" className="catering-form-textarea" value={formData.address} onChange={handleInputChange} rows="3" />
                                        </div>

                                        <div className="catering-form-row">
                                            <div className="catering-form-group"><label>City</label><input type="text" name="city" className="catering-form-input" value={formData.city} onChange={handleInputChange} /></div>
                                            <div className="catering-form-group"><label>State/Province</label><input type="text" name="state" className="catering-form-input" value={formData.state} onChange={handleInputChange} /></div>
                                        </div>

                                        <div className="catering-form-row">
                                            <div className="catering-form-group"><label>Postal Code</label><input type="text" name="postal_code" className="catering-form-input" value={formData.postal_code} onChange={handleInputChange} /></div>
                                            <div className="catering-form-group"><label>Country</label><input type="text" name="country" className="catering-form-input" value={formData.country} onChange={handleInputChange} /></div>
                                        </div>
                                    </div>
                                </>
                            )}

                            {formStep === 2 && (
                                <div className="catering-form-section">
                                    <h3><BankOutlined /> Employment Details</h3>

                                    {selectedPositionDetails.salary_grade && (
                                        <div className="catering-auto-fill-section">
                                            <h4><DollarOutlined /> Salary Information (Auto-filled from Position)</h4>
                                            <div className="catering-form-row">
                                                <div className="catering-form-group">
                                                    <label>Salary Grade</label>
                                                    <input type="text" className="catering-form-input catering-auto-field" value={selectedPositionDetails.salary_grade} disabled readOnly />
                                                    <small className="catering-form-hint">Derived from selected position</small>
                                                </div>
                                                <div className="catering-form-group">
                                                    <label>Hourly Rate (₱)</label>
                                                    <input type="text" className="catering-form-input catering-auto-field" value={`₱${selectedPositionDetails.hourly_rate.toLocaleString()}/hour`} disabled readOnly />
                                                    <small className="catering-form-hint">Based on salary grade</small>
                                                </div>
                                            </div>
                                            <div className="catering-form-row">
                                                <div className="catering-form-group">
                                                    <label>Monthly Equivalent (₱)</label>
                                                    <input type="text" className="catering-form-input catering-auto-field" value={`₱${(selectedPositionDetails.hourly_rate * 160).toLocaleString()}/month`} disabled readOnly />
                                                    <small className="catering-form-hint">Based on 160 hours/month (for reference only)</small>
                                                </div>
                                                <div className="catering-form-group">
                                                    <label>Hourly Rate Override (Optional)</label>
                                                    <input type="number" name="hourly_rate_override" className="catering-form-input" value={formData.hourly_rate_override} onChange={handleInputChange} placeholder="Leave empty to use grade rate" min="0" step="10" />
                                                    <small className="catering-form-hint">Only use if this employee has a different rate</small>
                                                </div>
                                            </div>
                                        </div>
                                    )}

                                    <div className="catering-form-row">
                                        <div className="catering-form-group">
                                            <label>Department *</label>
                                            <select name="department_id" className={`catering-form-select ${formErrors.department_id && touchedFields.department_id ? 'error' : ''}`} value={formData.department_id} onChange={handleInputChange} onBlur={() => handleBlur('department_id')}>
                                                <option value="">Select department</option>
                                                {departments.map(dept => <option key={dept.id} value={dept.id}>{dept.name}</option>)}
                                            </select>
                                            {formErrors.department_id && touchedFields.department_id && <span className="catering-form-error">{formErrors.department_id}</span>}
                                        </div>
                                        <div className="catering-form-group">
                                            <label>Employee Type *</label>
                                            <select name="employee_type" className="catering-form-select" value={formData.employee_type} onChange={handleInputChange}>
                                                {employmentTypeOptions.map(option => (<option key={option.value} value={option.value}>{option.label}</option>))}
                                            </select>
                                        </div>
                                    </div>

                                    <div className="catering-form-row">
                                        <div className="catering-form-group">
                                            <label>Status *</label>
                                            <select name="status" className="catering-form-select" value={formData.status} onChange={handleInputChange}>
                                                {statusOptions.filter(s => s.value !== 'terminated').map(option => (<option key={option.value} value={option.value}>{option.label}</option>))}
                                            </select>
                                            <small className="catering-form-hint">Note: Terminated status is for former employees</small>
                                        </div>
                                        <div className="catering-form-group">
                                            <label>Hire Date *</label>
                                            <input type="date" name="hire_date" className={`catering-form-input ${formErrors.hire_date && touchedFields.hire_date ? 'error' : ''}`} value={formData.hire_date} onChange={handleInputChange} onBlur={() => handleBlur('hire_date')} max={new Date().toISOString().split('T')[0]} />
                                            {formErrors.hire_date && touchedFields.hire_date && <span className="catering-form-error">{formErrors.hire_date}</span>}
                                        </div>
                                    </div>

                                    <div className="catering-form-row">
                                        <div className="catering-form-group">
                                            <label>Bank Name</label>
                                            <input type="text" name="bank_name" className="catering-form-input" value={formData.bank_name} onChange={handleInputChange} />
                                        </div>
                                        <div className="catering-form-group">
                                            <label>Bank Account</label>
                                            <input type="text" name="bank_account" className="catering-form-input" value={formData.bank_account} onChange={handleInputChange} />
                                        </div>
                                    </div>
                                </div>
                            )}

                            {formStep === 3 && (
                                <div className="catering-form-section">
                                    <h3><SafetyOutlined /> Government IDs</h3>
                                    <div className="catering-form-row">
                                        <div className="catering-form-group"><label>SSS Number</label><input type="text" name="sss" className="catering-form-input" value={formData.sss} onChange={handleInputChange} placeholder="XX-XXXXXXX-X" /></div>
                                        <div className="catering-form-group"><label>PhilHealth</label><input type="text" name="philhealth" className="catering-form-input" value={formData.philhealth} onChange={handleInputChange} placeholder="XX-XXXXXXXXX-X" /></div>
                                    </div>
                                    <div className="catering-form-row">
                                        <div className="catering-form-group"><label>Pag-IBIG</label><input type="text" name="pagibig" className="catering-form-input" value={formData.pagibig} onChange={handleInputChange} placeholder="XXXX-XXXX-XXXX" /></div>
                                        <div className="catering-form-group"><label>TIN</label><input type="text" name="tin" className="catering-form-input" value={formData.tin} onChange={handleInputChange} placeholder="XXX-XXX-XXX-XXX" /></div>
                                    </div>
                                </div>
                            )}

                            {formStep === 4 && (
                                <>
                                    <div className="catering-form-section">
                                        <h3><HeartOutlined /> Emergency Contact</h3>
                                        <div className="catering-form-row">
                                            <div className="catering-form-group"><label>Contact Name</label><input type="text" name="emergency_contact" className="catering-form-input" value={formData.emergency_contact} onChange={handleInputChange} /></div>
                                            <div className="catering-form-group"><label>Relationship</label><input type="text" name="emergency_relation" className="catering-form-input" value={formData.emergency_relation} onChange={handleInputChange} /></div>
                                        </div>
                                        <div className="catering-form-group"><label>Emergency Phone</label><input type="tel" name="emergency_phone" className="catering-form-input" value={formData.emergency_phone} onChange={handleInputChange} /></div>
                                    </div>

                                    <div className="catering-form-section">
                                        <h3><BookOutlined /> Skills & Certifications</h3>
                                        <div className="catering-form-group"><label>Skills (comma separated)</label><input type="text" name="skills" className="catering-form-input" value={formData.skills} onChange={handleInputChange} placeholder="e.g., Food Safety, Menu Planning, Leadership" /></div>
                                        <div className="catering-form-group"><label>Certifications</label><input type="text" name="certifications" className="catering-form-input" value={formData.certifications} onChange={handleInputChange} placeholder="e.g., ServSafe, Culinary Arts" /></div>
                                        <div className="catering-form-group"><label>Achievements</label><input type="text" name="achievements" className="catering-form-input" value={formData.achievements} onChange={handleInputChange} placeholder="e.g., Employee of the Month" /></div>
                                    </div>

                                    <div className="catering-form-section">
                                        <h3><FileTextOutlined /> Additional Notes</h3>
                                        <div className="catering-form-group"><textarea name="notes" className="catering-form-textarea" value={formData.notes} onChange={handleInputChange} placeholder="Enter any additional notes" rows="4" /></div>
                                    </div>
                                </>
                            )}
                        </form>
                    )}
                </div>

                <div className="catering-modal-footer">
                    {modalMode !== 'view' && formStep > 1 && (<button className="catering-btn" onClick={handlePrevStep} disabled={isSubmitting}>Previous</button>)}
                    <button className="catering-btn" onClick={closeModal} disabled={isSubmitting}>Cancel</button>
                    {modalMode !== 'view' && formStep < 4 && (<button className="catering-btn catering-btn-primary" onClick={handleNextStep} disabled={isSubmitting}>Next</button>)}
                    {modalMode !== 'view' && formStep === 4 && (<button className="catering-btn catering-btn-primary" onClick={handleSaveEmployee} disabled={isSubmitting}>{isSubmitting ? <LoadingOutlined spin /> : (modalMode === 'add' ? 'Add Staff Member' : 'Save Changes')}</button>)}
                    {modalMode === 'view' && (
                        <>
                            <button className="catering-btn" onClick={() => handlePrintProfile(selectedEmployee)}><PrinterOutlined /> Print Profile</button>
                            <button className="catering-btn" onClick={() => handleBookmark(selectedEmployee.id || selectedEmployee.employee_id)}><StarOutlined /> {selectedEmployee?.is_bookmarked ? 'Remove Bookmark' : 'Add Bookmark'}</button>
                            <button className="catering-btn catering-btn-primary" onClick={() => handleEditEmployee(selectedEmployee)}><EditOutlined /> Edit Profile</button>
                        </>
                    )}
                </div>
            </div>
        </div>
    );

    // ==================== MAIN RENDER ====================
    return (
        <div className="catering-layout">
            <header className="catering-header">
                <div className="catering-logo">
                    <div className="catering-logo-icon"><PeopleIcon /></div>
                    <div className="catering-logo-text">
                        <h1>Catering Staff Management</h1>
                        <span>Enterprise System</span>
                    </div>
                </div>
                <div className="catering-header-right">
                    <div className="catering-date"><CalendarOutlined /><span>{currentDate}</span></div>
                    <div className="catering-search">
                        <SearchOutlined className="catering-search-icon" />
                        <input type="text" className="catering-search-input" placeholder="Search by name, ID, department..." value={searchQuery} onChange={(e) => setSearchQuery(e.target.value)} />
                        {searchQuery && <button className="catering-search-clear" onClick={() => setSearchQuery('')}><CloseOutlined /></button>}
                    </div>
                    <button className="catering-header-icon" onClick={toggleFullscreen} title={showFullscreen ? 'Exit Fullscreen' : 'Enter Fullscreen'}><FullscreenOutlined /></button>
                    <button className="catering-header-icon" onClick={() => { refetchEmployees(); refetchStats(); }} title="Refresh Data"><ReloadOutlined /></button>
                </div>
            </header>

            <main className="catering-main">
                {/* Stats Grid */}
                <div className="catering-stats-grid">
                    {statistics.map((stat, index) => (
                        <div key={`stat-${index}`} className="catering-stat-card">
                            <div className="catering-stat-icon">{stat.icon}</div>
                            <div className="catering-stat-content"><h3>{stat.value}</h3><span>{stat.label}</span></div>
                            <span className="catering-stat-change">{stat.change}</span>
                        </div>
                    ))}
                </div>

                {/* Quick Actions */}
                <div className="catering-quick-actions">
                    {quickActions.map((action, index) => (
                        <div key={`action-${index}`} className="catering-quick-action" onClick={action.action}>
                            <div className="catering-quick-icon">{action.icon}</div>
                            <span>{action.label}</span>
                        </div>
                    ))}
                </div>

                {/* Archive Section */}
                {showArchive && (
                    <div className="catering-archive-section">
                        <div className="catering-archive-header">
                            <h3><InboxOutlined /> Archived Staff</h3>
                            <span className="catering-archive-count">{archivedEmployees.length} archived</span>
                            <button className="catering-archive-close" onClick={() => setShowArchive(false)}><CloseOutlined /></button>
                        </div>
                        {archivedLoading ? (
                            <div className="catering-loading-spinner"><LoadingOutlined spin /> Loading archived staff...</div>
                        ) : archivedEmployees.length > 0 ? (
                            <div className="catering-archive-list">
                                {archivedEmployees.map((emp) => (
                                    <div key={emp.id || emp.employee_id} className="catering-archive-item">
                                        <div className="catering-archive-item-info">
                                            <div className="catering-archive-avatar">
                                                {emp.profile_photo_url ? (<img src={emp.profile_photo_url} alt={emp.first_name} />) : (<div className="catering-archive-avatar-placeholder">{emp.first_name?.[0]}{emp.last_name?.[0]}</div>)}
                                            </div>
                                            <div className="catering-archive-details">
                                                <h4>{emp.first_name} {emp.last_name}</h4>
                                                <p><span>{emp.position?.title || 'N/A'}</span><span>•</span><span>{emp.department?.name || 'N/A'}</span></p>
                                                <small>Archived on: {new Date(emp.deleted_at || emp.updated_at).toLocaleDateString()}</small>
                                            </div>
                                        </div>
                                        <div className="catering-archive-actions">
                                            <button className="catering-archive-restore-btn" onClick={() => restoreEmployee(emp.id || emp.employee_id)}><RestOutlined /> Restore</button>
                                            <button className="catering-archive-delete-btn" onClick={() => permanentlyDeleteEmployee(emp.id || emp.employee_id)}><DeleteOutlined /> Delete Permanently</button>
                                        </div>
                                    </div>
                                ))}
                            </div>
                        ) : (
                            <div className="catering-archive-empty"><InboxOutlined className="catering-archive-empty-icon" /><p>No archived staff members</p></div>
                        )}
                    </div>
                )}

                {/* Filters */}
                <div className="catering-filters">
                    <div className="catering-filter-tabs">
                        <button className={`catering-filter-tab ${selectedTab === 'all' ? 'active' : ''}`} onClick={() => { setSelectedTab('all'); setSelectedStatus('all'); setCurrentPage(1); }}>All Staff</button>
                        <button className={`catering-filter-tab ${selectedTab === 'active' ? 'active' : ''}`} onClick={() => { setSelectedTab('active'); setSelectedStatus('active'); setCurrentPage(1); }}>Active</button>
                        <button className={`catering-filter-tab ${selectedTab === 'onleave' ? 'active' : ''}`} onClick={() => { setSelectedTab('onleave'); setSelectedStatus('onleave'); setCurrentPage(1); }}>On Leave</button>
                        <button className={`catering-filter-tab ${selectedTab === 'inactive' ? 'active' : ''}`} onClick={() => { setSelectedTab('inactive'); setSelectedStatus('inactive'); setCurrentPage(1); }}>Inactive</button>
                        <button className={`catering-filter-tab ${selectedTab === 'terminated' ? 'active' : ''}`} onClick={() => { setSelectedTab('terminated'); setSelectedStatus('terminated'); setCurrentPage(1); }}>Terminated</button>
                        <button className={`catering-filter-tab ${selectedTab === 'bookmarked' ? 'active' : ''}`} onClick={() => { setSelectedTab('bookmarked'); setSelectedStatus('all'); setCurrentPage(1); }}><StarOutlined /> Bookmarked</button>
                    </div>

                    <select className="catering-filter-select" value={selectedDepartment} onChange={(e) => { setSelectedDepartment(e.target.value); setCurrentPage(1); }}>
                        <option value="all">All Departments</option>
                        {departments.map(dept => <option key={dept.id} value={dept.id}>{dept.name}</option>)}
                    </select>

                    <div className="catering-badge-group">
                        <span className="catering-badge"><strong>{pagination?.total || filteredEmployees.length}</strong> Total Staff</span>
                    </div>
                </div>

                {/* Bulk Actions Bar - Show only in select mode */}
                {isSelectMode && selectedEmployees.length > 0 && (
                    <div className="catering-bulk-actions-bar">
                        <span className="catering-bulk-count">{selectedEmployees.length} selected</span>
                        <button className="catering-bulk-action" onClick={() => handleBulkStatusUpdate('active')}><CheckCircleOutlined /> Set Active</button>
                        <button className="catering-bulk-action" onClick={() => handleBulkStatusUpdate('onleave')}><PauseCircleOutlined /> Set On Leave</button>
                        <button className="catering-bulk-action" onClick={() => handleBulkStatusUpdate('inactive')}><InboxOutlined /> Set Inactive</button>
                        <button className="catering-bulk-action" onClick={() => handleBulkStatusUpdate('terminated')}><WarningOutlined /> Set Terminated</button>
                        <button className="catering-bulk-action" onClick={handleBulkArchive}><InboxOutlined /> Archive Selected</button>
                        <button className="catering-bulk-close" onClick={() => { setSelectedEmployees([]); setIsSelectMode(false); }}><CloseOutlined /></button>
                    </div>
                )}

                {/* Staff Table */}
                <div className="catering-table-card">
                    <div className="catering-table-header">
                        <div className="catering-table-title">
                            <h2>Staff Directory</h2>
                            <span className="catering-table-count">Showing {paginatedEmployees.length} of {pagination?.total || filteredEmployees.length} records</span>
                        </div>
                        <div className="catering-table-actions">
                            <button className="catering-btn catering-btn-primary" onClick={handleAddEmployee}><PlusOutlined /> Add Staff</button>
                        </div>
                    </div>

                    <div className="catering-table-container">
                        {isLoading ? (
                            <div className="catering-loading-spinner"><LoadingOutlined spin /> Loading staff members...</div>
                        ) : (
                            <table className="catering-table">
                                <thead>
                                    <tr>
                                        {isSelectMode && (
                                            <th style={{ width: '40px' }}>
                                                <input
                                                    type="checkbox"
                                                    checked={selectedEmployees.length === paginatedEmployees.length && paginatedEmployees.length > 0}
                                                    onChange={handleSelectAll}
                                                />
                                            </th>
                                        )}
                                        <th style={{ width: '50px' }}>#</th>
                                        {visibleColumns.employee && <th onClick={() => handleSort('name')} style={{ cursor: 'pointer' }}>Employee {sortConfig.key === 'name' && (sortConfig.direction === 'asc' ? <SortAscendingOutlined /> : <SortDescendingOutlined />)}</th>}
                                        {visibleColumns.department && <th onClick={() => handleSort('department')} style={{ cursor: 'pointer' }}>Department {sortConfig.key === 'department' && (sortConfig.direction === 'asc' ? <SortAscendingOutlined /> : <SortDescendingOutlined />)}</th>}
                                        {visibleColumns.position && <th onClick={() => handleSort('position')} style={{ cursor: 'pointer' }}>Position {sortConfig.key === 'position' && (sortConfig.direction === 'asc' ? <SortAscendingOutlined /> : <SortDescendingOutlined />)}</th>}
                                        {visibleColumns.status && <th onClick={() => handleSort('status')} style={{ cursor: 'pointer' }}>Status {sortConfig.key === 'status' && (sortConfig.direction === 'asc' ? <SortAscendingOutlined /> : <SortDescendingOutlined />)}</th>}
                                        {visibleColumns.salary_grade && <th onClick={() => handleSort('salary_grade')} style={{ cursor: 'pointer' }}>Salary Grade {sortConfig.key === 'salary_grade' && (sortConfig.direction === 'asc' ? <SortAscendingOutlined /> : <SortDescendingOutlined />)}</th>}
                                        {visibleColumns.hourly_rate && <th onClick={() => handleSort('hourly_rate')} style={{ cursor: 'pointer' }}>Hourly Rate {sortConfig.key === 'hourly_rate' && (sortConfig.direction === 'asc' ? <SortAscendingOutlined /> : <SortDescendingOutlined />)}</th>}
                                        {visibleColumns.contact && <th>Contact</th>}
                                        {visibleColumns.actions && <th>Actions</th>}
                                    </tr>
                                </thead>
                                <tbody>
                                    {paginatedEmployees.length > 0 ? (
                                        paginatedEmployees.map((emp, idx) => (
                                            <tr key={emp.id || emp.employee_id || Math.random()} className={selectedEmployees.includes(emp.id || emp.employee_id) ? 'selected' : ''}>
                                                {isSelectMode && (
                                                    <td>
                                                        <input
                                                            type="checkbox"
                                                            checked={selectedEmployees.includes(emp.id || emp.employee_id)}
                                                            onChange={() => handleSelectEmployee(emp.id || emp.employee_id)}
                                                        />
                                                    </td>
                                                )}
                                                <td className="catering-row-number">{getRowNumber(idx)}</td>
                                                {visibleColumns.employee && (
                                                    <td>
 <div className="catering-employee-cell">
    <div className="catering-employee-avatar">
        {emp.profile_photo_url ? (
            <img
                src={emp.profile_photo_url}
                alt={`${emp.first_name || ''} ${emp.last_name || ''}`}
                style={{
                    width: '40px',
                    height: '40px',
                    borderRadius: '50%',
                    objectFit: 'cover',
                    border: '2px solid #e5e7eb'
                }}
            />
        ) : (
            <div className="catering-employee-avatar-placeholder">
                {(emp.first_name?.[0] || '').toUpperCase()}
                {(emp.last_name?.[0] || '').toUpperCase()}
            </div>
        )}
    </div>

    <div className="catering-employee-info">
        <span className="catering-employee-name">
            {`${emp.first_name || ''} ${emp.last_name || ''}`.trim() || 'Unknown Staff'}
        </span>

        <span className="catering-employee-id">
            <IdcardOutlined /> {emp.employee_code || `EMP-${emp.employee_id || emp.id}`}
        </span>
    </div>
</div>
                                                    </td>
                                                )}
                                                {visibleColumns.department && <td><span className="catering-tag">{emp.department?.name || 'N/A'}</span></td>}
                                                {visibleColumns.position && <td><span className="catering-role-badge">{emp.position?.title || 'N/A'}</span></td>}
                                                {visibleColumns.status && (
                                                    <td className="catering-status-cell">
                                                        <button
                                                            className="catering-status-badge"
                                                            style={{
                                                                backgroundColor: statusOptions.find(s => s.value === emp.status)?.bgColor || '#f3f4f6',
                                                                color: statusOptions.find(s => s.value === emp.status)?.textColor || '#374151'
                                                            }}
                                                            onClick={() => setShowStatusDropdown(showStatusDropdown === emp.id ? null : emp.id)}
                                                        >
                                                            <span className="catering-status-dot" style={{ backgroundColor: statusOptions.find(s => s.value === emp.status)?.color || '#6b7280' }}></span>
                                                            {statusOptions.find(s => s.value === emp.status)?.label || emp.status || 'Active'}
                                                            <DownOutlined style={{ fontSize: '10px', marginLeft: '6px' }} />
                                                        </button>
                                                        {showStatusDropdown === emp.id && (
                                                            <div className="catering-status-dropdown">
                                                                <div className="catering-status-dropdown-header">
                                                                    <span>Change Status</span>
                                                                    <button onClick={() => setShowStatusDropdown(null)}><CloseOutlined /></button>
                                                                </div>
                                                                <div className="catering-status-dropdown-options">
                                                                    {statusOptions.map(option => (
                                                                        <button
                                                                            key={option.value}
                                                                            className={`catering-status-option ${emp.status === option.value ? 'active' : ''}`}
                                                                            onClick={() => handleUpdateStatus(emp.id || emp.employee_id, option.value)}
                                                                        >
                                                                            <span className="catering-status-dot" style={{ backgroundColor: option.color }}></span>
                                                                            <span>{option.label}</span>
                                                                        </button>
                                                                    ))}
                                                                </div>
                                                            </div>
                                                        )}
                                                    </td>
                                                )}
                                                {visibleColumns.salary_grade && <td><span className="catering-salary-grade-text">{getEmployeeSalaryGrade(emp)}</span></td>}
                                                {visibleColumns.hourly_rate && <td>₱{(emp.hourly_rate || emp.calculated_hourly_rate || 0).toLocaleString()}/hr</td>}
                                                {visibleColumns.contact && (
                                                    <td><div className="catering-contact-info"><span><MailFilled /> {emp.email}</span><span><PhoneFilled /> {emp.phone}</span></div></td>
                                                )}
                                                {visibleColumns.actions && (
                                                    <td>
                                                        <div className="catering-actions">
                                                            <button className="catering-action-btn view" onClick={() => handleViewEmployee(emp)}><EyeOutlined /></button>
                                                            <button className="catering-action-btn edit" onClick={() => handleEditEmployee(emp)}><EditOutlined /></button>
                                                            <button className="catering-action-btn bookmark" onClick={() => handleBookmark(emp.id || emp.employee_id)}><StarOutlined style={{ color: emp.is_bookmarked ? '#fbbf24' : '#9ca3af' }} /></button>
                                                            <button className="catering-action-btn delete" onClick={() => handleArchiveClick(emp)}><InboxOutlined /></button>
                                                        </div>
                                                    </td>
                                                )}
                                            </tr>
                                        ))
                                    ) : (
                                        <tr><td colSpan="10" className="catering-table-empty"><div className="catering-empty-state"><TeamOutlined className="catering-empty-icon" /><h3>No staff members found</h3><p>Try adjusting your filters or add a new staff member</p><button className="catering-btn catering-btn-primary" onClick={handleAddEmployee}><PlusOutlined /> Add Staff Member</button></div></td></tr>
                                    )}
                                </tbody>
                            </table>
                        )}
                    </div>

                    {/* Pagination - FIXED with next/prev buttons */}
                    {totalPages > 1 && (
                        <div className="catering-pagination">
                            <span className="catering-pagination-info">
                                Showing {((pagination?.current_page || currentPage) - 1) * pageSize + 1} to {Math.min((pagination?.current_page || currentPage) * pageSize, pagination?.total || filteredEmployees.length)} of {pagination?.total || filteredEmployees.length} staff members
                            </span>
                            <div className="catering-pagination-controls">
                                <button 
                                    className="catering-pagination-btn" 
                                    onClick={() => goToPage(1)} 
                                    disabled={currentPage === 1}
                                >
                                    «
                                </button>
                                <button 
                                    className="catering-pagination-btn" 
                                    onClick={prevPage} 
                                    disabled={currentPage === 1}
                                >
                                    ‹
                                </button>
                                {Array.from({ length: Math.min(5, totalPages) }, (_, i) => {
                                    let pageNum;
                                    if (totalPages <= 5) pageNum = i + 1;
                                    else if (currentPage <= 3) pageNum = i + 1;
                                    else if (currentPage >= totalPages - 2) pageNum = totalPages - 4 + i;
                                    else pageNum = currentPage - 2 + i;
                                    if (pageNum >= 1 && pageNum <= totalPages) {
                                        return (
                                            <button 
                                                key={pageNum} 
                                                className={`catering-pagination-btn ${currentPage === pageNum ? 'active' : ''}`} 
                                                onClick={() => goToPage(pageNum)}
                                            >
                                                {pageNum}
                                            </button>
                                        );
                                    }
                                    return null;
                                })}
                                <button 
                                    className="catering-pagination-btn" 
                                    onClick={nextPage} 
                                    disabled={currentPage === totalPages}
                                >
                                    ›
                                </button>
                                <button 
                                    className="catering-pagination-btn" 
                                    onClick={() => goToPage(totalPages)} 
                                    disabled={currentPage === totalPages}
                                >
                                    »
                                </button>
                            </div>
                        </div>
                    )}
                </div>
            </main>

            {/* Render All Modals */}
            {showModal && renderEmployeeModal()}
            {showSalaryGradeModal && renderSalaryGradeModal()}
            {showPositionModal && renderPositionModal()}
            {showDepartmentModal && renderDepartmentModal()}
            {showComplianceModal && renderComplianceModal()}
            {showBenefitsModal && renderBenefitsModal()}
            {showReportsModal && renderReportsModal()}
            {showStatusFilterModal && <StatusFilterModal />}

            {/* Delete Confirmation Modal */}
            {showDeleteConfirm && (
                <div className="catering-modal-overlay" onClick={() => setShowDeleteConfirm(false)}>
                    <div className="catering-modal catering-confirm-modal" onClick={(e) => e.stopPropagation()}>
                        <div className="catering-modal-header"><h2>Confirm Archive</h2><button className="catering-modal-close" onClick={() => setShowDeleteConfirm(false)}><CloseOutlined /></button></div>
                        <div className="catering-modal-body">
                            <div className="catering-confirm-content">
                                <InboxOutlined className="catering-confirm-icon" />
                                <h3>Archive staff member?</h3>
                                <p>Are you sure you want to archive <strong>{employeeToDelete?.first_name} {employeeToDelete?.last_name}</strong>?<br />The employee will be moved to the archive and can be restored later.</p>
                            </div>
                        </div>
                        <div className="catering-modal-footer">
                            <button className="catering-btn" onClick={() => setShowDeleteConfirm(false)}>Cancel</button>
                            <button className="catering-btn catering-btn-warning" onClick={confirmArchive} disabled={bulkUpdateStatusMutation.isPending}>{bulkUpdateStatusMutation.isPending ? <LoadingOutlined spin /> : <><InboxOutlined /> Archive</>}</button>
                        </div>
                    </div>
                </div>
            )}

            {/* Print Modal */}
            {showPrintModal && (
                <div className="catering-modal-overlay" onClick={() => setShowPrintModal(false)}>
                    <div className="catering-modal catering-small-modal" onClick={(e) => e.stopPropagation()}>
                        <div className="catering-modal-header"><h2>Print Staff Directory</h2><button className="catering-modal-close" onClick={() => setShowPrintModal(false)}><CloseOutlined /></button></div>
                        <div className="catering-modal-body">
                            <p>Print all staff members with professional layout?</p>
                            <div className="catering-import-actions">
                                <button className="catering-btn" onClick={() => setShowPrintModal(false)}>Cancel</button>
                                <button className="catering-btn catering-btn-primary" onClick={handlePrintAll}><PrinterOutlined /> Print All ({filteredEmployees.length})</button>
                            </div>
                        </div>
                    </div>
                </div>
            )}

            {/* Notification */}
            {showNotification && <Notification />}

            {/* Success Animation */}
            {showSuccessAnimation && (<div className="catering-success-animation"><CheckCircleOutlined /><span>Staff Added Successfully!</span></div>)}
        </div>
    );
};

export default StaffManagement;
