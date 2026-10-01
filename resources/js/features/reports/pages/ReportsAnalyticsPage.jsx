import React, { useState, useEffect } from 'react';
import {
  Row,
  Col,
  Card,
  Table,
  Button,
  Tag,
  Typography,
  Space,
  Select,
  DatePicker,
  Tabs,
  Progress,
  Statistic,
  Divider,
  List,
  Alert,
  Badge,
  Tooltip,
  Avatar,
  Rate,
  ConfigProvider,
  theme as antdTheme
} from 'antd';
import {
  DollarOutlined,
  CalendarOutlined,
  InboxOutlined,
  TeamOutlined,
  GiftOutlined,
  UserOutlined,
  MenuOutlined,
  FilePdfOutlined,
  FileExcelOutlined,
  PrinterOutlined,
  DownloadOutlined,
  MailOutlined,
  StarOutlined,
  WarningOutlined,
  CheckCircleOutlined,
  ClockCircleOutlined,
  ShoppingOutlined,
  PercentageOutlined,
  ExportOutlined,
  ReloadOutlined,
  ArrowUpOutlined,
  ArrowDownOutlined
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
  ResponsiveContainer,
  ComposedChart,
  RadarChart,
  PolarGrid,
  PolarAngleAxis,
  PolarRadiusAxis,
  Radar
} from 'recharts';
import { motion } from 'framer-motion';

import '../styles/Reports.css';

const { Title, Text, Paragraph } = Typography;
const { RangePicker } = DatePicker;
const { Option } = Select;
const { TabPane } = Tabs;

// -------------------- Mock Data for Reports --------------------
const dailySalesData = [
  { date: '2024-05-15', totalSales: 12500, orders: 18, revenue: 11800 },
  { date: '2024-05-16', totalSales: 8900, orders: 12, revenue: 8200 },
  { date: '2024-05-17', totalSales: 15400, orders: 24, revenue: 14600 },
  { date: '2024-05-18', totalSales: 6700, orders: 9, revenue: 6100 },
  { date: '2024-05-19', totalSales: 10300, orders: 15, revenue: 9800 },
  { date: '2024-05-20', totalSales: 18800, orders: 28, revenue: 17900 },
  { date: '2024-05-21', totalSales: 9200, orders: 13, revenue: 8500 },
];

const weeklySalesData = [
  { week: 'Week 1', sales: 45200, orders: 68, revenue: 42800 },
  { week: 'Week 2', sales: 38900, orders: 55, revenue: 36500 },
  { week: 'Week 3', sales: 51200, orders: 78, revenue: 48700 },
  { week: 'Week 4', sales: 47800, orders: 72, revenue: 45300 },
];

const monthlySalesData = [
  { month: 'Jan', sales: 156000, orders: 245, revenue: 148200 },
  { month: 'Feb', sales: 142000, orders: 218, revenue: 134900 },
  { month: 'Mar', sales: 168000, orders: 262, revenue: 159600 },
  { month: 'Apr', sales: 185000, orders: 289, revenue: 175750 },
  { month: 'May', sales: 198000, orders: 312, revenue: 188100 },
  { month: 'Jun', sales: 212000, orders: 335, revenue: 201400 },
];

const bestSellingPackages = [
  { name: 'Premium Wedding Package', sales: 28, revenue: 350000, percentage: 32 },
  { name: 'Corporate Elite Package', sales: 45, revenue: 225000, percentage: 21 },
  { name: 'Birthday Deluxe', sales: 62, revenue: 148800, percentage: 14 },
  { name: 'Anniversary Special', sales: 38, revenue: 106400, percentage: 10 },
  { name: 'Basic Catering', sales: 85, revenue: 85000, percentage: 8 },
];

const bookingTrendsData = [
  { month: 'Jan', bookings: 42, approved: 38, cancelled: 4 },
  { month: 'Feb', bookings: 38, approved: 35, cancelled: 3 },
  { month: 'Mar', bookings: 45, approved: 42, cancelled: 3 },
  { month: 'Apr', bookings: 52, approved: 48, cancelled: 4 },
  { month: 'May', bookings: 58, approved: 54, cancelled: 4 },
  { month: 'Jun', bookings: 65, approved: 61, cancelled: 4 },
];

const eventTypeTrends = [
  { type: 'Wedding', count: 156, revenue: 1950000, percentage: 45 },
  { type: 'Corporate', count: 98, revenue: 882000, percentage: 28 },
  { type: 'Birthday', count: 145, revenue: 580000, percentage: 15 },
  { type: 'Anniversary', count: 67, revenue: 335000, percentage: 8 },
  { type: 'Other', count: 34, revenue: 136000, percentage: 4 },
];

const inventoryUsageData = [
  { ingredient: 'Chicken Breast', usedQuantity: 450, remainingStock: 85, unit: 'kg', cost: 2250, reorderStatus: 'critical', minimumStock: 100 },
  { ingredient: 'Beef', usedQuantity: 320, remainingStock: 120, unit: 'kg', cost: 2560, reorderStatus: 'low', minimumStock: 150 },
  { ingredient: 'Salmon', usedQuantity: 180, remainingStock: 45, unit: 'kg', cost: 1980, reorderStatus: 'critical', minimumStock: 60 },
  { ingredient: 'Butter', usedQuantity: 95, remainingStock: 12, unit: 'packs', cost: 285, reorderStatus: 'critical', minimumStock: 20 },
  { ingredient: 'Flour', usedQuantity: 280, remainingStock: 65, unit: 'kg', cost: 140, reorderStatus: 'normal', minimumStock: 50 },
  { ingredient: 'Cream', usedQuantity: 120, remainingStock: 8, unit: 'liters', cost: 480, reorderStatus: 'critical', minimumStock: 15 },
  { ingredient: 'Vegetables', usedQuantity: 650, remainingStock: 150, unit: 'kg', cost: 1950, reorderStatus: 'low', minimumStock: 200 },
  { ingredient: 'Cheese', usedQuantity: 85, remainingStock: 22, unit: 'kg', cost: 680, reorderStatus: 'low', minimumStock: 30 },
];

