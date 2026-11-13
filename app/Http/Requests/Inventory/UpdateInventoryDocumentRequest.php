<?php

declare(strict_types=1);

namespace App\Http\Requests\Inventory;

use App\Models\InventoryDocument;
use Illuminate\Validation\Validator;

class UpdateInventoryDocumentRequest extends StoreInventoryDocumentRequest
{
    /**
     * Extra validation: ensure the document is in editable status.
     */
    public function withValidator(Validator $validator): void
    {
        parent::withValidator($validator);

        $validator->after(function (Validator $v): void {
            /** @var InventoryDocument|null $document */
            $document = $this->route('inventory_document');
            if ($document && ! $document->isEditable()) {
                $v->errors()->add('status', __('inventory.errors.cannot_edit_non_draft_document', [
                    'status' => $document->status->value,
                ]));
            }
        });
    }
}
