// src/components/EventManagement.jsx - COMPLETE FIXED
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
    Timeline,
    Progress,
    Row,
    Col,
    Descriptions,
    Alert,
    DatePicker,
    Popconfirm,
    Badge,
    Empty,
    Form,
    Dropdown,
    List,
    Steps,
    Radio,
    ConfigProvider,
    theme as antdTheme,
    Switch,
    Collapse,
    Statistic,
    notification,
    Pagination
} from 'antd';

import {
    CalendarOutlined,
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
    TeamOutlined,
    PrinterOutlined,
    ExportOutlined,
    TruckOutlined,
    LoadingOutlined,
    PlayCircleOutlined,
    EnvironmentOutlined,
    ScheduleOutlined,
    PhoneOutlined,
    MailOutlined,
    FlagOutlined,
    FilterOutlined,
    DashboardOutlined,
    OrderedListOutlined,
    PlusCircleOutlined,
    UserAddOutlined,
    CheckSquareOutlined,
    VideoCameraOutlined,
    FileTextOutlined,
    BarChartOutlined,
    WarningOutlined,
    ToolOutlined,
    SwapOutlined,
    UnorderedListOutlined,
    SyncOutlined,
    StopOutlined,
    AimOutlined,
    CarOutlined,
    HomeOutlined,
    FieldTimeOutlined,
    LeftOutlined,
    RightOutlined,
    RiseOutlined
} from '@ant-design/icons';

import { format, differenceInDays } from 'date-fns';
import dayjs from 'dayjs';
import * as XLSX from 'xlsx';
import '../styles/EventsManagement.css';
import { eventAPI, employeeAPI, equipmentAPI, scheduleAPI, inventoryAPI } from '../../../services/api';
import api from '../../../services/api';

const { Title, Text } = Typography;
const { Option } = Select;
const { TabPane } = Tabs;
const { Step } = Steps;
const { Panel } = Collapse;
const { TextArea } = Input;

