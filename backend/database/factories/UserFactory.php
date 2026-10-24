<?php

namespace Database\Factories;

use App\Domain\Identity\Models\OrganizationMember;
use App\Domain\Identity\Models\User;
use App\Domain\Organization\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use WeakMap;

/**
 * Tenant users get an active membership in the organization given with forOrganization()
 * (or in a new organization when none is given).
 *
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected $model = User::class;

    public const PASSWORD = 'Rahasia-Uji-123';

    protected static ?string $passwordHash = null;

    /** @var WeakMap<User, string>|null organization chosen per made model, consumed after creation */
    private static ?WeakMap $organizations = null;

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
            // user_type and status are guarded on purpose (not mass assignable).
            $user->user_type ??= User::TYPE_TENANT;
            $user->status ??= User::STATUS_ACTIVE;
        })->afterCreating(function (User $user) {
            if (! $user->isTenantUser()) {
                return;
            }
            $organizationId = self::organizations()[$user] ?? Organization::factory()->create()->id;
            (new OrganizationMember)->forceFill([
                'organization_id' => $organizationId,
                'user_id' => $user->id,
                'status' => User::STATUS_ACTIVE,
            ])->save();
        });
    }

    public function forOrganization(Organization|string $organization): static
    {
        $id = $organization instanceof Organization ? $organization->id : $organization;

        return $this->afterMaking(fn (User $u) => self::organizations()[$u] = $id);
    }

    public function platform(): static
    {
        return $this->afterMaking(fn (User $u) => $u->user_type = User::TYPE_PLATFORM);
    }

    /** The owner has proven the email address (required to accept invitations). */
    public function verified(): static
    {
        return $this->afterMaking(fn (User $u) => $u->email_verified_at = now());
    }

    public function status(string $status): static
    {
        return $this->afterMaking(fn (User $u) => $u->status = $status);
    }

    /** @return WeakMap<User, string> */
    private static function organizations(): WeakMap
    {
        return self::$organizations ??= new WeakMap;
    }
}
