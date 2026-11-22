<?php

declare(strict_types=1);

namespace App\Enums;

enum StocktakeVarianceDirection: string
{
    case INCREASE = 'increase';
    case DECREASE = 'decrease';
    case EXACT = 'exact';
}
