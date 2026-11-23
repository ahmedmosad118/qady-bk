<?php

declare(strict_types=1);

namespace App\Services\Accounting;

use App\Enums\Accounting\JournalType;
use App\Models\Journal;
use App\Repositories\JournalRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
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

            return $this->repository->create([
                'code' => $code,
                'name' => trim((string) $data['name']),
                'type' => JournalType::from((string) $data['type'])->value,
                'is_active' => (bool) ($data['is_active'] ?? true),
            ]);
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

            return $this->repository->update($locked, [
                'code' => $code,
                'name' => trim((string) ($data['name'] ?? $locked->name)),
                'type' => isset($data['type']) ? JournalType::from((string) $data['type'])->value : $locked->type->value,
                'is_active' => (bool) ($data['is_active'] ?? $locked->is_active),
            ]);
        });
    }

    public function delete(Journal $journal): bool
    {
        return DB::transaction(function () use ($journal): bool {
            /** @var Journal $locked */
            $locked = Journal::query()->lockForUpdate()->findOrFail($journal->id);

            return $this->repository->delete($locked);
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
}
