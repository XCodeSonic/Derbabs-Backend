// src/components/BookingQuotationManagement.jsx
import React, { useEffect, useMemo, useRef, useState } from 'react';

import {
    Alert,
    Badge,
    Button,
    Calendar,
    Card,
    Col,
    ConfigProvider,
    DatePicker,
    Divider,
    Form,
    Input,
    InputNumber,
    message,
    Modal,
    Radio,
    Row,
    Select,
    Spin,
    Table,
    Tabs,
    Tag,
    theme as antdTheme,
    Tooltip,
    Typography,
    Steps
} from 'antd';

import {
    AppstoreOutlined,
    BankOutlined,
    CalendarOutlined,
    CheckCircleOutlined,
    ClockCircleOutlined,
    CloseCircleOutlined,
    DeleteOutlined,
    DollarOutlined,
    EditOutlined,
    TagOutlined,
    EnvironmentOutlined,
    ExportOutlined,
    EyeOutlined,
    FileTextOutlined,
    FilterOutlined,
    ForkOutlined,
    LoadingOutlined,
    LockOutlined,
    MailOutlined,
    MenuOutlined,
    MessageOutlined,
    PhoneOutlined,
    PlusOutlined,
    PrinterOutlined,
    ReloadOutlined,
    ScheduleOutlined,
    SearchOutlined,
    SendOutlined,
    ShoppingOutlined,
    StopOutlined,
    TeamOutlined,
    TrophyOutlined,
    UnlockOutlined,
    UserOutlined,
    WalletOutlined,
    WarningOutlined,
    LeftOutlined,
    RightOutlined,
    SyncOutlined
} from '@ant-design/icons';

import dayjs from 'dayjs';
import * as XLSX from 'xlsx';

import api from '../../../services/api';

import {
    useBookings,
    useBookingStatistics,
    useCalendarAvailability,
    useCalendarEvents,
    useConfirmBooking,
    useCreateQuotation,
    useDeleteCalendarAvailability,
    useDeleteQuotation,
    useEventTypes,
    useQuotations,
    useRecordPayment,
    useRejectBooking,
    useRejectQuotation,
    useSaveCalendarAvailability,
    useSendQuotation
} from '../../../hooks/useBookingQuotation';

import '../../../features/bookings/styles/Sales.css';

const { Title, Text } = Typography;
const { Option } = Select;
const { TabPane } = Tabs;
const { TextArea } = Input;
const { Step } = Steps;

const REGULAR_BOOKINGS_PAGE_SIZE = 5;
const MULTI_DAY_EVENTS_PAGE_SIZE = 7;
const QUOTATIONS_PAGE_SIZE = 6;
const HISTORY_PAGE_SIZE = 5;
const TABLE_FETCH_LIMIT = 100;

// ============================================================
// SAFE VALUE HELPERS
// ============================================================
const safeString = (value, defaultValue = '') => {
    if (value === null || value === undefined) return defaultValue;
    if (typeof value === 'string' || typeof value === 'number' || typeof value === 'boolean') return String(value);
    return defaultValue;
};

const safeNumber = (value, defaultValue = 0) => {
    if (value === null || value === undefined || value === '') return defaultValue;
    const parsed = Number(value);
    return Number.isFinite(parsed) ? parsed : defaultValue;
};

const safeArray = (value, defaultValue = []) => {
    if (Array.isArray(value)) return value;
    return defaultValue;
};

const safeObject = (value, defaultValue = {}) => {
    if (value !== null && typeof value === 'object' && !Array.isArray(value)) return value;
    return defaultValue;
};

