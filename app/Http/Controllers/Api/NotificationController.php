<?php

namespace App\Http\Controllers\Api;

use App\Models\Notification;
use App\Services\NotificationService;
use App\Support\NotificationCatalog;
use App\Models\AuditLog;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    protected $notificationService;

    public function __construct(NotificationService $notificationService)
    {
        $this->notificationService = $notificationService;
    }

    /**
     * Backend catalog used by the system to know valid notification events,
     * priorities, categories and destinations. This is function metadata, not UI text.
     */
    public function catalog()
    {
        return response()->json([
            'success' => true,
            'data' => NotificationCatalog::all(),
        ]);
    }

    /**
     * Get notifications for authenticated user with filtering
     */
    public function index(Request $request)
    {
        $query = Notification::where('user_id', auth()->id());

        // Apply filters
        if ($request->boolean('unread')) {
            $query->whereNull('read_at');
        }

        if ($request->boolean('starred')) {
            $query->where('starred', true);
        }

        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }

        if ($request->filled('priority')) {
            $query->where('priority', $request->priority);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                    ->orWhere('message', 'like', "%{$search}%");
            });
        }

        // Pagination
        $perPage = $request->integer('per_page', 30);
        $notifications = $query->latest('notification_id')->paginate($perPage);
        $notifications->setCollection(
            $notifications->getCollection()->map(fn ($notification) => NotificationCatalog::decorate($notification))
        );

        // Get unread count
        $unreadCount = Notification::where('user_id', auth()->id())
            ->whereNull('read_at')
            ->count();

        return response()->json([
            'success' => true,
            'data' => $notifications,
            'unread_count' => $unreadCount,
        ]);
    }

    /**
     * Get unread count for authenticated user
     */
    public function getUnreadCount()
    {
        try {
            $count = Notification::where('user_id', auth()->id())
                ->whereNull('read_at')
                ->count();

            return response()->json([
                'success' => true,
                'data' => ['count' => $count]
            ]);
        } catch (\Exception $e) {
            \Log::error('Error getting unread count: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to get unread count',
                'data' => ['count' => 0]
            ], 500);
        }
    }

    /**
     * Get starred notifications
     */
    public function getStarred()
    {
        $notifications = Notification::where('user_id', auth()->id())
            ->where('starred', true)
            ->latest()
            ->paginate(30);

        return response()->json([
            'success' => true,
            'data' => $notifications
        ]);
    }

    /**
     * Get notifications by type
     */
    public function getByType($type)
    {
        $notifications = Notification::where('user_id', auth()->id())
            ->where('type', $type)
            ->latest()
            ->paginate(30);

        return response()->json([
            'success' => true,
            'data' => $notifications
        ]);
    }

    /**
     * Get notifications by priority
     */
    public function getByPriority($priority)
    {
        $notifications = Notification::where('user_id', auth()->id())
            ->where('priority', $priority)
            ->latest()
            ->paginate(30);

        return response()->json([
            'success' => true,
            'data' => $notifications
        ]);
    }

    /**
     * Get single notification
     */
    public function show(Notification $notification)
    {
        if ($notification->user_id !== auth()->id()) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized'
            ], 403);
        }

        return response()->json([
            'success' => true,
            'data' => NotificationCatalog::decorate($notification)
        ]);
    }

    /**
     * Create notification (admin/system only)
     */
    public function store(Request $request)
    {
        $isSuperAdmin = $request->user()?->roles()
            ->where('is_active', true)
            ->whereIn('slug', ['super-admin', 'super_admin', 'superadmin'])
            ->exists() ?? false;

        if (! $isSuperAdmin) {
            return response()->json([
                'success' => false,
                'message' => 'Forbidden. Only the Super Admin may create system-wide notifications.',
            ], 403);
        }

        $data = $request->validate([
            'user_id' => 'required|exists:users,user_id',
            'type' => 'required|string|max:50',
            'title' => 'required|string',
            'message' => 'required|string',
            'priority' => 'nullable|in:low,medium,high,critical',
            'data' => 'nullable|array',
            'action_url' => 'nullable|string|max:255',
        ]);

        $definition = NotificationCatalog::definition($data['type']);
        $data['title'] = $data['title'] ?: ($definition['title'] ?? '📢 System Notification');
        $data['priority'] = $data['priority'] ?? ($definition['priority'] ?? 'medium');
        $data['action_url'] = NotificationCatalog::destination($data['type'], $data['data'] ?? [], $data['action_url'] ?? null);
        $data['is_sent'] = true;
        $data['sent_at'] = now();

        $notification = Notification::create($data);
        AuditLog::log('notification_sent', 'notifications', $notification->notification_id, null, [
            'type' => $notification->type,
            'title' => $notification->title,
            'user_id' => $notification->user_id,
            'destination' => $notification->action_url,
        ]);

        // Broadcast via WebSocket if configured
        // event(new NewNotificationEvent($notification));

        return response()->json([
            'success' => true,
            'data' => $notification,
            'message' => 'Notification created'
        ]);
    }

    /**
     * Update notification
     */
    public function update(Request $request, Notification $notification)
    {
        if ($notification->user_id !== auth()->id()) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized'
            ], 403);
        }

        $notification->update($request->only(['read_at']));

        return response()->json([
            'success' => true,
            'data' => $notification,
            'message' => 'Notification updated'
        ]);
    }

    /**
     * Delete notification
     */
    public function destroy(Notification $notification)
    {
        if ($notification->user_id !== auth()->id()) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized'
            ], 403);
        }

        AuditLog::log('notification_deleted', 'notifications', $notification->notification_id, null, [
            'type' => $notification->type,
            'title' => $notification->title,
        ]);
        $notification->delete();

        return response()->json([
            'success' => true,
            'message' => 'Notification deleted'
        ]);
    }

    /**
     * Mark notification as read
     */
    public function markRead(Notification $notification)
    {
        if ($notification->user_id !== auth()->id()) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized'
            ], 403);
        }

        $notification->markAsRead();
        AuditLog::log('notification_read', 'notifications', $notification->notification_id, null, [
            'type' => $notification->type,
            'title' => $notification->title,
        ]);

        return response()->json([
            'success' => true,
            'data' => $notification,
            'message' => 'Marked as read'
        ]);
    }

    /**
     * Mark notification as unread
     */
    public function markUnread(Notification $notification)
    {
        if ($notification->user_id !== auth()->id()) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized'
            ], 403);
        }

        $notification->markAsUnread();

        return response()->json([
            'success' => true,
            'data' => $notification,
            'message' => 'Marked as unread'
        ]);
    }

    /**
     * Mark all notifications as read for authenticated user
     */
    public function markAllRead()
    {
        Notification::where('user_id', auth()->id())
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return response()->json([
            'success' => true,
            'message' => 'All notifications marked as read'
        ]);
    }

    /**
     * Toggle star status
     */
    public function toggleStar(Notification $notification)
    {
        if ($notification->user_id !== auth()->id()) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized'
            ], 403);
        }

        $notification->toggleStar();

        return response()->json([
            'success' => true,
            'data' => $notification,
            'message' => $notification->starred ? 'Starred' : 'Unstarred'
        ]);
    }

    /**
     * Clear all notifications for authenticated user
     */
    public function clearAll()
    {
        Notification::where('user_id', auth()->id())->delete();

        return response()->json([
            'success' => true,
            'message' => 'All notifications cleared'
        ]);
    }

    /**
     * Delete multiple notifications
     */
    public function deleteMultiple(Request $request)
    {
        $data = $request->validate([
            'notification_ids' => 'required|array',
            'notification_ids.*' => 'exists:notifications,notification_id',
        ]);

        Notification::where('user_id', auth()->id())
            ->whereIn('notification_id', $data['notification_ids'])
            ->delete();

        return response()->json([
            'success' => true,
            'message' => 'Notifications deleted'
        ]);
    }
}