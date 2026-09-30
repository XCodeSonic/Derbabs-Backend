<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use App\Models\Person;
use App\Models\Role;
use App\Models\Employee;
use App\Models\Customer;
use Illuminate\Support\Facades\Hash;

class UserAccountSeeder extends Seeder
{
    public function run(): void
    {
        // Create super admin role if it doesn't exist
        $superAdminRole = Role::updateOrCreate(
            ['slug' => 'super-admin'],
            [
                'name' => 'Super Administrator',
                'description' => 'Super administrator with unrestricted access',
                'is_active' => true,
            ]
        );

        // Create admin role if it doesn't exist
        $adminRole = Role::updateOrCreate(
            ['slug' => 'admin'],
            [
                'name' => 'Administrator',
                'description' => 'System administrator with full access',
                'is_active' => true,
            ]
        );

        $employeeRole = Role::updateOrCreate(
            ['slug' => 'employee'],
            [
                'name' => 'Employee',
                'description' => 'Staff employee with limited access',
                'is_active' => true,
            ]
        );

        $customerRole = Role::updateOrCreate(
            ['slug' => 'customer'],
            [
                'name' => 'Customer',
                'description' => 'Customer with booking access',
                'is_active' => true,
            ]
        );

        // Super Admin User
        $superAdminPerson = Person::updateOrCreate(
            ['email' => 'superadmin@catering.com'],
            [
                'first_name' => 'Super',
                'last_name' => 'Admin',
                'phone' => '0917-000-0000',
                'address_line_1' => '1 Super Admin Street',
                'city' => 'Quezon City',
                'province' => 'Metro Manila',
                'postal_code' => '1101',
                'country' => 'Philippines',
            ]
        );

        $superAdminUser = User::updateOrCreate(
            ['username' => 'superadmin'],
            [
                'person_id' => $superAdminPerson->person_id,
                'password' => Hash::make('superadmin123'),
                'is_active' => true,
                'email_verified_at' => now(),
                'last_login_at' => now(),
            ]
        );
        $superAdminUser->roles()->syncWithoutDetaching([$superAdminRole->role_id]);

        // Admin User
        $adminPerson = Person::updateOrCreate(
            ['email' => 'admin@catering.com'],
            [
                'first_name' => 'System',
                'last_name' => 'Administrator',
                'phone' => '0917-123-4567',
                'address_line_1' => '123 Admin Street',
                'city' => 'Quezon City',
                'province' => 'Metro Manila',
                'postal_code' => '1101',
                'country' => 'Philippines',
            ]
        );

        $adminUser = User::updateOrCreate(
            ['username' => 'admin'],
            [
                'person_id' => $adminPerson->person_id,
                'password' => Hash::make('admin123'),
                'is_active' => true,
                'email_verified_at' => now(),
                'last_login_at' => now(),
            ]
        );
        $adminUser->roles()->syncWithoutDetaching([$adminRole->role_id]);

        // Create employee users (for each employee position)
        $this->createEmployeeUsers($employeeRole);
        
        // Create customer users
        $this->createCustomerUsers($customerRole);
    }

