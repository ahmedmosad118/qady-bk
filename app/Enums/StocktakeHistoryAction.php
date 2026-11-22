<?php

declare(strict_types=1);

namespace App\Enums;

enum StocktakeHistoryAction: string
{
    case CREATED = 'created';
    case STARTED = 'started';
    case START_COUNTING = 'start_counting';
    case RECORDED_COUNT = 'recorded_count';
    case CANCEL_COUNT = 'cancel_count';
    case RECOUNT = 'recount';
    case SUBMITTED = 'submitted';
    case APPROVED = 'approved';
    case APPROVED_AND_COMPLETED = 'approved_and_completed';
    case REJECTED = 'rejected';
    case CANCELLED = 'cancelled';
    case COMPLETED = 'completed';
}
