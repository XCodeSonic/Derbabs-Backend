<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Customer;
use App\Models\Person;
use App\Models\User;
use App\Models\Role;
use Illuminate\Support\Facades\Hash;
use Faker\Factory as Faker;

class CustomerSeeder extends Seeder
{
    public function run(): void
    {
        $faker = Faker::create();

        $customerRole = Role::where('slug', 'customer')->first();
        
        for ($i = 1; $i <= 20; $i++) {
            $firstName = $faker->firstName;
            $lastName = $faker->lastName;
            $email = strtolower($firstName . '.' . $lastName . '@example.com');
            
            $person = Person::updateOrCreate(
                ['email' => $email],
                [
                    'first_name' => $firstName,
                    'last_name' => $lastName,
                    'phone' => '09' . rand(17, 18) . rand(100, 999) . rand(1000, 9999),
                    'address_line_1' => rand(1, 200) . ' ' . $faker->streetName,
                    'address_line_2' => rand(1, 10) . ' Floor, ' . $faker->buildingNumber,
                    'city' => $faker->city,
                    'province' => $faker->state,
                    'postal_code' => rand(1000, 2000),
                    'country' => 'Philippines',
                ]
            );

            $user = User::updateOrCreate(
                ['username' => strtolower($firstName . $lastName)],
                [
                    'person_id' => $person->person_id,
                    'password' => Hash::make('customer123'),
                    'is_active' => true,
                    'email_verified_at' => now(),
                    'last_login_at' => now()->subDays(rand(1, 90)),
                ]
            );

            if ($customerRole) {
                $user->roles()->syncWithoutDetaching([$customerRole->role_id]);
            }

            Customer::updateOrCreate(
                ['customer_code' => 'CUST-' . str_pad($i, 4, '0', STR_PAD_LEFT)],
                [
                    'person_id' => $person->person_id,
                    'user_id' => $user->user_id,
                    'customer_code' => 'CUST-' . str_pad($i, 4, '0', STR_PAD_LEFT),
                    'loyalty_points' => rand(0, 500),
                    'tier' => ['bronze', 'silver', 'gold', 'platinum'][rand(0, 3)],
                    'is_active' => true,
                ]
            );
        }
    }
}