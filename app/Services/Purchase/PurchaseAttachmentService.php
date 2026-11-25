<?php

declare(strict_types=1);

namespace App\Services\Purchase;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PurchaseAttachmentService
{
    public const DISK = 'local';

    /**
     * Store an uploaded document attachment on the private disk and return column attributes.
     *
     * @param  array<int, array{disk: string, path: string}>  $trackedPaths
     * @return array{
     *     attachment_disk: string,
     *     attachment_path: string,
     *     attachment_original_name: string,
     *     attachment_mime_type: string,
     *     attachment_size: int
     * }
     */
    public function storeFile(
        Model $document,
        string $folder,
        UploadedFile $file,
        array &$trackedPaths = [],
    ): array {
        $directory = "purchases/{$folder}/{$document->getKey()}";
        $extension = strtolower($file->getClientOriginalExtension() ?: $file->extension() ?: 'bin');
        $filename = Str::uuid()->toString().'.'.$extension;
        $path = $file->storeAs($directory, $filename, self::DISK);

        if ($path === false) {
            throw new RuntimeException('Failed to store purchase document attachment.');
        }

        $trackedPaths[] = [
            'disk' => self::DISK,
            'path' => $path,
        ];

        $originalName = Str::limit(
            basename((string) $file->getClientOriginalName()),
            255,
            ''
        );

        return [
            'attachment_disk' => self::DISK,
            'attachment_path' => $path,
            'attachment_original_name' => $originalName !== '' ? $originalName : $filename,
            'attachment_mime_type' => (string) ($file->getMimeType() ?: $file->getClientMimeType() ?: 'application/octet-stream'),
            'attachment_size' => (int) ($file->getSize() ?: 0),
        ];
    }

    /**
     * Apply attachment upload, replacement, or removal onto a persisted purchase document inside a transaction.
     *
     * @param  array<int, array{disk: string, path: string}>  $trackedFiles
     * @param  array<int, array{disk: string, path: string}>  $oldFilesToDelete
     */
    public function applyAttachmentUpdate(
        Model $document,
        string $folder,
        ?UploadedFile $newFile,
        bool $removeAttachment,
        array &$trackedFiles,
        array &$oldFilesToDelete,
    ): void {
        if ($newFile instanceof UploadedFile) {
            if ($existing = $this->captureTarget($document)) {
                $oldFilesToDelete[] = $existing;
            }

            $document->update($this->storeFile($document, $folder, $newFile, $trackedFiles));

            return;
        }

        if ($removeAttachment && ! empty($document->getAttribute('attachment_path'))) {
            if ($existing = $this->captureTarget($document)) {
                $oldFilesToDelete[] = $existing;
            }

            $document->update([
                'attachment_disk' => null,
                'attachment_path' => null,
                'attachment_original_name' => null,
                'attachment_mime_type' => null,
                'attachment_size' => null,
            ]);
        }
    }

    /**
     * Capture existing storage target before modifying or deleting a document.
     *
     * @return array{disk: string, path: string}|null
     */
    public function captureTarget(Model $document): ?array
    {
        $path = $document->getAttribute('attachment_path');
        if (empty($path)) {
            return null;
        }

        return [
            'disk' => (string) ($document->getAttribute('attachment_disk') ?: self::DISK),
            'path' => (string) $path,
        ];
    }

    /**
     * Immediately remove newly stored files when a database transaction fails and rolls back.
     *
     * @param  array<int, array{disk: string, path: string}>  $trackedPaths
     */
    public function deleteStoredFiles(array $trackedPaths): void
    {
        foreach ($trackedPaths as $entry) {
            if (! empty($entry['path'])) {
                Storage::disk($entry['disk'] ?: self::DISK)->delete($entry['path']);
            }
        }
    }

    /**
     * Delete superseded or removed physical files only after the active database transaction commits.
     *
     * @param  array<int, array{disk: string, path: string}>  $targets
     */
    public function schedulePhysicalDeletion(array $targets): void
    {
        $filtered = array_values(array_filter(
            $targets,
            static fn (array $target): bool => ! empty($target['path'])
        ));

        if ($filtered === []) {
            return;
        }

        $cleanup = function () use ($filtered): void {
            foreach ($filtered as $target) {
                Storage::disk($target['disk'] ?: self::DISK)->delete($target['path']);
            }
        };

        if (DB::transactionLevel() > 0) {
            DB::afterCommit($cleanup);
        } else {
            $cleanup();
        }
    }

    /**
     * Stream a document attachment inline or as a download after verifying file existence.
     */
    public function streamResponse(Model $document, bool $download = false): StreamedResponse
    {
        $disk = (string) ($document->getAttribute('attachment_disk') ?: self::DISK);
        $path = (string) ($document->getAttribute('attachment_path') ?? '');

        if ($path === '' || ! Storage::disk($disk)->exists($path)) {
            abort(404, (string) __('purchases.errors.attachment_not_found'));
        }

        $originalName = (string) ($document->getAttribute('attachment_original_name') ?: basename($path));
        $safeName = str_replace(['"', "\r", "\n", '/', '\\'], '_', $originalName);

        if ($download) {
            return Storage::disk($disk)->download($path, $safeName, [
                'X-Content-Type-Options' => 'nosniff',
            ]);
        }

        $mimeType = (string) ($document->getAttribute('attachment_mime_type') ?: 'application/octet-stream');

        return Storage::disk($disk)->response($path, $safeName, [
            'Content-Type' => $mimeType,
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
