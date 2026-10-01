// src/features/billing/pages/BillingInvoicing.jsx
// Complete fixed version - Properly parses payment data

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
    Row,
    Col,
    Descriptions,
    Alert,
    DatePicker,
    Badge,
    Empty,
    Form,
    Dropdown,
    Statistic,
    ConfigProvider,
    theme as antdTheme,
    Radio,
    Image,
    Avatar
} from 'antd';
import {
    DollarOutlined,
    CheckCircleOutlined,
    ClockCircleOutlined,
    EyeOutlined,
    SearchOutlined,
    PlusOutlined,
    EditOutlined,
    DeleteOutlined,
    ReloadOutlined,
    UserOutlined,
    MoreOutlined,
    PrinterOutlined,
    ExportOutlined,
    MailOutlined,
    FilterOutlined,
    DownloadOutlined,
    WarningOutlined,
    FileTextOutlined,
    CreditCardOutlined,
    WalletOutlined,
    CloseCircleOutlined,
    PercentageOutlined,
    BarChartOutlined,
    SendOutlined,
    BankOutlined,
    CalendarOutlined,
    ExclamationCircleOutlined,
    MobileOutlined,
} from '@ant-design/icons';
import dayjs from 'dayjs';
import * as XLSX from 'xlsx';
import api from '../../../services/api';
import '../styles/Billing.css';

const { Title, Text } = Typography;
const { Option } = Select;
const { TabPane } = Tabs;
const { RangePicker } = DatePicker;
const { TextArea } = Input;

