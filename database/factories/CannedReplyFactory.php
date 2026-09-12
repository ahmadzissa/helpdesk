<?php

namespace Database\Factories;

use App\Models\CannedReply;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CannedReply> */
class CannedReplyFactory extends Factory
{
    public function definition(): array
    {
        return ['title' => fake()->sentence(), 'shortcut' => '#'.fake()->unique()->word(), 'category' => 'General', 'body' => 'Hello {{name}}, your ticket is #{{ticket_id}}.'];
    }
}
