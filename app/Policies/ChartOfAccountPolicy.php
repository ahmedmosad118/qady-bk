<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;

class ChartOfAccountPolicy extends BasePolicy
{
    protected string $module = 'accounting.accounts';

    /** @var list<string> */
    protected array $lookupPermissions = [
        'accounting.settings.view',
        'accounting.settings.update',
        'contacts.create',
        'contacts.update',
    ];

    public function tree(User $user): bool
    {
        return $this->canAny($user, ['accounting.accounts.view']);
    }
}
