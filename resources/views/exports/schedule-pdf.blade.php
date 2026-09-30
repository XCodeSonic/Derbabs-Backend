// ==================== EXPORT FUNCTIONS ====================

const handleExportExcel = async () => {
try {
setLoading(true);

// Get current week dates
const weekStart = weekDays[0]?.format('YYYY-MM-DD');

const response = await scheduleAPI.exportExcel({
week_start: weekStart,
department_id: selectedDepartment !== 'all' ? selectedDepartment : null
});

// Create download link
const blob = new Blob([response.data], {
type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
});
const url = window.URL.createObjectURL(blob);
const link = document.createElement('a');
link.href = url;
link.setAttribute('download', `staff_schedule_${moment().format('YYYY-MM-DD')}.xlsx`);
document.body.appendChild(link);
link.click();
document.body.removeChild(link);
window.URL.revokeObjectURL(url);

showNotification('Exported to Excel successfully', 'success');
} catch (error) {
console.error('Export Excel error:', error);
showNotification('Failed to export Excel', 'error');
} finally {
setLoading(false);
}
};

const handleExportPdf = async () => {
try {
setLoading(true);

// Get current week dates
const weekStart = weekDays[0]?.format('YYYY-MM-DD');

const response = await scheduleAPI.exportPdf({
week_start: weekStart,
department_id: selectedDepartment !== 'all' ? selectedDepartment : null
});

// Create download link
const blob = new Blob([response.data], { type: 'application/pdf' });
const url = window.URL.createObjectURL(blob);
const link = document.createElement('a');
link.href = url;
link.setAttribute('download', `staff_schedule_${moment().format('YYYY-MM-DD')}.pdf`);
document.body.appendChild(link);
link.click();
document.body.removeChild(link);
window.URL.revokeObjectURL(url);

showNotification('Exported to PDF successfully', 'success');
} catch (error) {
console.error('Export PDF error:', error);
showNotification('Failed to export PDF', 'error');
} finally {
setLoading(false);
}
};

// Replace the old exportToExcel and exportToPDF in your dropdown with these:
const renderActionBar = () => {
return (
<div className="sdf-action-bar">
    <div className="sdf-action-left">
        <Space>
            <Badge count={shifts.length} showZero color="#1890ff">
                <Button icon={<ScheduleOutlined />}>Shifts</Button>
            </Badge>
            <Badge count={new Set(shifts.map(s=> s.employee_id || s.employeeId)).size} showZero color="#52c41a">
                <Button icon={<TeamOutlined />}>Staff</Button>
            </Badge>
            <Badge count={laborCosts.totalHours} showZero color="#faad14">
                <Button icon={<ClockCircleOutlined />}>Hours</Button>
            </Badge>
        </Space>
    </div>
    <div className="sdf-action-right">
        <Space>
            <Dropdown
                overlay={
                <Menu>
                <Menu.Item key="excel" icon={<FileExcelOutlined />} onClick={handleExportExcel}>
                Export as Excel
                </Menu.Item>
                <Menu.Item key="pdf" icon={<FilePdfOutlined />} onClick={handleExportPdf}>
                Export as PDF
                </Menu.Item>
                </Menu>
                }
                placement="bottomRight"
                >
                <Button icon={<ExportOutlined />}>
                Export
                <DownOutlined />
                </Button>
            </Dropdown>
            <Button
                type="primary"
                icon={<PlusOutlined />}
            onClick={() => {
            resetForm();
            setModalMode('add');
            setShowModal(true);
            }}
            >
            Create Shift
            </Button>
        </Space>
    </div>
</div>
);
};