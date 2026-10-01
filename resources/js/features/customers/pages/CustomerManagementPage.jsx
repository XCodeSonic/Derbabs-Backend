// src/components/CustomerManagement.jsx
import React, { useState, useEffect, useRef } from 'react';
import {
    Card, Table, Button, Space, Input, Select, Modal, Tabs, Tag, message, Divider, Tooltip, Typography, Row, Col,
    Descriptions, Alert, DatePicker, Popconfirm, Badge, Empty, Form, Dropdown, Progress, Statistic, InputNumber,
    Avatar, Rate, List, Drawer, ConfigProvider, theme as antdTheme, Skeleton, Switch
} from 'antd';
import {
    UserOutlined, PlusOutlined, EditOutlined, DeleteOutlined, EyeOutlined, SearchOutlined, ReloadOutlined,
    PrinterOutlined, ExportOutlined, FilterOutlined, CalendarOutlined, StarOutlined, StarFilled, MessageOutlined,
    PhoneOutlined, MailOutlined, EnvironmentOutlined, ClockCircleOutlined, CheckCircleOutlined, CloseCircleOutlined,
    SmileOutlined, FrownOutlined, MehOutlined, TeamOutlined, WalletOutlined, DashboardOutlined, FileTextOutlined, 
    SendOutlined, MoreOutlined, ArrowLeftOutlined, PaperClipOutlined, CameraOutlined, AudioOutlined,
    CheckOutlined, CheckCircleFilled, ClockCircleFilled
} from '@ant-design/icons';
import { useCustomers, useCreateCustomer, useUpdateCustomer, useDeleteCustomer, useCustomerBookings,
     useCustomerFeedback, useCustomerReviews, useCustomerMessages, useSendCustomerMessage, 
     useRespondToFeedback, useApproveReview, useHideReview, useFeatureReview } from '../../../hooks/useCustomerQueries';
import { format, formatDistanceToNow } from 'date-fns';
import '../styles/CustomerManagement.css';

const { Title, Text, Paragraph } = Typography;
const { Option } = Select;
const { TabPane } = Tabs;
const { TextArea } = Input;

