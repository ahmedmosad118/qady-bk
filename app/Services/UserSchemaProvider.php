<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\CRUD\Field;
use App\Core\CRUD\InputMaker;
use App\Models\User;

class UserSchemaProvider
{
    /**
     * Get the dynamic schema definition for User forms in the frontend.
     *
     * @return array<string, mixed>
     */
    public function getSchema(): array
    {
        return InputMaker::make()
            ->title('users.title')
            ->singularTitle('header.member')
            ->model(User::class)
            ->fields([
                Field::text('name', 'common.name')->required()->col(6),
                Field::email('email', 'auth.email')->required()->rules('email')->col(6),
                Field::tel('phone', 'users.phoneCol')->col(6),
                Field::select('status', 'common.status', [
                    ['value' => 'active', 'label' => 'common.active'],
                    ['value' => 'inactive', 'label' => 'common.inactive'],
                ])->default('active')->required()->col(6),
                Field::password('password', 'auth.password')
                    ->col(12)
                    ->help('اتركه فارغاً عند التعديل للإبقاء على كلمة المرور الحالية.'),
            ])
            ->toSchema();
    }
}
