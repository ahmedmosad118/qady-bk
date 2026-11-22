<?php

declare(strict_types=1);

namespace App\Services\Expense;

use App\Models\Expense;
use App\Models\ExpenseItem;
use App\Models\ExpenseItemAttachment;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExpenseAttachmentService
{
    public const DISK = 'local';

    /**
     * Store uploaded files for an expense item on the private disk and record metadata.
     *
     * @param  array<int, UploadedFile>  $files
     * @param  array<int, array{disk: string, path: string}>  $trackedPaths
     * @return array<int, ExpenseItemAttachment>
     */
    public function storeFiles(
        Expense $expense,
        ExpenseItem $item,
        array $files,
        User $user,
        array &$trackedPaths = []
    ): array {
        $insertRows = [];
        $insertedPaths = [];
        $now = now();

        foreach ($files as $file) {
            if (! $file instanceof UploadedFile) {
                continue;
            }

            $directory = "expenses/{$expense->id}/items/{$item->id}";
            $extension = strtolower($file->getClientOriginalExtension() ?: $file->extension() ?: 'bin');
            $filename = Str::uuid()->toString().'.'.$extension;
            $path = $file->storeAs($directory, $filename, self::DISK);

            if ($path === false) {
                throw new \RuntimeException('Failed to store attachment file.');
            }

            $trackedPaths[] = [
                'disk' => self::DISK,
                'path' => $path,
            ];
            $insertedPaths[] = $path;

            $originalName = Str::limit(
                basename((string) $file->getClientOriginalName()),
                255,
                ''
            );

            $insertRows[] = [
                'expense_item_id' => $item->id,
                'disk' => self::DISK,
                'path' => $path,
                'original_name' => $originalName !== '' ? $originalName : $filename,
                'mime_type' => (string) ($file->getMimeType() ?: $file->getClientMimeType() ?: 'application/octet-stream'),
                'size' => (int) ($file->getSize() ?: 0),
                'created_by' => $user->id,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if ($insertRows === []) {
            return [];
        }

        ExpenseItemAttachment::query()->insert($insertRows);

        return $item->attachments()
            ->whereIn('path', $insertedPaths)
            ->orderBy('id')
            ->get()
            ->all();
    }

    /**
     * Clean up newly uploaded files if a database transaction rolls back.
     *
     * @param  array<int, array{disk: string, path: string}>  $trackedPaths
     */
    public function deleteStoredFiles(array $trackedPaths): void
    {
        foreach ($trackedPaths as $entry) {
            if (! empty($entry['path'])) {
                Storage::disk($entry['disk'] ?? self::DISK)->delete($entry['path']);
            }
        }
    }

    /**
     * Schedule physical deletion of attachment files after the active DB transaction commits.
     *
     * @param  iterable<int, ExpenseItemAttachment>  $attachments
     */
    public function schedulePhysicalDeletion(iterable $attachments): void
    {
        $targets = [];

        foreach ($attachments as $attachment) {
            $targets[] = [
                'disk' => $attachment->disk ?: self::DISK,
                'path' => $attachment->path,
            ];
        }

        if ($targets === []) {
            return;
        }

        $cleanup = function () use ($targets): void {
            foreach ($targets as $target) {
                if (! empty($target['path'])) {
                    Storage::disk($target['disk'])->delete($target['path']);
                }
            }
        };

        if (DB::transactionLevel() > 0) {
            DB::afterCommit($cleanup);
        } else {
            $cleanup();
        }
    }

    /**
     * Stream an attachment file inline or as a download after verifying existence.
     */
    public function streamResponse(ExpenseItemAttachment $attachment, bool $download = false): StreamedResponse
    {
        $disk = $attachment->disk ?: self::DISK;

        if (! Storage::disk($disk)->exists($attachment->path)) {
            abort(404, __('expenses.errors.attachment_not_found'));
        }

        $safeName = str_replace(['"', "\r", "\n", '/', '\\'], '_', $attachment->original_name);

        if ($download) {
            return Storage::disk($disk)->download($attachment->path, $safeName, [
                'X-Content-Type-Options' => 'nosniff',
            ]);
        }

        return Storage::disk($disk)->response($attachment->path, $safeName, [
            'Content-Type' => $attachment->mime_type ?: 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
