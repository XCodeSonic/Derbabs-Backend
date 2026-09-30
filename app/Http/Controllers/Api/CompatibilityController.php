<?php

namespace App\Http\Controllers\Api;

use App\Models\AttendanceLog;
use App\Models\Booking;
use App\Models\BookingEquipment;
use App\Models\BookingPayment;
use App\Models\Department;
use App\Models\Employee;
use App\Models\InventoryMovement;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\Package;
use App\Models\Payroll;
use App\Models\Position;
use App\Models\PurchaseRequest;
use App\Models\Quotation;
use App\Models\SalaryGrade;
use App\Models\Setting;
use App\Services\EventService;
use App\Services\InventoryService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Compatibility aliases retained for existing frontend URLs.
 * Persistent operations delegate to their real controller/service instead of returning fake success payloads.
 */
class CompatibilityController extends Controller
{
    public function employeeAttendance(Employee $employee) { return $this->ok($employee->attendanceLogs()->with('schedule')->latest()->get()); }
    public function employeeLeaves(Employee $employee) { return $this->ok($employee->leaveRequests()->latest()->get()); }
    public function employeePayroll(Employee $employee) { return $this->ok($employee->payrolls()->with('items')->latest()->get()); }

    public function employeeBookmark(Employee $employee)
    {
        $key = 'user_' . (auth()->id() ?? 0) . '_employee_' . $employee->employee_id;
        $row = Setting::where('group', 'employee_bookmarks')->where('key', $key)->first();
        if ($row) {
            $row->delete();
            return $this->ok(['employee_id' => $employee->employee_id, 'bookmarked' => false], 'Employee bookmark removed.');
        }
        Setting::create(['group' => 'employee_bookmarks', 'key' => $key, 'value' => 'true', 'type' => 'boolean']);
        return $this->ok(['employee_id' => $employee->employee_id, 'bookmarked' => true], 'Employee bookmarked.');
    }

    public function employeeStatus(Request $request, Employee $employee)
    {
        $data = $request->validate(['status' => ['required', Rule::in(['active', 'on_leave', 'inactive', 'terminated'])]]);
        $employee->update($data);
        return $this->ok($employee, 'Employee status updated.');
    }

    public function employeeForceDelete(int $id) { Employee::withTrashed()->findOrFail($id)->forceDelete(); return $this->ok(null, 'Employee permanently deleted.'); }
    public function positionsBySalaryGrade(int $salaryGradeId) { return $this->ok(Position::with(['department', 'salaryGrade'])->where('salary_grade_id', $salaryGradeId)->get()); }
    public function toggleAvailability(MenuItem $menuItem) { $menuItem->update(['is_available' => ! $menuItem->is_available]); return $this->ok($menuItem, 'Availability updated.'); }
    public function toggleFeatured(MenuItem $menuItem) { $menuItem->update(['is_popular' => ! $menuItem->is_popular]); return $this->ok($menuItem, 'Featured status updated.'); }
    public function packages(Request $request) { return $this->ok(Package::with('items.menuItem')->latest('package_id')->paginate($request->integer('per_page', 20))); }
    public function package(Package $package) { return $this->ok($package->load('items.menuItem')); }
    public function quotationReject(Quotation $quotation) { $quotation->update(['status' => 'rejected']); return $this->ok($quotation, 'Quotation rejected.'); }
    public function quotationSend(Quotation $quotation) { $quotation->update(['status' => 'pending']); return $this->ok($quotation, 'Quotation marked as sent.'); }

    public function daily(Request $request) { return app(AttendanceController::class)->index($request); }
    public function dailyPending(Request $request) { return app(AttendanceController::class)->needsApproval($request); }
    public function dailySummary() { return app(AttendanceController::class)->summary(); }
    public function dailyApprove(Request $request, AttendanceLog $attendance) { return app(AttendanceController::class)->approve($request, $attendance); }
    public function dailyDecline(Request $request, AttendanceLog $attendance) { $request->merge(['approval_status' => 'rejected']); return app(AttendanceController::class)->updateStatus($request, $attendance); }
    public function dailyUnapprove(AttendanceLog $attendance) { return app(AttendanceController::class)->unverify($attendance); }
    public function dailyUndecline(AttendanceLog $attendance) { return app(AttendanceController::class)->unverify($attendance); }
    public function dailyApproveOvertime(Request $request, AttendanceLog $attendance) { return app(AttendanceController::class)->approveOvertime($request, $attendance); }
    public function dailyRejectOvertime(Request $request, AttendanceLog $attendance) { return app(AttendanceController::class)->rejectOvertime($request, $attendance); }

    public function dailyBulkApprove(Request $request)
    {
        $ids = $request->validate(['record_ids' => ['required', 'array'], 'record_ids.*' => ['integer']])['record_ids'];
        AttendanceLog::whereIn('attendance_id', $ids)->update(['approval_status' => 'approved', 'approved_by' => auth()->id(), 'approved_at' => now()]);
        return $this->ok(null, 'Attendance records approved.');
    }

