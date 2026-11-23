<?php

namespace Database\Factories;

use App\Models\Household;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Household> */
class HouseholdFactory extends Factory
{
    protected $model = Household::class;

    public function definition(): array
    {
        return ['name' => 'Family '.fake()->lastName(), 'city' => fake()->city(), 'locale' => 'de'];
    }
}
