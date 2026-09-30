<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            // System Setup
            DepartmentSeeder::class,
            SalaryGradeSeeder::class,
            PositionSeeder::class,
            
            // Users & Employees
            UserAccountSeeder::class,
            EmployeeSeeder::class,
            
            // Business Data
            EventTypeSeeder::class,
            MealCategorySeeder::class,
            IngredientSeeder::class,
            EquipmentSeeder::class,
            MenuItemSeeder::class,
            PackageMenuSeeder::class,
            PromoMenuSeeder::class,
            
            // Customers
            CustomerSeeder::class,
            
            // Bookings (Pending + Completed)
            BookingSeeder::class,           // BK-001 to BK-120 (80 pending + 40 completed)
            PreviousBookingSeeder::class,   // HIST-BK-121 to HIST-BK-200 (analytics-only history)
            
            // Dashboard Data (NEW)
            InvoiceSeeder::class,           // Creates invoices for completed bookings
            PaymentSeeder::class,           // Creates payments for completed bookings
            PayrollSeeder::class,           // Creates payroll records for expenses
            
                      // Attendance
            AttendanceSeeder::class,
            HistoricalOperationsSeeder::class, // Analytics-only schedules, attendance, equipment and stock movements

            // ⭐ NEW: Fill dashboard gaps for previous 18 months
            \Database\Seeders\HistoricalDashboardSeeder::class,
        ]);
    }
}