const stockMovementData = [
  { month: 'Jan', incoming: 12500, outgoing: 9800, wastage: 320 },
  { month: 'Feb', incoming: 11800, outgoing: 10200, wastage: 280 },
  { month: 'Mar', incoming: 14200, outgoing: 12100, wastage: 350 },
  { month: 'Apr', incoming: 15800, outgoing: 13800, wastage: 310 },
  { month: 'May', incoming: 16500, outgoing: 14900, wastage: 290 },
  { month: 'Jun', incoming: 18200, outgoing: 16200, wastage: 340 },
];

const attendanceSummaryData = [
  { employee: 'John Chef', present: 22, late: 1, absent: 0, overtime: 8, totalHours: 176, grossPay: 3520, netPay: 2816 },
  { employee: 'Sarah Server', present: 20, late: 2, absent: 1, overtime: 4, totalHours: 164, grossPay: 2624, netPay: 2099 },
  { employee: 'Mike Manager', present: 22, late: 0, absent: 0, overtime: 6, totalHours: 182, grossPay: 4550, netPay: 3640 },
  { employee: 'Emma Server', present: 21, late: 1, absent: 0, overtime: 2, totalHours: 170, grossPay: 2720, netPay: 2176 },
  { employee: 'David Chef', present: 18, late: 1, absent: 3, overtime: 0, totalHours: 144, grossPay: 2880, netPay: 2304 },
];

const financialData = [
  { month: 'Jan', revenue: 148200, expenses: 98500, profit: 49700, payroll: 18400 },
  { month: 'Feb', revenue: 134900, expenses: 91200, profit: 43700, payroll: 17600 },
  { month: 'Mar', revenue: 159600, expenses: 105000, profit: 54600, payroll: 19200 },
  { month: 'Apr', revenue: 175750, expenses: 112000, profit: 63750, payroll: 20100 },
  { month: 'May', revenue: 188100, expenses: 118500, profit: 69600, payroll: 21500 },
  { month: 'Jun', revenue: 201400, expenses: 125000, profit: 76400, payroll: 22800 },
];

const outstandingBalances = [
  { customer: 'James Wilson', amount: 8500, dueDate: '2024-06-01', status: 'overdue' },
  { customer: 'Olivia Martinez', amount: 4800, dueDate: '2024-06-05', status: 'pending' },
  { customer: 'Emma Watson', amount: 2500, dueDate: '2024-05-28', status: 'overdue' },
  { customer: 'Michael Brown', amount: 0, dueDate: null, status: 'paid' },
  { customer: 'Sophia Chen', amount: 0, dueDate: null, status: 'paid' },
];

const customerAnalyticsData = [
  { customer: 'Emma Watson', totalBookings: 8, totalSpent: 98500, satisfaction: 4.9, repeatStatus: 'loyal' },
  { customer: 'James Wilson', totalBookings: 5, totalSpent: 42500, satisfaction: 4.2, repeatStatus: 'regular' },
  { customer: 'Sophia Chen', totalBookings: 12, totalSpent: 118500, satisfaction: 4.8, repeatStatus: 'loyal' },
  { customer: 'Michael Brown', totalBookings: 3, totalSpent: 18500, satisfaction: 4.5, repeatStatus: 'regular' },
  { customer: 'Olivia Martinez', totalBookings: 6, totalSpent: 56200, satisfaction: 4.7, repeatStatus: 'loyal' },
];

const eventProfitabilityData = [
  { event: 'Smith Wedding', revenue: 12500, expenses: 7800, profit: 4700, margin: 37.6 },
  { event: 'Tech Summit', revenue: 18500, expenses: 11200, profit: 7300, margin: 39.5 },
  { event: 'Johnson Birthday', revenue: 5600, expenses: 3400, profit: 2200, margin: 39.3 },
  { event: 'Charity Gala', revenue: 15200, expenses: 9800, profit: 5400, margin: 35.5 },
  { event: 'Corporate Meeting', revenue: 8900, expenses: 5600, profit: 3300, margin: 37.1 },
];

const menuPerformanceData = [
  { item: 'Grilled Salmon', orders: 245, revenue: 12250, profitability: 45, popularity: 92 },
  { item: 'Beef Wellington', orders: 189, revenue: 17010, profitability: 52, popularity: 88 },
  { item: 'Chicken Cordon Bleu', orders: 312, revenue: 15600, profitability: 38, popularity: 95 },
  { item: 'Mushroom Risotto', orders: 178, revenue: 7120, profitability: 42, popularity: 78 },
  { item: 'Tiramisu', orders: 289, revenue: 8670, profitability: 55, popularity: 91 },
];

const COLORS = ['#3b82f6', '#10b981', '#f59e0b', '#ef4444', '#8b5cf6', '#ec4899', '#06b6d4', '#f97316'];

const formatCurrency = (value) => {
  if (value === undefined || value === null) return '$0';
  return `$${value.toLocaleString()}`;
};

const formatNumber = (value) => {
  if (value === undefined || value === null) return '0';
  return value.toLocaleString();
};