const CustomerManagement = () => {
    // ==================== STATE MANAGEMENT ====================
    const [selectedCustomer, setSelectedCustomer] = useState(null);
    const [searchText, setSearchText] = useState('');
    const [filterStatus, setFilterStatus] = useState('all');
    const [filterTier, setFilterTier] = useState('all');
    const [currentPage, setCurrentPage] = useState(1);
    const [pageSize, setPageSize] = useState(10);
    const [activeMainTab, setActiveMainTab] = useState('directory');
    const [isDarkMode, setIsDarkMode] = useState(false);
    const [isMessageViewOpen, setIsMessageViewOpen] = useState(false);
    const [selectedChatCustomer, setSelectedChatCustomer] = useState(null);
    const [chatMessages, setChatMessages] = useState([]);
    const [newMessage, setNewMessage] = useState('');
    const [isTyping, setIsTyping] = useState(false);
    const [messageSearchTerm, setMessageSearchTerm] = useState('');
    const messagesEndRef = useRef(null);
    const fileInputRef = useRef(null);
    
    const [customerModalVisible, setCustomerModalVisible] = useState(false);
    const [customerDetailsVisible, setCustomerDetailsVisible] = useState(false);
    const [feedbackModalVisible, setFeedbackModalVisible] = useState(false);
    const [messageModalVisible, setMessageModalVisible] = useState(false);
    const [selectedItem, setSelectedItem] = useState(null);
    
    const [customerForm] = Form.useForm();
    const [feedbackForm] = Form.useForm();
    const [messageForm] = Form.useForm();

    // ==================== REACT QUERY HOOKS ====================
    const { data: customersData, isLoading: customersLoading, refetch: refetchCustomers } = useCustomers({
        page: currentPage,
        per_page: pageSize,
        search: searchText,
        is_active: filterStatus !== 'all' ? (filterStatus === 'active') : undefined,
        tier: filterTier !== 'all' ? filterTier : undefined,
    });

    const createCustomer = useCreateCustomer();
    const updateCustomer = useUpdateCustomer();
    const deleteCustomer = useDeleteCustomer();
    const sendMessage = useSendCustomerMessage();
    const respondToFeedback = useRespondToFeedback();
    const approveReview = useApproveReview();
    const hideReview = useHideReview();
    const featureReview = useFeatureReview();

    const { data: customerBookings = [], refetch: refetchBookings } = useCustomerBookings(selectedCustomer?.customer_id);
    const { data: feedbacks = [], refetch: refetchFeedbacks } = useCustomerFeedback();
    const { data: reviews = [], refetch: refetchReviews } = useCustomerReviews();
    const { data: messages = [], refetch: refetchMessages } = useCustomerMessages();

    const customers = customersData?.data || [];
    const totalCustomers = customersData?.total || 0;
    const activeCustomers = customers.filter(c => c.is_active).length;
    const totalRevenue = customers.reduce((sum, c) => sum + (c.total_spent || 0), 0);
    const avgRating = feedbacks.length > 0 ? (feedbacks.reduce((sum, f) => sum + (f.overall_rating || 0), 0) / feedbacks.length).toFixed(1) : '0';
    const positiveSentiment = feedbacks.filter(f => f.sentiment === 'positive').length;
    const pendingReviews = reviews.filter(r => !r.is_approved).length;

    // ==================== CHAT FUNCTIONS ====================
    const openMessageView = (customer) => {
        setSelectedChatCustomer(customer);
        const customerMessages = messages.filter(m => 
            m.customer_id === customer.customer_id || 
            m.sender_id === customer.customer_id
        );
        setChatMessages(customerMessages.length > 0 ? customerMessages : [
            {
                id: 'welcome',
                message: `👋 Welcome! Start a conversation with ${customer.person?.first_name || 'Customer'}`,
                isSystem: true,
                created_at: new Date().toISOString()
            }
        ]);
        setIsMessageViewOpen(true);
        setIsTyping(false);
    };

    const closeMessageView = () => {
        setIsMessageViewOpen(false);
        setSelectedChatCustomer(null);
        setChatMessages([]);
        setNewMessage('');
    };

    const handleSendMessage = async () => {
        if (!newMessage.trim() || !selectedChatCustomer) return;
        
        const messageContent = newMessage.trim();
        setNewMessage('');
        
        // Optimistically add message
        const tempMessage = {
            id: `temp-${Date.now()}`,
            message: messageContent,
            sender_name: 'You',
            sender_type: 'admin',
            isAdmin: true,
            created_at: new Date().toISOString(),
            read_at: null,
        };
        setChatMessages(prev => [...prev, tempMessage]);
        
        // Simulate typing indicator
        setIsTyping(true);
        
        try {
            // Send actual message
            await sendMessage.mutateAsync({
                customer_id: selectedChatCustomer.customer_id,
                message: messageContent,
            });
            
            // Replace temp message with real one
            const realMessage = {
                ...tempMessage,
                id: `msg-${Date.now()}`,
            };
            setChatMessages(prev => prev.map(m => 
                m.id === tempMessage.id ? realMessage : m
            ));
            
            // Simulate auto-reply (optional)
            setTimeout(() => {
                setIsTyping(false);
                const autoReply = {
                    id: `reply-${Date.now()}`,
                    message: `Thanks for your message! We'll get back to you shortly.`,
                    sender_name: selectedChatCustomer.person?.first_name || 'Customer',
                    sender_type: 'customer',
                    isAdmin: false,
                    created_at: new Date().toISOString(),
                    read_at: new Date().toISOString(),
                };
                setChatMessages(prev => [...prev, autoReply]);
            }, 1500);
            
            refetchMessages();
        } catch (error) {
            message.error('Failed to send message');
            // Remove temp message on error
            setChatMessages(prev => prev.filter(m => m.id !== tempMessage.id));
        }
    };

    const handleKeyPress = (e) => {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            handleSendMessage();
        }
    };

    const handleFileUpload = () => {
        fileInputRef.current?.click();
    };

    // Scroll to bottom on new messages
    useEffect(() => {
        messagesEndRef.current?.scrollIntoView({ behavior: 'smooth' });
    }, [chatMessages]);

    // ==================== THEME DETECTION ====================
    useEffect(() => {
        const savedTheme = localStorage.getItem('theme');
        setIsDarkMode(savedTheme === 'dark' || (savedTheme !== 'light' && document.body.classList.contains('dark-mode')));
        
        const observer = new MutationObserver(() => {
            setIsDarkMode(document.body.classList.contains('dark-mode'));
        });
        observer.observe(document.body, { attributes: true, attributeFilter: ['class'] });
        return () => observer.disconnect();
    }, []);

    // ==================== CUSTOMER FUNCTIONS ====================
    const handleAddCustomer = () => {
        customerForm.resetFields();
        setSelectedItem(null);
        setCustomerModalVisible(true);
    };

    const handleEditCustomer = (record) => {
        setSelectedItem(record);
        customerForm.setFieldsValue({
            first_name: record.person?.first_name,
            last_name: record.person?.last_name,
            email: record.person?.email,
            phone: record.person?.phone,
            address: record.person?.address_line_1,
            tier: record.tier,
            dietary_restrictions: record.dietary_restrictions,
            notes: record.notes,
        });
        setCustomerModalVisible(true);
    };

    const handleViewCustomerDetails = async (record) => {
        setSelectedCustomer(record);
        setCustomerDetailsVisible(true);
        await refetchBookings();
    };

    const handleDeleteCustomer = (record) => {
        Modal.confirm({
            title: 'Delete Customer',
            content: `Are you sure you want to delete ${record.person?.first_name} ${record.person?.last_name}?`,
            okText: 'Delete',
            okType: 'danger',
            onOk: async () => {
                try {
                    await deleteCustomer.mutateAsync(record.customer_id);
                    message.success('Customer deleted successfully');
                    refetchCustomers();
                } catch (error) {
                    message.error(error.response?.data?.message || 'Failed to delete customer');
                }
            }
        });
    };

    const handleSaveCustomer = async (values) => {
        try {
            const customerData = {
                first_name: values.first_name,
                last_name: values.last_name,
                email: values.email,
                phone: values.phone,
                address_line_1: values.address,
                tier: values.tier,
                dietary_restrictions: values.dietary_restrictions,
                notes: values.notes,
            };
            
            if (selectedItem) {
                await updateCustomer.mutateAsync({ id: selectedItem.customer_id, data: customerData });
                message.success('Customer updated successfully');
            } else {
                await createCustomer.mutateAsync(customerData);
                message.success('Customer created successfully');
            }
            setCustomerModalVisible(false);
            customerForm.resetFields();
            refetchCustomers();
        } catch (error) {
            message.error(error.response?.data?.message || 'Failed to save customer');
        }
    };

    const handleRespondToFeedback = async (values) => {
        try {
            await respondToFeedback.mutateAsync({ id: selectedItem.review_id, response: values.response });
            message.success('Response sent to customer');
            setFeedbackModalVisible(false);
            refetchFeedbacks();
        } catch (error) {
            message.error(error.response?.data?.message || 'Failed to send response');
        }
    };

    const handleApproveReview = async (record) => {
        try {
            await approveReview.mutateAsync(record.review_id);
            message.success('Review approved and published');
            refetchReviews();
        } catch (error) {
            message.error(error.response?.data?.message || 'Failed to approve review');
        }
    };

    const handleHideReview = async (record) => {
        try {
            await hideReview.mutateAsync(record.review_id);
            message.success('Review hidden');
            refetchReviews();
        } catch (error) {
            message.error(error.response?.data?.message || 'Failed to hide review');
        }
    };

    const handleFeatureReview = async (record) => {
        try {
            await featureReview.mutateAsync({ id: record.review_id, featured: !record.is_featured });
            message.success(record.is_featured ? 'Removed from featured' : 'Added to featured');
            refetchReviews();
        } catch (error) {
            message.error(error.response?.data?.message || 'Failed to update featured status');
        }
    };

    const getSentimentIcon = (sentiment) => {
        if (sentiment === 'positive') return <SmileOutlined style={{ color: '#52c41a' }} />;
        if (sentiment === 'negative') return <FrownOutlined style={{ color: '#ff4d4f' }} />;
        return <MehOutlined style={{ color: '#faad14' }} />;
    };

    const getUnreadCount = (customerId) => {
        return messages.filter(m => 
            m.customer_id === customerId && 
            !m.read_at && 
            m.sender_type === 'customer'
        ).length;
    };

    // Filter chat customers for sidebar
    const chatCustomers = customers.filter(c => 
        messages.some(m => m.customer_id === c.customer_id) || 
        c.person?.first_name?.toLowerCase().includes(messageSearchTerm.toLowerCase()) ||
        c.person?.last_name?.toLowerCase().includes(messageSearchTerm.toLowerCase()) ||
        c.person?.email?.toLowerCase().includes(messageSearchTerm.toLowerCase())
    );

    // ==================== TABLE COLUMNS ====================
    const customerColumns = [
        { title: 'CUSTOMER ID', dataIndex: 'customer_code', key: 'code', width: 120, render: (text) => <Tag color="blue">{text}</Tag> },
        { title: 'CUSTOMER NAME', key: 'name', width: 180, render: (_, r) => (
            <div><Text strong>{r.person?.first_name} {r.person?.last_name}</Text><Text type="secondary" style={{ fontSize: 11, display: 'block' }}>{r.person?.email}</Text></div>
        )},
        { title: 'CONTACT', key: 'phone', width: 140, render: (_, r) => <div><PhoneOutlined /> {r.person?.phone || 'N/A'}</div> },
        { title: 'BOOKINGS', key: 'bookings', width: 90, align: 'center', render: (_, r) => <Badge count={r.total_bookings || 0} showZero /> },
        { title: 'TOTAL SPENT', key: 'spent', width: 130, align: 'right', render: (_, r) => <Text strong style={{ color: '#52c41a' }}>₱{(r.total_spent || 0).toLocaleString()}</Text> },
        { title: 'TIER', dataIndex: 'tier', key: 'tier', width: 100, align: 'center', render: (s) => <Tag color={s === 'platinum' ? 'gold' : s === 'gold' ? 'gold' : s === 'silver' ? 'blue' : 'default'}>{s || 'bronze'}</Tag> },
        { title: 'STATUS', dataIndex: 'is_active', key: 'status', width: 100, align: 'center', render: (s) => <Tag color={s ? 'green' : 'red'}>{s ? 'active' : 'inactive'}</Tag> },
        { title: 'ACTIONS', key: 'actions', width: 200, render: (_, record) => (
            <Space>
                <Tooltip title="View Details"><Button type="text" icon={<EyeOutlined />} onClick={() => handleViewCustomerDetails(record)} /></Tooltip>
                <Tooltip title="Edit"><Button type="text" icon={<EditOutlined />} onClick={() => handleEditCustomer(record)} /></Tooltip>
                <Tooltip title="Chat"><Button type="primary" icon={<MessageOutlined />} onClick={() => openMessageView(record)} size="small" /></Tooltip>
                <Dropdown menu={{ items: [{ key: 'delete', label: 'Delete', icon: <DeleteOutlined />, danger: true, onClick: () => handleDeleteCustomer(record) }] }}>
                    <Button type="text" icon={<MoreOutlined />} />
                </Dropdown>
            </Space>
        )}
    ];

    const bookingColumns = [
        { title: 'BOOKING ID', dataIndex: 'booking_no', key: 'id', width: 120, render: (text) => <Tag color="purple">{text}</Tag> },
        { title: 'EVENT TYPE', dataIndex: ['service_event', 'event_type', 'name'], key: 'type', width: 130 },
        { title: 'EVENT DATE', dataIndex: ['service_event', 'event_date'], key: 'date', width: 120, render: (text) => text ? format(new Date(text), 'MMM dd, yyyy') : 'N/A' },
        { title: 'VENUE', dataIndex: ['service_event', 'venue'], key: 'venue', width: 180, ellipsis: true },
        { title: 'PAX', dataIndex: ['service_event', 'guests_count'], key: 'pax', width: 80, align: 'center', render: (v) => <Tag>{v || 0}</Tag> },
        { title: 'AMOUNT', dataIndex: ['quotation', 'total_amount'], key: 'amount', width: 120, align: 'right', render: (v) => <Text strong>₱{(v || 0).toLocaleString()}</Text> },
        { title: 'STATUS', dataIndex: 'booking_status', key: 'status', width: 100, align: 'center', render: (s) => <Tag color={s === 'confirmed' ? 'green' : s === 'completed' ? 'blue' : 'orange'}>{s?.replace('_', ' ')}</Tag> },
    ];

    const feedbackColumns = [
        { title: 'CUSTOMER', key: 'customer', width: 150, render: (_, r) => r.customer_name || 'Customer' },
        { title: 'FOOD', dataIndex: 'food_rating', key: 'food', width: 80, align: 'center', render: (v) => <Rate disabled defaultValue={v} style={{ fontSize: 12 }} /> },
        { title: 'SERVICE', dataIndex: 'service_rating', key: 'service', width: 80, align: 'center', render: (v) => <Rate disabled defaultValue={v} style={{ fontSize: 12 }} /> },
        { title: 'OVERALL', dataIndex: 'overall_rating', key: 'overall', width: 80, align: 'center', render: (v) => <Rate disabled defaultValue={v} style={{ fontSize: 12 }} allowHalf /> },
        { title: 'SENTIMENT', key: 'sentiment', width: 100, align: 'center', render: (_, r) => <div>{getSentimentIcon(r.sentiment)} {r.sentiment}</div> },
        { title: 'DATE', dataIndex: 'created_at', key: 'date', width: 110, render: (v) => v ? format(new Date(v), 'MMM dd, yyyy') : 'N/A' },
        { title: 'ACTIONS', key: 'actions', width: 100, render: (_, r) => <Button type="text" icon={<EyeOutlined />} onClick={() => { setSelectedItem(r); setFeedbackModalVisible(true); }}>View</Button> }
    ];

    const reviewColumns = [
        { title: 'CUSTOMER', key: 'customer', width: 150, render: (_, r) => r.customer_name || 'Customer' },
        { title: 'REVIEW', dataIndex: 'comment', key: 'review', width: 250, ellipsis: true },
        { title: 'RATING', dataIndex: 'overall_rating', key: 'rating', width: 100, align: 'center', render: (v) => <Rate disabled defaultValue={v} style={{ fontSize: 14 }} /> },
        { title: 'STATUS', dataIndex: 'is_approved', key: 'status', width: 100, align: 'center', render: (v) => <Tag color={v ? 'green' : 'orange'}>{v ? 'approved' : 'pending'}</Tag> },
        { title: 'FEATURED', dataIndex: 'is_featured', key: 'featured', width: 80, align: 'center', render: (v) => v ? <StarFilled style={{ color: '#ff4d4f' }} /> : <StarOutlined /> },
        { title: 'ACTIONS', key: 'actions', width: 200, render: (_, record) => (
            <Space>
                {!record.is_approved && <Button size="small" type="primary" onClick={() => handleApproveReview(record)}>Approve</Button>}
                <Button size="small" icon={record.is_featured ? <StarFilled /> : <StarOutlined />} onClick={() => handleFeatureReview(record)} />
                <Popconfirm title="Hide this review?" onConfirm={() => handleHideReview(record)}><Button size="small" danger>Hide</Button></Popconfirm>
            </Space>
        )}
    ];

    const containerClass = `cm-customer-container ${isDarkMode ? 'cm-dark-mode' : ''}`;
    const headerClass = `cm-header ${isDarkMode ? 'cm-header-dark' : ''}`;
    const mainCardClass = `cm-main-card ${isDarkMode ? 'cm-main-card-dark' : ''}`;
    const tableClass = `cm-table ${isDarkMode ? 'cm-table-dark' : ''}`;
    const kpiCardClass = `cm-kpi-card ${isDarkMode ? 'cm-kpi-card-dark' : ''}`;
    const drawerClass = `cm-drawer ${isDarkMode ? 'cm-drawer-dark' : ''}`;

    return (
        <ConfigProvider theme={{ algorithm: isDarkMode ? antdTheme.darkAlgorithm : antdTheme.defaultAlgorithm }}>
            <div className={containerClass}>
                <div className={headerClass}>
                    <div className="cm-header-left">
                        <div className="cm-logo-icon"><UserOutlined /></div>
                        <div><h3>Customer Management</h3><span>Complete Customer Relationship Management</span></div>
                    </div>
                    <div className="cm-header-right">
                        <div className="cm-date-display"><CalendarOutlined /> <span>{new Date().toLocaleDateString('en-US', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' })}</span></div>
                        <Divider type="vertical" />
                        <Button icon={<ReloadOutlined />} onClick={() => refetchCustomers()} loading={customersLoading}>Refresh</Button>
                        <Button icon={<ExportOutlined />}>Export</Button>
                        <Button icon={<PrinterOutlined />}>Print</Button>
                    </div>
                </div>

                <div className="cm-main-content">
                    <div className="cm-kpi-grid">
                        <div className={kpiCardClass}><div className="cm-kpi-icon blue"><UserOutlined /></div><div><div className="cm-kpi-value">{totalCustomers}</div><div className="cm-kpi-label">Total Customers</div></div></div>
                        <div className={kpiCardClass}><div className="cm-kpi-icon green"><TeamOutlined /></div><div><div className="cm-kpi-value">{activeCustomers}</div><div className="cm-kpi-label">Active Customers</div></div></div>
                        <div className={kpiCardClass}><div className="cm-kpi-icon cyan"><WalletOutlined /></div><div><div className="cm-kpi-value">₱{totalRevenue.toLocaleString()}</div><div className="cm-kpi-label">Total Revenue</div></div></div>
                        <div className={kpiCardClass}><div className="cm-kpi-icon orange"><StarOutlined /></div><div><div className="cm-kpi-value">{avgRating}</div><div className="cm-kpi-label">Avg Rating</div><div className="cm-kpi-trend">{positiveSentiment} positive</div></div></div>
                    </div>

                    <Card className={mainCardClass} bordered={false}>
                        <Tabs activeKey={activeMainTab} onChange={setActiveMainTab}>
                            <TabPane tab={<span><UserOutlined /> Customer Directory</span>} key="directory">
                                <div className="cm-filters">
                                    <Select value={filterTier} onChange={setFilterTier} style={{ width: 140 }}><Option value="all">All Tiers</Option><Option value="platinum">Platinum</Option><Option value="gold">Gold</Option><Option value="silver">Silver</Option><Option value="bronze">Bronze</Option></Select>
                                    <Select value={filterStatus} onChange={setFilterStatus} style={{ width: 140 }}><Option value="all">All Status</Option><Option value="active">Active</Option><Option value="inactive">Inactive</Option></Select>
                                    <Input placeholder="Search by name or email..." value={searchText} onChange={(e) => setSearchText(e.target.value)} allowClear style={{ width: 240 }} prefix={<SearchOutlined />} />
                                    <Button type="primary" icon={<PlusOutlined />} onClick={handleAddCustomer}>Add Customer</Button>
                                </div>
                                <Table columns={customerColumns} dataSource={customers} rowKey="customer_id" loading={customersLoading} pagination={{ current: currentPage, pageSize, total: totalCustomers, showSizeChanger: true, showTotal: (total) => `${total} customers`, onChange: (page, size) => { setCurrentPage(page); setPageSize(size); } }} className={tableClass} scroll={{ x: 1300 }} />
                            </TabPane>
                            <TabPane tab={<span><CalendarOutlined /> Bookings</span>} key="bookings">
                                <Alert message="Booking History" description="Track all customer bookings across all events" type="info" showIcon style={{ marginBottom: 20 }} />
                                <Table columns={bookingColumns} dataSource={customerBookings} rowKey="booking_id" pagination={{ pageSize: 10 }} className={tableClass} />
                            </TabPane>
                            <TabPane tab={<span><StarOutlined /> Feedback</span>} key="feedback">
                                <Alert message="Customer Satisfaction Monitoring" description="Monitor food, service, and overall ratings" type="info" showIcon style={{ marginBottom: 20 }} />
                                <Table columns={feedbackColumns} dataSource={feedbacks} rowKey="review_id" pagination={{ pageSize: 10 }} className={tableClass} />
                            </TabPane>
                            <TabPane tab={<span><FileTextOutlined /> Reviews</span>} key="reviews">
                                <Alert message="Review Management" description="Approve, hide, or feature customer reviews" type="info" showIcon style={{ marginBottom: 20 }} />
                                <div style={{ marginBottom: 16 }}><Badge count={pendingReviews}><Tag color="orange">Pending Approval: {pendingReviews}</Tag></Badge></div>
                                <Table columns={reviewColumns} dataSource={reviews} rowKey="review_id" pagination={{ pageSize: 10 }} className={tableClass} />
                            </TabPane>
                            <TabPane tab={<span><MessageOutlined /> Messages</span>} key="messages">
                                <div style={{ padding: '20px' }}>
                                    <Alert message="Customer Communication" description="Manage all customer inquiries and messages" type="info" showIcon style={{ marginBottom: 20 }} />
                                    
                                    {/* Messenger-style Chat Interface */}
                                    <div className="cm-messenger-container">
                                        <div className="cm-messenger-sidebar">
                                            <div className="cm-messenger-sidebar-header">
                                                <div className="cm-messenger-title">
                                                    <MessageOutlined style={{ fontSize: 20, color: '#1a7ab5' }} />
                                                    <span>Conversations</span>
                                                </div>
                                                <div className="cm-messenger-search">
                                                    <Input 
                                                        placeholder="Search conversations..." 
                                                        prefix={<SearchOutlined style={{ color: '#94a3b8' }} />}
                                                        value={messageSearchTerm}
                                                        onChange={(e) => setMessageSearchTerm(e.target.value)}
                                                        size="small"
                                                        className="cm-messenger-search-input"
                                                    />
                                                </div>
                                            </div>
                                            <div className="cm-messenger-contact-list">
                                                {chatCustomers.length === 0 ? (
                                                    <Empty description="No conversations yet" image={Empty.PRESENTED_IMAGE_SIMPLE} />
                                                ) : (
                                                    chatCustomers.map(customer => {
                                                        const unreadCount = getUnreadCount(customer.customer_id);
                                                        const lastMessage = messages
                                                            .filter(m => m.customer_id === customer.customer_id)
                                                            .sort((a, b) => new Date(b.created_at) - new Date(a.created_at))[0];
                                                        const isActive = selectedChatCustomer?.customer_id === customer.customer_id;
                                                        
                                                        return (
                                                            <div 
                                                                key={customer.customer_id}
                                                                className={`cm-messenger-contact ${isActive ? 'cm-messenger-contact-active' : ''}`}
                                                                onClick={() => {
                                                                    if (selectedChatCustomer?.customer_id === customer.customer_id) return;
                                                                    openMessageView(customer);
                                                                }}
                                                            >
                                                                <Avatar 
                                                                    size={44} 
                                                                    icon={<UserOutlined />} 
                                                                    style={{ backgroundColor: isActive ? '#1a7ab5' : '#8b93a8' }}
                                                                />
                                                                <div className="cm-messenger-contact-info">
                                                                    <div className="cm-messenger-contact-name">
                                                                        <Text strong>{customer.person?.first_name} {customer.person?.last_name}</Text>
                                                                        {unreadCount > 0 && (
                                                                            <Badge count={unreadCount} style={{ marginLeft: 'auto' }} />
                                                                        )}
                                                                    </div>
                                                                    <div className="cm-messenger-contact-last">
                                                                        {lastMessage ? (
                                                                            <Text type="secondary" ellipsis style={{ fontSize: 13 }}>
                                                                                {lastMessage.sender_type === 'admin' ? 'You: ' : ''}
                                                                                {lastMessage.message?.substring(0, 40)}
                                                                                {lastMessage.message?.length > 40 ? '...' : ''}
                                                                            </Text>
                                                                        ) : (
                                                                            <Text type="secondary" style={{ fontSize: 13 }}>No messages yet</Text>
                                                                        )}
                                                                        <span className="cm-messenger-contact-time">
                                                                            {lastMessage?.created_at ? formatDistanceToNow(new Date(lastMessage.created_at), { addSuffix: true }) : ''}
                                                                        </span>
                                                                    </div>
                                                                </div>
                                                            </div>
                                                        );
                                                    })
                                                )}
                                            </div>
                                        </div>

                                        <div className="cm-messenger-chat">
                                            {selectedChatCustomer ? (
                                                <>
                                                    <div className="cm-messenger-chat-header">
                                                        <div className="cm-messenger-chat-header-left">
                                                            <Button 
                                                                type="text" 
                                                                icon={<ArrowLeftOutlined />} 
                                                                className="cm-messenger-back-btn"
                                                                onClick={closeMessageView}
                                                            />
                                                            <Avatar 
                                                                size={40} 
                                                                icon={<UserOutlined />} 
                                                                style={{ backgroundColor: '#1a7ab5' }}
                                                            />
                                                            <div className="cm-messenger-chat-header-info">
                                                                <Text strong style={{ fontSize: 15 }}>
                                                                    {selectedChatCustomer.person?.first_name} {selectedChatCustomer.person?.last_name}
                                                                </Text>
                                                                <Text type="secondary" style={{ fontSize: 12 }}>
                                                                    <Badge status="success" /> Active now
                                                                </Text>
                                                            </div>
                                                        </div>
                                                        <div className="cm-messenger-chat-header-right">
                                                            <Tooltip title="View Profile">
                                                                <Button type="text" icon={<EyeOutlined />} />
                                                            </Tooltip>
                                                            <Tooltip title="Call">
                                                                <Button type="text" icon={<PhoneOutlined />} />
                                                            </Tooltip>
                                                            <Tooltip title="More">
                                                                <Button type="text" icon={<MoreOutlined />} />
                                                            </Tooltip>
                                                        </div>
                                                    </div>

                                                    <div className="cm-messenger-chat-messages">
                                                        {chatMessages.map((msg, index) => {
                                                            const isSystem = msg.isSystem;
                                                            const isAdmin = msg.sender_type === 'admin' || msg.isAdmin;
                                                            const isCustomer = msg.sender_type === 'customer' && !msg.isAdmin;
                                                            const isFirstInGroup = index === 0 || 
                                                                chatMessages[index - 1]?.sender_type !== msg.sender_type;
                                                            
                                                            return (
                                                                <div key={msg.id || index} className="cm-message-wrapper">
                                                                    {isSystem ? (
                                                                        <div className="cm-message-system">
                                                                            <Text type="secondary" style={{ fontSize: 13 }}>
                                                                                {msg.message}
                                                                            </Text>
                                                                        </div>
                                                                    ) : (
                                                                        <div className={`cm-message-bubble-wrapper ${isAdmin ? 'cm-message-admin' : 'cm-message-customer'}`}>
                                                                            {isFirstInGroup && (
                                                                                <div className="cm-message-sender">
                                                                                    {isAdmin ? 'You' : msg.sender_name}
                                                                                </div>
                                                                            )}
                                                                            <div className={`cm-message-bubble ${isAdmin ? 'cm-message-bubble-admin' : 'cm-message-bubble-customer'}`}>
                                                                                <div className="cm-message-text">{msg.message}</div>
                                                                                <div className="cm-message-meta">
                                                                                    <span className="cm-message-time">
                                                                                        {msg.created_at ? format(new Date(msg.created_at), 'hh:mm a') : ''}
                                                                                    </span>
                                                                                    {isAdmin && (
                                                                                        <span className="cm-message-status">
                                                                                            {msg.read_at ? <CheckCircleFilled style={{ color: '#52c41a' }} /> : <ClockCircleFilled style={{ color: '#8b93a8' }} />}
                                                                                        </span>
                                                                                    )}
                                                                                </div>
                                                                            </div>
                                                                        </div>
                                                                    )}
                                                                </div>
                                                            );
                                                        })}
                                                        {isTyping && (
                                                            <div className="cm-message-typing">
                                                                <div className="cm-typing-indicator">
                                                                    <span></span>
                                                                    <span></span>
                                                                    <span></span>
                                                                </div>
                                                                <Text type="secondary" style={{ fontSize: 12, marginLeft: 8 }}>
                                                                    {selectedChatCustomer.person?.first_name} is typing...
                                                                </Text>
                                                            </div>
                                                        )}
                                                        <div ref={messagesEndRef} />
                                                    </div>

                                                    <div className="cm-messenger-chat-input">
                                                        <Button 
                                                            type="text" 
                                                            icon={<PaperClipOutlined />} 
                                                            className="cm-chat-attach-btn"
                                                            onClick={handleFileUpload}
                                                        />
                                                        <Button 
                                                            type="text" 
                                                            icon={<CameraOutlined />} 
                                                            className="cm-chat-attach-btn"
                                                        />
                                                        <input 
                                                            type="file" 
                                                            ref={fileInputRef} 
                                                            style={{ display: 'none' }}
                                                            onChange={(e) => {
                                                                // Handle file upload
                                                                message.info('File upload feature coming soon');
                                                                e.target.value = '';
                                                            }}
                                                        />
                                                        <TextArea
                                                            value={newMessage}
                                                            onChange={(e) => setNewMessage(e.target.value)}
                                                            onKeyPress={handleKeyPress}
                                                            placeholder="Type a message..."
                                                            autoSize={{ minRows: 1, maxRows: 4 }}
                                                            className="cm-chat-textarea"
                                                            variant="borderless"
                                                        />
                                                        <Button 
                                                            type="primary" 
                                                            shape="circle" 
                                                            icon={<SendOutlined />} 
                                                            className="cm-chat-send-btn"
                                                            onClick={handleSendMessage}
                                                            disabled={!newMessage.trim()}
                                                        />
                                                    </div>
                                                </>
                                            ) : (
                                                <div className="cm-messenger-chat-empty">
                                                    <div className="cm-messenger-chat-empty-content">
                                                        <MessageOutlined style={{ fontSize: 64, color: '#d9d9d9', marginBottom: 16 }} />
                                                        <Title level={4}>Select a conversation</Title>
                                                        <Text type="secondary" style={{ fontSize: 14 }}>
                                                            Choose a customer from the sidebar to start chatting
                                                        </Text>
                                                    </div>
                                                </div>
                                            )}
                                        </div>
                                    </div>
                                </div>
                            </TabPane>
                        </Tabs>
                    </Card>
                </div>

                {/* Modals */}
                <Modal title={selectedItem ? "Edit Customer" : "Add New Customer"} open={customerModalVisible} onCancel={() => setCustomerModalVisible(false)} width={700} footer={null}>
                    <Form form={customerForm} layout="vertical" onFinish={handleSaveCustomer}>
                        <Row gutter={16}><Col span={12}><Form.Item name="first_name" label="First Name" rules={[{ required: true }]}><Input placeholder="Enter first name" /></Form.Item></Col><Col span={12}><Form.Item name="last_name" label="Last Name" rules={[{ required: true }]}><Input placeholder="Enter last name" /></Form.Item></Col></Row>
                        <Row gutter={16}><Col span={12}><Form.Item name="email" label="Email" rules={[{ required: true, type: 'email' }]}><Input placeholder="email@example.com" /></Form.Item></Col><Col span={12}><Form.Item name="phone" label="Phone"><Input placeholder="Contact number" /></Form.Item></Col></Row>
                        <Form.Item name="address" label="Address"><Input placeholder="Address" /></Form.Item>
                        <Row gutter={16}><Col span={12}><Form.Item name="tier" label="Tier"><Select><Option value="bronze">Bronze</Option><Option value="silver">Silver</Option><Option value="gold">Gold</Option><Option value="platinum">Platinum</Option></Select></Form.Item></Col><Col span={12}><Form.Item name="dietary_restrictions" label="Dietary Restrictions"><Input placeholder="Any dietary restrictions?" /></Form.Item></Col></Row>
                        <Form.Item name="notes" label="Notes"><TextArea rows={2} placeholder="Additional notes about customer" /></Form.Item>
                        <Form.Item style={{ textAlign: 'right' }}><Space><Button onClick={() => setCustomerModalVisible(false)}>Cancel</Button><Button type="primary" htmlType="submit" loading={createCustomer.isPending || updateCustomer.isPending}>Save Customer</Button></Space></Form.Item>
                    </Form>
                </Modal>

                <Drawer title="Customer Details" open={customerDetailsVisible} onClose={() => setCustomerDetailsVisible(false)} width={600} className={drawerClass}>
                    {selectedCustomer && (<div>
                        <div className="cm-customer-profile"><Avatar size={80} icon={<UserOutlined />} /><div><Title level={4}>{selectedCustomer.person?.first_name} {selectedCustomer.person?.last_name}</Title><div><MailOutlined /> {selectedCustomer.person?.email}</div><div><PhoneOutlined /> {selectedCustomer.person?.phone || 'N/A'}</div></div></div>
                        <Divider /><Title level={5}>Statistics</Title>
                        <Row gutter={16}><Col span={12}><div className="cm-stat-box"><Text type="secondary">Total Bookings</Text><div className="cm-stat-num">{selectedCustomer.total_bookings || 0}</div></div></Col><Col span={12}><div className="cm-stat-box"><Text type="secondary">Total Spent</Text><div className="cm-stat-num">₱{(selectedCustomer.total_spent || 0).toLocaleString()}</div></div></Col></Row>
                        <Divider /><Title level={5}>Preferences</Title>
                        <Descriptions column={1} size="small"><Descriptions.Item label="Tier">{selectedCustomer.tier || 'bronze'}</Descriptions.Item><Descriptions.Item label="Dietary Restrictions">{selectedCustomer.dietary_restrictions || 'None'}</Descriptions.Item><Descriptions.Item label="Loyalty Points">{selectedCustomer.loyalty_points || 0}</Descriptions.Item><Descriptions.Item label="Notes">{selectedCustomer.notes || 'No notes'}</Descriptions.Item></Descriptions>
                        <Divider /><Title level={5}>Booking History</Title>
                        <Table dataSource={customerBookings} columns={bookingColumns.slice(0, 5)} pagination={false} size="small" />
                    </div>)}
                </Drawer>

                <Modal title="Feedback Details" open={feedbackModalVisible} onCancel={() => setFeedbackModalVisible(false)} width={600} footer={null}>
                    {selectedItem && (<div>
                        <div><Text strong>{selectedItem.customer_name}</Text><Text type="secondary"> - {selectedItem.created_at ? format(new Date(selectedItem.created_at), 'MMM dd, yyyy') : 'N/A'}</Text></div>
                        <Divider />
                        <div><Row gutter={16}><Col span={12}>Food: <Rate disabled defaultValue={selectedItem.food_rating} /></Col><Col span={12}>Service: <Rate disabled defaultValue={selectedItem.service_rating} /></Col></Row></div>
                        <Divider /><div><Text strong>Customer Comment:</Text><Paragraph>{selectedItem.comment}</Paragraph></div>
                        <Divider /><div><Text strong>Admin Response:</Text><Paragraph>{selectedItem.admin_response || 'No response yet'}</Paragraph></div>
                        <Form form={feedbackForm} onFinish={handleRespondToFeedback}><Form.Item name="response" label="Add Response"><TextArea rows={3} placeholder="Write your response..." /></Form.Item><Form.Item style={{ textAlign: 'right' }}><Button type="primary" htmlType="submit" loading={respondToFeedback.isPending}>Send Response</Button></Form.Item></Form>
                    </div>)}
                </Modal>

                <Modal title="Send Message" open={messageModalVisible} onCancel={() => setMessageModalVisible(false)} width={500} footer={null}>
                    <Form form={messageForm} layout="vertical" onFinish={handleSendMessage}>
                        <Form.Item name="customer_id" label="To" rules={[{ required: true }]}><Select placeholder="Select customer" showSearch optionFilterProp="children">{customers.map(c => (<Option key={c.customer_id} value={c.customer_id}>{c.person?.first_name} {c.person?.last_name} - {c.person?.email}</Option>))}</Select></Form.Item>
                        <Form.Item name="message" label="Message" rules={[{ required: true }]}><TextArea rows={5} placeholder="Type your message here..." /></Form.Item>
                        <Form.Item style={{ textAlign: 'right' }}><Space><Button onClick={() => setMessageModalVisible(false)}>Cancel</Button><Button type="primary" htmlType="submit" loading={sendMessage.isPending}>Send Message</Button></Space></Form.Item>
                    </Form>
                </Modal>
            </div>
        </ConfigProvider>
    );
};

export default CustomerManagement;