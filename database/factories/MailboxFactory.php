<?php

namespace Database\Factories;

use App\Models\Mailbox;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Mailbox> */
class MailboxFactory extends Factory
{
    public function definition(): array
    {
        return ['name' => fake()->company(), 'email' => fake()->unique()->safeEmail(), 'color' => '#7450bb', 'sending_enabled' => false];
    }
}
