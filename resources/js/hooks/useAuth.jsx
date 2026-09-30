import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { authAPI } from '../services/api';
import { useAuth } from '../contexts/AuthContext';

export const useLogin = () => {
  const { login } = useAuth();
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (credentials) => login(credentials),
    onSuccess: ({ user }) => {
      queryClient.setQueryData(['user'], user);
    },
  });
};

export const useLogout = () => {
  const { logout } = useAuth();
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: () => logout(),
    onSuccess: () => {
      queryClient.clear();
    },
  });
};

export const useUser = () => useQuery({
  queryKey: ['user'],
  queryFn: async () => {
    const response = await authAPI.getUser();
    return response?.data?.data?.user || response?.data?.user;
  },
  enabled: Boolean(localStorage.getItem('auth_token')),
  staleTime: 5 * 60 * 1000,
});

export const useUpdateProfile = () => {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (data) => authAPI.updateProfile(data),
    onSuccess: (response) => {
      const user = response?.data?.data?.user || response?.data?.user;
      queryClient.setQueryData(['user'], user);
      queryClient.invalidateQueries({ queryKey: ['user'] });
    },
  });
};

export const useChangePassword = () => useMutation({
  mutationFn: (data) => authAPI.changePassword(data),
});

export const useForgotPassword = () => useMutation({
  mutationFn: (data) => authAPI.forgotPassword(data),
});

export const useResetPassword = () => useMutation({
  mutationFn: (data) => authAPI.resetPassword(data),
});

export const useVerifyResetOtp = () => useMutation({
  mutationFn: (data) => authAPI.verifyResetOtp(data),
});

export const useResendResetOtp = () => useMutation({
  mutationFn: (data) => authAPI.resendResetOtp(data),
});
