// CateringDashboard.jsx - Fixed Dark/Light Mode with No Delay
import React, { useState, useEffect, useRef } from 'react';
import { 
  Row, 
  Col, 
  Card, 
  Table, 
  Progress, 
  Avatar, 
  Button, 
  Tag, 
  List, 
  Typography, 
  Space, 
  Select,
  Divider,
  ConfigProvider,
  theme as antdTheme
} from 'antd';
import { 
  CalendarOutlined, 
  ShoppingOutlined, 
  DollarOutlined, 
  CheckCircleOutlined,
  ClockCircleOutlined,
  RiseOutlined,
  FallOutlined,
  PlusOutlined,
  FileTextOutlined,
  UserOutlined,
  BarChartOutlined,
  ExportOutlined,
  EyeOutlined,
  TeamOutlined,
  GiftOutlined,
  DashboardOutlined,
  InboxOutlined,
  CreditCardOutlined,
  TruckOutlined,
  ScheduleOutlined
} from '@ant-design/icons';
import { 
  LineChart, 
  Line, 
  AreaChart, 
  Area, 
  BarChart, 
  Bar, 
  PieChart, 
  Pie, 
  Cell, 
  XAxis, 
  YAxis, 
  CartesianGrid, 
  Tooltip as RechartsTooltip, 
  Legend, 
  ResponsiveContainer 
} from 'recharts';
import { motion } from 'framer-motion';
import { format } from 'date-fns';
import '../styles/Dashboard.css';

const { Title, Text } = Typography;
const { Option } = Select;

// -------------------- Mock Data --------------------
const recentBookings = [
  { id: 'BK001', customer: 'Emma Watson', eventType: 'Wedding', eventDate: new Date(2024, 5, 15), status: 'approved', amount: 12500, paid: 10000 },
  { id: 'BK002', customer: 'James Wilson', eventType: 'Corporate Gala', eventDate: new Date(2024, 5, 18), status: 'pending', amount: 8500, paid: 0 },
  { id: 'BK003', customer: 'Sophia Chen', eventType: 'Birthday Party', eventDate: new Date(2024, 5, 20), status: 'approved', amount: 3200, paid: 3200 },
  { id: 'BK004', customer: 'Michael Brown', eventType: 'Anniversary', eventDate: new Date(2024, 5, 22), status: 'completed', amount: 5600, paid: 5600 },
  { id: 'BK005', customer: 'Olivia Martinez', eventType: 'Conference', eventDate: new Date(2024, 5, 25), status: 'approved', amount: 9800, paid: 5000 },
];

const upcomingEvents = [
  { id: 'EV001', name: 'Smith Wedding Reception', location: 'Grand Ballroom', date: new Date(2024, 5, 15, 18, 0), assignedStaff: ['John Chef', 'Sarah Server'], status: 'scheduled' },
  { id: 'EV002', name: 'Tech Summit 2024', location: 'Conference Hall A', date: new Date(2024, 5, 18, 9, 0), assignedStaff: ['Mike Manager', 'Lisa Coordinator'], status: 'preparing' },
  { id: 'EV003', name: 'Johnson Birthday', location: 'Garden Terrace', date: new Date(2024, 5, 20, 14, 0), assignedStaff: ['Emma Server', 'David Chef'], status: 'scheduled' },
  { id: 'EV004', name: 'Annual Charity Gala', location: 'Main Hall', date: new Date(2024, 5, 22, 19, 0), assignedStaff: ['Robert Manager', 'Anna Server', 'Chris Chef'], status: 'scheduled' },
];

const lowStockItems = [
  { id: 'INV001', name: 'Chicken Breast', currentStock: 15, minimumStock: 20, unit: 'kg', expiryDate: new Date(2024, 5, 10) },
  { id: 'INV002', name: 'Butter', currentStock: 5, minimumStock: 10, unit: 'packs' },
  { id: 'INV003', name: 'Fresh Cream', currentStock: 3, minimumStock: 8, unit: 'liters', expiryDate: new Date(2024, 5, 8) },
  { id: 'INV004', name: 'Parsley', currentStock: 0, minimumStock: 2, unit: 'bunches' },
];