    public function payslips(Request $request) { return $this->ok(Payroll::with(['employee.person', 'employee.department', 'items'])->latest('payroll_id')->paginate($request->integer('per_page', 20))); }
    public function payslip(Payroll $payroll) { return app(PayrollController::class)->payslip($payroll); }
    public function payslipGenerate(Request $request) { return app(PayrollController::class)->process($request, app(\App\Services\PayrollService::class)); }

    public function payslipBulkGenerate(Request $request)
    {
        $ids = $request->validate(['payroll_ids' => ['required', 'array', 'min:1'], 'payroll_ids.*' => ['integer']])['payroll_ids'];
        return $this->ok(Payroll::whereIn('payroll_id', $ids)->get(), 'Selected payslips loaded. Use the payroll process endpoint to generate new payroll records.');
    }

    public function payrollSummary(Request $request) { return app(PayrollController::class)->stats($request); }

    public function payrollBulkDeductions(Request $request)
    {
        $controller = app(PayrollController::class);
        if (method_exists($controller, 'bulkDeductions')) {
            return $controller->bulkDeductions($request);
        }
        return response()->json([
            'success' => false,
            'message' => 'Bulk payroll deductions require the payroll deduction schema and PayrollController::bulkDeductions implementation. They were not included in the supplied files, so this endpoint will not pretend to save data.',
        ], 422);
    }

