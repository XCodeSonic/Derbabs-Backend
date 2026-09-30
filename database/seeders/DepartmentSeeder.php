<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Department;
use Illuminate\Support\Str;

class DepartmentSeeder extends Seeder
{
    public function run(): void
    {
        $departments = [
            [
                'department_id' => 1,
                'name' => 'Executive Management',
                'code' => 'EXEC',
                'description' => 'Overall business strategy and leadership',
                'is_active' => true,
            ],
            [
                'department_id' => 2,
                'name' => 'Operations',
                'code' => 'OPS',
                'description' => 'Day-to-day catering operations management',
                'is_active' => true,
            ],
            [
                'department_id' => 3,
                'name' => 'Kitchen & Culinary',
                'code' => 'KIT',
                'description' => 'Food preparation and kitchen management',
                'is_active' => true,
            ],
            [
                'department_id' => 4,
                'name' => 'Events & Planning',
                'code' => 'EVT',
                'description' => 'Event planning and coordination',
                'is_active' => true,
            ],
            [
                'department_id' => 5,
                'name' => 'Sales & Marketing',
                'code' => 'S&M',
                'description' => 'Sales, promotions, and customer acquisition',
                'is_active' => true,
            ],
            [
                'department_id' => 6,
                'name' => 'Finance & Accounting',
                'code' => 'FIN',
                'description' => 'Financial management and reporting',
                'is_active' => true,
            ],
            [
                'department_id' => 7,
                'name' => 'Human Resources',
                'code' => 'HR',
                'description' => 'Employee management and recruitment',
                'is_active' => true,
            ],
            [
                'department_id' => 8,
                'name' => 'Logistics & Delivery',
                'code' => 'LOG',
                'description' => 'Transportation and delivery coordination',
                'is_active' => true,
            ],
            [
                'department_id' => 9,
                'name' => 'Inventory & Procurement',
                'code' => 'INV',
                'description' => 'Inventory management and purchasing',
                'is_active' => true,
            ],
            [
                'department_id' => 10,
                'name' => 'Maintenance & Facilities',
                'code' => 'MNT',
                'description' => 'Equipment maintenance and facility management',
                'is_active' => true,
            ],
        ];

        foreach ($departments as $department) {
            Department::updateOrCreate(
                ['department_id' => $department['department_id']],
                $department
            );
        }
    }
}