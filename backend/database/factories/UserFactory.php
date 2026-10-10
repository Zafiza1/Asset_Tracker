<?php

namespace Database\Factories;

use App\Domain\Identity\Models\User;
use App\Domain\Organization\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected $model = User::class;

    public const PASSWORD = 'Rahasia-Uji-123';

    protected static ?string $passwordHash = null;

    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'password' => static::$passwordHash ??= Hash::make(self::PASSWORD),
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (User $user) {
            // user_type, organization_id and status are guarded on purpose (not mass assignable).
            $user->user_type ??= User::TYPE_TENANT;
            $user->status ??= User::STATUS_ACTIVE;
            if ($user->user_type === User::TYPE_TENANT && $user->organization_id === null) {
                $user->organization_id = Organization::factory()->create()->id;
            }
        });
    }

    public function forOrganization(Organization|string $organization): static
    {
        $id = $organization instanceof Organization ? $organization->id : $organization;

        return $this->afterMaking(fn (User $u) => $u->organization_id = $id);
    }

    public function platform(): static
    {
        return $this->afterMaking(function (User $u) {
            $u->user_type = User::TYPE_PLATFORM;
            $u->organization_id = null;
        });
    }

    public function status(string $status): static
    {
        return $this->afterMaking(fn (User $u) => $u->status = $status);
    }
}
