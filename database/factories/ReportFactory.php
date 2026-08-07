<?php

namespace Database\Factories;

use App\Enums\ReportStatus;
use App\Models\CleaningJobPost;
use App\Models\Rating;
use App\Models\Report;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;

/**
 * @extends Factory<Report>
 */
class ReportFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'reporter_id' => User::factory(),
            'reportable_type' => (new CleaningJobPost)->getMorphClass(),
            'reportable_id' => CleaningJobPost::factory(),
            'reason' => fake()->sentence(),
            'status' => ReportStatus::Open,
        ];
    }

    /**
     * Point the report at a specific model, resolving its morph alias so the
     * stored type matches the enforced morph map. Named `targeting` rather
     * than `for` so it never shadows Eloquent's built-in relationship
     * `Factory::for()`.
     */
    public function targeting(Model $reportable): static
    {
        return $this->state(fn (array $attributes) => [
            'reportable_type' => $reportable->getMorphClass(),
            'reportable_id' => $reportable->getKey(),
        ]);
    }

    public function aboutUser(): static
    {
        return $this->state(fn (array $attributes) => [
            'reportable_type' => (new User)->getMorphClass(),
            'reportable_id' => User::factory(),
        ]);
    }

    public function aboutRating(): static
    {
        return $this->state(fn (array $attributes) => [
            'reportable_type' => (new Rating)->getMorphClass(),
            'reportable_id' => Rating::factory(),
        ]);
    }

    public function status(ReportStatus $status): static
    {
        return $this->state(fn (array $attributes) => ['status' => $status]);
    }
}
