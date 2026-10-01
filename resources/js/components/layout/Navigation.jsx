// Navigation.jsx - Complete Working Version with All Modals
import React, { useState, useEffect, useMemo, useCallback, useRef } from 'react';
import { motion, AnimatePresence } from 'framer-motion';
import './styles/Navigation.css';
import { useLocation, useNavigate } from 'react-router-dom';
import api from '../../services/api';
import { API_BASE_URL } from '../../config/env';

// Import your logo image
import companyLogo from '../../assets/images/logo.png';

// Professional icon imports
import { 
  FiMail, FiHome, FiPieChart, FiTrendingUp, FiCalendar, FiClock,
  FiStar, FiShoppingBag, FiTruck, FiPackage, FiClipboard, FiUsers,
  FiDollarSign, FiUserCheck, FiUserPlus, FiSettings, FiBell, FiUser,
  FiLock, FiHelpCircle, FiLogOut, FiChevronLeft, FiChevronRight,
  FiChevronDown, FiSun, FiMoon, FiBarChart2, FiActivity, FiGrid,
  FiFileText, FiDownload, FiPrinter, FiCalendar as FiCalendarIcon,
  FiCopy, FiCreditCard, FiBox, FiAlertCircle, FiCamera, FiSave,
  FiX, FiEdit2, FiUpload, FiTrash2, FiCheck, FiShield, FiKey,
  FiEye, FiEyeOff, FiMessageSquare, FiInfo
} from 'react-icons/fi';

import { 
  BsCalendarCheck, BsCalendarEvent, BsCalendarWeek, BsCalendarDate,
  BsCalendarMonth, BsClockHistory, BsCashStack, BsReceipt, BsTruck,
  BsCart3, BsPeople, BsShieldLock, BsCheckCircle, BsExclamationTriangle
} from 'react-icons/bs';

import { 
  MdOutlineInventory, MdOutlinePayments, MdOutlineNotificationsActive,
  MdOutlineReport, MdOutlineAssessment, MdOutlineSchedule,
  MdOutlineDateRange, MdOutlineEvent, MdHistory, MdOutlineRestaurantMenu,
  MdOutlineEventNote, MdOutlinePointOfSale, MdOutlineReceipt,
  MdOutlineLocalShipping, MdOutlineGroup, MdOutlinePersonAdd,
  MdOutlineAttachMoney, MdOutlineTimer, MdOutlineCalendarToday,
  MdOutlineLogout, MdOutlineSecurity, MdOutlineVerified, MdOutlineVpnKey,
  MdWarning, MdError
} from 'react-icons/md';

import { 
  RiTeamLine, RiSettings4Line, RiDashboardLine, RiFilePaperLine,
  RiCalendarScheduleLine, RiLineChartLine, RiCalendarEventLine,
  RiHistoryLine, RiBillLine, RiWalletLine, RiShoppingCartLine,
  RiTruckLine, RiGroupLine, RiUserSettingsLine, RiUserStarLine, RiFeedbackLine
} from 'react-icons/ri';

import {
  HiOutlineDocumentReport, HiOutlineChartBar, HiOutlineCalendar,
  HiOutlineCash, HiOutlineShoppingCart, HiOutlineTruck
} from 'react-icons/hi';

import { useAuth } from '../../contexts/AuthContext';

