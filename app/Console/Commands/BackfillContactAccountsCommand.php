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
                            {--seed-defaults : Ensure default Chart of Accounts and Accounting Settings exist before backfilling}
                            {--prune-unneeded : Unlink and delete contact accounts that do not match each contact\'s current active roles}';

    /**
     * @var string
     */
    protected $description = 'Safely create missing role-appropriate receivable/payable accounts for existing contacts.';

    public function handle(ContactAccountService $contactAccountService, ChartOfAccountSeeder $seeder): int
    {
        if ($this->option('seed-defaults')) {
            $seeder->run();
            $this->info('Ensured default Chart of Accounts and Accounting Settings.');
        }

        if ($this->option('prune-unneeded')) {
            $pruneStats = $contactAccountService->pruneUnneededAccounts();
            $this->info(sprintf(
                'Pruned unneeded accounts. Contacts updated: %d, Receivable removed: %d, Payable removed: %d.',
                $pruneStats['contacts_updated'],
                $pruneStats['pruned_receivable'],
                $pruneStats['pruned_payable'],
            ));
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