const BillingInvoicing = () => {
    // ==================== STATE MANAGEMENT ====================
    const [invoices, setInvoices] = useState([]);
    const [payments, setPayments] = useState([]);
    const [mobilePayments, setMobilePayments] = useState([]);
    const [debts, setDebts] = useState([]);
    const [financialReports, setFinancialReports] = useState([]);
    const [confirmedBookings, setConfirmedBookings] = useState([]);
    const [loading, setLoading] = useState(false);
    const [searchText, setSearchText] = useState('');
    const [filterStatus, setFilterStatus] = useState('all');
    const [filterDate, setFilterDate] = useState(null);
    const [currentPage, setCurrentPage] = useState(1);
    const [pageSize, setPageSize] = useState(10);
    const [activeMainTab, setActiveMainTab] = useState('invoices');
    const [isDarkMode, setIsDarkMode] = useState(() => {
        const savedTheme = localStorage.getItem('theme');
        if (savedTheme === 'dark') return true;
        if (savedTheme === 'light') return false;
        return document.body.classList.contains('dark-mode');
    });
    
    // Modal states
    const [invoiceModalVisible, setInvoiceModalVisible] = useState(false);
    const [invoiceDetailsModalVisible, setInvoiceDetailsModalVisible] = useState(false);
    const [paymentModalVisible, setPaymentModalVisible] = useState(false);
    const [reminderModalVisible, setReminderModalVisible] = useState(false);
    const [discountModalVisible, setDiscountModalVisible] = useState(false);
    const [selectedInvoice, setSelectedInvoice] = useState(null);
    const [editingInvoice, setEditingInvoice] = useState(null);
    const [selectedPayment, setSelectedPayment] = useState(null);
    const [receiptModalVisible, setReceiptModalVisible] = useState(false);
    const [receiptData, setReceiptData] = useState(null);
    const [rejectPaymentModalVisible, setRejectPaymentModalVisible] = useState(false);
    const [rejectReason, setRejectReason] = useState('');
    const [paymentMethod, setPaymentMethod] = useState('cash');
    
    // Forms
    const [invoiceForm] = Form.useForm();
    const [paymentForm] = Form.useForm();
    const [reminderForm] = Form.useForm();
    const [discountForm] = Form.useForm();

    const isMounted = useRef(true);

    // ==================== HELPER FUNCTIONS ====================
    const generateReferenceNumber = () => {
        const timestamp = new Date().getTime().toString().slice(-8);
        const random = Math.floor(Math.random() * 10000).toString().padStart(4, '0');
        return `PAY-${timestamp}-${random}`;
    };

    const ensureArray = (data) => {
        if (Array.isArray(data)) return data;
        if (data && typeof data === 'object') {
            // Check if it's a paginated response with data property
            if (data.data && Array.isArray(data.data)) return data.data;
            if (data.data && typeof data.data === 'object' && data.data.data && Array.isArray(data.data.data)) {
                return data.data.data;
            }
            return [data];
        }
        return [];
    };

    const extractDataFromResponse = (response) => {
        // Handle different response structures
        const data = response?.data?.data || response?.data || response;
        
        // If data is an array, return it
        if (Array.isArray(data)) return data;
        
        // If data has a data property that's an array
        if (data?.data && Array.isArray(data.data)) return data.data;
        
        // If data has a nested data structure (paginated)
        if (data?.data?.data && Array.isArray(data.data.data)) return data.data.data;
        
        // If data has a data property that has data
        if (data?.data && typeof data.data === 'object' && data.data.data && Array.isArray(data.data.data)) {
            return data.data.data;
        }
        
        return [];
    };

    const formatCurrency = (value) => {
        if (!value && value !== 0) return '₱0.00';
        return `₱${value.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
    };

    // ==================== THEME DETECTION ====================
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

    // ==================== LOAD DATA FROM BACKEND ====================
    const loadInvoices = async () => {
        setLoading(true);
        try {
            const response = await api.get('/invoices');
            const invoiceData = extractDataFromResponse(response);
            
            const invoicesWithBalance = invoiceData.map(inv => ({
                ...inv,
                balance: (inv.total_amount || 0) - (inv.paid_amount || 0)
            }));
            setInvoices(invoicesWithBalance);
            
            await loadPayments();
            await loadMobilePayments();
            await loadDebts();
            await loadFinancialReports();
            await loadConfirmedBookings();
        } catch (error) {
            console.error('Failed to load invoices:', error);
            message.error('Failed to load invoices');
            setInvoices([]);
        } finally {
            setLoading(false);
        }
    };

    // ==================== FIXED: LOAD PAYMENTS ====================
   const loadPayments = async () => {
    try {
        const response = await api.get('/payments?per_page=100');
        
        // Log the full response to debug
        console.log('Payments API Response:', response.data);
        
        // Extract data from nested structure
        let paymentData = [];
        
        // Try different response structures
        if (response.data?.data?.data?.data && Array.isArray(response.data.data.data.data)) {
            paymentData = response.data.data.data.data;
        } else if (response.data?.data?.data && Array.isArray(response.data.data.data)) {
            paymentData = response.data.data.data;
        } else if (response.data?.data && Array.isArray(response.data.data)) {
            paymentData = response.data.data;
        } else if (response.data && Array.isArray(response.data)) {
            paymentData = response.data;
        }
        
        // Fallback: find any array in the response
        if (paymentData.length === 0) {
            const findArray = (obj) => {
                if (Array.isArray(obj)) return obj;
                if (obj && typeof obj === 'object') {
                    for (const key in obj) {
                        if (Array.isArray(obj[key])) {
                            return obj[key];
                        }
                        if (typeof obj[key] === 'object') {
                            const result = findArray(obj[key]);
                            if (result && result.length > 0) return result;
                        }
                    }
                }
                return null;
            };
            const found = findArray(response.data);
            if (found && found.length > 0) paymentData = found;
        }
        
        // Format payments with fallback values
        const formattedPayments = paymentData.map(p => ({
            ...p,
            payment_number: p.payment_number || p.id || 'N/A',
            customer_name: p.customer_name || 'Unknown',
            invoice_number: p.invoice_number || 'N/A',
            payment_method: p.payment_method || 'N/A',
            payment_type: p.payment_type || 'partial',
            amount: p.amount || 0,
            reference_number: p.reference_number || 'N/A',
            date: p.date || p.payment_date || p.created_at,
            status: p.status || 'pending',
        }));
        
        setPayments(formattedPayments);
        
    } catch (error) {
        console.error('Failed to load payments:', error);
        setPayments([]);
    }
};

    const loadMobilePayments = async () => {
        try {
            const response = await api.get('/payments/mobile?per_page=100');
            let data = extractDataFromResponse(response);
            setMobilePayments(data);
        } catch (error) {
            console.error('Failed to load mobile payments:', error);
            setMobilePayments([]);
        }
    };

    const loadDebts = async () => {
        try {
            const response = await api.get('/debts');
            let debtData = extractDataFromResponse(response);
            
            const debtsWithOverdue = debtData.map(debt => {
                const dueDate = dayjs(debt.due_date);
                const today = dayjs();
                const daysOverdue = dueDate && dueDate.isBefore(today) ? today.diff(dueDate, 'day') : 0;
                return {
                    ...debt,
                    days_overdue: daysOverdue,
                    remaining_debt: (debt.total_debt || 0) - (debt.paid_debt || 0)
                };
            });
            setDebts(debtsWithOverdue);
        } catch (error) {
            console.error('Failed to load debts:', error);
            setDebts([]);
        }
    };

    const loadFinancialReports = async () => {
        try {
            const response = await api.get('/financial-reports?year=' + new Date().getFullYear());
            let reportData = extractDataFromResponse(response);
            setFinancialReports(reportData);
        } catch (error) {
            console.error('Failed to load financial reports:', error);
            setFinancialReports([]);
        }
    };

    const loadConfirmedBookings = async () => {
        try {
            const response = await api.get('/invoices/confirmed-bookings');
            let data = extractDataFromResponse(response);
            setConfirmedBookings(data);
        } catch (error) {
            console.error('Failed to load confirmed bookings:', error);
            setConfirmedBookings([]);
        }
    };

    // Initial load
    useEffect(() => {
        loadInvoices();
    }, []);

    // ==================== INVOICE FUNCTIONS ====================
    const handleViewInvoice = (record) => {
        setSelectedInvoice(record);
        setInvoiceDetailsModalVisible(true);
    };

    const handleCreateInvoice = () => {
        invoiceForm.resetFields();
        setEditingInvoice(null);
        invoiceForm.setFieldsValue({
            due_date: dayjs().add(30, 'days'),
            discount_type: 'fixed',
            discount: 0,
            additional_charges: 0
        });
        setInvoiceModalVisible(true);
    };

    const handleEditInvoice = (record) => {
        setEditingInvoice(record);
        invoiceForm.setFieldsValue({
            booking_id: record.booking_id,
            booking_no: record.booking_no,
            customer_name: record.customer_name,
            customer_email: record.customer_email,
            customer_phone: record.customer_phone,
            customer_address: record.customer_address,
            event_type: record.event_type,
            event_date: record.event_date ? dayjs(record.event_date) : null,
            subtotal: record.subtotal,
            discount: record.discount,
            discount_type: record.discount_type || 'fixed',
            additional_charges: record.additional_charges,
            total_amount: record.total_amount,
            due_date: record.due_date ? dayjs(record.due_date) : null,
            notes: record.notes
        });
        setInvoiceModalVisible(true);
    };

    const handleSelectBooking = (bookingId) => {
        const booking = confirmedBookings.find(b => b.booking_id === bookingId);
        if (booking) {
            invoiceForm.setFieldsValue({
                booking_id: booking.booking_id,
                booking_no: booking.booking_no,
                customer_name: booking.customer_name,
                customer_email: booking.customer_email,
                customer_phone: booking.customer_phone,
                customer_address: booking.customer_address,
                event_type: booking.event_type,
                event_date: booking.event_date ? dayjs(booking.event_date) : null,
                subtotal: booking.subtotal || booking.total_amount || 0,
                total_amount: booking.total_amount || booking.subtotal || 0,
                guests_count: booking.guests_count,
                venue: booking.venue
            });
        }
    };

    const handleSaveInvoice = async (values) => {
        try {
            let totalAmount = values.subtotal;
            let discountAmount = values.discount || 0;
            
            if (values.discount_type === 'percentage') {
                discountAmount = values.subtotal * (values.discount / 100);
                totalAmount = values.subtotal - discountAmount;
            } else {
                totalAmount = values.subtotal - (values.discount || 0);
            }
            
            totalAmount = totalAmount + (values.additional_charges || 0);
            
            const invoiceData = {
                booking_id: values.booking_id,
                subtotal: values.subtotal,
                discount: values.discount || 0,
                discount_type: values.discount_type || 'fixed',
                additional_charges: values.additional_charges || 0,
                total_amount: totalAmount,
                due_date: values.due_date ? values.due_date.format('YYYY-MM-DD') : null,
                notes: values.notes
            };
            
            if (editingInvoice) {
                await api.put(`/invoices/${editingInvoice.invoice_id}`, invoiceData);
                message.success('Invoice updated successfully');
            } else {
                await api.post('/invoices', invoiceData);
                message.success('Invoice created successfully');
            }
            
            setInvoiceModalVisible(false);
            invoiceForm.resetFields();
            await loadInvoices();
        } catch (error) {
            message.error(error.response?.data?.message || 'Failed to save invoice');
        }
    };

    const handleDeleteInvoice = async (record) => {
        Modal.confirm({
            title: 'Delete Invoice',
            content: `Are you sure you want to delete invoice ${record.invoice_number}?`,
            okText: 'Delete',
            okType: 'danger',
            cancelText: 'Cancel',
            onOk: async () => {
                try {
                    await api.delete(`/invoices/${record.invoice_id}`);
                    message.success('Invoice deleted successfully');
                    await loadInvoices();
                } catch (error) {
                    message.error('Failed to delete invoice');
                }
            }
        });
    };

    const handleApplyDiscount = async (values) => {
        try {
            let totalAmount = selectedInvoice.subtotal;
            let discountAmount = values.discount_value;
            
            if (values.discount_type === 'percentage') {
                discountAmount = selectedInvoice.subtotal * (values.discount_value / 100);
                totalAmount = selectedInvoice.subtotal - discountAmount;
            } else {
                totalAmount = selectedInvoice.subtotal - values.discount_value;
            }
            
            totalAmount = totalAmount + (selectedInvoice.additional_charges || 0);
            
            await api.put(`/invoices/${selectedInvoice.invoice_id}`, {
                discount: values.discount_value,
                discount_type: values.discount_type,
                total_amount: totalAmount
            });
            
            message.success('Discount applied successfully');
            setDiscountModalVisible(false);
            discountForm.resetFields();
            await loadInvoices();
        } catch (error) {
            message.error('Failed to apply discount');
        }
    };

    const handleSendReminder = async (values) => {
        try {
            await api.post(`/invoices/${selectedInvoice.invoice_id}/reminder`, {
                subject: values.subject,
                message: values.message
            });
            message.success(`Payment reminder sent to ${selectedInvoice.customer_name}`);
            setReminderModalVisible(false);
            reminderForm.resetFields();
        } catch (error) {
            message.error('Failed to send reminder');
        }
    };

    // ==================== PAYMENT FUNCTIONS ====================
    const handleRecordPayment = async (values) => {
        try {
            const referenceNumber = values.reference_number || generateReferenceNumber();
            
            const paymentData = {
                booking_id: selectedInvoice.booking_id,
                amount: values.amount,
                payment_method: values.payment_method,
                payment_type: values.payment_type || 'partial',
                reference_number: referenceNumber,
                notes: values.notes,
                verify_immediately: true
            };

            if (values.account_name) {
                paymentData.account_name = values.account_name;
            }
            if (values.account_number) {
                paymentData.account_number = values.account_number;
            }
            
            await api.post('/payments', paymentData);
            
            // Refresh all data to show updated values
            await loadInvoices();
            await loadPayments();
            await loadDebts();
            
            message.success(`Payment recorded successfully! Reference: ${referenceNumber}`);
            setPaymentModalVisible(false);
            paymentForm.resetFields();
            setPaymentMethod('cash');
            
        } catch (error) {
            console.error('Payment error:', error);
            message.error(error.response?.data?.message || 'Failed to record payment');
        }
    };

    const handleDownloadReceipt = async (payment) => {
        try {
            const response = await api.get(`/payments/${payment.payment_id}/download-receipt`, {
                responseType: 'blob'
            });
            const url = window.URL.createObjectURL(new Blob([response.data]));
            const link = document.createElement('a');
            link.href = url;
            link.setAttribute('download', `receipt-${payment.payment_number || payment.id}.pdf`);
            document.body.appendChild(link);
            link.click();
            link.remove();
            window.URL.revokeObjectURL(url);
            message.success('Receipt downloaded');
        } catch (error) {
            message.error('Failed to download receipt');
        }
    };

    const handleViewReceipt = async (payment) => {
        try {
            setSelectedPayment(payment);
            const response = await api.get(`/payments/${payment.payment_id}/receipt`);
            const data = response.data?.data || response.data;
            setReceiptData(data);
            setReceiptModalVisible(true);
        } catch (error) {
            message.error('Failed to load receipt');
        }
    };

    // ==================== MOBILE PAYMENT FUNCTIONS ====================
    const handleVerifyMobilePayment = async (payment) => {
        Modal.confirm({
            title: 'Verify Mobile Payment',
            content: `Verify payment of ${formatCurrency(payment.amount)} from ${payment.customer_name}?`,
            okText: 'Verify',
            cancelText: 'Cancel',
            onOk: async () => {
                try {
                    await api.post(`/payments/mobile/${payment.payment_id}/verify`, {
                        notes: 'Verified via admin'
                    });
                    message.success('Mobile payment verified successfully');
                    await loadMobilePayments();
                    await loadPayments();
                    await loadInvoices();
                } catch (error) {
                    message.error('Failed to verify payment');
                }
            }
        });
    };

    const handleRejectMobilePayment = (payment) => {
        setSelectedPayment(payment);
        setRejectReason('');
        setRejectPaymentModalVisible(true);
    };

    const handleConfirmReject = async () => {
        if (!rejectReason.trim()) {
            message.error('Please provide a reason for rejection');
            return;
        }
        
        try {
            await api.post(`/payments/mobile/${selectedPayment.payment_id}/reject`, {
                reason: rejectReason
            });
            message.success('Payment rejected successfully');
            setRejectPaymentModalVisible(false);
            await loadMobilePayments();
        } catch (error) {
            message.error('Failed to reject payment');
        }
    };

    // ==================== EXPORT FUNCTIONS ====================
    const exportToExcel = (data, filename, columns) => {
        if (!Array.isArray(data) || data.length === 0) {
            message.warning('No data to export');
            return;
        }
        
        const worksheetData = data.map(row => {
            const exportRow = {};
            columns.forEach(col => {
                if (col.dataIndex) {
                    let value = row[col.dataIndex];
                    if (col.dataIndex === 'total_amount' || col.dataIndex === 'amount' || 
                        col.dataIndex === 'paid_amount' || col.dataIndex === 'balance') {
                        value = `₱${value?.toLocaleString() || 0}`;
                    }
                    exportRow[col.title] = value || '';
                } else if (col.key === 'status') {
                    const config = getStatusConfig(row.status);
                    exportRow[col.title] = config.text;
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

    const exportInvoices = () => {
        const columns = [
            { title: 'INVOICE #', dataIndex: 'invoice_number' },
            { title: 'CUSTOMER', dataIndex: 'customer_name' },
            { title: 'EVENT', dataIndex: 'event_type' },
            { title: 'TOTAL', dataIndex: 'total_amount' },
            { title: 'PAID', dataIndex: 'paid_amount' },
            { title: 'BALANCE', dataIndex: 'balance' },
            { title: 'DUE DATE', dataIndex: 'due_date' },
            { title: 'STATUS', key: 'status' }
        ];
        exportToExcel(invoices, 'Invoices_Report', columns);
    };

    const exportPayments = () => {
        const columns = [
            { title: 'PAYMENT #', dataIndex: 'payment_number' },
            { title: 'CUSTOMER', dataIndex: 'customer_name' },
            { title: 'INVOICE', dataIndex: 'invoice_number' },
            { title: 'METHOD', dataIndex: 'payment_method' },
            { title: 'TYPE', dataIndex: 'payment_type' },
            { title: 'AMOUNT', dataIndex: 'amount' },
            { title: 'REFERENCE #', dataIndex: 'reference_number' },
            { title: 'DATE', dataIndex: 'date' },
            { title: 'STATUS', dataIndex: 'status' }
        ];
        exportToExcel(payments, 'Payments_Report', columns);
    };

    const exportDebts = () => {
        const columns = [
            { title: 'CUSTOMER', dataIndex: 'customer_name' },
            { title: 'BOOKING #', dataIndex: 'booking_no' },
            { title: 'TOTAL DEBT', dataIndex: 'total_debt' },
            { title: 'PAID', dataIndex: 'paid_debt' },
            { title: 'BALANCE', dataIndex: 'remaining_debt' },
            { title: 'DAYS OVERDUE', dataIndex: 'days_overdue' },
            { title: 'STATUS', dataIndex: 'status' }
        ];
        exportToExcel(debts, 'Debt_Management_Report', columns);
    };

    const printInvoice = (invoice) => {
        if (!invoice) return;
        
        const printWindow = window.open('', '_blank');
        const content = `
            <!DOCTYPE html>
            <html>
            <head>
                <title>Invoice ${invoice.invoice_number}</title>
                <style>
                    body { font-family: Arial, sans-serif; margin: 40px; }
                    .invoice-header { text-align: center; margin-bottom: 30px; }
                    .invoice-header h1 { color: #1a7ab5; margin: 0; }
                    .invoice-header p { color: #666; margin: 5px 0; }
                    .invoice-details { margin-bottom: 30px; }
                    .invoice-details table { width: 100%; }
                    .invoice-details td { padding: 5px; }
                    .items-table { width: 100%; border-collapse: collapse; margin-bottom: 30px; }
                    .items-table th, .items-table td { border: 1px solid #ddd; padding: 10px; text-align: left; }
                    .items-table th { background-color: #1a7ab5; color: white; }
                    .total-row { text-align: right; font-size: 18px; font-weight: bold; }
                    .status-badge { display: inline-block; padding: 4px 12px; border-radius: 20px; font-size: 12px; }
                    .status-paid { background: #d4edda; color: #155724; }
                    .status-partial { background: #fff3cd; color: #856404; }
                    .status-unpaid { background: #f8d7da; color: #721c24; }
                    .status-overdue { background: #f8d7da; color: #721c24; }
                    @media print {
                        body { margin: 0; padding: 20px; }
                        .no-print { display: none; }
                    }
                </style>
            </head>
            <body>
                <div class="invoice-header">
                    <h1>INVOICE</h1>
                    <p>${invoice.invoice_number}</p>
                    <p>Date: ${invoice.issue_date || new Date().toLocaleDateString()}</p>
                </div>
                <div class="invoice-details">
                    <table>
                        <tr><td style="width: 120px;"><strong>Bill To:</strong></td><td>${invoice.customer_name || ''}</td></tr>
                        <tr><td><strong>Email:</strong></td><td>${invoice.customer_email || ''}</td></tr>
                        <tr><td><strong>Phone:</strong></td><td>${invoice.customer_phone || ''}</td></tr>
                        <tr><td><strong>Event:</strong></td><td>${invoice.event_type || ''} on ${invoice.event_date || ''}</td></tr>
                        <tr><td><strong>Due Date:</strong></td><td>${invoice.due_date || ''}</td></tr>
                        <tr><td><strong>Status:</strong></td><td><span class="status-badge status-${invoice.status}">${invoice.status?.toUpperCase() || 'UNPAID'}</span></td></tr>
                    </table>
                </div>
                <table class="items-table">
                    <thead>
                        <tr><th>Description</th><th>Quantity</th><th>Unit Price</th><th>Total</th></tr>
                    </thead>
                    <tbody>
                        ${Array.isArray(invoice.items) && invoice.items.length > 0 ? invoice.items.map(item => `
                            <tr>
                                <td>${item.description || ''}</td>
                                <td>${item.quantity || 0}</td>
                                <td>₱${(item.unit_price || 0).toLocaleString()}</td>
                                <td>₱${(item.total || 0).toLocaleString()}</td>
                            </tr>
                        `).join('') : '<tr><td colspan="4">No items</td></tr>'}
                    </tbody>
                    <tfoot>
                        <tr><td colspan="3" style="text-align: right;"><strong>Subtotal:</strong></td><td>₱${(invoice.subtotal || 0).toLocaleString()}</td></tr>
                        ${invoice.discount > 0 ? `<tr><td colspan="3" style="text-align: right;"><strong>Discount:</strong></td><td>-₱${(invoice.discount || 0).toLocaleString()}</td></tr>` : ''}
                        ${invoice.additional_charges > 0 ? `<tr><td colspan="3" style="text-align: right;"><strong>Additional Charges:</strong></td><td>₱${(invoice.additional_charges || 0).toLocaleString()}</td></tr>` : ''}
                        <tr><td colspan="3" style="text-align: right;"><strong>Total:</strong></td><td><strong>₱${(invoice.total_amount || 0).toLocaleString()}</strong></td></tr>
                        <tr><td colspan="3" style="text-align: right;"><strong>Paid:</strong></td><td>₱${(invoice.paid_amount || 0).toLocaleString()}</td></tr>
                        <tr><td colspan="3" style="text-align: right;"><strong>Balance Due:</strong></td><td><strong>₱${(invoice.balance || 0).toLocaleString()}</strong></td></tr>
                    </tfoot>
                </table>
                ${invoice.notes ? `<p><strong>Notes:</strong> ${invoice.notes}</p>` : ''}
                <div class="no-print" style="text-align: center; margin-top: 30px;">
                    <button onclick="window.print()">Print</button>
                    <button onclick="window.close()">Close</button>
                </div>
            </body>
            </html>
        `;
        printWindow.document.write(content);
        printWindow.document.close();
    };

    // ==================== HELPER FUNCTIONS ====================
    const getStatusConfig = (status) => {
        const config = {
            paid: { color: '#52c41a', text: 'Paid', icon: <CheckCircleOutlined />, bg: 'rgba(82, 196, 26, 0.1)' },
            partial: { color: '#faad14', text: 'Partial', icon: <ClockCircleOutlined />, bg: 'rgba(250, 173, 20, 0.1)' },
            unpaid: { color: '#ff4d4f', text: 'Unpaid', icon: <WarningOutlined />, bg: 'rgba(255, 77, 79, 0.1)' },
            overdue: { color: '#ff4d4f', text: 'Overdue', icon: <ExclamationCircleOutlined />, bg: 'rgba(255, 77, 79, 0.1)' },
            pending: { color: '#faad14', text: 'Pending', icon: <ClockCircleOutlined />, bg: 'rgba(250, 173, 20, 0.1)' },
            completed: { color: '#52c41a', text: 'Completed', icon: <CheckCircleOutlined />, bg: 'rgba(82, 196, 26, 0.1)' },
            refunded: { color: '#722ed1', text: 'Refunded', icon: <BankOutlined />, bg: 'rgba(114, 46, 209, 0.1)' },
            failed: { color: '#ff4d4f', text: 'Failed', icon: <CloseCircleOutlined />, bg: 'rgba(255, 77, 79, 0.1)' }
        };
        return config[status] || config.unpaid;
    };

    const getPaymentMethodIcon = (method) => {
        const icons = {
            cash: <BankOutlined />,
            gcash: <WalletOutlined />,
            maya: <WalletOutlined />,
            bank_transfer: <BankOutlined />,
            card: <CreditCardOutlined />,
            check: <FileTextOutlined />
        };
        return icons[method] || <BankOutlined />;
    };

    // ==================== STATS ====================
    const totalRevenue = Array.isArray(invoices) ? invoices.reduce((sum, inv) => sum + (inv.total_amount || 0), 0) : 0;
    const totalPaid = Array.isArray(invoices) ? invoices.reduce((sum, inv) => sum + (inv.paid_amount || 0), 0) : 0;
    const totalOutstanding = Array.isArray(invoices) ? invoices.reduce((sum, inv) => sum + (inv.balance || 0), 0) : 0;
    const totalDebt = Array.isArray(debts) ? debts.reduce((sum, debt) => sum + (debt.remaining_debt || 0), 0) : 0;
    const overdueCount = Array.isArray(invoices) ? invoices.filter(i => i.status === 'overdue').length : 0;
    const overdueDebt = Array.isArray(debts) ? debts.filter(d => d.days_overdue > 0).reduce((sum, d) => sum + (d.remaining_debt || 0), 0) : 0;
    const collectionRate = totalRevenue > 0 ? ((totalPaid / totalRevenue) * 100).toFixed(1) : 0;

    // Invoice Columns
    const invoiceColumns = [
        { title: 'INVOICE #', dataIndex: 'invoice_number', key: 'invoice_number', width: 150, render: (text) => <Tag color="blue">{text || 'N/A'}</Tag> },
        { title: 'BOOKING #', dataIndex: 'booking_no', key: 'booking_no', width: 130, render: (text) => <Tag color="purple">{text || 'N/A'}</Tag> },
        { title: 'CUSTOMER', dataIndex: 'customer_name', key: 'customer_name', width: 200, render: (text, r) => <div><Text strong className={isDarkMode ? 'bi-text-dark-primary' : ''}>{text || 'Unknown'}</Text><Text type="secondary" style={{ fontSize: 11, display: 'block' }}>{r.customer_email || ''}</Text></div> },
        { title: 'EVENT', dataIndex: 'event_type', key: 'event_type', width: 130, render: (text) => <Tag color="cyan">{text || 'N/A'}</Tag> },
        { title: 'TOTAL', dataIndex: 'total_amount', key: 'total_amount', width: 130, align: 'right', render: (v) => <Text strong>₱{(v || 0).toLocaleString()}</Text> },
        { title: 'PAID', dataIndex: 'paid_amount', key: 'paid_amount', width: 130, align: 'right', render: (v) => <Text style={{ color: '#52c41a' }}>₱{(v || 0).toLocaleString()}</Text> },
        { title: 'BALANCE', dataIndex: 'balance', key: 'balance', width: 130, align: 'right', render: (v) => <Text strong style={{ color: (v || 0) > 0 ? '#ff4d4f' : '#52c41a' }}>₱{(v || 0).toLocaleString()}</Text> },
        { title: 'DUE DATE', dataIndex: 'due_date', key: 'due_date', width: 120, render: (d) => d ? dayjs(d).format('YYYY-MM-DD') : 'N/A' },
        { title: 'STATUS', dataIndex: 'status', key: 'status', width: 110, align: 'center', render: (s) => { const config = getStatusConfig(s); return <Tag color={config.color} style={{ backgroundColor: config.bg }}>{config.text}</Tag>; } },
        { 
            title: 'ACTIONS', 
            key: 'actions', 
            width: 220,
            render: (_, record) => (
                <Space size={4}>
                    <Tooltip title="View"><Button type="text" icon={<EyeOutlined />} onClick={() => handleViewInvoice(record)} /></Tooltip>
                    <Tooltip title="Edit"><Button type="text" icon={<EditOutlined />} onClick={() => handleEditInvoice(record)} /></Tooltip>
                    <Tooltip title="Apply Discount"><Button type="text" icon={<PercentageOutlined />} onClick={() => {
                        setSelectedInvoice(record);
                        discountForm.setFieldsValue({
                            discount_value: record.discount || 0,
                            discount_type: record.discount_type || 'fixed'
                        });
                        setDiscountModalVisible(true);
                    }} style={{ color: '#faad14' }} /></Tooltip>
                    <Tooltip title="Record Payment"><Button type="text" icon={<DollarOutlined />} onClick={() => {
                        setSelectedInvoice(record);
                        paymentForm.setFieldsValue({
                            amount: null,
                            payment_method: 'cash',
                            payment_type: (record.balance || 0) >= (record.total_amount || 0) ? 'full' : 'partial',
                            reference_number: '',
                            account_name: '',
                            account_number: ''
                        });
                        setPaymentMethod('cash');
                        setPaymentModalVisible(true);
                    }} style={{ color: '#52c41a' }} /></Tooltip>
                    <Tooltip title="Send Reminder"><Button type="text" icon={<MailOutlined />} onClick={() => {
                        setSelectedInvoice(record);
                        reminderForm.setFieldsValue({
                            subject: `Payment Reminder - ${record.invoice_number}`,
                            message: `Dear ${record.customer_name},\n\nThis is a friendly reminder that your payment of ₱${(record.balance || 0).toLocaleString()} for invoice ${record.invoice_number} is due on ${record.due_date}.\n\nPlease process the payment at your earliest convenience.\n\nThank you for your business.`
                        });
                        setReminderModalVisible(true);
                    }} style={{ color: '#1890ff' }} /></Tooltip>
                    <Dropdown menu={{ items: [{ key: 'delete', label: 'Delete', icon: <DeleteOutlined />, danger: true, onClick: () => handleDeleteInvoice(record) }] }}>
                        <Button type="text" icon={<MoreOutlined />} />
                    </Dropdown>
                </Space>
            )
        }
    ];

    // Payment Columns
    const paymentColumns = [
        { 
            title: 'PAYMENT #', 
            dataIndex: 'payment_number', 
            key: 'payment_number', 
            width: 150, 
            render: (text) => <Tag color="green">{text || 'N/A'}</Tag> 
        },
        { 
            title: 'CUSTOMER', 
            dataIndex: 'customer_name', 
            key: 'customer_name', 
            width: 200, 
            render: (text) => <span className={isDarkMode ? 'bi-text-dark-primary' : ''}>{text || 'Unknown'}</span> 
        },
        { 
            title: 'INVOICE', 
            dataIndex: 'invoice_number', 
            key: 'invoice_number', 
            width: 130, 
            render: (text) => <Tag color="blue">{text || 'N/A'}</Tag> 
        },
        { 
            title: 'METHOD', 
            dataIndex: 'payment_method', 
            key: 'payment_method', 
            width: 120, 
            render: (text) => <Tag icon={getPaymentMethodIcon(text)}>{text?.replace('_', ' ').toUpperCase() || 'N/A'}</Tag> 
        },
        { 
            title: 'TYPE', 
            dataIndex: 'payment_type', 
            key: 'payment_type', 
            width: 100, 
            render: (text) => <Tag color="purple">{text?.toUpperCase() || 'PARTIAL'}</Tag> 
        },
        { 
            title: 'AMOUNT', 
            dataIndex: 'amount', 
            key: 'amount', 
            width: 130, 
            align: 'right', 
            render: (v) => <Text strong style={{ color: '#52c41a' }}>₱{(v || 0).toLocaleString()}</Text> 
        },
        { 
            title: 'REFERENCE #', 
            dataIndex: 'reference_number', 
            key: 'reference_number', 
            width: 150, 
            render: (text) => <Tag color="purple">{text || 'N/A'}</Tag> 
        },
        { 
            title: 'DATE', 
            dataIndex: 'date', 
            key: 'date', 
            width: 120, 
            render: (d) => d ? dayjs(d).format('YYYY-MM-DD') : 'N/A' 
        },
        { 
            title: 'STATUS', 
            dataIndex: 'status', 
            key: 'status', 
            width: 110, 
            align: 'center', 
            render: (s) => { 
                const config = getStatusConfig(s); 
                return <Tag color={config.color} style={{ backgroundColor: config.bg }}>{config.text}</Tag>; 
            } 
        },
        { 
            title: 'ACTION', 
            key: 'action', 
            width: 160,
            render: (_, record) => (
                <Space size={4}>
                    <Tooltip title="View Receipt">
                        <Button type="text" icon={<EyeOutlined />} onClick={() => handleViewReceipt(record)} />
                    </Tooltip>
                    <Tooltip title="Download Receipt">
                        <Button type="text" icon={<DownloadOutlined />} onClick={() => handleDownloadReceipt(record)} />
                    </Tooltip>
                </Space>
            )
        }
    ];

    // Mobile Payment Columns
    const mobilePaymentColumns = [
        { 
            title: 'CUSTOMER', 
            dataIndex: 'customer_name', 
            key: 'customer_name', 
            width: 200,
            render: (text, record) => (
                <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                    {record.customer_avatar ? (
                        <Avatar src={record.customer_avatar} size={40} />
                    ) : (
                        <Avatar icon={<UserOutlined />} size={40} style={{ backgroundColor: '#1a7ab5' }} />
                    )}
                    <div>
                        <div><Text strong>{text || 'Unknown'}</Text></div>
                        <div style={{ fontSize: 12, color: '#8b93a8' }}>{record.customer_email || ''}</div>
                    </div>
                </div>
            )
        },
        { title: 'BOOKING #', dataIndex: 'booking_no', key: 'booking_no', width: 130, render: (text) => <Tag color="purple">{text || 'N/A'}</Tag> },
        { title: 'AMOUNT', dataIndex: 'amount', key: 'amount', width: 130, align: 'right', render: (v) => <Text strong>₱{(v || 0).toLocaleString()}</Text> },
        { title: 'METHOD', dataIndex: 'payment_method', key: 'payment_method', width: 120, render: (text) => <Tag icon={getPaymentMethodIcon(text)}>{text?.toUpperCase() || 'N/A'}</Tag> },
        { title: 'REFERENCE #', dataIndex: 'reference_number', key: 'reference_number', width: 150, render: (text) => <Tag color="purple">{text || 'N/A'}</Tag> },
        { title: 'ACCOUNT', key: 'account', width: 150, render: (_, record) => (
            <div>
                <div style={{ fontSize: 12 }}>{record.account_name || 'N/A'}</div>
                <div style={{ fontSize: 11, color: '#8b93a8' }}>{record.account_number || ''}</div>
            </div>
        )},
        { title: 'DATE', dataIndex: 'date', key: 'date', width: 120, render: (d) => d ? dayjs(d).format('YYYY-MM-DD') : 'N/A' },
        { title: 'STATUS', dataIndex: 'status', key: 'status', width: 110, align: 'center', render: (s) => { const config = getStatusConfig(s); return <Tag color={config.color} style={{ backgroundColor: config.bg }}>{config.text}</Tag>; } },
        { 
            title: 'ACTIONS', 
            key: 'actions', 
            width: 200,
            render: (_, record) => (
                <Space size={4}>
                    {record.status === 'pending' && (
                        <>
                            <Tooltip title="Verify Payment">
                                <Button type="primary" size="small" icon={<CheckCircleOutlined />} onClick={() => handleVerifyMobilePayment(record)}>
                                    Verify
                                </Button>
                            </Tooltip>
                            <Tooltip title="Reject Payment">
                                <Button danger size="small" icon={<CloseCircleOutlined />} onClick={() => handleRejectMobilePayment(record)}>
                                    Reject
                                </Button>
                            </Tooltip>
                        </>
                    )}
                    {record.status === 'completed' && (
                        <Tooltip title="View Receipt">
                            <Button type="text" icon={<EyeOutlined />} onClick={() => handleViewReceipt(record)} />
                        </Tooltip>
                    )}
                    {record.status === 'failed' && (
                        <Tooltip title="Rejected">
                            <Tag color="red">Rejected</Tag>
                        </Tooltip>
                    )}
                </Space>
            )
        }
    ];

    // Debt Columns
    const debtColumns = [
        { title: 'CUSTOMER', dataIndex: 'customer_name', key: 'customer_name', width: 200, render: (text, r) => <div><Text strong className={isDarkMode ? 'bi-text-dark-primary' : ''}>{text || 'Unknown'}</Text><Text type="secondary" style={{ fontSize: 11, display: 'block' }}>Invoice: {r.invoice_number || 'N/A'}</Text></div> },
        { title: 'BOOKING #', dataIndex: 'booking_no', key: 'booking_no', width: 130, render: (text) => <Tag color="purple">{text || 'N/A'}</Tag> },
        { title: 'TOTAL DEBT', dataIndex: 'total_debt', key: 'total_debt', width: 140, align: 'right', render: (v) => <Text strong>₱{(v || 0).toLocaleString()}</Text> },
        { title: 'PAID', dataIndex: 'paid_debt', key: 'paid_debt', width: 140, align: 'right', render: (v) => <Text style={{ color: '#52c41a' }}>₱{(v || 0).toLocaleString()}</Text> },
        { title: 'BALANCE', dataIndex: 'remaining_debt', key: 'remaining_debt', width: 140, align: 'right', render: (v) => <Text strong style={{ color: (v || 0) > 0 ? '#ff4d4f' : '#52c41a' }}>₱{(v || 0).toLocaleString()}</Text> },
        { title: 'DUE DATE', dataIndex: 'due_date', key: 'due_date', width: 120, render: (d) => d ? dayjs(d).format('YYYY-MM-DD') : 'N/A' },
        { title: 'DAYS OVERDUE', dataIndex: 'days_overdue', key: 'days_overdue', width: 110, align: 'center', render: (v) => (v || 0) > 0 ? <Tag color="red">{v} days</Tag> : <Tag color="green">On Time</Tag> },
        { title: 'STATUS', dataIndex: 'status', key: 'status', width: 110, align: 'center', render: (s) => { const config = getStatusConfig(s); return <Tag color={config.color} style={{ backgroundColor: config.bg }}>{config.text}</Tag>; } },
        { 
            title: 'DEPOSIT', 
            key: 'deposit', 
            width: 150,
            render: (_, record) => (
                <div>
                    <div>{record.is_deposit_paid ? 
                        <Tag color="green">Paid</Tag> : 
                        <Tag color="red">Unpaid</Tag>
                    }</div>
                    <div style={{ fontSize: 11, color: '#8b93a8' }}>
                        ₱{(record.deposit_paid || 0).toLocaleString()} / ₱{(record.total_amount * 0.3 || 0).toLocaleString()}
                    </div>
                </div>
            )
        }
    ];

    // Financial Report Columns
    const reportColumns = [
        { title: 'MONTH', dataIndex: 'month_name', key: 'month_name', width: 150, render: (text) => <Text strong className={isDarkMode ? 'bi-text-dark-primary' : ''}>{text || 'N/A'}</Text> },
        { title: 'REVENUE', dataIndex: 'revenue', key: 'revenue', width: 150, align: 'right', render: (v) => <Text strong style={{ color: '#52c41a' }}>₱{(v || 0).toLocaleString()}</Text> },
        { title: 'COLLECTED', dataIndex: 'collected', key: 'collected', width: 150, align: 'right', render: (v) => <Text style={{ color: '#1890ff' }}>₱{(v || 0).toLocaleString()}</Text> },
        { title: 'EXPENSES', dataIndex: 'total_expenses', key: 'total_expenses', width: 150, align: 'right', render: (v) => <Text style={{ color: '#ff4d4f' }}>₱{(v || 0).toLocaleString()}</Text> },
        { title: 'PROFIT', dataIndex: 'profit', key: 'profit', width: 150, align: 'right', render: (v) => <Text strong style={{ color: '#1a7ab5' }}>₱{(v || 0).toLocaleString()}</Text> },
        { title: 'MARGIN', dataIndex: 'profit_margin', key: 'profit_margin', width: 130, align: 'right', render: (v) => <Tag color={v > 20 ? 'green' : v > 10 ? 'orange' : 'red'}>{v || 0}%</Tag> },
        { title: 'COLLECTION RATE', dataIndex: 'collection_rate', key: 'collection_rate', width: 130, align: 'right', render: (v) => <Tag color={v > 80 ? 'green' : v > 50 ? 'orange' : 'red'}>{v || 0}%</Tag> },
    ];

    const today = new Date();
    const formattedDate = today.toLocaleDateString('en-US', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' });

    const filteredInvoices = Array.isArray(invoices) ? invoices.filter(inv => {
        if (searchText && !inv.customer_name?.toLowerCase().includes(searchText.toLowerCase()) && 
            !inv.invoice_number?.toLowerCase().includes(searchText.toLowerCase())) return false;
        if (filterStatus !== 'all' && inv.status !== filterStatus) return false;
        if (filterDate && filterDate[0] && filterDate[1]) {
            const invDate = dayjs(inv.issue_date);
            if (invDate.isBefore(filterDate[0]) || invDate.isAfter(filterDate[1])) return false;
        }
        return true;
    }) : [];

    const totalProfit = Array.isArray(financialReports) ? financialReports.reduce((sum, r) => sum + (r.profit || 0), 0) : 0;
    const avgProfitMargin = Array.isArray(financialReports) && financialReports.length > 0 
        ? financialReports.reduce((sum, r) => sum + (r.profit_margin || 0), 0) / financialReports.length 
        : 0;

    // Conditional classes
    const containerClass = `bi-billing-container ${isDarkMode ? 'bi-dark-mode' : ''}`;
    const headerClass = `bi-header ${isDarkMode ? 'bi-header-dark' : ''}`;
    const dateDisplayClass = `bi-date-display ${isDarkMode ? 'bi-date-display-dark' : ''}`;
    const mainCardClass = `bi-main-card ${isDarkMode ? 'bi-main-card-dark' : ''}`;
    const filtersClass = `bi-filters ${isDarkMode ? 'bi-filters-dark' : ''}`;
    const filterGroupClass = `bi-filter-group ${isDarkMode ? 'bi-filter-group-dark' : ''}`;
    const tableClass = `bi-table ${isDarkMode ? 'bi-table-dark' : ''}`;
    const kpiCardClass = `bi-kpi-card ${isDarkMode ? 'bi-kpi-card-dark' : ''}`;
    const modalClass = `bi-modal ${isDarkMode ? 'bi-modal-dark' : ''}`;
    const tabContentClass = `bi-tab-content ${isDarkMode ? 'bi-tab-content-dark' : ''}`;
    const debtCardClass = `bi-debt-card ${isDarkMode ? 'bi-debt-card-dark' : ''}`;
    const financialCardClass = `bi-financial-card ${isDarkMode ? 'bi-financial-card-dark' : ''}`;

    return (
        <ConfigProvider
            theme={{
                algorithm: isDarkMode ? antdTheme.darkAlgorithm : antdTheme.defaultAlgorithm,
                token: {
                    colorBgContainer: isDarkMode ? '#13172b' : '#ffffff',
                    colorBorderSecondary: isDarkMode ? '#1e2340' : '#e8edf2',
                    colorText: isDarkMode ? '#e2e8f0' : '#1e293b',
                    colorTextSecondary: isDarkMode ? '#8b93a8' : '#64748b',
                },
            }}
        >
            <div className={containerClass}>
                {/* Header */}
                <div className={headerClass}>
                    <div className="bi-header-left">
                        <div className="bi-logo-icon"><DollarOutlined /></div>
                        <div className="bi-header-info">
                            <h1>Billing & Invoicing System</h1>
                            <span>Financial Management Dashboard</span>
                        </div>
                    </div>
                    <div className="bi-header-right">
                        <div className={dateDisplayClass}>
                            <CalendarOutlined />
                            <span>{formattedDate}</span>
                        </div>
                        <Divider type="vertical" />
                        <Button icon={<ReloadOutlined />} onClick={loadInvoices}>Refresh</Button>
                        <Button icon={<ExportOutlined />} onClick={exportInvoices}>Export</Button>
                        <Button icon={<PrinterOutlined />} onClick={() => window.print()}>Print</Button>
                    </div>
                </div>

                {/* Main Content */}
                <div className="bi-main-content">
                    {/* KPI Cards */}
                    <div className="bi-kpi-grid">
                        <div className={kpiCardClass}>
                            <div className="bi-kpi-icon blue"><FileTextOutlined /></div>
                            <div><div className={`bi-kpi-value ${isDarkMode ? 'bi-text-dark-primary' : ''}`}>₱{totalRevenue.toLocaleString()}</div><div className="bi-kpi-label">Total Revenue</div></div>
                        </div>
                        <div className={kpiCardClass}>
                            <div className="bi-kpi-icon green"><CheckCircleOutlined /></div>
                            <div><div className={`bi-kpi-value ${isDarkMode ? 'bi-text-dark-primary' : ''}`}>₱{totalPaid.toLocaleString()}</div><div className="bi-kpi-label">Total Collected</div></div>
                        </div>
                        <div className={kpiCardClass}>
                            <div className="bi-kpi-icon orange"><WarningOutlined /></div>
                            <div><div className={`bi-kpi-value ${isDarkMode ? 'bi-text-dark-primary' : ''}`}>₱{totalOutstanding.toLocaleString()}</div><div className="bi-kpi-label">Outstanding Balance</div></div>
                        </div>
                        <div className={kpiCardClass}>
                            <div className="bi-kpi-icon red"><ClockCircleOutlined /></div>
                            <div><div className={`bi-kpi-value ${isDarkMode ? 'bi-text-dark-primary' : ''}`}>{overdueCount}</div><div className="bi-kpi-label">Overdue Invoices</div></div>
                        </div>
                    </div>

                    {/* Main Card */}
                    <Card className={mainCardClass} bordered={false}>
                        <Tabs activeKey={activeMainTab} onChange={setActiveMainTab} className="bi-tabs">
                            {/* Invoices Tab */}
                            <TabPane tab={<span><FileTextOutlined /> Invoices</span>} key="invoices">
                                <div className={filtersClass}>
                                    <div className={filterGroupClass}>
                                        <FilterOutlined />
                                        <Select value={filterStatus} onChange={setFilterStatus} style={{ width: 140 }} size="small" placeholder="Status">
                                            <Option value="all">All Status</Option>
                                            <Option value="paid">Paid</Option>
                                            <Option value="partial">Partial</Option>
                                            <Option value="unpaid">Unpaid</Option>
                                            <Option value="overdue">Overdue</Option>
                                        </Select>
                                    </div>
                                    <div className={filterGroupClass}>
                                        <CalendarOutlined />
                                        <RangePicker size="small" onChange={setFilterDate} placeholder={['Start Date', 'End Date']} format="YYYY-MM-DD" />
                                    </div>
                                    <div className={`${filterGroupClass} bi-search`}>
                                        <SearchOutlined />
                                        <Input placeholder="Search by customer or invoice #..." value={searchText} onChange={(e) => setSearchText(e.target.value)} allowClear size="small" style={{ width: 240 }} />
                                    </div>
                                    <Button type="primary" icon={<PlusOutlined />} onClick={handleCreateInvoice}>Create Invoice</Button>
                                </div>

                                <Table 
                                    columns={invoiceColumns} 
                                    dataSource={filteredInvoices} 
                                    rowKey="invoice_id" 
                                    loading={loading} 
                                    pagination={{ 
                                        current: currentPage, 
                                        pageSize: pageSize, 
                                        total: filteredInvoices.length, 
                                        showSizeChanger: true, 
                                        showTotal: (total) => `${total} invoices`,
                                        onChange: (page, size) => { setCurrentPage(page); setPageSize(size); },
                                        className: 'bi-pagination'
                                    }} 
                                    className={tableClass} 
                                    scroll={{ x: 1400 }} 
                                />
                            </TabPane>

                            {/* Payments Tab */}
                            <TabPane tab={<span><CreditCardOutlined /> Payment Tracking</span>} key="payments">
                                <div className={tabContentClass}>
                                    <Alert 
                                        message="Payment Tracking" 
                                        description="Complete transaction history with receipt generation. All payments are recorded with unique reference numbers." 
                                        type="info" 
                                        showIcon 
                                        style={{ marginBottom: 20 }}
                                        className={isDarkMode ? 'bi-alert-dark' : ''}
                                    />
                                    <Table 
                                        columns={paymentColumns} 
                                        dataSource={payments} 
                                        rowKey="payment_id" 
                                        pagination={{ pageSize: 10 }}
                                        className={tableClass}
                                    />
                                </div>
                            </TabPane>

                            {/* Mobile Payments Tab */}
                            <TabPane tab={<span><MobileOutlined /> Mobile Payments</span>} key="mobile">
                                <div className={tabContentClass}>
                                    <Alert 
                                        message="Mobile Payment Verification" 
                                        description="Track and verify mobile payments (GCash, Maya, Bank Transfer, Card). Customer proof of payment can be viewed." 
                                        type="info" 
                                        showIcon 
                                        style={{ marginBottom: 20 }}
                                        className={isDarkMode ? 'bi-alert-dark' : ''}
                                    />
                                    <Table 
                                        columns={mobilePaymentColumns} 
                                        dataSource={mobilePayments} 
                                        rowKey="payment_id" 
                                        pagination={{ pageSize: 10 }}
                                        className={tableClass}
                                    />
                                </div>
                            </TabPane>

                            {/* Debt Management Tab */}
                            <TabPane tab={<span><WarningOutlined /> Debt Management</span>} key="debts">
                                <div className={tabContentClass}>
                                    <Alert 
                                        message="Outstanding Debts" 
                                        description="Monitor customer debts, overdue payments, and deposit status. All invoices with balance are shown here." 
                                        type="warning" 
                                        showIcon 
                                        style={{ marginBottom: 20 }}
                                        className={isDarkMode ? 'bi-alert-dark' : ''}
                                    />
                                    <div className="bi-debt-summary">
                                        <Row gutter={16} style={{ marginBottom: 20 }}>
                                            <Col span={6}>
                                                <div className={debtCardClass}>
                                                    <Text type="secondary">Total Outstanding Debt</Text>
                                                    <div className="bi-debt-value">₱{totalDebt.toLocaleString()}</div>
                                                </div>
                                            </Col>
                                            <Col span={6}>
                                                <div className={debtCardClass}>
                                                    <Text type="secondary">Overdue Debt</Text>
                                                    <div className="bi-debt-value">₱{overdueDebt.toLocaleString()}</div>
                                                </div>
                                            </Col>
                                            <Col span={6}>
                                                <div className={debtCardClass}>
                                                    <Text type="secondary">Collection Rate</Text>
                                                    <div className="bi-debt-value">{collectionRate}%</div>
                                                </div>
                                            </Col>
                                            <Col span={6}>
                                                <div className={debtCardClass}>
                                                    <Text type="secondary">Overdue Count</Text>
                                                    <div className="bi-debt-value">{overdueCount}</div>
                                                </div>
                                            </Col>
                                        </Row>
                                    </div>
                                    <Table 
                                        columns={debtColumns} 
                                        dataSource={debts} 
                                        rowKey="invoice_id" 
                                        pagination={{ pageSize: 10 }}
                                        className={tableClass}
                                    />
                                </div>
                            </TabPane>

                            {/* Financial Reports Tab */}
                            <TabPane tab={<span><BarChartOutlined /> Financial Reports</span>} key="reports">
                                <div className={tabContentClass}>
                                    <Alert 
                                        message="Financial Performance" 
                                        description="Monthly revenue, expenses, and profit analysis. Track your business financial health." 
                                        type="info" 
                                        showIcon 
                                        style={{ marginBottom: 20 }}
                                        className={isDarkMode ? 'bi-alert-dark' : ''}
                                    />
                                    
                                    <Row gutter={16} style={{ marginBottom: 24 }}>
                                        <Col span={6}>
                                            <div className={financialCardClass}>
                                                <Statistic title="Total Revenue" value={totalRevenue} prefix="₱" valueStyle={{ color: '#52c41a' }} />
                                            </div>
                                        </Col>
                                        <Col span={6}>
                                            <div className={financialCardClass}>
                                                <Statistic title="Total Profit" value={totalProfit} prefix="₱" valueStyle={{ color: '#1a7ab5' }} />
                                            </div>
                                        </Col>
                                        <Col span={6}>
                                            <div className={financialCardClass}>
                                                <Statistic title="Avg Profit Margin" value={avgProfitMargin.toFixed(1)} suffix="%" valueStyle={{ color: '#722ed1' }} />
                                            </div>
                                        </Col>
                                        <Col span={6}>
                                            <div className={financialCardClass}>
                                                <Statistic title="Collection Rate" value={collectionRate} suffix="%" valueStyle={{ color: collectionRate > 80 ? '#52c41a' : '#faad14' }} />
                                            </div>
                                        </Col>
                                    </Row>

                                    <Table 
                                        columns={reportColumns} 
                                        dataSource={financialReports} 
                                        rowKey="id" 
                                        pagination={{ pageSize: 12 }}
                                        className={tableClass}
                                        summary={() => (
                                            <Table.Summary fixed>
                                                <Table.Summary.Row>
                                                    <Table.Summary.Cell index={0}><Text strong className={isDarkMode ? 'bi-text-dark-primary' : ''}>Totals</Text></Table.Summary.Cell>
                                                    <Table.Summary.Cell index={1} align="right"><Text strong>₱{Array.isArray(financialReports) ? financialReports.reduce((s, r) => s + (r.revenue || 0), 0).toLocaleString() : 0}</Text></Table.Summary.Cell>
                                                    <Table.Summary.Cell index={2} align="right"><Text strong>₱{Array.isArray(financialReports) ? financialReports.reduce((s, r) => s + (r.collected || 0), 0).toLocaleString() : 0}</Text></Table.Summary.Cell>
                                                    <Table.Summary.Cell index={3} align="right"><Text strong>₱{Array.isArray(financialReports) ? financialReports.reduce((s, r) => s + (r.total_expenses || 0), 0).toLocaleString() : 0}</Text></Table.Summary.Cell>
                                                    <Table.Summary.Cell index={4} align="right"><Text strong>₱{totalProfit.toLocaleString()}</Text></Table.Summary.Cell>
                                                    <Table.Summary.Cell index={5} align="right"><Text strong>{Array.isArray(financialReports) && financialReports.length > 0 ? (totalProfit / (financialReports.reduce((s, r) => s + (r.revenue || 0), 0) || 1) * 100).toFixed(1) : 0}%</Text></Table.Summary.Cell>
                                                    <Table.Summary.Cell index={6} align="right"><Text strong>{Array.isArray(financialReports) ? (financialReports.reduce((s, r) => s + (r.collection_rate || 0), 0) / financialReports.length).toFixed(1) : 0}%</Text></Table.Summary.Cell>
                                                </Table.Summary.Row>
                                            </Table.Summary>
                                        )}
                                    />
                                </div>
                            </TabPane>
                        </Tabs>
                    </Card>
                </div>

                {/* ==================== MODALS ==================== */}

                {/* Create/Edit Invoice Modal */}
                <Modal
                    title={editingInvoice ? "Edit Invoice" : "Create Invoice"}
                    open={invoiceModalVisible}
                    onCancel={() => { setInvoiceModalVisible(false); setEditingInvoice(null); invoiceForm.resetFields(); }}
                    width={800}
                    footer={null}
                    className={modalClass}
                >
                    <Form form={invoiceForm} layout="vertical" onFinish={handleSaveInvoice}>
                        <Form.Item name="booking_id" label="Select Booking" rules={[{ required: true }]}>
                            <Select 
                                placeholder="Select a confirmed booking" 
                                onChange={handleSelectBooking}
                                showSearch
                                optionFilterProp="children"
                            >
                                {confirmedBookings.map(booking => (
                                    <Option key={booking.booking_id} value={booking.booking_id}>
                                        {booking.booking_no} - {booking.customer_name} ({booking.event_date})
                                    </Option>
                                ))}
                            </Select>
                        </Form.Item>

                        <Row gutter={16}>
                            <Col span={12}>
                                <Form.Item name="booking_no" label="Booking Number">
                                    <Input disabled />
                                </Form.Item>
                            </Col>
                            <Col span={12}>
                                <Form.Item name="customer_name" label="Customer Name">
                                    <Input disabled />
                                </Form.Item>
                            </Col>
                        </Row>
                        <Row gutter={16}>
                            <Col span={12}>
                                <Form.Item name="customer_email" label="Email">
                                    <Input disabled />
                                </Form.Item>
                            </Col>
                            <Col span={12}>
                                <Form.Item name="customer_phone" label="Phone">
                                    <Input disabled />
                                </Form.Item>
                            </Col>
                        </Row>
                        <Form.Item name="customer_address" label="Address">
                            <Input disabled />
                        </Form.Item>
                        <Row gutter={16}>
                            <Col span={8}>
                                <Form.Item name="event_type" label="Event Type">
                                    <Input disabled />
                                </Form.Item>
                            </Col>
                            <Col span={8}>
                                <Form.Item name="event_date" label="Event Date">
                                    <DatePicker style={{ width: '100%' }} disabled />
                                </Form.Item>
                            </Col>
                            <Col span={8}>
                                <Form.Item name="guests_count" label="Guests">
                                    <InputNumber disabled style={{ width: '100%' }} />
                                </Form.Item>
                            </Col>
                        </Row>
                        <Form.Item name="venue" label="Venue">
                            <Input disabled />
                        </Form.Item>

                        <Divider />

                        <Row gutter={16}>
                            <Col span={8}>
                                <Form.Item name="subtotal" label="Subtotal" rules={[{ required: true }]}>
                                    <InputNumber min={0} style={{ width: '100%' }} prefix="₱" placeholder="0.00" />
                                </Form.Item>
                            </Col>
                            <Col span={4}>
                                <Form.Item name="discount_type" label="Discount Type" initialValue="fixed">
                                    <Select>
                                        <Option value="fixed">Fixed (₱)</Option>
                                        <Option value="percentage">Percentage (%)</Option>
                                    </Select>
                                </Form.Item>
                            </Col>
                            <Col span={4}>
                                <Form.Item name="discount" label="    Discount">
                                    <InputNumber min={0} style={{ width: '100%' }} placeholder="0" />
                                </Form.Item>
                            </Col>
                            <Col span={8}>
                                <Form.Item name="additional_charges" label="Additional Charges">
                                    <InputNumber min={0} style={{ width: '100%' }} prefix="₱" placeholder="0" />
                                </Form.Item>
                            </Col>
                        </Row>
                        <Row gutter={16}>
                            <Col span={12}>
                                <Form.Item name="total_amount" label="Total Amount" rules={[{ required: true }]}>
                                    <InputNumber min={0} style={{ width: '100%' }} prefix="₱" placeholder="0.00" disabled />
                                </Form.Item>
                            </Col>
                            <Col span={12}>
                                <Form.Item name="due_date" label="Due Date">
                                    <DatePicker style={{ width: '100%' }} />
                                </Form.Item>
                            </Col>
                        </Row>
                        <Form.Item name="notes" label="Notes">
                            <TextArea rows={3} placeholder="Additional notes" />
                        </Form.Item>
                        <Form.Item style={{ textAlign: 'right' }}>
                            <Space>
                                <Button onClick={() => setInvoiceModalVisible(false)}>Cancel</Button>
                                <Button type="primary" htmlType="submit">{editingInvoice ? "Update" : "Create"}</Button>
                            </Space>
                        </Form.Item>
                    </Form>
                </Modal>

                {/* Invoice Details Modal */}
                <Modal
                    title="Invoice Details"
                    open={invoiceDetailsModalVisible}
                    onCancel={() => setInvoiceDetailsModalVisible(false)}
                    width={800}
                    footer={[
                        <Button key="print" icon={<PrinterOutlined />} onClick={() => printInvoice(selectedInvoice)}>Print</Button>,
                        <Button key="email" icon={<MailOutlined />} onClick={() => {
                            reminderForm.setFieldsValue({
                                subject: `Invoice ${selectedInvoice?.invoice_number}`,
                                message: `Dear ${selectedInvoice?.customer_name},\n\nPlease find attached your invoice ${selectedInvoice?.invoice_number} for ₱${(selectedInvoice?.total_amount || 0).toLocaleString()}.\n\nThank you for your business.`
                            });
                            setReminderModalVisible(true);
                            setInvoiceDetailsModalVisible(false);
                        }}>Email</Button>,
                        <Button key="close" type="primary" onClick={() => setInvoiceDetailsModalVisible(false)}>Close</Button>
                    ]}
                    className={modalClass}
                >
                    {selectedInvoice && (
                        <div>
                            <div className="bi-modal-header">
                                <Tag color="blue">{selectedInvoice.invoice_number}</Tag>
                                <Tag color={getStatusConfig(selectedInvoice.status).color} style={{ backgroundColor: getStatusConfig(selectedInvoice.status).bg }}>{getStatusConfig(selectedInvoice.status).text}</Tag>
                            </div>
                            <Descriptions bordered column={2} size="small" className="bi-descriptions-modal">
                                <Descriptions.Item label="Booking ID">{selectedInvoice.booking_no || 'N/A'}</Descriptions.Item>
                                <Descriptions.Item label="Customer">{selectedInvoice.customer_name}</Descriptions.Item>
                                <Descriptions.Item label="Email">{selectedInvoice.customer_email}</Descriptions.Item>
                                <Descriptions.Item label="Phone">{selectedInvoice.customer_phone}</Descriptions.Item>
                                <Descriptions.Item label="Event Type">{selectedInvoice.event_type}</Descriptions.Item>
                                <Descriptions.Item label="Event Date">{selectedInvoice.event_date}</Descriptions.Item>
                                <Descriptions.Item label="Issue Date">{selectedInvoice.issue_date || dayjs().format('YYYY-MM-DD')}</Descriptions.Item>
                                <Descriptions.Item label="Due Date">{selectedInvoice.due_date}</Descriptions.Item>
                                <Descriptions.Item label="Subtotal">₱{(selectedInvoice.subtotal || 0).toLocaleString()}</Descriptions.Item>
                                <Descriptions.Item label="Discount">{selectedInvoice.discount > 0 ? `-₱${(selectedInvoice.discount || 0).toLocaleString()}` : 'None'}</Descriptions.Item>
                                <Descriptions.Item label="Additional Charges">₱{(selectedInvoice.additional_charges || 0).toLocaleString()}</Descriptions.Item>
                                <Descriptions.Item label="Total Amount"><Text strong>₱{(selectedInvoice.total_amount || 0).toLocaleString()}</Text></Descriptions.Item>
                                <Descriptions.Item label="Paid Amount">₱{(selectedInvoice.paid_amount || 0).toLocaleString()}</Descriptions.Item>
                                <Descriptions.Item label="Balance"><Text strong style={{ color: (selectedInvoice.balance || 0) > 0 ? '#ff4d4f' : '#52c41a' }}>₱{(selectedInvoice.balance || 0).toLocaleString()}</Text></Descriptions.Item>
                                <Descriptions.Item label="Notes" span={2}>{selectedInvoice.notes || 'None'}</Descriptions.Item>
                            </Descriptions>
                            <Divider />
                            <Title level={5}>Invoice Items</Title>
                            <Table 
                                dataSource={selectedInvoice.items || []} 
                                columns={[
                                    { title: 'Description', dataIndex: 'description' },
                                    { title: 'Quantity', dataIndex: 'quantity', align: 'center', width: 100 },
                                    { title: 'Unit Price', dataIndex: 'unit_price', align: 'right', render: (v) => `₱${(v || 0).toLocaleString()}` },
                                    { title: 'Total', dataIndex: 'total', align: 'right', render: (v) => `₱${(v || 0).toLocaleString()}` }
                                ]} 
                                pagination={false} 
                                size="small" 
                                className={tableClass}
                            />
                        </div>
                    )}
                </Modal>

                {/* Record Payment Modal */}
                <Modal
                    title="Record Payment"
                    open={paymentModalVisible}
                    onCancel={() => { 
                        setPaymentModalVisible(false); 
                        paymentForm.resetFields(); 
                        setPaymentMethod('cash');
                    }}
                    width={500}
                    footer={null}
                    className={modalClass}
                >
                    <Form 
                        form={paymentForm} 
                        layout="vertical" 
                        onFinish={handleRecordPayment}
                        onValuesChange={(changedValues) => {
                            if (changedValues.payment_method) {
                                setPaymentMethod(changedValues.payment_method);
                            }
                        }}
                    >
                        <div className="bi-payment-info" style={{ marginBottom: 16, padding: 12, background: isDarkMode ? '#1e2340' : '#f5f5f5', borderRadius: 8 }}>
                            <Row>
                                <Col span={12}><Text type="secondary">Customer:</Text><br /><Text strong>{selectedInvoice?.customer_name || 'N/A'}</Text></Col>
                                <Col span={12}><Text type="secondary">Invoice #:</Text><br /><Text strong>{selectedInvoice?.invoice_number || 'N/A'}</Text></Col>
                                <Col span={12}><Text type="secondary">Total Amount:</Text><br /><Text strong>₱{(selectedInvoice?.total_amount || 0).toLocaleString()}</Text></Col>
                                <Col span={12}><Text type="secondary">Remaining Balance:</Text><br /><Text strong style={{ color: '#ff4d4f' }}>₱{(selectedInvoice?.balance || 0).toLocaleString()}</Text></Col>
                            </Row>
                        </div>
                        
                        <Form.Item 
                            name="amount" 
                            label="Payment Amount" 
                            rules={[{ required: true, message: 'Please enter payment amount' }]}
                        >
                            <InputNumber 
                                min={0.01} 
                                max={selectedInvoice?.balance} 
                                style={{ width: '100%' }} 
                                prefix="₱" 
                                placeholder="0.00"
                            />
                        </Form.Item>
                        
                        <Form.Item 
                            name="payment_method" 
                            label="Payment Method" 
                            rules={[{ required: true }]}
                            initialValue="cash"
                        >
                            <Select onChange={(value) => setPaymentMethod(value)}>
                                <Option value="cash">Cash</Option>
                                <Option value="bank_transfer">Bank Transfer</Option>
                                <Option value="gcash">GCash</Option>
                                <Option value="maya">Maya</Option>
                                <Option value="card">Credit/Debit Card</Option>
                                <Option value="check">Check</Option>
                            </Select>
                        </Form.Item>
                        
                        <Form.Item name="payment_type" label="Payment Type" initialValue="partial">
                            <Select>
                                <Option value="deposit">Deposit</Option>
                                <Option value="partial">Partial Payment</Option>
                                <Option value="full">Full Payment</Option>
                            </Select>
                        </Form.Item>
                        
                        {paymentMethod !== 'cash' && (
                            <>
                                <Form.Item 
                                    name="account_name" 
                                    label="Account Name"
                                    rules={[{ required: true, message: 'Please enter account name' }]}
                                >
                                    <Input placeholder="Account holder name" />
                                </Form.Item>
                                
                                <Form.Item 
                                    name="account_number" 
                                    label="Account Number"
                                    rules={[{ required: true, message: 'Please enter account number' }]}
                                >
                                    <Input placeholder="Account number" />
                                </Form.Item>
                                
                                <Form.Item 
                                    name="reference_number" 
                                    label="Reference Number"
                                    rules={[{ required: true, message: 'Please enter reference number' }]}
                                >
                                    <Input placeholder="Transaction reference" />
                                </Form.Item>
                            </>
                        )}
                        
                        {paymentMethod === 'cash' && (
                            <Form.Item name="reference_number" label="Reference Number (Optional)">
                                <Input placeholder="Transaction reference (optional)" />
                            </Form.Item>
                        )}
                        
                        <Form.Item name="notes" label="Notes">
                            <TextArea rows={2} placeholder="Additional notes" />
                        </Form.Item>
                        
                        <Form.Item style={{ textAlign: 'right' }}>
                            <Space>
                                <Button onClick={() => { 
                                    setPaymentModalVisible(false); 
                                    paymentForm.resetFields(); 
                                    setPaymentMethod('cash');
                                }}>
                                    Cancel
                                </Button>
                                <Button type="primary" htmlType="submit">Record Payment</Button>
                            </Space>
                        </Form.Item>
                    </Form>
                </Modal>

                {/* Apply Discount Modal */}
                <Modal
                    title="Apply Discount"
                    open={discountModalVisible}
                    onCancel={() => { setDiscountModalVisible(false); discountForm.resetFields(); }}
                    width={450}
                    footer={null}
                    className={modalClass}
                >
                    <Form form={discountForm} layout="vertical" onFinish={handleApplyDiscount}>
                        <div className="bi-discount-info" style={{ marginBottom: 16, padding: 12, background: isDarkMode ? '#1e2340' : '#f5f5f5', borderRadius: 8 }}>
                            <Row>
                                <Col span={12}><Text type="secondary">Invoice #:</Text><br /><Text strong>{selectedInvoice?.invoice_number || 'N/A'}</Text></Col>
                                <Col span={12}><Text type="secondary">Subtotal:</Text><br /><Text strong>₱{(selectedInvoice?.subtotal || 0).toLocaleString()}</Text></Col>
                            </Row>
                        </div>
                        <Form.Item name="discount_type" label="Discount Type" initialValue="fixed">
                            <Radio.Group>
                                <Radio value="fixed">Fixed Amount (₱)</Radio>
                                <Radio value="percentage">Percentage (%)</Radio>
                            </Radio.Group>
                        </Form.Item>
                        <Form.Item name="discount_value" label="Discount Value" rules={[{ required: true }]}>
                            <InputNumber min={0} style={{ width: '100%' }} placeholder="Enter discount amount" />
                        </Form.Item>
                        <Form.Item style={{ textAlign: 'right' }}>
                            <Space>
                                <Button onClick={() => setDiscountModalVisible(false)}>Cancel</Button>
                                <Button type="primary" htmlType="submit">Apply Discount</Button>
                            </Space>
                        </Form.Item>
                    </Form>
                </Modal>

                {/* Send Reminder Modal */}
                <Modal
                    title="Send Payment Reminder"
                    open={reminderModalVisible}
                    onCancel={() => { setReminderModalVisible(false); reminderForm.resetFields(); }}
                    width={500}
                    footer={null}
                    className={modalClass}
                >
                    <Form form={reminderForm} layout="vertical" onFinish={handleSendReminder}>
                        <Alert 
                            message={`Reminder for ${selectedInvoice?.customer_name || 'Customer'}`} 
                            description={`Outstanding balance: ₱${(selectedInvoice?.balance || 0).toLocaleString()}`} 
                            type="warning" 
                            showIcon 
                            style={{ marginBottom: 20 }}
                            className={isDarkMode ? 'bi-alert-dark' : ''}
                        />
                        <Form.Item name="subject" label="Subject" rules={[{ required: true }]}>
                            <Input />
                        </Form.Item>
                        <Form.Item name="message" label="Message" rules={[{ required: true }]}>
                            <TextArea rows={6} />
                        </Form.Item>
                        <Form.Item style={{ textAlign: 'right' }}>
                            <Space>
                                <Button onClick={() => setReminderModalVisible(false)}>Cancel</Button>
                                <Button type="primary" htmlType="submit" icon={<SendOutlined />}>Send Reminder</Button>
                            </Space>
                        </Form.Item>
                    </Form>
                </Modal>

                {/* Receipt Modal */}
                <Modal
                    title="Payment Receipt"
                    open={receiptModalVisible}
                    onCancel={() => { setReceiptModalVisible(false); setReceiptData(null); }}
                    width={700}
                    footer={[
                        <Button key="download" icon={<DownloadOutlined />} onClick={() => handleDownloadReceipt(selectedPayment)}>
                            Download
                        </Button>,
                        <Button key="close" type="primary" onClick={() => setReceiptModalVisible(false)}>Close</Button>
                    ]}
                    className={modalClass}
                >
                    {receiptData && (
                        <div>
                            <div className="bi-receipt-header" style={{ textAlign: 'center', marginBottom: 20 }}>
                                <Title level={3}>Payment Receipt</Title>
                                <Text type="secondary">Receipt #: {receiptData.payment?.payment_number || 'N/A'}</Text>
                                <br />
                                <Text type="secondary">Date: {receiptData.payment?.date || ''}</Text>
                            </div>
                            
                            <Descriptions bordered column={2} size="small">
                                <Descriptions.Item label="Customer">{receiptData.payment?.customer_name}</Descriptions.Item>
                                <Descriptions.Item label="Booking #">{receiptData.payment?.booking_no}</Descriptions.Item>
                                <Descriptions.Item label="Amount">₱{(receiptData.payment?.amount || 0).toLocaleString()}</Descriptions.Item>
                                <Descriptions.Item label="Payment Method">{receiptData.payment?.payment_method?.toUpperCase()}</Descriptions.Item>
                                <Descriptions.Item label="Reference #">{receiptData.payment?.reference_number}</Descriptions.Item>
                                <Descriptions.Item label="Status">
                                    <Tag color={receiptData.payment?.status === 'completed' ? 'green' : 'orange'}>
                                        {receiptData.payment?.status?.toUpperCase()}
                                    </Tag>
                                </Descriptions.Item>
                            </Descriptions>

                            {receiptData.payment?.account_name && (
                                <Descriptions bordered column={2} size="small" style={{ marginTop: 12 }}>
                                    <Descriptions.Item label="Account Name">{receiptData.payment.account_name}</Descriptions.Item>
                                    <Descriptions.Item label="Account Number">{receiptData.payment.account_number}</Descriptions.Item>
                                </Descriptions>
                            )}

                            {receiptData.payment?.receipt_url && (
                                <div style={{ marginTop: 16, textAlign: 'center' }}>
                                    <Image 
                                        src={receiptData.payment.receipt_url} 
                                        alt="Receipt Proof" 
                                        style={{ maxHeight: 400, objectFit: 'contain' }}
                                    />
                                </div>
                            )}
                        </div>
                    )}
                </Modal>

                {/* Reject Payment Modal */}
                <Modal
                    title="Reject Mobile Payment"
                    open={rejectPaymentModalVisible}
                    onCancel={() => { setRejectPaymentModalVisible(false); setRejectReason(''); }}
                    width={450}
                    footer={[
                        <Button key="cancel" onClick={() => { setRejectPaymentModalVisible(false); setRejectReason(''); }}>
                            Cancel
                        </Button>,
                        <Button key="reject" danger type="primary" onClick={handleConfirmReject}>
                            Reject Payment
                        </Button>
                    ]}
                    className={modalClass}
                >
                    <Alert 
                        message={`Reject payment of ${formatCurrency(selectedPayment?.amount || 0)} from ${selectedPayment?.customer_name || 'Unknown'}`} 
                        description="Please provide a reason for rejection." 
                        type="error" 
                        showIcon 
                        style={{ marginBottom: 20 }}
                    />
                    <TextArea 
                        value={rejectReason} 
                        onChange={(e) => setRejectReason(e.target.value)} 
                        rows={4} 
                        placeholder="Enter rejection reason..."
                        style={{ marginBottom: 12 }}
                    />
                    <Text type="secondary" style={{ fontSize: 12 }}>This reason will be sent to the customer.</Text>
                </Modal>
            </div>
        </ConfigProvider>
    );
};

export default BillingInvoicing;



//ALREADY UPDATED