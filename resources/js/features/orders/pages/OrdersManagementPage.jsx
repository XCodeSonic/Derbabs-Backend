// src/components/OrderManagement.jsx - COMPLETE FIXED
import React, { useState, useEffect, useRef } from 'react';
import {
    Card,
    Table,
    Button,
    Space,
    Input,
    InputNumber,
    Select,
    Modal,
    Tabs,
    Tag,
    message,
    Divider,
    Tooltip,
    Typography,
    Alert,
    DatePicker,
    Empty,
    Dropdown,
    ConfigProvider,
    theme as antdTheme,
    Popconfirm,
    Spin,
    Form,
    Row,
    Col,
    Badge,
    Statistic,
    Pagination
} from 'antd';
import {
    ShoppingOutlined,
    EyeOutlined,
    SearchOutlined,
    CalendarOutlined,
    HistoryOutlined,
    ReloadOutlined,
    TrophyOutlined,
    PrinterOutlined,
    ExportOutlined,
    TruckOutlined,
    PlayCircleOutlined,
    StockOutlined,
    MenuOutlined,
    WalletOutlined,
    MailOutlined,
    PhoneOutlined,
    EnvironmentOutlined,
    ScheduleOutlined,
    OrderedListOutlined,
    MoreOutlined,
    EditOutlined,
    DeleteOutlined,
    RiseOutlined,
    PercentageOutlined,
    FilterOutlined,
    PlusOutlined,
    ShoppingCartOutlined,
    SaveOutlined,
    CheckCircleOutlined,
    ClockCircleOutlined,
    CloseCircleOutlined,
    SyncOutlined,
    DollarOutlined,
    TeamOutlined,
    UserOutlined,
    LoadingOutlined,
    WarningOutlined,
    InfoCircleOutlined,
    ContainerOutlined,
    CoffeeOutlined,
    ForkOutlined,
    CrownOutlined,
    LeftOutlined,
    RightOutlined
} from '@ant-design/icons';
import { useOrders, useOrderStatistics, useKitchenOrders, useDeliveryOrders, useAddToKitchen, useRemoveFromKitchen, useAddToDelivery, useRemoveFromDelivery, useUpdateOrderStatus, useUpdateKitchenTask, useUpdateDeliveryItem, useAddToShoppingList, useDeleteOrder, useCreateOrder, useUpdateOrder } from '../../../hooks/useOrders';
import { useShoppingList, useMarkShoppingItemPurchased } from '../../../hooks/useShoppingList';
import api from '../../../services/api';
import dayjs from 'dayjs';
import * as XLSX from 'xlsx';
import '../styles/OrdersManagement.css';

const { Title, Text } = Typography;
const { Option } = Select;
const { TabPane } = Tabs;
const { TextArea } = Input;

// ============================================================
// HELPER FUNCTIONS
// ============================================================
const formatBookingId = (bookingNo) => {
    if (!bookingNo) return 'N/A';
    if (bookingNo.match(/^BK-\d+$/i)) return bookingNo.toUpperCase();
    if (/^\d+$/.test(bookingNo)) return `BK-${bookingNo.padStart(4, '0')}`;
    if (bookingNo.toUpperCase().startsWith('BK')) {
        const numbers = bookingNo.match(/\d+/g);
        if (numbers) return `BK-${numbers[0].padStart(4, '0')}`;
    }
    return bookingNo;
};

