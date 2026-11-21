<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Accounting\ContactAccountService;
use Database\Seeders\ChartOfAccountSeeder;
use Illuminate\Console\Command;

class BackfillContactAccountsCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'accounting:backfill-contact-accounts
                            {--seed-defaults : Ensure default Chart of Accounts and Accounting Settings exist before backfilling}';

    /**
     * @var string
     */
    protected $description = 'Safely create missing receivable and payable sub-accounts for existing contacts.';

    public function handle(ContactAccountService $contactAccountService, ChartOfAccountSeeder $seeder): int
    {
        if ($this->option('seed-defaults')) {
            $seeder->run();
            $this->info('Ensured default Chart of Accounts and Accounting Settings.');
        }

        $stats = $contactAccountService->backfillMissingAccounts();

        $this->info(sprintf(
            'Backfill complete. Processed: %d, Receivable created: %d, Payable created: %d, Skipped: %d.',
            $stats['processed'],
            $stats['created_receivable'],
            $stats['created_payable'],
            $stats['skipped'],
        ));

        return self::SUCCESS;
    }
}