    public function payrollExport()
    {
        $stream = fopen('php://temp', 'r+');
        fputcsv($stream, ['payroll_number', 'employee', 'status', 'payment_date']);
        foreach (Payroll::with('employee.person')->get() as $row) {
            fputcsv($stream, [$row->payroll_number, $row->employee?->person?->full_name ?? '', $row->status, $row->payment_date]);
        }
        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);
        return response($csv, 200, ['Content-Type' => 'text/csv', 'Content-Disposition' => 'attachment; filename="payroll.csv"']);
    }

    public function removeKitchen(Order $order) { return app(OrderController::class)->removeFromKitchen($order); }
    public function removeDelivery(Order $order) { return app(OrderController::class)->removeFromDelivery($order); }
    public function updateKitchenTask(Request $request, Order $order) { return app(OrderController::class)->updateKitchenTask($request, $order); }
    public function updateDeliveryItem(Request $request, Order $order) { return app(OrderController::class)->updateDeliveryItem($request, $order); }

    public function shoppingList(Request $request) { return $this->ok(PurchaseRequest::with(['ingredient', 'supplier'])->latest('purchase_request_id')->paginate($request->integer('per_page', 20))); }

    public function shoppingAdd(Request $request, Order $order)
    {
        $data = $request->validate([
            'ingredient_id' => ['nullable', 'exists:ingredients,ingredient_id'],
            'quantity' => ['nullable', 'numeric', 'min:0.001'],
            'notes' => ['nullable', 'string'],
            'ingredients' => ['nullable', 'array'],
            'ingredients.*.ingredient_id' => ['required_with:ingredients', 'exists:ingredients,ingredient_id'],
            'ingredients.*.shortage' => ['nullable', 'numeric', 'min:0.001'],
            'ingredients.*.quantity' => ['nullable', 'numeric', 'min:0.001'],
            'ingredients.*.notes' => ['nullable', 'string'],
        ]);

        $items = $data['ingredients'] ?? [[
            'ingredient_id' => $data['ingredient_id'] ?? null,
            'quantity' => $data['quantity'] ?? null,
            'notes' => $data['notes'] ?? null,
        ]];
        if (($items[0]['ingredient_id'] ?? null) === null) {
            return response()->json(['success' => false, 'message' => 'Provide at least one ingredient.'], 422);
        }

        $rows = collect($items)->map(function (array $item) use ($order) {
            $quantity = (float) ($item['quantity'] ?? $item['shortage'] ?? 0);
            if ($quantity <= 0) {
                throw \Illuminate\Validation\ValidationException::withMessages(['quantity' => 'Shopping-list quantity must be greater than zero.']);
            }
            return PurchaseRequest::create([
                'pr_number' => 'SHOP-' . now()->format('YmdHisv') . '-' . random_int(100, 999),
                'ingredient_id' => $item['ingredient_id'],
                'quantity' => $quantity,
                'status' => 'pending',
                'urgency' => 'normal',
                'notes' => trim('Order ' . $order->order_number . '. ' . ($item['notes'] ?? '')),
                'requested_by' => auth()->id(),
            ]);
        })->values();

        return $this->ok($rows, 'Shopping-list items added.');
    }

    public function shoppingPurchased(PurchaseRequest $item, InventoryService $service)
    {
        return DB::transaction(function () use ($item, $service) {
            $item = PurchaseRequest::whereKey($item->getKey())->lockForUpdate()->firstOrFail();
            if ($item->status !== 'received') {
                $service->move((int) $item->ingredient_id, (float) $item->quantity, 'purchase', 'Received purchase request ' . $item->pr_number);
                $item->update(['status' => 'received']);
            }
            return $this->ok($item->fresh(), 'Shopping item marked purchased and inventory restocked.');
        });
    }

    public function shoppingDelete(PurchaseRequest $item) { $item->delete(); return $this->ok(null, 'Shopping item removed.'); }

    public function shoppingBulk(Request $request, InventoryService $service)
    {
        $ids = $request->validate(['item_ids' => ['required', 'array'], 'item_ids.*' => ['integer']])['item_ids'];
        foreach (PurchaseRequest::whereIn('purchase_request_id', $ids)->get() as $item) {
            $this->shoppingPurchased($item, $service);
        }
        return $this->ok(null, 'Shopping items marked purchased and inventory restocked.');
    }

    public function shoppingCount() { return $this->ok(['count' => PurchaseRequest::where('status', 'pending')->count()]); }
    public function inventoryRecent() { return $this->ok(InventoryMovement::with('ingredient')->latest('movement_id')->limit(50)->get()); }
    public function inventorySummary() { return $this->ok(InventoryMovement::selectRaw('movement_type, COUNT(*) total, SUM(quantity_change) quantity')->groupBy('movement_type')->get()); }
    public function inventoryType(string $type) { return $this->ok(InventoryMovement::with('ingredient')->where('movement_type', $type === 'deduction' ? 'usage' : $type)->latest('movement_id')->get()); }

    public function inventoryRange(Request $request)
    {
        $query = InventoryMovement::with('ingredient');
        if ($request->filled('from')) $query->whereDate('created_at', '>=', $request->input('from'));
        if ($request->filled('to')) $query->whereDate('created_at', '<=', $request->input('to'));
        return $this->ok($query->latest('movement_id')->get());
    }

    public function bookingEvent(Booking $booking) { return $this->ok($booking->load(['serviceEvent.customer.person', 'equipment.equipment', 'tracking'])); }
    public function receipt(BookingPayment $payment) { return $this->ok(['payment' => $payment->load('booking.serviceEvent.customer.person'), 'receipt_file' => $payment->receipt_file]); }
    public function employeesAll() { return $this->ok(Employee::with(['person', 'department', 'position.salaryGrade'])->latest('employee_id')->get()); }
    public function employeesBirthdays() { return $this->ok(Employee::with('person')->get()->filter(fn ($employee) => $employee->person?->birth_date)->values()); }
    public function employeesOnLeave() { return $this->ok(Employee::with(['person', 'department', 'position'])->where('status', 'on_leave')->get()); }

    public function employeesSearch(Request $request)
    {
        $search = (string) $request->input('q', '');
        return $this->ok(Employee::with(['person', 'department', 'position'])->whereHas('person', fn ($query) => $query->where('first_name', 'like', "%{$search}%")->orWhere('last_name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%"))->get());
    }

    public function employeesBulkArchive(Request $request) { $ids = $request->input('ids', $request->input('employee_ids', [])); Employee::whereIn('employee_id', $ids)->delete(); return $this->ok(null, 'Employees archived.'); }
    public function employeesBulkDelete(Request $request) { $ids = $request->input('ids', $request->input('employee_ids', [])); Employee::withTrashed()->whereIn('employee_id', $ids)->forceDelete(); return $this->ok(null, 'Employees permanently deleted.'); }
    public function departmentStats() { return $this->ok(['total_departments' => Department::count(), 'active_departments' => Department::count()]); }
    public function departmentsWithEmployees() { return $this->ok(Department::with('employees.person')->get()); }
    public function positionStats() { return $this->ok(['total_positions' => Position::count()]); }
    public function salaryGradeStats() { return $this->ok(['total_salary_grades' => SalaryGrade::count()]); }
    public function menuStatistics() { return $this->ok(['total_menus' => MenuItem::count(), 'active_menus' => MenuItem::where('is_available', true)->count(), 'featured_menus' => MenuItem::where('is_popular', true)->count(), 'total_packages' => Package::count(), 'total_promotions' => \App\Models\Promotion::count()]); }
    public function inventoryDashboardSummary() { return app(InventoryController::class)->dashboard(); }
    public function equipmentReservationStore(Request $request) { return app(InventoryController::class)->storeReservation($request, app(\App\Services\EquipmentService::class)); }
    public function paymentRefund(Request $request, BookingPayment $payment) { return app(PaymentController::class)->refund($request, $payment); }
    public function paymentSummary(Request $request) { return app(PaymentController::class)->summary($request); }
    public function eventSessions(int $id) { return app(EventController::class)->getSessions($id, app(EventService::class)); }
    public function eventSessionStore(Request $request, int $id) { return app(EventController::class)->addSession($request, $id, app(EventService::class)); }
    public function eventSessionUpdate(Request $request, int $id, string $sessionId) { return app(EventController::class)->updateSession($request, $id, $sessionId, app(EventService::class)); }
    public function eventSessionDelete(int $id, string $sessionId) { return app(EventController::class)->deleteSession($id, $sessionId, app(EventService::class)); }
    public function eventChecklistStore(Request $request, int $id) { return app(EventController::class)->addChecklistItem($request, $id, app(EventService::class)); }
    public function eventChecklistDelete(int $id, string $itemId) { return app(EventController::class)->deleteChecklistItem($id, $itemId, app(EventService::class)); }
    public function eventStaffDelete(int $id, int $staffId) { return app(EventController::class)->removeStaff($id, $staffId, app(EventService::class)); }
}
