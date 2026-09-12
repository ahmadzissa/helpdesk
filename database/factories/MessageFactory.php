<?php

namespace Database\Factories;

use App\Models\Message;
use App\Models\Ticket;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Message> */
class MessageFactory extends Factory
{
    public function definition(): array
    {
        return ['ticket_id' => Ticket::factory(), 'kind' => 'inbound', 'body' => fake()->paragraph(), 'author_email' => fake()->safeEmail()];
    }
}
