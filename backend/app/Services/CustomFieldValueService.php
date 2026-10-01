<?php

namespace App\Services;

use App\Models\CustomField;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class CustomFieldValueService
{
    /** Apply configured defaults and validate the asset metadata for one project. */
    public function validateAssetMetadata(int $projectId, array $metadata, array $existing = []): array
    {
        $values = array_merge($existing, $metadata);
        $fields = CustomField::where('project_id', $projectId)->where('entity_type', 'asset')->where('active', true)->orderBy('sort_order')->get();
        $rules = [];
        foreach ($fields as $field) {
            if (!array_key_exists($field->key, $values) && $field->default_value !== null) $values[$field->key] = $field->default_value;
            $rule = [$field->required ? 'required' : 'nullable'];
            $rule[] = match ($field->type) {
                'number' => 'integer', 'decimal' => 'numeric', 'boolean' => 'boolean',
                'date' => 'date', 'datetime' => 'date', 'select' => 'string',
                'multiselect' => 'array', 'json' => 'array', 'relation' => 'integer',
                default => 'string',
            };
            if (in_array($field->type, ['select', 'multiselect'], true) && is_array($field->options)) {
                $allowed = array_values($field->options);
                $rule[] = $field->type === 'select' ? 'in:'.implode(',', $allowed) : null;
                if ($field->type === 'multiselect') $rules['metadata.'.$field->key.'.*'] = ['in:'.implode(',', $allowed)];
            }
            foreach (['min', 'max'] as $constraint) if (isset($field->validation[$constraint]) && is_numeric($field->validation[$constraint])) $rule[] = $constraint.':'.$field->validation[$constraint];
            $rules['metadata.'.$field->key] = array_values(array_filter($rule));
        }
        $validator = Validator::make(['metadata' => $values], $rules);
        if ($validator->fails()) throw new ValidationException($validator);
        return $values;
    }
}
