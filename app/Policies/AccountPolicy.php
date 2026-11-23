<?php

declare(strict_types=1);

namespace App\Policies;

class AccountPolicy extends BasePolicy
{
    protected string $module = 'accounting.accounts';

    /** @var list<string> */
    protected array $lookupPermissions = [
        'accounting.settings.view',
        'accounting.settings.update',
    ];
}