// -------------------- Helper Components --------------------
const MetricCard = ({ title, value, subtitle, icon, color, trend, trendValue, prefix, isDarkMode }) => (
  <motion.div
    initial={{ opacity: 0, y: 20 }}
    animate={{ opacity: 1, y: 0 }}
    transition={{ duration: 0.3 }}
    style={{ height: '100%' }}
  >
    <Card className={`metric-card ${isDarkMode ? 'dark-mode-card' : ''}`} bodyStyle={{ padding: '20px' }}>
      <div className="metric-card-content">
        <div className="metric-card-info">
          <Text type="secondary" className="metric-card-title">{title}</Text>
          <div className="metric-card-value">
            {prefix}{typeof value === 'number' ? value.toLocaleString() : value}
          </div>
          {subtitle && <Text type="secondary" className="metric-card-subtitle">{subtitle}</Text>}
          {trend && (
            <Tag icon={trend > 0 ? <ArrowUpOutlined /> : <ArrowDownOutlined />} color={trend > 0 ? 'success' : 'error'} className="metric-card-trend">
              {trend > 0 ? '+' : ''}{trend}% {trendValue}
            </Tag>
          )}
        </div>
        <div className="metric-card-icon" style={{ background: `${color}10`, color: color }}>
          {icon}
        </div>
      </div>
    </Card>
  </motion.div>
);

const StatusBadge = ({ status }) => {
  const config = {
    critical: { color: 'error', text: 'Critical' },
    low: { color: 'warning', text: 'Low Stock' },
    normal: { color: 'success', text: 'Normal' },
    overdue: { color: 'error', text: 'Overdue' },
    pending: { color: 'warning', text: 'Pending' },
    paid: { color: 'success', text: 'Paid' },
    loyal: { color: 'gold', text: 'Loyal' },
    regular: { color: 'blue', text: 'Regular' }
  };
  const c = config[status] || { color: 'default', text: status };
  return <Badge status={c.color} text={c.text} />;
};

