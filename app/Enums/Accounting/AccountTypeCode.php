<?php

declare(strict_types=1);

namespace App\Enums\Accounting;

enum AccountTypeCode: string
{
    case Receivable = 'receivable';
    case BankCash = 'bank_cash';
    case CurrentAssets = 'current_assets';
    case NonCurrentAssets = 'non_current_assets';
    case Prepayments = 'prepayments';
    case FixedAssets = 'fixed_assets';
    case Payable = 'payable';
    case CreditCard = 'credit_card';
    case CurrentLiabilities = 'current_liabilities';
    case NonCurrentLiabilities = 'non_current_liabilities';
    case Equity = 'equity';
    case CurrentYearEarnings = 'current_year_earnings';
    case Income = 'income';
    case OtherIncome = 'other_income';
    case Expenses = 'expenses';
    case Depreciation = 'depreciation';
    case CostOfRevenue = 'cost_of_revenue';
    case OffBalance = 'off_balance';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