const attendanceRecords = [
  { employeeId: 'EMP001', employeeName: 'John Chef', timeIn: new Date(2024, 5, 14, 8, 15), status: 'present', schedule: '8:00 AM - 4:00 PM' },
  { employeeId: 'EMP002', employeeName: 'Sarah Server', timeIn: new Date(2024, 5, 14, 8, 30), status: 'late', schedule: '8:00 AM - 4:00 PM' },
  { employeeId: 'EMP003', employeeName: 'Mike Manager', timeIn: new Date(2024, 5, 14, 7, 55), status: 'present', schedule: '9:00 AM - 5:00 PM' },
  { employeeId: 'EMP004', employeeName: 'Emma Server', timeIn: new Date(2024, 5, 14, 8, 5), status: 'present', schedule: '8:00 AM - 4:00 PM' },
  { employeeId: 'EMP005', employeeName: 'David Chef', timeIn: new Date(2024, 5, 14, 0, 0), status: 'leave', schedule: '8:00 AM - 4:00 PM' },
];

// -------------------- Chart Data --------------------
const salesData = [
  { month: 'Jan', revenue: 45000, orders: 28 },
  { month: 'Feb', revenue: 52000, orders: 32 },
  { month: 'Mar', revenue: 48000, orders: 30 },
  { month: 'Apr', revenue: 61000, orders: 38 },
  { month: 'May', revenue: 58000, orders: 35 },
  { month: 'Jun', revenue: 72000, orders: 42 },
];

const bookingTrends = [
  { week: 'Week 1', weddings: 5, corporate: 3, birthday: 7 },
  { week: 'Week 2', weddings: 4, corporate: 4, birthday: 6 },
  { week: 'Week 3', weddings: 6, corporate: 2, birthday: 8 },
  { week: 'Week 4', weddings: 7, corporate: 5, birthday: 5 },
];

const inventoryUsage = [
  { name: 'Meat', value: 35, color: '#3b82f6' },
  { name: 'Vegetables', value: 25, color: '#10b981' },
  { name: 'Dairy', value: 15, color: '#f59e0b' },
  { name: 'Beverages', value: 15, color: '#8b5cf6' },
  { name: 'Others', value: 10, color: '#ef4444' },
];

const customerGrowth = [
  { month: 'Jan', newCustomers: 12, returning: 45 },
  { month: 'Feb', newCustomers: 15, returning: 52 },
  { month: 'Mar', newCustomers: 18, returning: 58 },
  { month: 'Apr', newCustomers: 22, returning: 65 },
  { month: 'May', newCustomers: 25, returning: 72 },
  { month: 'Jun', newCustomers: 30, returning: 85 },
];

// -------------------- Helper Components --------------------
const MetricCard = ({ title, value, subtitle, icon, color, trend, trendValue, isDarkMode }) => (
  <motion.div
    initial={{ opacity: 0, y: 20 }}
    animate={{ opacity: 1, y: 0 }}
    transition={{ duration: 0.3 }}
    style={{ height: '100%' }}
  >
    <div className={`cd-metric-card ${isDarkMode ? 'cd-metric-card-dark' : ''}`}>
      <div className="cd-metric-card-content">
        <div className="cd-metric-card-info">
          <Text type="secondary" className="cd-metric-card-title">{title}</Text>
          <div className="cd-metric-card-value">
            {value}
          </div>
          {subtitle && (
            <Text type="secondary" className="cd-metric-card-subtitle">{subtitle}</Text>
          )}
        </div>
        <div className="cd-metric-card-icon" style={{ background: `${color}12`, color: color }}>
          {icon}
        </div>
      </div>
      {trend && (
        <Tag icon={trend > 0 ? <RiseOutlined /> : <FallOutlined />} color={trend > 0 ? 'success' : 'error'} className="cd-metric-card-trend">
          {trend > 0 ? '+' : ''}{trend}% {trendValue}
        </Tag>
      )}
    </div>
  </motion.div>
);

