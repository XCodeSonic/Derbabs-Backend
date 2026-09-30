// src/components/SystemSettings.jsx
import React, { useState, useEffect } from 'react';
import { Card, Table, Button, Space, Input, Select, Modal, Tabs, Tag, message, Divider, Tooltip, Typography, Row, Col, Alert, Form, Switch, Checkbox, Avatar, Badge, ConfigProvider, theme as antdTheme, TimePicker, InputNumber } from 'antd';
import { SettingOutlined, UserOutlined, LockOutlined, HistoryOutlined, DollarOutlined, TruckOutlined, TeamOutlined, CalculatorOutlined, BoxPlotOutlined, MenuOutlined, CalendarOutlined, BellOutlined, CreditCardOutlined, FileTextOutlined, SafetyOutlined, PlusOutlined, EditOutlined, DeleteOutlined, ReloadOutlined, SaveOutlined, ExportOutlined, CheckCircleOutlined, CloseCircleOutlined, ToolOutlined } from '@ant-design/icons';
import { useUsers, useCreateUser, useUpdateUser, useDeleteUser, useRoles, useAuditLogs, useSettings, useUpdateSettings } from '../../../hooks/useSettingsQueries';
import dayjs from 'dayjs';
import '../styles/Settings.css';

const { Title, Text } = Typography;
const { Option } = Select;
const { TabPane } = Tabs;
const { TextArea } = Input;

