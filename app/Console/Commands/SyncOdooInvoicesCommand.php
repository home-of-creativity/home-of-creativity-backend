<?php

namespace App\Console\Commands;

use App\Actions\SyncOdooInvoices;
use Illuminate\Console\Command;

class SyncOdooInvoicesCommand extends Command
{
    protected $signature = 'odoo:sync-invoices {--full : Walk every customer invoice and drop the ones deleted in Odoo}';

    protected $description = 'Copy Odoo customer invoice states into the finance page.';

    public function handle(SyncOdooInvoices $sync): int
    {
        $result = $sync->handle((bool) $this->option('full'));
        $this->line(sprintf('synced=%s changed=%d removed=%d', $result['synced'] ? 'yes' : 'no', $result['changed'], $result['removed']));

        return self::SUCCESS;
    }
}
