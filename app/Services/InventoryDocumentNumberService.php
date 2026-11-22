<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\InventoryDocumentType;
use App\Models\InventoryDocument;
use App\Services\Support\DocumentSequenceGenerator;
use Carbon\Carbon;

class InventoryDocumentNumberService
{
    public function __construct(
        protected DocumentSequenceGenerator $sequenceGenerator,
    ) {}

    /**
     * Generate the next unique document number atomically for the given type and date.
     */
    public function generate(InventoryDocumentType|string $type, ?Carbon $date = null): string
    {
        $enum = $type instanceof InventoryDocumentType
            ? $type
            : InventoryDocumentType::tryFrom((string) $type);

        $prefix = $enum?->prefix() ?? 'DOC';
        $year = ($date ?? Carbon::now())->format('Y');

        return $this->sequenceGenerator->next(
            InventoryDocument::class,
            'document_number',
            "DOC-{$prefix}-{$year}-",
            5
        );
    }
}
