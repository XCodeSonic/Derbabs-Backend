import { lazy, Suspense } from 'react';
import { BrowserRouter, Navigate, Route, Routes } from 'react-router-dom';
import { FaSpinner } from 'react-icons/fa';
import AppLayout from '../../components/layout/AppLayout';
import ProtectedRoute from '../../components/common/ProtectedRoute';
import styles from './AppRouter.module.css';

const LoginPage = lazy(() => import('../../features/auth/pages/LoginPage'));
const DashboardPage = lazy(() => import('../../features/dashboard/pages/DashboardPage'));
const ReportsAnalyticsPage = lazy(() => import('../../features/reports/pages/ReportsAnalyticsPage'));
const InventoryPage = lazy(() => import('../../features/inventory/pages/InventoryPage'));
const MenuManagementPage = lazy(() => import('../../features/menu/pages/MenuManagementPage'));
const OrdersManagementPage = lazy(() => import('../../features/orders/pages/OrdersManagementPage'));
const EventsManagementPage = lazy(() => import('../../features/events/pages/EventsManagementPage'));
const PaymentPage = lazy(() => import('../../features/billing/pages/PaymentPage'));
const BookingQuotationManagementPage = lazy(() => import('../../features/bookings/pages/BookingQuotationManagementPage'));
const CustomerManagementPage = lazy(() => import('../../features/customers/pages/CustomerManagementPage'));
const SettingsPage = lazy(() => import('../../features/settings/pages/SettingsPage'));
const NotificationsPage = lazy(() => import('../../features/notifications/pages/NotificationsPage'));
const StaffDirectoryPage = lazy(() => import('../../features/staff/pages/StaffDirectoryPage'));
const StaffSchedulingPage = lazy(() => import('../../features/staff/pages/StaffSchedulingPage'));
const StaffAttendancePage = lazy(() => import('../../features/staff/pages/StaffAttendancePage'));
const StaffPayrollPage = lazy(() => import('../../features/staff/pages/StaffPayrollPage'));

const LoadingScreen = () => (
  <div className={styles.loadingScreen}>
    <FaSpinner className="cms-spinner" />
    <span className={styles.loadingText}>Loading...</span>
  </div>
);

const ProtectedPage = ({ children }) => (
  <ProtectedRoute>
    <AppLayout>{children}</AppLayout>
  </ProtectedRoute>
);

const AppRouter = () => (
  <BrowserRouter>
    <Suspense fallback={<LoadingScreen />}>
      <Routes>
        <Route path="/login" element={<LoginPage />} />
        <Route path="/" element={<Navigate to="/dashboard" replace />} />
        <Route path="/dashboard" element={<ProtectedPage><DashboardPage /></ProtectedPage>} />
        <Route path="/reports" element={<ProtectedPage><ReportsAnalyticsPage /></ProtectedPage>} />
        <Route path="/inventory" element={<ProtectedPage><InventoryPage /></ProtectedPage>} />
        <Route path="/menu" element={<ProtectedPage><MenuManagementPage /></ProtectedPage>} />
        <Route path="/orders&events/orders" element={<ProtectedPage><OrdersManagementPage /></ProtectedPage>} />
        <Route path="/orders&events/events" element={<ProtectedPage><EventsManagementPage /></ProtectedPage>} />
        <Route path="/billing" element={<ProtectedPage><PaymentPage /></ProtectedPage>} />
        <Route path="/booking" element={<ProtectedPage><BookingQuotationManagementPage /></ProtectedPage>} />
        <Route path="/customer-feedback" element={<ProtectedPage><CustomerManagementPage /></ProtectedPage>} />
        <Route path="/staff" element={<ProtectedPage><StaffDirectoryPage /></ProtectedPage>} />
        <Route path="/staff/directory" element={<ProtectedPage><StaffDirectoryPage /></ProtectedPage>} />
        <Route path="/staff/attendance" element={<ProtectedPage><StaffAttendancePage /></ProtectedPage>} />
        <Route path="/staff/payroll" element={<ProtectedPage><StaffPayrollPage /></ProtectedPage>} />
        <Route path="/staff/schedule" element={<ProtectedPage><StaffSchedulingPage /></ProtectedPage>} />
        <Route path="/settings" element={<ProtectedPage><SettingsPage /></ProtectedPage>} />
        <Route path="/notifications" element={<ProtectedPage><NotificationsPage /></ProtectedPage>} />
        <Route path="*" element={<Navigate to="/login" replace />} />
      </Routes>
    </Suspense>
  </BrowserRouter>
);

export default AppRouter;
