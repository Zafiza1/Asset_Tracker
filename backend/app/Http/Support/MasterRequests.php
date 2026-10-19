<?php

namespace App\Http\Support;

use App\Domain\GenericMaster\Attributes\AttributeType;
use App\Domain\Shared\Masters\MasterStatus;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

/**
 * Shared request handling for master data endpoints: listing (search, status filter,
 * whitelisted sort, pagination or a capped `all=1` list for selects) and common rules.
 */
final class MasterRequests
{
    /** Upper bound for `all=1` lists used by dropdowns. */
    public const MAX_ALL = 1000;

    /**
     * @param  array<string, string>  $sorts
     * @param  list<string>  $searchColumns
     * @return LengthAwarePaginator<int, mixed>|Collection<int, mixed>
     */
    public static function list(Builder $query, Request $request, array $sorts, string $defaultSort, array $searchColumns = ['code', 'name']): LengthAwarePaginator|Collection
    {
        $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(MasterStatus::ALL)],
            'all' => ['nullable', 'boolean'],
        ]);

        $query->when($request->query('search'), fn ($q, $term) => $q->where(function ($w) use ($term, $searchColumns) {
            foreach ($searchColumns as $column) {
                $w->orWhere($column, 'ilike', ListQuery::like($term));
            }
        }));
        // Archived records are hidden unless asked for explicitly.
        $status = $request->query('status');
        $status ? $query->where('status', $status) : $query->where('status', '!=', MasterStatus::ARCHIVED);

        ListQuery::sort($query, $request, $sorts, $defaultSort);

        return $request->boolean('all')
            ? $query->limit(self::MAX_ALL)->get()
            : $query->paginate(ListQuery::perPage($request))->withQueryString();
    }

    /** Uppercase codes before validation so uniqueness checks are case-insensitive in practice. */
    public static function normalizeCode(Request $request): void
    {
        if ($request->filled('code')) {
            $request->merge(['code' => strtoupper(trim((string) $request->input('code')))]);
        }
    }

    /**
     * Optional codes are generated from the name when left empty (see CodeGenerator).
     *
     * @return list<mixed>
     */
    public static function codeRules(int $max = 30, bool $optional = false): array
    {
        return [$optional ? 'nullable' : 'required', 'string', "max:{$max}", 'regex:/^[A-Z0-9][A-Z0-9_.-]*$/'];
    }

    /** @return array<string, list<mixed>> */
    public static function archiveRules(): array
    {
        return ['reason' => ['nullable', 'string', 'max:500']];
    }

    /**
     * Rules for creating/updating an attribute definition. The key is set at creation only;
     * semantic checks (validation keys per type, options, default) happen in the domain.
     *
     * @return array<string, list<mixed>>
     */
    public static function attributeRules(bool $creating, bool $generic): array
    {
        $required = $creating ? ['required'] : ['sometimes', 'required'];
        $rules = [
            'label' => [...$required, 'string', 'max:100'],
            'help_text' => ['sometimes', 'nullable', 'string', 'max:255'],
            'data_type' => [...$required, Rule::in(AttributeType::ALL)],
            'is_required' => ['sometimes', 'boolean'],
            'validation' => ['sometimes', 'array'],
            'options' => ['sometimes', 'nullable', 'array', 'max:'.AttributeType::MAX_OPTIONS],
            'unit_id' => ['sometimes', 'nullable', 'bail', 'uuid', Rule::exists('units', 'id')->where('status', MasterStatus::ACTIVE)],
            'default_value' => ['sometimes', 'nullable'],
            'is_searchable' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:65535'],
        ];
        if ($creating) {
            $rules['key'] = ['required', 'string', 'regex:/^[a-z][a-z0-9_]{0,49}$/', Rule::notIn(AttributeType::RESERVED_KEYS)];
        }
        if ($generic) {
            $rules['is_controlled'] = ['sometimes', 'boolean'];
            $rules['overridable'] = ['sometimes', 'array'];
            $rules['overridable.*'] = ['string'];
        }

        return $rules;
    }
}