    private function createEmployeeUsers($employeeRole): void
    {
        $employees = [
            ['first_name' => 'Juan', 'last_name' => 'Dela Cruz', 'email' => 'juan.delacruz@catering.com', 'employee_code' => 'EMP-001'],
            ['first_name' => 'Maria', 'last_name' => 'Santos', 'email' => 'maria.santos@catering.com', 'employee_code' => 'EMP-002'],
            ['first_name' => 'Jose', 'last_name' => 'Reyes', 'email' => 'jose.reyes@catering.com', 'employee_code' => 'EMP-003'],
            ['first_name' => 'Ana', 'last_name' => 'Gonzales', 'email' => 'ana.gonzales@catering.com', 'employee_code' => 'EMP-004'],
            ['first_name' => 'Carlos', 'last_name' => 'Fernandez', 'email' => 'carlos.fernandez@catering.com', 'employee_code' => 'EMP-005'],
            ['first_name' => 'Elena', 'last_name' => 'Mendoza', 'email' => 'elena.mendoza@catering.com', 'employee_code' => 'EMP-006'],
            ['first_name' => 'Ramon', 'last_name' => 'Aguilar', 'email' => 'ramon.aguilar@catering.com', 'employee_code' => 'EMP-007'],
            ['first_name' => 'Luz', 'last_name' => 'Cruz', 'email' => 'luz.cruz@catering.com', 'employee_code' => 'EMP-008'],
            ['first_name' => 'Pedro', 'last_name' => 'Ramos', 'email' => 'pedro.ramos@catering.com', 'employee_code' => 'EMP-009'],
            ['first_name' => 'Sofia', 'last_name' => 'Torres', 'email' => 'sofia.torres@catering.com', 'employee_code' => 'EMP-010'],
        ];

        foreach ($employees as $emp) {
            $person = Person::updateOrCreate(
                ['email' => $emp['email']],
                [
                    'first_name' => $emp['first_name'],
                    'last_name' => $emp['last_name'],
                    'phone' => '09' . rand(17, 18) . rand(100, 999) . rand(1000, 9999),
                    'address_line_1' => rand(1, 100) . ' Employee Street',
                    'city' => ['Quezon City', 'Manila', 'Pasig', 'Makati', 'Taguig'][rand(0, 4)],
                    'province' => 'Metro Manila',
                    'country' => 'Philippines',
                ]
            );

            $user = User::updateOrCreate(
                ['username' => strtolower($emp['first_name'] . $emp['last_name'])],
                [
                    'person_id' => $person->person_id,
                    'password' => Hash::make('employee123'),
                    'is_active' => true,
                    'email_verified_at' => now(),
                ]
            );
            $user->roles()->syncWithoutDetaching([$employeeRole->role_id]);
        }
    }

    private function createCustomerUsers($customerRole): void
    {
        $customers = [
            ['first_name' => 'Michael', 'last_name' => 'Tan', 'email' => 'michael.tan@email.com'],
            ['first_name' => 'Jennifer', 'last_name' => 'Lim', 'email' => 'jennifer.lim@email.com'],
            ['first_name' => 'David', 'last_name' => 'Garcia', 'email' => 'david.garcia@email.com'],
            ['first_name' => 'Christine', 'last_name' => 'Chua', 'email' => 'christine.chua@email.com'],
            ['first_name' => 'Mark', 'last_name' => 'Villanueva', 'email' => 'mark.villanueva@email.com'],
            ['first_name' => 'Rebecca', 'last_name' => 'Ocampo', 'email' => 'rebecca.ocampo@email.com'],
            ['first_name' => 'Joseph', 'last_name' => 'Martinez', 'email' => 'joseph.martinez@email.com'],
            ['first_name' => 'Patricia', 'last_name' => 'Bautista', 'email' => 'patricia.bautista@email.com'],
            ['first_name' => 'Christopher', 'last_name' => 'Dizon', 'email' => 'christopher.dizon@email.com'],
            ['first_name' => 'Kimberly', 'last_name' => 'Perez', 'email' => 'kimberly.perez@email.com'],
            ['first_name' => 'Anthony', 'last_name' => 'Reyes', 'email' => 'anthony.reyes@email.com'],
            ['first_name' => 'Michelle', 'last_name' => 'Santos', 'email' => 'michelle.santos@email.com'],
        ];

        foreach ($customers as $cust) {
            $person = Person::updateOrCreate(
                ['email' => $cust['email']],
                [
                    'first_name' => $cust['first_name'],
                    'last_name' => $cust['last_name'],
                    'phone' => '09' . rand(17, 18) . rand(100, 999) . rand(1000, 9999),
                    'address_line_1' => rand(1, 200) . ' Customer Avenue',
                    'city' => ['Quezon City', 'Manila', 'Pasig', 'Makati', 'Taguig', 'Mandaluyong'][rand(0, 5)],
                    'province' => 'Metro Manila',
                    'country' => 'Philippines',
                ]
            );

            $user = User::updateOrCreate(
                ['username' => strtolower($cust['first_name'] . $cust['last_name'])],
                [
                    'person_id' => $person->person_id,
                    'password' => Hash::make('customer123'),
                    'is_active' => true,
                    'email_verified_at' => now(),
                ]
            );
            $user->roles()->syncWithoutDetaching([$customerRole->role_id]);
        }
    }
}