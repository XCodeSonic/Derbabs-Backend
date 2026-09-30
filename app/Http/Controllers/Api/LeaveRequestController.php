<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\LeaveRequestForm;
use App\Models\LeaveRequest;
use App\Services\NotificationService;
use Illuminate\Http\Request;

class LeaveRequestController extends Controller
{
    protected $notificationService;

    public function __construct(NotificationService $notificationService)
    {
        $this->notificationService = $notificationService;
    }

    public function index(Request $request)
    {
        $query = LeaveRequest::with('employee.person');
        if ($request->status) $query->where('status', $request->status);
        if ($request->employee_id) $query->where('employee_id', $request->employee_id);
        return $this->ok($query->latest('leave_request_id')->paginate($request->integer('per_page', 20)));
    }


    public function stats($employeeId)
    {
        $query = LeaveRequest::where('employee_id', $employeeId);

        return $this->ok([
            'total' => (clone $query)->count(),
            'pending' => (clone $query)->where('status', 'pending')->count(),
            'approved' => (clone $query)->where('status', 'approved')->count(),
            'rejected' => (clone $query)->where('status', 'rejected')->count(),
            'cancelled' => (clone $query)->where('status', 'cancelled')->count(),
        ]);
    }

    public function show(LeaveRequest $leaveRequest)
    {
        return $this->ok($leaveRequest->load('employee.person'));
    }

    public function store(LeaveRequestForm $request)
    {
        $validated = $request->validated();
        $employee = $request->user()?->employee;
        $employeeId = $employee?->employee_id ?: ($validated['employee_id'] ?? null);

        if (! $employeeId) {
            return response()->json([
                'success' => false,
                'message' => 'Employee account not found for this user.',
                'errors' => ['employee_id' => ['Employee account not found for this user.']],
            ], 422);
        }

        unset($validated['employee_id']);
        $leaveRequest = LeaveRequest::create(array_merge($validated, ['employee_id' => $employeeId]));

        // ✅ Notification based on request type
        if ($leaveRequest->request_type === 'sick_leave') {
            $this->notificationService->sickLeaveSubmitted($leaveRequest);
        } elseif ($leaveRequest->request_type === 'day_off') {
            $this->notificationService->dayOffRequestSubmitted($leaveRequest);
        } else {
            $this->notificationService->leaveRequestSubmitted($leaveRequest);
        }

        return $this->ok($leaveRequest, 'Request submitted');
    }

    public function update(Request $request, LeaveRequest $leaveRequest)
    {
        $leaveRequest->update($request->only(['request_type', 'start_date', 'end_date', 'reason']));
        return $this->ok($leaveRequest, 'Request updated');
    }

    public function status(Request $request, LeaveRequest $leaveRequest)
    {
        $data = $request->validate([
            'status' => 'required|in:approved,rejected,pending,cancelled',
            'admin_notes' => 'nullable|string'
        ]);

        $leaveRequest->update($data + [
            'approved_by' => in_array($data['status'], ['approved', 'rejected']) ? auth()->id() : null,
            'approved_at' => in_array($data['status'], ['approved', 'rejected']) ? now() : null,
        ]);

        // ✅ Notification: Leave request status update to employee
        $employee = $leaveRequest->employee;
        if ($employee && $employee->user_id && in_array($data['status'], ['approved', 'rejected'])) {
            $statusText = $data['status'] === 'approved' ? 'approved' : 'rejected';
            $this->notificationService->notifyUser(
                $employee->user_id,
                'leave_request_' . $data['status'],
                "Leave Request {$statusText}",
                "Your leave request from {$leaveRequest->start_date->format('M d')} to {$leaveRequest->end_date->format('M d, Y')} has been {$statusText}." . ($data['admin_notes'] ? " Note: {$data['admin_notes']}" : ''),
                \App\Models\Notification::PRIORITY_MEDIUM,
                ['leave_request_id' => $leaveRequest->leave_request_id]
            );
        }

        return $this->ok($leaveRequest, 'Request status updated');
    }

    public function approve(Request $request, LeaveRequest $leaveRequest)
    {
        $request->merge(['status' => 'approved']);
        return $this->status($request, $leaveRequest);
    }

    public function reject(Request $request, LeaveRequest $leaveRequest)
    {
        $request->merge(['status' => 'rejected']);
        return $this->status($request, $leaveRequest);
    }

    public function cancel(Request $request, LeaveRequest $leaveRequest)
    {
        $leaveRequest->update(['status' => 'cancelled', 'admin_notes' => $request->input('reason')]);
        return $this->ok($leaveRequest, 'Request cancelled');
    }

    public function destroy(LeaveRequest $leaveRequest)
    {
        $leaveRequest->delete();
        return $this->ok(null, 'Request deleted');
    }
}
