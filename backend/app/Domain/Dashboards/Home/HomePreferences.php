<?php

namespace App\Domain\Dashboards\Home;

use App\Domain\Dashboards\Models\UserDashboardPreference;
use App\Domain\Identity\Models\User;
use Illuminate\Validation\Rule;

/**
 * A member's own arrangement of the home cards (docs/factory/features/F-06 §3.2, D-19): the
 * order they put the cards in and the cards they hid. Only card keys are stored, never data,
 * so a hidden card the member later loses the permission for simply stays out of the list.
 */
final class HomePreferences
{
    /** @return array<string, list<mixed>> */
    public static function rules(): array
    {
        $keys = Rule::in(array_keys(HomeCards::CARDS));

        return [
            'order' => ['present', 'array', 'max:'.count(HomeCards::CARDS)],
            'order.*' => ['string', 'distinct', $keys],
            'hidden' => ['present', 'array', 'max:'.count(HomeCards::CARDS)],
            'hidden.*' => ['string', 'distinct', $keys],
        ];
    }

    /** @return array{order: list<string>, hidden: list<string>} */
    public function for(User $user): array
    {
        return $this->normalize($this->row($user)?->home_cards ?? []);
    }

    /**
     * @param  array{order: list<string>, hidden: list<string>}  $data
     * @return array{order: list<string>, hidden: list<string>}
     */
    public function save(User $user, array $data): array
    {
        $row = $this->row($user) ?? new UserDashboardPreference;
        $row->user_id = $user->id;
        $row->home_cards = $this->normalize($data);
        $row->save();

        return $row->home_cards;
    }

    private function row(User $user): ?UserDashboardPreference
    {
        return UserDashboardPreference::query()->where('user_id', $user->id)->first();
    }

    /**
     * Drops keys that are no longer cards (a card removed in a later release).
     *
     * @param  array<string, mixed>  $value
     * @return array{order: list<string>, hidden: list<string>}
     */
    private function normalize(array $value): array
    {
        $known = fn ($list) => array_values(array_intersect(array_unique((array) $list), array_keys(HomeCards::CARDS)));

        return ['order' => $known($value['order'] ?? []), 'hidden' => $known($value['hidden'] ?? [])];
    }
}
