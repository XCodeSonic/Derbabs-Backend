<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\EventType;

class EventTypeSeeder extends Seeder
{
    public function run(): void
    {
        $eventTypes = [
            [
                'event_type_id' => 1,
                'name' => 'Wedding Reception',
                'slug' => 'wedding-reception',
                'description' => 'Full catering service for wedding celebrations',
                'is_active' => true,
            ],
            [
                'event_type_id' => 2,
                'name' => 'Corporate Event',
                'slug' => 'corporate-event',
                'description' => 'Catering for corporate meetings, seminars, and conferences',
                'is_active' => true,
            ],
            [
                'event_type_id' => 3,
                'name' => 'Birthday Party',
                'slug' => 'birthday-party',
                'description' => 'Catering for birthday celebrations of all ages',
                'is_active' => true,
            ],
            [
                'event_type_id' => 4,
                'name' => 'Anniversary',
                'slug' => 'anniversary',
                'description' => 'Catering for wedding anniversaries and special milestones',
                'is_active' => true,
            ],
            [
                'event_type_id' => 5,
                'name' => 'Family Reunion',
                'slug' => 'family-reunion',
                'description' => 'Large-scale catering for family gatherings and reunions',
                'is_active' => true,
            ],
            [
                'event_type_id' => 6,
                'name' => 'Christmas Party',
                'slug' => 'christmas-party',
                'description' => 'Holiday catering for Christmas parties and celebrations',
                'is_active' => true,
            ],
            [
                'event_type_id' => 7,
                'name' => 'New Year\'s Eve Celebration',
                'slug' => 'new-years-eve',
                'description' => 'Catering for New Year\'s Eve parties and gatherings',
                'is_active' => true,
            ],
            [
                'event_type_id' => 8,
                'name' => 'Baptism / Christening',
                'slug' => 'baptism',
                'description' => 'Catering for baptismal and christening receptions',
                'is_active' => true,
            ],
            [
                'event_type_id' => 9,
                'name' => 'Graduation Party',
                'slug' => 'graduation-party',
                'description' => 'Catering for graduation celebrations and parties',
                'is_active' => true,
            ],
            [
                'event_type_id' => 10,
                'name' => 'Retirement Party',
                'slug' => 'retirement-party',
                'description' => 'Catering for retirement celebrations and farewell events',
                'is_active' => true,
            ],
        ];

        foreach ($eventTypes as $type) {
            EventType::updateOrCreate(
                ['event_type_id' => $type['event_type_id']],
                $type
            );
        }
    }
}