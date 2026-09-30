<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\Auth\AuthController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\EmployeeController;
use App\Http\Controllers\Api\DepartmentController;
use App\Http\Controllers\Api\PositionController;
use App\Http\Controllers\Api\SalaryGradeController;
use App\Http\Controllers\Api\LeaveRequestController;
use App\Http\Controllers\Api\ScheduleController;
use App\Http\Controllers\Api\AttendanceController;
use App\Http\Controllers\Api\PayrollController;
use App\Http\Controllers\Api\BookingController;
use App\Http\Controllers\Api\QuotationController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\MenuItemController;
use App\Http\Controllers\Api\MealCategoryController;
use App\Http\Controllers\Api\PackageController;
use App\Http\Controllers\Api\IngredientController;
use App\Http\Controllers\Api\EquipmentController;
use App\Http\Controllers\Api\SupplierController;
use App\Http\Controllers\Api\PromotionController;
use App\Http\Controllers\Api\ReviewController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\SettingController;
use App\Http\Controllers\Api\EventTypeController;
use App\Http\Controllers\Api\DeliveryZoneController;
use App\Http\Controllers\Api\InventoryController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\EventController;
use App\Http\Controllers\Api\RecipeController;
use App\Http\Controllers\Api\CompatibilityController;
use App\Http\Controllers\Api\InvoiceController;
use App\Http\Controllers\Api\FinancialReportController;
use App\Http\Controllers\Api\ShiftTypeController;
use App\Http\Controllers\Api\DepositController;
use App\Http\Controllers\Api\CartController;
use App\Http\Controllers\Api\ProfitabilityController;
use App\Http\Controllers\Api\RefundController;
use App\Http\Controllers\Api\SystemAnnouncementController;
use App\Http\Controllers\Api\Auth\SocialAuthController;


