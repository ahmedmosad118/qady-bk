<?php

declare(strict_types=1);

namespace App\Models\Concerns;

trait HasPurchaseAttachment
{
    public function hasAttachment(): bool
    {
        return ! empty($this->attachment_path);
    }

    public function getHasAttachmentAttribute(): bool
    {
        return $this->hasAttachment();
    }
}