const formatCurrency = (value) => {
    const amount = safeNumber(value);
    return `₱${amount.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
};

const formatDate = (value, format = 'MMM DD, YYYY') => {
    if (!value) return 'N/A';
    const parsed = dayjs(value);
    return parsed.isValid() ? parsed.format(format) : 'Invalid Date';
};

const formatDateShort = (value) => {
    if (!value) return 'N/A';
    const parsed = dayjs(value);
    return parsed.isValid() ? parsed.format('MMM DD, YYYY') : 'Invalid Date';
};

const formatDateTime = (date, time) => {
    if (!date) return 'N/A';
    const dateStr = formatDateShort(date);
    if (time) return `${dateStr} at ${time}`;
    return dateStr;
};

const formatDays = (startDate, endDate) => {
    if (!startDate || !endDate) return 1;
    const start = dayjs(startDate);
    const end = dayjs(endDate);
    if (!start.isValid() || !end.isValid()) return 1;
    return Math.max(end.diff(start, 'day') + 1, 1);
};

// ============================================================
// NOTIFICATION HELPER
// ============================================================
const notifyBookingApproved = (bookingId, bookingNo) => {
    try {
        window.dispatchEvent(new CustomEvent('booking-approved', { 
            detail: { 
                bookingId: bookingId,
                bookingNo: bookingNo,
                message: 'Booking approved successfully',
                timestamp: new Date().toISOString()
            }
        }));
        
        localStorage.setItem('notifications_updated', Date.now().toString());
        console.log('📢 Booking approval event dispatched for:', bookingNo);
    } catch (error) {
        console.warn('Failed to dispatch notification event:', error);
    }
};

const notifyRescheduleRequest = (bookingId, newDate, newTime) => {
    try {
        window.dispatchEvent(new CustomEvent('booking-reschedule-requested', { 
            detail: { 
                bookingId: bookingId,
                newDate: newDate,
                newTime: newTime,
                timestamp: new Date().toISOString()
            }
        }));
        console.log('📢 Reschedule request event dispatched for:', bookingId);
    } catch (error) {
        console.warn('Failed to dispatch reschedule notification:', error);
    }
};

// ============================================================
// UI CONFIGURATION
// ============================================================
const bookingStatusOptions = [
    { value: 'all', label: 'All Statuses' },
    { value: 'pending_approval', label: 'Pending Approval' },
    { value: 'confirmed', label: 'Confirmed' },
    { value: 'cancelled', label: 'Cancelled' },
    { value: 'rejected', label: 'Rejected' }
];

const availabilityStatusOptions = [
    { value: 'available', label: 'Available (Normal Operation)' },
    { value: 'fully_booked', label: 'Fully Booked (No more bookings)' },
    { value: 'unavailable', label: 'Unavailable (Closed/Blocked)' }
];

const paymentMethodOptions = [
    { value: 'Cash', label: 'Cash' },
    { value: 'Bank Transfer', label: 'Bank Transfer' },
    { value: 'GCash', label: 'GCash' },
    { value: 'Credit Card', label: 'Credit Card' }
];

const timeOptions = [
    '8:00 AM', '9:00 AM', '10:00 AM', '11:00 AM', '12:00 PM',
    '1:00 PM', '2:00 PM', '3:00 PM', '4:00 PM', '5:00 PM', '6:00 PM',
    '7:00 PM', '8:00 PM'
];

const getStatusConfig = (status) => {
    const config = {
        pending: { text: 'Pending', color: '#f97316', background: '#fff7ed', icon: <ClockCircleOutlined /> },
        pending_approval: { text: 'Pending Approval', color: '#f97316', background: '#fff7ed', icon: <ClockCircleOutlined /> },
        confirmed: { text: 'Confirmed', color: '#10b981', background: '#ecfdf5', icon: <CheckCircleOutlined /> },
        completed: { text: 'Completed', color: '#10b981', background: '#ecfdf5', icon: <CheckCircleOutlined /> },
        rejected: { text: 'Rejected', color: '#ef4444', background: '#fef2f2', icon: <CloseCircleOutlined /> },
        cancelled: { text: 'Cancelled', color: '#ef4444', background: '#fef2f2', icon: <StopOutlined /> }
    };
    return config[status] || config.pending;
};

const getAvailabilityConfig = (status) => {
    const config = {
        available: { text: 'Available', status: 'success', color: '#10b981', background: '#ecfdf5', icon: <UnlockOutlined /> },
        fully_booked: { text: 'Fully Booked', status: 'warning', color: '#f97316', background: '#fff7ed', icon: <TeamOutlined /> },
        unavailable: { text: 'Unavailable', status: 'error', color: '#ef4444', background: '#fef2f2', icon: <LockOutlined /> }
    };
    return config[status] || config.available;
};

// ============================================================
// MAIN COMPONENT
// ============================================================
const BookingQuotationManagement = () => {
    const isMounted = useRef(true);

    const [activeMainTab, setActiveMainTab] = useState('bookings');
    const [activeBookingTab, setActiveBookingTab] = useState('regular');

    const [searchText, setSearchText] = useState('');
    const [filterStatus, setFilterStatus] = useState('all');
    const [filterEventType, setFilterEventType] = useState('all');
    const [filterSpecificDate, setFilterSpecificDate] = useState(null);

    const [regularPage, setRegularPage] = useState(1);
    const [multiDayPage, setMultiDayPage] = useState(1);
    const [quotationPage, setQuotationPage] = useState(1);
    const [historyPage, setHistoryPage] = useState(1);

    const [calendarMode, setCalendarMode] = useState('month');
    const [calendarCursor, setCalendarCursor] = useState(dayjs());
    const [selectedCalendarDate, setSelectedCalendarDate] = useState(dayjs());

    const [isDarkMode, setIsDarkMode] = useState(false);

    const [selectedBooking, setSelectedBooking] = useState(null);
    const [bookingStep, setBookingStep] = useState(0);

    const [bookingDetailsModalVisible, setBookingDetailsModalVisible] = useState(false);
    const [paymentModalVisible, setPaymentModalVisible] = useState(false);
    const [quotationModalVisible, setQuotationModalVisible] = useState(false);
    const [availabilityModalVisible, setAvailabilityModalVisible] = useState(false);
    const [rejectReasonModalVisible, setRejectReasonModalVisible] = useState(false);
    const [cancelReasonModalVisible, setCancelReasonModalVisible] = useState(false);
    const [rescheduleModalVisible, setRescheduleModalVisible] = useState(false);

    const [paymentForm] = Form.useForm();
    const [quotationForm] = Form.useForm();
    const [availabilityForm] = Form.useForm();
    const [rejectForm] = Form.useForm();
    const [cancelForm] = Form.useForm();
    const [rescheduleForm] = Form.useForm();

    const calendarRange = useMemo(() => {
        return {
            start: calendarCursor.startOf('month').subtract(7, 'day').format('YYYY-MM-DD'),
            end: calendarCursor.endOf('month').add(7, 'day').format('YYYY-MM-DD')
        };
    }, [calendarCursor]);

    const buildBookingParams = (scope, page, perPage, status = filterStatus) => {
        const params = {
            booking_scope: scope,
            page,
            per_page: perPage
        };

        if (status !== 'all') {
            params.status = status;
        }

        if (filterEventType !== 'all') {
            params.event_type_id = filterEventType;
        }

        if (searchText.trim()) {
            params.search = searchText.trim();
        }

        if (filterSpecificDate) {
            params.event_date = dayjs(filterSpecificDate).format('YYYY-MM-DD');
        }

        return params;
    };

    const buildHistoryParams = () => {
        const params = {
            page: 1,
            per_page: TABLE_FETCH_LIMIT,
            status: 'completed'
        };

        if (filterEventType !== 'all') {
            params.event_type_id = filterEventType;
        }

        if (searchText.trim()) {
            params.search = searchText.trim();
        }

        if (filterSpecificDate) {
            params.event_date = dayjs(filterSpecificDate).format('YYYY-MM-DD');
        }

        return params;
    };

    // ========================================================
    // DATABASE QUERIES
    // ========================================================
    const {
        data: regularBookingsData,
        isLoading: regularBookingsLoading,
        refetch: refetchRegularBookings
    } = useBookings(
        buildBookingParams(
            'regular',
            regularPage,
            REGULAR_BOOKINGS_PAGE_SIZE
        )
    );

    const {
        data: multiDayBookingsData,
        isLoading: multiDayBookingsLoading,
        refetch: refetchMultiDayBookings
    } = useBookings(
        buildBookingParams(
            'multi_day',
            multiDayPage,
            MULTI_DAY_EVENTS_PAGE_SIZE
        )
    );

    const {
        data: completedBookingsData,
        isLoading: completedBookingsLoading,
        refetch: refetchCompletedBookings
    } = useBookings(buildHistoryParams());

    const { data: statistics, refetch: refetchStatistics } = useBookingStatistics();

    const {
        data: quotationsData,
        isLoading: quotationsLoading,
        refetch: refetchQuotations
    } = useQuotations({
        page: quotationPage,
        per_page: QUOTATIONS_PAGE_SIZE,
        search: searchText.trim() || undefined
    });
    const { data: eventTypesData } = useEventTypes();
    const { data: calendarEvents, refetch: refetchCalendarEvents } = useCalendarEvents(calendarRange);
    const { data: calendarAvailabilityData, refetch: refetchCalendarAvailability } = useCalendarAvailability(calendarRange);

    // ========================================================
    // DATABASE MUTATIONS
    // ========================================================
    const confirmBookingMutation = useConfirmBooking();
    const rejectBookingMutation = useRejectBooking();
    const recordPaymentMutation = useRecordPayment();
    const createQuotationMutation = useCreateQuotation();
    const rejectQuotationMutation = useRejectQuotation();
    const sendQuotationMutation = useSendQuotation();
    const deleteQuotationMutation = useDeleteQuotation();
    const saveCalendarAvailabilityMutation = useSaveCalendarAvailability();
    const deleteCalendarAvailabilityMutation = useDeleteCalendarAvailability();

    // ========================================================
    // NORMALIZED DATABASE DATA
    // ========================================================
    const regularBookings = safeArray(regularBookingsData?.data).filter((booking) => {
        return safeString(booking.booking_status) !== 'completed' && safeString(booking.booking_status) !== 'cancelled';
    });

    const multiDayBookings = safeArray(multiDayBookingsData?.data).filter((booking) => {
        return safeString(booking.booking_status) !== 'completed' && safeString(booking.booking_status) !== 'cancelled';
    });

    const completedBookings = safeArray(completedBookingsData?.data);
    const quotations = safeArray(quotationsData?.data);
    const eventTypes = safeArray(eventTypesData?.data);
    const events = safeArray(calendarEvents);
    const calendarAvailability = safeArray(calendarAvailabilityData?.data);

    const stats = safeObject(statistics?.data || statistics, {
        total_bookings: 0, pending_approvals: 0, total_revenue: 0,
        total_paid: 0, total_outstanding: 0, regular_bookings: 0, multi_day_events: 0
    });

    // ========================================================
    // THEME
    // ========================================================
    useEffect(() => {
        const detectTheme = () => {
            if (!isMounted.current) return;
            setIsDarkMode(document.body.classList.contains('dark-mode'));
        };
        detectTheme();
        const observer = new MutationObserver(detectTheme);
        observer.observe(document.body, { attributes: true, attributeFilter: ['class'] });
        return () => {
            isMounted.current = false;
            observer.disconnect();
        };
    }, []);

    // ========================================================
    // EVENT LISTENERS
    // ========================================================
    useEffect(() => {
        const handleBookingEvent = (event) => {
            console.log('📢 Booking event received:', event.detail);
            refreshAllData(false);
        };
        
        window.addEventListener('booking-approved', handleBookingEvent);
        window.addEventListener('booking-cancelled', handleBookingEvent);
        window.addEventListener('booking-reschedule-requested', handleBookingEvent);
        
        return () => {
            window.removeEventListener('booking-approved', handleBookingEvent);
            window.removeEventListener('booking-cancelled', handleBookingEvent);
            window.removeEventListener('booking-reschedule-requested', handleBookingEvent);
        };
    }, []);

    // ========================================================
    // DATA HELPERS
    // ========================================================
    const getEventTypeName = (eventTypeId) => {
        const found = eventTypes.find((eventType) => Number(eventType.event_type_id || eventType.id) === Number(eventTypeId));
        return found?.name || 'Unknown';
    };

    const getCalendarAvailability = (dateValue) => {
        const date = dayjs(dateValue).format('YYYY-MM-DD');
        return calendarAvailability.find((item) => safeString(item.availability_date || item.date) === date);
    };

    const getBookingLocation = (booking) => booking.location || booking.venue || booking.delivery_address || 'N/A';
    const getServiceType = (booking) => booking.service_type || booking.fulfillment_type || booking.delivery_type || 'Catering Service';
    const getBookingId = (booking) => booking.id || booking.booking_id;

    const getMenuItems = (booking) => {
        const items = safeArray(booking.menu_items || booking.items || booking.selected_items, []);
        return items.map(item => ({
            name: safeString(item.name),
            quantity: safeNumber(item.quantity || item.total_quantity || item.qty, 1),
            price: safeNumber(item.price, 0),
            subtotal: safeNumber(item.total_price, safeNumber(item.price, 0) * safeNumber(item.quantity || item.total_quantity, 1))
        }));
    };

    const getMenuType = (booking) => booking.menu_selection_type === 'package' ? 'Package' : 'Customize';
    const getSpecialRequests = (booking) => safeString(booking.special_requests, 'No special requests');
    const getPackageInfo = (booking) => booking.package_summary || booking.selected_package || null;

    // ========================================================
    // REFRESH
    // ========================================================
    const refreshAllData = async (showNotification = true) => {
        try {
            await Promise.all([
                refetchRegularBookings(),
                refetchMultiDayBookings(),
                refetchCompletedBookings(),
                refetchStatistics(),
                refetchQuotations(),
                refetchCalendarEvents(),
                refetchCalendarAvailability()
            ]);
            
            if (showNotification) {
                message.success('Bookings data refreshed');
            }
        } catch (error) {
            console.error('Refresh error:', error);
            if (showNotification) {
                message.error('Failed to refresh data');
            }
        }
    };

    // ========================================================
    // EXPORT FUNCTIONS
    // ========================================================
    const exportToExcel = (data, filename, columns) => {
        const worksheetData = data.map(row => {
            const exportRow = {};
            columns.forEach(col => {
                if (col.dataIndex) {
                    exportRow[col.title] = row[col.dataIndex];
                } else if (col.render && typeof col.render === 'function') {
                    const rendered = col.render(row[col.dataIndex || col.key], row);
                    if (typeof rendered === 'object' && rendered.props) {
                        exportRow[col.title] = rendered.props.children || '';
                    } else {
                        exportRow[col.title] = rendered;
                    }
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

    const exportRegularBookings = () => {
        const columns = [
            { title: 'BOOKING #', dataIndex: 'booking_no' },
            { title: 'CUSTOMER', dataIndex: 'customer_name' },
            { title: 'EVENT DATE', dataIndex: 'event_date' },
            { title: 'LOCATION', dataIndex: 'venue' },
            { title: 'SERVICE', key: 'service_type' },
            { title: 'EVENT TYPE', key: 'event_type' },
            { title: 'PAX', dataIndex: 'guests_count' },
            { title: 'AMOUNT', dataIndex: 'total_amount' },
            { title: 'STATUS', dataIndex: 'booking_status' }
        ];
        const exportData = regularBookings.map(b => ({
            ...b,
            service_type: getServiceType(b),
            event_type: getEventTypeName(b.event_type_id)
        }));
        exportToExcel(exportData, 'Regular_Bookings', columns);
    };

    const exportMultiDayBookings = () => {
        const columns = [
            { title: 'BOOKING #', dataIndex: 'booking_no' },
            { title: 'CUSTOMER', dataIndex: 'customer_name' },
            { title: 'START DATE', dataIndex: 'event_date' },
            { title: 'END DATE', dataIndex: 'event_end_date' },
            { title: 'DAYS', key: 'days' },
            { title: 'LOCATION', dataIndex: 'venue' },
            { title: 'PAX', dataIndex: 'guests_count' },
            { title: 'AMOUNT', dataIndex: 'total_amount' },
            { title: 'STATUS', dataIndex: 'booking_status' }
        ];
        const exportData = multiDayBookings.map(b => ({
            ...b,
            days: formatDays(b.event_date, b.event_end_date || b.end_date || b.event_date)
        }));
        exportToExcel(exportData, 'Multi_Day_Events', columns);
    };

    const exportHistory = () => {
        const columns = [
            { title: 'BOOKING #', dataIndex: 'booking_no' },
            { title: 'CUSTOMER', dataIndex: 'customer_name' },
            { title: 'EVENT DATE', dataIndex: 'event_date' },
            { title: 'STATUS', dataIndex: 'booking_status' },
            { title: 'AMOUNT', dataIndex: 'total_amount' },
            { title: 'PAID', dataIndex: 'paid_amount' },
            { title: 'BALANCE', dataIndex: 'balance' }
        ];
        exportToExcel(completedBookings, 'Booking_History', columns);
    };

    const printTable = (data, title, columns) => {
        const printWindow = window.open('', '_blank');
        let htmlContent = `
            <!DOCTYPE html>
            <html>
            <head>
                <title>${title}</title>
                <style>
                    body { font-family: Arial, sans-serif; margin: 20px; }
                    h1 { color: #1a7ab5; text-align: center; }
                    table { width: 100%; border-collapse: collapse; margin-top: 20px; }
                    th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
                    th { background-color: #1a7ab5; color: white; }
                    tr:nth-child(even) { background-color: #f2f2f2; }
                    .print-date { text-align: center; margin-bottom: 20px; color: #666; }
                    @media print {
                        body { margin: 0; padding: 20px; }
                        .no-print { display: none; }
                    }
                </style>
            </head>
            <body>
                <h1>${title}</h1>
                <div class="print-date">Printed: ${new Date().toLocaleString()}</div>
                <table>
                    <thead>
                        <tr>
        `;
        
        columns.forEach(col => {
            htmlContent += `<th>${col.title}</th>`;
        });
        htmlContent += `</tr></thead><tbody>`;
        
        data.forEach(row => {
            htmlContent += `<tr>`;
            columns.forEach(col => {
                let value = '';
                if (col.dataIndex) {
                    value = row[col.dataIndex] || '';
                } else if (col.key === 'service_type') {
                    value = getServiceType(row);
                } else if (col.key === 'event_type') {
                    value = getEventTypeName(row.event_type_id);
                } else if (col.key === 'days') {
                    value = formatDays(row.event_date, row.event_end_date || row.end_date || row.event_date);
                } else {
                    value = row[col.key] || '';
                }
                if (col.title === 'AMOUNT' || col.title === 'TOTAL' || col.title === 'PAID' || col.title === 'BALANCE') {
                    value = formatCurrency(value);
                }
                htmlContent += `<td>${value}</td>`;
            });
            htmlContent += `</tr>`;
        });
        
        htmlContent += `
                    </tbody>
                </table>
                <div class="no-print" style="text-align: center; margin-top: 20px;">
                    <button onclick="window.print()">Print</button>
                    <button onclick="window.close()">Close</button>
                </div>
            </body>
            </html>
        `;
        
        printWindow.document.write(htmlContent);
        printWindow.document.close();
    };

    const printRegularBookings = () => {
        const columns = [
            { title: 'BOOKING #', dataIndex: 'booking_no' },
            { title: 'CUSTOMER', dataIndex: 'customer_name' },
            { title: 'EVENT DATE', dataIndex: 'event_date' },
            { title: 'LOCATION', dataIndex: 'venue' },
            { title: 'SERVICE', key: 'service_type' },
            { title: 'EVENT TYPE', key: 'event_type' },
            { title: 'PAX', dataIndex: 'guests_count' },
            { title: 'AMOUNT', dataIndex: 'total_amount' },
            { title: 'STATUS', dataIndex: 'booking_status' }
        ];
        const printData = regularBookings.map(b => ({
            ...b,
            service_type: getServiceType(b),
            event_type: getEventTypeName(b.event_type_id)
        }));
        printTable(printData, 'Regular Bookings Report', columns);
    };

    const printMultiDayBookings = () => {
        const columns = [
            { title: 'BOOKING #', dataIndex: 'booking_no' },
            { title: 'CUSTOMER', dataIndex: 'customer_name' },
            { title: 'START DATE', dataIndex: 'event_date' },
            { title: 'END DATE', dataIndex: 'event_end_date' },
            { title: 'DAYS', key: 'days' },
            { title: 'LOCATION', dataIndex: 'venue' },
            { title: 'PAX', dataIndex: 'guests_count' },
            { title: 'AMOUNT', dataIndex: 'total_amount' },
            { title: 'STATUS', dataIndex: 'booking_status' }
        ];
        const printData = multiDayBookings.map(b => ({
            ...b,
            days: formatDays(b.event_date, b.event_end_date || b.end_date || b.event_date)
        }));
        printTable(printData, 'Multi-Day Events Report', columns);
    };

    const printHistory = () => {
        const columns = [
            { title: 'BOOKING #', dataIndex: 'booking_no' },
            { title: 'CUSTOMER', dataIndex: 'customer_name' },
            { title: 'EVENT DATE', dataIndex: 'event_date' },
            { title: 'STATUS', dataIndex: 'booking_status' },
            { title: 'AMOUNT', dataIndex: 'total_amount' },
            { title: 'PAID', dataIndex: 'paid_amount' },
            { title: 'BALANCE', dataIndex: 'balance' }
        ];
        printTable(completedBookings, 'Booking History Report', columns);
    };

    // ========================================================
    // BOOKING ACTIONS
    // ========================================================
    const renderPaginationItem = (_, type, originalElement) => {
        if (type === 'prev') {
            return (
                <Button
                    className="bqm-pagination-navigation-button"
                    size="small"
                    icon={<LeftOutlined />}
                >
                    Previous
                </Button>
            );
        }

        if (type === 'next') {
            return (
                <Button
                    className="bqm-pagination-navigation-button"
                    size="small"
                >
                    Next <RightOutlined />
                </Button>
            );
        }

        return originalElement;
    };

    const renderEmptyPaginationFooter = (label) => {
        return (
            <div className="bqm-empty-pagination-footer">
                <span className="bqm-empty-pagination-total">
                    Total 0 {label}
                </span>

                <div className="bqm-empty-pagination-controls">
                    <Button
                        className="bqm-pagination-navigation-button"
                        size="small"
                        icon={<LeftOutlined />}
                        disabled
                    >
                        Previous
                    </Button>

                    <button
                        type="button"
                        className="bqm-empty-pagination-current-page"
                        disabled
                    >
                        1
                    </button>

                    <Button
                        className="bqm-pagination-navigation-button"
                        size="small"
                        disabled
                    >
                        Next <RightOutlined />
                    </Button>
                </div>
            </div>
        );
    };

    const handleCompleteBooking = async (booking) => {
        Modal.confirm({
            title: 'Mark Booking as Completed',
            content: `Move ${safeString(booking.booking_no)} to booking history?`,
            okText: 'Mark as Completed',
            onOk: async () => {
                const bookingId = getBookingId(booking);
                try {
                    await api.put(`/bookings/${bookingId}`, {
                        booking_status: 'completed'
                    });
                    
                    if (booking.service_event_id) {
                        await api.put(`/service-events/${booking.service_event_id}`, {
                            status: 'completed'
                        });
                    }
                    
                    setRegularPage(1);
                    setMultiDayPage(1);
                    setHistoryPage(1);
                    message.success('Booking moved to history successfully');
                    await refreshAllData();
                } catch (error) {
                    message.error(error?.response?.data?.message || 'Failed to complete booking');
                }
            }
        });
    };

    const handleCancelBooking = async (values) => {
        const bookingId = getBookingId(selectedBooking);
        try {
            await api.post(`/bookings/${bookingId}/cancel-with-reason`, {
                reason: values.reason
            });
            message.success('Booking cancelled and moved to history');
            setCancelReasonModalVisible(false);
            cancelForm.resetFields();
            await refreshAllData();
        } catch (error) {
            message.error(error?.response?.data?.message || 'Failed to cancel booking');
        }
    };

    const openCancelModal = (booking) => {
        setSelectedBooking(booking);
        cancelForm.resetFields();
        setCancelReasonModalVisible(true);
    };

    const handleRejectBooking = (values) => {
        const bookingId = getBookingId(selectedBooking);
        if (values.action === 'reject') {
            rejectBookingMutation.mutate(bookingId, {
                onSuccess: () => {
                    setRejectReasonModalVisible(false);
                    rejectForm.resetFields();
                }
            });
        } else if (values.action === 'reschedule') {
            rescheduleForm.setFieldsValue({
                new_date: dayjs(selectedBooking.event_date),
                new_time: selectedBooking.event_time,
                reason: values.reason
            });
            setRejectReasonModalVisible(false);
            setRescheduleModalVisible(true);
        }
    };

    const openRejectModal = (booking) => {
        setSelectedBooking(booking);
        rejectForm.resetFields();
        setRejectReasonModalVisible(true);
    };

    const handleReschedule = (values) => {
        const bookingId = getBookingId(selectedBooking);
        // Use the customer's requested date and time
        const newDate = values.new_date ? values.new_date.format('YYYY-MM-DD') : selectedBooking.event_date;
        const newTime = values.new_time || selectedBooking.event_time;
        
        // Send as customer request
        api.post(`/bookings/${bookingId}/request-reschedule`, {
            requested_date: newDate,
            requested_time: newTime,
            reason: values.reason,
            booking_status: 'reschedule_requested'
        }).then(() => {
            message.success('Reschedule request submitted to customer');
            setRescheduleModalVisible(false);
            rescheduleForm.resetFields();
            refreshAllData();
            // Notify admin via notification
            notifyRescheduleRequest(bookingId, newDate, newTime);
        }).catch(error => {
            message.error(error?.response?.data?.message || 'Failed to submit reschedule request');
        });
    };

    const openRescheduleModal = (booking) => {
        setSelectedBooking(booking);
        rescheduleForm.setFieldsValue({
            new_date: dayjs(booking.event_date),
            new_time: booking.event_time,
            reason: ''
        });
        setRescheduleModalVisible(true);
    };

    const openBookingDetails = (booking) => {
        setSelectedBooking(booking);
        setBookingStep(0);
        setBookingDetailsModalVisible(true);
    };

    const nextBookingStep = () => {
        if (bookingStep < 4) setBookingStep(bookingStep + 1);
    };

    const prevBookingStep = () => {
        if (bookingStep > 0) setBookingStep(bookingStep - 1);
    };

    const openPaymentModal = (booking) => {
        setSelectedBooking(booking);
        paymentForm.setFieldsValue({ amount: undefined, method: undefined, reference: '' });
        setPaymentModalVisible(true);
    };

    // ========================================================
    // CONFIRM BOOKING
    // ========================================================
    const confirmBooking = (booking) => {
        const bookingId = getBookingId(booking);
        const bookingNo = safeString(booking.booking_no);
        
        Modal.confirm({
            title: 'Confirm Booking',
            content: `Confirm ${bookingNo}? The approved booking will be inserted into Order Management and Event Management.`,
            okText: 'Confirm Booking',
            cancelText: 'Cancel',
            onOk: async () => {
                const hideLoading = message.loading(`Processing booking ${bookingNo}...`, 0);
                
                try {
                    if (confirmBookingMutation && typeof confirmBookingMutation.mutateAsync === 'function') {
                        await confirmBookingMutation.mutateAsync(bookingId);
                    } else if (confirmBookingMutation && typeof confirmBookingMutation.mutate === 'function') {
                        confirmBookingMutation.mutate(bookingId, {
                            onSuccess: async () => {
                                hideLoading();
                                notifyBookingApproved(bookingId, bookingNo);
                                await refreshAllData();
                                message.success(`✅ Booking ${bookingNo} approved successfully!`);
                            },
                            onError: (error) => {
                                hideLoading();
                                message.error(error?.response?.data?.message || 'Failed to approve booking');
                            }
                        });
                        return;
                    } else {
                        await api.post(`/bookings/${bookingId}/confirm`);
                    }
                    
                    hideLoading();
                    notifyBookingApproved(bookingId, bookingNo);
                    await refreshAllData();
                    message.success(`✅ Booking ${bookingNo} approved successfully! Order, invoice, and event tracking have been created.`);
                    
                } catch (error) {
                    hideLoading();
                    console.error('Approval error:', error);
                    message.error(error?.response?.data?.message || 'Failed to approve booking');
                }
            }
        });
    };

    const savePayment = (values) => {
        recordPaymentMutation.mutate({
            id: getBookingId(selectedBooking),
            data: { 
                amount: safeNumber(values.amount), 
                payment_method: safeString(values.method), 
                payment_type: 'partial',
                reference_number: safeString(values.reference) 
            }
        }, {
            onSuccess: () => {
                setPaymentModalVisible(false);
                paymentForm.resetFields();
                refreshAllData();
            }
        });
    };

    // ========================================================
    // CREATE BOOKING
    // ========================================================
    const openCreateBookingModal = () => {
        quotationForm.resetFields();
        setQuotationModalVisible(true);
    };

    const saveBooking = async (values) => {
        try {
            const response = await api.post('/bookings', {
                customer_name: safeString(values.customer_name),
                customer_email: safeString(values.customer_email),
                customer_phone: safeString(values.customer_phone),
                customer_address: safeString(values.customer_address),
                event_type_id: safeNumber(values.event_type_id),
                event_date: values.event_date.format('YYYY-MM-DD'),
                event_time: safeString(values.event_time),
                venue: safeString(values.venue),
                guests_count: safeNumber(values.guests_count),
                total_amount: safeNumber(values.total_amount),
                special_requests: safeString(values.special_requests),
                menu_selection_type: 'custom',
                service_type: 'buffet',
                delivery_method: 'pickup'
            });
            message.success('Booking created successfully');
            quotationForm.resetFields();
            setQuotationModalVisible(false);
            await refreshAllData();
        } catch (error) {
            message.error(error?.response?.data?.message || 'Failed to create booking');
        }
    };

    // ========================================================
    // CALENDAR AVAILABILITY ACTIONS
    // ========================================================
    const handleCalendarDayClick = (dateValue) => {
        const date = dayjs(dateValue);
        
        // Don't allow editing past dates
        if (date.isBefore(dayjs().startOf('day'))) {
            message.warning('Cannot edit availability for past dates');
            return;
        }
        
        const existing = getCalendarAvailability(date);
        setSelectedCalendarDate(date);
        
        let status = 'available';
        let maxBookings = null;
        let notes = '';
        
        if (existing) {
            status = existing.status || 'available';
            maxBookings = existing.max_bookings || null;
            notes = existing.notes || '';
        }
        
        availabilityForm.setFieldsValue({
            status: status,
            max_bookings: maxBookings,
            notes: notes
        });
        setAvailabilityModalVisible(true);
    };

    const saveCalendarAvailability = (values) => {
        const date = selectedCalendarDate.format('YYYY-MM-DD');
        const status = safeString(values.status, 'available');
        const maxBookings = values.max_bookings ? safeNumber(values.max_bookings) : null;
        
        const payload = {
            status: status,
            max_bookings: maxBookings,
            notes: safeString(values.notes)
        };
        
        console.log('Saving availability for date:', date, payload);
        
        saveCalendarAvailabilityMutation.mutate({
            date: date,
            data: payload
        }, {
            onSuccess: (response) => {
                message.success(`Availability for ${date} updated to ${status}`);
                setAvailabilityModalVisible(false);
                availabilityForm.resetFields();
                refetchCalendarAvailability();
                refetchCalendarEvents();
            },
            onError: (error) => {
                console.error('Save availability error:', error);
                message.error(error?.response?.data?.message || 'Failed to update availability');
            }
        });
    };

    const resetCalendarAvailability = () => {
        const date = selectedCalendarDate.format('YYYY-MM-DD');
        Modal.confirm({
            title: 'Reset Date Availability',
            content: `Reset ${date} to the default available state? This will remove any custom settings.`,
            okText: 'Reset',
            onOk: () => {
                deleteCalendarAvailabilityMutation.mutate(date, {
                    onSuccess: () => {
                        message.success(`${date} reset to available`);
                        setAvailabilityModalVisible(false);
                        refetchCalendarAvailability();
                    },
                    onError: (error) => {
                        message.error(error?.response?.data?.message || 'Failed to reset availability');
                    }
                });
            }
        });
    };

    // ========================================================
    // CALENDAR DATE CELL RENDER
    // ========================================================
    const approvedCalendarEvents = useMemo(() => {
        return events.filter((event) => {
            return ['confirmed', 'rescheduled'].includes(safeString(event.status));
        });
    }, [events]);

    const dateCellRender = (dateValue) => {
        const date = dateValue.format('YYYY-MM-DD');
        const dayEvents = approvedCalendarEvents.filter((event) => {
            return safeString(event.start).split('T')[0] === date;
        });
        const availability = getCalendarAvailability(dateValue);
        const availabilityConfig = getAvailabilityConfig(availability?.status || 'available');
        
        const isPast = dayjs(date).isBefore(dayjs().startOf('day'));
        
        const hasCustomAvailability = availability && availability.status !== 'available';
        const hasLimitedSlots = availability?.max_bookings && availability.status === 'available';

        return (
            <div 
                className="bqm-calendar-date-cell"
                onClick={(e) => {
                    e.stopPropagation();
                    if (isPast) {
                        message.warning('Cannot edit availability for past dates');
                        return;
                    }
                    handleCalendarDayClick(dateValue);
                }}
                style={{ 
                    cursor: isPast ? 'not-allowed' : 'pointer',
                    opacity: isPast ? 0.7 : 1
                }}
            >
                {(hasCustomAvailability || hasLimitedSlots) && (
                    <div 
                        className="bqm-calendar-availability-badge"
                        style={{ 
                            backgroundColor: hasCustomAvailability ? availabilityConfig.background : '#f0fdf4',
                            color: hasCustomAvailability ? availabilityConfig.color : '#10b981',
                            borderLeft: `3px solid ${hasCustomAvailability ? availabilityConfig.color : '#10b981'}`
                        }}
                    >
                        {hasCustomAvailability ? availabilityConfig.icon : <UnlockOutlined />}
                        <span>
                            {hasCustomAvailability 
                                ? availabilityConfig.text 
                                : `Limited: ${availability.max_bookings} slots`}
                        </span>
                    </div>
                )}
                
                <div className="bqm-calendar-events-wrapper">
                    {dayEvents.slice(0, 2).map((event) => (
                        <Tooltip key={event.id} title="Click to view booking details">
                            <button
                                type="button"
                                className="bqm-calendar-event-item"
                                onClick={(clickEvent) => {
                                    clickEvent.stopPropagation();
                                    openCalendarBookingDetails(event);
                                }}
                            >
                                <span className="bqm-calendar-event-time">
                                    {safeString(event.extendedProps?.event_time, 'Time TBD')}
                                </span>
                                <span className="bqm-calendar-event-name">
                                    {safeString(event.title).split(' - ')[0]}
                                </span>
                            </button>
                        </Tooltip>
                    ))}
                    {dayEvents.length > 2 && (
                        <div className="bqm-calendar-event-more">
                            +{dayEvents.length - 2} more
                        </div>
                    )}
                </div>
                
                {!isPast && (
                    <div className="bqm-calendar-date-hint">
                        <EditOutlined style={{ fontSize: 8, opacity: 0.5 }} />
                    </div>
                )}
            </div>
        );
    };

    const openCalendarBookingDetails = async (event) => {
        try {
            const response = await api.get(`/bookings/${event.id}`);
            const booking = response?.data?.data || response?.data || null;

            if (!booking) {
                message.warning('Booking details could not be loaded');
                return;
            }

            openBookingDetails(booking);
        } catch (error) {
            message.error(error?.response?.data?.message || 'Failed to load booking details');
        }
    };

    // ========================================================
    // ACTION BUTTONS
    // ========================================================
    const renderBookingActions = (booking) => {
        const status = safeString(booking.booking_status);
        return (
            <div className="bqm-action-group">
                <Tooltip title="View details">
                    <button className="bqm-action-icon view" onClick={() => openBookingDetails(booking)}><EyeOutlined /></button>
                </Tooltip>
                {status === 'pending_approval' && (
                    <>
                        <Tooltip title="Approve booking">
                            <button className="bqm-action-icon confirm" onClick={() => confirmBooking(booking)}>
                                <CheckCircleOutlined />
                            </button>
                        </Tooltip>
                        <Tooltip title="Reject or Reschedule booking">
                            <button className="bqm-action-icon reject" onClick={() => openRejectModal(booking)}>
                                <CloseCircleOutlined />
                            </button>
                        </Tooltip>
                    </>
                )}
                {status === 'confirmed' && (
                    <>
                        <Tooltip title="Cancel booking">
                            <button className="bqm-action-icon delete" onClick={() => openCancelModal(booking)}>
                                <StopOutlined />
                            </button>
                        </Tooltip>
                        <Tooltip title="Request reschedule">
                            <button className="bqm-action-icon edit" onClick={() => openRescheduleModal(booking)}>
                                <SyncOutlined />
                            </button>
                        </Tooltip>
                        <Tooltip title="Mark as completed">
                            <button className="bqm-action-icon confirm" onClick={() => handleCompleteBooking(booking)}>
                                <CheckCircleOutlined />
                            </button>
                        </Tooltip>
                        <Tooltip title="Record payment">
                            <button className="bqm-action-icon payment" onClick={() => openPaymentModal(booking)}>
                                <BankOutlined />
                            </button>
                        </Tooltip>
                    </>
                )}
            </div>
        );
    };

    // ========================================================
    // TABLE COLUMNS
    // ========================================================
    const regularBookingColumns = [
        { title: 'BOOKING #', dataIndex: 'booking_no', key: 'booking_no', width: 140, fixed: 'left', render: (value) => <span className="bqm-id-text">{safeString(value)}</span> },
        { title: 'CUSTOMER', dataIndex: 'customer_name', key: 'customer_name', width: 200, render: (value, record) => (
            <div className="bqm-customer-cell">
                <div className="bqm-customer-name">{safeString(value)}</div>
                <div className="bqm-customer-contact"><MailOutlined /> {safeString(record.customer_email, 'No email')}</div>
                <div className="bqm-customer-contact"><PhoneOutlined /> {safeString(record.customer_phone, 'No phone')}</div>
            </div>
        ) },
        { 
            title: 'EVENT DATE & LOCATION', 
            key: 'event_location', 
            width: 260,
            render: (_, record) => (
                <div className="bqm-event-location-cell">
                    <div className="bqm-event-date">{formatDateShort(record.event_date)}</div>
                    <div className="bqm-event-location"><EnvironmentOutlined /> {getBookingLocation(record)}</div>
                </div>
            )
        },
        { title: 'SERVICE', key: 'service_type', width: 130, render: (_, record) => <span className="bqm-service-tag">{getServiceType(record)}</span> },
        { title: 'EVENT TYPE', key: 'event_type', width: 130, render: (_, record) => <span className="bqm-event-tag">{getEventTypeName(record.event_type_id)}</span> },
        { title: 'PAX', dataIndex: 'guests_count', key: 'guests_count', width: 80, align: 'center', render: (value) => <span className="bqm-pax-number"><TeamOutlined /> {safeNumber(value)}</span> },
        { title: 'AMOUNT', dataIndex: 'total_amount', key: 'total_amount', width: 140, align: 'right', render: (value) => <span className="bqm-amount">{formatCurrency(value)}</span> },
        { title: 'STATUS', dataIndex: 'booking_status', key: 'booking_status', width: 140, render: (value) => { const config = getStatusConfig(value); return <span className="bqm-status" style={{ color: config.color, background: config.background }}>{config.icon}{config.text}</span>; } },
        { title: 'ACTION', key: 'action', width: 200, fixed: 'right', render: (_, record) => renderBookingActions(record) }
    ];

    const multiDayColumns = [
        { title: 'BOOKING #', dataIndex: 'booking_no', key: 'booking_no', width: 140, fixed: 'left', render: (value) => <span className="bqm-id-text">{safeString(value)}</span> },
        { title: 'CUSTOMER', dataIndex: 'customer_name', key: 'customer_name', width: 200, render: (value, record) => (
            <div className="bqm-customer-cell">
                <div className="bqm-customer-name">{safeString(value)}</div>
                <div className="bqm-customer-contact"><MailOutlined /> {safeString(record.customer_email, 'No email')}</div>
            </div>
        ) },
        { 
            title: 'EVENT PERIOD', 
            key: 'event_period', 
            width: 220,
            render: (_, record) => (
                <div className="bqm-event-period-cell">
                    <div className="bqm-event-date">{formatDateShort(record.event_date)} - {formatDateShort(record.event_end_date || record.end_date || record.event_date)}</div>
                    <div className="bqm-event-days"><ScheduleOutlined /> {formatDays(record.event_date, record.event_end_date || record.end_date || record.event_date)} days</div>
                </div>
            )
        },
        { 
            title: 'LOCATION', 
            key: 'location', 
            width: 220,
            ellipsis: true,
            render: (_, record) => (
                <div className="bqm-event-location-cell">
                    <EnvironmentOutlined /> {getBookingLocation(record)}
                </div>
            )
        },
        { title: 'PAX', dataIndex: 'guests_count', key: 'guests_count', width: 80, align: 'center', render: (value) => <span className="bqm-pax-number"><TeamOutlined /> {safeNumber(value)}</span> },
        { title: 'AMOUNT', dataIndex: 'total_amount', key: 'total_amount', width: 140, align: 'right', render: (value) => <span className="bqm-amount">{formatCurrency(value)}</span> },
        { title: 'STATUS', dataIndex: 'booking_status', key: 'booking_status', width: 140, render: (value) => { const config = getStatusConfig(value); return <span className="bqm-status" style={{ color: config.color, background: config.background }}>{config.icon}{config.text}</span>; } },
        { title: 'ACTION', key: 'action', width: 200, fixed: 'right', render: (_, record) => renderBookingActions(record) }
    ];

    const historyColumns = [
        { title: 'BOOKING #', dataIndex: 'booking_no', key: 'booking_no', width: 140, render: (value) => <span className="bqm-id-text">{safeString(value)}</span> },
        { title: 'CUSTOMER', dataIndex: 'customer_name', key: 'customer_name', width: 200 },
        { title: 'EVENT DATE', dataIndex: 'event_date', key: 'event_date', width: 120, render: (value) => formatDateShort(value) },
        { title: 'LOCATION', dataIndex: 'venue', key: 'venue', width: 180, ellipsis: true },
        { title: 'PAX', dataIndex: 'guests_count', key: 'guests_count', width: 80, align: 'center', render: (value) => <span className="bqm-pax-number">{safeNumber(value)}</span> },
        { title: 'AMOUNT', dataIndex: 'total_amount', key: 'total_amount', width: 140, align: 'right', render: (value) => <span className="bqm-amount">{formatCurrency(value)}</span> },
        { title: 'STATUS', dataIndex: 'booking_status', key: 'booking_status', width: 120, render: (value) => { const config = getStatusConfig(value); return <span className="bqm-status" style={{ color: config.color, background: config.background }}>{config.icon}{config.text}</span>; } },
        {
            title: 'ACTION',
            key: 'action',
            width: 100,
            fixed: 'right',
            render: (_, record) => (
                <div className="bqm-action-group">
                    <Tooltip title="View completed booking details">
                        <button className="bqm-action-icon view" onClick={() => openBookingDetails(record)}>
                            <EyeOutlined />
                        </button>
                    </Tooltip>
                </div>
            )
        }
    ];

    const quotationColumns = [
        { title: 'QUOTE #', dataIndex: 'quote_no', key: 'quote_no', width: 140, render: (value) => <span className="bqm-id-text">{safeString(value)}</span> },
        { title: 'CUSTOMER', dataIndex: 'customer_name', key: 'customer_name', width: 200, render: (value, record) => (
            <div className="bqm-customer-cell">
                <div className="bqm-customer-name">{safeString(value)}</div>
                <div className="bqm-customer-contact"><MailOutlined /> {safeString(record.customer_email, 'No email')}</div>
            </div>
        ) },
        { title: 'EVENT DATE', dataIndex: 'event_date', key: 'event_date', width: 120, render: (value) => formatDateShort(value) },
        { title: 'PAX', dataIndex: 'guests_count', key: 'guests_count', width: 80, align: 'center', render: (value) => <span className="bqm-pax-number">{safeNumber(value)}</span> },
        { title: 'AMOUNT', dataIndex: 'total_amount', key: 'total_amount', width: 140, align: 'right', render: (value) => <span className="bqm-amount">{formatCurrency(value)}</span> },
        { title: 'STATUS', dataIndex: 'status', key: 'status', width: 130, render: (value) => { const config = getStatusConfig(value); return <span className="bqm-status" style={{ color: config.color, background: config.background }}>{config.icon}{config.text}</span>; } },
        { title: 'ACTION', key: 'action', width: 150, render: (_, record) => (
            <div className="bqm-action-group">
                <Tooltip title="Send quotation"><button className="bqm-action-icon send" onClick={() => sendQuotationMutation.mutate(record.id)}><SendOutlined /></button></Tooltip>
                <Tooltip title="Reject quotation"><button className="bqm-action-icon reject" onClick={() => rejectQuotationMutation.mutate(record.id)}><CloseCircleOutlined /></button></Tooltip>
                <Tooltip title="Delete quotation"><button className="bqm-action-icon delete" onClick={() => deleteQuotationMutation.mutate(record.id)}><DeleteOutlined /></button></Tooltip>
            </div>
        ) }
    ];

    // ========================================================
    // BOOKING DETAILS STEPS
    // ========================================================
    const bookingSteps = [
        { title: 'Customer', icon: <UserOutlined /> },
        { title: 'Event', icon: <CalendarOutlined /> },
        { title: 'Menu', icon: <ForkOutlined /> },
        { title: 'Requests', icon: <MessageOutlined /> },
        { title: 'Summary', icon: <DollarOutlined /> }
    ];

    const renderBookingStepContent = () => {
        if (!selectedBooking) return null;

        switch (bookingStep) {
            case 0:
                return (
                    <div className="bqm-step-content">
                        <div className="bqm-info-card">
                            <div className="bqm-info-row"><span className="bqm-info-label"><UserOutlined /> Customer Name</span><span className="bqm-info-value">{safeString(selectedBooking.customer_name)}</span></div>
                            <div className="bqm-info-row"><span className="bqm-info-label"><MailOutlined /> Email Address</span><span className="bqm-info-value">{safeString(selectedBooking.customer_email)}</span></div>
                            <div className="bqm-info-row"><span className="bqm-info-label"><PhoneOutlined /> Phone Number</span><span className="bqm-info-value">{safeString(selectedBooking.customer_phone, 'N/A')}</span></div>
                            <div className="bqm-info-row"><span className="bqm-info-label"><EnvironmentOutlined /> Address</span><span className="bqm-info-value">{safeString(selectedBooking.customer_address, 'N/A')}</span></div>
                        </div>
                    </div>
                );
            case 1:
                return (
                    <div className="bqm-step-content">
                        <div className="bqm-info-card">
                            <div className="bqm-info-row"><span className="bqm-info-label"><TagOutlined /> Event Type</span><span className="bqm-info-value">{getEventTypeName(selectedBooking.event_type_id)}</span></div>
                            <div className="bqm-info-row"><span className="bqm-info-label"><CalendarOutlined /> Event Date</span><span className="bqm-info-value">{formatDateTime(selectedBooking.event_date, selectedBooking.event_time)}</span></div>
                            <div className="bqm-info-row"><span className="bqm-info-label"><EnvironmentOutlined /> Venue/Location</span><span className="bqm-info-value">{getBookingLocation(selectedBooking)}</span></div>
                            <div className="bqm-info-row"><span className="bqm-info-label"><ForkOutlined /> Service Type</span><span className="bqm-info-value">{getServiceType(selectedBooking)}</span></div>
                            <div className="bqm-info-row"><span className="bqm-info-label"><TeamOutlined /> Number of Guests</span><span className="bqm-info-value">{safeNumber(selectedBooking.guests_count)} PAX</span></div>
                        </div>
                    </div>
                );
            case 2:
                return (
                    <div className="bqm-step-content">
                        <div className="bqm-info-card">
                            <div className="bqm-info-row"><span className="bqm-info-label"><MenuOutlined /> Menu Type</span><span className="bqm-info-value"><Tag color={getMenuType(selectedBooking) === 'Package' ? '#8b5cf6' : '#f59e0b'}>{getMenuType(selectedBooking)} Menu</Tag></span></div>
                            {getPackageInfo(selectedBooking) && (
                                <div className="bqm-package-info">
                                    <div className="bqm-info-row"><span className="bqm-info-label"><AppstoreOutlined /> Package Name</span><span className="bqm-info-value">{safeString(getPackageInfo(selectedBooking).name)}</span></div>
                                    <div className="bqm-info-row"><span className="bqm-info-label"><DollarOutlined /> Price per Person</span><span className="bqm-info-value">{formatCurrency(getPackageInfo(selectedBooking).base_price_per_pax)}</span></div>
                                </div>
                            )}
                            <div className="bqm-menu-table-wrapper">
                                <div className="bqm-menu-header"><span>Item Name</span><span>Qty</span><span>Price</span><span>Subtotal</span></div>
                                {getMenuItems(selectedBooking).map((item, idx) => (
                                    <div key={idx} className="bqm-menu-row"><span>{item.name}</span><span>{item.quantity}</span><span>{formatCurrency(item.price)}</span><span className="bqm-menu-subtotal">{formatCurrency(item.subtotal)}</span></div>
                                ))}
                                <div className="bqm-menu-total"><span>Total Menu Amount:</span><strong>{formatCurrency(getMenuItems(selectedBooking).reduce((sum, item) => sum + item.subtotal, 0))}</strong></div>
                            </div>
                        </div>
                    </div>
                );
            case 3:
                return (
                    <div className="bqm-step-content">
                        <div className="bqm-info-card">
                            <div className="bqm-special-request-box">
                                <MessageOutlined style={{ fontSize: 32, color: '#3b82f6' }} />
                                <p>{getSpecialRequests(selectedBooking)}</p>
                            </div>
                        </div>
                    </div>
                );
            case 4:
                return (
                    <div className="bqm-step-content">
                        <div className="bqm-info-card">
                            <div className="bqm-price-summary">
                                <div className="bqm-price-summary-header">
                                    <DollarOutlined style={{ fontSize: 28, color: '#3b82f6' }} />
                                    <span>Financial Summary</span>
                                </div>
                                <div className="bqm-price-row"><span>Total Amount</span><strong>{formatCurrency(selectedBooking.total_amount)}</strong></div>
                                <div className="bqm-price-row"><span>Status</span><Tag color={getStatusConfig(selectedBooking.booking_status).color}>{getStatusConfig(selectedBooking.booking_status).text}</Tag></div>
                            </div>
                        </div>
                    </div>
                );
            default:
                return null;
        }
    };

    // ========================================================
    // UI CLASSES
    // ========================================================
    const containerClass = `bqm-container ${isDarkMode ? 'bqm-dark-mode' : ''}`;
    const headerClass = `bqm-header ${isDarkMode ? 'bqm-header-dark' : ''}`;
    const mainCardClass = `bqm-main-card ${isDarkMode ? 'bqm-main-card-dark' : ''}`;
    const filtersClass = `bqm-filters ${isDarkMode ? 'bqm-filters-dark' : ''}`;
    const filterGroupClass = `bqm-filter-group ${isDarkMode ? 'bqm-filter-group-dark' : ''}`;
    const tableClass = `bqm-table ${isDarkMode ? 'bqm-table-dark' : ''}`;
    const isLoading = regularBookingsLoading || multiDayBookingsLoading || completedBookingsLoading || quotationsLoading;

    // ========================================================
    // RENDER
    // ========================================================
    return (
        <ConfigProvider theme={{ algorithm: isDarkMode ? antdTheme.darkAlgorithm : antdTheme.defaultAlgorithm }}>
            <div className={containerClass}>
                <div className={headerClass}>
                    <div className="bqm-header-left">
                        <div className="bqm-logo-icon"><TrophyOutlined /></div>
                        <div className="bqm-header-info">
                            <h1>Booking & Quotation Management</h1>
                            <span>ENTERPRISE SYSTEM</span>
                        </div>
                    </div>
                    <div className="bqm-header-right">
                        <div className="bqm-date-display"><CalendarOutlined /><span>{dayjs().format('dddd, MMMM DD, YYYY')}</span></div>
                        <Divider type="vertical" />
                        <Button icon={<ReloadOutlined />} onClick={() => refreshAllData(true)}>Refresh</Button>
                        <Button icon={<ExportOutlined />} onClick={() => {
                            if (activeBookingTab === 'regular') exportRegularBookings();
                            else if (activeBookingTab === 'multi_day') exportMultiDayBookings();
                            else if (activeMainTab === 'history') exportHistory();
                        }}>Export</Button>
                        <Button icon={<PrinterOutlined />} onClick={() => {
                            if (activeBookingTab === 'regular') printRegularBookings();
                            else if (activeBookingTab === 'multi_day') printMultiDayBookings();
                            else if (activeMainTab === 'history') printHistory();
                        }}>Print</Button>
                    </div>
                </div>

                <div className="bqm-kpi-grid">
                    <div className="bqm-kpi-card"><div className="bqm-kpi-icon blue"><CalendarOutlined /></div><div className="bqm-kpi-stats"><div className="bqm-kpi-value">{safeNumber(stats.total_bookings)}</div><div className="bqm-kpi-label">Total Bookings</div></div></div>
                    <div className="bqm-kpi-card"><div className="bqm-kpi-icon orange"><ClockCircleOutlined /></div><div className="bqm-kpi-stats"><div className="bqm-kpi-value">{safeNumber(stats.pending_approvals)}</div><div className="bqm-kpi-label">Pending Approvals</div></div></div>
                    <div className="bqm-kpi-card"><div className="bqm-kpi-icon green"><WalletOutlined /></div><div className="bqm-kpi-stats"><div className="bqm-kpi-value">{formatCurrency(stats.total_revenue)}</div><div className="bqm-kpi-label">Total Revenue</div></div></div>
                    <div className="bqm-kpi-card"><div className="bqm-kpi-icon red"><WarningOutlined /></div><div className="bqm-kpi-stats"><div className="bqm-kpi-value">{formatCurrency(stats.total_outstanding)}</div><div className="bqm-kpi-label">Outstanding Balance</div></div></div>
                </div>

                <Card className={mainCardClass} bordered={false}>
                    <Tabs activeKey={activeMainTab} onChange={setActiveMainTab} className="bqm-tabs">
                        <TabPane tab={<span><CalendarOutlined /> Bookings</span>} key="bookings">
                            <div className={filtersClass}>
                                <div className={filterGroupClass}><FilterOutlined /><Select value={filterStatus} onChange={(value) => { setFilterStatus(value); setRegularPage(1); setMultiDayPage(1); setQuotationPage(1); setHistoryPage(1); }} className="bqm-filter-select" placeholder="Status">{bookingStatusOptions.map((option) => (<Option key={option.value} value={option.value}>{option.label}</Option>))}</Select></div>
                                <div className={filterGroupClass}><CalendarOutlined /><DatePicker value={filterSpecificDate} onChange={(value) => { setFilterSpecificDate(value); setRegularPage(1); setMultiDayPage(1); setQuotationPage(1); setHistoryPage(1); }} format="YYYY-MM-DD" allowClear className="bqm-date-picker" placeholder="Select Date" /></div>
                                <div className={filterGroupClass}><AppstoreOutlined /><Select value={filterEventType} onChange={(value) => { setFilterEventType(value); setRegularPage(1); setMultiDayPage(1); setQuotationPage(1); setHistoryPage(1); }} className="bqm-filter-select" placeholder="Event Type"><Option value="all">All Event Types</Option>{eventTypes.map((eventType) => (<Option key={eventType.event_type_id || eventType.id} value={eventType.event_type_id || eventType.id}>{eventType.name}</Option>))}</Select></div>
                                <div className={`${filterGroupClass} bqm-search`}><SearchOutlined /><Input value={searchText} onChange={(event) => { setSearchText(event.target.value); setRegularPage(1); setMultiDayPage(1); setQuotationPage(1); setHistoryPage(1); }} placeholder="Search booking or customer..." allowClear className="bqm-search-input" /></div>
                                <Button type="primary" icon={<PlusOutlined />} onClick={openCreateBookingModal}>Create Booking</Button>
                            </div>

                            <Spin spinning={isLoading} indicator={<LoadingOutlined spin />}>
                                <Tabs activeKey={activeBookingTab} onChange={setActiveBookingTab} className="bqm-inner-tabs">
                                    <TabPane key="regular" tab={<span><ForkOutlined /> Regular Bookings <Badge count={regularBookings.length} overflowCount={999} /></span>}>
                                        <Table
                                            columns={regularBookingColumns}
                                            dataSource={regularBookings}
                                            rowKey={(record) => getBookingId(record)}
                                            className={tableClass}
                                            scroll={{ x: 1400 }}
                                            footer={
                                                regularBookings.length === 0
                                                    ? () => renderEmptyPaginationFooter('regular bookings')
                                                    : undefined
                                            }
                                            pagination={{
                                                current: regularPage,
                                                pageSize: REGULAR_BOOKINGS_PAGE_SIZE,
                                                total: regularBookings.length,
                                                showSizeChanger: false,
                                                showTotal: (total) => `Total ${total} regular bookings`,
                                                itemRender: renderPaginationItem,
                                                onChange: setRegularPage
                                            }}
                                        />
                                    </TabPane>
                                    <TabPane key="multi_day" tab={<span><ScheduleOutlined /> Multi-Day Events <Badge count={multiDayBookings.length} overflowCount={999} /></span>}>
                                        <Table
                                            columns={multiDayColumns}
                                            dataSource={multiDayBookings}
                                            rowKey={(record) => getBookingId(record)}
                                            className={tableClass}
                                            scroll={{ x: 1400 }}
                                            footer={
                                                multiDayBookings.length === 0
                                                    ? () => renderEmptyPaginationFooter('multi-day events')
                                                    : undefined
                                            }
                                            pagination={{
                                                current: multiDayPage,
                                                pageSize: MULTI_DAY_EVENTS_PAGE_SIZE,
                                                total: multiDayBookings.length,
                                                showSizeChanger: false,
                                                showTotal: (total) => `Total ${total} multi-day events`,
                                                itemRender: renderPaginationItem,
                                                onChange: setMultiDayPage
                                            }}
                                        />
                                    </TabPane>
                                </Tabs>
                            </Spin>
                        </TabPane>

                        <TabPane tab={<span><FileTextOutlined /> Quotations</span>} key="quotations">
                            <div className="bqm-tab-content">
                                <Alert message="Quotation Management" description="Create and manage customer quotations. Approved bookings are inserted into Order Management and Event Management only after admin confirmation." type="info" showIcon className="bqm-info-alert" />
                                <Table
                                    columns={quotationColumns}
                                    dataSource={quotations}
                                    rowKey={(record) => record.id}
                                    className={tableClass}
                                    scroll={{ x: 1100 }}
                                    footer={
                                        quotations.length === 0
                                            ? () => renderEmptyPaginationFooter('quotations')
                                            : undefined
                                    }
                                    pagination={{
                                        current: quotationPage,
                                        pageSize: QUOTATIONS_PAGE_SIZE,
                                        total: quotations.length,
                                        showSizeChanger: false,
                                        showTotal: (total) => `Total ${total} quotations`,
                                        itemRender: renderPaginationItem,
                                        onChange: setQuotationPage
                                    }}
                                />
                            </div>
                        </TabPane>

                        <TabPane tab={<span><CheckCircleOutlined /> Booking History</span>} key="history">
                            <div className="bqm-tab-content">
                                <Alert
                                    message="Completed & Cancelled Booking History"
                                    description="Bookings marked as completed or cancelled are removed from the active tables and stored here for review."
                                    type="success"
                                    showIcon
                                    className="bqm-info-alert"
                                />
                                <Table
                                    columns={historyColumns}
                                    dataSource={completedBookings}
                                    rowKey={(record) => getBookingId(record)}
                                    className={tableClass}
                                    scroll={{ x: 1200 }}
                                    footer={
                                        completedBookings.length === 0
                                            ? () => renderEmptyPaginationFooter('completed bookings')
                                            : undefined
                                    }
                                    pagination={{
                                        current: historyPage,
                                        pageSize: HISTORY_PAGE_SIZE,
                                        total: completedBookings.length,
                                        showSizeChanger: false,
                                        showTotal: (total) => `Total ${total} completed bookings`,
                                        itemRender: renderPaginationItem,
                                        onChange: setHistoryPage
                                    }}
                                />
                            </div>
                        </TabPane>

                        <TabPane tab={<span><ScheduleOutlined /> Calendar</span>} key="calendar">
                            <div className="bqm-tab-content bqm-calendar-tab-content">
                                <div className="bqm-calendar-toolbar bqm-calendar-toolbar-compact">
                                    <div>
                                        <Text strong>Event Calendar & Date Availability</Text>
                                        <div>
                                            <Text type="secondary">
                                                <EditOutlined /> Click any date to edit availability (Available, Limited Slots, Fully Booked, or Unavailable)
                                            </Text>
                                        </div>
                                    </div>
                                    <Radio.Group
                                        value={calendarMode}
                                        onChange={(event) => setCalendarMode(event.target.value)}
                                        buttonStyle="solid"
                                        size="small"
                                    >
                                        <Radio.Button value="month">Month</Radio.Button>
                                        <Radio.Button value="year">Year</Radio.Button>
                                    </Radio.Group>
                                </div>

                                <div className="bqm-calendar-legend">
                                    <div className="bqm-legend-item">
                                        <span className="bqm-legend-color available"></span>
                                        <span>Available</span>
                                    </div>
                                    <div className="bqm-legend-item">
                                        <span className="bqm-legend-color limited"></span>
                                        <span>Limited Slots</span>
                                    </div>
                                    <div className="bqm-legend-item">
                                        <span className="bqm-legend-color fully-booked"></span>
                                        <span>Fully Booked</span>
                                    </div>
                                    <div className="bqm-legend-item">
                                        <span className="bqm-legend-color unavailable"></span>
                                        <span>Unavailable</span>
                                    </div>
                                    <div className="bqm-legend-item">
                                        <span className="bqm-legend-color event"></span>
                                        <span>Has Events</span>
                                    </div>
                                    <div className="bqm-legend-item">
                                        <span className="bqm-legend-color clickable"></span>
                                        <span>Click to Edit</span>
                                    </div>
                                </div>

                                <div className="bqm-calendar-wrapper bqm-calendar-wrapper-compact">
                                    <Calendar
                                        value={calendarCursor}
                                        mode={calendarMode}
                                        dateCellRender={dateCellRender}
                                        onPanelChange={(dateValue, mode) => {
                                            setCalendarCursor(dateValue);
                                            setCalendarMode(mode);
                                        }}
                                    />
                                </div>
                            </div>
                        </TabPane>
                    </Tabs>
                </Card>

                {/* ====================================================
                    BOOKING DETAILS MODAL
                ==================================================== */}
                <Modal
                    title={
                        <div className="bqm-modal-header-clean">
                            <div className="bqm-modal-title-icon"><EyeOutlined /></div>
                            <div className="bqm-modal-title-text">Booking Details</div>
                            <div className="bqm-modal-badge">{safeString(selectedBooking?.booking_no)}</div>
                        </div>
                    }
                    open={bookingDetailsModalVisible}
                    onCancel={() => setBookingDetailsModalVisible(false)}
                    maskClosable={false}
                    keyboard={false}
                    closable={true}
                    width={750}
                    className="bqm-modal-clean bqm-modal-no-scroll"
                    footer={
                        <div className="bqm-modal-footer-simple">
                            <div className="bqm-simple-buttons">
                                {bookingStep > 0 && (
                                    <Button onClick={prevBookingStep} icon={<LeftOutlined />}>
                                        Previous
                                    </Button>
                                )}
                                {bookingStep < 4 ? (
                                    <Button type="primary" onClick={nextBookingStep} icon={<RightOutlined />} iconPosition="end">
                                        Next
                                    </Button>
                                ) : (
                                    <Button type="primary" onClick={() => setBookingDetailsModalVisible(false)}>
                                        Close
                                    </Button>
                                )}
                            </div>
                        </div>
                    }
                    bodyStyle={{ padding: 0, maxHeight: 'none', overflow: 'visible' }}
                >
                    <div className="bqm-modal-step-container">
                        <div className="bqm-modal-step-header">
                            <div className="bqm-step-icon">{bookingSteps[bookingStep].icon}</div>
                            <div>
                                <div className="bqm-step-title">{bookingSteps[bookingStep].title}</div>
                                <div className="bqm-step-desc">
                                    {bookingStep === 0 && 'Personal and contact information'}
                                    {bookingStep === 1 && 'Date, time, venue and guest count'}
                                    {bookingStep === 2 && 'Menu items and package details'}
                                    {bookingStep === 3 && 'Additional notes and requirements'}
                                    {bookingStep === 4 && 'Payment overview and status'}
                                </div>
                            </div>
                        </div>
                        <div className="bqm-modal-step-body">
                            {renderBookingStepContent()}
                        </div>
                    </div>
                </Modal>

                {/* ====================================================
                    CREATE BOOKING MODAL
                ==================================================== */}
                <Modal
                    title={
                        <div className="bqm-modal-header-clean">
                            <div className="bqm-modal-title-icon"><PlusOutlined /></div>
                            <div className="bqm-modal-title-text">Create New Booking</div>
                        </div>
                    }
                    open={quotationModalVisible}
                    onCancel={() => setQuotationModalVisible(false)}
                    maskClosable={false}
                    keyboard={false}
                    footer={null}
                    width={800}
                    className="bqm-modal-clean"
                    bodyStyle={{ padding: 0, maxHeight: '70vh', overflowY: 'auto' }}
                >
                    <div className="bqm-modal-clean-content">
                        <Form form={quotationForm} layout="vertical" onFinish={saveBooking}>
                            <Row gutter={16}>
                                <Col span={12}><Form.Item name="customer_name" label="Customer Name" rules={[{ required: true }]}><Input placeholder="Enter customer name" /></Form.Item></Col>
                                <Col span={12}><Form.Item name="customer_email" label="Email" rules={[{ required: true, type: 'email' }]}><Input placeholder="customer@example.com" /></Form.Item></Col>
                            </Row>
                            <Row gutter={16}>
                                <Col span={12}><Form.Item name="customer_phone" label="Phone Number"><Input placeholder="Contact number" /></Form.Item></Col>
                                <Col span={12}><Form.Item name="customer_address" label="Address"><Input placeholder="Address" /></Form.Item></Col>
                            </Row>
                            <Row gutter={16}>
                                <Col span={12}><Form.Item name="event_type_id" label="Event Type" rules={[{ required: true }]}><Select placeholder="Select event type">{eventTypes.map((type) => (<Option key={type.event_type_id || type.id} value={type.event_type_id || type.id}>{type.name}</Option>))}</Select></Form.Item></Col>
                                <Col span={12}><Form.Item name="guests_count" label="Number of Guests" rules={[{ required: true }]}><InputNumber min={1} style={{ width: '100%' }} placeholder="Total pax" /></Form.Item></Col>
                            </Row>
                            <Row gutter={16}>
                                <Col span={12}><Form.Item name="event_date" label="Event Date" rules={[{ required: true }]}><DatePicker style={{ width: '100%' }} /></Form.Item></Col>
                                <Col span={12}><Form.Item name="event_time" label="Event Time" rules={[{ required: true }]}><Select placeholder="Select time">{timeOptions.map((time) => (<Option key={time} value={time}>{time}</Option>))}</Select></Form.Item></Col>
                            </Row>
                            <Form.Item name="venue" label="Venue" rules={[{ required: true }]}><Input placeholder="Event venue" /></Form.Item>
                            <Form.Item name="total_amount" label="Total Amount (₱)" rules={[{ required: true }]}><InputNumber min={0} style={{ width: '100%' }} formatter={value => `₱ ${value}`} placeholder="0" /></Form.Item>
                            <Form.Item name="special_requests" label="Special Requests"><TextArea rows={3} placeholder="Any special requests or notes..." /></Form.Item>
                            <div className="bqm-modal-buttons-clean">
                                <Button onClick={() => setQuotationModalVisible(false)}>Cancel</Button>
                                <Button type="primary" htmlType="submit">Create Booking</Button>
                            </div>
                        </Form>
                    </div>
                </Modal>

                {/* ====================================================
                    REJECT WITH OPTION MODAL
                ==================================================== */}
                <Modal
                    title={
                        <div className="bqm-modal-header-clean">
                            <div className="bqm-modal-title-icon"><CloseCircleOutlined /></div>
                            <div className="bqm-modal-title-text">Reject or Reschedule Booking</div>
                            <div className="bqm-modal-badge">{safeString(selectedBooking?.booking_no)}</div>
                        </div>
                    }
                    open={rejectReasonModalVisible}
                    onCancel={() => setRejectReasonModalVisible(false)}
                    maskClosable={false}
                    keyboard={false}
                    footer={null}
                    width={500}
                    className="bqm-modal-clean"
                >
                    <div className="bqm-modal-clean-content">
                        <Form form={rejectForm} layout="vertical" onFinish={handleRejectBooking}>
                            <Form.Item name="action" label="Action" rules={[{ required: true }]}>
                                <Radio.Group>
                                    <Radio value="reject">Reject Booking</Radio>
                                    <Radio value="reschedule">Request Reschedule</Radio>
                                </Radio.Group>
                            </Form.Item>
                            <Form.Item name="reason" label="Reason" rules={[{ required: true }]}>
                                <TextArea rows={3} placeholder="Please provide a reason..." />
                            </Form.Item>
                            <div className="bqm-modal-buttons-clean">
                                <Button onClick={() => setRejectReasonModalVisible(false)}>Cancel</Button>
                                <Button type="primary" htmlType="submit">Submit</Button>
                            </div>
                        </Form>
                    </div>
                </Modal>

                {/* ====================================================
                    RESCHEDULE MODAL
                ==================================================== */}
                <Modal
                    title={
                        <div className="bqm-modal-header-clean">
                            <div className="bqm-modal-title-icon"><SyncOutlined /></div>
                            <div className="bqm-modal-title-text">Request Reschedule</div>
                            <div className="bqm-modal-badge">{safeString(selectedBooking?.booking_no)}</div>
                        </div>
                    }
                    open={rescheduleModalVisible}
                    onCancel={() => setRescheduleModalVisible(false)}
                    maskClosable={false}
                    keyboard={false}
                    footer={null}
                    width={500}
                    className="bqm-modal-clean"
                >
                    <div className="bqm-modal-clean-content">
                        <Alert
                            message="Customer-Requested Reschedule"
                            description={`Current event date: ${formatDateShort(selectedBooking?.event_date)} at ${selectedBooking?.event_time}`}
                            type="info"
                            showIcon
                            style={{ marginBottom: 16 }}
                        />
                        <Form form={rescheduleForm} layout="vertical" onFinish={handleReschedule}>
                            <Form.Item 
                                name="new_date" 
                                label="Requested New Date" 
                                rules={[{ required: true }]}
                                extra="This is the date the customer has requested"
                            >
                                <DatePicker style={{ width: '100%' }} disabledDate={(current) => current && current < dayjs().startOf('day')} />
                            </Form.Item>
                            <Form.Item 
                                name="new_time" 
                                label="Requested New Time" 
                                rules={[{ required: true }]}
                                extra="This is the time the customer has requested"
                            >
                                <Select placeholder="Select time">
                                    {timeOptions.map(time => <Option key={time} value={time}>{time}</Option>)}
                                </Select>
                            </Form.Item>
                            <Form.Item name="reason" label="Reason for Reschedule" rules={[{ required: true }]}>
                                <TextArea rows={3} placeholder="Please provide a reason for the reschedule..." />
                            </Form.Item>
                            <div className="bqm-modal-buttons-clean">
                                <Button onClick={() => setRescheduleModalVisible(false)}>Cancel</Button>
                                <Button type="primary" htmlType="submit">Submit Reschedule Request</Button>
                            </div>
                        </Form>
                    </div>
                </Modal>

                {/* ====================================================
                    CANCEL MODAL
                ==================================================== */}
                <Modal
                    title={
                        <div className="bqm-modal-header-clean">
                            <div className="bqm-modal-title-icon"><StopOutlined /></div>
                            <div className="bqm-modal-title-text">Cancel Booking</div>
                            <div className="bqm-modal-badge">{safeString(selectedBooking?.booking_no)}</div>
                        </div>
                    }
                    open={cancelReasonModalVisible}
                    onCancel={() => setCancelReasonModalVisible(false)}
                    maskClosable={false}
                    keyboard={false}
                    footer={null}
                    width={500}
                    className="bqm-modal-clean"
                >
                    <div className="bqm-modal-clean-content">
                        <Form form={cancelForm} layout="vertical" onFinish={handleCancelBooking}>
                            <Form.Item name="reason" label="Cancellation Reason" rules={[{ required: true }]}>
                                <TextArea rows={3} placeholder="Please provide a reason for cancellation..." />
                            </Form.Item>
                            <div className="bqm-modal-buttons-clean">
                                <Button onClick={() => setCancelReasonModalVisible(false)}>Cancel</Button>
                                <Button type="primary" danger htmlType="submit">Confirm Cancellation</Button>
                            </div>
                        </Form>
                    </div>
                </Modal>

                {/* ====================================================
                    PAYMENT MODAL
                ==================================================== */}
                <Modal
                    title={<div className="bqm-modal-header-clean"><div className="bqm-modal-title-icon"><BankOutlined /></div><div className="bqm-modal-title-text">Record Payment</div></div>}
                    open={paymentModalVisible}
                    onCancel={() => setPaymentModalVisible(false)}
                    maskClosable={false}
                    keyboard={false}
                    footer={null}
                    width={500}
                    className="bqm-modal-clean"
                >
                    <div className="bqm-modal-clean-content">
                        <div className="bqm-clean-amount-badge">Outstanding: <strong>{selectedBooking ? formatCurrency(selectedBooking.balance || selectedBooking.total_amount) : '₱0.00'}</strong></div>
                        <Form form={paymentForm} layout="vertical" onFinish={savePayment}>
                            <Form.Item name="amount" label="Payment Amount" rules={[{ required: true }]}><InputNumber min={1} style={{ width: '100%' }} formatter={value => `₱ ${value}`} placeholder="0" /></Form.Item>
                            <Form.Item name="method" label="Payment Method" rules={[{ required: true }]}><Select>{paymentMethodOptions.map((option) => (<Option key={option.value} value={option.value}>{option.label}</Option>))}</Select></Form.Item>
                            <Form.Item name="reference" label="Reference Number"><Input placeholder="Transaction reference" /></Form.Item>
                            <div className="bqm-modal-buttons-clean"><Button onClick={() => setPaymentModalVisible(false)}>Cancel</Button><Button type="primary" htmlType="submit">Record Payment</Button></div>
                        </Form>
                    </div>
                </Modal>

                {/* ====================================================
                    CALENDAR AVAILABILITY EDIT MODAL
                ==================================================== */}
                <Modal
                    title={
                        <div className="bqm-modal-header-clean">
                            <div className="bqm-modal-title-icon"><EditOutlined /></div>
                            <div className="bqm-modal-title-text">Edit Date Availability</div>
                            <div className="bqm-modal-badge">{selectedCalendarDate.format('MMM DD, YYYY')}</div>
                        </div>
                    }
                    open={availabilityModalVisible}
                    onCancel={() => setAvailabilityModalVisible(false)}
                    maskClosable={false}
                    keyboard={false}
                    footer={null}
                    width={560}
                    className="bqm-modal-clean"
                >
                    <div className="bqm-modal-clean-content">
                        <Alert 
                            message="Date Availability Control" 
                            description="Set this date's booking availability. This will control how many bookings can be accepted for this date." 
                            type="info" 
                            showIcon 
                            style={{ marginBottom: 20 }} 
                        />
                        <Form form={availabilityForm} layout="vertical" onFinish={saveCalendarAvailability}>
                            <Form.Item 
                                name="status" 
                                label="Availability Status" 
                                rules={[{ required: true }]}
                                extra="Choose how this date should be treated for new bookings"
                            >
                                <Select>
                                    {availabilityStatusOptions.map((option) => (
                                        <Option key={option.value} value={option.value}>
                                            <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                                                {getAvailabilityConfig(option.value).icon}
                                                <span>{option.label}</span>
                                            </div>
                                        </Option>
                                    ))}
                                </Select>
                            </Form.Item>
                            
                            <Form.Item 
                                name="max_bookings" 
                                label="Maximum Bookings Limit"
                                extra="Leave empty for unlimited bookings (only applies when status is Available)"
                            >
                                <InputNumber 
                                    min={1} 
                                    style={{ width: '100%' }} 
                                    placeholder="Leave blank for no limit" 
                                />
                            </Form.Item>
                            
                            <Form.Item 
                                name="notes" 
                                label="Admin Notes"
                                extra="Internal notes about why this date has restrictions"
                            >
                                <TextArea 
                                    rows={3} 
                                    placeholder="E.g., Holiday surcharge applies, Limited staff available, etc." 
                                />
                            </Form.Item>
                            
                            <div className="bqm-modal-buttons-clean">
                                <Button danger onClick={resetCalendarAvailability}>Reset to Available</Button>
                                <Button onClick={() => setAvailabilityModalVisible(false)}>Cancel</Button>
                                <Button type="primary" htmlType="submit">Save Settings</Button>
                            </div>
                        </Form>
                    </div>
                </Modal>
            </div>
        </ConfigProvider>
    );
};

export default BookingQuotationManagement;