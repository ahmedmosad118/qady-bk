<?php

declare(strict_types=1);

namespace App\Http\Requests\Excel;

use App\Enums\ExcelModuleType;
use App\Services\Excel\ExcelModuleRegistry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class ImportExcelRequest extends FormRequest
{
    public function authorize(ExcelModuleRegistry $registry): bool
    {
        $type = $this->route('type');

        if (! $type instanceof ExcelModuleType) {
            return false;
        }

        $handler = $registry->resolve($type);

        return Gate::allows($handler->importAbility(), $handler->modelClass());
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'excel_file' => [
                'required',
                'file',
                'mimes:xlsx,xls',
                'max:10240',
            ],
        ];
    }
}
