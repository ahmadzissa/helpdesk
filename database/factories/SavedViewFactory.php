<?php

namespace Database\Factories;

use App\Models\SavedView;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SavedView> */
class SavedViewFactory extends Factory
{
    public function definition(): array
    {
        return ['name' => fake()->words(2, true), 'tag' => null, 'filters' => []];
    }
}
