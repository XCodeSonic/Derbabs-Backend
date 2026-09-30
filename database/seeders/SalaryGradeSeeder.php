<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\SalaryGrade;

class SalaryGradeSeeder extends Seeder
{
    public function run(): void
    {
        $grades = [
            [
                'salary_grade_id' => 1,
                'grade_name' => 'Entry Level',
                'grade_code' => 'SG-001',
                'min_hourly_rate' => 50.00,
                'max_hourly_rate' => 75.00,
                'default_hourly_rate' => 60.00,
                'description' => 'Entry level positions, trainees, interns',
                'is_active' => true,
            ],
            [
                'salary_grade_id' => 2,
                'grade_name' => 'Junior Staff',
                'grade_code' => 'SG-002',
                'min_hourly_rate' => 70.00,
                'max_hourly_rate' => 100.00,
                'default_hourly_rate' => 85.00,
                'description' => 'Junior level staff with some experience',
                'is_active' => true,
            ],
            [
                'salary_grade_id' => 3,
                'grade_name' => 'Senior Staff',
                'grade_code' => 'SG-003',
                'min_hourly_rate' => 95.00,
                'max_hourly_rate' => 140.00,
                'default_hourly_rate' => 115.00,
                'description' => 'Senior staff with proven expertise',
                'is_active' => true,
            ],
            [
                'salary_grade_id' => 4,
                'grade_name' => 'Supervisor',
                'grade_code' => 'SG-004',
                'min_hourly_rate' => 130.00,
                'max_hourly_rate' => 180.00,
                'default_hourly_rate' => 155.00,
                'description' => 'Supervisory roles with team management',
                'is_active' => true,
            ],
            [
                'salary_grade_id' => 5,
                'grade_name' => 'Manager',
                'grade_code' => 'SG-005',
                'min_hourly_rate' => 170.00,
                'max_hourly_rate' => 250.00,
                'default_hourly_rate' => 210.00,
                'description' => 'Department and team managers',
                'is_active' => true,
            ],
            [
                'salary_grade_id' => 6,
                'grade_name' => 'Senior Manager',
                'grade_code' => 'SG-006',
                'min_hourly_rate' => 240.00,
                'max_hourly_rate' => 350.00,
                'default_hourly_rate' => 295.00,
                'description' => 'Senior management roles',
                'is_active' => true,
            ],
            [
                'salary_grade_id' => 7,
                'grade_name' => 'Executive',
                'grade_code' => 'SG-007',
                'min_hourly_rate' => 340.00,
                'max_hourly_rate' => 500.00,
                'default_hourly_rate' => 420.00,
                'description' => 'Executive leadership positions',
                'is_active' => true,
            ],
        ];

        foreach ($grades as $grade) {
            SalaryGrade::updateOrCreate(
                ['salary_grade_id' => $grade['salary_grade_id']],
                $grade
            );
        }
    }
}