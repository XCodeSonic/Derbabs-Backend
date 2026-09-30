// src/app/providers/AppProviders.jsx
import { AppDataProvider } from '../../contexts/AppDataContext';
import { AuthProvider } from '../../contexts/AuthContext';
import { NotificationProvider } from '../../contexts/NotificationContext';
import { QueryProvider } from './QueryProvider';

const AppProviders = ({ children }) => (
  <QueryProvider>
    <AuthProvider>
      <NotificationProvider>
        <AppDataProvider>{children}</AppDataProvider>
      </NotificationProvider>
    </AuthProvider>
  </QueryProvider>
);

export default AppProviders;