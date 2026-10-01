// src/components/EquipmentInOut.jsx
import React, { useState, useEffect } from 'react';
import {
    Card,
    Table,
    Button,
    Space,
    Input,
    Select,
    Modal,
    Tag,
    message,
    Tooltip,
    Typography,
    DatePicker,
    Form,
    InputNumber,
    Descriptions,
    Row,
    Col,
    Statistic,
} from 'antd';
import {
    SwapOutlined,
    EyeOutlined,
    EditOutlined,
    DeleteOutlined,
    CheckCircleOutlined,
    ClockCircleOutlined,
    ReloadOutlined,
    CalendarOutlined,
    ArrowLeftOutlined,
} from '@ant-design/icons';
import dayjs from 'dayjs';
import { equipmentAPI } from '../services/api';

const { Title, Text } = Typography;
const { Option } = Select;
const { TextArea } = Input;

const EquipmentInOut = ({ eventId, eventDetails, onClose }) => {
    const [loading, setLoading] = useState(false);
    const [trackingRecords, setTrackingRecords] = useState([]);
    const [filterStatus, setFilterStatus] = useState('all');
    const [selectedDate, setSelectedDate] = useState(dayjs());
    const [showCheckInModal, setShowCheckInModal] = useState(false);
    const [showEditModal, setShowEditModal] = useState(false);
    const [selectedRecord, setSelectedRecord] = useState(null);
    const [checkInForm] = Form.useForm();
    const [editForm] = Form.useForm();

    useEffect(() => {
        loadTrackingRecords();
    }, [eventId, selectedDate]);

    const loadTrackingRecords = async () => {
        setLoading(true);
        try {
            const params = {
                date: selectedDate.format('YYYY-MM-DD'),
                per_page: 100,
            };
            if (eventId) {
                params.booking_id = eventId;
            }
            if (filterStatus !== 'all') {
                params.status = filterStatus;
            }
            
            const response = await equipmentAPI.getTracking(params);
            const data = response.data?.data?.data || response.data?.data || [];
            setTrackingRecords(data);
        } catch (error) {
            console.error('Failed to load tracking records:', error);
            message.error('Failed to load equipment tracking records');
        } finally {
            setLoading(false);
        }
    };

    const handleCheckIn = async (values) => {
        try {
            await equipmentAPI.checkin(selectedRecord.id, values);
            message.success('Equipment checked in successfully');
            setShowCheckInModal(false);
            checkInForm.resetFields();
            loadTrackingRecords();
        } catch (error) {
            console.error('Check-in error:', error);
            message.error(error.response?.data?.message || 'Failed to check in equipment');
        }
    };

    const handleEdit = async (values) => {
        try {
            await equipmentAPI.updateTracking(selectedRecord.id, {
                quantity_reserved: values.quantity,
                rental_end_date: values.return_date?.format('YYYY-MM-DD'),
            });
            message.success('Tracking record updated successfully');
            setShowEditModal(false);
            editForm.resetFields();
            loadTrackingRecords();
        } catch (error) {
            console.error('Update error:', error);
            message.error(error.response?.data?.message || 'Failed to update record');
        }
    };

    const getStatusConfig = (status) => {
        const config = {
            reserved: { color: 'processing', text: 'Reserved', icon: <ClockCircleOutlined /> },
            checked_out: { color: 'warning', text: 'Checked Out', icon: <SwapOutlined /> },
            returned: { color: 'success', text: 'Returned', icon: <CheckCircleOutlined /> },
            damaged: { color: 'error', text: 'Damaged', icon: <DeleteOutlined /> },
            missing: { color: 'error', text: 'Missing', icon: <DeleteOutlined /> },
        };
        return config[status] || config.reserved;
    };

    const columns = [
        {
            title: 'Equipment',
            dataIndex: ['equipment', 'name'],
            key: 'equipment',
            width: 200,
            render: (text, record) => (
                <Tooltip title={`Category: ${record.equipment?.category || 'Uncategorized'}`}>
                    <Text strong>{text || 'Unknown'}</Text>
                </Tooltip>
            ),
        },
        {
            title: 'Qty Out',
            dataIndex: 'quantity_reserved',
            key: 'quantity',
            width: 80,
            align: 'center',
            render: (qty) => <Tag color="blue">{qty}</Tag>,
        },
        {
            title: 'Qty Used',
            dataIndex: 'quantity_used',
            key: 'used',
            width: 80,
            align: 'center',
            render: (qty) => qty || 0,
        },
        {
            title: 'Qty Damaged',
            dataIndex: 'quantity_damaged',
            key: 'damaged',
            width: 100,
            align: 'center',
            render: (qty) => qty > 0 ? <Tag color="red">{qty}</Tag> : 0,
        },
        {
            title: 'Qty Missing',
            dataIndex: 'quantity_missing',
            key: 'missing',
            width: 100,
            align: 'center',
            render: (qty) => qty > 0 ? <Tag color="orange">{qty}</Tag> : 0,
        },
        {
            title: 'Checkout Date',
            dataIndex: 'checked_out_date',
            key: 'checkout_date',
            width: 130,
            render: (date) => date ? dayjs(date).format('MMM DD, YYYY') : '-',
        },
        {
            title: 'Return Date',
            dataIndex: 'rental_end_date',
            key: 'return_date',
            width: 130,
            render: (date, record) => {
                const isOverdue = record.status === 'checked_out' && dayjs(date).isBefore(dayjs());
                return (
                    <Tooltip title={isOverdue ? 'Overdue!' : ''}>
                        <Text style={{ color: isOverdue ? '#ff4d4f' : undefined }}>
                            {date ? dayjs(date).format('MMM DD, YYYY') : '-'}
                            {isOverdue && <Tag color="error" style={{ marginLeft: 8 }}>Overdue</Tag>}
                        </Text>
                    </Tooltip>
                );
            },
        },
        {
            title: 'Status',
            dataIndex: 'status',
            key: 'status',
            width: 120,
            render: (status) => {
                const config = getStatusConfig(status);
                return <Tag color={config.color}>{config.icon} {config.text}</Tag>;
            },
        },
        {
            title: 'Actions',
            key: 'actions',
            width: 200,
            render: (_, record) => (
                <Space>
                    {record.status === 'checked_out' && (
                        <Tooltip title="Check In (Return)">
                            <Button
                                size="small"
                                type="primary"
                                icon={<CheckCircleOutlined />}
                                onClick={() => {
                                    setSelectedRecord(record);
                                    checkInForm.setFieldsValue({
                                        equipment_name: record.equipment?.name,
                                        quantity: record.quantity_reserved,
                                    });
                                    setShowCheckInModal(true);
                                }}
                            >
                                Check In
                            </Button>
                        </Tooltip>
                    )}
                    {record.status !== 'returned' && (
                        <Tooltip title="Edit">
                            <Button
                                size="small"
                                icon={<EditOutlined />}
                                onClick={() => {
                                    setSelectedRecord(record);
                                    editForm.setFieldsValue({
                                        quantity: record.quantity_reserved,
                                        return_date: record.rental_end_date ? dayjs(record.rental_end_date) : null,
                                    });
                                    setShowEditModal(true);
                                }}
                            />
                        </Tooltip>
                    )}
                    <Tooltip title="View Details">
                        <Button
                            size="small"
                            icon={<EyeOutlined />}
                            onClick={() => {
                                setSelectedRecord(record);
                                Modal.info({
                                    title: 'Equipment Tracking Details',
                                    width: 600,
                                    content: (
                                        <Descriptions bordered column={2} size="small">
                                            <Descriptions.Item label="Equipment" span={2}>
                                                {record.equipment?.name}
                                            </Descriptions.Item>
                                            <Descriptions.Item label="Category">
                                                {record.equipment?.category || 'Uncategorized'}
                                            </Descriptions.Item>
                                            <Descriptions.Item label="Condition">
                                                {record.equipment?.condition || 'Good'}
                                            </Descriptions.Item>
                                            <Descriptions.Item label="Quantity Out">
                                                {record.quantity_reserved}
                                            </Descriptions.Item>
                                            <Descriptions.Item label="Quantity Used">
                                                {record.quantity_used || 0}
                                            </Descriptions.Item>
                                            <Descriptions.Item label="Quantity Damaged">
                                                {record.quantity_damaged || 0}
                                            </Descriptions.Item>
                                            <Descriptions.Item label="Quantity Missing">
                                                {record.quantity_missing || 0}
                                            </Descriptions.Item>
                                            <Descriptions.Item label="Status">
                                                <Tag color={getStatusConfig(record.status).color}>
                                                    {getStatusConfig(record.status).text}
                                                </Tag>
                                            </Descriptions.Item>
                                            <Descriptions.Item label="Checkout Date" span={2}>
                                                {record.checked_out_date ? dayjs(record.checked_out_date).format('MMM DD, YYYY') : '-'}
                                            </Descriptions.Item>
                                            <Descriptions.Item label="Expected Return" span={2}>
                                                {record.rental_end_date ? dayjs(record.rental_end_date).format('MMM DD, YYYY') : '-'}
                                            </Descriptions.Item>
                                            <Descriptions.Item label="Actual Return" span={2}>
                                                {record.checked_in_date ? dayjs(record.checked_in_date).format('MMM DD, YYYY') : '-'}
                                            </Descriptions.Item>
                                            <Descriptions.Item label="Condition Out" span={2}>
                                                {record.condition_notes_out || '-'}
                                            </Descriptions.Item>
                                            <Descriptions.Item label="Condition In" span={2}>
                                                {record.condition_notes_in || '-'}
                                            </Descriptions.Item>
                                            <Descriptions.Item label="Notes" span={2}>
                                                {record.notes || '-'}
                                            </Descriptions.Item>
                                        </Descriptions>
                                    ),
                                });
                            }}
                        />
                    </Tooltip>
                </Space>
            ),
        },
    ];

    return (
        <div style={{ padding: '16px' }}>
            {/* Header */}
            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 16 }}>
                <div style={{ display: 'flex', alignItems: 'center' }}>
                    <Button icon={<ArrowLeftOutlined />} onClick={onClose}>Back</Button>
                    <Title level={4} style={{ margin: '0 16px 0 16px' }}>Equipment Check-In/Out Tracking</Title>
                    {eventDetails && (
                        <Text type="secondary">
                            {eventDetails.display_name || eventDetails.event_name} - {eventDetails.booking_no}
                        </Text>
                    )}
                </div>
                <Button icon={<ReloadOutlined />} onClick={loadTrackingRecords}>Refresh</Button>
            </div>

            {/* Filters */}
            <div style={{ marginBottom: 16 }}>
                <Space>
                    <DatePicker
                        value={selectedDate}
                        onChange={setSelectedDate}
                        format="YYYY-MM-DD"
                        allowClear={false}
                        suffixIcon={<CalendarOutlined />}
                    />
                    <Select
                        value={filterStatus}
                        onChange={setFilterStatus}
                        style={{ width: 150 }}
                        placeholder="Filter by Status"
                    >
                        <Option value="all">All Status</Option>
                        <Option value="reserved">Reserved</Option>
                        <Option value="checked_out">Checked Out</Option>
                        <Option value="returned">Returned</Option>
                        <Option value="damaged">Damaged</Option>
                    </Select>
                </Space>
            </div>

            {/* Summary Cards */}
            <Row gutter={16} style={{ marginBottom: 16 }}>
                <Col span={6}>
                    <Card size="small">
                        <Statistic
                            title="Checked Out"
                            value={trackingRecords.filter(r => r.status === 'checked_out').length}
                            prefix={<SwapOutlined />}
                            valueStyle={{ color: '#faad14' }}
                        />
                    </Card>
                </Col>
                <Col span={6}>
                    <Card size="small">
                        <Statistic
                            title="Returned"
                            value={trackingRecords.filter(r => r.status === 'returned').length}
                            prefix={<CheckCircleOutlined />}
                            valueStyle={{ color: '#52c41a' }}
                        />
                    </Card>
                </Col>
                <Col span={6}>
                    <Card size="small">
                        <Statistic
                            title="Damaged"
                            value={trackingRecords.filter(r => r.status === 'damaged').length}
                            prefix={<DeleteOutlined />}
                            valueStyle={{ color: '#ff4d4f' }}
                        />
                    </Card>
                </Col>
                <Col span={6}>
                    <Card size="small">
                        <Statistic
                            title="Overdue"
                            value={trackingRecords.filter(r => 
                                r.status === 'checked_out' && 
                                r.rental_end_date && 
                                dayjs(r.rental_end_date).isBefore(dayjs())
                            ).length}
                            prefix={<ClockCircleOutlined />}
                            valueStyle={{ color: '#ff4d4f' }}
                        />
                    </Card>
                </Col>
            </Row>

            {/* Table */}
            <Table
                columns={columns}
                dataSource={trackingRecords}
                rowKey="id"
                loading={loading}
                pagination={{
                    pageSize: 10,
                    showTotal: (total) => `Total ${total} records`,
                }}
                locale={{
                    emptyText: 'No equipment tracking records found for this date',
                }}
            />

            {/* Check-In Modal */}
            <Modal
                title="Check In Equipment (Return)"
                open={showCheckInModal}
                onCancel={() => { setShowCheckInModal(false); checkInForm.resetFields(); }}
                footer={null}
                width={500}
            >
                <Form form={checkInForm} layout="vertical" onFinish={handleCheckIn}>
                    <Form.Item name="equipment_name" label="Equipment">
                        <Input disabled />
                    </Form.Item>
                    <Form.Item name="quantity" label="Quantity Out">
                        <InputNumber disabled style={{ width: '100%' }} />
                    </Form.Item>
                    <Form.Item name="condition_notes_in" label="Condition (In)">
                        <Select placeholder="Select condition">
                            <Option value="Excellent">Excellent</Option>
                            <Option value="Good">Good</Option>
                            <Option value="Fair">Fair</Option>
                            <Option value="Poor">Poor</Option>
                        </Select>
                    </Form.Item>
                    <Form.Item name="quantity_used" label="Quantity Used">
                        <InputNumber min={0} style={{ width: '100%' }} placeholder="0" />
                    </Form.Item>
                    <Form.Item name="quantity_damaged" label="Quantity Damaged">
                        <InputNumber min={0} style={{ width: '100%' }} placeholder="0" />
                    </Form.Item>
                    <Form.Item name="quantity_missing" label="Quantity Missing">
                        <InputNumber min={0} style={{ width: '100%' }} placeholder="0" />
                    </Form.Item>
                    <Form.Item name="notes" label="Return Notes">
                        <TextArea rows={3} placeholder="Additional notes..." />
                    </Form.Item>
                    <Form.Item style={{ textAlign: 'right' }}>
                        <Space>
                            <Button onClick={() => { setShowCheckInModal(false); checkInForm.resetFields(); }}>Cancel</Button>
                            <Button type="primary" htmlType="submit">Check In</Button>
                        </Space>
                    </Form.Item>
                </Form>
            </Modal>

            {/* Edit Modal */}
            <Modal
                title="Edit Tracking Record"
                open={showEditModal}
                onCancel={() => { setShowEditModal(false); editForm.resetFields(); }}
                footer={null}
                width={400}
            >
                <Form form={editForm} layout="vertical" onFinish={handleEdit}>
                    <Form.Item label="Equipment" name="equipment_name">
                        <Input value={selectedRecord?.equipment?.name} disabled />
                    </Form.Item>
                    <Form.Item name="quantity" label="Quantity">
                        <InputNumber min={1} style={{ width: '100%' }} placeholder="Enter quantity" />
                    </Form.Item>
                    <Form.Item name="return_date" label="Expected Return Date">
                        <DatePicker style={{ width: '100%' }} />
                    </Form.Item>
                    <Form.Item style={{ textAlign: 'right' }}>
                        <Space>
                            <Button onClick={() => { setShowEditModal(false); editForm.resetFields(); }}>Cancel</Button>
                            <Button type="primary" htmlType="submit">Update</Button>
                        </Space>
                    </Form.Item>
                </Form>
            </Modal>
        </div>
    );
};

export default EquipmentInOut;