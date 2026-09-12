<?php

namespace Database\Factories;

use App\Models\Ticket;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Ticket> */
class TicketFactory extends Factory
{
    public function definition(): array
    {
        return ['subject' => fake()->sentence(), 'requester_name' => fake()->name(), 'requester_email' => fake()->safeEmail(), 'status' => 'Open', 'priority' => 'Normal', 'source' => 'Email', 'folder' => 'inbox', 'tags' => [], 'cc' => [], 'custom_fields' => [], 'unread' => true, 'last_activity_at' => now()];
    }
}