// -------------------- Main Reports Component --------------------
const ReportsAnalytics = () => {
  const [activeTab, setActiveTab] = useState('1');
  const [dateRange, setDateRange] = useState('month');
  const [exportLoading, setExportLoading] = useState(false);
  const [isDarkMode, setIsDarkMode] = useState(false);

  // Listen for theme changes from Navigation component
  useEffect(() => {
    const checkTheme = () => {
      const isDark = document.body.classList.contains('dark-mode');
      setIsDarkMode(isDark);
    };
    
    checkTheme();
    
    const observer = new MutationObserver((mutations) => {
      mutations.forEach((mutation) => {
        if (mutation.attributeName === 'class') {
          const isDark = document.body.classList.contains('dark-mode');
          setIsDarkMode(isDark);
        }
      });
    });
    
    observer.observe(document.body, { attributes: true });
    
    const handleStorageChange = (e) => {
      if (e.key === 'theme') {
        const isDark = e.newValue === 'dark';
        setIsDarkMode(isDark);
      }
    };
    
    window.addEventListener('storage', handleStorageChange);
    
    return () => {
      observer.disconnect();
      window.removeEventListener('storage', handleStorageChange);
    };
  }, []);

  const salesTableColumns = [
    { title: 'Date', dataIndex: 'date', key: 'date', render: (val) => <Text strong>{val}</Text> },
    { title: 'Total Sales', dataIndex: 'totalSales', key: 'totalSales', render: (val) => <Text className="sales-amount">{formatCurrency(val)}</Text> },
    { title: 'Total Orders', dataIndex: 'orders', key: 'orders', render: (val) => <Tag color="blue">{formatNumber(val)}</Tag> },
    { title: 'Revenue', dataIndex: 'revenue', key: 'revenue', render: (val) => <Text className="revenue-amount">{formatCurrency(val)}</Text> },
    { title: 'Avg Order Value', key: 'avg', render: (_, record) => <Text type="secondary">{formatCurrency((record.totalSales || 0) / (record.orders || 1))}</Text> },
  ];

  const inventoryTableColumns = [
    { title: 'Ingredient', dataIndex: 'ingredient', key: 'ingredient', render: (text) => <Text strong>{text}</Text> },
    { title: 'Usage', dataIndex: 'usedQuantity', key: 'usedQuantity', render: (val, record) => `${formatNumber(val)} ${record.unit}` },
    { title: 'Stock Level', dataIndex: 'remainingStock', key: 'remainingStock', render: (val, record) => (
      <div className="stock-level-container">
        <div className="stock-level-header">
          <Text type="secondary" className="stock-level-text">{val} / {record.minimumStock} {record.unit}</Text>
          <Text type="secondary" className={`stock-level-percent ${val < record.minimumStock ? 'low-stock' : 'healthy-stock'}`}>
            {((val / (val + record.usedQuantity)) * 100).toFixed(0)}%
          </Text>
        </div>
        <Progress 
          percent={(val / (val + record.usedQuantity)) * 100} 
          size="small" 
          strokeColor={val < record.minimumStock ? '#ef4444' : '#10b981'}
          showInfo={false}
        />
      </div>
    ) },
    { title: 'Cost', dataIndex: 'cost', key: 'cost', render: (val) => <Text>{formatCurrency(val)}</Text> },
    { title: 'Status', dataIndex: 'reorderStatus', key: 'reorderStatus', render: (status) => <StatusBadge status={status} /> },
  ];

  const payrollTableColumns = [
    { title: 'Employee', dataIndex: 'employee', key: 'employee', render: (text) => <Space><Avatar size="small" className="employee-avatar">{text.charAt(0)}</Avatar><Text strong>{text}</Text></Space> },
    { title: 'Hours', dataIndex: 'totalHours', key: 'totalHours', render: (val) => <Tag color="cyan">{val}h</Tag> },
    { title: 'Gross Pay', dataIndex: 'grossPay', key: 'grossPay', render: (val) => <Text className="gross-pay">{formatCurrency(val)}</Text> },
    { title: 'Net Pay', dataIndex: 'netPay', key: 'netPay', render: (val) => <Text className="net-pay">{formatCurrency(val)}</Text> },
    { title: 'Overtime', dataIndex: 'overtime', key: 'overtime', render: (val) => val > 0 ? <Tag color="orange">{val}h</Tag> : '-' },
    { title: 'Attendance', key: 'attendance', render: (_, record) => `${record.present}/22 days (${((record.present/22)*100).toFixed(0)}%)` },
  ];

  const customerTableColumns = [
    { title: 'Customer', dataIndex: 'customer', key: 'customer', render: (text) => <Text strong>{text}</Text> },
    { title: 'Bookings', dataIndex: 'totalBookings', key: 'totalBookings', render: (val) => <Badge count={val} showZero color="blue" /> },
    { title: 'Total Spent', dataIndex: 'totalSpent', key: 'totalSpent', render: (val) => <Text className="customer-spent">{formatCurrency(val)}</Text> },
    { title: 'Satisfaction', dataIndex: 'satisfaction', key: 'satisfaction', render: (val) => (
      <Space>
        <Rate disabled defaultValue={val} className="satisfaction-rate" />
        <Text type="secondary">({val})</Text>
      </Space>
    ) },
    { title: 'Status', dataIndex: 'repeatStatus', key: 'repeatStatus', render: (status) => <StatusBadge status={status} /> },
  ];

  const eventProfitabilityTableColumns = [
    { title: 'Event', dataIndex: 'event', key: 'event', render: (text) => <Text strong>{text}</Text> },
    { title: 'Revenue', dataIndex: 'revenue', key: 'revenue', render: (val) => <Text className="event-revenue">{formatCurrency(val)}</Text> },
    { title: 'Expenses', dataIndex: 'expenses', key: 'expenses', render: (val) => <Text type="secondary">{formatCurrency(val)}</Text> },
    { title: 'Profit', dataIndex: 'profit', key: 'profit', render: (val) => <Text className="event-profit">{formatCurrency(val)}</Text> },
    { title: 'Margin', dataIndex: 'margin', key: 'margin', render: (val) => <Tag color={val > 38 ? 'green' : 'orange'}>{val}%</Tag> },
  ];

  const handleExport = (type) => {
    setExportLoading(true);
    setTimeout(() => setExportLoading(false), 1500);
  };

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
      <div className={`reports-container ${isDarkMode ? 'dark-mode' : ''}`}>
        <div className="reports-content">
          
          {/* Header */}
          <div className="reports-header">
            <div className="reports-title-section">
              <Title level={3} className="reports-title">Reports & Analytics</Title>
              <Paragraph className="reports-subtitle">Comprehensive business insights and performance metrics</Paragraph>
            </div>
            <Space size={12}>
              <Select 
                defaultValue="month" 
                className="date-range-select"
                onChange={setDateRange}
                suffixIcon={<CalendarOutlined />}
              >
                <Option value="week">This Week</Option>
                <Option value="month">This Month</Option>
                <Option value="quarter">This Quarter</Option>
                <Option value="year">This Year</Option>
              </Select>
              <RangePicker className="date-picker" />
              <Tooltip title="Export Data">
                <Button icon={<ExportOutlined />} loading={exportLoading} onClick={() => handleExport('pdf')} className="export-btn">
                  Export
                </Button>
              </Tooltip>
              <Tooltip title="Refresh">
                <Button icon={<ReloadOutlined />} className="refresh-btn" />
              </Tooltip>
            </Space>
          </div>

          {/* Key Metrics Row 1 */}
          <Row gutter={[16, 16]} className="metrics-row">
            <Col xs={24} sm={12} lg={6}>
              <MetricCard 
                title="Total Revenue" 
                value={1025600} 
                icon={<DollarOutlined />} 
                color="#3b82f6" 
                trend={18} 
                trendValue="vs last period" 
                prefix="$"
                isDarkMode={isDarkMode}
              />
            </Col>
            <Col xs={24} sm={12} lg={6}>
              <MetricCard 
                title="Total Orders" 
                value={1560} 
                icon={<ShoppingOutlined />} 
                color="#10b981" 
                trend={12} 
                trendValue="vs last period"
                isDarkMode={isDarkMode}
              />
            </Col>
            <Col xs={24} sm={12} lg={6}>
              <MetricCard 
                title="Profit Margin" 
                value="38.5%" 
                icon={<PercentageOutlined />} 
                color="#f59e0b" 
                trend={5} 
                trendValue="improvement"
                isDarkMode={isDarkMode}
              />
            </Col>
            <Col xs={24} sm={12} lg={6}>
              <MetricCard 
                title="Customer Satisfaction" 
                value="4.7/5" 
                icon={<StarOutlined />} 
                color="#ef4444" 
                trend={8} 
                trendValue="increase"
                isDarkMode={isDarkMode}
              />
            </Col>
          </Row>

          {/* Key Metrics Row 2 */}
          <Row gutter={[16, 16]} className="metrics-row">
            <Col xs={24} sm={12} lg={6}>
              <MetricCard 
                title="Active Bookings" 
                value={48} 
                icon={<CalendarOutlined />} 
                color="#8b5cf6" 
                subtitle="Next 30 days"
                isDarkMode={isDarkMode}
              />
            </Col>
            <Col xs={24} sm={12} lg={6}>
              <MetricCard 
                title="Low Stock Items" 
                value={7} 
                icon={<WarningOutlined />} 
                color="#ef4444" 
                subtitle="Requires attention"
                isDarkMode={isDarkMode}
              />
            </Col>
            <Col xs={24} sm={12} lg={6}>
              <MetricCard 
                title="Active Staff" 
                value={24} 
                icon={<TeamOutlined />} 
                color="#06b6d4" 
                subtitle="Present today"
                isDarkMode={isDarkMode}
              />
            </Col>
            <Col xs={24} sm={12} lg={6}>
              <MetricCard 
                title="Conversion Rate" 
                value="84.5%" 
                icon={<CheckCircleOutlined />} 
                color="#10b981" 
                trend={6} 
                trendValue="increase"
                isDarkMode={isDarkMode}
              />
            </Col>
          </Row>

          {/* Tabs Section */}
          <Card className="tabs-card">
            <Tabs activeKey={activeTab} onChange={setActiveTab} size="large" className="reports-tabs">
              {/* Sales Reports */}
              <TabPane tab={<span><DollarOutlined /> Sales</span>} key="1">
                <div className="tab-content">
                  <Row gutter={[24, 24]}>
                    <Col xs={24} lg={16}>
                      <Card title="Revenue & Orders Trend" className="chart-card">
                        <ResponsiveContainer width="100%" height={320}>
                          <ComposedChart data={monthlySalesData}>
                            <CartesianGrid strokeDasharray="3 3" stroke={chartColors.grid} />
                            <XAxis dataKey="month" stroke={chartColors.text} />
                            <YAxis yAxisId="left" stroke={chartColors.text} />
                            <YAxis yAxisId="right" orientation="right" stroke={chartColors.text} />
                            <RechartsTooltip 
                              contentStyle={{ 
                                borderRadius: 8, 
                                border: `1px solid ${chartColors.tooltip.borderColor}`,
                                backgroundColor: chartColors.tooltip.backgroundColor,
                                color: chartColors.tooltip.textColor,
                                boxShadow: '0 2px 8px rgba(0,0,0,0.1)'
                              }} 
                            />
                            <Legend wrapperStyle={{ color: chartColors.text }} />
                            <Bar yAxisId="left" dataKey="sales" fill="#3b82f6" name="Sales ($)" radius={[4, 4, 0, 0]} barSize={40} />
                            <Line yAxisId="right" type="monotone" dataKey="orders" stroke="#10b981" name="Orders" strokeWidth={2} dot={{ r: 4, fill: '#10b981' }} />
                          </ComposedChart>
                        </ResponsiveContainer>
                      </Card>
                    </Col>
                    <Col xs={24} lg={8}>
                      <Card title="Top Packages" className="chart-card">
                        <ResponsiveContainer width="100%" height={320}>
                          <PieChart>
                            <Pie data={bestSellingPackages} cx="50%" cy="50%" innerRadius={50} outerRadius={85} dataKey="percentage" label>
                              {bestSellingPackages.map((_, idx) => (<Cell key={idx} fill={COLORS[idx % COLORS.length]} />))}
                            </Pie>
                            <RechartsTooltip 
                              contentStyle={{ 
                                borderRadius: 8, 
                                border: `1px solid ${chartColors.tooltip.borderColor}`,
                                backgroundColor: chartColors.tooltip.backgroundColor,
                                color: chartColors.tooltip.textColor
                              }} 
                            />
                            <Legend wrapperStyle={{ color: chartColors.text }} />
                          </PieChart>
                        </ResponsiveContainer>
                      </Card>
                    </Col>
                  </Row>
                  <Row style={{ marginTop: 24 }}>
                    <Col span={24}>
                      <Card title="Daily Sales Report" extra={<Button icon={<DownloadOutlined />} size="small">Download</Button>} className="table-card">
                        <Table columns={salesTableColumns} dataSource={dailySalesData} rowKey="date" pagination={false} size="middle" />
                      </Card>
                    </Col>
                  </Row>
                </div>
              </TabPane>

              {/* Booking Reports */}
              <TabPane tab={<span><CalendarOutlined /> Bookings</span>} key="2">
                <div className="tab-content">
                  <Row gutter={[24, 24]}>
                    <Col xs={24} lg={12}>
                      <Card title="Booking Trends" className="chart-card">
                        <ResponsiveContainer width="100%" height={300}>
                          <AreaChart data={bookingTrendsData}>
                            <CartesianGrid strokeDasharray="3 3" stroke={chartColors.grid} />
                            <XAxis dataKey="month" stroke={chartColors.text} />
                            <YAxis stroke={chartColors.text} />
                            <RechartsTooltip 
                              contentStyle={{ 
                                borderRadius: 8, 
                                border: `1px solid ${chartColors.tooltip.borderColor}`,
                                backgroundColor: chartColors.tooltip.backgroundColor,
                                color: chartColors.tooltip.textColor
                              }} 
                            />
                            <Legend wrapperStyle={{ color: chartColors.text }} />
                            <Area type="monotone" dataKey="bookings" stackId="1" stroke="#3b82f6" fill="#3b82f620" name="Bookings" />
                            <Area type="monotone" dataKey="approved" stackId="2" stroke="#10b981" fill="#10b98120" name="Approved" />
                            <Area type="monotone" dataKey="cancelled" stackId="3" stroke="#ef4444" fill="#ef444420" name="Cancelled" />
                          </AreaChart>
                        </ResponsiveContainer>
                      </Card>
                    </Col>
                    <Col xs={24} lg={12}>
                      <Card title="Event Type Distribution" className="chart-card">
                        <ResponsiveContainer width="100%" height={300}>
                          <PieChart>
                            <Pie data={eventTypeTrends} cx="50%" cy="50%" innerRadius={45} outerRadius={85} dataKey="percentage" label>
                              {eventTypeTrends.map((_, idx) => (<Cell key={idx} fill={COLORS[idx % COLORS.length]} />))}
                            </Pie>
                            <RechartsTooltip 
                              contentStyle={{ 
                                borderRadius: 8, 
                                border: `1px solid ${chartColors.tooltip.borderColor}`,
                                backgroundColor: chartColors.tooltip.backgroundColor,
                                color: chartColors.tooltip.textColor
                              }} 
                            />
                            <Legend wrapperStyle={{ color: chartColors.text }} />
                          </PieChart>
                        </ResponsiveContainer>
                      </Card>
                    </Col>
                  </Row>
                  <Row style={{ marginTop: 24 }}>
                    <Col span={24}>
                      <Card title="Booking KPIs" className="stats-card">
                        <Row gutter={[24, 24]}>
                          <Col span={6}><Statistic title="Total Bookings" value={312} /></Col>
                          <Col span={6}><Statistic title="Conversion Rate" value={84.5} suffix="%" /></Col>
                          <Col span={6}><Statistic title="Cancellation Rate" value={6.2} suffix="%" /></Col>
                          <Col span={6}><Statistic title="Peak Season" value="Jun-Aug" /></Col>
                        </Row>
                      </Card>
                    </Col>
                  </Row>
                </div>
              </TabPane>

              {/* Inventory Reports */}
              <TabPane tab={<span><InboxOutlined /> Inventory</span>} key="3">
                <div className="tab-content">
                  <Row gutter={[24, 24]}>
                    <Col xs={24} lg={16}>
                      <Card title="Stock Movement Analysis" className="chart-card">
                        <ResponsiveContainer width="100%" height={320}>
                          <BarChart data={stockMovementData}>
                            <CartesianGrid strokeDasharray="3 3" stroke={chartColors.grid} />
                            <XAxis dataKey="month" stroke={chartColors.text} />
                            <YAxis stroke={chartColors.text} />
                            <RechartsTooltip 
                              contentStyle={{ 
                                borderRadius: 8, 
                                border: `1px solid ${chartColors.tooltip.borderColor}`,
                                backgroundColor: chartColors.tooltip.backgroundColor,
                                color: chartColors.tooltip.textColor
                              }} 
                            />
                            <Legend wrapperStyle={{ color: chartColors.text }} />
                            <Bar dataKey="incoming" fill="#3b82f6" name="Incoming (kg)" radius={[4, 4, 0, 0]} />
                            <Bar dataKey="outgoing" fill="#10b981" name="Outgoing (kg)" radius={[4, 4, 0, 0]} />
                            <Bar dataKey="wastage" fill="#ef4444" name="Wastage (kg)" radius={[4, 4, 0, 0]} />
                          </BarChart>
                        </ResponsiveContainer>
                      </Card>
                    </Col>
                    <Col xs={24} lg={8}>
                      <Card title="Alerts & Status" className="alerts-card">
                        <div>
                          <div className="stock-health">
                            <div className="stock-health-header">
                              <Text strong>Stock Health</Text>
                              <Text className="stock-health-value">65%</Text>
                            </div>
                            <Progress percent={65} strokeColor="#f59e0b" />
                          </div>
                          <Divider />
                          <Text strong>Critical Items:</Text>
                          {inventoryUsageData.filter(i => i.reorderStatus === 'critical').slice(0, 3).map(item => (
                            <Alert key={item.ingredient} message={item.ingredient} description={`${item.remainingStock} ${item.unit} remaining`} type="error" showIcon className="critical-alert" />
                          ))}
                        </div>
                      </Card>
                    </Col>
                  </Row>
                  <Row style={{ marginTop: 24 }}>
                    <Col span={24}>
                      <Card title="Inventory Usage Report" className="table-card">
                        <Table columns={inventoryTableColumns} dataSource={inventoryUsageData} rowKey="ingredient" pagination={false} size="middle" />
                      </Card>
                    </Col>
                  </Row>
                </div>
              </TabPane>

              {/* Employee Reports */}
              <TabPane tab={<span><TeamOutlined /> Staff</span>} key="4">
                <div className="tab-content">
                  <Row gutter={[24, 24]}>
                    <Col xs={24} lg={12}>
                      <Card title="Attendance Overview" className="chart-card">
                        <ResponsiveContainer width="100%" height={300}>
                          <BarChart data={attendanceSummaryData}>
                            <CartesianGrid strokeDasharray="3 3" stroke={chartColors.grid} />
                            <XAxis dataKey="employee" angle={-45} textAnchor="end" height={80} stroke={chartColors.text} />
                            <YAxis stroke={chartColors.text} />
                            <RechartsTooltip 
                              contentStyle={{ 
                                borderRadius: 8, 
                                border: `1px solid ${chartColors.tooltip.borderColor}`,
                                backgroundColor: chartColors.tooltip.backgroundColor,
                                color: chartColors.tooltip.textColor
                              }} 
                            />
                            <Legend wrapperStyle={{ color: chartColors.text }} />
                            <Bar dataKey="present" fill="#3b82f6" name="Present" radius={[4, 4, 0, 0]} />
                            <Bar dataKey="late" fill="#f59e0b" name="Late" radius={[4, 4, 0, 0]} />
                            <Bar dataKey="absent" fill="#ef4444" name="Absent" radius={[4, 4, 0, 0]} />
                          </BarChart>
                        </ResponsiveContainer>
                      </Card>
                    </Col>
                    <Col xs={24} lg={12}>
                      <Card title="Payroll Summary" className="chart-card">
                        <ResponsiveContainer width="100%" height={300}>
                          <LineChart data={attendanceSummaryData}>
                            <CartesianGrid strokeDasharray="3 3" stroke={chartColors.grid} />
                            <XAxis dataKey="employee" angle={-45} textAnchor="end" height={80} stroke={chartColors.text} />
                            <YAxis stroke={chartColors.text} />
                            <RechartsTooltip 
                              contentStyle={{ 
                                borderRadius: 8, 
                                border: `1px solid ${chartColors.tooltip.borderColor}`,
                                backgroundColor: chartColors.tooltip.backgroundColor,
                                color: chartColors.tooltip.textColor
                              }} 
                            />
                            <Legend wrapperStyle={{ color: chartColors.text }} />
                            <Line type="monotone" dataKey="grossPay" stroke="#3b82f6" name="Gross Pay ($)" strokeWidth={2} />
                            <Line type="monotone" dataKey="netPay" stroke="#10b981" name="Net Pay ($)" strokeWidth={2} />
                          </LineChart>
                        </ResponsiveContainer>
                      </Card>
                    </Col>
                  </Row>
                  <Row style={{ marginTop: 24 }}>
                    <Col span={24}>
                      <Card title="Payroll Details" className="table-card">
                        <Table columns={payrollTableColumns} dataSource={attendanceSummaryData} rowKey="employee" pagination={false} size="middle" />
                      </Card>
                    </Col>
                  </Row>
                </div>
              </TabPane>

              {/* Financial Reports */}
              <TabPane tab={<span><DollarOutlined /> Finance</span>} key="5">
                <div className="tab-content">
                  <Row gutter={[24, 24]}>
                    <Col xs={24} lg={16}>
                      <Card title="Revenue vs Expenses" className="chart-card">
                        <ResponsiveContainer width="100%" height={320}>
                          <AreaChart data={financialData}>
                            <CartesianGrid strokeDasharray="3 3" stroke={chartColors.grid} />
                            <XAxis dataKey="month" stroke={chartColors.text} />
                            <YAxis stroke={chartColors.text} />
                            <RechartsTooltip 
                              contentStyle={{ 
                                borderRadius: 8, 
                                border: `1px solid ${chartColors.tooltip.borderColor}`,
                                backgroundColor: chartColors.tooltip.backgroundColor,
                                color: chartColors.tooltip.textColor
                              }} 
                            />
                            <Legend wrapperStyle={{ color: chartColors.text }} />
                            <Area type="monotone" dataKey="revenue" stroke="#3b82f6" fill="#3b82f620" name="Revenue" />
                            <Area type="monotone" dataKey="expenses" stroke="#ef4444" fill="#ef444420" name="Expenses" />
                            <Line type="monotone" dataKey="profit" stroke="#10b981" name="Profit" strokeWidth={2} />
                          </AreaChart>
                        </ResponsiveContainer>
                      </Card>
                    </Col>
                    <Col xs={24} lg={8}>
                      <Card title="Financial Health" className="health-card">
                        <div className="health-stats">
                          <div className="profit-margin">34.8%</div>
                          <Text type="secondary">Average Profit Margin</Text>
                          <Divider />
                          <div className="outstanding-section">
                            <Text strong>Outstanding Balances:</Text>
                            {outstandingBalances.filter(b => b.status !== 'paid').map(balance => (
                              <Alert key={balance.customer} message={balance.customer} description={`${formatCurrency(balance.amount)} due ${balance.dueDate}`} type="warning" showIcon className="outstanding-alert" />
                            ))}
                          </div>
                        </div>
                      </Card>
                    </Col>
                  </Row>
                  <Row style={{ marginTop: 24 }}>
                    <Col span={24}>
                      <Card title="Outstanding Balances" className="table-card">
                        <Table 
                          columns={[
                            { title: 'Customer', dataIndex: 'customer', key: 'customer' },
                            { title: 'Amount Due', dataIndex: 'amount', key: 'amount', render: (v) => formatCurrency(v) },
                            { title: 'Due Date', dataIndex: 'dueDate', key: 'dueDate', render: (v) => v || '-' },
                            { title: 'Status', dataIndex: 'status', key: 'status', render: (s) => <StatusBadge status={s} /> },
                          ]}
                          dataSource={outstandingBalances}
                          rowKey="customer"
                          pagination={false}
                          size="middle"
                        />
                      </Card>
                    </Col>
                  </Row>
                </div>
              </TabPane>

              {/* Customer Analytics */}
              <TabPane tab={<span><UserOutlined /> Customers</span>} key="6">
                <div className="tab-content">
                  <Row gutter={[24, 24]}>
                    <Col xs={24} lg={12}>
                      <Card title="Top Customers by Spending" className="chart-card">
                        <ResponsiveContainer width="100%" height={300}>
                          <BarChart data={customerAnalyticsData} layout="vertical">
                            <CartesianGrid strokeDasharray="3 3" stroke={chartColors.grid} />
                            <XAxis type="number" stroke={chartColors.text} />
                            <YAxis type="category" dataKey="customer" width={100} stroke={chartColors.text} />
                            <RechartsTooltip 
                              contentStyle={{ 
                                borderRadius: 8, 
                                border: `1px solid ${chartColors.tooltip.borderColor}`,
                                backgroundColor: chartColors.tooltip.backgroundColor,
                                color: chartColors.tooltip.textColor
                              }} 
                            />
                            <Bar dataKey="totalSpent" fill="#3b82f6" name="Total Spent ($)" radius={[0, 4, 4, 0]} />
                          </BarChart>
                        </ResponsiveContainer>
                      </Card>
                    </Col>
                    <Col xs={24} lg={12}>
                      <Card title="Satisfaction Metrics" className="chart-card">
                        <ResponsiveContainer width="100%" height={300}>
                          <RadarChart data={[
                            { metric: 'Food Quality', value: 4.8 },
                            { metric: 'Service', value: 4.6 },
                            { metric: 'Punctuality', value: 4.9 },
                            { metric: 'Value', value: 4.5 },
                            { metric: 'Presentation', value: 4.7 },
                          ]}>
                            <PolarGrid stroke={chartColors.grid} />
                            <PolarAngleAxis dataKey="metric" tick={{ fill: chartColors.text }} />
                            <PolarRadiusAxis domain={[0, 5]} tick={{ fill: chartColors.text }} />
                            <Radar name="Score" dataKey="value" stroke="#3b82f6" fill="#3b82f650" />
                            <RechartsTooltip 
                              contentStyle={{ 
                                borderRadius: 8, 
                                border: `1px solid ${chartColors.tooltip.borderColor}`,
                                backgroundColor: chartColors.tooltip.backgroundColor,
                                color: chartColors.tooltip.textColor
                              }} 
                            />
                          </RadarChart>
                        </ResponsiveContainer>
                      </Card>
                    </Col>
                  </Row>
                  <Row style={{ marginTop: 24 }}>
                    <Col span={24}>
                      <Card title="Customer Analytics" className="table-card">
                        <Table columns={customerTableColumns} dataSource={customerAnalyticsData} rowKey="customer" pagination={false} size="middle" />
                      </Card>
                    </Col>
                  </Row>
                </div>
              </TabPane>

              {/* Event Reports */}
              <TabPane tab={<span><GiftOutlined /> Events</span>} key="7">
                <div className="tab-content">
                  <Row gutter={[24, 24]}>
                    <Col xs={24} lg={12}>
                      <Card title="Event Profitability Analysis" className="chart-card">
                        <ResponsiveContainer width="100%" height={320}>
                          <BarChart data={eventProfitabilityData}>
                            <CartesianGrid strokeDasharray="3 3" stroke={chartColors.grid} />
                            <XAxis dataKey="event" angle={-45} textAnchor="end" height={80} stroke={chartColors.text} />
                            <YAxis stroke={chartColors.text} />
                            <RechartsTooltip 
                              contentStyle={{ 
                                borderRadius: 8, 
                                border: `1px solid ${chartColors.tooltip.borderColor}`,
                                backgroundColor: chartColors.tooltip.backgroundColor,
                                color: chartColors.tooltip.textColor
                              }} 
                            />
                            <Legend wrapperStyle={{ color: chartColors.text }} />
                            <Bar dataKey="revenue" fill="#3b82f6" name="Revenue ($)" radius={[4, 4, 0, 0]} />
                            <Bar dataKey="profit" fill="#10b981" name="Profit ($)" radius={[4, 4, 0, 0]} />
                          </BarChart>
                        </ResponsiveContainer>
                      </Card>
                    </Col>
                    <Col xs={24} lg={12}>
                      <Card title="Event Metrics" className="stats-card">
                        <div className="event-metrics">
                          <div className="completion-rate">96.5%</div>
                          <Text type="secondary">Completion Rate</Text>
                          <Divider />
                          <Row gutter={16}>
                            <Col span={8}><Statistic title="Completed" value={312} /></Col>
                            <Col span={8}><Statistic title="In Progress" value={8} /></Col>
                            <Col span={8}><Statistic title="Cancelled" value={12} /></Col>
                          </Row>
                        </div>
                      </Card>
                    </Col>
                  </Row>
                  <Row style={{ marginTop: 24 }}>
                    <Col span={24}>
                      <Card title="Event Profitability Report" className="table-card">
                        <Table columns={eventProfitabilityTableColumns} dataSource={eventProfitabilityData} rowKey="event" pagination={false} size="middle" />
                      </Card>
                    </Col>
                  </Row>
                </div>
              </TabPane>

              {/* Menu Analytics */}
              <TabPane tab={<span><MenuOutlined /> Menu</span>} key="8">
                <div className="tab-content">
                  <Row gutter={[24, 24]}>
                    <Col xs={24} lg={16}>
                      <Card title="Menu Item Performance" className="chart-card">
                        <ResponsiveContainer width="100%" height={320}>
                          <BarChart data={menuPerformanceData}>
                            <CartesianGrid strokeDasharray="3 3" stroke={chartColors.grid} />
                            <XAxis dataKey="item" angle={-45} textAnchor="end" height={80} stroke={chartColors.text} />
                            <YAxis yAxisId="left" stroke={chartColors.text} />
                            <YAxis yAxisId="right" orientation="right" stroke={chartColors.text} />
                            <RechartsTooltip 
                              contentStyle={{ 
                                borderRadius: 8, 
                                border: `1px solid ${chartColors.tooltip.borderColor}`,
                                backgroundColor: chartColors.tooltip.backgroundColor,
                                color: chartColors.tooltip.textColor
                              }} 
                            />
                            <Legend wrapperStyle={{ color: chartColors.text }} />
                            <Bar yAxisId="left" dataKey="revenue" fill="#3b82f6" name="Revenue ($)" radius={[4, 4, 0, 0]} />
                            <Line yAxisId="right" type="monotone" dataKey="popularity" stroke="#f59e0b" name="Popularity" strokeWidth={2} />
                          </BarChart>
                        </ResponsiveContainer>
                      </Card>
                    </Col>
                    <Col xs={24} lg={8}>
                      <Card title="Top 5 Profitable Items" className="list-card">
                        <List
                          dataSource={[...menuPerformanceData].sort((a, b) => b.profitability - a.profitability)}
                          renderItem={(item, idx) => (
                            <List.Item className="profitable-item">
                              <Space>
                                <div className="rank-badge" style={{ background: COLORS[idx] }}>{idx + 1}</div>
                                <Text strong>{item.item}</Text>
                              </Space>
                              <Tag color="green">{item.profitability}% Margin</Tag>
                            </List.Item>
                          )}
                        />
                      </Card>
                    </Col>
                  </Row>
                </div>
              </TabPane>
            </Tabs>
          </Card>

          {/* Footer */}
          <div className="reports-footer">
            <Space size={24}>
              <Button icon={<FilePdfOutlined />} onClick={() => handleExport('pdf')} type="link">PDF Export</Button>
              <Button icon={<FileExcelOutlined />} onClick={() => handleExport('excel')} type="link">Excel Export</Button>
              <Button icon={<MailOutlined />} onClick={() => handleExport('email')} type="link">Email Report</Button>
              <Button icon={<PrinterOutlined />} onClick={() => window.print()} type="link">Print</Button>
            </Space>
          </div>
        </div>
      </div>
    </ConfigProvider>
  );
};

export default ReportsAnalytics;