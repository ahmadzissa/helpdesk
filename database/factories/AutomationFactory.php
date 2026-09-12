<?php

namespace Database\Factories;

use App\Models\Automation;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Automation> */
class AutomationFactory extends Factory
{
    public function definition(): array
    {
        return ['name' => fake()->sentence(), 'enabled' => true, 'trigger' => 'ticket.created', 'conditions' => [], 'actions' => ['priority' => 'High']];
    }
}