const EventManagement = () => {
    // ==================== STATE MANAGEMENT ====================
    const [events, setEvents] = useState([]);
    const [selectedEvent, setSelectedEvent] = useState(null);
    const [loading, setLoading] = useState(false);
    const [searchText, setSearchText] = useState('');
    const [filterStatus, setFilterStatus] = useState('all');
    const [filterCustomer, setFilterCustomer] = useState('all');
    const [selectedDate, setSelectedDate] = useState(null);
    const [currentPage, setCurrentPage] = useState(1);
    const [pageSize, setPageSize] = useState(10);

    const [activeMainTab, setActiveMainTab] = useState('events');
    const [isDarkMode, setIsDarkMode] = useState(() => {
        const savedTheme = localStorage.getItem('theme');
        if (savedTheme === 'dark') return true;
        if (savedTheme === 'light') return false;
        return document.body.classList.contains('dark-mode');
    });
    
    const [uniqueCustomers, setUniqueCustomers] = useState([]);
    
    // Ongoing Events Monitoring states
    const [ongoingEvents, setOngoingEvents] = useState([]);
    const [selectedOngoingEvent, setSelectedOngoingEvent] = useState(null);
    
    // Modal states
    const [eventDetailsModalVisible, setEventDetailsModalVisible] = useState(false);
    const [editEventModalVisible, setEditEventModalVisible] = useState(false);
    
    // Staff Assignment states
    const [staffAssignmentModalVisible, setStaffAssignmentModalVisible] = useState(false);
    const [selectedEventForStaff, setSelectedEventForStaff] = useState(null);
    const [addStaffModalVisible, setAddStaffModalVisible] = useState(false);
    const [staffList, setStaffList] = useState([]);
    const [staffForm] = Form.useForm();
    const [selectedEquipmentList, setSelectedEquipmentList] = useState([]);

    // Event Checklist states
    const [checklistModalVisible, setChecklistModalVisible] = useState(false);
    const [selectedEventForChecklist, setSelectedEventForChecklist] = useState(null);
    const [checklist, setChecklist] = useState([]);
    
    // Delivery Tracking states
    const [deliveryTrackingModalVisible, setDeliveryTrackingModalVisible] = useState(false);
    const [selectedEventForDelivery, setSelectedEventForDelivery] = useState(null);
    const [deliveryTrackings, setDeliveryTrackings] = useState([]);
    const [addDeliveryModalVisible, setAddDeliveryModalVisible] = useState(false);
    const [addDeliveryForm] = Form.useForm();
    
    // Live Event Status states
    const [liveStatusModalVisible, setLiveStatusModalVisible] = useState(false);
    const [selectedEventForStatus, setSelectedEventForStatus] = useState(null);
    
    // Equipment Check-In/Check-Out Tracking states
    const [equipmentTrackingModalVisible, setEquipmentTrackingModalVisible] = useState(false);
    const [selectedEventForEquipment, setSelectedEventForEquipment] = useState(null);
    const [equipmentCheckoutModalVisible, setEquipmentCheckoutModalVisible] = useState(false);
    const [equipmentReturnModalVisible, setEquipmentReturnModalVisible] = useState(false);
    const [selectedEquipmentItem, setSelectedEquipmentItem] = useState(null);
    const [equipmentForm] = Form.useForm();
    const [availableEquipmentList, setAvailableEquipmentList] = useState([]);
    const [equipmentTrackingData, setEquipmentTrackingData] = useState([]);
    
    // Event Form
    const [eventForm] = Form.useForm();

    const isMounted = useRef(true);

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

    // ==================== DATA LOADING ====================
    const extractList = (response) => {
        const payload = response?.data?.data;
        if (Array.isArray(payload)) return payload;
        if (Array.isArray(payload?.data)) return payload.data;
        return [];
    };

    useEffect(() => {
        loadEvents();
        loadStaff();
        loadEquipment();
        loadEquipmentTracking();
    }, []);

    const loadEvents = async () => {
        setLoading(true);
        try {
            const response = await eventAPI.getEvents({ per_page: 100 });
            const records = extractList(response);
            
            const transformedRecords = records.map(record => ({
                ...record,
                booking_id: record.booking_id || record.id,
                booking_no: record.booking_no || `BK-${record.id}`,
                display_name: record.display_name || `${record.customer_name || 'Unknown'} - ${record.event_name || 'Event'}`,
                customer_name: record.customer_name || 'Unknown',
                total_days: record.total_days || 1,
                current_day: record.current_day || 1,
                customer_email: record.customer_email || '',
                customer_phone: record.customer_phone || ''
            }));
            
            setEvents(transformedRecords);
            
            // Filter ongoing events
            const ongoing = transformedRecords.filter(e => {
                const eventDate = dayjs(e.date);
                const today = dayjs();
                const isPastOrToday = eventDate.isSame(today, 'day') || eventDate.isBefore(today);
                return (e.status === 'ongoing' || (e.status === 'upcoming' && isPastOrToday)) && e.status !== 'completed';
            }).map(e => ({ ...e, status: 'ongoing' }));
            
            setOngoingEvents(ongoing);
            
            const customers = [...new Set(transformedRecords.map(e => e.customer_name).filter(Boolean))];
            setUniqueCustomers(customers);
            
        } catch (error) {
            console.error('Unable to load events from database', error);
            setEvents([]);
            setOngoingEvents([]);
            message.error('Unable to load events from the database');
        } finally { setLoading(false); }
    };

    const loadStaff = async () => {
        try {
            const response = await employeeAPI.getActive();
            const staffData = response?.data?.data || response?.data || [];
            setStaffList(staffData);
        } catch (error) {
            console.error('Unable to load staff', error);
            setStaffList([]);
        }
    };

    const loadEquipment = async () => {
        try {
            const response = await equipmentAPI.getEquipment({ per_page: 100, active: 1 });
            const equipmentData = response.data?.data?.data || response.data?.data || [];
            setAvailableEquipmentList(equipmentData.map(item => ({
                id: item.equipment_id || item.id,
                name: item.name || 'Unnamed',
                category: item.category || 'Uncategorized',
                unit: item.unit || 'units',
                available: item.available_quantity || item.available || (item.total_quantity - item.reserved_quantity) || 0,
                total_quantity: item.total_quantity || 0
            })));
        } catch (error) {
            console.error('Unable to load equipment', error);
            setAvailableEquipmentList([]);
        }
    };

    // ==================== ✅ FIXED: LOAD EQUIPMENT TRACKING - NO 404 ERRORS ====================
    const loadEquipmentTracking = async () => {
        try {
            let data = [];
            
            // Get events data (this endpoint definitely exists)
            const eventsResponse = await eventAPI.getEvents({ per_page: 100 });
            const eventsData = eventsResponse.data?.data?.data || eventsResponse.data?.data || [];
            
            // Extract equipment_in_out from events
            eventsData.forEach(event => {
                if (event.equipment_in_out && event.equipment_in_out.length > 0) {
                    event.equipment_in_out.forEach(eq => {
                        let equipmentName = 'Unknown Equipment';
                        if (eq.equipment) {
                            equipmentName = eq.equipment.name || 'Unknown Equipment';
                        } else if (eq.equipment_name) {
                            equipmentName = eq.equipment_name;
                        } else if (eq.name) {
                            equipmentName = eq.name;
                        }
                        
                        data.push({
                            ...eq,
                            id: eq.id || eq.booking_equipment_id || `eq-${Date.now()}-${Math.random()}`,
                            equipment_name: equipmentName,
                            equipment: { name: equipmentName, category: eq.category || '' },
                            booking: {
                                booking_no: event.booking_no || event.id,
                                serviceEvent: {
                                    customer: {
                                        person: { full_name: event.customer_name || 'Unknown' }
                                    }
                                }
                            },
                            status: eq.status || 'checked_out',
                            checked_out_date: eq.checked_out_date || eq.checkout_date || eq.created_at || new Date().toISOString(),
                            rental_end_date: eq.rental_end_date || eq.expected_return_date || dayjs().add(7, 'days').format('YYYY-MM-DD'),
                            customer_name: event.customer_name || 'Unknown'
                        });
                    });
                }
            });
            
            setEquipmentTrackingData(data);
            
            if (data.length === 0) {
                console.log('No equipment tracking data found in events');
            } else {
                console.log('✅ Loaded', data.length, 'equipment tracking items from events');
            }
            
        } catch (error) {
            console.error('Unable to load equipment tracking:', error);
            setEquipmentTrackingData([]);
        }
    };

    // Auto-update ongoing events every minute
    useEffect(() => {
        const interval = setInterval(() => {
            const now = dayjs();
            const updatedEvents = events.map(event => {
                const eventDate = dayjs(event.date);
                if (event.status === 'upcoming' && (eventDate.isSame(now, 'day') || eventDate.isBefore(now))) {
                    return { ...event, status: 'ongoing' };
                }
                return event;
            });
            setEvents(updatedEvents);
            
            const ongoing = updatedEvents.filter(e => e.status === 'ongoing' && e.status !== 'completed');
            setOngoingEvents(ongoing);
        }, 60000);
        
        return () => clearInterval(interval);
    }, [events]);

    // ==================== HELPER FUNCTIONS ====================
    const getStatusConfig = (status) => {
        const config = {
            upcoming: { color: '#3b82f6', text: 'Upcoming', icon: <ClockCircleOutlined />, bg: 'rgba(59, 130, 246, 0.1)' },
            ongoing: { color: '#f59e0b', text: 'Ongoing', icon: <PlayCircleOutlined />, bg: 'rgba(245, 158, 11, 0.1)' },
            completed: { color: '#10b981', text: 'Completed', icon: <CheckCircleOutlined />, bg: 'rgba(16, 185, 129, 0.1)' },
            cancelled: { color: '#ef4444', text: 'Cancelled', icon: <StopOutlined />, bg: 'rgba(239, 68, 68, 0.1)' }
        };
        return config[status] || config.upcoming;
    };

    const getDeliveryStatusConfig = (status) => {
        const config = {
            pending: { color: 'default', text: 'Pending' },
            departed: { color: 'processing', text: 'Departed' },
            en_route: { color: 'warning', text: 'En Route' },
            arrived: { color: 'success', text: 'Arrived' },
            completed: { color: 'success', text: 'Completed' },
            cancelled: { color: 'error', text: 'Cancelled' }
        };
        return config[status] || config.pending;
    };

    const getEquipmentStatusConfig = (status) => {
        const config = {
            reserved: { color: 'processing', text: 'Reserved' },
            checked_out: { color: 'warning', text: 'Checked Out' },
            returned: { color: 'success', text: 'Returned' },
            damaged: { color: 'error', text: 'Damaged' },
            missing: { color: 'error', text: 'Missing' }
        };
        return config[status] || config.reserved;
    };

    // ==================== START EVENT ====================
    const handleStartEvent = async (record) => {
        Modal.confirm({
            title: 'Start Event',
            content: `Mark "${record.display_name || record.event_name}" (Booking: ${record.booking_no}) as ongoing?`,
            okText: 'Start Event',
            cancelText: 'Cancel',
            onOk: async () => {
                try {
                    await eventAPI.updateEvent(record.id, { status: 'ongoing' });
                    message.success(`Event "${record.display_name || record.event_name}" has started`);
                    await loadEvents();
                    notification.success({
                        message: 'Event Started',
                        description: `${record.display_name || record.event_name} is now in ongoing events.`,
                        placement: 'topRight',
                    });
                } catch (error) {
                    console.error('Start event error:', error);
                    message.error(error.response?.data?.message || 'Failed to start event');
                }
            }
        });
    };

    // ==================== STAFF FUNCTIONS ====================
    const handleStaffAssignment = (record) => {
        setSelectedEventForStaff(record);
        setStaffAssignmentModalVisible(true);
    };

    const handleAddStaffToEvent = async (values) => {
        try {
            const event = selectedEventForStaff;
            const eventDate = event.date;
            const eventEndDate = event.end_date || event.date;
            
            let startTime = '08:00:00';
            let endTime = '17:00:00';
            if (event.time) {
                try {
                    const [time, modifier] = event.time.split(' ');
                    let [hour, minute] = time.split(':');
                    hour = parseInt(hour);
                    if (modifier === 'PM' && hour !== 12) hour += 12;
                    if (modifier === 'AM' && hour === 12) hour = 0;
                    startTime = `${String(hour).padStart(2, '0')}:${minute || '00'}:00`;
                    endTime = `${String(hour + 8).padStart(2, '0')}:${minute || '00'}:00`;
                } catch (e) {}
            }
            
            let existingSchedules = [];
            try {
                const response = await scheduleAPI.getByEmployee(values.staff_id);
                existingSchedules = response?.data?.data || response?.data || [];
            } catch (e) {}
            
            let startDate = dayjs(eventDate);
            let endDate = dayjs(eventEndDate);
            let currentDate = startDate;
            let createdCount = 0;
            
            while (currentDate.isSameOrBefore(endDate)) {
                const dateStr = currentDate.format('YYYY-MM-DD');
                const existing = existingSchedules.find(s => 
                    s.work_date === dateStr && s.employee_id === values.staff_id
                );
                
                if (!existing) {
                    await scheduleAPI.create({
                        employee_id: values.staff_id,
                        work_date: dateStr,
                        start_time: startTime,
                        end_time: endTime,
                        break_minutes: 60,
                        assignment_details: JSON.stringify({
                            placement: event.location || 'Event Venue',
                            notes: `Assigned to event: ${event.display_name || event.event_name} - Role: ${values.role}`,
                            booking_id: event.id,
                            role: values.role
                        }),
                        status: 'scheduled'
                    });
                    createdCount++;
                }
                currentDate = currentDate.add(1, 'day');
            }
            
            const staffData = {
                staff_id: values.staff_id,
                role: values.role,
                schedule: `${startTime} - ${endTime}`,
                phone: values.phone || event.customer_phone || '',
                email: values.email || event.customer_email || '',
                status: 'confirmed',
                schedule_created: createdCount
            };
            
            await eventAPI.assignStaff(event.id, staffData);
            
            message.success(`Staff assigned to ${event.display_name || event.event_name} with ${createdCount} schedule(s) created`);
            setAddStaffModalVisible(false);
            staffForm.resetFields();
            await loadEvents();
            await loadStaff();
        } catch (error) {
            console.error('Staff assignment error:', error);
            message.error(error.response?.data?.message || 'Failed to assign staff');
        }
    };

    const handleRemoveStaff = async (eventId, staffId) => {
        try {
            await eventAPI.removeStaff(eventId, staffId);
            message.success('Staff removed from event');
            await loadEvents();
        } catch (error) {
            message.error('Failed to remove staff');
        }
    };

    const handleUpdateStaffStatus = async (eventId, staffId, status) => {
        try {
            await eventAPI.updateStaffStatus(eventId, staffId, { status });
            message.success(`Staff status updated to ${status}`);
            await loadEvents();
        } catch (error) {
            message.error('Failed to update staff status');
        }
    };

    // ==================== CHECKLIST FUNCTIONS ====================
    const handleViewChecklist = async (record) => {
        setSelectedEventForChecklist(record);
        try {
            const response = await eventAPI.getEvent(record.id);
            const eventData = response.data?.data || response.data || record;
            const existingChecklist = eventData.checklist || [];
            const deliveryItems = (eventData.delivery_tracking || []).map(d => ({
                id: `delivery-${d.id || Date.now()}`,
                task: `🚚 ${d.item || 'Delivery'} - ${d.vehicle || 'Vehicle'} (${d.driver || 'Driver'})`,
                assigned_to: d.driver || 'Driver',
                status: d.status === 'arrived' || d.status === 'completed' || d.status === 'delivered' ? 'completed' : 'pending',
                is_delivery: true,
                delivery_id: d.id,
                notes: `Location: ${d.location || 'TBD'} | ETA: ${d.eta || 'TBD'} | Items: ${d.items || 'N/A'}`,
                source: 'delivery_preparation',
                delivery_details: d
            }));
            const combinedChecklist = [...deliveryItems, ...existingChecklist];
            setChecklist(combinedChecklist);
            setChecklistModalVisible(true);
        } catch (error) {
            console.error('Failed to load checklist data:', error);
            message.error('Failed to load checklist data');
        }
    };

    const handleAddChecklistItem = async (values) => {
        try {
            const task = values.task || values;
            const assignedTo = values.assigned_to || 'Unassigned';
            const newItem = {
                id: `custom-${Date.now()}`,
                task: typeof task === 'string' ? task : task.task,
                assigned_to: assignedTo,
                status: 'pending',
                is_custom: true
            };
            await eventAPI.addChecklistItem(selectedEventForChecklist.id, newItem);
            message.success('Checklist item added');
            await handleViewChecklist(selectedEventForChecklist);
        } catch (error) {
            console.error('Failed to add checklist item:', error);
            message.error('Failed to add checklist item');
        }
    };

    const handleUpdateChecklistItem = async (itemId, status) => {
        try {
            const item = checklist.find(i => i.id === itemId);
            if (item?.is_delivery && item.delivery_id) {
                const deliveryStatus = status === 'completed' ? 'arrived' : 'pending';
                await eventAPI.updateDeliveryStatus(selectedEventForChecklist.id, item.delivery_id, { status: deliveryStatus });
                message.success('Delivery status updated');
                setChecklist(prev => prev.map(i => i.id === itemId ? { ...i, status } : i));
            } else {
                await eventAPI.updateChecklistItem(selectedEventForChecklist.id, itemId, { status });
                message.success('Checklist item updated');
                setChecklist(prev => prev.map(i => i.id === itemId ? { ...i, status } : i));
            }
            await loadEvents();
        } catch (error) {
            console.error('Update checklist error:', error);
            message.error('Failed to update checklist');
        }
    };

    const handleDeleteChecklistItem = async (eventId, itemId) => {
        try {
            const item = checklist.find(i => i.id === itemId);
            if (item?.is_delivery) {
                message.warning('This item is managed from Delivery Preparation section');
                return;
            }
            await eventAPI.deleteChecklistItem(eventId, itemId);
            message.success('Checklist item deleted');
            setChecklist(prev => prev.filter(i => i.id !== itemId));
            await loadEvents();
        } catch (error) {
            message.error('Failed to delete checklist item');
        }
    };

    // ==================== DELIVERY FUNCTIONS ====================
    const handleViewDeliveryTracking = (record) => {
        setSelectedEventForDelivery(record);
        const deliveries = record.delivery_tracking || record.deliveries || [];
        setDeliveryTrackings(deliveries);
        setDeliveryTrackingModalVisible(true);
    };

    const handleAddDeliverySubmit = async (values) => {
        try {
            const newDelivery = {
                vehicle: values.vehicle,
                driver: values.driver,
                driver_phone: values.driver_phone || '',
                eta: values.eta,
                location: values.location || '',
                status: 'pending',
                items: values.items || '',
                created_at: new Date().toISOString()
            };
            await eventAPI.addDelivery(selectedEventForDelivery.id, newDelivery);
            message.success('Delivery added successfully');
            setAddDeliveryModalVisible(false);
            addDeliveryForm.resetFields();
            await loadEvents();
            const updatedEvent = events.find(e => e.id === selectedEventForDelivery.id);
            if (updatedEvent) {
                setDeliveryTrackings(updatedEvent.delivery_tracking || updatedEvent.deliveries || []);
            }
        } catch (error) {
            console.error('Add delivery error:', error);
            message.error(error.response?.data?.message || 'Failed to add delivery');
        }
    };

    const handleUpdateDeliveryStatus = async (deliveryId, status, location = null) => {
        try {
            await eventAPI.updateDeliveryStatus(selectedEventForDelivery.id, deliveryId, { status, location });
            message.success(`Delivery status updated to ${status}`);
            await loadEvents();
            setDeliveryTrackings(prev => 
                prev.map(d => d.id === deliveryId ? { ...d, status, location: location || d.location } : d)
            );
        } catch (error) {
            console.error('Update delivery error:', error);
            message.error(error.response?.data?.message || 'Failed to update delivery status');
        }
    };

    // ==================== EQUIPMENT FUNCTIONS ====================
    const handleCheckoutEquipment = async (values) => {
        try {
            const payload = {
                equipment_id: values.equipment_id,
                quantity: values.quantity,
                expected_return_date: values.expected_return_date ? values.expected_return_date.format('YYYY-MM-DD') : dayjs().add(7, 'days').format('YYYY-MM-DD'),
                condition_notes_out: values.condition_out || 'Good',
                notes: values.notes || '',
                checked_out_by: values.checked_out_by || 'System'
            };
            
            const eventId = selectedEventForEquipment?.id;
            if (!eventId) {
                message.error('No event selected');
                return;
            }
            
            const response = await eventAPI.checkoutEquipment(eventId, payload);
            
            if (response.data?.success !== false) {
                const equipmentName = availableEquipmentList.find(e => e.id === values.equipment_id)?.name || 'Equipment';
                message.success(`✅ ${payload.quantity} unit(s) of ${equipmentName} checked out successfully`);
                setEquipmentCheckoutModalVisible(false);
                equipmentForm.resetFields();
                await loadEvents();
                await loadEquipment();
                await loadEquipmentTracking();
                notification.success({
                    message: 'Equipment Checked Out',
                    description: `${payload.quantity} unit(s) of ${equipmentName} checked out.`,
                    placement: 'topRight',
                    duration: 3,
                });
            } else {
                message.error(response.data?.message || 'Failed to checkout equipment');
            }
        } catch (error) {
            console.error('Equipment checkout error:', error);
            const errorMsg = error.response?.data?.message || error.message || 'Failed to checkout equipment';
            message.error(errorMsg);
        }
    };

    const handleReturnEquipment = async (values) => {
        try {
            if (!selectedEquipmentItem) {
                message.error('No equipment selected for return');
                return;
            }
            
            const payload = {
                condition_in: values.condition_in || 'Good',
                quantity_used: values.quantity_used || 0,
                quantity_damaged: values.quantity_damaged || 0,
                quantity_missing: values.quantity_missing || 0,
                returned_by: values.returned_by || 'System',
                notes: values.return_notes || ''
            };
            
            const response = await eventAPI.returnEquipment(
                selectedEventForEquipment?.id || selectedEquipmentItem.booking?.serviceEvent?.id,
                selectedEquipmentItem.id, 
                payload
            );
            
            if (response.data?.success !== false) {
                message.success('✅ Equipment returned successfully');
                setEquipmentReturnModalVisible(false);
                setSelectedEquipmentItem(null);
                equipmentForm.resetFields();
                await loadEvents();
                await loadEquipment();
                await loadEquipmentTracking();
                notification.success({
                    message: 'Equipment Returned',
                    description: `Equipment checked in successfully.`,
                    placement: 'topRight',
                    duration: 3,
                });
            } else {
                message.error(response.data?.message || 'Failed to return equipment');
            }
        } catch (error) {
            console.error('Equipment return error:', error);
            const errorMsg = error.response?.data?.message || error.message || 'Failed to return equipment';
            message.error(errorMsg);
        }
    };

    const handleEquipmentSelect = async (equipmentId) => {
        const selectedDate = equipmentForm.getFieldValue('expected_return_date');
        if (!selectedDate) {
            message.warning('Please select an expected return date first');
            return;
        }
        try {
            const response = await api.get('/inventory/equipment-availability', {
                params: {
                    equipment_id: equipmentId,
                    date: selectedDate.format('YYYY-MM-DD')
                }
            });
            const availability = response.data?.data || {};
            if (!availability.is_available) {
                message.warning(`Only ${availability.available} of ${availability.total} units available on this date`);
            }
        } catch (error) {
            console.warn('Could not check availability:', error);
        }
    };

    // ==================== ONGOING EVENT FUNCTIONS ====================
    const handleViewOngoingEvent = (record) => {
        setSelectedOngoingEvent(record);
        setLiveStatusModalVisible(true);
    };

    const handleMarkEventOngoing = async (record) => {
        try {
            await eventAPI.updateEvent(record.id, { status: 'ongoing' });
            message.success(`Event ${record.display_name || record.event_name} marked as ongoing`);
            await loadEvents();
        } catch (error) {
            console.error('Mark ongoing error:', error);
            message.error(error.response?.data?.message || 'Failed to update event status');
        }
    };

    const handleUpdateDailyProgress = async (day, completion, notes) => {
        try {
            await eventAPI.updateDailyProgress(selectedOngoingEvent.id, day, { completion, notes });
            message.success(`Day ${day} progress updated`);
            await loadEvents();
            setSelectedOngoingEvent(prev => {
                const updated = { ...prev };
                if (!updated.daily_progress) updated.daily_progress = [];
                updated.daily_progress[day - 1] = { ...updated.daily_progress[day - 1], completion, notes };
                const total = updated.daily_progress.length;
                const sum = updated.daily_progress.reduce((acc, dp) => acc + (dp.completion || 0), 0);
                updated.progress = Math.round(sum / total);
                return updated;
            });
        } catch (error) {
            console.error('Update daily progress error:', error);
            message.error('Failed to update daily progress');
        }
    };

    const handleUpdateAttendance = async (day, present) => {
        try {
            await eventAPI.updateAttendance(selectedOngoingEvent.id, day, { present });
            message.success(`Day ${day} attendance updated`);
            await loadEvents();
            setSelectedOngoingEvent(prev => {
                const updated = { ...prev };
                if (!updated.attendance) updated.attendance = [];
                const registered = updated.attendance[day - 1]?.registered || updated.guests_count || 0;
                updated.attendance[day - 1] = { 
                    ...updated.attendance[day - 1], 
                    present, 
                    registered,
                    attendance_rate: Math.round((present / registered) * 100)
                };
                return updated;
            });
        } catch (error) {
            console.error('Update attendance error:', error);
            message.error('Failed to update attendance');
        }
    };

    const handleAdvanceToNextDay = async () => {
        try {
            const result = await eventAPI.advanceToNextDay(selectedOngoingEvent.id);
            message.success(result.data?.message || 'Advanced to next day');
            await loadEvents();
        } catch (error) {
            message.error('Failed to advance to next day');
        }
    };

    const handleCompleteEvent = async (record) => {
        Modal.confirm({
            title: 'Complete Event',
            content: `Mark "${record.display_name || record.event_name}" (Booking: ${record.booking_no}) as completed?`,
            okText: 'Complete',
            cancelText: 'Cancel',
            onOk: async () => {
                try {
                    await eventAPI.completeEvent(record.id);
                    message.success('Event marked as completed');
                    setLiveStatusModalVisible(false);
                    await loadEvents();
                } catch (error) {
                    console.error('Complete event error:', error);
                    message.error(error.response?.data?.message || 'Failed to complete event');
                }
            }
        });
    };

    // ==================== GENERAL EVENT FUNCTIONS ====================
    const handleViewEventDetails = (record) => {
        setSelectedEvent(record);
        setEventDetailsModalVisible(true);
    };

    const handleEditEvent = (record) => {
        setSelectedEvent(record);
        eventForm.setFieldsValue({
            ...record,
            date: record.date ? dayjs(record.date) : null,
            end_date: record.end_date ? dayjs(record.end_date) : null
        });
        setEditEventModalVisible(true);
    };

    const handleUpdateEvent = async (values) => {
        try {
            await eventAPI.updateEvent(selectedEvent.id, {
                customer_name: values.customer_name,
                event_name: values.event_name,
                event_type: values.event_type,
                date: values.date.format('YYYY-MM-DD'),
                end_date: values.end_date ? values.end_date.format('YYYY-MM-DD') : values.date.format('YYYY-MM-DD'),
                time: values.time,
                location: values.location,
                guests_count: values.guests_count
            });
            message.success('Event updated successfully');
            setEditEventModalVisible(false);
            await loadEvents();
        } catch (error) {
            message.error('Failed to update event');
        }
    };

    // ==================== EXPORT FUNCTIONS ====================
    const exportToExcel = (data, filename, columns) => {
        const worksheetData = data.map(row => {
            const exportRow = {};
            columns.forEach(col => {
                if (col.dataIndex) {
                    exportRow[col.title] = row[col.dataIndex];
                } else if (col.render) {
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

    const exportEvents = () => {
        const columns = [
            { title: 'BOOKING ID', dataIndex: 'booking_no' },
            { title: 'CUSTOMER', dataIndex: 'customer_name' },
            { title: 'EVENT', dataIndex: 'event_name' },
            { title: 'DATE', dataIndex: 'date' },
            { title: 'TIME', dataIndex: 'time' },
            { title: 'LOCATION', dataIndex: 'location' },
            { title: 'GUESTS', dataIndex: 'guests_count' },
            { title: 'STATUS', dataIndex: 'status' }
        ];
        exportToExcel(events, 'Events_Report', columns);
    };

    // ==================== PAGINATION ====================
    const renderPaginationItem = (_, type, originalElement) => {
        if (type === 'prev') {
            return (
                <Button className="em-pagination-navigation-button" size="small" icon={<LeftOutlined />}>
                    Previous
                </Button>
            );
        }
        if (type === 'next') {
            return (
                <Button className="em-pagination-navigation-button" size="small">
                    Next <RightOutlined />
                </Button>
            );
        }
        return originalElement;
    };

    const renderEmptyPaginationFooter = (label) => {
        return (
            <div className="em-empty-pagination-footer">
                <span className="em-empty-pagination-total">Total 0 {label}</span>
                <div className="em-empty-pagination-controls">
                    <Button className="em-pagination-navigation-button" size="small" icon={<LeftOutlined />} disabled>
                        Previous
                    </Button>
                    <button type="button" className="em-empty-pagination-current-page" disabled>1</button>
                    <Button className="em-pagination-navigation-button" size="small" disabled>
                        Next <RightOutlined />
                    </Button>
                </div>
            </div>
        );
    };

    // ==================== DROPDOWN MENU ====================
    const getActionMenuItems = (record) => {
        const items = [
            { 
                key: 'staff', 
                label: 'Staff Assignment', 
                icon: <TeamOutlined />, 
                onClick: () => handleStaffAssignment(record) 
            },
            { 
                key: 'checklist', 
                label: 'Event Checklist', 
                icon: <CheckCircleOutlined />, 
                onClick: () => handleViewChecklist(record) 
            },
            { 
                key: 'delivery', 
                label: 'Delivery Tracking', 
                icon: <TruckOutlined />, 
                onClick: () => handleViewDeliveryTracking(record) 
            },
            { 
                key: 'livestatus', 
                label: 'Live Status', 
                icon: <DashboardOutlined />, 
                onClick: () => handleViewOngoingEvent(record) 
            },
            { type: 'divider' },
        ];
        
        if (record.status === 'upcoming' || record.status === 'confirmed') {
            items.push({
                key: 'start', 
                label: '▶ Start Event', 
                icon: <PlayCircleOutlined style={{ color: '#10b981' }} />, 
                onClick: () => handleStartEvent(record)
            });
        }
        
        items.push({
            key: 'checkout', 
            label: '🔧 Checkout Equipment', 
            icon: <PlusCircleOutlined style={{ color: '#3b82f6' }} />, 
            onClick: () => {
                setSelectedEventForEquipment(record);
                equipmentForm.resetFields();
                setEquipmentCheckoutModalVisible(true);
            }
        });
        
        if (record.status === 'upcoming' || record.status === 'confirmed') {
            items.push({
                key: 'ongoing', 
                label: 'Mark as Ongoing', 
                icon: <SyncOutlined style={{ color: '#f59e0b' }} />, 
                onClick: () => handleMarkEventOngoing(record)
            });
        }
        
        items.push({ type: 'divider' });
        
        items.push({
            key: 'complete', 
            label: 'Complete Event', 
            icon: <FlagOutlined />, 
            onClick: () => handleCompleteEvent(record), 
            disabled: record.status === 'completed' 
        });
        
        items.push({
            key: 'edit', 
            label: 'Edit Event', 
            icon: <EditOutlined />, 
            onClick: () => handleEditEvent(record) 
        });
        
        return items;
    };

    // ==================== TABLE COLUMNS ====================
    const eventColumns = [
        { 
            title: 'BOOKING ID', 
            dataIndex: 'booking_no', 
            key: 'booking_no', 
            width: 140, 
            fixed: 'left',
            render: (text) => <span className="em-id-text">{text || 'N/A'}</span>
        },
        { 
            title: 'CUSTOMER', 
            dataIndex: 'customer_name', 
            key: 'customer_name', 
            width: 180,
            render: (text, record) => (
                <div className="em-customer-cell">
                    <div className="em-customer-name">{text || 'Unknown'}</div>
                    {record.customer_email && (
                        <div className="em-customer-contact"><MailOutlined /> {record.customer_email}</div>
                    )}
                    {record.customer_phone && (
                        <div className="em-customer-contact"><PhoneOutlined /> {record.customer_phone}</div>
                    )}
                </div>
            )
        },
        { 
            title: 'EVENT', 
            key: 'event', 
            width: 200,
            render: (_, record) => (
                <div>
                    <div className="em-event-name">{record.event_name || 'N/A'}</div>
                    {record.event_type && <Tag color="blue" style={{ marginTop: 4 }}>{record.event_type}</Tag>}
                    {record.total_days > 1 && <Tag color="purple" style={{ marginTop: 4 }}>Multi-Day</Tag>}
                </div>
            )
        },
        { 
            title: 'DATE & TIME', 
            key: 'datetime', 
            width: 180,
            render: (_, record) => (
                <div className="em-datetime-cell">
                    <div><CalendarOutlined /> {record.date}</div>
                    <div><ClockCircleOutlined /> {record.time || 'Not specified'}</div>
                    {record.total_days > 1 && record.end_date && (
                        <div><ScheduleOutlined /> to {record.end_date}</div>
                    )}
                </div>
            )
        },
        { 
            title: 'LOCATION', 
            dataIndex: 'location', 
            key: 'location', 
            width: 180, 
            ellipsis: true,
            render: (text) => <div><EnvironmentOutlined /> {text || 'N/A'}</div>
        },
        { 
            title: 'GUESTS', 
            dataIndex: 'guests_count', 
            key: 'guests_count', 
            width: 80, 
            align: 'center',
            render: (v) => <span className="em-pax-number"><TeamOutlined /> {v || 0}</span>
        },
        { 
            title: 'STAFF', 
            key: 'staff', 
            width: 80, 
            align: 'center',
            render: (_, record) => <Badge count={record.assigned_staff?.length || 0} showZero style={{ backgroundColor: '#3b82f6' }} />
        },
        { 
            title: 'STATUS', 
            dataIndex: 'status', 
            key: 'status', 
            width: 120, 
            align: 'center',
            render: (status) => {
                const config = getStatusConfig(status);
                return (
                    <span className="em-status" style={{ color: config.color, background: config.bg }}>
                        {config.icon} {config.text}
                    </span>
                );
            }
        },
        { 
            title: 'PROGRESS', 
            key: 'progress', 
            width: 120,
            render: (_, record) => <Progress percent={record.progress || 0} size="small" strokeColor="#3b82f6" />
        },
        { 
            title: 'ACTIONS', 
            key: 'actions', 
            width: 160, 
            fixed: 'right',
            render: (_, record) => (
                <div className="em-action-group">
                    <Tooltip title="View Details">
                        <button className="em-action-icon view" onClick={() => handleViewEventDetails(record)}>
                            <EyeOutlined />
                        </button>
                    </Tooltip>
                    <Tooltip title="Edit Event">
                        <button className="em-action-icon edit" onClick={() => handleEditEvent(record)}>
                            <EditOutlined />
                        </button>
                    </Tooltip>
                    <Dropdown menu={{ items: getActionMenuItems(record) }} placement="bottomRight">
                        <button className="em-action-icon more">
                            <MoreOutlined />
                        </button>
                    </Dropdown>
                </div>
            )
        }
    ];

    const ongoingEventsColumns = [
        { 
            title: 'BOOKING ID', 
            dataIndex: 'booking_no', 
            key: 'booking_no', 
            width: 140,
            render: (text) => <span className="em-id-text">{text || 'N/A'}</span>
        },
        { 
            title: 'CUSTOMER', 
            dataIndex: 'customer_name', 
            key: 'customer_name', 
            width: 160,
            render: (text) => <span className="em-customer-name">{text || 'Unknown'}</span>
        },
        { 
            title: 'EVENT', 
            dataIndex: 'event_name', 
            key: 'event_name', 
            width: 160,
            render: (text) => <Tag color="cyan">{text || 'N/A'}</Tag>
        },
        { 
            title: 'DATES', 
            key: 'dates', 
            width: 160,
            render: (_, r) => `${r.date} to ${r.end_date || r.date}`
        },
        { 
            title: 'CURRENT DAY', 
            key: 'current_day', 
            width: 120,
            align: 'center',
            render: (_, r) => <Tag color="processing">Day {r.current_day} of {r.total_days || 1}</Tag>
        },
        { 
            title: 'PROGRESS', 
            key: 'progress', 
            width: 200,
            render: (_, r) => (
                <div>
                    {r.daily_progress?.map((dp, idx) => (
                        <div key={idx} style={{ marginBottom: 4 }}>
                            <Text type="secondary" style={{ fontSize: 11 }}>Day {idx + 1}:</Text>
                            <Progress percent={dp?.completion || 0} size="small" strokeColor={dp?.completion === 100 ? '#10b981' : '#3b82f6'} />
                        </div>
                    ))}
                </div>
            )
        },
        { 
            title: 'ACTIONS', 
            key: 'actions', 
            width: 120,
            render: (_, record) => (
                <Button size="small" type="primary" icon={<EyeOutlined />} onClick={() => handleViewOngoingEvent(record)}>
                    Monitor
                </Button>
            )
        }
    ];

    const equipmentTrackingColumns = [
        { 
            title: 'Equipment', 
            key: 'name',
            render: (_, record) => {
                const name = record.equipment?.name || record.equipment_name || record.name || 'Unknown';
                const category = record.equipment?.category || record.category || '';
                return (
                    <div>
                        <div><strong>{name}</strong></div>
                        {category && <div style={{ fontSize: 12, color: '#64748b' }}>{category}</div>}
                    </div>
                );
            }
        },
        { 
            title: 'Event', 
            key: 'event',
            render: (_, record) => {
                const bookingNo = record.booking?.booking_no || record.booking_no || 'N/A';
                const customerName = record.booking?.serviceEvent?.customer?.person?.full_name || 
                                    record.customer_name || 
                                    record.booking?.customer_name ||
                                    'Unknown';
                return (
                    <div>
                        <div><span className="em-id-text">{bookingNo}</span></div>
                        <div style={{ fontSize: 12, color: '#64748b' }}>{customerName}</div>
                    </div>
                );
            }
        },
        { 
            title: 'Qty', 
            key: 'qty',
            align: 'center',
            render: (_, record) => {
                const quantity = record.quantity_reserved || record.quantity || 0;
                return <span style={{ fontWeight: 600 }}>{quantity} units</span>;
            }
        },
        { 
            title: 'Checkout Date', 
            key: 'checkout',
            render: (_, record) => {
                const date = record.checked_out_date || record.checkout_date;
                return date ? dayjs(date).format('MMM DD, YYYY') : '-';
            }
        },
        { 
            title: 'Expected Return', 
            key: 'return',
            render: (_, record) => {
                const date = record.rental_end_date || record.expected_return_date;
                if (!date) return '-';
                const isOverdue = dayjs(date).isBefore(dayjs()) && record.status !== 'returned';
                return (
                    <span style={{ color: isOverdue ? '#ef4444' : 'inherit' }}>
                        {dayjs(date).format('MMM DD, YYYY')}
                        {isOverdue && <Tag color="error" style={{ marginLeft: 4 }}>Overdue</Tag>}
                    </span>
                );
            }
        },
        { 
            title: 'Status', 
            dataIndex: 'status', 
            key: 'status',
            render: (status) => {
                const config = getEquipmentStatusConfig(status);
                return <Tag color={config.color}>{config.text}</Tag>;
            }
        },
        {
            title: 'Action',
            key: 'action',
            render: (_, record) => {
                if (record.status === 'checked_out' || record.status === 'reserved') {
                    return (
                        <Button 
                            size="small" 
                            type="primary"
                            onClick={() => {
                                setSelectedEquipmentItem(record);
                                setSelectedEventForEquipment(record.booking?.serviceEvent || record);
                                equipmentForm.setFieldsValue({
                                    equipment_name: record.equipment?.name || record.equipment_name || record.name,
                                    quantity: record.quantity_reserved || record.quantity || 0,
                                    condition_in: '',
                                    quantity_used: 0,
                                    quantity_damaged: 0,
                                    quantity_missing: 0,
                                    returned_by: '',
                                    return_notes: ''
                                });
                                setEquipmentReturnModalVisible(true);
                            }}
                        >
                            Check In
                        </Button>
                    );
                }
                return <Tag color="default">Completed</Tag>;
            }
        }
    ];

    // ==================== STATS ====================
    const stats = [
        { title: 'Total Events', value: events.length, icon: <CalendarOutlined />, color: 'blue' },
        { title: 'Upcoming', value: events.filter(e => e.status === 'upcoming').length, icon: <ClockCircleOutlined />, color: 'cyan' },
        { title: 'Ongoing', value: events.filter(e => e.status === 'ongoing').length, icon: <PlayCircleOutlined />, color: 'orange' },
        { title: 'Equipment Out', value: events.reduce((sum, e) => sum + (e.equipment_in_out?.filter(eq => eq.status === 'checked_out').length || 0), 0), icon: <SwapOutlined />, color: 'purple' }
    ];

    // ==================== CSS CLASSES ====================
    const containerClass = `em-event-container ${isDarkMode ? 'em-dark-mode' : ''}`;
    const headerClass = `em-header ${isDarkMode ? 'em-header-dark' : ''}`;
    const dateDisplayClass = `em-date-display ${isDarkMode ? 'em-date-display-dark' : ''}`;
    const mainCardClass = `em-main-card ${isDarkMode ? 'em-main-card-dark' : ''}`;
    const filtersClass = `em-filters ${isDarkMode ? 'em-filters-dark' : ''}`;
    const filterGroupClass = `em-filter-group ${isDarkMode ? 'em-filter-group-dark' : ''}`;
    const tableClass = `em-table ${isDarkMode ? 'em-table-dark' : ''}`;
    const kpiCardClass = `em-kpi-card ${isDarkMode ? 'em-kpi-card-dark' : ''}`;
    const tabContentClass = `em-tab-content ${isDarkMode ? 'em-tab-content-dark' : ''}`;
    const modalClass = `em-modal-clean ${isDarkMode ? 'em-modal-dark' : ''}`;
    const alertClass = isDarkMode ? 'em-alert-dark' : '';

    const today = new Date();
    const formattedDate = today.toLocaleDateString('en-US', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' });

    const filteredEvents = events.filter(event => {
        if (searchText && !event.display_name?.toLowerCase().includes(searchText.toLowerCase()) && 
            !event.event_name?.toLowerCase().includes(searchText.toLowerCase()) &&
            !event.customer_name?.toLowerCase().includes(searchText.toLowerCase()) &&
            !event.booking_no?.toLowerCase().includes(searchText.toLowerCase())) {
            return false;
        }
        if (filterStatus !== 'all' && event.status !== filterStatus) return false;
        if (filterCustomer !== 'all' && event.customer_name !== filterCustomer) return false;
        if (selectedDate) {
            const eventDate = dayjs(event.date);
            const filterDate = dayjs(selectedDate);
            if (!eventDate.isSame(filterDate, 'day')) return false;
        }
        return true;
    });

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
                    <div className="em-header-left">
                        <Tooltip title="Event Management System">
                            <div className="em-logo-icon"><CalendarOutlined /></div>
                        </Tooltip>
                        <div className="em-header-info">
                            <h1>Event Management System</h1>
                            <span>Complete Event Operations</span>
                        </div>
                    </div>
                    <div className="em-header-right">
                        <div className={dateDisplayClass}>
                            <CalendarOutlined />
                            <span>{formattedDate}</span>
                        </div>
                        <Divider type="vertical" style={{ height: 28 }} />
                        <Tooltip title="Refresh all data">
                            <Button icon={<ReloadOutlined />} onClick={loadEvents}>Refresh</Button>
                        </Tooltip>
                        <Tooltip title="Export to Excel">
                            <Button icon={<ExportOutlined />} onClick={exportEvents}>Export</Button>
                        </Tooltip>
                        <Tooltip title="Print current view">
                            <Button icon={<PrinterOutlined />} onClick={() => window.print()}>Print</Button>
                        </Tooltip>
                    </div>
                </div>

                {/* ==================== KPI CARDS ==================== */}
                <div className="em-kpi-grid">
                    {stats.map((stat, index) => (
                        <div key={index} className={kpiCardClass}>
                            <div className={`em-kpi-icon ${stat.color}`}>
                                {stat.icon}
                            </div>
                            <div className="em-kpi-stats">
                                <div className="em-kpi-value">{stat.value}</div>
                                <div className="em-kpi-label">{stat.title}</div>
                            </div>
                            <div className={`em-kpi-trend ${stat.value > 0 ? 'up' : 'warning'}`}>
                                {stat.value > 0 ? <RiseOutlined /> : <ClockCircleOutlined />}
                                {stat.value > 0 ? ` ${stat.value} active` : ' No active'}
                            </div>
                        </div>
                    ))}
                </div>

                {/* ==================== MAIN CARD ==================== */}
                <Card className={mainCardClass} bordered={false}>
                    <Tabs 
                        activeKey={activeMainTab} 
                        onChange={(key) => {
                            setActiveMainTab(key);
                            if (key === 'events') loadEvents();
                            else if (key === 'ongoing') loadEvents();
                            else if (key === 'staff') { loadEvents(); loadStaff(); }
                            else if (key === 'equipment') { loadEquipment(); loadEquipmentTracking(); }
                            else if (key === 'checklist') loadEvents();
                            else if (key === 'delivery') loadEvents();
                        }} 
                        className="em-tabs"
                        destroyInactiveTabPane={true}
                    >
                        {/* ==================== EVENTS TAB ==================== */}
                        <TabPane tab={<span><CalendarOutlined /> All Events</span>} key="events">
                            <div className="em-tab-content">
                                <div className={filtersClass}>
                                    <div className={filterGroupClass}>
                                        <FilterOutlined />
                                        <Select value={filterStatus} onChange={setFilterStatus} className="em-filter-select" placeholder="Status">
                                            <Option value="all">All Status</Option>
                                            <Option value="upcoming">Upcoming</Option>
                                            <Option value="ongoing">Ongoing</Option>
                                            <Option value="completed">Completed</Option>
                                        </Select>
                                    </div>
                                    <div className={filterGroupClass}>
                                        <UserOutlined />
                                        <Select 
                                            value={filterCustomer} 
                                            onChange={setFilterCustomer} 
                                            className="em-filter-select" 
                                            placeholder="Filter by Customer"
                                            showSearch
                                            optionFilterProp="children"
                                        >
                                            <Option value="all">All Customers</Option>
                                            {uniqueCustomers.map(customer => (
                                                <Option key={customer} value={customer}>{customer}</Option>
                                            ))}
                                        </Select>
                                    </div>
                                    <div className={filterGroupClass}>
                                        <CalendarOutlined />
                                        <DatePicker onChange={setSelectedDate} placeholder="Select Date" format="YYYY-MM-DD" allowClear className="em-date-picker" />
                                    </div>
                                    <div className={`${filterGroupClass} em-search`}>
                                        <SearchOutlined />
                                        <Input 
                                            placeholder="Search by booking ID, customer or event name..." 
                                            value={searchText} 
                                            onChange={(e) => setSearchText(e.target.value)} 
                                            allowClear 
                                            className="em-search-input"
                                        />
                                    </div>
                                </div>

                                <div className="em-table-container">
                                    <Table 
                                        columns={eventColumns} 
                                        dataSource={filteredEvents} 
                                        rowKey="id" 
                                        loading={loading} 
                                        className={tableClass}
                                        scroll={{ x: 1500 }}
                                        footer={
                                            filteredEvents.length === 0
                                                ? () => renderEmptyPaginationFooter('events')
                                                : undefined
                                        }
                                        pagination={{
                                            current: currentPage,
                                            pageSize: 10,
                                            total: filteredEvents.length,
                                            showSizeChanger: false,
                                            showTotal: (total) => `Total ${total} events`,
                                            itemRender: renderPaginationItem,
                                            onChange: (page, size) => { setCurrentPage(page); if (size) setPageSize(size); }
                                        }}
                                    />
                                </div>
                            </div>
                        </TabPane>

                        {/* ==================== ONGOING EVENTS TAB ==================== */}
                        <TabPane tab={<span><DashboardOutlined /> Ongoing Events</span>} key="ongoing">
                            <div className={tabContentClass}>
                                <Alert 
                                    message="Multi-Day Event Monitoring" 
                                    description="Monitor and manage ongoing multi-day events including seminars, workshops, and conferences." 
                                    type="info" 
                                    showIcon 
                                    style={{ marginBottom: 20 }}
                                    className={alertClass}
                                />
                                <div className="em-table-container">
                                    {ongoingEvents.length === 0 ? (
                                        <Empty description="No ongoing multi-day events at the moment" />
                                    ) : (
                                        <Table 
                                            columns={ongoingEventsColumns} 
                                            dataSource={ongoingEvents} 
                                            rowKey="id" 
                                            className={tableClass}
                                            scroll={{ x: 1100 }}
                                            pagination={{ 
                                                pageSize: 10,
                                                showTotal: (total) => `Total ${total} ongoing events`,
                                                itemRender: renderPaginationItem
                                            }}
                                        />
                                    )}
                                </div>
                            </div>
                        </TabPane>

                        {/* ==================== STAFF TAB ==================== */}
                        <TabPane tab={<span><TeamOutlined /> Staff Management</span>} key="staff">
                            <div className={tabContentClass}>
                                <Alert 
                                    message="Staff Assignment Management" 
                                    description="Assign and manage staff members for each event." 
                                    type="info" 
                                    showIcon 
                                    style={{ marginBottom: 20 }}
                                    className={alertClass}
                                />
                                <div className="em-table-container">
                                    <Table
                                        dataSource={events}
                                        rowKey="id"
                                        columns={[
                                            { 
                                                title: 'Booking ID', 
                                                dataIndex: 'booking_no', 
                                                width: 130, 
                                                render: (text) => <span className="em-id-text">{text || 'N/A'}</span> 
                                            },
                                            { 
                                                title: 'Customer', 
                                                dataIndex: 'customer_name', 
                                                width: 160, 
                                                render: (text) => <span className="em-customer-name">{text || 'Unknown'}</span> 
                                            },
                                            { 
                                                title: 'Event', 
                                                dataIndex: 'event_name', 
                                                width: 160, 
                                                render: (text) => <Tag color="blue">{text}</Tag> 
                                            },
                                            { 
                                                title: 'Event Date', 
                                                dataIndex: 'date', 
                                                width: 110 
                                            },
                                            { 
                                                title: 'Location', 
                                                dataIndex: 'location', 
                                                width: 160, 
                                                ellipsis: true 
                                            },
                                            { 
                                                title: 'Assigned Staff', 
                                                render: (_, r) => <Badge count={r.assigned_staff?.length || 0} style={{ backgroundColor: '#3b82f6' }} /> 
                                            },
                                            { 
                                                title: 'Action', 
                                                width: 150, 
                                                render: (_, r) => (
                                                    <Button size="small" type="primary" icon={<TeamOutlined />} onClick={() => handleStaffAssignment(r)}>
                                                        Manage Staff
                                                    </Button>
                                                ) 
                                            }
                                        ]}
                                        pagination={{ pageSize: 10 }}
                                        className={tableClass}
                                    />
                                </div>
                            </div>
                        </TabPane>

                        {/* ==================== EQUIPMENT TAB ==================== */}
                        <TabPane tab={<span><SwapOutlined /> Equipment In/Out</span>} key="equipment">
                            <div className={tabContentClass}>
                                <Alert 
                                    message="Equipment Check-In/Check-Out Tracking" 
                                    description="Track all equipment coming in and out for events." 
                                    type="info" 
                                    showIcon 
                                    style={{ marginBottom: 20 }}
                                    className={alertClass}
                                />
                                
                                <div style={{ marginBottom: 16 }}>
                                    <Button 
                                        type="primary" 
                                        icon={<PlusCircleOutlined />}
                                        onClick={() => {
                                            if (events.length > 0) {
                                                setSelectedEventForEquipment(events[0]);
                                                equipmentForm.resetFields();
                                                setEquipmentCheckoutModalVisible(true);
                                            } else {
                                                message.warning('No events available');
                                            }
                                        }}
                                    >
                                        Checkout Equipment
                                    </Button>
                                    <Button 
                                        style={{ marginLeft: 8 }}
                                        icon={<ReloadOutlined />} 
                                        onClick={() => loadEquipmentTracking()}
                                    >
                                        Refresh
                                    </Button>
                                </div>
                                
                                <div className="em-table-container">
                                    {equipmentTrackingData.length === 0 ? (
                                        <Empty 
                                            description="No equipment checked out. Click 'Checkout Equipment' to checkout equipment for events."
                                            image={Empty.PRESENTED_IMAGE_SIMPLE}
                                        >
                                            <Button 
                                                type="primary" 
                                                icon={<PlusCircleOutlined />}
                                                onClick={() => {
                                                    if (events.length > 0) {
                                                        setSelectedEventForEquipment(events[0]);
                                                        equipmentForm.resetFields();
                                                        setEquipmentCheckoutModalVisible(true);
                                                    } else {
                                                        message.warning('No events available');
                                                    }
                                                }}
                                            >
                                                Checkout Equipment
                                            </Button>
                                        </Empty>
                                    ) : (
                                        <Table
                                            dataSource={equipmentTrackingData}
                                            rowKey="id"
                                            columns={equipmentTrackingColumns}
                                            pagination={{ pageSize: 10 }}
                                            className={tableClass}
                                        />
                                    )}
                                </div>
                            </div>
                        </TabPane>

                        {/* ==================== CHECKLIST TAB ==================== */}
                        <TabPane tab={<span><CheckCircleOutlined /> Checklists</span>} key="checklist">
                            <div className={tabContentClass}>
                                <Alert 
                                    message="Event Checklist Management" 
                                    description="Track task completion status for each event" 
                                    type="info" 
                                    showIcon 
                                    style={{ marginBottom: 20 }}
                                    className={alertClass}
                                />
                                <div className="em-table-container">
                                    <Table
                                        dataSource={events}
                                        rowKey="id"
                                        columns={[
                                            { 
                                                title: 'Booking ID', 
                                                dataIndex: 'booking_no', 
                                                width: 130, 
                                                render: (text) => <span className="em-id-text">{text || 'N/A'}</span> 
                                            },
                                            { 
                                                title: 'Customer', 
                                                dataIndex: 'customer_name', 
                                                width: 160, 
                                                render: (text) => <span className="em-customer-name">{text || 'Unknown'}</span> 
                                            },
                                            { 
                                                title: 'Event', 
                                                dataIndex: 'event_name', 
                                                width: 160, 
                                                render: (text) => <Tag color="blue">{text}</Tag> 
                                            },
                                            { 
                                                title: 'Event Date', 
                                                dataIndex: 'date', 
                                                width: 110 
                                            },
                                            { 
                                                title: 'Progress', 
                                                width: 150, 
                                                render: (_, r) => {
                                                    const completed = r.checklist?.filter(i => i.status === 'completed').length || 0;
                                                    const total = r.checklist?.length || 1;
                                                    const percent = (completed / total) * 100;
                                                    return <Progress percent={Math.round(percent)} size="small" strokeColor="#3b82f6" />
                                                }
                                            },
                                            { 
                                                title: 'Action', 
                                                width: 130, 
                                                render: (_, r) => (
                                                    <Button size="small" type="primary" icon={<CheckCircleOutlined />} onClick={() => handleViewChecklist(r)}>
                                                        View Checklist
                                                    </Button>
                                                ) 
                                            }
                                        ]}
                                        pagination={{ pageSize: 10 }}
                                        className={tableClass}
                                    />
                                </div>
                            </div>
                        </TabPane>

                        {/* ==================== DELIVERY TAB ==================== */}
                        <TabPane tab={<span><TruckOutlined /> Delivery Tracking</span>} key="delivery">
                            <div className={tabContentClass}>
                                <Alert 
                                    message="Delivery Tracking System" 
                                    description="Monitor delivery vehicles and real-time location status" 
                                    type="info" 
                                    showIcon 
                                    style={{ marginBottom: 20 }}
                                    className={alertClass}
                                />
                                <div className="em-table-container">
                                    <Table
                                        dataSource={events}
                                        rowKey="id"
                                        columns={[
                                            { 
                                                title: 'Booking ID', 
                                                dataIndex: 'booking_no', 
                                                width: 130, 
                                                render: (text) => <span className="em-id-text">{text || 'N/A'}</span> 
                                            },
                                            { 
                                                title: 'Customer', 
                                                dataIndex: 'customer_name', 
                                                width: 160, 
                                                render: (text) => <span className="em-customer-name">{text || 'Unknown'}</span> 
                                            },
                                            { 
                                                title: 'Event', 
                                                dataIndex: 'event_name', 
                                                width: 160, 
                                                render: (text) => <Tag color="blue">{text}</Tag> 
                                            },
                                            { 
                                                title: 'Event Date', 
                                                dataIndex: 'date', 
                                                width: 110 
                                            },
                                            { 
                                                title: 'Venue', 
                                                dataIndex: 'location', 
                                                width: 180, 
                                                ellipsis: true, 
                                                render: (text) => text || 'N/A' 
                                            },
                                            { 
                                                title: 'Action', 
                                                width: 130, 
                                                render: (_, r) => (
                                                    <Button size="small" type="primary" icon={<TruckOutlined />} onClick={() => handleViewDeliveryTracking(r)}>
                                                        Track Delivery
                                                    </Button>
                                                ) 
                                            }
                                        ]}
                                        pagination={{ pageSize: 10 }}
                                        className={tableClass}
                                    />
                                </div>
                            </div>
                        </TabPane>
                    </Tabs>
                </Card>

                {/* ============================================================
                    MODALS - All use the same clean style
                ============================================================ */}

                {/* ==================== EVENT DETAILS MODAL ==================== */}
                <Modal
    title={
        <div className="em-modal-header-clean">
            <div className="em-modal-title-icon"><EyeOutlined /></div>
            <div className="em-modal-title-text">Event Details</div>
            <div className="em-modal-badge">{selectedEvent?.booking_no || 'N/A'}</div>
        </div>
    }
    open={eventDetailsModalVisible}
    onCancel={() => setEventDetailsModalVisible(false)}
    width={900}
    className={modalClass}
    centered={true}
    footer={
        <div className="em-modal-footer-simple">
            <Button type="primary" onClick={() => setEventDetailsModalVisible(false)}>Close</Button>
        </div>
    }
    bodyStyle={{ padding: 0, maxHeight: 'none', overflow: 'visible' }}
>
    {selectedEvent && (
        <div className="em-modal-clean-content">
            <div className="em-modal-header-tags">
                <span className="em-id-text">{selectedEvent.booking_no || 'N/A'}</span>
                <span className="em-status" style={{ color: getStatusConfig(selectedEvent.status).color, background: getStatusConfig(selectedEvent.status).bg }}>
                    {getStatusConfig(selectedEvent.status).icon} {getStatusConfig(selectedEvent.status).text}
                </span>
                {selectedEvent.total_days > 1 && <Tag color="purple">Multi-Day ({selectedEvent.total_days} days)</Tag>}
            </div>

            <div className="em-clean-section">
                <div className="em-clean-section-title"><UserOutlined /> Customer Information</div>
                <div className="em-clean-grid">
                    <div><span className="em-clean-label">Name:</span> {selectedEvent.customer_name || 'Unknown'}</div>
                    <div><span className="em-clean-label">Email:</span> {selectedEvent.customer_email || 'N/A'}</div>
                    <div><span className="em-clean-label">Phone:</span> {selectedEvent.customer_phone || 'N/A'}</div>
                </div>
            </div>

            <div className="em-clean-section">
                <div className="em-clean-section-title"><CalendarOutlined /> Event Information</div>
                <div className="em-clean-grid">
                    <div><span className="em-clean-label">Event Name:</span> {selectedEvent.event_name || 'N/A'}</div>
                    <div><span className="em-clean-label">Event Type:</span> {selectedEvent.event_type || 'N/A'}</div>
                    <div><span className="em-clean-label">Start Date:</span> {selectedEvent.date}</div>
                    <div><span className="em-clean-label">End Date:</span> {selectedEvent.end_date || selectedEvent.date}</div>
                    <div><span className="em-clean-label">Time:</span> {selectedEvent.time || 'Not specified'}</div>
                    <div><span className="em-clean-label">Location:</span> {selectedEvent.location || 'N/A'}</div>
                    <div><span className="em-clean-label">Guests:</span> {selectedEvent.guests_count || 0} PAX</div>
                    <div><span className="em-clean-label">Progress:</span> <Progress percent={selectedEvent.progress || 0} size="small" strokeColor="#3b82f6" style={{ width: 120 }} /></div>
                </div>
            </div>

            <Divider />

            <div className="em-clean-section">
                <div className="em-clean-section-title"><TeamOutlined /> Assigned Staff</div>
                {selectedEvent.assigned_staff?.length > 0 ? (
                    <Table 
                        dataSource={selectedEvent.assigned_staff} 
                        rowKey="staff_id" 
                        columns={[
                            { title: 'Name', dataIndex: 'name' },
                            { title: 'Role', dataIndex: 'role' },
                            { title: 'Schedule', dataIndex: 'schedule' },
                            { title: 'Status', dataIndex: 'status', render: (s) => <Tag color={s === 'confirmed' ? 'success' : 'warning'}>{s}</Tag> }
                        ]}
                        pagination={false} 
                        size="small" 
                        className={tableClass}
                    />
                ) : (
                    <Empty description="No staff assigned" />
                )}
            </div>

            <Divider />

            <div className="em-clean-section">
                <div className="em-clean-section-title"><SwapOutlined /> Equipment In/Out History</div>
                {selectedEvent.equipment_in_out?.length > 0 ? (
                    <Table 
                        dataSource={selectedEvent.equipment_in_out} 
                        columns={equipmentTrackingColumns}
                        pagination={false} 
                        size="small" 
                        className={tableClass}
                    />
                ) : (
                    <Empty description="No equipment transactions" />
                )}
            </div>

            <Divider />

            <div className="em-clean-section">
                <div className="em-clean-section-title"><TruckOutlined /> Delivery Tracking</div>
                {selectedEvent.delivery_tracking?.length > 0 ? (
                    <Table 
                        dataSource={selectedEvent.delivery_tracking} 
                        columns={[
                            { title: 'Vehicle', dataIndex: 'vehicle' },
                            { title: 'Driver', dataIndex: 'driver' },
                            { title: 'Contact', dataIndex: 'driver_phone' },
                            { title: 'ETA', dataIndex: 'eta' },
                            { title: 'Location', dataIndex: 'location' },
                            { title: 'Status', dataIndex: 'status', render: (s) => <Tag color={getDeliveryStatusConfig(s).color}>{getDeliveryStatusConfig(s).text}</Tag> }
                        ]}
                        pagination={false} 
                        size="small" 
                        className={tableClass}
                    />
                ) : (
                    <Empty description="No deliveries" />
                )}
            </div>
        </div>
    )}
</Modal>

                {/* ==================== EDIT EVENT MODAL ==================== */}
                <Modal
                    title={
                        <div className="em-modal-header-clean">
                            <div className="em-modal-title-icon"><EditOutlined /></div>
                            <div className="em-modal-title-text">Edit Event</div>
                            <div className="em-modal-badge">{selectedEvent?.booking_no || 'N/A'}</div>
                        </div>
                    }
                    open={editEventModalVisible}
                    onCancel={() => setEditEventModalVisible(false)}
                    width={700}
                    className={modalClass}
                    footer={
                        <div className="em-modal-footer-simple">
                            <Button onClick={() => setEditEventModalVisible(false)}>Cancel</Button>
                            <Button type="primary" onClick={() => eventForm.submit()}>Save Changes</Button>
                        </div>
                    }
                    bodyStyle={{ padding: 0, maxHeight: 'none', overflow: 'visible' }}
                >
                    <div className="em-modal-clean-content">
                        <Form form={eventForm} layout="vertical" onFinish={handleUpdateEvent}>
                            <Row gutter={16}>
                                <Col span={12}>
                                    <Form.Item name="customer_name" label="Customer Name" rules={[{ required: true }]}>
                                        <Input />
                                    </Form.Item>
                                </Col>
                                <Col span={12}>
                                    <Form.Item name="event_name" label="Event Name" rules={[{ required: true }]}>
                                        <Input />
                                    </Form.Item>
                                </Col>
                            </Row>
                            <Row gutter={16}>
                                <Col span={12}>
                                    <Form.Item name="event_type" label="Event Type" rules={[{ required: true }]}>
                                        <Select>
                                            <Option value="Seminar">Seminar</Option>
                                            <Option value="Workshop">Workshop</Option>
                                            <Option value="Conference">Conference</Option>
                                            <Option value="Wedding">Wedding</Option>
                                            <Option value="Corporate Event">Corporate Event</Option>
                                            <Option value="Birthday Party">Birthday Party</Option>
                                        </Select>
                                    </Form.Item>
                                </Col>
                                <Col span={12}>
                                    <Form.Item name="guests_count" label="Expected Participants">
                                        <InputNumber min={1} style={{ width: '100%' }} />
                                    </Form.Item>
                                </Col>
                            </Row>
                            <Row gutter={16}>
                                <Col span={12}>
                                    <Form.Item name="date" label="Start Date" rules={[{ required: true }]}>
                                        <DatePicker style={{ width: '100%' }} />
                                    </Form.Item>
                                </Col>
                                <Col span={12}>
                                    <Form.Item name="end_date" label="End Date">
                                        <DatePicker style={{ width: '100%' }} />
                                    </Form.Item>
                                </Col>
                            </Row>
                            <Row gutter={16}>
                                <Col span={12}>
                                    <Form.Item name="time" label="Start Time" rules={[{ required: true }]}>
                                        <Select>
                                            <Option value="8:00 AM">8:00 AM</Option>
                                            <Option value="9:00 AM">9:00 AM</Option>
                                            <Option value="10:00 AM">10:00 AM</Option>
                                            <Option value="1:00 PM">1:00 PM</Option>
                                            <Option value="3:00 PM">3:00 PM</Option>
                                            <Option value="7:00 PM">7:00 PM</Option>
                                        </Select>
                                    </Form.Item>
                                </Col>
                                <Col span={12}>
                                    <Form.Item name="location" label="Location" rules={[{ required: true }]}>
                                        <Input />
                                    </Form.Item>
                                </Col>
                            </Row>
                        </Form>
                    </div>
                </Modal>

                {/* ==================== STAFF ASSIGNMENT MODAL ==================== */}
                <Modal
                    title={
                        <div className="em-modal-header-clean">
                            <div className="em-modal-title-icon"><TeamOutlined /></div>
                            <div className="em-modal-title-text">Staff Assignment</div>
                            <div className="em-modal-badge">{selectedEventForStaff?.booking_no || 'N/A'}</div>
                        </div>
                    }
                    open={staffAssignmentModalVisible}
                    onCancel={() => setStaffAssignmentModalVisible(false)}
                    width={800}
                    className={modalClass}
                    footer={
                        <div className="em-modal-footer-simple">
                            <Button onClick={() => setStaffAssignmentModalVisible(false)}>Close</Button>
                        </div>
                    }
                    bodyStyle={{ padding: 0, maxHeight: 'none', overflow: 'visible' }}
                >
                    {selectedEventForStaff && (
                        <div className="em-modal-clean-content">
                            <div className="em-modal-header-tags">
                                <span className="em-id-text">{selectedEventForStaff.booking_no || 'N/A'}</span>
                                <span className="em-customer-name">{selectedEventForStaff.display_name || selectedEventForStaff.event_name}</span>
                                <Tag color="blue">{selectedEventForStaff.date} at {selectedEventForStaff.time}</Tag>
                            </div>

                            <div style={{ marginBottom: 16, textAlign: 'right' }}>
                                <Button type="primary" icon={<UserAddOutlined />} onClick={() => setAddStaffModalVisible(true)}>
                                    Assign Staff
                                </Button>
                            </div>

                            <Table
                                dataSource={selectedEventForStaff.assigned_staff || []}
                                rowKey="staff_id"
                                columns={[
                                    { title: 'Name', dataIndex: 'name' },
                                    { title: 'Role', dataIndex: 'role' },
                                    { title: 'Schedule', dataIndex: 'schedule' },
                                    { title: 'Phone', dataIndex: 'phone' },
                                    { 
                                        title: 'Status', 
                                        dataIndex: 'status', 
                                        render: (status, record) => (
                                            <Select value={status} size="small" onChange={(val) => handleUpdateStaffStatus(selectedEventForStaff.id, record.staff_id, val)} style={{ width: 100 }}>
                                                <Option value="pending">Pending</Option>
                                                <Option value="confirmed">Confirmed</Option>
                                                <Option value="declined">Declined</Option>
                                            </Select>
                                        ) 
                                    },
                                    { 
                                        title: 'Action', 
                                        render: (_, record) => (
                                            <Popconfirm title="Remove staff?" onConfirm={() => handleRemoveStaff(selectedEventForStaff.id, record.staff_id)}>
                                                <Button type="text" danger icon={<DeleteOutlined />} />
                                            </Popconfirm>
                                        ) 
                                    }
                                ]}
                                pagination={false}
                                size="small"
                                className={tableClass}
                            />
                        </div>
                    )}
                </Modal>

                {/* ==================== ADD STAFF MODAL ==================== */}
               <Modal
    title={
        <div className="em-modal-header-clean">
            <div className="em-modal-title-icon"><UserAddOutlined /></div>
            <div className="em-modal-title-text">Assign Staff to Event</div>
            <div className="em-modal-badge">{selectedEventForStaff?.booking_no || 'N/A'}</div>
        </div>
    }
    open={addStaffModalVisible}
    onCancel={() => {
        setAddStaffModalVisible(false);
        staffForm.resetFields();
    }}
    width={580}
    className={modalClass}
    footer={null}
    bodyStyle={{ padding: 0, maxHeight: 'none', overflow: 'visible' }}
>
    <div className="em-modal-clean-content">
        {/* Event Summary */}
        <div className="em-modal-event-summary">
            <div className="em-summary-row">
                <span className="em-summary-label">Event:</span>
                <span className="em-summary-value">{selectedEventForStaff?.display_name || selectedEventForStaff?.event_name || 'N/A'}</span>
            </div>
            <div className="em-summary-row">
                <span className="em-summary-label">Date:</span>
                <span className="em-summary-value">{selectedEventForStaff?.date} at {selectedEventForStaff?.time || 'TBD'}</span>
            </div>
            <div className="em-summary-row">
                <span className="em-summary-label">Location:</span>
                <span className="em-summary-value">{selectedEventForStaff?.location || 'TBD'}</span>
            </div>
            <div className="em-summary-row">
                <span className="em-summary-label">Guests:</span>
                <span className="em-summary-value">{selectedEventForStaff?.guests_count || 0} PAX</span>
            </div>
        </div>

        <Divider style={{ margin: '12px 0 16px 0' }} />

        <Form form={staffForm} layout="vertical" onFinish={handleAddStaffToEvent}>
            <Row gutter={16}>
                <Col span={24}>
                    <Form.Item 
                        name="staff_id" 
                        label="Select Staff Members" 
                        rules={[{ required: true, message: 'Please select at least one staff member' }]}
                    >
                        <Select 
                            placeholder="Search and select staff members..." 
                            showSearch 
                            mode="multiple"
                            notFoundContent="No staff available"
                            maxTagCount={3}
                            className="em-select-enhanced"
                            optionFilterProp="children"
                        >
                            {staffList && staffList.length > 0 ? (
                                staffList.map(staff => (
                                    <Option key={staff.employee_id || staff.id} value={staff.employee_id || staff.id}>
                                        <div className="em-staff-option">
                                            <span className="em-staff-name">{staff.full_name || staff.name || 'Unknown'}</span>
                                            <span className="em-staff-position">{staff.position?.title || staff.position || 'Staff'}</span>
                                        </div>
                                    </Option>
                                ))
                            ) : (
                                <Option value="" disabled>No staff members found</Option>
                            )}
                        </Select>
                        <small className="em-form-hint">
                            <TeamOutlined /> Select multiple staff members to assign to this event
                        </small>
                    </Form.Item>
                </Col>
            </Row>

            <Row gutter={16}>
                <Col span={12}>
                    <Form.Item 
                        name="role" 
                        label="Role" 
                        rules={[{ required: true, message: 'Please enter a role' }]}
                    >
                        <Input 
                            placeholder="e.g., Event Coordinator" 
                            className="em-input-enhanced"
                            prefix={<UserOutlined />}
                        />
                    </Form.Item>
                </Col>
                <Col span={12}>
                    <Form.Item 
                        name="schedule" 
                        label="Schedule" 
                        rules={[{ required: true, message: 'Please enter schedule' }]}
                    >
                        <Input 
                            placeholder="e.g., 08:00 - 17:00" 
                            className="em-input-enhanced"
                            prefix={<ScheduleOutlined />}
                            defaultValue={`${selectedEventForStaff?.time || '08:00'} - ${selectedEventForStaff?.time ? dayjs(selectedEventForStaff.time, 'HH:mm').add(8, 'hour').format('HH:mm') : '17:00'}`}
                        />
                        <small className="em-form-hint">
                            <ClockCircleOutlined /> Based on event time: {selectedEventForStaff?.time || '08:00'}
                        </small>
                    </Form.Item>
                </Col>
            </Row>

            <Row gutter={16}>
                <Col span={12}>
                    <Form.Item name="phone" label="Contact Number">
                        <Input 
                            placeholder="Phone number" 
                            className="em-input-enhanced"
                            prefix={<PhoneOutlined />}
                            defaultValue={selectedEventForStaff?.customer_phone || ''}
                        />
                        <small className="em-form-hint">Auto-filled from event contact</small>
                    </Form.Item>
                </Col>
                <Col span={12}>
                    <Form.Item name="email" label="Email Address">
                        <Input 
                            placeholder="Email address" 
                            className="em-input-enhanced"
                            prefix={<MailOutlined />}
                            defaultValue={selectedEventForStaff?.customer_email || ''}
                        />
                        <small className="em-form-hint">Auto-filled from event contact</small>
                    </Form.Item>
                </Col>
            </Row>

            <div className="em-modal-footer-simple" style={{ padding: '16px 0 0 0' }}>
                <Button onClick={() => {
                    setAddStaffModalVisible(false);
                    staffForm.resetFields();
                }}>Cancel</Button>
                <Button type="primary" htmlType="submit" icon={<UserAddOutlined />}>
                    Assign Staff
                </Button>
            </div>
        </Form>
    </div>
</Modal>
                {/* ==================== CHECKLIST MODAL ==================== */}
                <Modal
                    title={
                        <div className="em-modal-header-clean">
                            <div className="em-modal-title-icon"><CheckCircleOutlined /></div>
                            <div className="em-modal-title-text">Event Checklist</div>
                            <div className="em-modal-badge">{selectedEventForChecklist?.booking_no || 'N/A'}</div>
                        </div>
                    }
                    open={checklistModalVisible}
                    onCancel={() => setChecklistModalVisible(false)}
                    width={800}
                    className={modalClass}
                    footer={
                        <div className="em-modal-footer-simple">
                            <Button 
                                type="primary" 
                                icon={<PlusOutlined />} 
                                onClick={() => {
                                    const task = prompt('Enter task description:');
                                    const assignedTo = prompt('Assign to:');
                                    if (task) {
                                        handleAddChecklistItem({ task, assigned_to: assignedTo || 'Unassigned' });
                                    }
                                }}
                            >
                                Add Task
                            </Button>
                            <Button onClick={() => setChecklistModalVisible(false)}>Close</Button>
                        </div>
                    }
                    bodyStyle={{ padding: 0, maxHeight: 'none', overflow: 'visible' }}
                >
                    {selectedEventForChecklist && (
                        <div className="em-modal-clean-content">
                            <div className="em-modal-header-tags">
                                <span className="em-id-text">{selectedEventForChecklist.booking_no || 'N/A'}</span>
                                <span className="em-customer-name">{selectedEventForChecklist.display_name || selectedEventForChecklist.event_name}</span>
                                <Tag color="blue">{selectedEventForChecklist.date}</Tag>
                            </div>

                            <div style={{ marginBottom: 12, display: 'flex', gap: 8, flexWrap: 'wrap' }}>
                                <Tag color="blue">📦 Delivery Items</Tag>
                                <Tag color="green">📋 Custom Tasks</Tag>
                            </div>

                            <List
                                dataSource={checklist}
                                rowKey="id"
                                renderItem={item => (
                                    <List.Item
                                        className="em-checklist-item"
                                        actions={[
                                            <Select key="status" value={item.status} size="small" onChange={(val) => handleUpdateChecklistItem(item.id, val)} style={{ width: 120 }}>
                                                <Option value="pending">Pending</Option>
                                                <Option value="in_progress">In Progress</Option>
                                                <Option value="completed">Completed</Option>
                                            </Select>,
                                            !item.is_delivery && (
                                                <Popconfirm key="delete" title="Delete this item?" onConfirm={() => handleDeleteChecklistItem(selectedEventForChecklist.id, item.id)}>
                                                    <Button type="text" danger icon={<DeleteOutlined />} size="small" />
                                                </Popconfirm>
                                            )
                                        ]}
                                    >
                                        <List.Item.Meta
                                            avatar={
                                                item.status === 'completed' ? 
                                                    <CheckCircleOutlined style={{ color: '#10b981' }} /> : 
                                                    item.status === 'in_progress' ? 
                                                        <LoadingOutlined style={{ color: '#3b82f6' }} /> : 
                                                        <ClockCircleOutlined style={{ color: '#f59e0b' }} />
                                            }
                                            title={
                                                <div>
                                                    <span>{item.task}</span>
                                                    {item.is_delivery && <Tag color="blue" size="small" style={{ marginLeft: 8 }}>Delivery</Tag>}
                                                    {!item.is_delivery && <Tag color="green" size="small" style={{ marginLeft: 8 }}>Custom</Tag>}
                                                </div>
                                            }
                                            description={`Assigned to: ${item.assigned_to}${item.notes ? ` | ${item.notes}` : ''}`}
                                        />
                                    </List.Item>
                                )}
                            />

                            <Progress 
                                percent={Math.round((checklist.filter(i => i.status === 'completed').length / Math.max(checklist.length, 1)) * 100)} 
                                strokeColor="#3b82f6" 
                                style={{ marginTop: 20 }} 
                            />
                        </div>
                    )}
                </Modal>

                {/* ==================== DELIVERY TRACKING MODAL ==================== */}
                <Modal
                    title={
                        <div className="em-modal-header-clean">
                            <div className="em-modal-title-icon"><TruckOutlined /></div>
                            <div className="em-modal-title-text">Delivery Tracking</div>
                            <div className="em-modal-badge">{selectedEventForDelivery?.booking_no || 'N/A'}</div>
                        </div>
                    }
                    open={deliveryTrackingModalVisible}
                    onCancel={() => setDeliveryTrackingModalVisible(false)}
                    width={900}
                    className={modalClass}
                    footer={
                        <div className="em-modal-footer-simple">
                            <Button type="primary" icon={<PlusOutlined />} onClick={() => {
                                addDeliveryForm.resetFields();
                                setAddDeliveryModalVisible(true);
                            }}>
                                Add Delivery
                            </Button>
                            <Button onClick={() => setDeliveryTrackingModalVisible(false)}>Close</Button>
                        </div>
                    }
                    bodyStyle={{ padding: 0, maxHeight: 'none', overflow: 'visible' }}
                >
                    {selectedEventForDelivery && (
                        <div className="em-modal-clean-content">
                            <div className="em-modal-header-tags">
                                <span className="em-id-text">{selectedEventForDelivery.booking_no || 'N/A'}</span>
                                <span className="em-customer-name">{selectedEventForDelivery.display_name || selectedEventForDelivery.event_name}</span>
                                <Tag color="blue">{selectedEventForDelivery.location || 'Venue TBD'}</Tag>
                            </div>

                            <Table
                                dataSource={deliveryTrackings}
                                rowKey="id"
                                columns={[
                                    { title: 'Vehicle', dataIndex: 'vehicle' },
                                    { title: 'Driver', dataIndex: 'driver' },
                                    { title: 'Contact', dataIndex: 'driver_phone' },
                                    { title: 'ETA', dataIndex: 'eta' },
                                    { title: 'Location', dataIndex: 'location' },
                                    { 
                                        title: 'Status', 
                                        dataIndex: 'status', 
                                        render: (s) => <Tag color={getDeliveryStatusConfig(s).color}>{getDeliveryStatusConfig(s).text}</Tag> 
                                    },
                                    { 
                                        title: 'Action', 
                                        render: (_, record) => (
                                            <Space>
                                                <Select value={record.status} size="small" onChange={(val) => handleUpdateDeliveryStatus(record.id, val)} style={{ width: 110 }}>
                                                    <Option value="pending">Pending</Option>
                                                    <Option value="departed">Departed</Option>
                                                    <Option value="en_route">En Route</Option>
                                                    <Option value="arrived">Arrived</Option>
                                                    <Option value="completed">Completed</Option>
                                                </Select>
                                                {record.status !== 'completed' && record.status !== 'arrived' && (
                                                    <Button size="small" type="text" onClick={() => {
                                                        const location = prompt('Enter current location:');
                                                        if (location) {
                                                            handleUpdateDeliveryStatus(record.id, record.status, location);
                                                        }
                                                    }}>Update Location</Button>
                                                )}
                                            </Space>
                                        ) 
                                    }
                                ]}
                                pagination={false}
                                size="small"
                                className={tableClass}
                            />
                        </div>
                    )}
                </Modal>

                {/* ==================== ADD DELIVERY MODAL ==================== */}
                <Modal
                    title={
                        <div className="em-modal-header-clean">
                            <div className="em-modal-title-icon"><PlusOutlined /></div>
                            <div className="em-modal-title-text">Add New Delivery</div>
                            <div className="em-modal-badge">{selectedEventForDelivery?.booking_no || 'N/A'}</div>
                        </div>
                    }
                    open={addDeliveryModalVisible}
                    onCancel={() => {
                        setAddDeliveryModalVisible(false);
                        addDeliveryForm.resetFields();
                    }}
                    width={500}
                    className={modalClass}
                    footer={null}
                    bodyStyle={{ padding: 0, maxHeight: 'none', overflow: 'visible' }}
                >
                    <div className="em-modal-clean-content">
                        <Form form={addDeliveryForm} layout="vertical" onFinish={handleAddDeliverySubmit}>
                            <Form.Item name="vehicle" label="Vehicle" rules={[{ required: true }]}>
                                <Input placeholder="Vehicle number/name" />
                            </Form.Item>
                            <Form.Item name="driver" label="Driver" rules={[{ required: true }]}>
                                <Input placeholder="Driver name" />
                            </Form.Item>
                            <Form.Item name="driver_phone" label="Driver Phone">
                                <Input placeholder="Contact number" />
                            </Form.Item>
                            <Form.Item name="eta" label="Estimated Arrival" rules={[{ required: true }]}>
                                <Input placeholder="e.g., 10:00 AM" />
                            </Form.Item>
                            <Form.Item name="location" label="Current Location">
                                <Input placeholder="Location" />
                            </Form.Item>
                            <Form.Item name="items" label="Items to Deliver">
                                <TextArea rows={2} placeholder="List items being delivered" />
                            </Form.Item>
                            <div className="em-modal-footer-simple" style={{ padding: '16px 0 0 0' }}>
                                <Button onClick={() => {
                                    setAddDeliveryModalVisible(false);
                                    addDeliveryForm.resetFields();
                                }}>Cancel</Button>
                                <Button type="primary" htmlType="submit">Add Delivery</Button>
                            </div>
                        </Form>
                    </div>
                </Modal>

                {/* ==================== EQUIPMENT CHECKOUT MODAL ==================== */}
                <Modal
                    title={
                        <div className="em-modal-header-clean">
                            <div className="em-modal-title-icon"><PlusCircleOutlined /></div>
                            <div className="em-modal-title-text">Checkout Equipment</div>
                            <div className="em-modal-badge">{selectedEventForEquipment?.booking_no || 'N/A'}</div>
                        </div>
                    }
                    open={equipmentCheckoutModalVisible}
                    onCancel={() => setEquipmentCheckoutModalVisible(false)}
                    width={500}
                    className={modalClass}
                    footer={null}
                    bodyStyle={{ padding: 0, maxHeight: 'none', overflow: 'visible' }}
                >
                    <div className="em-modal-clean-content">
                        <Form form={equipmentForm} layout="vertical" onFinish={handleCheckoutEquipment}>
                            <Form.Item 
                                name="expected_return_date" 
                                label="Expected Return Date" 
                                rules={[{ required: true }]}
                            >
                                <DatePicker 
                                    style={{ width: '100%' }} 
                                    onChange={(date) => {
                                        equipmentForm.setFieldsValue({ equipment_id: undefined });
                                        message.info('Please re-select equipment after changing date');
                                    }}
                                />
                            </Form.Item>
                            <Form.Item name="equipment_id" label="Equipment" rules={[{ required: true }]}>
                                <Select 
                                    placeholder="Select equipment" 
                                    showSearch
                                    optionFilterProp="children"
                                    onChange={handleEquipmentSelect}
                                    notFoundContent="No equipment available"
                                >
                                    {availableEquipmentList && availableEquipmentList.length > 0 ? (
                                        availableEquipmentList.map(eq => {
                                            const isAvailable = eq.available > 0;
                                            return (
                                                <Option 
                                                    key={eq.id} 
                                                    value={eq.id}
                                                    disabled={!isAvailable}
                                                >
                                                    {eq.name} ({isAvailable ? `Available: ${eq.available}` : 'OUT OF STOCK'})
                                                </Option>
                                            );
                                        })
                                    ) : (
                                        <Option value="" disabled>No equipment found</Option>
                                    )}
                                </Select>
                            </Form.Item>
                            <Form.Item name="quantity" label="Quantity" rules={[{ required: true }]}>
                                <InputNumber min={1} style={{ width: '100%' }} placeholder="Quantity" />
                            </Form.Item>
                            <Form.Item name="condition_out" label="Condition (Out)" rules={[{ required: true }]}>
                                <Select placeholder="Select condition">
                                    <Option value="Excellent">Excellent</Option>
                                    <Option value="Good">Good</Option>
                                    <Option value="Fair">Fair</Option>
                                    <Option value="Poor">Poor</Option>
                                </Select>
                            </Form.Item>
                            <Form.Item name="checked_out_by" label="Checked Out By">
                                <Input placeholder="Name of person checking out" />
                            </Form.Item>
                            <Form.Item name="notes" label="Notes">
                                <TextArea rows={3} placeholder="Additional notes" />
                            </Form.Item>
                            <div className="em-modal-footer-simple" style={{ padding: '16px 0 0 0' }}>
                                <Button onClick={() => setEquipmentCheckoutModalVisible(false)}>Cancel</Button>
                                <Button type="primary" htmlType="submit">Checkout Equipment</Button>
                            </div>
                        </Form>
                    </div>
                </Modal>

                {/* ==================== EQUIPMENT RETURN MODAL ==================== */}
                <Modal
    title={
        <div className="em-modal-header-clean">
            <div className="em-modal-title-icon"><SwapOutlined /></div>
            <div className="em-modal-title-text">Return Equipment</div>
            <div className="em-modal-badge">{selectedEventForEquipment?.booking_no || 'N/A'}</div>
        </div>
    }
    open={equipmentReturnModalVisible}
    onCancel={() => {
        setEquipmentReturnModalVisible(false);
        setSelectedEquipmentItem(null);
        equipmentForm.resetFields();
    }}
    width={580}
    className={modalClass}
    footer={null}
    bodyStyle={{ padding: 0, maxHeight: 'none', overflow: 'visible' }}
>
    <div className="em-modal-clean-content">
        {/* Equipment Summary */}
        <div className="em-modal-equipment-summary">
            <div className="em-summary-row">
                <span className="em-summary-label">Equipment:</span>
                <span className="em-summary-value"><strong>{selectedEquipmentItem?.equipment?.name || selectedEquipmentItem?.equipment_name || 'N/A'}</strong></span>
            </div>
            <div className="em-summary-row">
                <span className="em-summary-label">Quantity:</span>
                <span className="em-summary-value">{selectedEquipmentItem?.quantity_reserved || selectedEquipmentItem?.quantity || 0} units</span>
            </div>
            <div className="em-summary-row">
                <span className="em-summary-label">Checkout Date:</span>
                <span className="em-summary-value">{selectedEquipmentItem?.checked_out_date ? dayjs(selectedEquipmentItem.checked_out_date).format('MMM DD, YYYY') : 'N/A'}</span>
            </div>
            <div className="em-summary-row">
                <span className="em-summary-label">Expected Return:</span>
                <span className="em-summary-value" style={{ color: selectedEquipmentItem?.rental_end_date && dayjs(selectedEquipmentItem.rental_end_date).isBefore(dayjs()) && selectedEquipmentItem.status !== 'returned' ? '#ef4444' : 'inherit' }}>
                    {selectedEquipmentItem?.rental_end_date ? dayjs(selectedEquipmentItem.rental_end_date).format('MMM DD, YYYY') : 'N/A'}
                    {selectedEquipmentItem?.rental_end_date && dayjs(selectedEquipmentItem.rental_end_date).isBefore(dayjs()) && selectedEquipmentItem.status !== 'returned' && (
                        <Tag color="error" style={{ marginLeft: 8 }}>OVERDUE</Tag>
                    )}
                </span>
            </div>
        </div>

        <Divider style={{ margin: '12px 0 16px 0' }} />

        <Form form={equipmentForm} layout="vertical" onFinish={handleReturnEquipment}>
            <Row gutter={16}>
                <Col span={24}>
                    <Form.Item 
                        name="equipment_name" 
                        label="Equipment"
                    >
                        <Input disabled className="em-input-enhanced" />
                    </Form.Item>
                </Col>
            </Row>

            <Row gutter={16}>
                <Col span={12}>
                    <Form.Item 
                        name="quantity" 
                        label="Quantity"
                    >
                        <InputNumber disabled style={{ width: '100%' }} className="em-input-enhanced" />
                    </Form.Item>
                </Col>
                <Col span={12}>
                    <Form.Item 
                        name="condition_in" 
                        label="Condition (In)" 
                        rules={[{ required: true, message: 'Please select condition' }]}
                    >
                        <Select placeholder="Select condition" className="em-select-enhanced">
                            <Option value="Excellent">⭐ Excellent</Option>
                            <Option value="Good">✅ Good</Option>
                            <Option value="Fair">⚠️ Fair</Option>
                            <Option value="Poor">❌ Poor</Option>
                            <Option value="Damaged">🔧 Damaged</Option>
                        </Select>
                    </Form.Item>
                </Col>
            </Row>

            <Row gutter={16}>
                <Col span={8}>
                    <Form.Item name="quantity_used" label="Quantity Used">
                        <InputNumber min={0} style={{ width: '100%' }} placeholder="0" className="em-input-enhanced" />
                    </Form.Item>
                </Col>
                <Col span={8}>
                    <Form.Item name="quantity_damaged" label="Quantity Damaged">
                        <InputNumber min={0} style={{ width: '100%' }} placeholder="0" className="em-input-enhanced" />
                    </Form.Item>
                </Col>
                <Col span={8}>
                    <Form.Item name="quantity_missing" label="Quantity Missing">
                        <InputNumber min={0} style={{ width: '100%' }} placeholder="0" className="em-input-enhanced" />
                    </Form.Item>
                </Col>
            </Row>

            <Row gutter={16}>
                <Col span={12}>
                    <Form.Item name="returned_by" label="Returned By">
                        <Input placeholder="Name of person returning" className="em-input-enhanced" prefix={<UserOutlined />} />
                    </Form.Item>
                </Col>
                <Col span={12}>
                    <Form.Item name="return_date" label="Return Date">
                        <DatePicker style={{ width: '100%' }} className="em-input-enhanced" defaultValue={dayjs()} />
                    </Form.Item>
                </Col>
            </Row>

            <Row gutter={16}>
                <Col span={24}>
                    <Form.Item name="return_notes" label="Return Notes">
                        <TextArea rows={3} placeholder="Notes about return condition, issues, or additional information..." className="em-input-enhanced" />
                    </Form.Item>
                </Col>
            </Row>

            <div className="em-modal-footer-simple" style={{ padding: '16px 0 0 0' }}>
                <Button onClick={() => {
                    setEquipmentReturnModalVisible(false);
                    setSelectedEquipmentItem(null);
                    equipmentForm.resetFields();
                }}>Cancel</Button>
                <Button type="primary" htmlType="submit" icon={<CheckCircleOutlined />}>
                    Confirm Return
                </Button>
            </div>
        </Form>
    </div>
</Modal>
                {/* ==================== LIVE STATUS MODAL ==================== */}
                <Modal
                    title={
                        <div className="em-modal-header-clean">
                            <div className="em-modal-title-icon"><DashboardOutlined /></div>
                            <div className="em-modal-title-text">Live Event Monitoring</div>
                            <div className="em-modal-badge">{selectedOngoingEvent?.booking_no || 'N/A'}</div>
                        </div>
                    }
                    open={liveStatusModalVisible}
                    onCancel={() => setLiveStatusModalVisible(false)}
                    width={1100}
                    className={modalClass}
                    footer={
                        <div className="em-modal-footer-simple">
                            <Button icon={<ReloadOutlined />} onClick={() => {
                                loadEvents();
                                const updated = events.find(e => e.id === selectedOngoingEvent?.id);
                                if (updated) setSelectedOngoingEvent(updated);
                            }}>
                                Refresh
                            </Button>
                            <Button type="primary" onClick={() => setLiveStatusModalVisible(false)}>Close</Button>
                        </div>
                    }
                    bodyStyle={{ padding: 0, maxHeight: 'none', overflow: 'visible' }}
                >
                    {selectedOngoingEvent && (
                        <div className="em-modal-clean-content">
                            <div className="em-modal-header-tags">
                                <span className="em-id-text">{selectedOngoingEvent.booking_no || 'N/A'}</span>
                                <span className="em-status" style={{ color: getStatusConfig(selectedOngoingEvent.status).color, background: getStatusConfig(selectedOngoingEvent.status).bg }}>
                                    {getStatusConfig(selectedOngoingEvent.status).icon} {getStatusConfig(selectedOngoingEvent.status).text}
                                </span>
                                <Tag color="blue">{selectedOngoingEvent.customer_name || 'Unknown'}</Tag>
                                {selectedOngoingEvent.total_days > 1 && (
                                    <Tag color="purple">Day {selectedOngoingEvent.current_day || 1} of {selectedOngoingEvent.total_days || 1}</Tag>
                                )}
                            </div>

                            <div className="em-overall-progress">
                                <Title level={5}>Overall Event Progress</Title>
                                <Progress percent={selectedOngoingEvent.progress || 0} strokeColor="#8b5cf6" />
                                <Row gutter={16} style={{ marginTop: 16 }}>
                                    <Col span={6}>
                                        <div className="em-info-card">
                                            <Text type="secondary">Customer</Text>
                                            <div><Text strong>{selectedOngoingEvent.customer_name || 'Unknown'}</Text></div>
                                        </div>
                                    </Col>
                                    <Col span={6}>
                                        <div className="em-info-card">
                                            <Text type="secondary">Event Date</Text>
                                            <div><Text strong>{selectedOngoingEvent.date}</Text></div>
                                        </div>
                                    </Col>
                                    <Col span={6}>
                                        <div className="em-info-card">
                                            <Text type="secondary">Venue</Text>
                                            <div><Text strong>{selectedOngoingEvent.location || 'N/A'}</Text></div>
                                        </div>
                                    </Col>
                                    <Col span={6}>
                                        <div className="em-info-card">
                                            <Text type="secondary">Total Guests</Text>
                                            <div><Text strong>{selectedOngoingEvent.guests_count || 0} PAX</Text></div>
                                        </div>
                                    </Col>
                                </Row>
                            </div>

                            <Divider />

                            <Tabs defaultActiveKey="0" className="em-daily-tabs">
                                {[...Array(selectedOngoingEvent.total_days || 1)].map((_, idx) => {
                                    const dayData = selectedOngoingEvent.daily_progress?.[idx] || {};
                                    const date = dayjs(selectedOngoingEvent.date).add(idx, 'day');
                                    const isToday = date.isToday();
                                    
                                    return (
                                        <TabPane 
                                            key={idx}
                                            tab={
                                                <span>
                                                    {isToday && <Badge dot color="red" />}
                                                    Day {idx + 1}
                                                    <Text type="secondary" style={{ fontSize: 11, marginLeft: 4 }}>
                                                        {date.format('MMM DD')}
                                                    </Text>
                                                </span>
                                            }
                                        >
                                            <div className="em-day-content">
                                                <Row gutter={16}>
                                                    <Col span={12}>
                                                        <Card title="📊 Daily Progress" size="small">
                                                            <Progress 
                                                                percent={dayData.completion || 0} 
                                                                strokeColor={dayData.completion >= 100 ? '#10b981' : '#3b82f6'}
                                                            />
                                                            <Text type="secondary">{dayData.notes || 'No notes for this day'}</Text>
                                                            <div style={{ marginTop: 8 }}>
                                                                <Button 
                                                                    size="small" 
                                                                    onClick={() => {
                                                                        const newCompletion = prompt('Enter completion percentage (0-100):', dayData.completion || 0);
                                                                        const newNotes = prompt('Enter notes:', dayData.notes || '');
                                                                        if (newCompletion !== null) {
                                                                            handleUpdateDailyProgress(idx + 1, parseInt(newCompletion), newNotes || '');
                                                                        }
                                                                    }}
                                                                >
                                                                    Update Progress
                                                                </Button>
                                                            </div>
                                                        </Card>
                                                    </Col>
                                                    <Col span={12}>
                                                        <Card title="👥 Attendance" size="small">
                                                            <div style={{ fontSize: 28, fontWeight: 'bold', color: '#3b82f6' }}>
                                                                {dayData.present || 0} / {selectedOngoingEvent.guests_count || 0}
                                                            </div>
                                                            <Text type="secondary">
                                                                Attendance Rate: {dayData.attendance_rate || 0}%
                                                            </Text>
                                                            <div style={{ marginTop: 8 }}>
                                                                <Button 
                                                                    size="small" 
                                                                    onClick={() => {
                                                                        const present = prompt('Enter number of present attendees:', dayData.present || 0);
                                                                        if (present !== null) {
                                                                            handleUpdateAttendance(idx + 1, parseInt(present));
                                                                        }
                                                                    }}
                                                                >
                                                                    Update Attendance
                                                                </Button>
                                                            </div>
                                                        </Card>
                                                    </Col>
                                                </Row>
                                            </div>
                                        </TabPane>
                                    );
                                })}
                            </Tabs>

                            <Divider />

                            <div className="em-event-notes">
                                <Title level={5}>📝 Event Notes</Title>
                                <TextArea 
                                    value={selectedOngoingEvent.notes} 
                                    onChange={(e) => {
                                        const updatedEvent = { ...selectedOngoingEvent, notes: e.target.value };
                                        setSelectedOngoingEvent(updatedEvent);
                                    }}
                                    rows={3}
                                    placeholder="Add notes about event progress, issues, or announcements..."
                                />
                            </div>

                            <div style={{ marginTop: 20, display: 'flex', justifyContent: 'space-between' }}>
                                <div>
                                    <Button icon={<ReloadOutlined />} onClick={() => {
                                        loadEvents();
                                        const updated = events.find(e => e.id === selectedOngoingEvent.id);
                                        if (updated) setSelectedOngoingEvent(updated);
                                    }}>
                                        Refresh
                                    </Button>
                                </div>
                                <div>
                                    {(selectedOngoingEvent.current_day || 1) < (selectedOngoingEvent.total_days || 1) && (
                                        <Button type="primary" onClick={handleAdvanceToNextDay}>
                                            Advance to Day {(selectedOngoingEvent.current_day || 1) + 1}
                                        </Button>
                                    )}
                                    {selectedOngoingEvent.status !== 'completed' && (
                                        <Button danger onClick={() => handleCompleteEvent(selectedOngoingEvent)} style={{ marginLeft: 12 }}>
                                            Complete Event
                                        </Button>
                                    )}
                                </div>
                            </div>
                        </div>
                    )}
                </Modal>

                {/* ==================== EQUIPMENT TRACKING MODAL ==================== */}
                <Modal
                    title={
                        <div className="em-modal-header-clean">
                            <div className="em-modal-title-icon"><SwapOutlined /></div>
                            <div className="em-modal-title-text">Equipment Tracking</div>
                            <div className="em-modal-badge">All Events</div>
                        </div>
                    }
                    open={equipmentTrackingModalVisible}
                    onCancel={() => setEquipmentTrackingModalVisible(false)}
                    width={1100}
                    className={modalClass}
                    footer={
                        <div className="em-modal-footer-simple">
                            <Button icon={<ReloadOutlined />} onClick={() => loadEquipmentTracking()}>Refresh</Button>
                            <Button type="primary" onClick={() => setEquipmentTrackingModalVisible(false)}>Close</Button>
                        </div>
                    }
                    bodyStyle={{ padding: 0, maxHeight: 'none', overflow: 'visible' }}
                >
                    <div className="em-modal-clean-content">
                        <Alert 
                            message="Equipment In/Out Tracking" 
                            description="Monitor all equipment checked out for events. Click 'Check In' to return equipment." 
                            type="info" 
                            showIcon 
                            style={{ marginBottom: 20 }}
                            className={alertClass}
                        />
                        <Table
                            dataSource={equipmentTrackingData}
                            rowKey="id"
                            columns={equipmentTrackingColumns}
                            pagination={{ pageSize: 10 }}
                            className={tableClass}
                        />
                    </div>
                </Modal>
            </div>
        </ConfigProvider>
    );
};

export default EventManagement;