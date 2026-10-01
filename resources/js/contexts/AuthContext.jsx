import React, { createContext, useContext, useEffect, useMemo, useState } from 'react';
import { message } from 'antd';
import { authAPI, clearAuth, handleApiError } from '../services/api';

const AuthContext = createContext(null);

const getStoredUser = () => {
  const storedUser = localStorage.getItem('user') || localStorage.getItem('userData');

  if (!storedUser) return null;

  try {
    return JSON.parse(storedUser);
  } catch (error) {
    console.error('Unable to read the stored user:', error);
    clearAuth();
    return null;
  }
};

const extractLoginData = (response) => {
  const payload = response?.data?.data || response?.data || {};

  return {
    token: payload?.token || payload?.access_token || payload?.auth_token,
    user: payload?.user || payload?.employee || payload?.admin,
  };
};

export const useAuth = () => {
  const context = useContext(AuthContext);

  if (!context) {
    throw new Error('useAuth must be used within an AuthProvider');
  }

  return context;
};

export const AuthProvider = ({ children }) => {
  const [user, setUser] = useState(null);
  const [isAuthenticated, setIsAuthenticated] = useState(false);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    const token = localStorage.getItem('auth_token') || localStorage.getItem('authToken') || localStorage.getItem('token');
    const storedUser = getStoredUser();

    if (token && storedUser) {
      setUser(storedUser);
      setIsAuthenticated(true);
    } else {
      clearAuth();
      setUser(null);
      setIsAuthenticated(false);
    }

    setLoading(false);
  }, []);

  const login = async (credentials) => {
    const userId = credentials?.userId?.trim?.() || credentials?.username?.trim?.() || credentials?.email?.trim?.() || '';
    const password = credentials?.password || '';

    if (!userId || !password) {
      throw new Error('User ID and password are required.');
    }

    setLoading(true);

    try {
      const response = await authAPI.login({
        userId,
        password,
        remember_me: credentials?.remember_me ?? credentials?.rememberMe ?? false,
      });

      const loginData = extractLoginData(response);

      if (!loginData.token || !loginData.user) {
        throw new Error('The backend login response is missing the authentication token or user data.');
      }

      localStorage.setItem('auth_token', loginData.token);
      localStorage.setItem('user', JSON.stringify(loginData.user));
      setUser(loginData.user);
      setIsAuthenticated(true);
      message.success('Login successful.');

      return loginData;
    } catch (error) {
      clearAuth();
      setUser(null);
      setIsAuthenticated(false);
      message.error(handleApiError(error, 'Login failed. Please check your credentials.'));
      throw error;
    } finally {
      setLoading(false);
    }
  };

const validateToken = async () => {
  try {
    const response = await authAPI.getUser();
    return response?.data?.data || response?.data;
  } catch (error) {
    // Only clear auth if it's a 401 and we're not on login page
    if (error.response?.status === 401) {
      // Check if we're on login page
      const isLoginPage = window.location.pathname.includes('/login');
      
      // If on login page, just return null without clearing
      if (isLoginPage) {
        return null;
      }
      
      // Otherwise, clear auth but don't redirect yet - let the component handle it
      clearAuth();
      return null;
    }
    // For other errors, re-throw
    throw error;
  }
};

useEffect(() => {
  const token = localStorage.getItem('auth_token') || localStorage.getItem('authToken') || localStorage.getItem('token');
  const storedUser = getStoredUser();
  const isLoginPage = window.location.pathname.includes('/login');

  if (token && storedUser) {
    // Validate token on app load
    validateToken()
      .then((userData) => {
        if (userData) {
          // Token is valid
          setUser(userData);
          setIsAuthenticated(true);
        } else {
          // Token is invalid
          if (!isLoginPage) {
            // Only clear if not on login page
            clearAuth();
            setUser(null);
            setIsAuthenticated(false);
          } else {
            // On login page, just clear but don't show errors
            clearAuth();
            setUser(null);
            setIsAuthenticated(false);
          }
        }
      })
      .catch((error) => {
        // Don't clear auth for network errors or non-401 errors
        if (error.response?.status === 401 && !isLoginPage) {
          clearAuth();
          setUser(null);
          setIsAuthenticated(false);
        }
      })
      .finally(() => setLoading(false));
  } else {
    // No token, clear everything
    clearAuth();
    setUser(null);
    setIsAuthenticated(false);
    setLoading(false);
  }
}, []);

  const logout = async () => {
    const token = localStorage.getItem('auth_token') || localStorage.getItem('authToken') || localStorage.getItem('token');

    try {
      if (token) await authAPI.logout();
    } catch (error) {
      console.warn('Logout API request failed:', error?.response?.data || error?.message);
    } finally {
      clearAuth();
      setUser(null);
      setIsAuthenticated(false);
    }
  };

  const value = useMemo(() => ({
    user,
    isAuthenticated,
    loading,
    login,
    logout,
  }), [user, isAuthenticated, loading]);

  return (
    <AuthContext.Provider value={value}>
      {children}
    </AuthContext.Provider>
  );
};
