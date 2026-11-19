<?php

namespace App\Domain\Dashboards\Models;

use App\Domain\Identity\Models\User;
use App\Domain\Shared\Database\JsonObject;
use App\Domain\Shared\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A member's dashboard preferences in one organization; only that member reads them (RLS).
 * The default dashboard must still be accessible when used, otherwise the Overview applies.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $user_id
 * @property string|null $default_dashboard_id
 * @property array<string, mixed> $sidebar_preferences
 * @property array{order?: list<string>, hidden?: list<string>} $home_cards Home card order and hidden cards (F-06 §3.2)
 */
class UserDashboardPreference extends Model
{
    use BelongsToOrganization, HasUuids;

    protected $fillable = ['default_dashboard_id', 'sidebar_preferences', 'home_cards'];

    protected function casts(): array
    {
        return ['sidebar_preferences' => JsonObject::class, 'home_cards' => JsonObject::class];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function defaultDashboard(): BelongsTo
    {
        return $this->belongsTo(Dashboard::class, 'default_dashboard_id');
    }
}
