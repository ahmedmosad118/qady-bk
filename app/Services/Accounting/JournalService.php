<?php

declare(strict_types=1);

namespace App\Services\Accounting;

use App\Enums\Accounting\JournalType;
use App\Models\Journal;
use App\Repositories\JournalRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class JournalService
{
    public function __construct(
        protected JournalRepository $repository,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     */
    public function paginate(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        return $this->repository->paginate($filters, $perPage);
    }

    /**
     * Create a new journal book.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Journal
    {
        return DB::transaction(function () use ($data): Journal {
            $code = trim((string) $data['code']);
            $this->assertUniqueCode($code);

            try {
                return $this->repository->create([
                    'code' => $code,
                    'name' => trim((string) $data['name']),
                    'type' => JournalType::from((string) $data['type'])->value,
                    'is_active' => (bool) ($data['is_active'] ?? true),
                ]);
            } catch (QueryException $e) {
                if ($this->isUniqueConstraintException($e)) {
                    throw ValidationException::withMessages([
                        'code' => [(string) __('accounting.errors.journal_code_duplicate')],
                    ]);
                }

                throw $e;
            }
        });
    }

    /**
     * Update an existing journal book.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(Journal $journal, array $data): Journal
    {
        return DB::transaction(function () use ($journal, $data): Journal {
            /** @var Journal $locked */
            $locked = Journal::query()->lockForUpdate()->findOrFail($journal->id);
            $code = trim((string) ($data['code'] ?? $locked->code));

            if ($code !== $locked->code) {
                $this->assertUniqueCode($code, $locked->id);
            }

            try {
                return $this->repository->update($locked, [
                    'code' => $code,
                    'name' => trim((string) ($data['name'] ?? $locked->name)),
                    'type' => isset($data['type']) ? JournalType::from((string) $data['type'])->value : $locked->type->value,
                    'is_active' => (bool) ($data['is_active'] ?? $locked->is_active),
                ]);
            } catch (QueryException $e) {
                if ($this->isUniqueConstraintException($e)) {
                    throw ValidationException::withMessages([
                        'code' => [(string) __('accounting.errors.journal_code_duplicate')],
                    ]);
                }

                throw $e;
            }
        });
    }

    /**
     * Safely delete an unused journal without cascading dependent records.
     */
    public function delete(Journal $journal): bool
    {
        return DB::transaction(function () use ($journal): bool {
            /** @var Journal $locked */
            $locked = Journal::query()->lockForUpdate()->findOrFail($journal->id);

            try {
                return $this->repository->delete($locked);
            } catch (QueryException $e) {
                if ($this->isForeignKeyConstraintException($e)) {
                    throw ValidationException::withMessages([
                        'journal' => [(string) __('accounting.errors.journal_in_use')],
                    ]);
                }

                throw $e;
            }
        });
    }

    private function assertUniqueCode(string $code, ?int $ignoreId = null): void
    {
        $exists = Journal::query()
            ->where('code', $code)
            ->when($ignoreId !== null, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->lockForUpdate()
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'code' => [(string) __('accounting.errors.journal_code_duplicate')],
            ]);
        }
    }

    private function isUniqueConstraintException(QueryException $e): bool
    {
        $sqlState = (string) ($e->errorInfo[0] ?? '');
        $driverCode = (int) ($e->errorInfo[1] ?? 0);
        $message = mb_strtolower($e->getMessage());

        return $driverCode === 1062
            || ($sqlState === '23000' && (str_contains($message, 'unique') || str_contains($message, 'duplicate')));
    }

    private function isForeignKeyConstraintException(QueryException $e): bool
    {
        $sqlState = (string) ($e->errorInfo[0] ?? '');
        $driverCode = (int) ($e->errorInfo[1] ?? 0);

        return $sqlState === '23000' || $driverCode === 1451 || str_contains(mb_strtolower($e->getMessage()), 'foreign key constraint');
    }
}