const SystemSettings = () => {
    const [activeMainTab, setActiveMainTab] = useState('users');
    const [isDarkMode, setIsDarkMode] = useState(false);
    const [userModalVisible, setUserModalVisible] = useState(false);
    const [editingUser, setEditingUser] = useState(null);
    const [userForm] = Form.useForm();

    const { data: usersData, isLoading: usersLoading, refetch: refetchUsers } = useUsers();
    const { data: roles = [] } = useRoles();
    const { data: auditLogs = [], refetch: refetchAudit } = useAuditLogs();
    const { data: settings, isLoading: settingsLoading, refetch: refetchSettings } = useSettings();
    const updateSettings = useUpdateSettings();
    const createUser = useCreateUser();
    const updateUser = useUpdateUser();
    const deleteUser = useDeleteUser();

    const users = usersData?.data || [];
    const [pricingRules, setPricingRules] = useState({ service_fee_percentage: 10, delivery_fee_per_km: 50, min_delivery_fee: 200, labor_cost_per_hour: 250 });
    const [deliverySettings, setDeliverySettings] = useState({ delivery_radius: 50, fee_per_km: 50, min_charge: 200, free_delivery_min: 5000, pickup_allowed: true });
    const [employeeSettings, setEmployeeSettings] = useState({ grace_period_minutes: 10, late_deduction_per_minute: 5, sick_leave_days_per_year: 15, vacation_leave_days_per_year: 15 });
    const [payrollSettings, setPayrollSettings] = useState({ cutoff_start: 1, cutoff_end: 15, daily_rate: 537, hourly_rate: 67.125, overtime_rate: 1.5 });
    const [inventorySettings, setInventorySettings] = useState({ low_stock_threshold: 10, reorder_level: 20, ingredient_buffer_percentage: 5, yield_percentage: 95 });
    const [paymentSettings, setPaymentSettings] = useState({ downpayment_percentage: 30, credit_limit: 50000, methods: [{ id: 'cash', name: 'Cash', enabled: true, fee: 0 }, { id: 'gcash', name: 'GCash', enabled: true, fee: 0 }] });
    const [securitySettings, setSecuritySettings] = useState({ password_min_length: 8, session_timeout_minutes: 30, login_attempts_limit: 5, two_factor_auth: false });

    useEffect(() => {
        const savedTheme = localStorage.getItem('theme');
        setIsDarkMode(savedTheme === 'dark' || (savedTheme !== 'light' && document.body.classList.contains('dark-mode')));
        const observer = new MutationObserver(() => setIsDarkMode(document.body.classList.contains('dark-mode')));
        observer.observe(document.body, { attributes: true, attributeFilter: ['class'] });
        return () => observer.disconnect();
    }, []);

    useEffect(() => {
        if (settings) {
            setPricingRules(prev => ({ ...prev, ...settings.pricing }));
            setDeliverySettings(prev => ({ ...prev, ...settings.delivery }));
            setEmployeeSettings(prev => ({ ...prev, ...settings.employee }));
            setPayrollSettings(prev => ({ ...prev, ...settings.payroll }));
            setInventorySettings(prev => ({ ...prev, ...settings.inventory }));
            setPaymentSettings(prev => ({ ...prev, ...settings.payment }));
            setSecuritySettings(prev => ({ ...prev, ...settings.security }));
        }
    }, [settings]);

    const handleSaveSettings = async (section, data) => {
        try {
            await updateSettings.mutateAsync({ section, data });
            message.success(`${section} settings saved successfully`);
            refetchSettings();
        } catch (error) {
            message.error(error.response?.data?.message || 'Failed to save settings');
        }
    };

    const handleAddUser = () => { setEditingUser(null); userForm.resetFields(); setUserModalVisible(true); };
    const handleEditUser = (user) => { setEditingUser(user); userForm.setFieldsValue(user); setUserModalVisible(true); };
    const handleSaveUser = async (values) => {
        try {
            if (editingUser) await updateUser.mutateAsync({ id: editingUser.id, data: values });
            else await createUser.mutateAsync(values);
            message.success(`User ${editingUser ? 'updated' : 'created'} successfully`);
            setUserModalVisible(false);
            refetchUsers();
        } catch (error) { message.error(error.response?.data?.message || 'Failed to save user'); }
    };
    const handleDeleteUser = (user) => { Modal.confirm({ title: 'Delete User', content: `Delete ${user.name}?`, onOk: async () => { await deleteUser.mutateAsync(user.id); refetchUsers(); } }); };

    const userColumns = [
        { title: 'USER', key: 'user', render: (_, r) => (<div><Avatar style={{ backgroundColor: '#1a7ab5' }}>{r.name?.charAt(0)}</Avatar><div><div>{r.name}</div><div className="settings-user-email">{r.email}</div></div></div>) },
        { title: 'ROLE', dataIndex: 'role', key: 'role', render: (role) => <Tag color="blue">{role}</Tag> },
        { title: 'STATUS', dataIndex: 'is_active', key: 'status', render: (status) => <Badge status={status ? 'success' : 'error'} text={status ? 'Active' : 'Inactive'} /> },
        { title: 'LAST LOGIN', dataIndex: 'last_login', key: 'last_login', render: (v) => v ? dayjs(v).format('MMM DD, YYYY h:mm A') : 'Never' },
        { title: 'ACTIONS', key: 'actions', render: (_, r) => (<Space><Tooltip title="Edit"><Button type="text" icon={<EditOutlined />} onClick={() => handleEditUser(r)} /></Tooltip><Tooltip title="Delete"><Button type="text" icon={<DeleteOutlined />} onClick={() => handleDeleteUser(r)} /></Tooltip></Space>) },
    ];

    const auditColumns = [
        { title: 'DATE', dataIndex: 'created_at', key: 'date', width: 180, render: (v) => dayjs(v).format('YYYY-MM-DD HH:mm:ss') },
        { title: 'USER', dataIndex: 'user_name', key: 'user', width: 150 },
        { title: 'MODULE', dataIndex: 'module', key: 'module', width: 120, render: (module) => <Tag color="cyan">{module}</Tag> },
        { title: 'ACTION', dataIndex: 'action', key: 'action' },
        { title: 'IP', dataIndex: 'ip_address', key: 'ip', width: 130 },
    ];

    const containerClass = `settings-container ${isDarkMode ? 'settings-dark-mode' : ''}`;
    const headerClass = `settings-header ${isDarkMode ? 'settings-header-dark' : ''}`;
    const mainCardClass = `settings-main-card ${isDarkMode ? 'settings-main-card-dark' : ''}`;
    const sidebarClass = `settings-sidebar ${isDarkMode ? 'settings-sidebar-dark' : ''}`;
    const contentClass = `settings-content ${isDarkMode ? 'settings-content-dark' : ''}`;
    const tableClass = `settings-table ${isDarkMode ? 'settings-table-dark' : ''}`;

    const sidebarMenuItems = [
        { key: 'users', icon: <UserOutlined />, label: 'User & Roles' },
        { key: 'audit', icon: <HistoryOutlined />, label: 'Audit Logs' },
        { key: 'pricing', icon: <DollarOutlined />, label: 'Pricing Rules' },
        { key: 'delivery', icon: <TruckOutlined />, label: 'Delivery' },
        { key: 'employee', icon: <TeamOutlined />, label: 'Employee' },
        { key: 'payroll', icon: <CalculatorOutlined />, label: 'Payroll' },
        { key: 'inventory', icon: <BoxPlotOutlined />, label: 'Inventory' },
        { key: 'menu', icon: <MenuOutlined />, label: 'Menu' },
        { key: 'events', icon: <CalendarOutlined />, label: 'Events' },
        { key: 'payment', icon: <CreditCardOutlined />, label: 'Payment' },
        { key: 'notifications', icon: <BellOutlined />, label: 'Notifications' },
        { key: 'documents', icon: <FileTextOutlined />, label: 'Documents' },
        { key: 'security', icon: <SafetyOutlined />, label: 'Security' },
    ];

    return (
        <ConfigProvider theme={{ algorithm: isDarkMode ? antdTheme.darkAlgorithm : antdTheme.defaultAlgorithm }}>
            <div className={containerClass}>
                <div className={headerClass}>
                    <div className="settings-header-left"><div className="settings-logo-icon"><SettingOutlined /></div><div><h1>System Settings</h1><span>CONFIGURE & MANAGE SYSTEM</span></div></div>
                    <div className="settings-header-right"><Button icon={<ReloadOutlined />} onClick={() => { refetchUsers(); refetchAudit(); refetchSettings(); }}>Refresh</Button><Button type="primary" icon={<SaveOutlined />}>Save All</Button></div>
                </div>

                <div className="settings-layout">
                    <div className={sidebarClass}>
                        <div className="settings-sidebar-header"><SettingOutlined /> Settings Menu</div>
                        <div className="settings-sidebar-menu">{sidebarMenuItems.map(item => (<div key={item.key} className={`settings-sidebar-item ${activeMainTab === item.key ? 'active' : ''}`} onClick={() => setActiveMainTab(item.key)}><div className="settings-sidebar-icon">{item.icon}</div><div className="settings-sidebar-label">{item.label}</div></div>))}</div>
                    </div>

                    <div className={contentClass}>
                        {activeMainTab === 'users' && (<Card className={mainCardClass}><div className="settings-section-header"><div className="settings-section-title"><UserOutlined /> User Management</div><Button type="primary" icon={<PlusOutlined />} onClick={handleAddUser}>Add User</Button></div><Table columns={userColumns} dataSource={users} rowKey="id" className={tableClass} loading={usersLoading} /></Card>)}

                        {activeMainTab === 'audit' && (<Card className={mainCardClass}><div className="settings-section-header"><div className="settings-section-title"><HistoryOutlined /> Audit Logs</div><Space><Button icon={<ExportOutlined />}>Export Logs</Button></Space></div><Table columns={auditColumns} dataSource={auditLogs} rowKey="audit_id" className={tableClass} /></Card>)}

                        {activeMainTab === 'pricing' && (<Card className={mainCardClass}><div className="settings-section-header"><div className="settings-section-title"><DollarOutlined /> Pricing Rules</div></div><Row gutter={24}><Col span={8}><div className="settings-pricing-card"><div className="settings-pricing-label">Service Fee %</div><InputNumber min={0} max={100} value={pricingRules.service_fee_percentage} onChange={(v) => setPricingRules({...pricingRules, service_fee_percentage: v})} style={{ width: '100%' }} suffix="%" /></div></Col><Col span={8}><div className="settings-pricing-card"><div className="settings-pricing-label">Delivery Fee per KM</div><InputNumber min={0} value={pricingRules.delivery_fee_per_km} onChange={(v) => setPricingRules({...pricingRules, delivery_fee_per_km: v})} style={{ width: '100%' }} prefix="₱" /></div></Col><Col span={8}><div className="settings-pricing-card"><div className="settings-pricing-label">Min Delivery Fee</div><InputNumber min={0} value={pricingRules.min_delivery_fee} onChange={(v) => setPricingRules({...pricingRules, min_delivery_fee: v})} style={{ width: '100%' }} prefix="₱" /></div></Col></Row><div className="settings-section-actions"><Button type="primary" icon={<SaveOutlined />} onClick={() => handleSaveSettings('pricing', pricingRules)}>Save Pricing Rules</Button></div></Card>)}

                        {activeMainTab === 'delivery' && (<Card className={mainCardClass}><div className="settings-section-header"><div className="settings-section-title"><TruckOutlined /> Delivery Settings</div></div><Row gutter={24}><Col span={8}><div className="settings-pricing-card"><div className="settings-pricing-label">Delivery Radius (km)</div><InputNumber min={0} value={deliverySettings.delivery_radius} onChange={(v) => setDeliverySettings({...deliverySettings, delivery_radius: v})} suffix="km" style={{ width: '100%' }} /></div></Col><Col span={8}><div className="settings-pricing-card"><div className="settings-pricing-label">Fee per KM</div><InputNumber min={0} value={deliverySettings.fee_per_km} onChange={(v) => setDeliverySettings({...deliverySettings, fee_per_km: v})} prefix="₱" style={{ width: '100%' }} /></div></Col><Col span={8}><div className="settings-pricing-card"><div className="settings-pricing-label">Free Delivery Minimum</div><InputNumber min={0} value={deliverySettings.free_delivery_min} onChange={(v) => setDeliverySettings({...deliverySettings, free_delivery_min: v})} prefix="₱" style={{ width: '100%' }} /></div></Col><Col span={8}><div className="settings-pricing-card"><div className="settings-pricing-label">Pickup Allowed</div><Switch checked={deliverySettings.pickup_allowed} onChange={(v) => setDeliverySettings({...deliverySettings, pickup_allowed: v})} /></div></Col></Row><div className="settings-section-actions"><Button type="primary" icon={<SaveOutlined />} onClick={() => handleSaveSettings('delivery', deliverySettings)}>Save Delivery Settings</Button></div></Card>)}

                        {activeMainTab === 'employee' && (<Card className={mainCardClass}><div className="settings-section-header"><div className="settings-section-title"><TeamOutlined /> Employee Settings</div></div><Row gutter={24}><Col span={8}><div className="settings-pricing-card"><div className="settings-pricing-label">Grace Period (min)</div><InputNumber min={0} value={employeeSettings.grace_period_minutes} onChange={(v) => setEmployeeSettings({...employeeSettings, grace_period_minutes: v})} suffix="min" style={{ width: '100%' }} /></div></Col><Col span={8}><div className="settings-pricing-card"><div className="settings-pricing-label">Late Deduction per Min</div><InputNumber min={0} value={employeeSettings.late_deduction_per_minute} onChange={(v) => setEmployeeSettings({...employeeSettings, late_deduction_per_minute: v})} prefix="₱" style={{ width: '100%' }} /></div></Col><Col span={8}><div className="settings-pricing-card"><div className="settings-pricing-label">Sick Leave Days</div><InputNumber min={0} value={employeeSettings.sick_leave_days_per_year} onChange={(v) => setEmployeeSettings({...employeeSettings, sick_leave_days_per_year: v})} suffix="days" style={{ width: '100%' }} /></div></Col><Col span={8}><div className="settings-pricing-card"><div className="settings-pricing-label">Vacation Leave Days</div><InputNumber min={0} value={employeeSettings.vacation_leave_days_per_year} onChange={(v) => setEmployeeSettings({...employeeSettings, vacation_leave_days_per_year: v})} suffix="days" style={{ width: '100%' }} /></div></Col></Row><div className="settings-section-actions"><Button type="primary" icon={<SaveOutlined />} onClick={() => handleSaveSettings('employee', employeeSettings)}>Save Employee Settings</Button></div></Card>)}

                        {activeMainTab === 'payroll' && (<Card className={mainCardClass}><div className="settings-section-header"><div className="settings-section-title"><CalculatorOutlined /> Payroll Settings</div></div><Row gutter={24}><Col span={8}><div className="settings-pricing-card"><div className="settings-pricing-label">Daily Rate</div><InputNumber min={0} value={payrollSettings.daily_rate} onChange={(v) => setPayrollSettings({...payrollSettings, daily_rate: v})} prefix="₱" style={{ width: '100%' }} /></div></Col><Col span={8}><div className="settings-pricing-card"><div className="settings-pricing-label">Hourly Rate</div><InputNumber min={0} value={payrollSettings.hourly_rate} onChange={(v) => setPayrollSettings({...payrollSettings, hourly_rate: v})} prefix="₱" style={{ width: '100%' }} /></div></Col><Col span={8}><div className="settings-pricing-card"><div className="settings-pricing-label">Overtime Rate</div><InputNumber min={1} step={0.1} value={payrollSettings.overtime_rate} onChange={(v) => setPayrollSettings({...payrollSettings, overtime_rate: v})} suffix="x" style={{ width: '100%' }} /></div></Col></Row><div className="settings-section-actions"><Button type="primary" icon={<SaveOutlined />} onClick={() => handleSaveSettings('payroll', payrollSettings)}>Save Payroll Settings</Button></div></Card>)}

                        {activeMainTab === 'security' && (<Card className={mainCardClass}><div className="settings-section-header"><div className="settings-section-title"><SafetyOutlined /> Security Settings</div></div><Row gutter={24}><Col span={8}><div className="settings-pricing-card"><div className="settings-pricing-label">Min Password Length</div><InputNumber min={6} max={20} value={securitySettings.password_min_length} onChange={(v) => setSecuritySettings({...securitySettings, password_min_length: v})} style={{ width: '100%' }} /></div></Col><Col span={8}><div className="settings-pricing-card"><div className="settings-pricing-label">Session Timeout (min)</div><InputNumber min={5} value={securitySettings.session_timeout_minutes} onChange={(v) => setSecuritySettings({...securitySettings, session_timeout_minutes: v})} style={{ width: '100%' }} /></div></Col><Col span={8}><div className="settings-pricing-card"><div className="settings-pricing-label">Login Attempts Limit</div><InputNumber min={1} max={10} value={securitySettings.login_attempts_limit} onChange={(v) => setSecuritySettings({...securitySettings, login_attempts_limit: v})} style={{ width: '100%' }} /></div></Col><Col span={24}><div className="settings-pricing-card"><Checkbox checked={securitySettings.two_factor_auth} onChange={(e) => setSecuritySettings({...securitySettings, two_factor_auth: e.target.checked})}>Enable Two-Factor Authentication (2FA)</Checkbox></div></Col></Row><div className="settings-section-actions"><Button type="primary" icon={<SaveOutlined />} onClick={() => handleSaveSettings('security', securitySettings)}>Save Security Settings</Button></div></Card>)}
                    </div>
                </div>

                <Modal title={editingUser ? "Edit User" : "Add New User"} open={userModalVisible} onCancel={() => setUserModalVisible(false)} width={500} footer={null}>
                    <Form form={userForm} layout="vertical" onFinish={handleSaveUser}>
                        <Form.Item name="name" label="Full Name" rules={[{ required: true }]}><Input placeholder="Enter full name" /></Form.Item>
                        <Form.Item name="email" label="Email" rules={[{ required: true, type: 'email' }]}><Input placeholder="Enter email" /></Form.Item>
                        <Form.Item name="role" label="Role" rules={[{ required: true }]}><Select placeholder="Select role">{roles.map(r => (<Option key={r.role_id} value={r.name}>{r.name}</Option>))}</Select></Form.Item>
                        <Form.Item name="is_active" label="Status" valuePropName="checked"><Switch checkedChildren="Active" unCheckedChildren="Inactive" /></Form.Item>
                        <Form.Item style={{ textAlign: 'right' }}><Space><Button onClick={() => setUserModalVisible(false)}>Cancel</Button><Button type="primary" htmlType="submit">Save</Button></Space></Form.Item>
                    </Form>
                </Modal>
            </div>
        </ConfigProvider>
    );
};

export default SystemSettings;