Route::prefix('v1')->group(function () {

    Route::get('/health', fn() => response()->json([
        'success' => true,
        'status' => 'online',
        'message' => 'API server is reachable',
        'server_time' => now()->toDateTimeString(),
    ]));

    Route::get('/ping', fn() => response()->json([
        'success' => true,
        'message' => 'pong',
        'server_time' => now()->toDateTimeString(),
    ]));

    // ============================================================
    // PUBLIC ROUTES (NO AUTH REQUIRED)
    // ============================================================

    Route::post('/auth/login', [AuthController::class, 'login']);
    Route::post('/auth/admin-login', [AuthController::class, 'adminLogin']);
    Route::post('/auth/employee-login', [AuthController::class, 'employeeLogin']);
    Route::post('/auth/mobile-employee-login', [AuthController::class, 'employeeLogin']);
    Route::post('/auth/forgot-password', [AuthController::class, 'forgotPassword']);
    Route::post('/auth/verify-otp', [AuthController::class, 'verifyResetOtp']);
    Route::post('/auth/resend-otp', [AuthController::class, 'resendResetOtp']);
    Route::post('/auth/reset-password', [AuthController::class, 'resetPassword']);
    Route::post('/auth/request-registration-otp', [AuthController::class, 'requestRegistrationOtp']);
    Route::post('/auth/verify-registration-otp', [AuthController::class, 'verifyRegistrationOtp']);
    Route::post('/auth/register-with-otp', [AuthController::class, 'registerCustomerWithOtp']);
    Route::post('/auth/request-email-otp', [AuthController::class, 'requestRegistrationOtp']);

    Route::post('/register', [AuthController::class, 'registerCustomer']);
    Route::post('/customers/register', [AuthController::class, 'registerCustomer']);

    Route::get('/public/menu-items', [MenuItemController::class, 'index']);
    Route::get('/public/packages', [PackageController::class, 'index']);
    Route::get('/public/promotions', [PromotionController::class, 'index']);
    Route::get('/public/meal-categories', [MealCategoryController::class, 'index']);

    // ⭐ Master allergen list — PUBLIC so the mobile profile screen can
    //    display it before login. Mobile fetches this on every cold start.
    Route::get('/public/allergens', [SettingController::class, 'getAllergens']);


    // Public delivery zones — used by the customer booking form to look up the
    // delivery fee for a selected location. Returns only active zones.
    Route::get('/public/delivery-zones', function () {
        return response()->json([
            'success' => true,
            'data' => \App\Models\DeliveryZone::where('is_active', true)
                ->orderBy('name')
                ->get(['delivery_zone_id', 'name', 'delivery_fee', 'description']),
        ]);
    });

    // ============================================================
    // AUTHENTICATED ROUTES
    // ============================================================
    Route::middleware(['auth:sanctum', 'role.access'])->group(function () {
        // ==================== AUTH & PROFILE ====================
        Route::get('/auth/user', [AuthController::class, 'user']);
        Route::get('/auth/profile', [AuthController::class, 'profile']);
        Route::match(['put', 'post'], '/auth/profile', [AuthController::class, 'updateProfile']);
        Route::put('/auth/change-password', [AuthController::class, 'changePassword']);
        Route::post('/auth/profile-photo', [AuthController::class, 'updateProfilePhoto']);
        Route::delete('/auth/profile-photo', [AuthController::class, 'removeProfilePhoto']);
        Route::post('/auth/logout', [AuthController::class, 'logout']);
        Route::post('/auth/login-status', [AuthController::class, 'loginStatus']);

        // ⭐ Self-profile update — used by the mobile app so employees can
        //    update their own Person fields (name, email, phone, address, etc.)
        //    without admin privileges.
        Route::match(['put', 'post'], '/auth/self-profile', [AuthController::class, 'updateSelfProfile']);

        // ⭐ Customer food allergies — one source of truth (food_allergens Setting group).
        Route::get('/auth/me/allergies', [SettingController::class, 'getMyAllergies']);
        Route::put('/auth/me/allergies', [SettingController::class, 'updateMyAllergies']);

        // ==================== CART ====================
        Route::get('/cart', [CartController::class, 'show']);
        Route::post('/cart/items', [CartController::class, 'store']);
        Route::put('/cart/items/{cartItem}', [CartController::class, 'update']);
        Route::delete('/cart/items/{cartItem}', [CartController::class, 'destroy']);
        Route::delete('/cart', [CartController::class, 'clear']);
        Route::post('/auth/send-otp', [AuthController::class, 'sendEmailOtp']);
        Route::post('/auth/verify-email-otp', [AuthController::class, 'verifyEmailOtp']);


        // ==================== ACTIVE ANNOUNCEMENTS (all authenticated users) ====================
        Route::get('/announcements/active', [SystemAnnouncementController::class, 'active']);

        // ==================== PROMOTIONS ====================
        Route::prefix('promotions')->group(function () {
            Route::get('/', [PromotionController::class, 'index']);
            Route::get('/stats', [PromotionController::class, 'stats']);
            Route::get('/active', [PromotionController::class, 'getActivePromotions']);
            Route::post('/', [PromotionController::class, 'store']);
            Route::post('/validate', [PromotionController::class, 'validatePromoCode']);
            Route::post('/redeem', [PromotionController::class, 'redeemPromoCode']);
            Route::post('/send-expiry-reminders', [PromotionController::class, 'sendExpiryReminders']);
            Route::get('/{promotion}', [PromotionController::class, 'show']);
            Route::put('/{promotion}', [PromotionController::class, 'update']);
            Route::delete('/{promotion}', [PromotionController::class, 'destroy']);
            Route::post('/{promotion}/toggle-active', [PromotionController::class, 'toggleActive']);
            Route::post('/{promotion}/duplicate', [PromotionController::class, 'duplicate']);
            Route::get('/{promotion}/redemptions', [PromotionController::class, 'getRedemptions']);
            Route::get('/{promotion}/analytics', [PromotionController::class, 'getAnalytics']);
        });

        // ==================== DASHBOARD ====================
        Route::get('/dashboard', [DashboardController::class, 'index']);
        Route::get('/dashboard/stats', [DashboardController::class, 'index']);
        Route::get('/dashboard/charts', [DashboardController::class, 'charts']);
        Route::get('/dashboard/detail/{card}', [DashboardController::class, 'detail']);
        Route::get('/dashboard/monthly-summary', [DashboardController::class, 'index']);
        Route::get('/dashboard/recent-bookings', [DashboardController::class, 'recentBookings']);
        Route::get('/dashboard/upcoming-events', [DashboardController::class, 'upcomingEvents']);
        Route::get('/dashboard/low-stock', [DashboardController::class, 'lowStock']);
        Route::get('/dashboard/today-attendance', [DashboardController::class, 'todayAttendance']);
        Route::get('/dashboard/revenue-chart', [DashboardController::class, 'revenueChart']);
        Route::get('/dashboard/event-distribution', [DashboardController::class, 'eventDistribution']);

        // ============================================================
        // EMPLOYEES
        // ============================================================
        Route::get('/employees/eligible-for-payroll', [EmployeeController::class, 'eligibleForPayroll']);
        Route::get('/employees/stats', [EmployeeController::class, 'stats']);
        Route::get('/employees/archived', [EmployeeController::class, 'archived']);
        Route::get('/employees/active', [EmployeeController::class, 'active']);
        Route::get('/employees/all', [CompatibilityController::class, 'employeesAll']);
        Route::get('/employees/all-list', [CompatibilityController::class, 'employeesAll']);
        Route::get('/employees/birthdays', [CompatibilityController::class, 'employeesBirthdays']);
        Route::get('/employees/on-leave', [CompatibilityController::class, 'employeesOnLeave']);
        Route::get('/employees/search', [CompatibilityController::class, 'employeesSearch']);

        Route::get('/employees/without-accounts', [SettingController::class, 'getEmployeesWithoutAccounts']);
        Route::get('/employees/{employee}/for-account', [SettingController::class, 'getEmployeeForAccount']);

        Route::post('/employees/bulk-archive', [CompatibilityController::class, 'employeesBulkArchive']);
        Route::post('/employees/bulk-delete', [CompatibilityController::class, 'employeesBulkDelete']);
        Route::post('/employees/bulk-update-status', [EmployeeController::class, 'bulkStatus']);
        Route::post('/employees/bulk-import', [EmployeeController::class, 'bulkImport']);
        Route::post('/employees/{employee}/force-password-reset', [EmployeeController::class, 'forcePasswordReset']);
        Route::post('/employees/{employee}/block', [EmployeeController::class, 'block']);
        Route::post('/employees/{employee}/unblock', [EmployeeController::class, 'unblock']);
        Route::post('/employees/{employee}', [EmployeeController::class, 'update']);
        Route::post('/employees/{id}/restore', [EmployeeController::class, 'restore']);
        Route::apiResource('employees', EmployeeController::class);

        // ==================== DEPARTMENTS, POSITIONS, SALARY GRADES ====================
        Route::get('/departments/stats', [CompatibilityController::class, 'departmentStats']);
        Route::get('/departments/with-employees', [CompatibilityController::class, 'departmentsWithEmployees']);
        Route::get('/positions/stats', [CompatibilityController::class, 'positionStats']);
        Route::get('/salary-grades/stats', [CompatibilityController::class, 'salaryGradeStats']);
        Route::get('/meal-categories/manage', [MealCategoryController::class, 'index']);
        Route::get('/menu-statistics', [CompatibilityController::class, 'menuStatistics']);
        Route::post('/menu-items/{menuItem}', [MenuItemController::class, 'update']);
        Route::post('/menu-items/{menuItem}/toggle-availability', [MenuItemController::class, 'toggleAvailability']);
        Route::post('/menu-items/{menuItem}/toggle-featured', [MenuItemController::class, 'toggleFeatured']);
        Route::get('/packages/manage', [CompatibilityController::class, 'packages']);
        Route::get('/packages/manage/{package}', [CompatibilityController::class, 'package']);
        Route::get('/ingredients/low-stock', [IngredientController::class, 'lowStock']);
        Route::put('/ingredients/{ingredient}/stock', [IngredientController::class, 'updateStock']);
        Route::post('/ingredients/{id}/restore', [IngredientController::class, 'restore']);
        Route::get('/products/stats', [IngredientController::class, 'stats']);
        Route::get('/products', [IngredientController::class, 'index']);
        Route::post('/products', [IngredientController::class, 'store']);
        Route::get('/products/{ingredient}', [IngredientController::class, 'show']);
        Route::put('/products/{ingredient}', [IngredientController::class, 'update']);
        Route::delete('/products/{ingredient}', [IngredientController::class, 'destroy']);
        Route::post('/products/{id}/restore', [IngredientController::class, 'restore']);
        Route::post('/packages/{id}/restore', [PackageController::class, 'restore']);

        // ==================== NOTIFICATIONS ====================
        Route::prefix('notifications')->group(function () {
            Route::get('/', [NotificationController::class, 'index']);
            Route::get('/catalog', [NotificationController::class, 'catalog']);
            Route::get('/unread-count', [NotificationController::class, 'getUnreadCount']);
            Route::get('/starred', [NotificationController::class, 'getStarred']);
            Route::get('/type/{type}', [NotificationController::class, 'getByType']);
            Route::get('/priority/{priority}', [NotificationController::class, 'getByPriority']);
            Route::post('/', [NotificationController::class, 'store']);
            Route::post('/read-all', [NotificationController::class, 'markAllRead']);
            Route::delete('/clear-all', [NotificationController::class, 'clearAll']);
            Route::post('/delete-multiple', [NotificationController::class, 'deleteMultiple']);
            Route::get('/{notification}', [NotificationController::class, 'show']);
            Route::put('/{notification}', [NotificationController::class, 'update']);
            Route::delete('/{notification}', [NotificationController::class, 'destroy']);
            Route::post('/{notification}/read', [NotificationController::class, 'markRead']);
            Route::post('/{notification}/unread', [NotificationController::class, 'markUnread']);
            Route::post('/{notification}/star', [NotificationController::class, 'toggleStar']);
        });

        // ==================== QUOTATIONS ====================
        Route::prefix('quotations')->group(function () {
            Route::get('/', [QuotationController::class, 'index']);
            Route::post('/', [QuotationController::class, 'store']);
            Route::get('/{quotation}', [QuotationController::class, 'show']);
            Route::put('/{quotation}', [QuotationController::class, 'update']);
            Route::delete('/{quotation}', [QuotationController::class, 'destroy']);
            Route::post('/{quotation}/approve', [QuotationController::class, 'approve']);
            Route::post('/{quotation}/reject', [CompatibilityController::class, 'quotationReject']);
            Route::post('/{quotation}/send', [QuotationController::class, 'send']);
        });

        // ==================== BOOKING CALENDAR AVAILABILITY ====================
        Route::prefix('booking-calendar')->group(function () {
            Route::get('/availability', [BookingController::class, 'availability']);
            Route::put('/availability/{date}', [BookingController::class, 'saveAvailability']);
            Route::delete('/availability/{date}', [BookingController::class, 'deleteAvailability']);
            Route::get('/available-dates', [BookingController::class, 'getAvailableDates']);
            Route::get('/time-slots', [BookingController::class, 'getAvailableTimeSlots']);
        });

        // ============================================================
        // ⭐ Cashier access to Orders & Events module
        //    The frontend route + API must both allow cashiers.
        //    The RoleAccessMiddleware::cashierCanAccess already whitelists
        //    'orders' and 'events' read endpoints. This block ensures the
        //    full CRUD surface used by the UnifiedOrderEvents page is
        //    reachable for cashiers without being blocked by a missing
        //    route-level role gate.
        // ============================================================

        // ============================================================
        // ==================== BOOKINGS ====================
        // ============================================================
        Route::get('/bookings/check-conflicts', [BookingController::class, 'conflicts']);
        Route::get('/bookings/completed', [BookingController::class, 'getCompleted']);
        Route::get('/bookings-statistics', [BookingController::class, 'statistics']);
        Route::get('/calendar-events', [BookingController::class, 'calendar']);
        Route::post('/bookings/{booking}/confirm', [BookingController::class, 'approve']);
        Route::post('/bookings/{booking}/reject', [BookingController::class, 'reject']);
        Route::post('/bookings/{booking}/unreject', [BookingController::class, 'unreject']);
        Route::post('/bookings/{booking}/cancel', [BookingController::class, 'cancel']);
        Route::post('/bookings/{booking}/reschedule', [BookingController::class, 'reschedule']);
        Route::post('/bookings/{booking}/request-reschedule', [BookingController::class, 'requestReschedule']);
        Route::post('/bookings/{booking}/approve-reschedule', [BookingController::class, 'approveReschedule']);
        Route::post('/bookings/{booking}/reject-reschedule', [BookingController::class, 'rejectReschedule']);
        Route::post('/bookings/{booking}/record-payment', [BookingController::class, 'recordPayment']);
        Route::get('/bookings/{booking}/payment-summary', [BookingController::class, 'paymentSummary']);
        Route::post('/bookings/{booking}/create-order', [BookingController::class, 'createOrder']);
        Route::post('/bookings/{booking}/complete', [BookingController::class, 'complete']);
        Route::post('/bookings/{booking}/cancel-with-reason', [BookingController::class, 'cancelWithReason']);
        Route::get('/bookings/check-conflicts-notify', [BookingController::class, 'checkConflictsAndNotify']);
        Route::post('/bookings/{booking}/create-event', [BookingController::class, 'createEvent']);
        Route::post('/bookings/{booking}/cancel-reschedule-proposal', [BookingController::class, 'cancelRescheduleProposal']);

        // ⭐ Un-start an event started by mistake. Reverts booking_status
        //    from 'ongoing' back to 'confirmed' and clears the ongoing
        //    EventTracking row so the booking reappears in Confirmed Bookings.
        Route::post('/bookings/{booking}/unstart-event', [BookingController::class, 'unstartEvent']);

        Route::post('/bookings/{booking}/request-refund',   [BookingController::class, 'requestRefund']);
        Route::post('/bookings/{booking}/approve-refund',   [BookingController::class, 'approveRefund']);
        Route::post('/bookings/{booking}/reject-refund',    [BookingController::class, 'rejectRefund']);
        Route::post('/bookings/{booking}/deposit-decision', [BookingController::class, 'depositDecision']);
        Route::post('/bookings/{booking}/confirm-refund',      [BookingController::class, 'confirmRefund']);
        Route::post('/bookings/{booking}/admin-direct-refund', [BookingController::class, 'adminDirectRefund']);


        Route::post('/bookings/{booking}/customer-update', [BookingController::class, 'customerUpdate']);
        Route::post('/bookings/{booking}/customer-reschedule-response', [BookingController::class, 'customerRescheduleResponse']);
        Route::post('/bookings/{booking}/customer-post-rejection', [BookingController::class, 'customerPostRejectionDecision']);
        // ============================================================
        // ⭐ NEW: BOOKING AVAILABILITY + RESCHEDULE VALIDATION ROUTES
        // ============================================================
        Route::post('/bookings/validate-slot', [BookingController::class, 'validateSlot']);
        Route::post('/bookings/{booking}/admin-reschedule', [BookingController::class, 'adminRescheduleWithValidation']);
        Route::post('/bookings/{booking}/customer-request-reschedule', [BookingController::class, 'customerRequestRescheduleWithValidation']);
        Route::post('/bookings/{booking}/customer-cancel', [BookingController::class, 'customerCancelWithValidation']);
        Route::post('/bookings/{booking}/customer-respond-reschedule', [BookingController::class, 'customerRespondToAdminReschedule']);

        // ============================================================
        // BOOKING INGREDIENTS MANAGEMENT ROUTES
        // ============================================================
        Route::prefix('bookings')->group(function () {
            Route::get('/ingredients-management', [BookingController::class, 'getBookingsWithIngredients']);
            Route::get('/{booking}/ingredients-details', [BookingController::class, 'getBookingIngredientsDetails']);
            Route::get('/{booking}/menu-item/{menuItemId}/ingredients', [BookingController::class, 'getMenuItemIngredients']);
            Route::post('/{booking}/ingredients-mark-purchased', [BookingController::class, 'markIngredientsPurchasedPerBooking']);
            Route::post('/{booking}/ingredients-mark-all-purchased', [BookingController::class, 'markAllIngredientsPurchased']);
        });

        Route::apiResource('/bookings', BookingController::class);

        // ==================== INVENTORY ====================
        Route::get('/inventory/dashboard-stats', [InventoryController::class, 'dashboard']);
        Route::get('/inventory/movements', [InventoryController::class, 'movements']);
        Route::post('/inventory/movements', [InventoryController::class, 'recordMovement']);
        Route::match(['get', 'post'], '/inventory/waste', [InventoryController::class, 'waste']);
        Route::match(['get', 'post'], '/inventory/purchase-requests', [InventoryController::class, 'purchaseRequests']);
        Route::get('/inventory/purchase-requests/{purchaseRequest}', [InventoryController::class, 'showPurchaseRequest']);
        Route::put('/inventory/purchase-requests/{purchaseRequest}', [InventoryController::class, 'updatePurchaseRequest']);
        Route::get('/inventory/purchase-suggestions', [InventoryController::class, 'purchaseSuggestions']);
        Route::get('/inventory/approved-requests', [InventoryController::class, 'approvedRequests']);
        Route::get('/inventory/approved-request-files', [InventoryController::class, 'approvedRequestFiles']);
        Route::put('/inventory/approved-request-files/{date}/rename', [InventoryController::class, 'renameApprovedRequestFile']);
        Route::get('/inventory/notifications', [InventoryController::class, 'inventoryNotifications']);
        Route::get('/inventory/maintenance-schedule', [InventoryController::class, 'maintenanceSchedule']);
        Route::get('/inventory/maintenance', [InventoryController::class, 'maintenanceRecords']);
        Route::post('/inventory/maintenance', [InventoryController::class, 'storeMaintenance']);
        Route::put('/inventory/maintenance/{maintenance}', [InventoryController::class, 'updateMaintenance']);
        Route::delete('/inventory/maintenance/{maintenance}', [InventoryController::class, 'cancelMaintenance']);
        Route::get('/inventory/low-stock', [IngredientController::class, 'lowStock']);
        Route::get('/inventory/expiring-soon', [InventoryController::class, 'expiringSoon']);
        Route::get('/inventory/stock-value', [InventoryController::class, 'stockValue']);
        Route::get('/equipment/reservations', [InventoryController::class, 'reservations']);
        Route::post('/equipment/reservations', [InventoryController::class, 'storeReservation']);
        Route::put('/equipment/reservations/{tracking}', [InventoryController::class, 'updateTracking']);
        Route::get('/inventory/summary', [CompatibilityController::class, 'inventoryDashboardSummary']);
        Route::get('/inventory/equipment-warnings', [InventoryController::class, 'equipmentWarnings']);
        Route::get('/inventory/history/{type}/{id}', [InventoryController::class, 'history']);
        Route::get('/inventory-history/item/{type}/{id}', [InventoryController::class, 'history']);

        // ==================== DELIVERY ZONES ====================
        // IMPORTANT: The literal toggle route MUST be declared BEFORE the
        // apiResource('delivery-zones') call, otherwise Laravel matches
        // {deliveryZone} = "toggle" and routes to the show() method.
        Route::post('/delivery-zones/{deliveryZone}/toggle', [DeliveryZoneController::class, 'toggleStatus']);

        // ==================== OTHER RESOURCES ====================
        Route::apiResources([
            'departments' => DepartmentController::class,
            'positions' => PositionController::class,
            'salary-grades' => SalaryGradeController::class,
            'menu-items' => MenuItemController::class,
            'meal-categories' => MealCategoryController::class,
            'categories' => MealCategoryController::class,
            'packages' => PackageController::class,
            'ingredients' => IngredientController::class,
            'suppliers' => SupplierController::class,
            'reviews' => ReviewController::class,
            'event-types' => EventTypeController::class,
            'delivery-zones' => DeliveryZoneController::class,
            'shift-types' => ShiftTypeController::class,
        ]);
        // ==================== SYSTEM SETTINGS ====================
        Route::put('/settings', [SettingController::class, 'updateCompatibility']);

        // ⭐ Food Allergens master list — CRUD
        Route::get('/settings/allergens', [SettingController::class, 'getAllergens']);
        Route::post('/settings/allergens', [SettingController::class, 'createAllergen']);
        Route::put('/settings/allergens/{allergenId}', [SettingController::class, 'updateAllergen']);
        Route::delete('/settings/allergens/{allergenId}', [SettingController::class, 'deleteAllergen']);
        // ⭐ Insight visibility (Hide/Unhide KPI cards).
        //   GET  → any authenticated user (cashier must SEE the hidden state)
        //   PUT  → admin / super-admin only
        //
        //   Declared BEFORE the `settings` prefix group so it never hits
        //   `PUT /settings/{section}` and its section allow-list.
        //   Note: RoleAccessMiddleware::adminCanAccessBookingSettings and
        //   cashierCanAccess already whitelist these two paths, so the
        //   request passes through the role.access gate.
        Route::get('/settings/insight-visibility', [SettingController::class, 'getInsightVisibility']);
        Route::get('/settings/financial-visibility', [SettingController::class, 'getFinancialVisibility']);
        Route::put('/settings/financial-visibility', [SettingController::class, 'updateFinancialVisibility']);

        // ⭐ Billing KPI visibility — Hide/Unhide for Billing & Invoicing KPIs.
        Route::get('/settings/billing-visibility', [SettingController::class, 'getBillingVisibility']);
        Route::put('/settings/billing-visibility', [SettingController::class, 'updateBillingVisibility']);

        // ⭐ Role enforcement is done inside RoleAccessMiddleware
        //    (see adminCanAccessBookingSettings + cashierCanAccess).
        //    No extra `role:` middleware needed → avoids the "Target class
        //    [role] does not exist" error when the alias isn't registered.
        Route::put('/settings/insight-visibility', [SettingController::class, 'updateInsightVisibility']);

        Route::prefix('settings')->group(function () {
            Route::get('/', [SettingController::class, 'index']);
            Route::post('/reset', [SettingController::class, 'reset']);

            // Literal routes — MUST come before `/{section}`

            // Backup configuration
            Route::get('/system-backup-config', [SettingController::class, 'getBackupConfig']);
            Route::put('/system-backup-config', [SettingController::class, 'updateBackupConfig']);

            // Role restrictions
            Route::get('/role-restrictions/{roleSlug}', [SettingController::class, 'getRoleRestrictions']);
            Route::put('/role-restrictions/{roleSlug}', [SettingController::class, 'updateRoleRestrictions']);

            // User account settings
            Route::get('/user-account-settings', [SettingController::class, 'getUserAccountSettings']);
            Route::put('/user-account-settings', [SettingController::class, 'updateUserAccountSettings']);

            // Maintenance configuration & actions
            Route::get('/maintenance-config', [SettingController::class, 'getMaintenanceConfig']);
            Route::put('/maintenance-config', [SettingController::class, 'updateMaintenanceConfig']);
            Route::post('/clear-temp-files', [SettingController::class, 'clearTemporaryFiles']);
            Route::post('/run-database-maintenance', [SettingController::class, 'runDatabaseMaintenance']);
            Route::post('/force-logout-all', [SettingController::class, 'forceLogoutAllSessions']);

            // Staff snapshot + group updates
            Route::get('/staff/snapshot', [SettingController::class, 'staffSnapshot']);
            Route::put('/staff/{group}', [SettingController::class, 'updateStaffGroup']);

            // Super Admin snapshot + group updates
            Route::get('/system/snapshot', [SettingController::class, 'superAdminSnapshot']);
            Route::put('/system/{group}', [SettingController::class, 'updateSystemGroup']);

            // Login logs
            Route::get('/login-logs', [SettingController::class, 'loginLogs']);

            // Backups
            Route::get('/backups', [SettingController::class, 'listBackups']);
            Route::post('/backups', [SettingController::class, 'createBackup']);
            Route::get('/backups/{filename}/download', [SettingController::class, 'downloadBackup']);
            Route::post('/backups/{filename}/restore', [SettingController::class, 'restoreBackup']);
            Route::delete('/backups/{filename}', [SettingController::class, 'deleteBackup']);

            // System status & cache
            Route::get('/system-status', [SettingController::class, 'systemStatus']);
            Route::post('/clear-cache', [SettingController::class, 'clearSystemCache']);

            // Wildcard MUST be last
            Route::get('/{section}', [SettingController::class, 'getSection']);
            Route::put('/{section}', [SettingController::class, 'updateSection']);
        });

        // ==================== USERS & ROLES ====================
        Route::get('/users', [SettingController::class, 'getUsers']);
        Route::post('/users', [SettingController::class, 'createUser']);
        Route::post('/users/from-employee', [SettingController::class, 'createUserFromEmployee']);
        Route::get('/users/{user}', [SettingController::class, 'getUser']);
        Route::put('/users/{user}', [SettingController::class, 'updateUser']);
        Route::put('/users/{user}/role', [SettingController::class, 'updateUserRole']);
        Route::post('/users/{user}/toggle-active', [SettingController::class, 'toggleUserActive']);
        Route::post('/users/{user}/ban', [SettingController::class, 'banUser']);
        Route::post('/users/{user}/unban', [SettingController::class, 'unbanUser']);
        Route::post('/users/{user}/force-logout', [SettingController::class, 'forceLogoutUser']);
        Route::post('/users/{user}/force-password', [SettingController::class, 'forceChangePassword']);

        Route::get('/roles/{role}', [SettingController::class, 'getRole']);
        Route::post('/roles', [SettingController::class, 'createRole']);
        Route::put('/roles/{role}', [SettingController::class, 'updateRole']);
        Route::delete('/roles/{role}', [SettingController::class, 'deleteRole']);
        Route::get('/roles', [SettingController::class, 'getRoles']);

        // ==================== AUDIT LOGS ====================
        Route::get('/audit-logs', [SettingController::class, 'getAuditLogs']);
        Route::get('/audit-logs/catalog', [SettingController::class, 'auditCatalog']);
        Route::get('/audit-logs/export', [SettingController::class, 'exportAuditLogs']);

        // ==================== CUSTOMERS ====================
        Route::get('/customers/stats', [CustomerController::class, 'stats']);
        Route::get('/customers', [CustomerController::class, 'index']);
        Route::get('/customers/{customer}/loyalty', [CustomerController::class, 'loyalty']);
        Route::post('/customers/{customer}/loyalty/add', [CustomerController::class, 'addLoyaltyPoints']);
        Route::post('/customers/{customer}/loyalty/redeem', [CustomerController::class, 'redeemLoyaltyPoints']);
        Route::post('/customers/{id}/restore', [CustomerController::class, 'restore']);
        Route::post('/customer-messages/{message}/read', [CustomerController::class, 'markMessageRead']);
        Route::get('/customers/{customer}', [CustomerController::class, 'show']);
        Route::post('/customers', [CustomerController::class, 'store']);
        Route::put('/customers/{customer}', [CustomerController::class, 'update']);
        Route::delete('/customers/{customer}', [CustomerController::class, 'destroy']);
        Route::get('/customers-feedback', [CustomerController::class, 'feedback']);
        Route::get('/customer-messages', [CustomerController::class, 'messages']);
        Route::post('/customer-messages', [CustomerController::class, 'sendMessage']);
        Route::get('/customers/{customer}/bookings', [CustomerController::class, 'bookings']);
        Route::get('/customers/{customer}/payments', [CustomerController::class, 'payments']);
        Route::get('/customers/{customer}/reviews', [CustomerController::class, 'reviews']);
        Route::post('/customers/{customer}/send-email', [CustomerController::class, 'sendEmail']);
        Route::post('/customers/{customer}/toggle-status', [CustomerController::class, 'toggleStatus']);

        // ==================== FEEDBACK & REVIEWS ====================
        Route::post('/feedbacks/{review}/respond', [ReviewController::class, 'respond']);
        Route::post('/reviews/{review}/approve', [ReviewController::class, 'approve']);
        Route::post('/reviews/{review}/feature', [ReviewController::class, 'toggleFeature']);
        Route::post('/reviews/{review}/hide', [ReviewController::class, 'hide']);

        // ==================== LEAVE REQUESTS ====================
        Route::get('/employee-requests/pending', [LeaveRequestController::class, 'index']);
        Route::get('/employee-requests/stats/{employeeId}', [LeaveRequestController::class, 'stats']);
        Route::put('/employee-requests/{leaveRequest}/status', [LeaveRequestController::class, 'status']);
        Route::post('/employee-requests/{leaveRequest}/cancel', [LeaveRequestController::class, 'cancel']);
        Route::apiResource('/employee-requests', LeaveRequestController::class);
        Route::post('/leave-requests/{leaveRequest}/approve', [LeaveRequestController::class, 'approve']);
        Route::post('/leave-requests/{leaveRequest}/reject', [LeaveRequestController::class, 'reject']);

        // ==================== SCHEDULES ====================
        Route::get('/schedules/today', [ScheduleController::class, 'today']);
        Route::get('/schedules/week', [ScheduleController::class, 'week']);
        Route::get('/schedules/month', [ScheduleController::class, 'month']);
        Route::get('/schedules/archived', [ScheduleController::class, 'archived']);
        Route::get('/schedules/stats', [ScheduleController::class, 'stats']);
        Route::get('/schedules/range', [ScheduleController::class, 'range']);
        Route::get('/schedules/warnings', [ScheduleController::class, 'warnings']);
        Route::get('/schedules/completed-shifts', [ScheduleController::class, 'completed']);
        Route::get('/schedules/date/{date}', [ScheduleController::class, 'date']);
        Route::get('/schedules/employee/{employeeId}', [ScheduleController::class, 'employee']);
        Route::post('/schedules/bulk', [ScheduleController::class, 'bulk']);
        Route::post('/schedules/bulk-archive', [ScheduleController::class, 'bulkArchive']);
        Route::post('/schedules/bulk-restore', [ScheduleController::class, 'bulkRestore']);
        Route::post('/schedules/{schedule}/archive', [ScheduleController::class, 'archive']);
        Route::post('/schedules/{id}/restore', [ScheduleController::class, 'restore']);
        Route::apiResource('/schedules', ScheduleController::class);

        // ==================== ATTENDANCE ====================
        Route::get('/attendance/all', [AttendanceController::class, 'index']);
        Route::post('/attendance/login', [AttendanceController::class, 'mobileLogin']);
        Route::post('/attendance/logout', [AttendanceController::class, 'mobileLogout']);
        Route::get('/attendance/today', [AttendanceController::class, 'today']);
        Route::get('/attendance/summary', [AttendanceController::class, 'summary']);
        Route::get('/attendance/statistics', [AttendanceController::class, 'statistics']);
        Route::get('/attendance/employee-overview', [AttendanceController::class, 'employeeOverview']);
        Route::get('/attendance/employee-records', [AttendanceController::class, 'employeeRecords']);
        Route::post('/attendance/generate-summary', [AttendanceController::class, 'generateSummary']);
        Route::post('/attendance/save-summary-to-payroll', [AttendanceController::class, 'saveSummaryToPayroll']);
        Route::post('/attendance/save-all-summaries-to-payroll', [AttendanceController::class, 'saveAllSummariesToPayroll']);
        Route::get('/attendance/history', [AttendanceController::class, 'index']);
        Route::get('/attendance/range', [AttendanceController::class, 'index']);
        Route::get('/attendance/needs-approval', [AttendanceController::class, 'needsApproval']);
        Route::get('/attendance/employee/{employee}', [AttendanceController::class, 'employee']);
        Route::get('/attendance/employee-saved-records', [AttendanceController::class, 'employeeSavedRecords']);
        Route::post('/attendance/time-in', [AttendanceController::class, 'timeIn']);
        Route::post('/attendance/time-out', [AttendanceController::class, 'timeOut']);
        Route::put('/attendance/{attendance}/times', [AttendanceController::class, 'updateTimes']);
        Route::put('/attendance/{attendance}/status', [AttendanceController::class, 'updateStatus']);
        Route::post('/attendance/{attendance}/approve', [AttendanceController::class, 'approve']);
        Route::post('/attendance/{attendance}/unverify', [AttendanceController::class, 'unverify']);
        Route::post('/attendance/{attendance}/approve-unscheduled', [AttendanceController::class, 'approveUnscheduled']);
        Route::post('/attendance/{attendance}/approve-overtime', [AttendanceController::class, 'approveOvertime']);
        Route::post('/attendance/{attendance}/reject-overtime', [AttendanceController::class, 'rejectOvertime']);
        Route::post('/attendance/{attendance}/approve-undertime', [AttendanceController::class, 'approveUndertime']);
        Route::post('/attendance/{attendance}/reject-undertime', [AttendanceController::class, 'rejectUndertime']);
        Route::post('/attendance/overtime/bulk-decision', [AttendanceController::class, 'bulkOvertimeDecision']);
        Route::get('/attendance/check-missing-timeouts', [AttendanceController::class, 'checkMissingTimeouts']);
        Route::post('/attendance/create', [AttendanceController::class, 'createManual']);
        Route::post('/attendance/{attendance}/flag', [AttendanceController::class, 'flagAttendance']);
        // ==================== PAYROLL ====================
        Route::get('/payroll', [PayrollController::class, 'index']);
        Route::get('/payroll/history', [PayrollController::class, 'history']);
        Route::get('/payroll/stats', [PayrollController::class, 'stats']);
        Route::get('/payroll/history-stats', [PayrollController::class, 'historyStats']);
        Route::post('/payroll/preview', [PayrollController::class, 'preview']);
        Route::post('/payroll/process', [PayrollController::class, 'process']);
        Route::post('/payroll/{payroll}/approve', [PayrollController::class, 'approve']);
        Route::post('/payroll/{payroll}/mark-paid', [PayrollController::class, 'markPaid']);
        Route::post('/payroll/{id}/restore', [PayrollController::class, 'restore']);
        Route::delete('/payroll/{id}/permanent', [PayrollController::class, 'permanentDelete']);
        Route::get('/payroll/{payroll}/payslip', [PayrollController::class, 'payslip']);
        Route::get('/payroll/summary', [CompatibilityController::class, 'payrollSummary']);
        Route::post('/payroll/bulk-deductions', [CompatibilityController::class, 'payrollBulkDeductions']);
        Route::get('/payroll/export', [CompatibilityController::class, 'payrollExport']);
        Route::get('/payroll/{payroll}', [PayrollController::class, 'show']);
        Route::put('/payroll/{payroll}', [PayrollController::class, 'update']);
        Route::delete('/payroll/{payroll}', [PayrollController::class, 'destroy']);

        // ==================== PAYMENTS ====================
        Route::get('/payments', [PaymentController::class, 'index']);
        Route::get('/payments/tracking', [PaymentController::class, 'tracking']);
        Route::get('/payments/history', [PaymentController::class, 'history']);
        Route::get('/payments/summary', [PaymentController::class, 'summary']);
        Route::post('/payments', [PaymentController::class, 'store']);
        Route::post('/payments/mobile', [PaymentController::class, 'recordMobilePayment']);
        Route::get('/payments/mobile', [PaymentController::class, 'getAllMobilePayments']);
        Route::post('/payments/mobile/{payment}/verify', [PaymentController::class, 'verifyMobilePayment']);
        Route::post('/payments/mobile/{payment}/reject', [PaymentController::class, 'rejectMobilePayment']);
        Route::get('/payments/{payment}', [PaymentController::class, 'show']);
        Route::put('/payments/{payment}', [PaymentController::class, 'update']);
        Route::post('/payments/{payment}/verify', [PaymentController::class, 'verify']);
        Route::post('/payments/{payment}/reject', [PaymentController::class, 'reject']);
        Route::delete('/payments/{payment}', [PaymentController::class, 'destroy']);
        Route::get('/payments/{payment}/download-receipt', [PaymentController::class, 'downloadReceipt']);
        Route::get('/payments/{payment}/receipt', [PaymentController::class, 'getReceipt']);
        Route::post('/payments/{payment}/refund', [PaymentController::class, 'refund']);

        // ==================== INVOICES ====================
        Route::get('/invoices', [InvoiceController::class, 'index']);
        Route::get('/invoices/confirmed-bookings', [InvoiceController::class, 'getConfirmedBookings']);
        Route::post('/invoices', [InvoiceController::class, 'store']);
        Route::get('/invoices/{invoice}', [InvoiceController::class, 'show'])->whereNumber('invoice');
        Route::put('/invoices/{invoice}', [InvoiceController::class, 'update'])->whereNumber('invoice');
        Route::delete('/invoices/{invoice}', [InvoiceController::class, 'destroy'])->whereNumber('invoice');
        Route::get('/invoices/{invoice}/payments', [InvoiceController::class, 'payments'])->whereNumber('invoice');
        Route::post('/invoices/{invoice}/reminder', [InvoiceController::class, 'sendReminder'])->whereNumber('invoice');
        Route::get('/invoices/{invoice}/download', [InvoiceController::class, 'download'])->whereNumber('invoice');
        Route::get('/debts', [InvoiceController::class, 'debts']);
        // ==================== DEPOSITS ====================
        Route::get('/deposits/pending', [DepositController::class, 'pending']);
        Route::post('/deposits/send-reminders', [DepositController::class, 'sendReminders']);
        Route::post('/deposits/auto-cancel', [DepositController::class, 'autoCancel']);

        // ==================== FINANCIAL REPORTS ====================
        Route::get('/financial-reports', [FinancialReportController::class, 'index']);
        Route::get('/financial-reports/sales', [FinancialReportController::class, 'sales']);
        Route::get('/financial-reports/expenses', [FinancialReportController::class, 'expenses']);
        Route::get('/financial-reports/profit-loss', [FinancialReportController::class, 'profitLoss']);

        // ==================== EVENTS ====================
        Route::get('/events/stats', [EventController::class, 'stats']);
        Route::get('/event-statistics', [EventController::class, 'stats']);
        Route::get('/event-calendar', [BookingController::class, 'calendar']);
        Route::post('/events/{eventCode}/return-equipment', [EventController::class, 'returnEventEquipment']);
        Route::post('/events/{id}/tracking', [EventController::class, 'track']);
        Route::get('/events', [EventController::class, 'index']);
        Route::get('/events/{id}', [EventController::class, 'show']);
        Route::post('/events', [EventController::class, 'store']);
        Route::match(['put', 'post'], '/events/{id}', [EventController::class, 'update']);
        Route::delete('/events/{id}', [EventController::class, 'destroy']);

        // ==================== EQUIPMENT TRACKING ====================
        Route::get('/equipment/tracking', [InventoryController::class, 'equipmentTracking']);
        Route::get('/equipment/availability', [InventoryController::class, 'equipmentAvailability']);
        Route::get('/equipment/availability-all', [InventoryController::class, 'equipmentAvailabilityAll']);
        Route::post('/equipment/checkout', [InventoryController::class, 'equipmentCheckout']);
        Route::post('/equipment/checkin/{tracking}', [InventoryController::class, 'equipmentCheckIn']);
        Route::put('/equipment/tracking/{tracking}', [InventoryController::class, 'updateTracking']);
        Route::get('/equipment/summary', [InventoryController::class, 'equipmentSummary']);
        Route::get('/equipment/stats', [EquipmentController::class, 'stats']);
        Route::post('/equipment/{id}/restore', [EquipmentController::class, 'restore']);
        Route::get('/equipment/{equipment}/history', [EquipmentController::class, 'history']);
        Route::apiResource('/equipment', EquipmentController::class);

        // ==================== PROFITABILITY ANALYTICS ====================
        Route::get('/profitability/dashboard', [ProfitabilityController::class, 'dashboard']);
        Route::get('/profitability/report', [ProfitabilityController::class, 'report']);
        Route::get('/profitability/menus', [ProfitabilityController::class, 'menuPerformance']);

        Route::get('/bookings/{booking}/profitability', [ProfitabilityController::class, 'show']);
        Route::post('/bookings/{booking}/profitability/snapshot', [ProfitabilityController::class, 'saveSnapshot']);
        Route::put('/bookings/{booking}/profitability/costs', [ProfitabilityController::class, 'saveCosts']);

        // ==================== REPORTS MODULE ====================
        Route::prefix('reports')->group(function () {
            Route::get('/sales', [ReportController::class, 'sales']);
            Route::get('/inventory', [ReportController::class, 'inventory']);
            Route::get('/payroll', [ReportController::class, 'payroll']);
            Route::get('/events', [ReportController::class, 'events']);
            Route::get('/customers', [ReportController::class, 'customers']);
            Route::get('/financial', [ReportController::class, 'financial']);
            Route::get('/additional', [ReportController::class, 'additional']);
        });

        // ==================== EVENT SUB-RESOURCES ====================
        Route::prefix('events')->group(function () {
            Route::get('/{id}/pending-deductions', [EventController::class, 'getPendingDeductions']);
            Route::post('/{id}/confirm-deductions', [EventController::class, 'confirmDeductions']);
            Route::post('/{id}/advance-day', [EventController::class, 'advanceToNextDay']);
            Route::put('/{id}/attendance/{day}', [EventController::class, 'updateAttendance']);
            Route::post('/{id}/deliveries', [EventController::class, 'addDelivery']);
            Route::post('/{id}/staff', [EventController::class, 'assignStaff']);
            Route::get('/{id}/staff', [EventController::class, 'getStaff']);
            Route::put('/{id}/staff/{staffId}', [EventController::class, 'updateStaffStatus']);
            Route::put('/{id}/staff/{staffId}/status', [EventController::class, 'updateStaffStatus']);
            Route::delete('/{id}/staff/{staffId}', [EventController::class, 'removeStaff']);
            Route::get('/{id}/checklist', [EventController::class, 'getChecklist']);
            Route::put('/{id}/checklist/{itemId}', [EventController::class, 'updateChecklistItem']);
            Route::post('/{id}/checklist', [EventController::class, 'addChecklistItem']);
            Route::delete('/{id}/checklist/{itemId}', [EventController::class, 'deleteChecklistItem']);
            Route::get('/{id}/sessions', [EventController::class, 'getSessions']);
            Route::post('/{id}/sessions', [EventController::class, 'addSession']);
            Route::put('/{id}/sessions/{sessionId}/status', [EventController::class, 'updateSessionStatus']);
            Route::delete('/{id}/sessions/{sessionId}', [EventController::class, 'deleteSession']);
            Route::get('/{id}/deliveries', [EventController::class, 'getDeliveries']);
            Route::put('/{id}/deliveries/{deliveryId}', [EventController::class, 'updateDelivery']);
            Route::delete('/{id}/deliveries/{deliveryId}', [EventController::class, 'deleteDelivery']);
            Route::put('/{id}/deliveries/{deliveryId}/status', [EventController::class, 'updateDeliveryStatus']);
            Route::get('/{id}/daily-progress', [EventController::class, 'getDailyProgress']);
            Route::put('/{id}/daily-progress/{day}', [EventController::class, 'updateDailyProgress']);
            Route::get('/{id}/equipment', [EventController::class, 'getEquipment']);
            Route::post('/{id}/equipment/approve-all', [EventController::class, 'approveAllEquipment']);
            Route::post('/{id}/equipment/approve-selected', [EventController::class, 'approveSelectedEquipment']);
            Route::post('/{id}/equipment/checkout', [EventController::class, 'checkoutEquipment']);
            Route::post('/{id}/equipment/{transactionId}/return', [EventController::class, 'returnEquipment']);
            Route::get('/{id}/live-status', [EventController::class, 'liveStatus']);
            Route::put('/{id}/live-status', [EventController::class, 'updateLiveStatus']);
            Route::post('/{id}/start', [EventController::class, 'startEvent']);
            Route::put('/{id}/meal-services/{mealServiceId}/status', [EventController::class, 'updateMealStatus']);
            Route::post('/{id}/complete', [EventController::class, 'complete']);
        });

        // ==================== ORDERS ====================
        Route::prefix('orders')->group(function () {
            Route::get('/stats', [OrderController::class, 'stats']);
            Route::get('/kitchen-orders', [OrderController::class, 'kitchenOrders']);
            Route::get('/delivery-orders', [OrderController::class, 'deliveryOrders']);
            Route::post('/{order}/status', [OrderController::class, 'updateStatus']);
            Route::post('/{order}/add-to-kitchen', [OrderController::class, 'kitchen']);
            Route::post('/{order}/add-to-delivery', [OrderController::class, 'delivery']);
            Route::post('/{order}/remove-from-kitchen', [OrderController::class, 'removeFromKitchen']);
            Route::post('/{order}/remove-from-delivery', [OrderController::class, 'removeFromDelivery']);
            Route::put('/{order}/kitchen-task', [OrderController::class, 'updateKitchenTask']);
            Route::put('/{order}/delivery-item', [OrderController::class, 'updateDeliveryItem']);
            Route::post('/{order}/compute-ingredients', [OrderController::class, 'computeIngredients']);
            Route::get('/{order}/ingredients', [OrderController::class, 'ingredients']);
        });
        Route::apiResource('/orders', OrderController::class);

        // ==================== RECIPES ====================
        Route::get('/recipes', [RecipeController::class, 'index']);
        Route::post('/recipes', [RecipeController::class, 'store']);
        Route::post('/recipes/bulk', [RecipeController::class, 'bulk']);
        Route::get('/recipes/{menuItem}', [RecipeController::class, 'show']);
        Route::delete('/recipes/{menuItem}', [RecipeController::class, 'destroy']);

        // ==================== SHOPPING LIST ====================
        Route::prefix('shopping-list')->group(function () {
            Route::get('/', [CompatibilityController::class, 'shoppingList']);
            Route::get('/pending-count', [CompatibilityController::class, 'shoppingCount']);
            Route::post('/items/{item}/purchased', [CompatibilityController::class, 'shoppingPurchased']);
            Route::delete('/items/{item}', [CompatibilityController::class, 'shoppingDelete']);
            Route::post('/bulk-purchased', [CompatibilityController::class, 'shoppingBulk']);
        });
        Route::post('/orders/{order}/shopping-list', [CompatibilityController::class, 'shoppingAdd']);

        // ==================== COMPATIBILITY ALIASES ====================
        Route::get('/employees/{employee}/attendance', [CompatibilityController::class, 'employeeAttendance']);
        Route::get('/employees/{employee}/leaves', [CompatibilityController::class, 'employeeLeaves']);
        Route::get('/employees/{employee}/payroll', [CompatibilityController::class, 'employeePayroll']);
        Route::post('/employees/{employee}/toggle-bookmark', [CompatibilityController::class, 'employeeBookmark']);
        Route::post('/employees/{employee}/update-status', [CompatibilityController::class, 'employeeStatus']);
        Route::delete('/employees/{id}/force', [CompatibilityController::class, 'employeeForceDelete']);
        Route::get('/positions/by-salary-grade/{salaryGradeId}', [CompatibilityController::class, 'positionsBySalaryGrade']);

        // ==================== DAILY ATTENDANCE ====================
        Route::get('/daily-attendance', [CompatibilityController::class, 'daily']);
        Route::get('/daily-attendance/pending', [CompatibilityController::class, 'dailyPending']);
        Route::get('/daily-attendance/summary', [CompatibilityController::class, 'dailySummary']);
        Route::post('/daily-attendance/bulk-approve', [CompatibilityController::class, 'dailyBulkApprove']);
        Route::post('/daily-attendance/{attendance}/approve', [CompatibilityController::class, 'dailyApprove']);
        Route::post('/daily-attendance/{attendance}/decline', [CompatibilityController::class, 'dailyDecline']);
        Route::post('/daily-attendance/{attendance}/unapprove', [CompatibilityController::class, 'dailyUnapprove']);
        Route::post('/daily-attendance/{attendance}/undecline', [CompatibilityController::class, 'dailyUndecline']);
        Route::post('/daily-attendance/{attendance}/approve-overtime', [CompatibilityController::class, 'dailyApproveOvertime']);
        Route::post('/daily-attendance/{attendance}/reject-overtime', [CompatibilityController::class, 'dailyRejectOvertime']);

        // ==================== PAYSLIPS ====================
        Route::get('/payslips', [CompatibilityController::class, 'payslips']);
        Route::get('/payslips/{payroll}', [CompatibilityController::class, 'payslip']);
        Route::post('/payslips/generate', [CompatibilityController::class, 'payslipGenerate']);
        Route::get('/payslips/{payroll}/download', [CompatibilityController::class, 'payslip']);
        Route::post('/payslips/{payroll}/email', [CompatibilityController::class, 'payslip']);
        Route::post('/payslips/bulk-generate', [CompatibilityController::class, 'payslipBulkGenerate']);

        // ==================== INVENTORY HISTORY ====================
        Route::get('/inventory-history/recent', [CompatibilityController::class, 'inventoryRecent']);
        Route::get('/inventory-history/summary', [CompatibilityController::class, 'inventorySummary']);
        Route::get('/inventory-history/type/{type}', [CompatibilityController::class, 'inventoryType']);
        Route::get('/inventory-history/date-range', [CompatibilityController::class, 'inventoryRange']);

        // ==================== KITCHEN PREPARATION ====================
        Route::get('/orders/{order}/kitchen-tasks', [OrderController::class, 'getKitchenTasks']);
        Route::put('/orders/{order}/kitchen-tasks', [OrderController::class, 'updateKitchenTasks']);

        // ==================== DELIVERY PREPARATION ====================
        Route::get('/orders/{order}/delivery-items', [OrderController::class, 'getDeliveryItems']);
        Route::put('/orders/{order}/delivery-items', [OrderController::class, 'updateDeliveryItems']);
        Route::post('/orders/{order}/delivery-item', [OrderController::class, 'addDeliveryItem']);

        // ==================== INGREDIENTS ====================
        Route::get('/bookings/{booking}/ingredients-summary', [BookingController::class, 'getIngredientsSummary']);
        Route::post('/bookings/{booking}/ingredients-purchased', [BookingController::class, 'markIngredientsPurchased']);

        // ==================== REFUNDS (ADMIN + SUPER ADMIN ONLY) ====================
        Route::middleware(['role:admin,super-admin'])
            ->prefix('refunds')
            ->group(function () {
                Route::get('/', [RefundController::class, 'index']);
                Route::get('/statistics', [RefundController::class, 'statistics']);
                Route::get('/pending', [RefundController::class, 'pending']);
                Route::get('/approved', [RefundController::class, 'approved']);

                Route::get('/booking/{bookingId}', [RefundController::class, 'byBooking']);
                Route::get('/invoice/{invoiceId}', [RefundController::class, 'byInvoice']);

                Route::post('/', [RefundController::class, 'store']);
                Route::get('/{refund}', [RefundController::class, 'show']);
                Route::post('/{refund}/approve', [RefundController::class, 'approve']);
                Route::post('/{refund}/release', [RefundController::class, 'release']);
                Route::post('/{refund}/reject', [RefundController::class, 'reject']);
                Route::post('/admin-direct', [RefundController::class, 'adminDirect']);
            });

        // ==================== SUPER ADMIN ONLY ====================
        Route::middleware(['role:super-admin'])->group(function () {

            // System Announcements (CRUD)
            Route::prefix('system-announcements')->group(function () {
                Route::get('/', [SystemAnnouncementController::class, 'index']);
                Route::post('/', [SystemAnnouncementController::class, 'store']);
                Route::put('/{id}', [SystemAnnouncementController::class, 'update']);
                Route::delete('/{id}', [SystemAnnouncementController::class, 'destroy']);
            });
        });
    });
});
