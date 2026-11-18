<?php

namespace App\Http\Controllers\Tenant;

use App\Domain\AssetTransactions\Models\TransactionAttachment;
use App\Domain\AssetTransactions\Models\TransactionTypeDefinition;
use App\Domain\AssetTransactions\Services\TransactionAttachments;
use App\Domain\Shared\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Files of attachment fields (F-07 §3.2): upload for one field of a type, then send the returned
 * id as the field's value; download when the transaction holding it is visible to the user.
 */
class TransactionAttachmentController extends Controller
{
    public function __construct(private readonly TransactionAttachments $attachments) {}

    public function store(Request $request, TransactionTypeDefinition $transactionType): JsonResponse
    {
        $data = $request->validate([
            'field_key' => ['required', 'string', 'max:40'],
            'file' => ['required', 'file'],
        ], ['file.required' => 'Pilih file.']);

        $attachment = $this->attachments->upload($transactionType, $data['field_key'], $request->file('file'));

        return response()->json(['data' => $attachment->summary()], 201);
    }

    /** Always a download, never shown inline (an uploaded file is not trusted as a page). */
    public function show(TransactionAttachment $transactionAttachment): StreamedResponse
    {
        $this->attachments->assertReadable($transactionAttachment);
        $disk = Storage::disk(TransactionAttachments::DISK);
        if (! $disk->exists($transactionAttachment->path)) {
            throw new ApiException('NOT_FOUND', 'File tidak ditemukan.', 404);
        }

        return $disk->download($transactionAttachment->path, $transactionAttachment->original_name, ['X-Content-Type-Options' => 'nosniff']);
    }
}