const Navigation = () => {
  const [isCollapsed, setIsCollapsed] = useState(false);
  const [isDarkMode, setIsDarkMode] = useState(false);
  const [openSubmenu, setOpenSubmenu] = useState(null);
  const [hoveredItem, setHoveredItem] = useState(null);
  const [showTooltip, setShowTooltip] = useState(null);
  const [showLogoutModal, setShowLogoutModal] = useState(false);
  const [isLoggingOut, setIsLoggingOut] = useState(false);
  const [modalAnimation, setModalAnimation] = useState('idle');
  const [showProfileModal, setShowProfileModal] = useState(false);
  const [showEditProfileModal, setShowEditProfileModal] = useState(false);
  const [showSecurityModal, setShowSecurityModal] = useState(false);
  const [isSaving, setIsSaving] = useState(false);
  const [showSuccessMessage, setShowSuccessMessage] = useState(false);
  const [successMessage, setSuccessMessage] = useState('');
  const [showErrorMessage, setShowErrorMessage] = useState(false);
  const [errorMessage, setErrorMessage] = useState('');
  const [showWarningMessage, setShowWarningMessage] = useState(false);
  const [warningMessage, setWarningMessage] = useState('');
  
  // ==================== NOTIFICATION BADGE STATE ====================
  const [unreadCount, setUnreadCount] = useState(0);
  const notificationBadge = unreadCount > 0;
  
  const { logout: authLogout } = useAuth();
  
  // User profile data state - loaded from API
  const [userProfile, setUserProfile] = useState({
    id: '',
    user_id: '',
    full_name: '',
    email: '',
    phone_number: '',
    country_code: '+63',
    role: '',
    is_verified: false,
    profile_photo: null,
    department: 'IT Department',
    location: 'Manila, Philippines',
    joinDate: '',
    bio: ''
  });

  const [editFormData, setEditFormData] = useState({
    full_name: '',
    email: '',
    user_id: '',
    phone_number: '',
    location: '',
    bio: '',
    current_password: '',
    new_password: '',
    new_password_confirmation: ''
  });

  const [securityFormData, setSecurityFormData] = useState({
    current_password: '',
    new_password: '',
    new_password_confirmation: ''
  });

  const [formErrors, setFormErrors] = useState({});
  const [showPasswordFields, setShowPasswordFields] = useState(false);
  const [uploadProgress, setUploadProgress] = useState(0);
  const [isUploading, setIsUploading] = useState(false);
  const [showSecurityPassword, setShowSecurityPassword] = useState(false);
  const [showSecurityNewPassword, setShowSecurityNewPassword] = useState(false);
  const [showSecurityConfirmPassword, setShowSecurityConfirmPassword] = useState(false);
  const [confirmDeleteAvatar, setConfirmDeleteAvatar] = useState(false);

  const location = useLocation();
  const navigate = useNavigate();

  // ==================== FETCH UNREAD NOTIFICATIONS COUNT - OPTIMIZED ====================
  const fetchUnreadCount = useCallback(async () => {
    try {
        const token = localStorage.getItem('auth_token');
        if (!token) return;
        
        let count = 0;
        
        try {
            const response = await api.get('/notifications/unread-count');
            count = response.data?.data?.count || response.data?.count || 0;
        } catch (error) {
            // Fallback to main endpoint if dedicated endpoint fails
            if (error.response?.status === 404) {
                const fallbackResponse = await api.get('/notifications', {
                    params: { unread: true, per_page: 1 }
                });
                count = fallbackResponse.data?.unread_count || 
                       fallbackResponse.data?.data?.total || 0;
            } else {
                throw error;
            }
        }
        
        count = Math.max(0, parseInt(count) || 0);
        setUnreadCount(count);
        
    } catch (error) {
        console.error('Failed to fetch unread count:', error);
    }
  }, []);

  // ==================== SETUP NOTIFICATION UPDATES - NO POLLING, ONLY EVENTS ====================
  useEffect(() => {
    // Initial fetch only once
    fetchUnreadCount();
    
    // Event listeners only - NO interval polling
    const handleNotificationRead = () => fetchUnreadCount();
    const handleNewNotification = () => fetchUnreadCount();
    const handleBookingApproved = () => setTimeout(fetchUnreadCount, 1000);
    
    window.addEventListener('notification-read', handleNotificationRead);
    window.addEventListener('new-notification', handleNewNotification);
    window.addEventListener('booking-approved', handleBookingApproved);
    
    return () => {
      window.removeEventListener('notification-read', handleNotificationRead);
      window.removeEventListener('new-notification', handleNewNotification);
      window.removeEventListener('booking-approved', handleBookingApproved);
    };
  }, [fetchUnreadCount]);

  // Auto-hide messages
  useEffect(() => {
    if (showSuccessMessage) {
      const timer = setTimeout(() => setShowSuccessMessage(false), 3000);
      return () => clearTimeout(timer);
    }
  }, [showSuccessMessage]);

  useEffect(() => {
    if (showErrorMessage) {
      const timer = setTimeout(() => setShowErrorMessage(false), 5000);
      return () => clearTimeout(timer);
    }
  }, [showErrorMessage]);

  useEffect(() => {
    if (showWarningMessage) {
      const timer = setTimeout(() => setShowWarningMessage(false), 4000);
      return () => clearTimeout(timer);
    }
  }, [showWarningMessage]);

  // Load user data from API on mount
  useEffect(() => {
    const fetchUserData = async () => {
      try {
        const token = localStorage.getItem('auth_token');
        if (token) {
          const response = await api.get('/auth/user');
          if (response.data.success) {
            const user = response.data.data.user;
            setUserProfile(prev => ({
              ...prev,
              id: user.id,
              user_id: user.user_id,
              full_name: user.full_name,
              email: user.email,
              phone_number: user.phone_number,
              country_code: user.country_code || '+63',
              role: user.role,
              is_verified: user.is_verified,
              profile_photo: user.profile_photo || null,
              joinDate: new Date(user.created_at || Date.now()).toLocaleDateString('en-US', { month: 'long', year: 'numeric' })
            }));
          }
        }
      } catch (error) {
        console.error('Failed to fetch user data:', error);
      }
    };
    
    fetchUserData();
  }, []);

  // Check for saved theme preference
  useEffect(() => {
    const savedTheme = localStorage.getItem('theme');
    if (savedTheme === 'dark') {
      setIsDarkMode(true);
      document.body.classList.add('dark-mode');
      document.body.classList.remove('light-mode');
    } else {
      setIsDarkMode(false);
      document.body.classList.add('light-mode');
      document.body.classList.remove('dark-mode');
    }
  }, []);

  useEffect(() => {
    if (isDarkMode) {
      document.body.classList.add('dark-mode');
      document.body.classList.remove('light-mode');
      localStorage.setItem('theme', 'dark');
    } else {
      document.body.classList.add('light-mode');
      document.body.classList.remove('dark-mode');
      localStorage.setItem('theme', 'light');
    }
  }, [isDarkMode]);

  // Get current active item based on pathname
  useEffect(() => {
    const path = location.pathname;
    if (path === '/staff/directory' || path === '/staff/schedule' || path === '/staff/attendance' || path === '/staff/payroll') {
      setOpenSubmenu('staff');
    } else if (path === '/orders&events/orders' || path === '/orders&events/events') {
      setOpenSubmenu('order-events');
    }
  }, [location]);

  useEffect(() => {
    return () => {
      setShowLogoutModal(false);
      setShowProfileModal(false);
      setShowEditProfileModal(false);
      setShowSecurityModal(false);
      setIsLoggingOut(false);
      setModalAnimation('idle');
    };
  }, []);

  // Handle escape key press
  useEffect(() => {
    const handleEscKey = (e) => {
      if (e.key === 'Escape') {
        if (showLogoutModal) handleCloseModal();
        if (showProfileModal) setShowProfileModal(false);
        if (showEditProfileModal) setShowEditProfileModal(false);
        if (showSecurityModal) setShowSecurityModal(false);
        if (confirmDeleteAvatar) setConfirmDeleteAvatar(false);
      }
    };

    window.addEventListener('keydown', handleEscKey);
    return () => window.removeEventListener('keydown', handleEscKey);
  }, [showLogoutModal, showProfileModal, showEditProfileModal, showSecurityModal, confirmDeleteAvatar]);

  // Handle body scroll lock when modal is open
  useEffect(() => {
    if (showLogoutModal || showProfileModal || showEditProfileModal || showSecurityModal || confirmDeleteAvatar) {
      document.body.style.overflow = 'hidden';
    } else {
      document.body.style.overflow = 'unset';
    }

    return () => {
      document.body.style.overflow = 'unset';
    };
  }, [showLogoutModal, showProfileModal, showEditProfileModal, showSecurityModal, confirmDeleteAvatar]);

  // Initialize edit form with current user data
  const initializeEditForm = () => {
    setEditFormData({
      full_name: userProfile.full_name,
      email: userProfile.email,
      user_id: userProfile.user_id,
      phone_number: userProfile.phone_number,
      location: userProfile.location,
      bio: userProfile.bio,
      current_password: '',
      new_password: '',
      new_password_confirmation: ''
    });
    setFormErrors({});
    setShowPasswordFields(false);
  };

  // Initialize security form
  const initializeSecurityForm = () => {
    setSecurityFormData({
      current_password: '',
      new_password: '',
      new_password_confirmation: ''
    });
    setFormErrors({});
    setShowSecurityPassword(false);
    setShowSecurityNewPassword(false);
    setShowSecurityConfirmPassword(false);
  };

  // Show message helpers
  const showSuccess = (message) => {
    setSuccessMessage(message);
    setShowSuccessMessage(true);
  };

  const showError = (message) => {
    setErrorMessage(message);
    setShowErrorMessage(true);
  };

  const showWarning = (message) => {
    setWarningMessage(message);
    setShowWarningMessage(true);
  };

  // Handle logout
  const handleLogout = async () => {
    setIsLoggingOut(true);
    setModalAnimation('loggingOut');
    
    try {
      const token = localStorage.getItem('auth_token');
      
      localStorage.removeItem('auth_token');
      localStorage.removeItem('user');
      localStorage.removeItem('userToken');
      localStorage.removeItem('userData');
      localStorage.removeItem('rememberMe');
      sessionStorage.clear();
      
      document.cookie.split(";").forEach((c) => {
        document.cookie = c
          .replace(/^ +/, "")
          .replace(/=.*/, "=;expires=" + new Date().toUTCString() + ";path=/");
      });
      
      authLogout();
      
      if (token) {
        fetch(`${API_BASE_URL}/auth/logout`, {
          method: 'POST',
          headers: {
            'Authorization': `Bearer ${token}`,
            'Content-Type': 'application/json'
          }
        }).catch(e => console.log('Logout API error:', e));
      }
      
      setShowLogoutModal(false);
      setModalAnimation('idle');
      setIsLoggingOut(false);
      navigate('/login', { replace: true });
      
    } catch (error) {
      console.error('Logout error:', error);
      setModalAnimation('error');
      setIsLoggingOut(false);
      showError('An error occurred during logout. Please try again.');
      
      setTimeout(() => {
        setModalAnimation('idle');
        navigate('/login', { replace: true });
      }, 1500);
    }
  };

  const handleCloseModal = () => {
    if (isLoggingOut) return;
    
    setModalAnimation('closing');
    setTimeout(() => {
      setShowLogoutModal(false);
      setModalAnimation('idle');
    }, 200);
  };

  const handleProfileClick = () => {
    setShowProfileModal(true);
  };

  const handleEditProfile = () => {
    initializeEditForm();
    setShowProfileModal(false);
    setShowEditProfileModal(true);
  };

  const handleSecuritySettings = () => {
    initializeSecurityForm();
    setShowProfileModal(false);
    setShowSecurityModal(true);
  };

  // Handle avatar upload
  const handleAvatarUpload = async (e) => {
    const file = e.target.files[0];
    if (!file) return;

    const allowedTypes = ['image/jpeg', 'image/png', 'image/jpg', 'image/gif', 'image/webp'];
    if (!allowedTypes.includes(file.type)) {
      showError('Please upload a valid image file (JPEG, PNG, GIF, or WEBP)');
      return;
    }

    if (file.size > 5 * 1024 * 1024) {
      showError('Image size should be less than 5MB');
      return;
    }

    const img = new Image();
    const objectUrl = URL.createObjectURL(file);
    
    img.onload = () => {
      URL.revokeObjectURL(objectUrl);
      if (img.width > 2000 || img.height > 2000) {
        showError('Image dimensions should be less than 2000x2000 pixels');
        return;
      }
      uploadAvatar(file);
    };
    
    img.onerror = () => {
      URL.revokeObjectURL(objectUrl);
      showError('Failed to load image. Please try another file.');
    };
    
    img.src = objectUrl;
  };

  const uploadAvatar = async (file) => {
    setIsUploading(true);
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

    const formData = new FormData();
    formData.append('profile_photo', file);
    formData.append('_method', 'PUT');

    try {
      const response = await api.post('/auth/profile', formData, {
        headers: { 'Content-Type': 'multipart/form-data' }
      });
      
      if (response.data.success) {
        const profilePhotoUrl = response.data.data.user?.profile_photo || URL.createObjectURL(file);
        setUserProfile(prev => ({
          ...prev,
          profile_photo: profilePhotoUrl
        }));
        showSuccess('Profile photo updated successfully!');
      } else {
        showError(response.data.message || 'Failed to save profile photo');
      }
    } catch (error) {
      console.error('Failed to save profile photo:', error);
      showError(error.response?.data?.message || 'Failed to save profile photo. Please try again.');
    } finally {
      setIsUploading(false);
      setUploadProgress(0);
      clearInterval(interval);
    }
  };

  // Remove avatar
  const handleRemoveAvatar = () => {
    setConfirmDeleteAvatar(true);
  };

  const confirmRemoveAvatar = async () => {
    setConfirmDeleteAvatar(false);
    
    try {
      const response = await api.delete('/auth/profile-photo');
      
      if (response.data.success) {
        setUserProfile(prev => ({
          ...prev,
          profile_photo: null
        }));
        showSuccess('Profile photo removed successfully!');
      } else {
        showError(response.data.message || 'Failed to remove profile photo');
      }
    } catch (error) {
      console.error('Failed to remove profile photo:', error);
      showError('Failed to remove profile photo. Please try again.');
    }
  };

  // Validate edit form
  const validateEditForm = () => {
    const errors = {};
    
    if (!editFormData.full_name.trim()) {
      errors.full_name = 'Full name is required';
    } else if (editFormData.full_name.length < 2) {
      errors.full_name = 'Name must be at least 2 characters';
    } else if (editFormData.full_name.length > 100) {
      errors.full_name = 'Name must be less than 100 characters';
    }
    
    if (!editFormData.email.trim()) {
      errors.email = 'Email address is required';
    } else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(editFormData.email)) {
      errors.email = 'Please enter a valid email address';
    }
    
    if (!editFormData.user_id.trim()) {
      errors.user_id = 'User ID is required';
    } else if (editFormData.user_id.length < 3) {
      errors.user_id = 'User ID must be at least 3 characters';
    } else if (editFormData.user_id.length > 50) {
      errors.user_id = 'User ID must be less than 50 characters';
    } else if (!/^[a-zA-Z0-9_.-]+$/.test(editFormData.user_id)) {
      errors.user_id = 'User ID can only contain letters, numbers, underscores, dots, and hyphens';
    }
    
    if (editFormData.phone_number) {
      const cleanPhone = editFormData.phone_number.replace(/\s/g, '');
      if (!/^[0-9]{10}$/.test(cleanPhone)) {
        errors.phone_number = 'Please enter a valid 10-digit phone number';
      }
    }
    
    if (showPasswordFields) {
      if (!editFormData.current_password) {
        errors.current_password = 'Current password is required to change password';
      }
      if (editFormData.new_password) {
        if (editFormData.new_password.length < 8) {
          errors.new_password = 'Password must be at least 8 characters';
        }
        if (!/(?=.*[a-z])/.test(editFormData.new_password)) {
          errors.new_password = 'Password must contain at least one lowercase letter';
        }
        if (!/(?=.*[A-Z])/.test(editFormData.new_password)) {
          errors.new_password = 'Password must contain at least one uppercase letter';
        }
        if (!/(?=.*\d)/.test(editFormData.new_password)) {
          errors.new_password = 'Password must contain at least one number';
        }
        if (editFormData.new_password !== editFormData.new_password_confirmation) {
          errors.new_password_confirmation = 'Passwords do not match';
        }
      }
    }
    
    return errors;
  };

  // Validate security form
  const validateSecurityForm = () => {
    const errors = {};
    
    if (!securityFormData.current_password) {
      errors.current_password = 'Current password is required';
    }
    
    if (!securityFormData.new_password) {
      errors.new_password = 'New password is required';
    } else if (securityFormData.new_password.length < 8) {
      errors.new_password = 'Password must be at least 8 characters';
    }
    
    if (securityFormData.new_password) {
      if (!/(?=.*[a-z])/.test(securityFormData.new_password)) {
        errors.new_password = 'Password must contain at least one lowercase letter';
      }
      if (!/(?=.*[A-Z])/.test(securityFormData.new_password)) {
        errors.new_password = 'Password must contain at least one uppercase letter';
      }
      if (!/(?=.*\d)/.test(securityFormData.new_password)) {
        errors.new_password = 'Password must contain at least one number';
      }
    }
    
    if (securityFormData.new_password !== securityFormData.new_password_confirmation) {
      errors.new_password_confirmation = 'Passwords do not match';
    }
    
    return errors;
  };

  // Handle save profile
  const handleSaveProfile = async () => {
    const errors = validateEditForm();
    if (Object.keys(errors).length > 0) {
      setFormErrors(errors);
      showWarning('Please fix the errors before saving');
      return;
    }
    
    setIsSaving(true);
    
    try {
      const updateData = {
        full_name: editFormData.full_name,
        email: editFormData.email,
        phone_number: editFormData.phone_number,
        location: editFormData.location,
        bio: editFormData.bio
      };
      
      if (showPasswordFields && editFormData.new_password) {
        updateData.current_password = editFormData.current_password;
        updateData.password = editFormData.new_password;
        updateData.password_confirmation = editFormData.new_password_confirmation;
      }
      
      const response = await api.put('/auth/profile', updateData);
      
      if (response.data.success) {
        setUserProfile(prev => ({
          ...prev,
          full_name: editFormData.full_name,
          email: editFormData.email,
          phone_number: editFormData.phone_number,
          location: editFormData.location,
          bio: editFormData.bio
        }));
        
        showSuccess('Profile updated successfully!');
        
        setTimeout(() => {
          setShowEditProfileModal(false);
        }, 1500);
        
        setIsSaving(false);
      }
    } catch (error) {
      console.error('Profile update error:', error);
      if (error.response?.data?.errors) {
        setFormErrors(error.response.data.errors);
        showWarning('Please fix the validation errors');
      } else {
        showError(error.response?.data?.message || 'Failed to update profile');
      }
      setIsSaving(false);
    }
  };

  // Handle security save
  const handleSecuritySave = async () => {
    const errors = validateSecurityForm();
    if (Object.keys(errors).length > 0) {
      setFormErrors(errors);
      showWarning('Please fix the errors before changing password');
      return;
    }
    
    setIsSaving(true);
    
    try {
      const response = await api.put('/auth/change-password', {
        current_password: securityFormData.current_password,
        password: securityFormData.new_password,
        password_confirmation: securityFormData.new_password_confirmation
      });
      
      if (response.data.success) {
        showSuccess('Password changed successfully!');
        
        setTimeout(() => {
          setShowSecurityModal(false);
          initializeSecurityForm();
        }, 2000);
        
        setIsSaving(false);
      }
    } catch (error) {
      console.error('Password change error:', error);
      showError(error.response?.data?.message || 'Failed to change password');
      setIsSaving(false);
    }
  };

  // Handle edit form input changes
  const handleEditInputChange = (e) => {
    const { name, value } = e.target;
    setEditFormData(prev => ({ ...prev, [name]: value }));
    
    if (formErrors[name]) {
      setFormErrors(prev => ({ ...prev, [name]: '' }));
    }
  };

  // Handle security form input changes
  const handleSecurityInputChange = (e) => {
    const { name, value } = e.target;
    setSecurityFormData(prev => ({ ...prev, [name]: value }));
    
    if (formErrors[name]) {
      setFormErrors(prev => ({ ...prev, [name]: '' }));
    }
  };

  const toggleTheme = (e) => {
    e.stopPropagation();
    const newDarkMode = !isDarkMode;
    setIsDarkMode(newDarkMode);
    
    if (newDarkMode) {
      document.body.classList.add('dark-mode');
      document.body.classList.remove('light-mode');
    } else {
      document.body.classList.add('light-mode');
      document.body.classList.remove('dark-mode');
    }
    
    localStorage.setItem('theme', newDarkMode ? 'dark' : 'light');
    
    const event = new CustomEvent('themeChange', { detail: { isDark: newDarkMode } });
    window.dispatchEvent(event);
  };

  const getModalAnimationClass = () => {
    switch(modalAnimation) {
      case 'loggingOut': return 'navigation-modal-pulse';
      case 'error': return 'navigation-modal-shake';
      case 'closing': return 'navigation-modal-fade-out';
      default: return '';
    }
  };

  // Message Components
  const SuccessMessage = () => {
    if (!showSuccessMessage) return null;
    return (
      <div className={`navigation-toast-message success ${isDarkMode ? 'dark' : ''}`}>
        <BsCheckCircle className="toast-icon" />
        <span>{successMessage}</span>
        <button onClick={() => setShowSuccessMessage(false)} className="toast-close">×</button>
      </div>
    );
  };

  const ErrorMessage = () => {
    if (!showErrorMessage) return null;
    return (
      <div className={`navigation-toast-message error ${isDarkMode ? 'dark' : ''}`}>
        <MdError className="toast-icon" />
        <span>{errorMessage}</span>
        <button onClick={() => setShowErrorMessage(false)} className="toast-close">×</button>
      </div>
    );
  };

  const WarningMessage = () => {
    if (!showWarningMessage) return null;
    return (
      <div className={`navigation-toast-message warning ${isDarkMode ? 'dark' : ''}`}>
        <MdWarning className="toast-icon" />
        <span>{warningMessage}</span>
        <button onClick={() => setShowWarningMessage(false)} className="toast-close">×</button>
      </div>
    );
  };

  // ==================== MENU SECTIONS WITH NOTIFICATION BADGE ====================
  const menuSections = useMemo(() => [
    {
      title: 'MAIN',
      items: [
        {
          id: 'dashboard',
          title: 'Dashboard',
          icon: RiDashboardLine,
          secondaryIcon: FiPieChart,
          path: '/dashboard',
          description: 'Dashboard overview and insights',
        },
        {
          id: 'reports',
          title: 'Reports & Analytics',
          icon: HiOutlineDocumentReport,
          secondaryIcon: MdOutlineReport,
          path: '/reports',
          description: 'Manage reports and analytics',
        },
      ]
    },
    {
      title: 'SALES MANAGEMENT',
      items: [
        {
          id: 'booking',
          title: 'Sales & Bookings',
          icon: BsCalendarCheck,
          expandedIcon: BsCalendarEvent,
          path: '/booking',
          description: 'Analytics overview',
        }
      ]
    },
    {
      title: 'OPERATIONS',
      items: [
        {
          id: 'inventory',
          title: 'Inventory',
          icon: MdOutlineInventory,
          secondaryIcon: FiPackage,
          path: '/inventory',
          description: 'Stock management'
        },
        {
          id: 'order-events',
          title: 'Order & Events',
          icon: BsCart3,
          secondaryIcon: FiShoppingBag,
          path: '/orders-events',
          description: 'Manage orders and events',
          submenu: [
            {
              id: 'orders',
              title: 'Orders',
              icon: FiClock,
              path: '/orders&events/orders',
              description: 'Manage customer orders'
            },
            {
              id: 'events',
              title: 'Events',
              icon: MdOutlineEvent,
              path: '/orders&events/events',
              description: 'Manage catering events'
            }
          ]
        },
        {
          id: 'Menu-management',
          title: 'Menu Management',
          icon: MdOutlineEventNote,
          secondaryIcon: BsCalendarEvent,
          path: '/menu',
          description: 'Manage menu',
        },
        {
          id: 'billing-invoicing',
          title: 'Billing & Invoicing',
          icon: RiBillLine,
          secondaryIcon: FiCreditCard,
          path: '/billing',
          description: 'Financial transactions',
        }
      ]
    },
    {
      title: 'TEAM',
      items: [
        {
          id: 'staff',
          title: 'Staff Management',
          icon: RiTeamLine,
          secondaryIcon: FiUserPlus,
          path: '/staff',
          description: 'Manage team',
          submenu: [
            {
              id: 'staff-directory',
              title: 'Staff Directory',
              icon: BsPeople,
              path: '/staff/directory',
              description: 'View all staff members'
            },
            {
              id: 'schedule',
              title: 'Schedule',
              icon: MdOutlineSchedule,
              path: '/staff/schedule',
              description: 'Staff scheduling'
            },
            {
              id: 'attendance',
              title: 'Attendance',
              icon: FiUserCheck,
              path: '/staff/attendance',
              description: 'Staff attendance tracking'
            },
            {
              id: 'payroll',
              title: 'Payroll',
              icon: MdOutlineAttachMoney,
              path: '/staff/payroll',
              description: 'Salary management'
            },
          ]
        }
      ]
    },
    {
      title: 'SYSTEM',
      items: [
        {
          id: 'notifications',
          title: 'Notifications',
          icon: MdOutlineNotificationsActive,
          secondaryIcon: FiBell,
          path: '/notifications',
          description: 'Alerts & updates',
          badge: unreadCount,
          showBadge: notificationBadge,
          badgeColor: '#ff4d4f'
        },
        {
          id: 'customer-feedback',
          title: 'Customer Feedback',
          icon: RiFeedbackLine,
          secondaryIcon: FiMessageSquare,
          path: '/customer-feedback',
          description: 'Manage customer feedback'
        },
        {
          id: 'settings',
          title: 'Settings',
          icon: RiSettings4Line,
          secondaryIcon: FiLock,
          path: '/settings',
          description: 'System configuration'
        }
      ]
    }
  ], [unreadCount, notificationBadge]);

  const handleItemClick = (item) => {
    if (item.submenu) {
      setOpenSubmenu(openSubmenu === item.id ? null : item.id);
    } else {
      navigate(item.path);
    }
  };

  const handleSubmenuClick = (subItem, parentId) => {
    navigate(subItem.path);
    setOpenSubmenu(parentId);
  };

  const isItemActive = (item) => {
    if (item.submenu) {
      return item.submenu.some(sub => sub.path === location.pathname);
    }
    return item.path === location.pathname;
  };

  const isSubItemActive = (path) => {
    return location.pathname === path;
  };

  // Helper function to render item with red notification badge
  const renderNavItem = (item) => {
    const hasBadge = item.showBadge && item.badge > 0;
    
    return (
      <div key={item.id} className="nav-item-wrapper">
        <div
          className={`nav-item ${isItemActive(item) || (item.submenu && openSubmenu === item.id) ? 'active' : ''}`}
          onClick={() => handleItemClick(item)}
          onMouseEnter={() => {
            setHoveredItem(item.id);
            if (isCollapsed) setShowTooltip(item.id);
          }}
          onMouseLeave={() => {
            setHoveredItem(null);
            setShowTooltip(null);
          }}
        >
          <div className="nav-item-content">
            <div className="icon-container" style={{ position: 'relative' }}>
              <div className="icon-primary notification-bell-icon">
                <item.icon />
              </div>
              
              {/* RED NOTIFICATION BADGE */}
              {hasBadge && (
                <motion.div 
                  className="nav-notification-badge"
                  initial={{ scale: 0 }}
                  animate={{ scale: 1 }}
                  exit={{ scale: 0 }}
                  style={{
                    position: 'absolute',
                    top: '-8px',
                    right: '-12px',
                    minWidth: '20px',
                    height: '20px',
                    padding: '0 5px',
                    background: item.badgeColor || '#ff4d4f',
                    borderRadius: '20px',
                    fontSize: '11px',
                    fontWeight: 'bold',
                    color: 'white',
                    display: 'flex',
                    alignItems: 'center',
                    justifyContent: 'center',
                    boxShadow: '0 0 0 2px var(--nav-bg, #ffffff)',
                    zIndex: 10,
                    fontFamily: 'monospace',
                    letterSpacing: '0.5px'
                  }}
                >
                  {item.badge > 99 ? '99+' : item.badge}
                </motion.div>
              )}
              
              {item.secondaryIcon && (
                <div 
                  className="icon-secondary"
                  style={{ 
                    opacity: hoveredItem === item.id ? 1 : 0,
                    transform: hoveredItem === item.id ? 'scale(1)' : 'scale(0)'
                  }}
                >
                  <item.secondaryIcon />
                </div>
              )}
            </div>
            
            {!isCollapsed && (
              <div className="item-text">
                <span className="item-title">{item.title}</span>
                {item.description && (
                  <span className="item-description">{item.description}</span>
                )}
              </div>
            )}
          </div>

          {item.submenu && !isCollapsed && (
            <div
              className="submenu-indicator"
              style={{ transform: openSubmenu === item.id ? 'rotate(180deg)' : 'rotate(0deg)' }}
            >
              <FiChevronDown />
            </div>
          )}
        </div>

        <AnimatePresence>
          {item.submenu && openSubmenu === item.id && !isCollapsed && (
            <motion.div 
              className="submenu"
              initial={{ opacity: 0, height: 0 }}
              animate={{ opacity: 1, height: 'auto' }}
              exit={{ opacity: 0, height: 0 }}
            >
              {item.submenu.map((subItem) => (
                <div
                  key={subItem.id}
                  className={`submenu-item ${isSubItemActive(subItem.path) ? 'active' : ''}`}
                  onClick={() => handleSubmenuClick(subItem, item.id)}
                >
                  <div className="submenu-icon-wrapper">
                    <subItem.icon />
                  </div>
                  <div className="submenu-text">
                    <span className="submenu-title">{subItem.title}</span>
                    {subItem.description && (
                      <span className="submenu-description">{subItem.description}</span>
                    )}
                  </div>
                </div>
              ))}
            </motion.div>
          )}
        </AnimatePresence>

        {isCollapsed && showTooltip === item.id && (
          <div className="tooltip">
            <strong>{item.title}</strong>
            {item.description && <span>{item.description}</span>}
            {hasBadge && <span className="tooltip-badge">{item.badge} unread</span>}
          </div>
        )}
      </div>
    );
  };

  return (
    <>
      {/* Toast Messages */}
      <SuccessMessage />
      <ErrorMessage />
      <WarningMessage />

      <motion.div 
        className={`navigation-container ${isDarkMode ? 'dark-mode' : 'light-mode'}`}
        initial={false}
        animate={{ width: isCollapsed ? '98px' : '360px' }}
        transition={{ duration: 0.4, ease: [0.4, 0, 0.2, 1] }}
      >
        <div className="nav-backdrop" />
        
        <div className="nav-header">
          <motion.div 
            className="logo-container"
            onClick={() => setIsCollapsed(!isCollapsed)}
            whileHover={{ scale: 1.02 }}
            whileTap={{ scale: 0.98 }}
            style={{ cursor: 'pointer' }}
          >
            <div className="logo-image-wrapper">
              <img src={companyLogo} alt="Dear Bab's Catering" className="logo-image" />
            </div>
            
            <AnimatePresence>
              {!isCollapsed && (
                <motion.div 
                  className="logo-text"
                  initial={{ opacity: 0, width: 0 }}
                  animate={{ opacity: 1, width: 'auto' }}
                  exit={{ opacity: 0, width: 0 }}
                  transition={{ duration: 0.3 }}
                >
                  <h4>Dear Bab's <span>Catering</span></h4>
                  <p>Management System</p>
                </motion.div>
              )}
            </AnimatePresence>

            <motion.div 
              className="collapse-hint"
              animate={{ rotate: isCollapsed ? 180 : 0 }}
              transition={{ duration: 0.3 }}
            >
              {isCollapsed ? <FiChevronRight /> : <FiChevronLeft />}
            </motion.div>
          </motion.div>
        </div>

        <motion.div 
          className="theme-toggle-container"
          initial={{ opacity: 0 }}
          animate={{ opacity: 1 }}
          transition={{ delay: 0.2 }}
        >
          <motion.button
            className="theme-toggle"
            onClick={toggleTheme}
            whileHover={{ scale: 1.1 }}
            whileTap={{ scale: 0.9 }}
          >
            <motion.div
              animate={{ rotate: isDarkMode ? 180 : 0 }}
              transition={{ duration: 0.3 }}
            >
              {isDarkMode ? <FiSun /> : <FiMoon />}
            </motion.div>
          </motion.button>
          {!isCollapsed && <span className="theme-label">{isDarkMode ? 'Light Mode' : 'Dark Mode'}</span>}
        </motion.div>

        <nav className="nav-menu">
          {menuSections.map((section, sectionIndex) => (
            <div key={section.title} className="nav-section">
              {!isCollapsed && (
                <motion.div 
                  className="section-title"
                  initial={{ opacity: 0, x: -20 }}
                  animate={{ opacity: 1, x: 0 }}
                  transition={{ delay: sectionIndex * 0.1 }}
                >
                  {section.title}
                </motion.div>
              )}
              
              {section.items.map((item) => renderNavItem(item))}
            </div>
          ))}
        </nav>

        <div 
          className="navigation-user-profile"
          onClick={handleProfileClick}
          style={{ cursor: 'pointer' }}
        >
          <div className="navigation-profile-avatar">
            {userProfile.profile_photo ? (
              <img src={userProfile.profile_photo} alt="Profile" className="navigation-avatar-image" />
            ) : (
              <div className="navigation-avatar-content">
                <FiUser />
              </div>
            )}
            <div className="navigation-status-indicator" />
          </div>
          
          {!isCollapsed && (
            <div className="navigation-profile-info">
              <h4>{userProfile.full_name}</h4>
              <p>{userProfile.role === 'admin' ? 'Administrator' : userProfile.role === 'user' ? 'Staff Member' : userProfile.role}</p>
            </div>
          )}
          
          <button 
            className="navigation-logout-btn"
            onClick={(e) => {
              e.stopPropagation();
              setShowLogoutModal(true);
            }}
            disabled={isLoggingOut}
          >
            <FiLogOut />
            {!isCollapsed && <span>Logout</span>}
          </button>
        </div>
      </motion.div>

      {/* Logout Confirmation Modal */}
      <AnimatePresence mode="wait">
        {showLogoutModal && (
          <>
            <div className="navigation-modal-overlay" onClick={handleCloseModal} />
            <div className="navigation-modal-center-wrapper">
              <div className={`navigation-logout-modal ${getModalAnimationClass()}`}>
                <div className="navigation-modal-header">
                  <div className="navigation-modal-icon"><FiAlertCircle /></div>
                  <button className="navigation-modal-close-btn" onClick={handleCloseModal} disabled={isLoggingOut}>×</button>
                </div>
                <h3 className="navigation-modal-title">
                  {modalAnimation === 'loggingOut' ? 'Logging Out...' : modalAnimation === 'error' ? 'Logout Failed' : 'Confirm Logout'}
                </h3>
                <p className="navigation-modal-message">
                  {modalAnimation === 'loggingOut' ? 'Please wait while we securely log you out...' : 
                   modalAnimation === 'error' ? 'An error occurred during logout. Please try again.' : 
                   'Are you sure you want to logout? Any unsaved changes will be lost.'}
                </p>
                {modalAnimation !== 'loggingOut' && modalAnimation !== 'error' && (
                  <div className="navigation-session-info">
                    <BsShieldLock />
                    <div className="navigation-session-details">
                      <strong>Current Session</strong>
                      <span>Logged in as: {userProfile.email}</span>
                      <span>Session started: {new Date().toLocaleString()}</span>
                    </div>
                  </div>
                )}
                {modalAnimation === 'error' && (<p className="navigation-error-message">Please check your connection and try again</p>)}
                <div className="navigation-modal-actions">
                  <button className="navigation-cancel-btn" onClick={handleCloseModal} disabled={isLoggingOut}>
                    {modalAnimation === 'loggingOut' ? 'Please Wait...' : 'Cancel'}
                  </button>
                  <button className={`navigation-confirm-btn ${modalAnimation === 'loggingOut' ? 'logging-out' : ''}`} onClick={handleLogout} disabled={isLoggingOut || modalAnimation === 'error'}>
                    {modalAnimation === 'loggingOut' ? (<><span className="navigation-spinner" />Logging out...</>) : modalAnimation === 'error' ? 'Try Again' : 'Yes, Logout'}
                  </button>
                </div>
                {modalAnimation === 'loggingOut' && (<div className="navigation-logout-progress" />)}
              </div>
            </div>
          </>
        )}
      </AnimatePresence>

      {/* Profile Modal */}
      <AnimatePresence mode="wait">
        {showProfileModal && (
          <>
            <div className="navigation-modal-overlay" onClick={() => setShowProfileModal(false)} />
            <div className="navigation-modal-center-wrapper">
              <div className="navigation-profile-modal">
                <div className="navigation-profile-modal-header">
                  <div className="navigation-profile-modal-avatar">
                    {userProfile.profile_photo ? <img src={userProfile.profile_photo} alt="Profile" /> : <FiUser />}
                    {userProfile.is_verified && (<div className="navigation-verified-badge"><MdOutlineVerified /></div>)}
                  </div>
                  <button className="navigation-profile-modal-close" onClick={() => setShowProfileModal(false)}>×</button>
                </div>
                <h3 className="navigation-profile-modal-name">{userProfile.full_name}</h3>
                <p className="navigation-profile-modal-role">{userProfile.role === 'admin' ? 'Administrator' : userProfile.role === 'user' ? 'Staff Member' : userProfile.role}</p>
                <div className="navigation-profile-modal-info">
                  <div className="navigation-profile-info-item"><FiMail /><span>{userProfile.email}</span></div>
                  <div className="navigation-profile-info-item"><FiUser /><span>User ID: {userProfile.user_id}</span></div>
                  {userProfile.phone_number && (<div className="navigation-profile-info-item"><FiTruck /><span>{userProfile.country_code} {userProfile.phone_number}</span></div>)}
                  <div className="navigation-profile-info-item"><FiCalendar /><span>Member since: {userProfile.joinDate}</span></div>
                </div>
                <div className="navigation-profile-modal-actions">
                  <button className="navigation-profile-edit-btn" onClick={handleEditProfile}><FiEdit2 /> Edit Profile</button>
                  <button className="navigation-profile-security-btn" onClick={handleSecuritySettings}><MdOutlineSecurity /> Security</button>
                </div>
              </div>
            </div>
          </>
        )}
      </AnimatePresence>

      {/* Edit Profile Modal */}
      <AnimatePresence mode="wait">
        {showEditProfileModal && (
          <>
            <div className="navigation-modal-overlay" onClick={() => setShowEditProfileModal(false)} />
            <div className="navigation-modal-center-wrapper">
              <div className="navigation-edit-profile-modal">
                <div className="navigation-edit-profile-header">
                  <h3>Edit Profile</h3>
                  <button className="navigation-edit-profile-close" onClick={() => setShowEditProfileModal(false)}><FiX /></button>
                </div>
                {formErrors.general && (<div className="navigation-error-message-modal"><FiAlertCircle /><span>{formErrors.general}</span></div>)}
                <div className="navigation-edit-profile-body">
                  <div className="navigation-avatar-upload-section">
                    <div className="navigation-avatar-preview">
                      {userProfile.profile_photo ? <img src={userProfile.profile_photo} alt="Profile" className="navigation-avatar-img" /> : <div className="navigation-avatar-placeholder"><FiUser /></div>}
                      {isUploading && (<div className="navigation-upload-overlay"><div className="navigation-upload-progress"><div className="navigation-upload-progress-bar" style={{ width: `${uploadProgress}%` }} /></div></div>)}
                    </div>
                    <div className="navigation-avatar-actions">
                      <label className="navigation-upload-btn"><FiCamera /><input type="file" accept="image/jpeg,image/png,image/jpg,image/gif,image/webp" onChange={handleAvatarUpload} style={{ display: 'none' }} />Upload Photo</label>
                      {userProfile.profile_photo && (<button className="navigation-remove-avatar-btn" onClick={handleRemoveAvatar}><FiTrash2 /> Remove</button>)}
                    </div>
                    {formErrors.avatar && <span className="navigation-error-text">{formErrors.avatar}</span>}
                    <p className="navigation-avatar-hint">Recommended: Square image, max 5MB (JPEG, PNG, GIF, WEBP)</p>
                  </div>
                  <div className="navigation-edit-form">
                    <div className="navigation-form-row">
                      <div className="navigation-form-group"><label>Full Name <span className="required">*</span></label><input type="text" name="full_name" value={editFormData.full_name} onChange={handleEditInputChange} className={formErrors.full_name ? 'error' : ''} />{formErrors.full_name && <span className="navigation-error-text">{formErrors.full_name}</span>}</div>
                      <div className="navigation-form-group"><label>Email Address <span className="required">*</span></label><input type="email" name="email" value={editFormData.email} onChange={handleEditInputChange} className={formErrors.email ? 'error' : ''} />{formErrors.email && <span className="navigation-error-text">{formErrors.email}</span>}</div>
                    </div>
                    <div className="navigation-form-row">
                      <div className="navigation-form-group"><label>User ID <span className="required">*</span></label><input type="text" name="user_id" value={editFormData.user_id} onChange={handleEditInputChange} className={formErrors.user_id ? 'error' : ''} disabled /><small className="navigation-field-hint">User ID cannot be changed</small></div>
                      <div className="navigation-form-group"><label>Phone Number</label><div className="navigation-phone-input"><span className="navigation-country-code">+63</span><input type="tel" name="phone_number" value={editFormData.phone_number} onChange={handleEditInputChange} className={formErrors.phone_number ? 'error' : ''} /></div>{formErrors.phone_number && <span className="navigation-error-text">{formErrors.phone_number}</span>}<small className="navigation-field-hint">10-digit mobile number</small></div>
                    </div>
                    <div className="navigation-form-group"><label>Bio</label><textarea name="bio" value={editFormData.bio} onChange={handleEditInputChange} rows="3" /><small className="navigation-field-hint">Maximum 500 characters</small></div>
                    <div className="navigation-password-section">
                      <button type="button" className="navigation-toggle-password-btn" onClick={() => setShowPasswordFields(!showPasswordFields)}><FiLock />{showPasswordFields ? 'Cancel Password Change' : 'Change Password'}</button>
                      {showPasswordFields && (<div className="navigation-password-fields">
                        <div className="navigation-form-group"><label>Current Password <span className="required">*</span></label><input type="password" name="current_password" value={editFormData.current_password} onChange={handleEditInputChange} className={formErrors.current_password ? 'error' : ''} />{formErrors.current_password && <span className="navigation-error-text">{formErrors.current_password}</span>}</div>
                        <div className="navigation-form-row">
                          <div className="navigation-form-group"><label>New Password</label><input type="password" name="new_password" value={editFormData.new_password} onChange={handleEditInputChange} className={formErrors.new_password ? 'error' : ''} />{formErrors.new_password && <span className="navigation-error-text">{formErrors.new_password}</span>}</div>
                          <div className="navigation-form-group"><label>Confirm New Password</label><input type="password" name="new_password_confirmation" value={editFormData.new_password_confirmation} onChange={handleEditInputChange} className={formErrors.new_password_confirmation ? 'error' : ''} />{formErrors.new_password_confirmation && <span className="navigation-error-text">{formErrors.new_password_confirmation}</span>}</div>
                        </div>
                        <div className="navigation-password-requirements"><p>Password requirements:</p><ul><li className={editFormData.new_password.length >= 8 ? 'valid' : ''}>✓ At least 8 characters</li><li className={/(?=.*[a-z])/.test(editFormData.new_password) ? 'valid' : ''}>✓ Contains lowercase letter</li><li className={/(?=.*[A-Z])/.test(editFormData.new_password) ? 'valid' : ''}>✓ Contains uppercase letter</li><li className={/(?=.*\d)/.test(editFormData.new_password) ? 'valid' : ''}>✓ Contains a number</li></ul></div>
                      </div>)}
                    </div>
                  </div>
                </div>
                <div className="navigation-edit-profile-footer">
                  <button className="navigation-cancel-edit-btn" onClick={() => setShowEditProfileModal(false)} disabled={isSaving}>Cancel</button>
                  <button className="navigation-save-edit-btn" onClick={handleSaveProfile} disabled={isSaving}>{isSaving ? (<><span className="navigation-spinner-small" />Saving...</>) : (<><FiSave /> Save Changes</>)}</button>
                </div>
              </div>
            </div>
          </>
        )}
      </AnimatePresence>

      {/* Security Modal */}
      <AnimatePresence mode="wait">
        {showSecurityModal && (
          <>
            <div className="navigation-modal-overlay" onClick={() => setShowSecurityModal(false)} />
            <div className="navigation-modal-center-wrapper">
              <div className="navigation-security-modal">
                <div className="navigation-edit-profile-header">
                  <h3>Security Settings</h3>
                  <button className="navigation-edit-profile-close" onClick={() => setShowSecurityModal(false)}><FiX /></button>
                </div>
                <div className="navigation-security-body">
                  <div className="navigation-security-icon"><MdOutlineVpnKey /></div>
                  <h4>Change Password</h4>
                  <p>Update your password to keep your account secure</p>
                  <div className="navigation-security-form">
                    <div className="navigation-form-group">
                      <label>Current Password <span className="required">*</span></label>
                      <div className="navigation-password-input-wrapper">
                        <input type={showSecurityPassword ? "text" : "password"} name="current_password" value={securityFormData.current_password} onChange={handleSecurityInputChange} className={formErrors.current_password ? 'error' : ''} />
                        <button type="button" className="navigation-password-eye" onClick={() => setShowSecurityPassword(!showSecurityPassword)}>{showSecurityPassword ? <FiEyeOff /> : <FiEye />}</button>
                      </div>
                      {formErrors.current_password && <span className="navigation-error-text">{formErrors.current_password}</span>}
                    </div>
                    <div className="navigation-form-group">
                      <label>New Password <span className="required">*</span></label>
                      <div className="navigation-password-input-wrapper">
                        <input type={showSecurityNewPassword ? "text" : "password"} name="new_password" value={securityFormData.new_password} onChange={handleSecurityInputChange} className={formErrors.new_password ? 'error' : ''} />
                        <button type="button" className="navigation-password-eye" onClick={() => setShowSecurityNewPassword(!showSecurityNewPassword)}>{showSecurityNewPassword ? <FiEyeOff /> : <FiEye />}</button>
                      </div>
                      {formErrors.new_password && <span className="navigation-error-text">{formErrors.new_password}</span>}
                    </div>
                    <div className="navigation-form-group">
                      <label>Confirm New Password <span className="required">*</span></label>
                      <div className="navigation-password-input-wrapper">
                        <input type={showSecurityConfirmPassword ? "text" : "password"} name="new_password_confirmation" value={securityFormData.new_password_confirmation} onChange={handleSecurityInputChange} className={formErrors.new_password_confirmation ? 'error' : ''} />
                        <button type="button" className="navigation-password-eye" onClick={() => setShowSecurityConfirmPassword(!showSecurityConfirmPassword)}>{showSecurityConfirmPassword ? <FiEyeOff /> : <FiEye />}</button>
                      </div>
                      {formErrors.new_password_confirmation && <span className="navigation-error-text">{formErrors.new_password_confirmation}</span>}
                    </div>
                    <div className="navigation-password-requirements"><p>Password requirements:</p><ul><li className={securityFormData.new_password.length >= 8 ? 'valid' : ''}>✓ At least 8 characters</li><li className={/(?=.*[a-z])/.test(securityFormData.new_password) ? 'valid' : ''}>✓ Contains lowercase letter</li><li className={/(?=.*[A-Z])/.test(securityFormData.new_password) ? 'valid' : ''}>✓ Contains uppercase letter</li><li className={/(?=.*\d)/.test(securityFormData.new_password) ? 'valid' : ''}>✓ Contains a number</li></ul></div>
                  </div>
                </div>
                <div className="navigation-edit-profile-footer">
                  <button className="navigation-cancel-edit-btn" onClick={() => setShowSecurityModal(false)} disabled={isSaving}>Cancel</button>
                  <button className="navigation-save-edit-btn" onClick={handleSecuritySave} disabled={isSaving}>{isSaving ? (<><span className="navigation-spinner-small" />Updating...</>) : (<><FiSave /> Update Password</>)}</button>
                </div>
              </div>
            </div>
          </>
        )}
      </AnimatePresence>

      {/* Confirm Delete Avatar Modal */}
      <AnimatePresence mode="wait">
        {confirmDeleteAvatar && (
          <>
            <div className="navigation-modal-overlay" onClick={() => setConfirmDeleteAvatar(false)} />
            <div className="navigation-modal-center-wrapper">
              <div className="navigation-confirm-modal">
                <div className="navigation-confirm-header">
                  <div className="navigation-confirm-icon warning"><BsExclamationTriangle /></div>
                  <h3>Confirm Remove Photo</h3>
                </div>
                <p className="navigation-confirm-message">Are you sure you want to remove your profile photo?</p>
                <div className="navigation-confirm-actions">
                  <button className="navigation-confirm-cancel" onClick={() => setConfirmDeleteAvatar(false)}>Cancel</button>
                  <button className="navigation-confirm-danger" onClick={confirmRemoveAvatar}>Yes, Remove</button>
                </div>
              </div>
            </div>
          </>
        )}
      </AnimatePresence>
    </>
  );
};

export default Navigation;