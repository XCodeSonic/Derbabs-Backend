<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Employee;
use App\Models\Person;
use App\Models\User;
use App\Models\Role;
use Illuminate\Support\Facades\Hash;

class EmployeeSeeder extends Seeder
{
    public function run(): void
    {
        $employeeData = [
            // Executive Management
            ['first_name' => 'Juan', 'last_name' => 'Dela Cruz', 'department_id' => 1, 'position_id' => 1, 'hourly_rate' => 420.00, 'status' => 'active'],
            ['first_name' => 'Maria', 'last_name' => 'Santos', 'department_id' => 1, 'position_id' => 2, 'hourly_rate' => 295.00, 'status' => 'active'],
            
            // Operations
            ['first_name' => 'Jose', 'last_name' => 'Reyes', 'department_id' => 2, 'position_id' => 3, 'hourly_rate' => 210.00, 'status' => 'active'],
            ['first_name' => 'Ana', 'last_name' => 'Gonzales', 'department_id' => 2, 'position_id' => 4, 'hourly_rate' => 155.00, 'status' => 'active'],
            ['first_name' => 'Carlos', 'last_name' => 'Fernandez', 'department_id' => 2, 'position_id' => 5, 'hourly_rate' => 85.00, 'status' => 'active'],
            ['first_name' => 'Elena', 'last_name' => 'Mendoza', 'department_id' => 2, 'position_id' => 5, 'hourly_rate' => 85.00, 'status' => 'active'],
            
            // Kitchen & Culinary
            ['first_name' => 'Ramon', 'last_name' => 'Aguilar', 'department_id' => 3, 'position_id' => 6, 'hourly_rate' => 210.00, 'status' => 'active'],
            ['first_name' => 'Luz', 'last_name' => 'Cruz', 'department_id' => 3, 'position_id' => 7, 'hourly_rate' => 155.00, 'status' => 'active'],
            ['first_name' => 'Pedro', 'last_name' => 'Ramos', 'department_id' => 3, 'position_id' => 8, 'hourly_rate' => 115.00, 'status' => 'active'],
            ['first_name' => 'Sofia', 'last_name' => 'Torres', 'department_id' => 3, 'position_id' => 9, 'hourly_rate' => 115.00, 'status' => 'active'],
            ['first_name' => 'Miguel', 'last_name' => 'Villanueva', 'department_id' => 3, 'position_id' => 9, 'hourly_rate' => 115.00, 'status' => 'active'],
            ['first_name' => 'Rosa', 'last_name' => 'Dizon', 'department_id' => 3, 'position_id' => 10, 'hourly_rate' => 85.00, 'status' => 'active'],
            ['first_name' => 'Fernando', 'last_name' => 'Bautista', 'department_id' => 3, 'position_id' => 10, 'hourly_rate' => 85.00, 'status' => 'active'],
            ['first_name' => 'Gloria', 'last_name' => 'Perez', 'department_id' => 3, 'position_id' => 11, 'hourly_rate' => 60.00, 'status' => 'active'],
            ['first_name' => 'Ricardo', 'last_name' => 'Garcia', 'department_id' => 3, 'position_id' => 11, 'hourly_rate' => 60.00, 'status' => 'active'],
            
            // Events & Planning
            ['first_name' => 'Teresa', 'last_name' => 'Ocampo', 'department_id' => 4, 'position_id' => 12, 'hourly_rate' => 210.00, 'status' => 'active'],
            ['first_name' => 'Manuel', 'last_name' => 'Martinez', 'department_id' => 4, 'position_id' => 13, 'hourly_rate' => 155.00, 'status' => 'active'],
            ['first_name' => 'Cecilia', 'last_name' => 'Chua', 'department_id' => 4, 'position_id' => 13, 'hourly_rate' => 155.00, 'status' => 'active'],
            ['first_name' => 'Rafael', 'last_name' => 'Lim', 'department_id' => 4, 'position_id' => 14, 'hourly_rate' => 115.00, 'status' => 'active'],
            
            // Sales & Marketing
            ['first_name' => 'Isabel', 'last_name' => 'Tan', 'department_id' => 5, 'position_id' => 15, 'hourly_rate' => 210.00, 'status' => 'active'],
            ['first_name' => 'Antonio', 'last_name' => 'Sy', 'department_id' => 5, 'position_id' => 16, 'hourly_rate' => 155.00, 'status' => 'active'],
            ['first_name' => 'Lourdes', 'last_name' => 'Uy', 'department_id' => 5, 'position_id' => 17, 'hourly_rate' => 115.00, 'status' => 'active'],
            ['first_name' => 'Eduardo', 'last_name' => 'Go', 'department_id' => 5, 'position_id' => 18, 'hourly_rate' => 85.00, 'status' => 'active'],
            
            // Finance & Accounting
            ['first_name' => 'Carmen', 'last_name' => 'Lopez', 'department_id' => 6, 'position_id' => 19, 'hourly_rate' => 210.00, 'status' => 'active'],
            ['first_name' => 'Alberto', 'last_name' => 'Rivera', 'department_id' => 6, 'position_id' => 20, 'hourly_rate' => 155.00, 'status' => 'active'],
            ['first_name' => 'Josefina', 'last_name' => 'Morales', 'department_id' => 6, 'position_id' => 21, 'hourly_rate' => 115.00, 'status' => 'active'],
            ['first_name' => 'Rodrigo', 'last_name' => 'Castro', 'department_id' => 6, 'position_id' => 22, 'hourly_rate' => 85.00, 'status' => 'active'],
            ['first_name' => 'Beatrice', 'last_name' => 'Valdez', 'department_id' => 6, 'position_id' => 23, 'hourly_rate' => 85.00, 'status' => 'active'],
            
            // Human Resources
            ['first_name' => 'Francisco', 'last_name' => 'Pineda', 'department_id' => 7, 'position_id' => 24, 'hourly_rate' => 210.00, 'status' => 'active'],
            ['first_name' => 'Margarita', 'last_name' => 'Dela Cruz', 'department_id' => 7, 'position_id' => 25, 'hourly_rate' => 115.00, 'status' => 'active'],
            
            // Logistics & Delivery
            ['first_name' => 'Gregorio', 'last_name' => 'Santiago', 'department_id' => 8, 'position_id' => 26, 'hourly_rate' => 155.00, 'status' => 'active'],
            ['first_name' => 'Dolores', 'last_name' => 'Aguilar', 'department_id' => 8, 'position_id' => 27, 'hourly_rate' => 115.00, 'status' => 'active'],
            ['first_name' => 'Vicente', 'last_name' => 'Gutierrez', 'department_id' => 8, 'position_id' => 28, 'hourly_rate' => 85.00, 'status' => 'active'],
            ['first_name' => 'Rosario', 'last_name' => 'Mercado', 'department_id' => 8, 'position_id' => 28, 'hourly_rate' => 85.00, 'status' => 'active'],
            ['first_name' => 'Felix', 'last_name' => 'Bautista', 'department_id' => 8, 'position_id' => 28, 'hourly_rate' => 85.00, 'status' => 'active'],
            
            // Inventory & Procurement
            ['first_name' => 'Aurora', 'last_name' => 'Reyes', 'department_id' => 9, 'position_id' => 29, 'hourly_rate' => 155.00, 'status' => 'active'],
            ['first_name' => 'Rogelio', 'last_name' => 'Santos', 'department_id' => 9, 'position_id' => 30, 'hourly_rate' => 115.00, 'status' => 'active'],
            ['first_name' => 'Nelia', 'last_name' => 'Cruz', 'department_id' => 9, 'position_id' => 31, 'hourly_rate' => 85.00, 'status' => 'active'],
            ['first_name' => 'Ramon', 'last_name' => 'Luna', 'department_id' => 9, 'position_id' => 31, 'hourly_rate' => 85.00, 'status' => 'active'],
            
            // Maintenance & Facilities
            ['first_name' => 'Mariano', 'last_name' => 'Tolentino', 'department_id' => 10, 'position_id' => 32, 'hourly_rate' => 155.00, 'status' => 'active'],
            ['first_name' => 'Salvador', 'last_name' => 'Cruz', 'department_id' => 10, 'position_id' => 33, 'hourly_rate' => 115.00, 'status' => 'active'],
        ];

        $employeeRole = Role::where('slug', 'employee')->first();

        foreach ($employeeData as $index => $data) {
            $email = strtolower($data['first_name'] . '.' . $data['last_name'] . '@catering.com');
            
            $person = Person::updateOrCreate(
                ['email' => $email],
                [
                    'first_name' => $data['first_name'],
                    'last_name' => $data['last_name'],
                    'phone' => '09' . rand(17, 18) . rand(100, 999) . rand(1000, 9999),
                    'address_line_1' => rand(1, 100) . ' ' . $data['last_name'] . ' Street',
                    'city' => ['Quezon City', 'Manila', 'Pasig', 'Makati', 'Taguig'][rand(0, 4)],
                    'province' => 'Metro Manila',
                    'postal_code' => rand(1000, 2000),
                    'country' => 'Philippines',
                    'birth_date' => now()->subYears(rand(22, 55))->subDays(rand(1, 365)),
                    'gender' => ['male', 'female'][rand(0, 1)],
                    'civil_status' => ['single', 'married'][rand(0, 1)],
                ]
            );

            $user = User::updateOrCreate(
                ['username' => strtolower($data['first_name'] . $data['last_name'])],
                [
                    'person_id' => $person->person_id,
                    'password' => Hash::make('employee123'),
                    'is_active' => true,
                    'email_verified_at' => now(),
                    'last_login_at' => now()->subDays(rand(1, 30)),
                ]
            );

            if ($employeeRole) {
                $user->roles()->syncWithoutDetaching([$employeeRole->role_id]);
            }

            Employee::updateOrCreate(
                ['employee_code' => 'EMP-' . str_pad($index + 1, 3, '0', STR_PAD_LEFT)],
                [
                    'person_id' => $person->person_id,
                    'user_id' => $user->user_id,
                    'department_id' => $data['department_id'],
                    'position_id' => $data['position_id'],
                    'employee_code' => 'EMP-' . str_pad($index + 1, 3, '0', STR_PAD_LEFT),
                    'hire_date' => now()->subYears(rand(1, 10))->subMonths(rand(0, 11))->subDays(rand(1, 28)),
                    'hourly_rate' => $data['hourly_rate'],
                    'status' => $data['status'],
                    'sss_number' => 'SSS-' . rand(1000000000, 9999999999),
                    'philhealth_number' => 'PHIL-' . rand(1000000000, 9999999999),
                    'pagibig_number' => 'PAG-' . rand(1000000000, 9999999999),
                    'tin_number' => 'TIN-' . rand(1000000000, 9999999999),
                ]
            );
        }
    }
}