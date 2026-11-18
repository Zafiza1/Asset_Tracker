<?php

namespace App\Domain\AssetTransactions\Models;

use App\Domain\Shared\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * A file of an attachment field (F-07 §3.2). Uploaded before the form is sent, then referenced
 * by id in the field value and claimed by the run or transaction number that stores it.
 *
 * @property string $id
 * @property string $type_definition_id
 * @property string $field_key
 * @property string $path
 * @property string $original_name
 * @property string $mime_type
 * @property int $size
 * @property string $uploaded_by
 * @property string|null $run_id
 * @property string|null $transaction_number
 */
class TransactionAttachment extends Model
{
    use BelongsToOrganization, HasUuids;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'claimed_at' => 'datetime',
        ];
    }

    /** @return array{id: string, name: string, size: int, mime_type: string} */
    public function summary(): array
    {
        return ['id' => $this->id, 'name' => $this->original_name, 'size' => $this->size, 'mime_type' => $this->mime_type];
    }
}