const StatusBadge = ({ status }) => {
  const statusMap = {
    pending: { color: 'gold', text: 'Pending' },
    approved: { color: 'blue', text: 'Approved' },
    completed: { color: 'green', text: 'Completed' },
    cancelled: { color: 'red', text: 'Cancelled' },
    scheduled: { color: 'blue', text: 'Scheduled' },
    preparing: { color: 'orange', text: 'Preparing' },
    ongoing: { color: 'green', text: 'Ongoing' },
    present: { color: 'green', text: 'Present' },
    late: { color: 'orange', text: 'Late' },
    absent: { color: 'red', text: 'Absent' },
    leave: { color: 'default', text: 'On Leave' },
  };
  return <Tag color={statusMap[status]?.color} className="cd-status-badge">{statusMap[status]?.text}</Tag>;
};

// -------------------- Main Dashboard Component --------------------
const CateringDashboard = () => {
  const [currentTime, setCurrentTime] = useState(new Date());
  const [selectedTimeRange, setSelectedTimeRange] = useState('week');
  const [isDarkMode, setIsDarkMode] = useState(() => {
    // Immediate theme detection on mount - no delay
    const savedTheme = localStorage.getItem('theme');
    if (savedTheme === 'dark') return true;
    if (savedTheme === 'light') return false;
    return document.body.classList.contains('dark-mode');
  });
  
  const isMounted = useRef(true);

  // Listen for theme changes from Navigation component
  useEffect(() => {
    isMounted.current = true;
    
    // Function to update theme immediately
    const updateTheme = () => {
      if (isMounted.current) {
        const isDark = document.body.classList.contains('dark-mode');
        setIsDarkMode(isDark);
      }
    };
    
    // Create a mutation observer for body class changes
    const observer = new MutationObserver(() => {
      updateTheme();
    });
    
    observer.observe(document.body, { attributes: true, attributeFilter: ['class'] });
    
    // Listen for custom theme change event
    const handleThemeChange = (e) => {
      if (isMounted.current) {
        setIsDarkMode(e.detail.isDark);
      }
    };
    
    // Listen for storage events (cross-tab)
    const handleStorageChange = (e) => {
      if (e.key === 'theme' && isMounted.current) {
        const isDark = e.newValue === 'dark';
        setIsDarkMode(isDark);
      }
    };
    
    window.addEventListener('themeChange', handleThemeChange);
    window.addEventListener('storage', handleStorageChange);
    
    return () => {
      isMounted.current = false;
      observer.disconnect();
      window.removeEventListener('themeChange', handleThemeChange);
      window.removeEventListener('storage', handleStorageChange);
    };
  }, []);

  // Timer for current time
  useEffect(() => {
    const timer = setInterval(() => {
      if (isMounted.current) {
        setCurrentTime(new Date());
      }
    }, 1000);
    return () => clearInterval(timer);
  }, []);

  // Metrics data
  const metrics = {
    totalBookings: 156,
    totalRevenue: 342500,
    activeOrders: 23,
    pendingPayments: 28450,
    pendingApprovals: 8,
    completedEvents: 134,
    upcomingEvents: upcomingEvents.length,
    activeStaff: attendanceRecords.filter(a => a.status === 'present').length
  };

  const recentBookingsColumns = [
    { title: 'Booking ID', dataIndex: 'id', key: 'id', render: (text) => <Text strong className="cd-booking-id">{text}</Text> },
    { title: 'Customer', dataIndex: 'customer', key: 'customer', render: (text) => <span>{text}</span> },
    { title: 'Event Type', dataIndex: 'eventType', key: 'eventType', render: (text) => <span>{text}</span> },
    { title: 'Event Date', dataIndex: 'eventDate', key: 'eventDate', render: (date) => <span>{format(date, 'MMM dd, yyyy')}</span> },
    { title: 'Status', dataIndex: 'status', key: 'status', render: (status) => <StatusBadge status={status} /> },
    { title: 'Amount', dataIndex: 'amount', key: 'amount', render: (amount) => <span className="cd-amount-value">${amount.toLocaleString()}</span> },
    { title: '', key: 'action', render: () => <Button type="text" icon={<EyeOutlined />} size="small" className="cd-action-btn" /> },
  ];

  const upcomingEventsColumns = [
    { title: 'Event Name', dataIndex: 'name', key: 'name', render: (text) => <span className="cd-event-name">{text}</span> },
    { title: 'Location', dataIndex: 'location', key: 'location', render: (text) => <span>{text}</span> },
    { title: 'Date', dataIndex: 'date', key: 'date', render: (date) => <span>{format(date, 'MMM dd, h:mm a')}</span> },
    { title: 'Staff', dataIndex: 'assignedStaff', key: 'assignedStaff', render: (staff) => (
      <Avatar.Group maxCount={3} size="small">
        {staff.map((name, i) => <Avatar key={i} className="cd-staff-avatar">{name.charAt(0)}</Avatar>)}
      </Avatar.Group>
    ) },
    { title: 'Status', dataIndex: 'status', key: 'status', render: (status) => <StatusBadge status={status} /> },
  ];

  const lowStockColumns = [
    { title: 'Ingredient', dataIndex: 'name', key: 'name', render: (text) => <span className="cd-ingredient-name">{text}</span> },
    { title: 'Stock Level', dataIndex: 'currentStock', key: 'currentStock', render: (stock, record) => (
      <div className="cd-stock-level-container">
        <div className="cd-stock-level-header">
          <span className="cd-stock-level-text">{stock} / {record.minimumStock} {record.unit}</span>
          <span className={`cd-stock-level-status ${stock === 0 ? 'cd-out-of-stock' : 'cd-low-stock'}`}>
            {stock === 0 ? 'Out of Stock' : 'Low Stock'}
          </span>
        </div>
        <Progress percent={Math.min(100, (stock / record.minimumStock) * 100)} size="small" status={stock === 0 ? 'exception' : 'active'} showInfo={false} className="cd-stock-progress" />
      </div>
    ) },
  ];

  const attendanceColumns = [
    { title: 'Employee', dataIndex: 'employeeName', key: 'employeeName', render: (name) => <Space><Avatar size="small" icon={<UserOutlined />} className="cd-employee-avatar" /><span>{name}</span></Space> },
    { title: 'Time In', dataIndex: 'timeIn', key: 'timeIn', render: (time) => time.getHours ? format(time, 'h:mm a') : '—' },
    { title: 'Status', dataIndex: 'status', key: 'status', render: (status) => <StatusBadge status={status} /> },
    { title: 'Schedule', dataIndex: 'schedule', key: 'schedule', render: (text) => <Text type="secondary" className="cd-schedule-text">{text}</Text> },
  ];

  // Chart color configurations based on theme
  const chartColors = {
    text: isDarkMode ? '#8b93a8' : '#64748b',
    grid: isDarkMode ? '#1e2340' : '#eef2ff',
    tooltip: {
      backgroundColor: isDarkMode ? '#1a1f38' : '#ffffff',
      borderColor: isDarkMode ? '#2a2f4e' : '#eef2ff',
      textColor: isDarkMode ? '#ffffff' : '#1e293b'
    }
  };

  // Container classes based on theme
  const containerClass = `cd-dashboard-container ${isDarkMode ? 'cd-dark-mode' : ''}`;
  const logoContainerClass = `cd-logo-container ${isDarkMode ? 'cd-logo-container-dark' : ''}`;
  const chartCardClass = `cd-chart-card ${isDarkMode ? 'cd-chart-card-dark' : ''}`;
  const tableCardClass = `cd-table-card ${isDarkMode ? 'cd-table-card-dark' : ''}`;
  const tableClass = `cd-data-table ${isDarkMode ? 'cd-table-dark' : ''}`;
  const quickActionsCardClass = `cd-quick-actions-card ${isDarkMode ? 'cd-quick-actions-card-dark' : ''}`;
  const quickActionBtnClass = `cd-quick-action-btn ${isDarkMode ? 'cd-quick-action-btn-dark' : ''}`;
  const activityItemClass = `cd-activity-item ${isDarkMode ? 'cd-activity-item-dark' : ''}`;

  return (
    <ConfigProvider
      theme={{
        algorithm: isDarkMode ? antdTheme.darkAlgorithm : antdTheme.defaultAlgorithm,
        token: {
          colorBgContainer: isDarkMode ? '#13172b' : '#ffffff',
          colorBorderSecondary: isDarkMode ? '#1e2340' : '#eef2ff',
          colorText: isDarkMode ? '#e2e8f0' : '#1e293b',
          colorTextSecondary: isDarkMode ? '#8b93a8' : '#64748b',
        },
      }}
    >
      <div className={containerClass}>
        <div className="cd-dashboard-content">
          
          {/* Header Section with Title and Date */}
          <div className="cd-dashboard-header">
            <div className="cd-header-left">
              <div className={logoContainerClass}>
                <DashboardOutlined className="cd-logo-icon" />
              </div>
              <div>
                <Title level={3} className="cd-dashboard-title">
                  Catering Management Dashboard
                </Title>
                <div className="cd-datetime-container">
                  <Text type="secondary" className="cd-date-text">
                    {format(currentTime, 'EEEE, MMMM dd, yyyy')}
                  </Text>
                  <Text type="secondary" className="cd-time-text">
                    {format(currentTime, 'h:mm a')}
                  </Text>
                </div>
              </div>
            </div>
            <Select 
              defaultValue="week" 
              className="cd-time-range-select"
              onChange={setSelectedTimeRange}
              suffixIcon={<ClockCircleOutlined />}
            >
              <Option value="day">Today</Option>
              <Option value="week">This Week</Option>
              <Option value="month">This Month</Option>
              <Option value="year">This Year</Option>
            </Select>
          </div>

          {/* Top Metrics Panel - Row 1: First 4 metrics */}
          <div className="cd-metrics-row">
            <Row gutter={[16, 16]}>
              <Col xs={24} sm={12} lg={6}>
                <MetricCard 
                  title="TOTAL BOOKINGS" 
                  value={metrics.totalBookings} 
                  icon={<CalendarOutlined style={{ fontSize: 22 }} />} 
                  color="#3b82f6"
                  trend={12}
                  trendValue="vs last month"
                  isDarkMode={isDarkMode}
                />
              </Col>
              <Col xs={24} sm={12} lg={6}>
                <MetricCard 
                  title="TOTAL REVENUE" 
                  value={`$${metrics.totalRevenue.toLocaleString()}`} 
                  icon={<DollarOutlined style={{ fontSize: 22 }} />} 
                  color="#10b981"
                  trend={8}
                  trendValue="vs last month"
                  isDarkMode={isDarkMode}
                />
              </Col>
              <Col xs={24} sm={12} lg={6}>
                <MetricCard 
                  title="ACTIVE ORDERS" 
                  value={metrics.activeOrders} 
                  icon={<ShoppingOutlined style={{ fontSize: 22 }} />} 
                  color="#f59e0b"
                  trend={-3}
                  trendValue="vs yesterday"
                  isDarkMode={isDarkMode}
                />
              </Col>
              <Col xs={24} sm={12} lg={6}>
                <MetricCard 
                  title="PENDING PAYMENTS" 
                  value={`$${metrics.pendingPayments.toLocaleString()}`} 
                  icon={<CreditCardOutlined style={{ fontSize: 22 }} />} 
                  color="#ef4444"
                  trend={5}
                  trendValue="vs last week"
                  isDarkMode={isDarkMode}
                />
              </Col>
            </Row>
          </div>

          {/* Top Metrics Panel - Row 2: Next 4 metrics */}
          <div className="cd-metrics-row">
            <Row gutter={[16, 16]}>
              <Col xs={24} sm={12} lg={6}>
                <MetricCard 
                  title="PENDING APPROVALS" 
                  value={metrics.pendingApprovals} 
                  icon={<ClockCircleOutlined style={{ fontSize: 22 }} />} 
                  color="#8b5cf6"
                  isDarkMode={isDarkMode}
                />
              </Col>
              <Col xs={24} sm={12} lg={6}>
                <MetricCard 
                  title="COMPLETED EVENTS" 
                  value={metrics.completedEvents} 
                  icon={<CheckCircleOutlined style={{ fontSize: 22 }} />} 
                  color="#06b6d4"
                  trend={15}
                  trendValue="completion rate"
                  isDarkMode={isDarkMode}
                />
              </Col>
              <Col xs={24} sm={12} lg={6}>
                <MetricCard 
                  title="UPCOMING EVENTS" 
                  value={metrics.upcomingEvents} 
                  icon={<GiftOutlined style={{ fontSize: 22 }} />} 
                  color="#ec4899"
                  isDarkMode={isDarkMode}
                />
              </Col>
              <Col xs={24} sm={12} lg={6}>
                <MetricCard 
                  title="ACTIVE STAFF" 
                  value={metrics.activeStaff} 
                  icon={<TeamOutlined style={{ fontSize: 22 }} />} 
                  color="#f97316"
                  isDarkMode={isDarkMode}
                />
              </Col>
            </Row>
          </div>

          {/* Charts Section */}
          <Row gutter={[24, 24]} className="cd-charts-row">
            <Col xs={24} lg={16}>
              <Card 
                title="Revenue & Orders Overview" 
                extra={<Button icon={<ExportOutlined />} className="cd-export-btn">Export Data</Button>}
                className={chartCardClass}
              >
                <ResponsiveContainer width="100%" height={360}>
                  <LineChart data={salesData}>
                    <CartesianGrid strokeDasharray="3 3" stroke={chartColors.grid} />
                    <XAxis dataKey="month" stroke={chartColors.text} />
                    <YAxis yAxisId="left" stroke={chartColors.text} />
                    <YAxis yAxisId="right" orientation="right" stroke={chartColors.text} />
                    <RechartsTooltip 
                      contentStyle={{ 
                        borderRadius: 12, 
                        border: `1px solid ${chartColors.tooltip.borderColor}`,
                        backgroundColor: chartColors.tooltip.backgroundColor,
                        color: chartColors.tooltip.textColor,
                        boxShadow: '0 4px 12px rgba(0,0,0,0.1)' 
                      }} 
                    />
                    <Legend wrapperStyle={{ color: chartColors.text }} />
                    <Line yAxisId="left" type="monotone" dataKey="revenue" stroke="#3b82f6" name="Revenue ($)" strokeWidth={2.5} dot={{ r: 5, fill: '#3b82f6', strokeWidth: 0 }} />
                    <Line yAxisId="right" type="monotone" dataKey="orders" stroke="#10b981" name="Orders" strokeWidth={2.5} dot={{ r: 5, fill: '#10b981', strokeWidth: 0 }} />
                  </LineChart>
                </ResponsiveContainer>
              </Card>
            </Col>
            <Col xs={24} lg={8}>
              <Card title="Inventory Distribution" className={chartCardClass}>
                <ResponsiveContainer width="100%" height={360}>
                  <PieChart>
                    <Pie data={inventoryUsage} cx="50%" cy="50%" innerRadius={65} outerRadius={110} paddingAngle={5} dataKey="value" label>
                      {inventoryUsage.map((entry, index) => (
                        <Cell key={`cell-${index}`} fill={entry.color} stroke="none" />
                      ))}
                    </Pie>
                    <RechartsTooltip 
                      contentStyle={{ 
                        borderRadius: 12, 
                        border: `1px solid ${chartColors.tooltip.borderColor}`,
                        backgroundColor: chartColors.tooltip.backgroundColor,
                        color: chartColors.tooltip.textColor
                      }} 
                    />
                    <Legend wrapperStyle={{ color: chartColors.text }} verticalAlign="bottom" height={36} />
                  </PieChart>
                </ResponsiveContainer>
              </Card>
            </Col>
          </Row>

          <Row gutter={[24, 24]} className="cd-charts-row">
            <Col xs={24} lg={12}>
              <Card title="Booking Trends by Event Type" className={chartCardClass}>
                <ResponsiveContainer width="100%" height={320}>
                  <BarChart data={bookingTrends}>
                    <CartesianGrid strokeDasharray="3 3" stroke={chartColors.grid} />
                    <XAxis dataKey="week" stroke={chartColors.text} />
                    <YAxis stroke={chartColors.text} />
                    <RechartsTooltip 
                      contentStyle={{ 
                        borderRadius: 12, 
                        border: `1px solid ${chartColors.tooltip.borderColor}`,
                        backgroundColor: chartColors.tooltip.backgroundColor,
                        color: chartColors.tooltip.textColor
                      }} 
                    />
                    <Legend wrapperStyle={{ color: chartColors.text }} />
                    <Bar dataKey="weddings" fill="#3b82f6" name="Weddings" radius={[8, 8, 0, 0]} />
                    <Bar dataKey="corporate" fill="#10b981" name="Corporate" radius={[8, 8, 0, 0]} />
                    <Bar dataKey="birthday" fill="#f59e0b" name="Birthday" radius={[8, 8, 0, 0]} />
                  </BarChart>
                </ResponsiveContainer>
              </Card>
            </Col>
            <Col xs={24} lg={12}>
              <Card title="Customer Growth Trends" className={chartCardClass}>
                <ResponsiveContainer width="100%" height={320}>
                  <AreaChart data={customerGrowth}>
                    <CartesianGrid strokeDasharray="3 3" stroke={chartColors.grid} />
                    <XAxis dataKey="month" stroke={chartColors.text} />
                    <YAxis stroke={chartColors.text} />
                    <RechartsTooltip 
                      contentStyle={{ 
                        borderRadius: 12, 
                        border: `1px solid ${chartColors.tooltip.borderColor}`,
                        backgroundColor: chartColors.tooltip.backgroundColor,
                        color: chartColors.tooltip.textColor
                      }} 
                    />
                    <Legend wrapperStyle={{ color: chartColors.text }} />
                    <Area type="monotone" dataKey="newCustomers" stackId="1" stroke="#3b82f6" fill="#3b82f615" name="New Customers" />
                    <Area type="monotone" dataKey="returning" stackId="2" stroke="#10b981" fill="#10b98115" name="Returning Customers" />
                  </AreaChart>
                </ResponsiveContainer>
              </Card>
            </Col>
          </Row>

          {/* Tables Section */}
          <Row gutter={[24, 24]} className="cd-tables-row">
            <Col span={24}>
              <Card 
                title="Recent Bookings" 
                extra={
                  <Button type="primary" icon={<PlusOutlined />} className="cd-new-booking-btn">
                    New Booking
                  </Button>
                }
                className={tableCardClass}
              >
                <Table 
                  columns={recentBookingsColumns} 
                  dataSource={recentBookings} 
                  rowKey="id" 
                  pagination={{ pageSize: 5, showSizeChanger: true, showTotal: (total) => `Total ${total} bookings` }} 
                  className={tableClass}
                />
              </Card>
            </Col>
          </Row>

          <Row gutter={[24, 24]} className="cd-tables-row">
            <Col xs={24} lg={12}>
              <Card 
                title="Upcoming Events" 
                extra={<Button type="link" className="cd-view-all-btn">View All →</Button>}
                className={tableCardClass}
              >
                <Table columns={upcomingEventsColumns} dataSource={upcomingEvents} rowKey="id" pagination={false} className={tableClass} />
              </Card>
            </Col>
            <Col xs={24} lg={12}>
              <Card 
                title="Inventory Alerts" 
                extra={<Button type="link" className="cd-view-all-btn">Manage Inventory →</Button>}
                className={tableCardClass}
              >
                <Table columns={lowStockColumns} dataSource={lowStockItems} rowKey="id" pagination={false} className={tableClass} />
              </Card>
            </Col>
          </Row>

          <Row gutter={[24, 24]} className="cd-tables-row">
            <Col xs={24} lg={12}>
              <Card title="Staff Attendance Overview" className={tableCardClass}>
                <Table columns={attendanceColumns} dataSource={attendanceRecords} rowKey="employeeId" pagination={false} className={tableClass} />
                <Divider className="cd-attendance-divider" />
                <div className="cd-attendance-summary">
                  <div><Text type="secondary">✅ Present: {attendanceRecords.filter(a => a.status === 'present').length}</Text></div>
                  <div><Text type="warning">⚠️ Late: {attendanceRecords.filter(a => a.status === 'late').length}</Text></div>
                  <div><Text type="secondary">📋 Leave: {attendanceRecords.filter(a => a.status === 'leave').length}</Text></div>
                </div>
              </Card>
            </Col>
            <Col xs={24} lg={12}>
              <Card title="Recent Activity & Updates" className={tableCardClass}>
                <List
                  dataSource={[
                    { title: 'Schedule Created', value: '0 total shifts saved', icon: <ScheduleOutlined style={{ color: '#3b82f6' }} /> },
                    { title: 'Staff Scheduled', value: '2 active', icon: <TeamOutlined style={{ color: '#10b981' }} /> },
                    { title: 'Employee Requests', value: '0 pending approval', icon: <UserOutlined style={{ color: '#f59e0b' }} /> },
                    { title: 'Total Hours', value: '0.0 for ' + format(new Date(), 'yyyy-MM-dd'), icon: <ClockCircleOutlined style={{ color: '#8b5cf6' }} /> },
                  ]}
                  renderItem={(item) => (
                    <div className={activityItemClass}>
                      <Space>
                        <div className="cd-activity-icon">
                          {item.icon}
                        </div>
                        <Text strong className="cd-activity-title">{item.title}</Text>
                      </Space>
                      <Text className="cd-activity-value">{item.value}</Text>
                    </div>
                  )}
                />
              </Card>
            </Col>
          </Row>

          {/* Quick Actions */}
          <Card title="Quick Actions" className={quickActionsCardClass}>
            <Row gutter={[20, 20]}>
              <Col xs={12} sm={8} md={4}>
                <Button className={quickActionBtnClass} icon={<FileTextOutlined style={{ fontSize: 20 }} />} block size="large">
                  <span className="cd-action-label">Create Quotation</span>
                </Button>
              </Col>
              <Col xs={12} sm={8} md={4}>
                <Button className={quickActionBtnClass} icon={<CalendarOutlined style={{ fontSize: 20 }} />} block size="large">
                  <span className="cd-action-label">Add Booking</span>
                </Button>
              </Col>
              <Col xs={12} sm={8} md={4}>
                <Button className={quickActionBtnClass} icon={<InboxOutlined style={{ fontSize: 20 }} />} block size="large">
                  <span className="cd-action-label">Add Stock</span>
                </Button>
              </Col>
              <Col xs={12} sm={8} md={4}>
                <Button className={quickActionBtnClass} icon={<DollarOutlined style={{ fontSize: 20 }} />} block size="large">
                  <span className="cd-action-label">Generate Payroll</span>
                </Button>
              </Col>
              <Col xs={12} sm={8} md={4}>
                <Button className={quickActionBtnClass} icon={<BarChartOutlined style={{ fontSize: 20 }} />} block size="large">
                  <span className="cd-action-label">View Reports</span>
                </Button>
              </Col>
              <Col xs={12} sm={8} md={4}>
                <Button className={quickActionBtnClass} icon={<TruckOutlined style={{ fontSize: 20 }} />} block size="large">
                  <span className="cd-action-label">Track Orders</span>
                </Button>
              </Col>
            </Row>
          </Card>

          {/* Footer */}
          <div className="cd-dashboard-footer">
            <Text type="secondary" className="cd-footer-text">
              © 2024 CaterFlow - Catering Management System | All metrics are real-time and automatically updated
            </Text>
          </div>
        </div>
      </div>
    </ConfigProvider>
  );
};

export default CateringDashboard;