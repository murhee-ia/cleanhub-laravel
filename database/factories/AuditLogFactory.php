<?php

namespace Database\Factories;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AuditLog>
 */
class AuditLogFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'action' => fake()->randomElement([
                'report.resolved',
                'content.hidden',
                'user.suspended',
                'user.role_changed',
            ]),
            'auditable_type' => null,
            'auditable_id' => null,
            'context' => null,
        ];
    }
}
