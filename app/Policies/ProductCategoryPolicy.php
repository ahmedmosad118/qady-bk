<?php

declare(strict_types=1);

namespace App\Policies;

class ProductCategoryPolicy extends BasePolicy
{
    protected string $module = 'product_categories';

    /** @var list<string> */
    protected array $lookupPermissions = [
        'products.view',
        'products.create',
        'products.update',
        'pos.sell',
    ];
}