const formatCurrency = (value) => {
    if (!value && value !== 0) return '₱0.00';
    return `₱${value.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
};

const safeNumber = (value, defaultValue = 0) => {
    const num = Number(value);
    return isNaN(num) ? defaultValue : num;
};

const safeString = (value, defaultValue = '') => {
    if (value === null || value === undefined) return defaultValue;
    return String(value);
};

const safeArray = (value, defaultValue = []) => {
    if (Array.isArray(value)) return value;
    return defaultValue;
};

// ============================================================
// STATUS CONFIG
// ============================================================
const getStatusConfig = (status) => {
    const config = {
        pending: { color: '#f97316', text: 'Pending', icon: <ClockCircleOutlined />, bg: '#fff7ed' },
        preparing: { color: '#3b82f6', text: 'Preparing', icon: <SyncOutlined spin />, bg: '#eff6ff' },
        ready: { color: '#10b981', text: 'Ready', icon: <CheckCircleOutlined />, bg: '#ecfdf5' },
        ongoing: { color: '#8b5cf6', text: 'Ongoing', icon: <TruckOutlined />, bg: '#f5f3ff' },
        completed: { color: '#059669', text: 'Completed', icon: <CheckCircleOutlined />, bg: '#ecfdf5' },
        cancelled: { color: '#ef4444', text: 'Cancelled', icon: <CloseCircleOutlined />, bg: '#fef2f2' }
    };
    return config[status] || config.pending;
};

// ============================================================
// MAIN COMPONENT
// ============================================================
const OrderManagement = () => {
    // ==================== STATE ====================
    const [searchText, setSearchText] = useState('');
    const [filterStatus, setFilterStatus] = useState('all');
    const [selectedDate, setSelectedDate] = useState(null);
    const [currentPage, setCurrentPage] = useState(1);
    const [pageSize, setPageSize] = useState(8);
    const [activeMainTab, setActiveMainTab] = useState('orders');
    const [isDarkMode, setIsDarkMode] = useState(() => {
        const savedTheme = localStorage.getItem('theme');
        if (savedTheme === 'dark') return true;
        if (savedTheme === 'light') return false;
        return document.body.classList.contains('dark-mode');
    });

    // Modal states
    const [orderDetailsModalVisible, setOrderDetailsModalVisible] = useState(false);
    const [editOrderModalVisible, setEditOrderModalVisible] = useState(false);
    const [statusUpdateModalVisible, setStatusUpdateModalVisible] = useState(false);
    const [kitchenModalVisible, setKitchenModalVisible] = useState(false);
    const [deliveryModalVisible, setDeliveryModalVisible] = useState(false);
    const [ingredientsModalVisible, setIngredientsModalVisible] = useState(false);
    const [createOrderModalVisible, setCreateOrderModalVisible] = useState(false);
    const [selectedOrder, setSelectedOrder] = useState(null);
    const [kitchenTasks, setKitchenTasks] = useState([]);
    const [deliveryItems, setDeliveryItems] = useState([]);
    const [selectedOrderForIngredients, setSelectedOrderForIngredients] = useState(null);
    const [computedIngredients, setComputedIngredients] = useState([]);
    const [isComputingIngredients, setIsComputingIngredients] = useState(false);
    const [isMarkingPurchased, setIsMarkingPurchased] = useState(false);
    const [showAddDeliveryItem, setShowAddDeliveryItem] = useState(false);

    // Pagination states
    const [kitchenCurrentPage, setKitchenCurrentPage] = useState(1);
    const [kitchenPageSize, setKitchenPageSize] = useState(5);
    const [deliveryCurrentPage, setDeliveryCurrentPage] = useState(1);
    const [deliveryPageSize, setDeliveryPageSize] = useState(5);

    // Forms
    const [createOrderForm] = Form.useForm();
    const [editOrderForm] = Form.useForm();
    const [deliveryItemForm] = Form.useForm();

    // ==================== API HOOKS ====================
    const { data: ordersData, isLoading: ordersLoading, refetch: refetchOrders } = useOrders({
        status: filterStatus !== 'all' ? filterStatus : undefined,
        search: searchText || undefined,
        event_date: selectedDate ? dayjs(selectedDate).format('YYYY-MM-DD') : undefined,
        page: currentPage,
        per_page: pageSize
    });

    const { data: statistics, refetch: refetchStatistics } = useOrderStatistics();
    const { data: kitchenOrders, refetch: refetchKitchenOrders } = useKitchenOrders();
    const { data: deliveryOrders, refetch: refetchDeliveryOrders } = useDeliveryOrders();
    const { data: shoppingList, refetch: refetchShoppingList } = useShoppingList();

    // Mutations
    const addToKitchenMutation = useAddToKitchen();
    const removeFromKitchenMutation = useRemoveFromKitchen();
    const addToDeliveryMutation = useAddToDelivery();
    const removeFromDeliveryMutation = useRemoveFromDelivery();
    const updateStatusMutation = useUpdateOrderStatus();
    const updateKitchenTaskMutation = useUpdateKitchenTask();
    const updateDeliveryItemMutation = useUpdateDeliveryItem();
    const addToShoppingListMutation = useAddToShoppingList();
    const deleteOrderMutation = useDeleteOrder();
    const createOrderMutation = useCreateOrder();
    const updateOrderMutation = useUpdateOrder();

    const isMounted = useRef(true);

    // ==================== THEME ====================
    useEffect(() => {
        isMounted.current = true;

        const updateTheme = () => {
            if (isMounted.current) {
                const isDark = document.body.classList.contains('dark-mode');
                setIsDarkMode(isDark);
            }
        };

        const observer = new MutationObserver(() => {
            updateTheme();
        });

        observer.observe(document.body, { attributes: true, attributeFilter: ['class'] });

        const handleThemeChange = (e) => {
            if (isMounted.current) {
                setIsDarkMode(e.detail.isDark);
            }
        };

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

    // ==================== AUTO-REFRESH ====================
    useEffect(() => {
        const interval = setInterval(() => {
            if (activeMainTab === 'orders') {
                refetchOrders();
                refetchStatistics();
            }
        }, 30000);
        return () => clearInterval(interval);
    }, [activeMainTab, refetchOrders, refetchStatistics]);

    // ==================== DATA ====================
    const orders = safeArray(ordersData?.data);
    const stats = statistics || {
        total_orders: 0,
        total_revenue: 0,
        completed_orders: 0,
        pending_purchases: 0,
        preparing_orders: 0,
        ready_orders: 0,
        ongoing_orders: 0,
        cancelled_orders: 0,
        pending_orders: 0,
        outstanding_balance: 0
    };

    const totalOrders = stats.total_orders;
    const totalRevenue = stats.total_revenue;
    const completionRate = totalOrders > 0 ? (stats.completed_orders || 0) / totalOrders * 100 : 0;
    const pendingPurchases = stats.pending_purchases || 0;

    // ==================== HELPER FUNCTIONS ====================
    const getMenuItems = (order) => {
        const items = safeArray(order.menu_items);
        return items.map(item => ({
            name: safeString(item.name),
            quantity: safeNumber(item.quantity, 1),
            price: safeNumber(item.price, 0),
            subtotal: safeNumber(item.price, 0) * safeNumber(item.quantity, 1)
        }));
    };

    const getTotalMenuAmount = (order) => {
        return getMenuItems(order).reduce((sum, item) => sum + item.subtotal, 0);
    };

    // ==================== EXPORT FUNCTIONS ====================
    const exportToExcel = (data, filename, columns) => {
        const worksheetData = data.map(row => {
            const exportRow = {};
            columns.forEach(col => {
                if (col.dataIndex) {
                    let value = row[col.dataIndex];
                    if (col.dataIndex === 'total_amount' || col.dataIndex === 'amount') {
                        value = formatCurrency(value);
                    }
                    exportRow[col.title] = value;
                } else if (col.key === 'status') {
                    exportRow[col.title] = getStatusConfig(row.order_status).text;
                } else {
                    exportRow[col.title] = row[col.key] || '';
                }
            });
            return exportRow;
        });

        const ws = XLSX.utils.json_to_sheet(worksheetData);
        const wb = XLSX.utils.book_new();
        XLSX.utils.book_append_sheet(wb, ws, filename);
        XLSX.writeFile(wb, `${filename}.xlsx`);
        message.success(`${filename} exported successfully`);
    };

    const exportOrders = () => {
        const columns = [
            { title: 'BOOKING ID', dataIndex: 'booking_no' },
            { title: 'CUSTOMER', dataIndex: 'customer_name' },
            { title: 'EVENT DATE', dataIndex: 'event_date' },
            { title: 'EVENT TIME', dataIndex: 'event_time' },
            { title: 'VENUE', dataIndex: 'venue' },
            { title: 'PAX', dataIndex: 'guests_count' },
            { title: 'TOTAL AMOUNT', dataIndex: 'total_amount' },
            { title: 'STATUS', key: 'status' }
        ];
        exportToExcel(orders, 'Orders_Report', columns);
    };

    // ==================== PRINT FUNCTIONS ====================
    const printKitchenTasks = (tasks) => {
        if (!tasks || tasks.length === 0) {
            message.warning('No kitchen tasks to print');
            return;
        }
        const printWindow = window.open('', '_blank');
        let htmlContent = `
            <!DOCTYPE html>
            <html>
            <head>
                <title>Kitchen Tasks - ${selectedOrder?.booking_no}</title>
                <style>
                    body { font-family: 'Inter', sans-serif; margin: 40px; background: #f8fafc; }
                    .print-container { max-width: 1200px; margin: 0 auto; background: white; padding: 40px; border-radius: 16px; }
                    h1 { color: #1a7ab5; text-align: center; font-size: 28px; }
                    .header { text-align: center; margin-bottom: 30px; }
                    .event-details { display: flex; justify-content: center; gap: 20px; flex-wrap: wrap; }
                    .event-details span { background: #f0f4f8; padding: 6px 16px; border-radius: 20px; font-size: 13px; }
                    table { width: 100%; border-collapse: collapse; margin-top: 20px; }
                    th, td { border: 1px solid #eef2f8; padding: 12px 16px; text-align: left; }
                    th { background: #1a7ab5; color: white; font-weight: 600; }
                    .no-print { text-align: center; margin-top: 20px; }
                    @media print { .no-print { display: none; } }
                </style>
            </head>
            <body>
                <div class="print-container">
                    <h1>🧑‍🍳 Kitchen Production Task Sheet</h1>
                    <div class="header">
                        <h2>Booking: ${selectedOrder?.booking_no}</h2>
                        <div class="event-details">
                            <span>👤 ${selectedOrder?.customer_name}</span>
                            <span>📅 ${selectedOrder?.event_date}</span>
                            <span>⏰ ${selectedOrder?.event_time}</span>
                            <span>📍 ${selectedOrder?.venue}</span>
                            <span>👥 ${selectedOrder?.guests_count} PAX</span>
                        </div>
                    </div>
                    <table>
                        <thead><tr><th>#</th><th>Task</th><th>Qty</th><th>Status</th></tr></thead>
                        <tbody>
        `;
        let counter = 0;
        tasks.forEach(task => {
            if (task.is_header) return;
            counter++;
            htmlContent += `
                <tr>
                    <td>${counter}</td>
                    <td>${task.task}</td>
                    <td>${task.quantity || task.servings || '-'}</td>
                    <td>${task.status || 'Pending'}</td>
                </tr>
            `;
        });
        htmlContent += `
                        </tbody>
                    </table>
                    <div class="no-print">
                        <button onclick="window.print()">🖨️ Print</button>
                        <button onclick="window.close()">Close</button>
                    </div>
                </div>
            </body>
            </html>
        `;
        printWindow.document.write(htmlContent);
        printWindow.document.close();
    };

    const printDeliveryItems = (items) => {
        if (!items || items.length === 0) {
            message.warning('No delivery items to print');
            return;
        }
        const printWindow = window.open('', '_blank');
        let htmlContent = `
            <!DOCTYPE html>
            <html>
            <head>
                <title>Delivery Items - ${selectedOrder?.booking_no}</title>
                <style>
                    body { font-family: 'Inter', sans-serif; margin: 40px; background: #f8fafc; }
                    .print-container { max-width: 1200px; margin: 0 auto; background: white; padding: 40px; border-radius: 16px; }
                    h1 { color: #1a7ab5; text-align: center; font-size: 28px; }
                    .header { text-align: center; margin-bottom: 30px; }
                    .event-details { display: flex; justify-content: center; gap: 20px; flex-wrap: wrap; }
                    .event-details span { background: #f0f4f8; padding: 6px 16px; border-radius: 20px; font-size: 13px; }
                    table { width: 100%; border-collapse: collapse; margin-top: 20px; }
                    th, td { border: 1px solid #eef2f8; padding: 12px 16px; text-align: left; }
                    th { background: #1a7ab5; color: white; font-weight: 600; }
                    .no-print { text-align: center; margin-top: 20px; }
                    @media print { .no-print { display: none; } }
                </style>
            </head>
            <body>
                <div class="print-container">
                    <h1>🚚 Delivery Preparation List</h1>
                    <div class="header">
                        <h2>Booking: ${selectedOrder?.booking_no}</h2>
                        <div class="event-details">
                            <span>👤 ${selectedOrder?.customer_name}</span>
                            <span>📅 ${selectedOrder?.event_date}</span>
                            <span>⏰ ${selectedOrder?.event_time}</span>
                            <span>📍 ${selectedOrder?.venue}</span>
                        </div>
                    </div>
                    <table>
                        <thead><tr><th>#</th><th>Item</th><th>Qty</th><th>Status</th></tr></thead>
                        <tbody>
        `;
        items.forEach((item, idx) => {
            htmlContent += `
                <tr>
                    <td>${idx + 1}</td>
                    <td>${item.item}</td>
                    <td>${item.quantity}</td>
                    <td>${item.status || 'Pending'}</td>
                </tr>
            `;
        });
        htmlContent += `
                        </tbody>
                    </table>
                    <div class="no-print">
                        <button onclick="window.print()">🖨️ Print</button>
                        <button onclick="window.close()">Close</button>
                    </div>
                </div>
            </body>
            </html>
        `;
        printWindow.document.write(htmlContent);
        printWindow.document.close();
    };

    // ==================== ORDER FUNCTIONS ====================
    const handleViewOrderDetails = (record) => {
        setSelectedOrder(record);
        setOrderDetailsModalVisible(true);
    };

    const handleEditOrder = (record) => {
        setSelectedOrder(record);
        editOrderForm.setFieldsValue({
            customer_name: record.customer_name,
            customer_email: record.customer_email,
            customer_phone: record.customer_phone,
            customer_address: record.customer_address,
            event_type: record.event_type,
            event_date: record.event_date ? dayjs(record.event_date) : null,
            event_time: record.event_time,
            venue: record.venue,
            guests_count: record.guests_count,
            total_amount: record.total_amount,
            special_requests: record.special_requests
        });
        setEditOrderModalVisible(true);
    };

    const handleUpdateOrder = async (values) => {
        try {
            await updateOrderMutation.mutateAsync({
                id: selectedOrder.id,
                data: values
            });
            message.success('Order updated successfully');
            setEditOrderModalVisible(false);
            refetchOrders();
            refetchStatistics();
        } catch (error) {
            message.error(error.response?.data?.message || 'Failed to update order');
        }
    };

    const handleUpdateStatus = (record) => {
        setSelectedOrder(record);
        setStatusUpdateModalVisible(true);
    };

    const handleConfirmStatusUpdate = async (status) => {
        if (!selectedOrder) return;
        try {
            await updateStatusMutation.mutateAsync({
                id: selectedOrder.id,
                data: { status }
            });
            message.success(`Status updated to ${status} for Booking ${formatBookingId(selectedOrder.booking_no)}`);
            setStatusUpdateModalVisible(false);
            refetchOrders();
            refetchStatistics();
            refetchKitchenOrders();
            refetchDeliveryOrders();
        } catch (error) {
            message.error('Failed to update status');
        }
    };

    const handleDeleteOrder = async (record) => {
        try {
            await deleteOrderMutation.mutateAsync(record.id);
            message.success(`Order for Booking ${formatBookingId(record.booking_no)} deleted`);
            refetchOrders();
            refetchStatistics();
            refetchKitchenOrders();
            refetchDeliveryOrders();
        } catch (error) {
            message.error('Failed to delete order');
        }
    };

    const handleCreateOrder = async (values) => {
        try {
            await createOrderMutation.mutateAsync({
                ...values,
                total_amount: values.total_amount,
                event_date: values.event_date ? values.event_date.format('YYYY-MM-DD') : null
            });
            message.success('Order created successfully');
            setCreateOrderModalVisible(false);
            createOrderForm.resetFields();
            refetchOrders();
            refetchStatistics();
        } catch (error) {
            message.error('Failed to create order');
        }
    };

    // ==================== KITCHEN FUNCTIONS ====================
    const generateKitchenTasks = (order) => {
        const tasks = [];
        const menuItems = safeArray(order.menu_items);
        const eventDate = dayjs(order.event_date);
        const eventTime = order.event_time || '12:00 PM';

        const [time, modifier] = eventTime.split(' ');
        let [hour, minute] = time.split(':');
        hour = parseInt(hour);
        if (modifier === 'PM' && hour !== 12) hour += 12;
        if (modifier === 'AM' && hour === 12) hour = 0;

        const eventDateTime = eventDate.hour(hour).minute(parseInt(minute || 0));
        const prepStartTime = eventDateTime.subtract(120, 'minutes');

        tasks.push({
            id: `header-${Date.now()}`,
            type: 'header',
            task: `📋 EVENT ORDER - ${order.booking_no}`,
            customer: order.customer_name,
            event_date: order.event_date,
            event_time: order.event_time,
            venue: order.venue,
            guests: order.guests_count,
            is_header: true
        });

        let currentTime = prepStartTime;
        menuItems.forEach((item, idx) => {
            const prepTime = item.prep_time_minutes || 30;
            const endTime = currentTime.add(prepTime, 'minutes');

            tasks.push({
                id: `menu-${Date.now()}-${idx}`,
                type: 'menu_item',
                task: `🍽️ ${item.name}`,
                quantity: item.quantity,
                servings: item.quantity * (order.guests_count || 1),
                start_time: currentTime.format('hh:mm A'),
                end_time: endTime.format('hh:mm A'),
                assigned_to: 'Kitchen Team',
                status: 'pending',
                notes: item.special_instructions || '',
                is_header: false
            });

            currentTime = endTime;
        });

        tasks.push({
            id: `complete-${Date.now()}`,
            type: 'completion',
            task: '✅ Quality check and final packaging',
            assigned_to: 'Quality Control',
            status: 'pending',
            deadline: eventDateTime.subtract(15, 'minutes').format('hh:mm A'),
            is_header: false
        });

        return tasks;
    };

    const handleAddToKitchen = async (record) => {
        try {
            await addToKitchenMutation.mutateAsync(record.id);
            const tasks = generateKitchenTasks(record);
            await updateKitchenTaskMutation.mutateAsync({
                orderId: record.id,
                data: { tasks: tasks }
            });
            message.success(`Booking ${formatBookingId(record.booking_no)} added to Kitchen Preparation`);
            refetchOrders();
            refetchKitchenOrders();
        } catch (error) {
            message.error('Failed to add to kitchen');
        }
    };

    const handleViewKitchenPrep = (record) => {
        setSelectedOrder(record);
        const tasks = generateKitchenTasks(record);
        setKitchenTasks(tasks);
        setKitchenCurrentPage(1);
        setKitchenModalVisible(true);
    };

    const handleRemoveFromKitchenPrep = async (record) => {
        try {
            await removeFromKitchenMutation.mutateAsync(record.id);
            message.success(`Booking ${formatBookingId(record.booking_no)} removed from Kitchen Preparation`);
            refetchOrders();
            refetchKitchenOrders();
        } catch (error) {
            message.error('Failed to remove from kitchen');
        }
    };

    const handleUpdateKitchenTask = async (taskId, updates) => {
        try {
            const updatedTasks = kitchenTasks.map(task =>
                task.id === taskId ? { ...task, ...updates } : task
            );
            setKitchenTasks(updatedTasks);

            await updateKitchenTaskMutation.mutateAsync({
                orderId: selectedOrder?.id,
                data: { tasks: updatedTasks }
            });
            message.success('Kitchen task updated');
            refetchOrders();
        } catch (error) {
            message.error('Failed to update kitchen task');
        }
    };

    // ==================== DELIVERY FUNCTIONS ====================
    const generateDeliveryItems = (order) => {
        const items = [];
        const eventDate = dayjs(order.event_date);
        const eventTime = order.event_time || '12:00 PM';

        const [time, modifier] = eventTime.split(' ');
        let [hour, minute] = time.split(':');
        hour = parseInt(hour);
        if (modifier === 'PM' && hour !== 12) hour += 12;
        if (modifier === 'AM' && hour === 12) hour = 0;

        const eventDateTime = eventDate.hour(hour).minute(parseInt(minute || 0));
        const deliveryTime = eventDateTime.subtract(30, 'minutes');

        const foodItems = safeArray(order.menu_items);
        if (foodItems.length > 0) {
            items.push({
                id: `food-${Date.now()}`,
                type: 'food',
                item: '📦 Food Packages',
                quantity: order.guests_count || 0,
                status: 'pending',
                scheduled_time: deliveryTime.format('hh:mm A'),
                items: foodItems.map(f => `${f.name} x${f.quantity}`).join(', '),
                can_edit: true
            });
        }

        const equipmentNeeded = [
            { name: 'Serving Platters', quantity: Math.ceil((order.guests_count || 0) / 10) },
            { name: 'Cutlery Sets', quantity: order.guests_count || 0 },
            { name: 'Glassware', quantity: order.guests_count || 0 },
            { name: 'Napkins', quantity: order.guests_count || 0 },
            { name: 'Tablecloths', quantity: Math.ceil((order.guests_count || 0) / 8) }
        ];

        equipmentNeeded.forEach((eq, idx) => {
            items.push({
                id: `eq-${Date.now()}-${idx}`,
                type: 'equipment',
                item: `🔧 ${eq.name}`,
                quantity: eq.quantity || 1,
                status: 'pending',
                scheduled_time: deliveryTime.subtract(15, 'minutes').format('hh:mm A'),
                notes: eq.notes || '',
                can_edit: true
            });
        });

        items.push({
            id: `driver-${Date.now()}`,
            type: 'driver',
            item: '🚚 Delivery Assignment',
            quantity: 1,
            status: 'pending',
            scheduled_time: deliveryTime.subtract(45, 'minutes').format('hh:mm A'),
            driver: order.delivery_contact_person || 'TBD',
            contact: order.delivery_contact_phone || 'TBD',
            address: order.delivery_address || order.venue || 'TBD',
            can_edit: true
        });

        return items;
    };

    const handleAddToDelivery = async (record) => {
        try {
            await addToDeliveryMutation.mutateAsync(record.id);
            const items = generateDeliveryItems(record);
            await updateDeliveryItemMutation.mutateAsync({
                orderId: record.id,
                data: { items: items }
            });
            message.success(`Booking ${formatBookingId(record.booking_no)} added to Delivery Preparation`);
            refetchOrders();
            refetchDeliveryOrders();
        } catch (error) {
            message.error('Failed to add to delivery');
        }
    };

    const handleViewDeliveryPrep = (record) => {
        setSelectedOrder(record);
        const items = generateDeliveryItems(record);
        setDeliveryItems(items);
        setDeliveryCurrentPage(1);
        setDeliveryModalVisible(true);
        setShowAddDeliveryItem(false);
    };

    const handleRemoveFromDeliveryPrep = async (record) => {
        try {
            await removeFromDeliveryMutation.mutateAsync(record.id);
            message.success(`Booking ${formatBookingId(record.booking_no)} removed from Delivery Preparation`);
            refetchOrders();
            refetchDeliveryOrders();
        } catch (error) {
            message.error('Failed to remove from delivery');
        }
    };

    const handleUpdateDeliveryItem = async (itemId, updates) => {
        try {
            const updatedItems = deliveryItems.map(item =>
                item.id === itemId ? { ...item, ...updates } : item
            );
            setDeliveryItems(updatedItems);

            await updateDeliveryItemMutation.mutateAsync({
                orderId: selectedOrder?.id,
                data: { items: updatedItems }
            });
            message.success('Delivery item updated');
            refetchOrders();
        } catch (error) {
            message.error('Failed to update delivery item');
        }
    };

    const handleAddDeliveryItem = async (values) => {
        const newItem = {
            id: `manual-${Date.now()}`,
            type: values.type || 'equipment',
            item: values.item,
            quantity: values.quantity || 1,
            status: 'pending',
            scheduled_time: values.scheduled_time || dayjs().add(1, 'hour').format('hh:mm A'),
            contact: values.contact || '',
            notes: values.notes || '',
            can_edit: true,
            is_manual: true
        };
        const updatedItems = [...deliveryItems, newItem];
        setDeliveryItems(updatedItems);
        await updateDeliveryItemMutation.mutateAsync({
            orderId: selectedOrder?.id,
            data: { items: updatedItems }
        });
        deliveryItemForm.resetFields();
        setShowAddDeliveryItem(false);
        message.success('Delivery item added');
    };

    // ==================== INGREDIENTS FUNCTIONS ====================
    const computeIngredientsForOrder = async (order) => {
        try {
            const menuItems = safeArray(order.menu_items);
            const ingredientsMap = new Map();

            for (const menuItem of menuItems) {
                const menuItemId = menuItem.menu_item_id || menuItem.id;
                if (!menuItemId) continue;

                try {
                    const recipeResponse = await api.get(`/recipes/${menuItemId}`);
                    let recipeData = recipeResponse.data?.data || recipeResponse.data;

                    let recipeIngredients = [];
                    if (recipeData?.recipe_ingredients) {
                        recipeIngredients = recipeData.recipe_ingredients;
                    } else if (recipeData?.recipeIngredients) {
                        recipeIngredients = recipeData.recipeIngredients;
                    } else if (Array.isArray(recipeData)) {
                        recipeIngredients = recipeData;
                    } else if (recipeData?.data && Array.isArray(recipeData.data)) {
                        recipeIngredients = recipeData.data;
                    }

                    if (!recipeIngredients || recipeIngredients.length === 0) {
                        console.warn(`No recipe ingredients found for ${menuItem.name}`);
                        continue;
                    }

                    for (const ingredient of recipeIngredients) {
                        const ingredientId = ingredient.ingredient_id;
                        const quantityPerPax = parseFloat(ingredient.quantity_per_pax) || 0;
                        const requiredQty = quantityPerPax * (menuItem.quantity || 1);

                        if (ingredientsMap.has(ingredientId)) {
                            const existing = ingredientsMap.get(ingredientId);
                            existing.quantity_needed += requiredQty;
                            existing.menu_items.push({
                                name: menuItem.name,
                                quantity: menuItem.quantity,
                                per_pax: quantityPerPax
                            });
                        } else {
                            let currentStock = 0;
                            let reservedStock = 0;
                            let unitCost = 0;
                            let ingredientName = ingredient.ingredient?.name || ingredient.name || 'Unknown Ingredient';
                            let unit = ingredient.unit || 'kg';

                            try {
                                const stockResponse = await api.get(`/ingredients/${ingredientId}`);
                                const stockData = stockResponse.data?.data || stockResponse.data;
                                currentStock = parseFloat(stockData?.current_quantity) || 0;
                                reservedStock = parseFloat(stockData?.reserved_quantity) || 0;
                                unitCost = parseFloat(stockData?.unit_cost) || 0;
                                ingredientName = stockData?.name || ingredientName;
                                unit = stockData?.unit || unit;
                            } catch (e) {
                                console.warn(`No stock found for ingredient ${ingredientId}`);
                            }

                            ingredientsMap.set(ingredientId, {
                                ingredient_id: ingredientId,
                                name: ingredientName,
                                unit: unit,
                                per_pax: quantityPerPax,
                                quantity_needed: requiredQty,
                                current_stock: currentStock,
                                reserved_quantity: reservedStock,
                                available_stock: currentStock - reservedStock,
                                unit_cost: unitCost,
                                menu_items: [{
                                    name: menuItem.name,
                                    quantity: menuItem.quantity,
                                    per_pax: quantityPerPax
                                }]
                            });
                        }
                    }
                } catch (error) {
                    console.warn(`Failed to fetch recipe for ${menuItem.name}:`, error);
                }
            }

            const computed = Array.from(ingredientsMap.values()).map(ing => {
                const shortage = Math.max(0, ing.quantity_needed - ing.available_stock);
                return {
                    ...ing,
                    shortage: Math.round(shortage * 100) / 100,
                    need_to_buy: shortage > 0,
                    status: shortage > 0 ? 'insufficient' : (ing.available_stock < ing.quantity_needed * 1.2 ? 'low' : 'sufficient')
                };
            });

            return computed;
        } catch (error) {
            console.error('Failed to compute ingredients:', error);
            message.error('Failed to fetch ingredient requirements');
            return [];
        }
    };

    const handleViewIngredients = async (record) => {
        setSelectedOrderForIngredients(record);
        setIngredientsModalVisible(true);
        setComputedIngredients([]);
        setIsComputingIngredients(true);

        try {
            const computed = await computeIngredientsForOrder(record);
            setComputedIngredients(computed);

            const itemsNeedingPurchase = computed.filter(i => i.need_to_buy);
            if (itemsNeedingPurchase.length > 0) {
                message.info(`${itemsNeedingPurchase.length} ingredients need to be purchased. Click "Add to Purchase List" to create requests.`);
            } else {
                message.success('All ingredients are sufficiently stocked!');
            }
        } catch (error) {
            console.error('Failed to compute ingredients:', error);
            message.error('Failed to fetch ingredient requirements');
        } finally {
            setIsComputingIngredients(false);
        }
    };

    const handleAddToShoppingList = async (orderId, ingredients) => {
        try {
            const itemsToAdd = ingredients.filter(i => i.need_to_buy === true && i.shortage > 0);

            if (itemsToAdd.length === 0) {
                message.info('All ingredients are sufficiently stocked. No purchases needed.');
                return;
            }

            let addedCount = 0;
            let failedCount = 0;

            const hideLoading = message.loading(`Adding ${itemsToAdd.length} items to purchase list...`, 0);

            for (const ingredient of itemsToAdd) {
                try {
                    const ingredientId = ingredient.ingredient_id;
                    if (!ingredientId) {
                        console.warn('Missing ingredient_id for:', ingredient);
                        failedCount++;
                        continue;
                    }

                    const quantityToBuy = Math.ceil((ingredient.shortage || 0) * 1.1);

                    if (quantityToBuy <= 0) {
                        continue;
                    }

                    const response = await api.post('/inventory/purchase-requests', {
                        ingredient_id: ingredientId,
                        quantity: quantityToBuy,
                        urgency: ingredient.shortage > 50 ? 'critical' : (ingredient.shortage > 20 ? 'urgent' : 'normal'),
                        notes: `Auto-generated from order ${formatBookingId(selectedOrderForIngredients?.booking_no)} - Needed: ${ingredient.quantity_needed} ${ingredient.unit}, Available: ${ingredient.available_stock}, Shortage: ${ingredient.shortage}`
                    });

                    if (response.data?.success !== false) {
                        addedCount++;
                        console.log(`✅ Added ${ingredient.name} (${quantityToBuy} ${ingredient.unit}) to purchase list`);
                    } else {
                        failedCount++;
                        console.warn(`Failed to add ${ingredient.name}:`, response.data?.message);
                    }
                } catch (e) {
                    console.warn(`Failed to create purchase request for ${ingredient.name}:`, e);
                    failedCount++;
                }
            }

            hideLoading();

            if (addedCount > 0) {
                message.success(`✅ Added ${addedCount} items to purchase list${failedCount > 0 ? ` (${failedCount} failed)` : ''}`);
                setIngredientsModalVisible(false);
                await refetchShoppingList();
                await refetchOrders();
                await refetchStatistics();
            } else {
                message.error('Failed to add items to purchase list. Please check ingredient IDs.');
            }
        } catch (error) {
            console.error('Failed to add to shopping list:', error);
            message.error(error.response?.data?.message || 'Failed to add to shopping list');
        }
    };

    const handleMarkAsPurchased = async (itemId) => {
        if (!itemId) {
            message.error('Invalid item ID');
            return;
        }

        setIsMarkingPurchased(true);

        try {
            let purchaseRequest = null;
            try {
                const response = await api.get(`/inventory/purchase-requests/${itemId}`);
                purchaseRequest = response.data?.data || response.data;
            } catch (e) {
                const item = shoppingList.find(i => i.id === itemId || i.item_id === itemId || i.purchase_request_id === itemId);
                if (item) {
                    purchaseRequest = item;
                }
            }

            if (!purchaseRequest) {
                message.error('Purchase request not found');
                setIsMarkingPurchased(false);
                return;
            }

            const ingredientId = purchaseRequest.ingredient_id || purchaseRequest.ingredient?.ingredient_id;
            const requestedQuantity = parseFloat(purchaseRequest.quantity || purchaseRequest.quantity_needed || 0);

            if (!ingredientId || requestedQuantity <= 0) {
                message.error('Invalid purchase request data');
                setIsMarkingPurchased(false);
                return;
            }

            try {
                const response = await api.post(`/shopping-list/items/${itemId}/purchased`);

                if (response.data?.success !== false) {
                    message.success('✅ Item marked as purchased and stock updated');
                    await refetchShoppingList();
                    await refetchOrders();
                    await refetchStatistics();
                } else {
                    message.error(response.data?.message || 'Failed to mark as purchased');
                }
            } catch (error) {
                console.error('Failed to mark as purchased:', error);

                try {
                    await api.put(`/inventory/purchase-requests/${itemId}`, {
                        status: 'received'
                    });
                    message.success('✅ Item marked as purchased');
                    await refetchShoppingList();
                    await refetchOrders();
                    await refetchStatistics();
                } catch (e) {
                    console.error('Alternative mark as purchased failed:', e);
                    message.error(error.response?.data?.message || 'Failed to mark as purchased');
                }
            }
        } catch (error) {
            console.error('Mark as purchased error:', error);
            message.error(error.response?.data?.message || 'Failed to mark as purchased');
        } finally {
            setIsMarkingPurchased(false);
        }
    };

    // ==================== DROPDOWN MENU ====================
    const getActionMenuItems = (record) => [
        {
            key: 'ingredients',
            label: 'Ingredient Requirements',
            icon: <StockOutlined />,
            onClick: () => handleViewIngredients(record)
        },
        {
            key: 'kitchen',
            label: record.added_to_kitchen ? 'View Kitchen Prep' : 'Add to Kitchen',
            icon: <MenuOutlined />,
            onClick: () => record.added_to_kitchen ? handleViewKitchenPrep(record) : handleAddToKitchen(record)
        },
        {
            key: 'delivery',
            label: record.added_to_delivery ? 'View Delivery Prep' : 'Add to Delivery',
            icon: <TruckOutlined />,
            onClick: () => record.added_to_delivery ? handleViewDeliveryPrep(record) : handleAddToDelivery(record)
        },
        { type: 'divider' },
        {
            key: 'status',
            label: 'Update Status',
            icon: <PlayCircleOutlined />,
            onClick: () => handleUpdateStatus(record)
        },
        { type: 'divider' },
        {
            key: 'edit',
            label: 'Edit Order',
            icon: <EditOutlined />,
            onClick: () => handleEditOrder(record)
        },
        {
            key: 'delete',
            label: 'Delete Order',
            icon: <DeleteOutlined />,
            danger: true,
            onClick: () => handleDeleteOrder(record)
        }
    ];

    // ==================== TABLE COLUMNS ====================
    const orderColumns = [
        {
            title: 'BOOKING ID',
            dataIndex: 'booking_no',
            key: 'booking_no',
            width: 140,
            fixed: 'left',
            render: (text) => <span className="om-id-text">{formatBookingId(text)}</span>
        },
        {
            title: 'CUSTOMER',
            key: 'customer',
            width: 220,
            render: (_, record) => (
                <div className="om-customer-cell">
                    <div className="om-customer-name">{safeString(record.customer_name)}</div>
                    <div className="om-customer-contact"><MailOutlined /> {safeString(record.customer_email, 'No email')}</div>
                    <div className="om-customer-contact"><PhoneOutlined /> {safeString(record.customer_phone, 'No phone')}</div>
                </div>
            )
        },
        {
            title: 'EVENT DETAILS',
            key: 'event',
            width: 220,
            render: (_, record) => (
                <div className="om-event-cell">
                    <div><CalendarOutlined /> {safeString(record.event_date)}</div>
                    <div><ScheduleOutlined /> {safeString(record.event_time)}</div>
                    <div><EnvironmentOutlined /> {safeString(record.venue, 'N/A')}</div>
                </div>
            )
        },
        {
            title: 'PAX',
            dataIndex: 'guests_count',
            key: 'pax',
            width: 80,
            align: 'center',
            render: (v) => <span className="om-pax-number"><TeamOutlined /> {safeNumber(v)}</span>
        },
        {
            title: 'AMOUNT',
            dataIndex: 'total_amount',
            key: 'amount',
            width: 140,
            align: 'right',
            render: (v) => <span className="om-amount">{formatCurrency(v)}</span>
        },
        {
            title: 'STATUS',
            dataIndex: 'order_status',
            key: 'status',
            width: 140,
            align: 'center',
            render: (s) => {
                const config = getStatusConfig(s);
                return (
                    <span className="om-status" style={{ color: config.color, background: config.bg }}>
                        {config.icon} {config.text}
                    </span>
                );
            }
        },
        {
            title: 'ACTION',
            key: 'actions',
            width: 160,
            fixed: 'right',
            render: (_, record) => (
                <div className="om-action-group">
                    <Tooltip title="View Details">
                        <button className="om-action-icon view" onClick={() => handleViewOrderDetails(record)}>
                            <EyeOutlined />
                        </button>
                    </Tooltip>
                    <Tooltip title="Edit Order">
                        <button className="om-action-icon edit" onClick={() => handleEditOrder(record)}>
                            <EditOutlined />
                        </button>
                    </Tooltip>
                    <Dropdown menu={{ items: getActionMenuItems(record) }} placement="bottomRight">
                        <button className="om-action-icon more">
                            <MoreOutlined />
                        </button>
                    </Dropdown>
                </div>
            )
        }
    ];

    const kitchenPrepColumns = [
        {
            title: 'BOOKING ID',
            key: 'booking_id',
            width: 140,
            render: (_, record) => <span className="om-id-text">{formatBookingId(record.booking_no)}</span>
        },
        { title: 'Customer', dataIndex: 'customer_name', width: 180 },
        { title: 'Event Date', dataIndex: 'event_date', width: 110 },
        { title: 'Event Time', dataIndex: 'event_time', width: 100 },
        { title: 'Venue', dataIndex: 'venue', width: 200, ellipsis: true },
        { title: 'Pax', dataIndex: 'guests_count', width: 70, align: 'center', render: (v) => <span className="om-pax-number">{safeNumber(v)}</span> },
        {
            title: 'Action',
            width: 150,
            render: (_, r) => (
                <div className="om-action-group">
                    <Tooltip title="View Kitchen Prep Tasks">
                        <button className="om-action-icon view" onClick={() => handleViewKitchenPrep(r)}>
                            <EyeOutlined />
                        </button>
                    </Tooltip>
                    <Tooltip title="Remove from Kitchen">
                        <button className="om-action-icon delete" onClick={() => handleRemoveFromKitchenPrep(r)}>
                            <DeleteOutlined />
                        </button>
                    </Tooltip>
                </div>
            )
        }
    ];

    const deliveryPrepColumns = [
        {
            title: 'BOOKING ID',
            key: 'booking_id',
            width: 140,
            render: (_, record) => <span className="om-id-text">{formatBookingId(record.booking_no)}</span>
        },
        { title: 'Customer', dataIndex: 'customer_name', width: 180 },
        { title: 'Delivery Date', dataIndex: 'event_date', width: 110 },
        { title: 'Delivery Address', dataIndex: 'delivery_address', width: 200, ellipsis: true, render: (v) => v || record.venue },
        { title: 'Contact', dataIndex: 'delivery_contact_phone', width: 120 },
        {
            title: 'Action',
            width: 150,
            render: (_, r) => (
                <div className="om-action-group">
                    <Tooltip title="View Delivery Prep Items">
                        <button className="om-action-icon view" onClick={() => handleViewDeliveryPrep(r)}>
                            <EyeOutlined />
                        </button>
                    </Tooltip>
                    <Tooltip title="Remove from Delivery">
                        <button className="om-action-icon delete" onClick={() => handleRemoveFromDeliveryPrep(r)}>
                            <DeleteOutlined />
                        </button>
                    </Tooltip>
                </div>
            )
        }
    ];

    // ==================== PAGINATION RENDER ====================
    const renderPaginationItem = (_, type, originalElement) => {
        if (type === 'prev') {
            return (
                <Button className="om-pagination-navigation-button" size="small" icon={<LeftOutlined />}>
                    Previous
                </Button>
            );
        }

        if (type === 'next') {
            return (
                <Button className="om-pagination-navigation-button" size="small">
                    Next <RightOutlined />
                </Button>
            );
        }

        return originalElement;
    };

    const renderEmptyPaginationFooter = (label) => {
        return (
            <div className="om-empty-pagination-footer">
                <span className="om-empty-pagination-total">Total 0 {label}</span>
                <div className="om-empty-pagination-controls">
                    <Button className="om-pagination-navigation-button" size="small" icon={<LeftOutlined />} disabled>
                        Previous
                    </Button>
                    <button type="button" className="om-empty-pagination-current-page" disabled>1</button>
                    <Button className="om-pagination-navigation-button" size="small" disabled>
                        Next <RightOutlined />
                    </Button>
                </div>
            </div>
        );
    };

    // ==================== CSS CLASSES ====================
    const containerClass = `om-container ${isDarkMode ? 'om-dark-mode' : ''}`;
    const headerClass = `om-header ${isDarkMode ? 'om-header-dark' : ''}`;
    const mainCardClass = `om-main-card ${isDarkMode ? 'om-main-card-dark' : ''}`;
    const filtersClass = `om-filters ${isDarkMode ? 'om-filters-dark' : ''}`;
    const filterGroupClass = `om-filter-group ${isDarkMode ? 'om-filter-group-dark' : ''}`;
    const dateDisplayClass = `om-date-display ${isDarkMode ? 'om-date-display-dark' : ''}`;
    const tableClass = `om-table ${isDarkMode ? 'om-table-dark' : ''}`;
    const kpiCardClass = `om-kpi-card ${isDarkMode ? 'om-kpi-card-dark' : ''}`;
    const shoppingListClass = `om-shopping-list-section ${isDarkMode ? 'om-shopping-list-section-dark' : ''}`;
    const alertClass = `om-info-alert ${isDarkMode ? 'om-alert-dark' : ''}`;
    const modalClass = `om-modal-clean ${isDarkMode ? 'om-modal-dark' : ''}`;
    const isLoading = ordersLoading;

    const today = new Date();
    const formattedDate = today.toLocaleDateString('en-US', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' });

    // ============================================================
    // RENDER
    // ============================================================
    return (
        <ConfigProvider
            theme={{
                algorithm: isDarkMode ? antdTheme.darkAlgorithm : antdTheme.defaultAlgorithm,
                token: {
                    colorPrimary: '#3b82f6',
                    colorBgContainer: isDarkMode ? '#1e293b' : '#ffffff',
                    colorBorderSecondary: isDarkMode ? '#334155' : '#e2e8f0',
                    colorText: isDarkMode ? '#e2e8f0' : '#1e293b',
                    colorTextSecondary: isDarkMode ? '#94a3b8' : '#64748b',
                    borderRadius: 12,
                    fontFamily: "'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif",
                },
                components: {
                    Table: {
                        headerBg: isDarkMode ? '#0f172a' : '#f8fafc',
                        headerColor: isDarkMode ? '#cbd5e1' : '#1e293b',
                        headerBorderRadius: 0,
                    },
                    Card: {
                        borderRadiusLG: 16,
                    },
                    Modal: {
                        borderRadiusLG: 20,
                    },
                    Button: {
                        borderRadius: 10,
                    },
                    Input: {
                        borderRadius: 10,
                    },
                    Select: {
                        borderRadius: 10,
                    },
                }
            }}
        >
            <div className={containerClass}>
                {/* ==================== HEADER ==================== */}
                <div className={headerClass}>
                    <div className="om-header-left">
                        <Tooltip title="Order Management System">
                            <div className="om-logo-icon"><TrophyOutlined /></div>
                        </Tooltip>
                        <div className="om-header-info">
                            <h1>Order Management System</h1>
                            <span>ENTERPRISE OPERATIONS</span>
                        </div>
                    </div>
                    <div className="om-header-right">
                        <div className={dateDisplayClass}>
                            <CalendarOutlined />
                            <span>{formattedDate}</span>
                        </div>
                        <Divider type="vertical" style={{ height: 28 }} />
                        <Tooltip title="Refresh all data">
                            <Button icon={<ReloadOutlined />} onClick={() => {
                                refetchOrders();
                                refetchStatistics();
                                refetchKitchenOrders();
                                refetchDeliveryOrders();
                                refetchShoppingList();
                                message.success('Data refreshed');
                            }}>Refresh</Button>
                        </Tooltip>
                        <Tooltip title="Export to Excel">
                            <Button icon={<ExportOutlined />} onClick={exportOrders}>Export</Button>
                        </Tooltip>
                        <Tooltip title="Print current view">
                            <Button icon={<PrinterOutlined />} onClick={() => window.print()}>Print</Button>
                        </Tooltip>
                        <Tooltip title="Create new order">
                            <Button type="primary" icon={<PlusOutlined />} onClick={() => setCreateOrderModalVisible(true)}>
                                Create Order
                            </Button>
                        </Tooltip>
                    </div>
                </div>

                {/* ==================== KPI CARDS ==================== */}
                <div className="om-kpi-grid">
                    <div className={kpiCardClass}>
                        <div className="om-kpi-icon blue"><ShoppingOutlined /></div>
                        <div className="om-kpi-stats">
                            <div className="om-kpi-value">{totalOrders}</div>
                            <div className="om-kpi-label">Total Bookings</div>
                        </div>
                        <div className="om-kpi-trend up">+12%</div>
                    </div>
                    <div className={kpiCardClass}>
                        <div className="om-kpi-icon orange"><ClockCircleOutlined /></div>
                        <div className="om-kpi-stats">
                            <div className="om-kpi-value">{stats.pending_orders || 0}</div>
                            <div className="om-kpi-label">Pending Approvals</div>
                        </div>
                        <div className="om-kpi-trend warning">Needs attention</div>
                    </div>
                    <div className={kpiCardClass}>
                        <div className="om-kpi-icon green"><WalletOutlined /></div>
                        <div className="om-kpi-stats">
                            <div className="om-kpi-value">{formatCurrency(totalRevenue)}</div>
                            <div className="om-kpi-label">Total Revenue</div>
                        </div>
                        <div className="om-kpi-trend up">+8%</div>
                    </div>
                    <div className={kpiCardClass}>
                        <div className="om-kpi-icon purple"><DollarOutlined /></div>
                        <div className="om-kpi-stats">
                            <div className="om-kpi-value">{formatCurrency(stats.outstanding_balance || 0)}</div>
                            <div className="om-kpi-label">Outstanding Balance</div>
                        </div>
                        <div className="om-kpi-trend warning">Pending</div>
                    </div>
                </div>

                {/* ==================== MAIN CARD ==================== */}
                <Card className={mainCardClass} bordered={false}>
                    <Tabs
                        activeKey={activeMainTab}
                        onChange={(key) => {
                            setActiveMainTab(key);
                            if (key === 'orders') {
                                refetchOrders();
                                refetchStatistics();
                            } else if (key === 'kitchen') {
                                refetchKitchenOrders();
                            } else if (key === 'delivery') {
                                refetchDeliveryOrders();
                            } else if (key === 'ingredients') {
                                refetchShoppingList();
                            }
                        }}
                        className="om-tabs"
                        destroyInactiveTabPane={true}
                    >
                        {/* ==================== ORDERS TAB ==================== */}
                        <TabPane tab={<span><OrderedListOutlined /> All Orders</span>} key="orders">
                            <div className="om-tab-content">
                                <div className={filtersClass}>
                                    <div className={filterGroupClass}>
                                        <FilterOutlined />
                                        <Select value={filterStatus} onChange={setFilterStatus} className="om-filter-select" placeholder="Status">
                                            <Option value="all">All Status</Option>
                                            <Option value="pending">Pending</Option>
                                            <Option value="preparing">Preparing</Option>
                                            <Option value="ready">Ready</Option>
                                            <Option value="ongoing">Ongoing</Option>
                                            <Option value="completed">Completed</Option>
                                            <Option value="cancelled">Cancelled</Option>
                                        </Select>
                                    </div>
                                    <div className={filterGroupClass}>
                                        <CalendarOutlined />
                                        <DatePicker onChange={setSelectedDate} placeholder="Select Date" format="YYYY-MM-DD" allowClear className="om-date-picker" />
                                    </div>
                                    <div className={`${filterGroupClass} om-search`}>
                                        <SearchOutlined />
                                        <Input
                                            placeholder="Search by Booking ID or customer name..."
                                            value={searchText}
                                            onChange={(e) => setSearchText(e.target.value)}
                                            allowClear
                                            className="om-search-input"
                                        />
                                    </div>
                                </div>

                                <div className="om-table-container">
                                    <Spin spinning={isLoading} indicator={<LoadingOutlined spin />}>
                                        <Table
                                            columns={orderColumns}
                                            dataSource={orders}
                                            rowKey="id"
                                            className={tableClass}
                                            scroll={{ x: 1300 }}
                                            footer={
                                                orders.length === 0
                                                    ? () => renderEmptyPaginationFooter('orders')
                                                    : undefined
                                            }
                                            pagination={{
                                                current: currentPage,
                                                pageSize: 8,
                                                total: ordersData?.total || orders.length,
                                                showSizeChanger: false,
                                                showTotal: (total) => `Total ${total} orders`,
                                                itemRender: renderPaginationItem,
                                                onChange: (page, size) => { setCurrentPage(page); if (size) setPageSize(size); }
                                            }}
                                        />
                                    </Spin>
                                </div>
                            </div>
                        </TabPane>

                        {/* ==================== KITCHEN PREP TAB ==================== */}
                        <TabPane tab={<span><MenuOutlined /> Kitchen Preparation</span>} key="kitchen">
                            <div className="om-tab-content">
                                <Alert
                                    message="Kitchen Production Management"
                                    description="Track and manage all kitchen preparation activities. Orders added to kitchen will appear here."
                                    type="info"
                                    showIcon
                                    className={alertClass}
                                />
                                <div className="om-table-container">
                                    {(!kitchenOrders || kitchenOrders.length === 0) ? (
                                        <Empty description="No orders in kitchen preparation. Click 'Add to Kitchen' from any order to add." />
                                    ) : (
                                        <Table
                                            dataSource={kitchenOrders}
                                            columns={kitchenPrepColumns}
                                            rowKey="id"
                                            className={tableClass}
                                            footer={
                                                kitchenOrders.length === 0
                                                    ? () => renderEmptyPaginationFooter('kitchen orders')
                                                    : undefined
                                            }
                                            pagination={{
                                                current: kitchenCurrentPage,
                                                pageSize: 5,
                                                total: kitchenOrders.length,
                                                showSizeChanger: false,
                                                showTotal: (total) => `Total ${total} orders`,
                                                itemRender: renderPaginationItem,
                                                onChange: (page, size) => { setKitchenCurrentPage(page); if (size) setKitchenPageSize(size); }
                                            }}
                                        />
                                    )}
                                </div>
                            </div>
                        </TabPane>

                        {/* ==================== DELIVERY PREP TAB ==================== */}
                        <TabPane tab={<span><TruckOutlined /> Delivery Preparation</span>} key="delivery">
                            <div className="om-tab-content">
                                <Alert
                                    message="Delivery Coordination Center"
                                    description="Monitor delivery schedules and equipment readiness. Orders added to delivery will appear here."
                                    type="info"
                                    showIcon
                                    className={alertClass}
                                />
                                <div className="om-table-container">
                                    {(!deliveryOrders || deliveryOrders.length === 0) ? (
                                        <Empty description="No orders in delivery preparation. Click 'Add to Delivery' from any order to add." />
                                    ) : (
                                        <Table
                                            dataSource={deliveryOrders}
                                            columns={deliveryPrepColumns}
                                            rowKey="id"
                                            className={tableClass}
                                            footer={
                                                deliveryOrders.length === 0
                                                    ? () => renderEmptyPaginationFooter('delivery orders')
                                                    : undefined
                                            }
                                            pagination={{
                                                current: deliveryCurrentPage,
                                                pageSize: 5,
                                                total: deliveryOrders.length,
                                                showSizeChanger: false,
                                                showTotal: (total) => `Total ${total} orders`,
                                                itemRender: renderPaginationItem,
                                                onChange: (page, size) => { setDeliveryCurrentPage(page); if (size) setDeliveryPageSize(size); }
                                            }}
                                        />
                                    )}
                                </div>
                            </div>
                        </TabPane>

                        {/* ==================== INGREDIENTS TAB ==================== */}
                        <TabPane tab={<span><StockOutlined /> Ingredients Management</span>} key="ingredients">
                            <div className="om-tab-content">
                                <Alert
                                    message="Ingredients Management"
                                    description="All computed ingredients that need to be purchased are shown below. Mark items as purchased when done."
                                    type="info"
                                    showIcon
                                    className={alertClass}
                                />

                                <div className={shoppingListClass}>
                                    <div className="om-section-header">
                                        <div className="om-section-header-left">
                                            <Title level={5}><ShoppingCartOutlined /> Ingredients to Purchase</Title>
                                        </div>
                                        <div className="om-section-header-right">
                                            <Badge
                                                count={shoppingList?.filter(item => item.status === 'pending').length || 0}
                                                style={{ backgroundColor: '#ef4444' }}
                                                className="om-section-badge"
                                            />
                                            <Button
                                                size="small"
                                                icon={<ReloadOutlined />}
                                                onClick={() => refetchShoppingList()}
                                                style={{ marginLeft: 8 }}
                                            >
                                                Refresh
                                            </Button>
                                        </div>
                                    </div>

                                    <div className="om-shopping-table-wrapper">
                                        {shoppingList && shoppingList.length > 0 ? (
                                            <Table
                                                dataSource={shoppingList}
                                                rowKey={(record) => record.id || record.item_id || record.purchase_request_id || Math.random().toString()}
                                                columns={[
                                                    {
                                                        title: 'Booking ID',
                                                        key: 'booking_no',
                                                        width: 150,
                                                        render: (_, record) => {
                                                            let bookingNo = 'N/A';
                                                            if (record.order) {
                                                                bookingNo = record.order.booking_no || record.order.order_number || 'N/A';
                                                            } else if (record.booking) {
                                                                bookingNo = record.booking.booking_no || 'N/A';
                                                            } else if (record.booking_no) {
                                                                bookingNo = record.booking_no;
                                                            } else if (record.order_number) {
                                                                bookingNo = record.order_number;
                                                            }
                                                            if (bookingNo === 'N/A' && record.notes) {
                                                                const match = record.notes.match(/order (BK-\d+)/i);
                                                                if (match) bookingNo = match[1];
                                                            }
                                                            return <span className="om-id-text">{formatBookingId(bookingNo)}</span>;
                                                        }
                                                    },
                                                    {
                                                        title: 'Ingredient',
                                                        key: 'ingredient_name',
                                                        width: 200,
                                                        render: (_, record) => {
                                                            const name = record.ingredient_name || record.ingredient?.name || record.name || 'Unknown Ingredient';
                                                            const customerName = record.order?.customer_name || record.customer_name || record.booking?.customer_name || 'Unknown';
                                                            return (
                                                                <div>
                                                                    <div style={{ fontWeight: 500 }}>{name}</div>
                                                                    <div style={{ fontSize: 12, color: '#64748b' }}>From: {customerName}</div>
                                                                </div>
                                                            );
                                                        }
                                                    },
                                                    {
                                                        title: 'Quantity',
                                                        key: 'quantity',
                                                        width: 120,
                                                        align: 'right',
                                                        render: (_, record) => {
                                                            const qty = record.quantity_needed || record.quantity || record.requested_quantity || 0;
                                                            const unit = record.unit || record.ingredient?.unit || 'units';
                                                            return <span style={{ fontWeight: 600 }}>{qty} {unit}</span>;
                                                        }
                                                    },
                                                    {
                                                        title: 'Unit',
                                                        key: 'unit',
                                                        width: 80,
                                                        render: (_, record) => record.unit || record.ingredient?.unit || 'units'
                                                    },
                                                    {
                                                        title: 'Urgency',
                                                        key: 'urgency',
                                                        width: 100,
                                                        align: 'center',
                                                        render: (_, record) => {
                                                            const urgency = record.urgency || 'normal';
                                                            return (
                                                                <Tag color={urgency === 'critical' ? 'red' : urgency === 'urgent' ? 'orange' : 'blue'}>
                                                                    {urgency.charAt(0).toUpperCase() + urgency.slice(1)}
                                                                </Tag>
                                                            );
                                                        }
                                                    },
                                                    {
                                                        title: 'Status',
                                                        key: 'status',
                                                        width: 100,
                                                        align: 'center',
                                                        render: (_, record) => {
                                                            const status = record.status || 'pending';
                                                            return (
                                                                <Tag color={status === 'pending' ? 'warning' : 'success'}>
                                                                    {status === 'pending' ? 'Pending' : 'Purchased'}
                                                                </Tag>
                                                            );
                                                        }
                                                    },
                                                    {
                                                        title: 'Action',
                                                        key: 'action',
                                                        width: 160,
                                                        render: (_, record) => {
                                                            const itemId = record.id || record.item_id || record.purchase_request_id;
                                                            const status = record.status || 'pending';

                                                            if (status === 'pending' && itemId) {
                                                                return (
                                                                    <Tooltip title="Mark as Purchased">
                                                                        <Button
                                                                            size="small"
                                                                            type="primary"
                                                                            icon={<CheckCircleOutlined />}
                                                                            onClick={() => handleMarkAsPurchased(itemId)}
                                                                            loading={isMarkingPurchased}
                                                                            style={{ background: '#10b981', borderColor: '#10b981' }}
                                                                        >
                                                                            Mark Purchased
                                                                        </Button>
                                                                    </Tooltip>
                                                                );
                                                            } else if (status === 'purchased' || status === 'received') {
                                                                return (
                                                                    <Tooltip title="Already Purchased">
                                                                        <span style={{ color: '#10b981' }}>
                                                                            <CheckCircleOutlined /> Purchased
                                                                        </span>
                                                                    </Tooltip>
                                                                );
                                                            }
                                                            return <span style={{ color: '#64748b' }}>No action</span>;
                                                        }
                                                    }
                                                ]}
                                                pagination={{ pageSize: 10 }}
                                                className="om-shopping-table"
                                                size="middle"
                                            />
                                        ) : (
                                            <Empty
                                                description="No items need to be purchased. Click 'Ingredient Requirements' on any order to calculate ingredients."
                                                image={Empty.PRESENTED_IMAGE_SIMPLE}
                                            >
                                                <Button
                                                    type="primary"
                                                    icon={<StockOutlined />}
                                                    onClick={() => {
                                                        const orderWithItems = orders.find(o => o.menu_items?.length > 0);
                                                        if (orderWithItems) {
                                                            handleViewIngredients(orderWithItems);
                                                        } else {
                                                            message.info('Please find an order with menu items to compute ingredients');
                                                        }
                                                    }}
                                                >
                                                    Compute Ingredients
                                                </Button>
                                            </Empty>
                                        )}
                                    </div>
                                </div>
                            </div>
                        </TabPane>
                    </Tabs>
                </Card>

                {/* ============================================================
                    ORDER DETAILS MODAL - MATCHING IMAGE STYLE
                ============================================================ */}
                <Modal
                    title={
                        <div className="om-modal-header-clean">
                            <div className="om-modal-title-icon"><EyeOutlined /></div>
                            <div className="om-modal-title-text">Order Details</div>
                            <div className="om-modal-badge">{formatBookingId(selectedOrder?.booking_no)}</div>
                        </div>
                    }
                    open={orderDetailsModalVisible}
                    onCancel={() => setOrderDetailsModalVisible(false)}
                    width={800}
                    className={modalClass}
                    footer={
                        <div className="om-modal-footer-simple">
                            <Button type="primary" onClick={() => setOrderDetailsModalVisible(false)}>
                                Close
                            </Button>
                        </div>
                    }
                    bodyStyle={{ padding: 0, maxHeight: 'none', overflow: 'visible' }}
                >
                    {selectedOrder && (
                        <div className="om-modal-clean-content">
                            {/* Header Tags */}
                            <div className="om-modal-header-tags">
                                <span className="om-id-text">{formatBookingId(selectedOrder.booking_no)}</span>
                                <span className="om-status" style={{ color: getStatusConfig(selectedOrder.order_status).color, background: getStatusConfig(selectedOrder.order_status).bg }}>
                                    {getStatusConfig(selectedOrder.order_status).icon} {getStatusConfig(selectedOrder.order_status).text}
                                </span>
                            </div>

                            {/* Customer Information */}
                            <div className="om-clean-section">
                                <div className="om-clean-section-title"><UserOutlined /> Customer Information</div>
                                <div className="om-clean-grid">
                                    <div><span className="om-clean-label">Name:</span> {selectedOrder.customer_name}</div>
                                    <div><span className="om-clean-label">Email:</span> {selectedOrder.customer_email}</div>
                                    <div><span className="om-clean-label">Phone:</span> {selectedOrder.customer_phone}</div>
                                    <div><span className="om-clean-label">Address:</span> {selectedOrder.customer_address || 'N/A'}</div>
                                </div>
                            </div>

                            {/* Event Information */}
                            <div className="om-clean-section">
                                <div className="om-clean-section-title"><CalendarOutlined /> Event Information</div>
                                <div className="om-clean-grid">
                                    <div><span className="om-clean-label">Event Type:</span> {selectedOrder.event_type}</div>
                                    <div><span className="om-clean-label">Event Date:</span> {selectedOrder.event_date} at {selectedOrder.event_time}</div>
                                    <div><span className="om-clean-label">Venue:</span> {selectedOrder.venue}</div>
                                    <div><span className="om-clean-label">Guests:</span> {selectedOrder.guests_count} PAX</div>
                                </div>
                            </div>

                            {/* Menu Items - SCROLLABLE */}
                            <div className="om-clean-section">
                                <div className="om-clean-section-title"><ShoppingOutlined /> Menu Items</div>
                                <div className="om-menu-items-table">
                                    <div className="om-menu-header">
                                        <span>Item Name</span>
                                        <span>Quantity</span>
                                        <span>Unit Price</span>
                                        <span>Subtotal</span>
                                    </div>
                                    {getMenuItems(selectedOrder).map((item, idx) => (
                                        <div key={idx} className="om-menu-row">
                                            <span className="om-menu-name">{item.name}</span>
                                            <span className="om-menu-qty">{item.quantity}</span>
                                            <span className="om-menu-price">{formatCurrency(item.price)}</span>
                                            <span className="om-menu-subtotal">{formatCurrency(item.subtotal)}</span>
                                        </div>
                                    ))}
                                    <div className="om-menu-total">
                                        <span>Total Amount</span>
                                        <strong>{formatCurrency(selectedOrder.total_amount)}</strong>
                                    </div>
                                </div>
                            </div>
                        </div>
                    )}
                </Modal>

                {/* ============================================================
                    EDIT ORDER MODAL
                ============================================================ */}
                <Modal
                    title={
                        <div className="om-modal-header-clean">
                            <div className="om-modal-title-icon"><EditOutlined /></div>
                            <div className="om-modal-title-text">Edit Order</div>
                            <div className="om-modal-badge">{formatBookingId(selectedOrder?.booking_no)}</div>
                        </div>
                    }
                    open={editOrderModalVisible}
                    onCancel={() => {
                        setEditOrderModalVisible(false);
                        setSelectedOrder(null);
                        editOrderForm.resetFields();
                    }}
                    width={700}
                    className={modalClass}
                    footer={
                        <div className="om-modal-footer-simple">
                            <Button onClick={() => {
                                setEditOrderModalVisible(false);
                                setSelectedOrder(null);
                                editOrderForm.resetFields();
                            }}>Cancel</Button>
                            <Button type="primary" onClick={() => editOrderForm.submit()}>Save Changes</Button>
                        </div>
                    }
                    bodyStyle={{ padding: 0, maxHeight: 'none', overflow: 'visible' }}
                >
                    <div className="om-modal-clean-content">
                        <Form form={editOrderForm} layout="vertical" onFinish={handleUpdateOrder}>
                            <Row gutter={16}>
                                <Col span={12}>
                                    <Form.Item name="customer_name" label="Customer Name" rules={[{ required: true }]}>
                                        <Input />
                                    </Form.Item>
                                </Col>
                                <Col span={12}>
                                    <Form.Item name="customer_email" label="Email" rules={[{ required: true, type: 'email' }]}>
                                        <Input />
                                    </Form.Item>
                                </Col>
                            </Row>
                            <Row gutter={16}>
                                <Col span={12}>
                                    <Form.Item name="customer_phone" label="Phone">
                                        <Input />
                                    </Form.Item>
                                </Col>
                                <Col span={12}>
                                    <Form.Item name="customer_address" label="Address">
                                        <Input />
                                    </Form.Item>
                                </Col>
                            </Row>
                            <Row gutter={16}>
                                <Col span={8}>
                                    <Form.Item name="event_type" label="Event Type" rules={[{ required: true }]}>
                                        <Select>
                                            <Option value="Wedding">Wedding</Option>
                                            <Option value="Birthday">Birthday</Option>
                                            <Option value="Corporate">Corporate</Option>
                                            <Option value="Anniversary">Anniversary</Option>
                                            <Option value="Seminar">Seminar</Option>
                                        </Select>
                                    </Form.Item>
                                </Col>
                                <Col span={8}>
                                    <Form.Item name="event_date" label="Event Date" rules={[{ required: true }]}>
                                        <DatePicker style={{ width: '100%' }} />
                                    </Form.Item>
                                </Col>
                                <Col span={8}>
                                    <Form.Item name="event_time" label="Event Time" rules={[{ required: true }]}>
                                        <Select>
                                            <Option value="8:00 AM">8:00 AM</Option>
                                            <Option value="9:00 AM">9:00 AM</Option>
                                            <Option value="10:00 AM">10:00 AM</Option>
                                            <Option value="11:00 AM">11:00 AM</Option>
                                            <Option value="12:00 PM">12:00 PM</Option>
                                            <Option value="1:00 PM">1:00 PM</Option>
                                            <Option value="2:00 PM">2:00 PM</Option>
                                            <Option value="3:00 PM">3:00 PM</Option>
                                            <Option value="4:00 PM">4:00 PM</Option>
                                            <Option value="5:00 PM">5:00 PM</Option>
                                            <Option value="6:00 PM">6:00 PM</Option>
                                            <Option value="7:00 PM">7:00 PM</Option>
                                        </Select>
                                    </Form.Item>
                                </Col>
                            </Row>
                            <Row gutter={16}>
                                <Col span={12}>
                                    <Form.Item name="venue" label="Venue" rules={[{ required: true }]}>
                                        <Input placeholder="Event location" />
                                    </Form.Item>
                                </Col>
                                <Col span={12}>
                                    <Form.Item name="guests_count" label="Number of Guests" rules={[{ required: true }]}>
                                        <InputNumber min={1} style={{ width: '100%' }} placeholder="Total pax" />
                                    </Form.Item>
                                </Col>
                            </Row>
                            <Form.Item name="total_amount" label="Total Amount" rules={[{ required: true }]}>
                                <InputNumber min={0} style={{ width: '100%' }} prefix="₱" placeholder="0.00" />
                            </Form.Item>
                            <Form.Item name="special_requests" label="Special Requests">
                                <TextArea rows={3} placeholder="Any special requests or notes..." />
                            </Form.Item>
                        </Form>
                    </div>
                </Modal>

                {/* ============================================================
                    KITCHEN PREP MODAL
                ============================================================ */}
              <Modal
    title={
        <div className="om-modal-header-clean">
            <div className="om-modal-title-icon"><MenuOutlined /></div>
            <div className="om-modal-title-text">Kitchen Production Tasks</div>
            <div className="om-modal-badge">{formatBookingId(selectedOrder?.booking_no)}</div>
        </div>
    }
    open={kitchenModalVisible}
    onCancel={() => setKitchenModalVisible(false)}
    width={1100}
    className={modalClass}
    footer={
        <div className="om-modal-footer-simple">
            <Button type="primary" icon={<PrinterOutlined />} onClick={() => printKitchenTasks(kitchenTasks)}>
                Print Tasks
            </Button>
            <Button onClick={() => setKitchenModalVisible(false)}>Close</Button>
        </div>
    }
    bodyStyle={{ padding: 0, maxHeight: 'none', overflow: 'visible' }}
>
    <div className="om-modal-clean-content">
        {/* ===== HEADER SECTION ===== */}
        {kitchenTasks.filter(t => t.is_header).map((header, idx) => (
            <div key={idx} className="om-kitchen-header-card">
                <div className="om-kitchen-header-icon"><CrownOutlined /></div>
                <div className="om-kitchen-header-info">
                    <h3 className="om-kitchen-header-title">{header.task}</h3>
                    <div className="om-kitchen-header-meta">
                        <span><UserOutlined /> {header.customer}</span>
                        <span><CalendarOutlined /> {header.event_date}</span>
                        <span><ScheduleOutlined /> {header.event_time}</span>
                        <span><EnvironmentOutlined /> {header.venue}</span>
                        <span><TeamOutlined /> {header.guests} PAX</span>
                    </div>
                </div>
                <div className="om-kitchen-header-status">
                    <Badge status="processing" text="In Progress" />
                    <span className="om-task-count">
                        {kitchenTasks.filter(t => !t.is_header).length} Tasks
                    </span>
                </div>
            </div>
        ))}

        {/* ===== TASKS TABLE ===== */}
        <div className="om-kitchen-table-wrapper">
            <div className="om-kitchen-table-header">
                <div className="om-col-task">Task</div>
                <div className="om-col-qty">Qty</div>
                <div className="om-col-servings">Servings</div>
                <div className="om-col-time">Start</div>
                <div className="om-col-time">End</div>
                <div className="om-col-assignee">Assigned To</div>
                <div className="om-col-status">Status</div>
                <div className="om-col-action">Action</div>
            </div>
            <div className="om-kitchen-table-body">
                {kitchenTasks
                    .filter(t => !t.is_header)
                    .slice((kitchenCurrentPage - 1) * kitchenPageSize, kitchenCurrentPage * kitchenPageSize)
                    .map((task, idx) => (
                        <div key={task.id || idx} className={`om-kitchen-table-row ${task.type === 'completion' ? 'completion-row' : ''}`}>
                            <div className="om-col-task">
                                <div className="om-task-icon">
                                    {task.type === 'menu_item' ? <ForkOutlined /> : <CheckCircleOutlined />}
                                </div>
                                <span className="om-task-name">{task.task}</span>
                            </div>
                            <div className="om-col-qty">{task.quantity || '-'}</div>
                            <div className="om-col-servings">{task.servings || '-'}</div>
                            <div className="om-col-time">{task.start_time || '-'}</div>
                            <div className="om-col-time">{task.end_time || task.deadline || '-'}</div>
                            <div className="om-col-assignee">{task.assigned_to || 'Kitchen Team'}</div>
                            <div className="om-col-status">
                                <Tag color={task.status === 'completed' ? 'success' : task.status === 'in_progress' ? 'processing' : 'warning'}>
                                    {task.status === 'completed' ? '✅ Done' : task.status === 'in_progress' ? '🔄 In Progress' : '⏳ Pending'}
                                </Tag>
                            </div>
                            <div className="om-col-action">
                                <Select
                                    value={task.status}
                                    size="small"
                                    onChange={(val) => handleUpdateKitchenTask(task.id, { status: val })}
                                    className="om-status-select"
                                    dropdownMatchSelectWidth={false}
                                >
                                    <Option value="pending">Pending</Option>
                                    <Option value="in_progress">In Progress</Option>
                                    <Option value="completed">Completed</Option>
                                </Select>
                            </div>
                        </div>
                    ))}
            </div>
        </div>

        {/* ===== PAGINATION ===== */}
        <div className="om-kitchen-pagination">
            <Pagination
                current={kitchenCurrentPage}
                pageSize={kitchenPageSize}
                total={kitchenTasks.filter(t => !t.is_header).length}
                onChange={(page, size) => {
                    setKitchenCurrentPage(page);
                    if (size) setKitchenPageSize(size);
                }}
                showSizeChanger
                showTotal={(total) => `Total ${total} tasks`}
                pageSizeOptions={['5', '10', '20']}
            />
        </div>
    </div>
</Modal>

          
                {/* ============================================================
                    DELIVERY PREP MODAL
                ============================================================ */}
           <Modal
    title={
        <div className="om-modal-header-clean">
            <div className="om-modal-title-icon"><TruckOutlined /></div>
            <div className="om-modal-title-text">Delivery Preparation Items</div>
            <div className="om-modal-badge">{formatBookingId(selectedOrder?.booking_no)}</div>
        </div>
    }
    open={deliveryModalVisible}
    onCancel={() => {
        setDeliveryModalVisible(false);
        setShowAddDeliveryItem(false);
    }}
    width={1100}
    className={modalClass}
    footer={
        <div className="om-modal-footer-simple">
            <Button icon={<PrinterOutlined />} onClick={() => printDeliveryItems(deliveryItems)}>
                Print Delivery List
            </Button>
            <Button onClick={() => setDeliveryModalVisible(false)}>Close</Button>
        </div>
    }
    bodyStyle={{ padding: 0, maxHeight: 'none', overflow: 'visible' }}
>
    <div className="om-modal-clean-content">
        {/* ===== ADD DELIVERY SECTION ===== */}
        <div className="om-add-delivery-section">
            <div className="om-add-delivery-left">
                <PlusOutlined className="om-add-icon" />
                <span className="om-add-title">Add Delivery Item</span>
            </div>
            <Button
                type="primary"
                icon={<PlusOutlined />}
                onClick={() => {
                    deliveryItemForm.resetFields();
                    deliveryItemForm.setFieldsValue({
                        type: 'equipment',
                        quantity: 1,
                        scheduled_time: dayjs().add(1, 'hour').format('hh:mm A')
                    });
                    setShowAddDeliveryItem(!showAddDeliveryItem);
                }}
            >
                {showAddDeliveryItem ? 'Cancel' : 'Add Item'}
            </Button>
        </div>

        {/* ===== ADD FORM ===== */}
        {showAddDeliveryItem && (
            <div className="om-add-delivery-form">
                <Form form={deliveryItemForm} onFinish={handleAddDeliveryItem} layout="vertical">
                    <Row gutter={12}>
                        <Col span={7}>
                            <Form.Item name="item" rules={[{ required: true }]} label="Item Name">
                                <Input placeholder="e.g., Tables, Chairs, Utensils" />
                            </Form.Item>
                        </Col>
                        <Col span={4}>
                            <Form.Item name="quantity" rules={[{ required: true }]} label="Quantity">
                                <InputNumber min={1} placeholder="Qty" style={{ width: '100%' }} />
                            </Form.Item>
                        </Col>
                        <Col span={4}>
                            <Form.Item name="type" label="Type">
                                <Select>
                                    <Option value="equipment">Equipment</Option>
                                    <Option value="food">Food</Option>
                                    <Option value="driver">Driver</Option>
                                    <Option value="other">Other</Option>
                                </Select>
                            </Form.Item>
                        </Col>
                        <Col span={4}>
                            <Form.Item name="scheduled_time" label="Scheduled Time">
                                <Input placeholder="HH:MM AM/PM" />
                            </Form.Item>
                        </Col>
                        <Col span={5}>
                            <Form.Item name="contact" label="Contact">
                                <Input placeholder="Phone number" />
                            </Form.Item>
                        </Col>
                        <Col span={24}>
                            <Form.Item name="notes" label="Notes">
                                <Input placeholder="Additional notes..." />
                            </Form.Item>
                        </Col>
                        <Col span={24} style={{ textAlign: 'right' }}>
                            <Button type="primary" htmlType="submit" icon={<SaveOutlined />}>
                                Add Item
                            </Button>
                        </Col>
                    </Row>
                </Form>
            </div>
        )}

        {/* ===== DELIVERY TABLE ===== */}
        <div className="om-delivery-table-wrapper">
            <div className="om-delivery-table-header">
                <div className="om-col-item">Item</div>
                <div className="om-col-type">Type</div>
                <div className="om-col-qty">Qty</div>
                <div className="om-col-time">Scheduled</div>
                <div className="om-col-contact">Contact</div>
                <div className="om-col-status">Status</div>
                <div className="om-col-action">Action</div>
            </div>
            <div className="om-delivery-table-body">
                {deliveryItems.length === 0 ? (
                    <div className="om-empty-state">
                        <Empty description="No delivery items added. Click 'Add Item' to add delivery items." />
                    </div>
                ) : (
                    deliveryItems
                        .slice((deliveryCurrentPage - 1) * deliveryPageSize, deliveryCurrentPage * deliveryPageSize)
                        .map((item, idx) => (
                            <div key={item.id || idx} className="om-delivery-table-row">
                                <div className="om-col-item">
                                    <div className="om-item-icon">
                                        {item.type === 'food' ? <CoffeeOutlined /> :
                                         item.type === 'driver' ? <UserOutlined /> :
                                         <ContainerOutlined />}
                                    </div>
                                    <span className="om-item-name">{item.item}</span>
                                </div>
                                <div className="om-col-type">
                                    <Tag color={item.type === 'food' ? 'green' : item.type === 'driver' ? 'blue' : 'orange'}>
                                        {item.type || 'Equipment'}
                                    </Tag>
                                </div>
                                <div className="om-col-qty">{item.quantity}</div>
                                <div className="om-col-time">{item.scheduled_time || '-'}</div>
                                <div className="om-col-contact">{item.contact || '-'}</div>
                                <div className="om-col-status">
                                    <Tag color={item.status === 'delivered' ? 'success' : item.status === 'dispatched' ? 'processing' : item.status === 'ready' ? 'blue' : 'warning'}>
                                        {item.status === 'delivered' ? '📦 Delivered' : 
                                         item.status === 'dispatched' ? '🚚 Dispatched' : 
                                         item.status === 'ready' ? '✅ Ready' : 
                                         item.status === 'preparing' ? '🔧 Preparing' : '⏳ Pending'}
                                    </Tag>
                                </div>
                                <div className="om-col-action">
                                    <div className="om-delivery-actions">
                                        <Select
                                            value={item.status}
                                            size="small"
                                            onChange={(val) => handleUpdateDeliveryItem(item.id, { status: val })}
                                            className="om-status-select"
                                            dropdownMatchSelectWidth={false}
                                        >
                                            <Option value="pending">Pending</Option>
                                            <Option value="preparing">Preparing</Option>
                                            <Option value="ready">Ready</Option>
                                            <Option value="dispatched">Dispatched</Option>
                                            <Option value="delivered">Delivered</Option>
                                        </Select>
                                        <Popconfirm
                                            title="Remove this item?"
                                            onConfirm={() => {
                                                const updatedItems = deliveryItems.filter(i => i.id !== item.id);
                                                setDeliveryItems(updatedItems);
                                                updateDeliveryItemMutation.mutateAsync({
                                                    orderId: selectedOrder?.id,
                                                    data: { items: updatedItems }
                                                });
                                            }}
                                        >
                                            <Button type="text" danger icon={<DeleteOutlined />} size="small" />
                                        </Popconfirm>
                                    </div>
                                </div>
                            </div>
                        ))
                )}
            </div>
        </div>

        {/* ===== PAGINATION ===== */}
        {deliveryItems.length > 0 && (
            <div className="om-delivery-pagination">
                <Pagination
                    current={deliveryCurrentPage}
                    pageSize={deliveryPageSize}
                    total={deliveryItems.length}
                    onChange={(page, size) => {
                        setDeliveryCurrentPage(page);
                        if (size) setDeliveryPageSize(size);
                    }}
                    showSizeChanger
                    showTotal={(total) => `Total ${total} items`}
                    pageSizeOptions={['5', '10', '20']}
                />
            </div>
        )}
    </div>
</Modal>

                {/* ============================================================
                    INGREDIENT COMPUTATION MODAL
                ============================================================ */}
                <Modal
                    title={
                        <div className="om-modal-header-clean">
                            <div className="om-modal-title-icon"><StockOutlined /></div>
                            <div className="om-modal-title-text">Ingredient Requirements</div>
                            <div className="om-modal-badge">{formatBookingId(selectedOrderForIngredients?.booking_no)}</div>
                        </div>
                    }
                    open={ingredientsModalVisible}
                    onCancel={() => setIngredientsModalVisible(false)}
                    width={950}
                    className={modalClass}
                    footer={
                        <div className="om-modal-footer-simple">
                            <Button onClick={() => setIngredientsModalVisible(false)}>Close</Button>
                            <Button
                                type="primary"
                                icon={<ShoppingCartOutlined />}
                                onClick={() => handleAddToShoppingList(selectedOrderForIngredients?.id, computedIngredients)}
                                loading={addToShoppingListMutation.isLoading}
                                disabled={computedIngredients.length === 0 || !computedIngredients.some(i => i.need_to_buy)}
                            >
                                Add to Purchase List ({computedIngredients.filter(i => i.need_to_buy).length})
                            </Button>
                            <Button icon={<ExportOutlined />} onClick={() => {
                                const columns = [
                                    { title: 'INGREDIENT', dataIndex: 'name' },
                                    { title: 'QUANTITY NEEDED', dataIndex: 'quantity_needed' },
                                    { title: 'UNIT', dataIndex: 'unit' },
                                    { title: 'CURRENT STOCK', dataIndex: 'current_stock' },
                                    { title: 'SHORTAGE', dataIndex: 'shortage' },
                                    { title: 'STATUS', dataIndex: 'status' }
                                ];
                                exportToExcel(computedIngredients, 'Ingredient_Requirements', columns);
                            }} disabled={computedIngredients.length === 0}>
                                Export
                            </Button>
                        </div>
                    }
                    bodyStyle={{ padding: 0, maxHeight: 'none', overflow: 'visible' }}
                >
                    {selectedOrderForIngredients && (
                        <div className="om-modal-clean-content">
                            <div className="om-order-summary">
                                <Row gutter={16}>
                                    <Col span={6}>
                                        <div className="om-summary-box">
                                            <span className="om-summary-label">Booking ID</span>
                                            <span className="om-summary-value">
                                                <span className="om-id-text">{formatBookingId(selectedOrderForIngredients.booking_no)}</span>
                                            </span>
                                        </div>
                                    </Col>
                                    <Col span={6}>
                                        <div className="om-summary-box">
                                            <span className="om-summary-label">Customer</span>
                                            <span className="om-summary-value">{selectedOrderForIngredients.customer_name}</span>
                                        </div>
                                    </Col>
                                    <Col span={6}>
                                        <div className="om-summary-box">
                                            <span className="om-summary-label">Event Date</span>
                                            <span className="om-summary-value">{selectedOrderForIngredients.event_date}</span>
                                        </div>
                                    </Col>
                                    <Col span={6}>
                                        <div className="om-summary-box">
                                            <span className="om-summary-label">Total Guests</span>
                                            <span className="om-summary-value">{selectedOrderForIngredients.guests_count} PAX</span>
                                        </div>
                                    </Col>
                                </Row>
                            </div>

                            <div className="om-clean-section">
                                <div className="om-clean-section-title"><ShoppingOutlined /> Menu Items Ordered</div>
                                <div className="om-menu-items-table">
                                    <div className="om-menu-header">
                                        <span>Menu Item</span>
                                        <span>Quantity</span>
                                        <span>Unit Price</span>
                                        <span>Subtotal</span>
                                    </div>
                                    {getMenuItems(selectedOrderForIngredients).map((item, idx) => (
                                        <div key={idx} className="om-menu-row">
                                            <span className="om-menu-name">{item.name}</span>
                                            <span className="om-menu-qty">{item.quantity}</span>
                                            <span className="om-menu-price">{formatCurrency(item.price)}</span>
                                            <span className="om-menu-subtotal">{formatCurrency(item.subtotal)}</span>
                                        </div>
                                    ))}
                                    <div className="om-menu-total">
                                        <span>Total Order Amount</span>
                                        <strong>{formatCurrency(selectedOrderForIngredients.total_amount)}</strong>
                                    </div>
                                </div>
                            </div>

                            <Divider />

                            <div className="om-clean-section">
                                <div className="om-clean-section-title"><StockOutlined /> Required Ingredients Breakdown</div>
                                {isComputingIngredients ? (
                                    <div className="om-loading-container" style={{ padding: '40px', textAlign: 'center' }}>
                                        <Spin tip="Computing ingredient requirements..." />
                                        <p style={{ marginTop: 16, color: '#64748b' }}>Please wait while we calculate all required ingredients...</p>
                                    </div>
                                ) : computedIngredients.length === 0 ? (
                                    <Empty
                                        description="No ingredients computed yet. Click 'Ingredient Requirements' from the order to calculate."
                                        image={Empty.PRESENTED_IMAGE_SIMPLE}
                                    />
                                ) : (
                                    <div className="om-ingredients-table">
                                        <div className="om-ingredients-header">
                                            <span>Ingredient</span>
                                            <span>Per Pax</span>
                                            <span>Total Needed</span>
                                            <span>Current Stock</span>
                                            <span>Reserved</span>
                                            <span>Available</span>
                                            <span>Shortage</span>
                                            <span>Status</span>
                                        </div>
                                        {computedIngredients.map((ing, idx) => (
                                            <div key={ing.ingredient_id || idx} className={`om-ingredients-row ${ing.status}`}>
                                                <span className="om-ingredient-name">{ing.name}</span>
                                                <span>{ing.per_pax} {ing.unit}</span>
                                                <span className="om-ingredient-needed">{Math.round(ing.quantity_needed * 100) / 100} {ing.unit}</span>
                                                <span>{Math.round(ing.current_stock * 100) / 100} {ing.unit}</span>
                                                <span>{Math.round(ing.reserved_quantity * 100) / 100} {ing.unit}</span>
                                                <span className={ing.available_stock >= ing.quantity_needed ? 'om-available' : 'om-unavailable'}>
                                                    {Math.round(ing.available_stock * 100) / 100} {ing.unit}
                                                </span>
                                                <span>
                                                    {ing.shortage > 0 ?
                                                        <span className="om-shortage">{Math.round(ing.shortage * 100) / 100} {ing.unit}</span> :
                                                        <span className="om-sufficient">Sufficient</span>
                                                    }
                                                </span>
                                                <span>
                                                    {ing.need_to_buy ?
                                                        <Tag color="error">Need to Buy</Tag> :
                                                        <Tag color="success">In Stock</Tag>
                                                    }
                                                </span>
                                            </div>
                                        ))}
                                    </div>
                                )}

                                {computedIngredients.length > 0 && !isComputingIngredients && (
                                    <div className="om-ingredients-summary" style={{ marginTop: 16, padding: 12, background: isDarkMode ? '#1e293b' : '#f8fafc', borderRadius: 8 }}>
                                        <Row gutter={16}>
                                            <Col span={8}>
                                                <Statistic
                                                    title="Total Ingredients"
                                                    value={computedIngredients.length}
                                                    suffix="items"
                                                />
                                            </Col>
                                            <Col span={8}>
                                                <Statistic
                                                    title="Need to Buy"
                                                    value={computedIngredients.filter(i => i.need_to_buy).length}
                                                    suffix="items"
                                                    valueStyle={{ color: '#ef4444' }}
                                                />
                                            </Col>
                                            <Col span={8}>
                                                <Statistic
                                                    title="Total Shortage"
                                                    value={computedIngredients.reduce((sum, i) => sum + (i.shortage || 0), 0)}
                                                    suffix="units"
                                                    valueStyle={{ color: '#f97316' }}
                                                />
                                            </Col>
                                        </Row>
                                    </div>
                                )}
                            </div>
                        </div>
                    )}
                </Modal>

                {/* ============================================================
                    STATUS UPDATE MODAL
                ============================================================ */}
                <Modal
                    title={
                        <div className="om-modal-header-clean">
                            <div className="om-modal-title-icon"><PlayCircleOutlined /></div>
                            <div className="om-modal-title-text">Update Order Status</div>
                            <div className="om-modal-badge">{formatBookingId(selectedOrder?.booking_no)}</div>
                        </div>
                    }
                    open={statusUpdateModalVisible}
                    onCancel={() => setStatusUpdateModalVisible(false)}
                    width={450}
                    className={modalClass}
                    footer={null}
                    bodyStyle={{ padding: 0, maxHeight: 'none', overflow: 'visible' }}
                >
                    <div className="om-modal-clean-content">
                        <div className="om-status-buttons">
                            {['pending', 'preparing', 'ready', 'ongoing', 'completed', 'cancelled'].map(status => {
                                const config = getStatusConfig(status);
                                return (
                                    <button
                                        key={status}
                                        className={`om-status-btn ${selectedOrder?.order_status === status ? 'active' : ''}`}
                                        onClick={() => handleConfirmStatusUpdate(status)}
                                        style={{ borderColor: config.color, color: config.color, background: config.bg }}
                                    >
                                        {config.icon} {config.text}
                                    </button>
                                );
                            })}
                        </div>
                        <div className="om-status-cancel">
                            <Button onClick={() => setStatusUpdateModalVisible(false)}>Cancel</Button>
                        </div>
                    </div>
                </Modal>

                {/* ============================================================
                    CREATE ORDER MODAL
                ============================================================ */}
                <Modal
                    title={
                        <div className="om-modal-header-clean">
                            <div className="om-modal-title-icon"><PlusOutlined /></div>
                            <div className="om-modal-title-text">Create New Order</div>
                            <div className="om-modal-badge">New</div>
                        </div>
                    }
                    open={createOrderModalVisible}
                    onCancel={() => {
                        setCreateOrderModalVisible(false);
                        createOrderForm.resetFields();
                    }}
                    width={720}
                    className={modalClass}
                    footer={
                        <div className="om-modal-footer-simple">
                            <Button onClick={() => {
                                setCreateOrderModalVisible(false);
                                createOrderForm.resetFields();
                            }}>Cancel</Button>
                            <Button type="primary" onClick={() => createOrderForm.submit()} loading={createOrderMutation.isLoading}>
                                <SaveOutlined /> Create Order
                            </Button>
                        </div>
                    }
                    bodyStyle={{ padding: 0, maxHeight: 'none', overflow: 'visible' }}
                >
                    <div className="om-modal-clean-content">
                        <Form form={createOrderForm} layout="vertical" onFinish={handleCreateOrder}>
                            <Row gutter={16}>
                                <Col span={12}>
                                    <Form.Item name="booking_no" label="Booking ID" rules={[{ required: true }]}>
                                        <Input placeholder="Enter Booking ID (e.g., BK-0001)" />
                                    </Form.Item>
                                </Col>
                                <Col span={12}>
                                    <Form.Item name="customer_name" label="Customer Name" rules={[{ required: true }]}>
                                        <Input placeholder="Enter customer name" />
                                    </Form.Item>
                                </Col>
                            </Row>
                            <Row gutter={16}>
                                <Col span={12}>
                                    <Form.Item name="customer_email" label="Email" rules={[{ required: true, type: 'email' }]}>
                                        <Input placeholder="customer@example.com" />
                                    </Form.Item>
                                </Col>
                                <Col span={12}>
                                    <Form.Item name="customer_phone" label="Phone">
                                        <Input placeholder="Contact number" />
                                    </Form.Item>
                                </Col>
                            </Row>
                            <Row gutter={16}>
                                <Col span={12}>
                                    <Form.Item name="customer_address" label="Address">
                                        <Input placeholder="Delivery address" />
                                    </Form.Item>
                                </Col>
                                <Col span={12}>
                                    <Form.Item name="venue" label="Venue" rules={[{ required: true }]}>
                                        <Input placeholder="Event location" />
                                    </Form.Item>
                                </Col>
                            </Row>
                            <Row gutter={16}>
                                <Col span={8}>
                                    <Form.Item name="event_type" label="Event Type" rules={[{ required: true }]}>
                                        <Select placeholder="Select event type">
                                            <Option value="Wedding">🎊 Wedding</Option>
                                            <Option value="Birthday">🎂 Birthday</Option>
                                            <Option value="Corporate">🏢 Corporate</Option>
                                            <Option value="Anniversary">💕 Anniversary</Option>
                                            <Option value="Seminar">📚 Seminar</Option>
                                        </Select>
                                    </Form.Item>
                                </Col>
                                <Col span={8}>
                                    <Form.Item name="event_date" label="Event Date" rules={[{ required: true }]}>
                                        <DatePicker style={{ width: '100%' }} placeholder="Select date" />
                                    </Form.Item>
                                </Col>
                                <Col span={8}>
                                    <Form.Item name="event_time" label="Event Time" rules={[{ required: true }]}>
                                        <Select placeholder="Select time">
                                            <Option value="8:00 AM">8:00 AM</Option>
                                            <Option value="9:00 AM">9:00 AM</Option>
                                            <Option value="10:00 AM">10:00 AM</Option>
                                            <Option value="11:00 AM">11:00 AM</Option>
                                            <Option value="12:00 PM">12:00 PM</Option>
                                            <Option value="1:00 PM">1:00 PM</Option>
                                            <Option value="2:00 PM">2:00 PM</Option>
                                            <Option value="3:00 PM">3:00 PM</Option>
                                            <Option value="4:00 PM">4:00 PM</Option>
                                            <Option value="5:00 PM">5:00 PM</Option>
                                            <Option value="6:00 PM">6:00 PM</Option>
                                            <Option value="7:00 PM">7:00 PM</Option>
                                        </Select>
                                    </Form.Item>
                                </Col>
                            </Row>
                            <Row gutter={16}>
                                <Col span={12}>
                                    <Form.Item name="guests_count" label="Number of Guests" rules={[{ required: true }]}>
                                        <InputNumber min={1} style={{ width: '100%' }} placeholder="Total pax" />
                                    </Form.Item>
                                </Col>
                                <Col span={12}>
                                    <Form.Item name="total_amount" label="Total Amount" rules={[{ required: true }]}>
                                        <InputNumber min={0} style={{ width: '100%' }} prefix="₱" placeholder="0.00" />
                                    </Form.Item>
                                </Col>
                            </Row>
                            <Form.Item name="special_requests" label="Special Requests">
                                <TextArea rows={3} placeholder="Any special requests or notes..." />
                            </Form.Item>
                            <Form.Item name="menu_items" label="Menu Items">
                                <Select
                                    mode="tags"
                                    placeholder="Enter menu items with quantities (e.g., Chicken Adobo x 50)"
                                    tokenSeparators={[',']}
                                />
                            </Form.Item>
                        </Form>
                    </div>
                </Modal>
            </div>
        </ConfigProvider>
    );
};

export default OrderManagement;