// src/pages/LoginPage.jsx
import React, { useState, useEffect } from 'react';
import { useNavigate } from 'react-router-dom';
import { useMutation } from '@tanstack/react-query';
import { 
  FaLock, FaUser, FaEye, FaEyeSlash, FaArrowRight, 
  FaSpinner, FaExclamationCircle, FaCheckCircle,
  FaUtensils, FaClipboardList, FaCalendarAlt, FaUsers,
  FaMoon, FaSun, FaEnvelope, FaKey, FaShieldAlt,
  FaArrowLeft, FaClock, FaRedo, FaPaperPlane, FaCheck,
  FaTimes
} from 'react-icons/fa';
import { useLogin, useForgotPassword, useResetPassword } from '../../../hooks/useAuth';
import { authAPI, clearAuth } from '../../../services/api';
import { useAuth } from '../../../contexts/AuthContext';
import logo from '../../../assets/images/logo3.png';
import '../styles/LoginPage.css';

const LoginPage = () => {
  const navigate = useNavigate();
  const { isAuthenticated: isAuth, loading: authLoading } = useAuth();
  const [darkMode, setDarkMode] = useState(false);
  const [formData, setFormData] = useState({ username: '', password: '' });
  const [rememberMe, setRememberMe] = useState(false);
  const [showPassword, setShowPassword] = useState(false);
  const [errors, setErrors] = useState({});
  const [loginError, setLoginError] = useState('');
  const [showSuccess, setShowSuccess] = useState(false);
  
  const [showForgotPassword, setShowForgotPassword] = useState(false);
  const [forgotPasswordStep, setForgotPasswordStep] = useState('request');
  const [forgotPasswordData, setForgotPasswordData] = useState({
    userId: '',
    otpCode: '',
    newPassword: '',
    confirmNewPassword: ''
  });
  const [resetToken, setResetToken] = useState('');
  const [resetOtpTimer, setResetOtpTimer] = useState(60);
  const [canResendResetOtp, setCanResendResetOtp] = useState(false);
  const [showResetPassword, setShowResetPassword] = useState(false);
  const [showConfirmResetPassword, setShowConfirmResetPassword] = useState(false);
  const [otpDigits, setOtpDigits] = useState(['', '', '', '', '', '']);

  // React Query mutations from hooks
  const loginMutation = useLogin();
  const forgotPasswordMutation = useForgotPassword();
  const resetPasswordMutation = useResetPassword();
  
  // Local mutations for OTP verification and resend
  const verifyOtpMutation = useMutation({
    mutationFn: (data) => authAPI.verifyResetOtp(data),
    onSuccess: (response) => {
      if (response.data?.data?.reset_token) {
        setResetToken(response.data.data.reset_token);
      }
      setForgotPasswordStep('reset');
      setErrors({});
    },
    onError: (error) => {
      const errorMessage = error.response?.data?.message || 'Invalid OTP. Please try again.';
      setErrors({ fp_otp: errorMessage });
      setOtpDigits(['', '', '', '', '', '']);
    }
  });

  const resendOtpMutation = useMutation({
    mutationFn: (data) => authAPI.resendResetOtp(data),
    onSuccess: (response) => {
      setResetOtpTimer(60);
      setCanResendResetOtp(false);
      setOtpDigits(['', '', '', '', '', '']);
      if (response.data?.data?.debug_otp && import.meta.env.DEV) {
        alert(`Your new reset OTP is: ${response.data.data.debug_otp}`);
      }
    },
    onError: (error) => {
      const errorMessage = error.response?.data?.message || 'Failed to resend OTP. Please try again.';
      setErrors({ fp_otp: errorMessage });
    }
  });

  const isLoading = loginMutation.isPending || forgotPasswordMutation.isPending || 
                    verifyOtpMutation.isPending || resendOtpMutation.isPending || 
                    resetPasswordMutation.isPending;

  // Load dark mode preference
  useEffect(() => {
    const savedTheme = localStorage.getItem('theme');
    if (savedTheme === 'dark') {
      setDarkMode(true);
      document.body.classList.add('dearbabs-dark-mode');
    }
  }, []);

  useEffect(() => {
    if (darkMode) {
      document.body.classList.add('dearbabs-dark-mode');
      localStorage.setItem('theme', 'dark');
    } else {
      document.body.classList.remove('dearbabs-dark-mode');
      localStorage.setItem('theme', 'light');
    }
  }, [darkMode]);

  // Check if already logged in - redirect to dashboard
  useEffect(() => {
    if (!authLoading && isAuth) {
      console.log('User already authenticated, redirecting to dashboard');
      navigate('/dashboard', { replace: true });
    }
  }, [isAuth, authLoading, navigate]);

  // Clear stale local authentication data without calling logout when no valid token exists.
  useEffect(() => {
    const token = localStorage.getItem('auth_token');
    const storedUser = localStorage.getItem('user');

    if (!authLoading && !isAuth && (token || storedUser)) {
      clearAuth();
    }
  }, [isAuth, authLoading]);

  // Timer for OTP resend
  useEffect(() => {
    let interval;
    if (forgotPasswordStep === 'verify' && resetOtpTimer > 0 && !canResendResetOtp) {
      interval = setInterval(() => {
        setResetOtpTimer((prev) => {
          if (prev <= 1) {
            setCanResendResetOtp(true);
            return 0;
          }
          return prev - 1;
        });
      }, 1000);
    }
    return () => clearInterval(interval);
  }, [forgotPasswordStep, resetOtpTimer, canResendResetOtp]);

  // Handle login success
  useEffect(() => {
    if (loginMutation.isSuccess) {
      setShowSuccess(true);
      setLoginError('');
      setErrors({});
      
      // Redirect after showing success message
      setTimeout(() => {
        navigate('/dashboard', { replace: true });
      }, 1500);
    }
  }, [loginMutation.isSuccess, navigate]);

  // Handle login error
  useEffect(() => {
    if (loginMutation.isError) {
      const error = loginMutation.error;
      const status = error.response?.status;
      const errorData = error.response?.data;
      
      console.log('Login error details:', { status, errorData });
      
      // Set specific error messages based on response
      if (status === 401) {
        setLoginError('Invalid username/email or password. Please try again.');
        setErrors({ 
          general: 'Invalid username/email or password',
          username: 'Please check your username/email',
          password: 'Please check your password'
        });
      } else if (status === 403) {
        setLoginError(errorData?.message || 'Your account is not active. Please contact administrator.');
        setErrors({ general: errorData?.message });
      } else if (status === 422) {
        setLoginError('Please fill in all required fields.');
        setErrors(errorData?.errors || {});
      } else {
        setLoginError(errorData?.message || 'Login failed. Please try again.');
        setErrors({ general: errorData?.message });
      }
      
      setShowSuccess(false);
    }
  }, [loginMutation.isError, loginMutation.error]);

  // Handle forgot password success
  useEffect(() => {
    if (forgotPasswordMutation.isSuccess && forgotPasswordMutation.data?.data?.debug_otp) {
      alert(`Your reset OTP is: ${forgotPasswordMutation.data.data.debug_otp}`);
    }
  }, [forgotPasswordMutation.isSuccess, forgotPasswordMutation.data]);

  // Handle reset password success
  useEffect(() => {
    if (resetPasswordMutation.isSuccess) {
      setTimeout(() => {
        setShowForgotPassword(false);
        setForgotPasswordStep('request');
        setForgotPasswordData({ userId: '', otpCode: '', newPassword: '', confirmNewPassword: '' });
        setOtpDigits(['', '', '', '', '', '']);
        setResetToken('');
        setErrors({});
        // Show success message to user
        alert('Password reset successfully! You can now login with your new password.');
      }, 2000);
    }
  }, [resetPasswordMutation.isSuccess]);

  const toggleTheme = () => setDarkMode(!darkMode);

  const handleInputChange = (e) => {
    const { name, value } = e.target;
    setFormData(prev => ({ ...prev, [name]: value }));
    if (errors[name]) setErrors(prev => ({ ...prev, [name]: '' }));
    if (loginError) setLoginError('');
  };

  const handleForgotPasswordChange = (e) => {
    const { name, value } = e.target;
    setForgotPasswordData(prev => ({ ...prev, [name]: value }));
    if (errors[name]) setErrors(prev => ({ ...prev, [name]: '' }));
  };

  const handleOtpChange = (index, value) => {
    if (value.length > 1) return;
    const newOtp = [...otpDigits];
    newOtp[index] = value.replace(/[^0-9]/g, '');
    setOtpDigits(newOtp);
    setForgotPasswordData(prev => ({ ...prev, otpCode: newOtp.join('') }));
    
    if (value && index < 5) {
      const nextInput = document.getElementById(`otp-input-${index + 1}`);
      if (nextInput) nextInput.focus();
    }
  };

  const handleOtpKeyDown = (index, e) => {
    if (e.key === 'Backspace' && !otpDigits[index] && index > 0) {
      const prevInput = document.getElementById(`otp-input-${index - 1}`);
      if (prevInput) prevInput.focus();
    }
  };

const handleLogin = async (e) => {
  e.preventDefault();
  setLoginError('');
  setErrors({});

  if (!formData.username.trim()) {
    setErrors({ username: 'Username or Email is required' });
    return;
  }

  if (!formData.password) {
    setErrors({ password: 'Password is required' });
    return;
  }

  // Clear any old tokens before login attempt
  clearAuth();

  loginMutation.mutate({
    userId: formData.username.trim(),
    password: formData.password,
    remember_me: rememberMe
  }, {
    onSuccess: (data) => {
      // Ensure token is stored
      const token = data?.token || data?.data?.token;
      if (token) {
        localStorage.setItem('auth_token', token);
      }
      navigate('/dashboard');
    },
    onError: (err) => {
      console.error('Login failed', err);
      // Don't show session expired message on login failure
      if (err.response?.status === 401) {
        setLoginError('Invalid username/email or password. Please try again.');
      }
    }
  });
};

  const handleRequestPasswordReset = async (e) => {
    e.preventDefault();
    if (!forgotPasswordData.userId.trim()) {
      setErrors({ fp_userId: 'Please enter your User ID or Email' });
      return;
    }
    
    forgotPasswordMutation.mutate({ user_id: forgotPasswordData.userId.trim() }, {
      onSuccess: (response) => {
        setForgotPasswordStep('verify');
        setResetOtpTimer(60);
        setCanResendResetOtp(false);
        setOtpDigits(['', '', '', '', '', '']);
        setErrors({});
      },
      onError: (error) => {
        const errorMessage = error.response?.data?.message || 'User not found. Please check your User ID or Email.';
        setErrors({ fp_userId: errorMessage });
      }
    });
  };
  
  const handleVerifyResetOtp = async () => {
    const otpValue = otpDigits.join('');
    if (otpValue.length !== 6) {
      setErrors({ fp_otp: 'Please enter a valid 6-digit OTP' });
      return;
    }
    
    verifyOtpMutation.mutate({
      user_id: forgotPasswordData.userId,
      otp_code: otpValue
    });
  };
  
  const handleResendResetOtp = async () => {
    if (!canResendResetOtp) return;
    
    resendOtpMutation.mutate({ user_id: forgotPasswordData.userId });
  };
  
  const handleResetPassword = async () => {
    if (!forgotPasswordData.newPassword) {
      setErrors({ fp_newPassword: 'Please enter a new password' });
      return;
    }
    if (forgotPasswordData.newPassword.length < 8) {
      setErrors({ fp_newPassword: 'Password must be at least 8 characters' });
      return;
    }
    if (forgotPasswordData.newPassword !== forgotPasswordData.confirmNewPassword) {
      setErrors({ fp_confirmNewPassword: 'Passwords do not match' });
      return;
    }
    
    resetPasswordMutation.mutate({
      user_id: forgotPasswordData.userId,
      new_password: forgotPasswordData.newPassword,
      password_confirmation: forgotPasswordData.confirmNewPassword,
      reset_token: resetToken
    });
  };

  const closeForgotPasswordModal = () => {
    setShowForgotPassword(false);
    setForgotPasswordStep('request');
    setForgotPasswordData({ userId: '', otpCode: '', newPassword: '', confirmNewPassword: '' });
    setOtpDigits(['', '', '', '', '', '']);
    setErrors({});
  };

  // Status Message Component
  const StatusMessage = () => {
    if (showSuccess && loginMutation.isSuccess) {
      return (
        <div className="cms-login-status success">
          <FaCheckCircle className="cms-status-icon" />
          <span>Login successful! Redirecting to dashboard...</span>
        </div>
      );
    }
    if (resetPasswordMutation.isSuccess) {
      return (
        <div className="cms-login-status success">
          <FaCheckCircle className="cms-status-icon" />
          <span>Password reset successfully! You can now login.</span>
        </div>
      );
    }
    return null;
  };

  // Error Alert Component
  const ErrorAlert = ({ message, onClose }) => {
    if (!message) return null;
    
    return (
      <div className="cms-error-alert">
        <FaExclamationCircle className="cms-error-icon" />
        <span>{message}</span>
        <button onClick={onClose} className="cms-error-close">&times;</button>
      </div>
    );
  };

  const ForgotPasswordModal = () => {
    if (!showForgotPassword) return null;
    
    const isVerifying = verifyOtpMutation.isPending;
    const isResending = resendOtpMutation.isPending;
    const isResetting = resetPasswordMutation.isPending;
    
    return (
      <div className={`cms-modal-overlay ${darkMode ? 'dark' : ''}`} onClick={closeForgotPasswordModal}>
        <div className={`cms-modal-content ${darkMode ? 'dark' : ''}`} onClick={(e) => e.stopPropagation()}>
          <button className="cms-modal-close-btn" onClick={closeForgotPasswordModal}>
            <FaTimes />
          </button>
          
          {/* Step 1: Request Reset */}
          {forgotPasswordStep === 'request' && (
            <>
              <div className="cms-modal-icon-wrapper">
                <div className="cms-modal-icon-circle">
                  <FaKey />
                </div>
              </div>
              <h3 className="cms-modal-title">Reset Password</h3>
              <p className="cms-modal-description">
                Enter your User ID or Email address and we'll send you a verification code.
              </p>
              
              <form onSubmit={handleRequestPasswordReset} className="cms-modal-form">
                <div className="cms-form-group">
                  <label>User ID or Email</label>
                  <div className={`cms-input-wrapper ${errors.fp_userId ? 'error' : ''} ${darkMode ? 'dark' : ''}`}>
                    <FaEnvelope className="cms-input-icon" />
                    <input
                      type="text"
                      name="userId"
                      placeholder="Enter your User ID or Email"
                      value={forgotPasswordData.userId}
                      onChange={handleForgotPasswordChange}
                      disabled={isLoading}
                    />
                  </div>
                  {errors.fp_userId && <span className="cms-field-error">{errors.fp_userId}</span>}
                </div>
                
                <button type="submit" className="cms-submit-btn" disabled={forgotPasswordMutation.isPending}>
                  {forgotPasswordMutation.isPending ? <FaSpinner className="cms-spinner" /> : <><FaPaperPlane /> Send Verification Code</>}
                </button>
              </form>
            </>
          )}

          {/* Step 2: Verify OTP */}
          {forgotPasswordStep === 'verify' && (
            <>
              <button className="cms-modal-back-btn" onClick={() => setForgotPasswordStep('request')}>
                <FaArrowLeft /> Back
              </button>
              
              <div className="cms-modal-icon-wrapper">
                <div className="cms-modal-icon-circle verify">
                  <FaShieldAlt />
                </div>
              </div>
              <h3 className="cms-modal-title">Verify Your Identity</h3>
              <p className="cms-modal-description">
                Enter the 6-digit verification code sent to your email.
              </p>
              
              <div className="cms-otp-container">
                <div className="cms-otp-inputs">
                  {otpDigits.map((digit, index) => (
                    <input
                      key={index}
                      id={`otp-input-${index}`}
                      type="text"
                      maxLength="1"
                      className={`cms-otp-input ${errors.fp_otp ? 'error' : ''} ${darkMode ? 'dark' : ''}`}
                      value={digit}
                      onChange={(e) => handleOtpChange(index, e.target.value)}
                      onKeyDown={(e) => handleOtpKeyDown(index, e)}
                      disabled={isVerifying || isResending}
                      autoFocus={index === 0}
                    />
                  ))}
                </div>
                {errors.fp_otp && <span className="cms-field-error cms-otp-error">{errors.fp_otp}</span>}
              </div>
              
              <div className="cms-timer-section">
                {canResendResetOtp ? (
                  <button className="cms-resend-btn" onClick={handleResendResetOtp} disabled={isResending}>
                    {isResending ? <FaSpinner className="cms-spinner" /> : <><FaRedo /> Resend Code</>}
                  </button>
                ) : (
                  <div className={`cms-timer ${darkMode ? 'dark' : ''}`}>
                    <FaClock />
                    <span>Resend code in {resetOtpTimer}s</span>
                  </div>
                )}
              </div>
              
              <button
                className="cms-submit-btn"
                onClick={handleVerifyResetOtp}
                disabled={isVerifying || otpDigits.join('').length !== 6}
              >
                {isVerifying ? <FaSpinner className="cms-spinner" /> : <><FaCheck /> Verify Code</>}
              </button>
            </>
          )}

          {/* Step 3: Reset Password */}
          {forgotPasswordStep === 'reset' && (
            <>
              <button className="cms-modal-back-btn" onClick={() => setForgotPasswordStep('verify')}>
                <FaArrowLeft /> Back
              </button>
              
              <div className="cms-modal-icon-wrapper">
                <div className="cms-modal-icon-circle reset">
                  <FaLock />
                </div>
              </div>
              <h3 className="cms-modal-title">Create New Password</h3>
              <p className="cms-modal-description">
                Your new password must be different from previously used passwords.
              </p>
              
              <form onSubmit={(e) => { e.preventDefault(); handleResetPassword(); }} className="cms-modal-form">
                <div className="cms-form-group">
                  <label>New Password</label>
                  <div className={`cms-input-wrapper ${errors.fp_newPassword ? 'error' : ''} ${darkMode ? 'dark' : ''}`}>
                    <FaLock className="cms-input-icon" />
                    <input
                      type={showResetPassword ? "text" : "password"}
                      name="newPassword"
                      placeholder="Enter new password"
                      value={forgotPasswordData.newPassword}
                      onChange={handleForgotPasswordChange}
                      disabled={isResetting}
                    />
                    <button type="button" className="cms-password-toggle" onClick={() => setShowResetPassword(!showResetPassword)}>
                      {showResetPassword ? <FaEyeSlash /> : <FaEye />}
                    </button>
                  </div>
                  {errors.fp_newPassword && <span className="cms-field-error">{errors.fp_newPassword}</span>}
                  <div className="cms-password-requirements">
                    <span className={forgotPasswordData.newPassword.length >= 8 ? 'valid' : ''}>✓ At least 8 characters</span>
                    <span className={/(?=.*[a-z])/.test(forgotPasswordData.newPassword) ? 'valid' : ''}>✓ Lowercase letter</span>
                    <span className={/(?=.*[A-Z])/.test(forgotPasswordData.newPassword) ? 'valid' : ''}>✓ Uppercase letter</span>
                    <span className={/(?=.*\d)/.test(forgotPasswordData.newPassword) ? 'valid' : ''}>✓ Number</span>
                  </div>
                </div>
                
                <div className="cms-form-group">
                  <label>Confirm New Password</label>
                  <div className={`cms-input-wrapper ${errors.fp_confirmNewPassword ? 'error' : ''} ${darkMode ? 'dark' : ''}`}>
                    <FaLock className="cms-input-icon" />
                    <input
                      type={showConfirmResetPassword ? "text" : "password"}
                      name="confirmNewPassword"
                      placeholder="Confirm your new password"
                      value={forgotPasswordData.confirmNewPassword}
                      onChange={handleForgotPasswordChange}
                      disabled={isResetting}
                    />
                    <button type="button" className="cms-password-toggle" onClick={() => setShowConfirmResetPassword(!showConfirmResetPassword)}>
                      {showConfirmResetPassword ? <FaEyeSlash /> : <FaEye />}
                    </button>
                  </div>
                  {errors.fp_confirmNewPassword && <span className="cms-field-error">{errors.fp_confirmNewPassword}</span>}
                </div>
                
                <button type="submit" className="cms-submit-btn" disabled={isResetting}>
                  {isResetting ? <FaSpinner className="cms-spinner" /> : <><FaCheck /> Reset Password</>}
                </button>
              </form>
            </>
          )}
        </div>
      </div>
    );
  };

  const features = [
    { icon: <FaClipboardList />, text: 'Real-time order tracking' },
    { icon: <FaUtensils />, text: 'Menu and inventory management' },
    { icon: <FaCalendarAlt />, text: 'Event booking system' },
    { icon: <FaUsers />, text: 'Employee management' }
  ];

  // Show loading state while checking authentication
  if (authLoading) {
    return (
      <div className="cms-login-loading">
        <FaSpinner className="cms-spinner" />
        <span>Loading...</span>
      </div>
    );
  }

  // If already authenticated, don't render login form (will redirect via useEffect)
  if (isAuth) {
    return null;
  }

  return (
    <div className={`cms-login-container ${darkMode ? 'dark' : 'light'}`}>
      <button className={`cms-theme-toggle ${darkMode ? 'dark' : 'light'}`} onClick={toggleTheme}>
        {darkMode ? <FaSun /> : <FaMoon />}
      </button>

      {/* LEFT PANEL */}
      <div className="cms-brand-panel">
        <div className="cms-brand-content">
          <div className="cms-logo-area">
            <div className="cms-logo-placeholder">
              <img 
                src={logo} 
                alt="Dear Ba'bs Logo" 
                className="cms-brand-logo-img"
                onError={(e) => {
                  e.target.onerror = null;
                  e.target.style.display = 'none';
                  const parent = e.target.parentElement;
                  if (parent && !parent.querySelector('.cms-brand-logo-fallback')) {
                    const fallback = document.createElement('div');
                    fallback.className = 'cms-brand-logo-fallback';
                    fallback.innerHTML = '🍔';
                    parent.appendChild(fallback);
                  }
                }}
              />
            </div>
            <div className="cms-brand-text">
              <div className="cms-system-name">Dear Ba'bs</div>
              <div className="cms-system-subtitle">Fastfood and Catering Services</div>
            </div>
          </div>
          
          <h1 className="cms-headline">
            Streamline your <span>catering operations</span> with ease.
          </h1>
          
          <div className="cms-features">
            {features.map((feature, index) => (
              <div className="cms-feature-item" key={index}>
                <div className="cms-feature-icon">{feature.icon}</div>
                <span>{feature.text}</span>
              </div>
            ))}
          </div>
        </div>
      </div>

      {/* RIGHT PANEL - Login Form */}
      <div className="cms-login-panel">
        <div className={`cms-login-card ${darkMode ? 'dark' : 'light'}`}>
          <div className="cms-login-header">
            <h2>Welcome back</h2>
            <p>Sign in to your account to continue.</p>
          </div>

          {/* Success Message */}
          <StatusMessage />

          {/* Error Message */}
          <ErrorAlert message={loginError} onClose={() => setLoginError('')} />

          {/* Field Errors */}
          {errors.general && !loginError && (
            <div className={`cms-error-message ${darkMode ? 'dark' : ''}`}>
              <FaExclamationCircle className="cms-error-icon" />
              <span>{errors.general}</span>
            </div>
          )}

          <form onSubmit={handleLogin} className="cms-login-form">
            <div className="cms-form-group">
              <label>Username or Email</label>
              <div className={`cms-input-wrapper ${errors.username ? 'error' : ''} ${darkMode ? 'dark' : ''}`}>
                <FaUser className="cms-input-icon" />
                <input
                  type="text"
                  name="username"
                  placeholder="Enter your username or email"
                  value={formData.username}
                  onChange={handleInputChange}
                  disabled={isLoading}
                  autoComplete="username"
                />
              </div>
              {errors.username && <span className="cms-field-error">{errors.username}</span>}
            </div>

            <div className="cms-form-group">
              <label>Password</label>
              <div className={`cms-input-wrapper ${errors.password ? 'error' : ''} ${darkMode ? 'dark' : ''}`}>
                <FaLock className="cms-input-icon" />
                <input
                  type={showPassword ? "text" : "password"}
                  name="password"
                  placeholder="Enter your password"
                  value={formData.password}
                  onChange={handleInputChange}
                  disabled={isLoading}
                  autoComplete="current-password"
                />
                <button type="button" className="cms-password-toggle" onClick={() => setShowPassword(!showPassword)}>
                  {showPassword ? <FaEyeSlash /> : <FaEye />}
                </button>
              </div>
              {errors.password && <span className="cms-field-error">{errors.password}</span>}
            </div>

            <div className="cms-form-options">
              <label className={`cms-remember-me ${darkMode ? 'dark' : ''}`}>
                <input type="checkbox" checked={rememberMe} onChange={(e) => setRememberMe(e.target.checked)} />
                <span className="cms-checkmark"></span>
                <span>Remember me</span>
              </label>
              <button type="button" className={`cms-forgot-link ${darkMode ? 'dark' : ''}`} onClick={() => setShowForgotPassword(true)}>
                Forgot password?
              </button>
            </div>

            <button type="submit" className="cms-submit-btn" disabled={isLoading}>
              {loginMutation.isPending ? (
                <><FaSpinner className="cms-spinner" /> Signing In...</>
              ) : (
                <>Sign In <FaArrowRight className="cms-btn-icon" /></>
              )}
            </button>
          </form>

          <div className={`cms-footer-note ${darkMode ? 'dark' : ''}`}>
            <div className="cms-security-badges">
              <span className="cms-badge">• PROTECTED</span>
              <span className="cms-badge">• SECURED</span>
              <span className="cms-badge">• TRUSTED</span>
            </div>
            <div className="cms-copyright">Restricted to authorized personnel only.</div>
            <div className="cms-copyright-year">© 2026 Dear Ba'bs Management System</div>
          </div>
        </div>
      </div>
      
      <ForgotPasswordModal />
    </div>
  );
};

export default LoginPage;