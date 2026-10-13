<?php

namespace App\Console\Commands;

use App\Actions\SyncOdooQuotations;
use Illuminate\Console\Command;

class SyncOdooQuotationsCommand extends Command
{
    protected $signature = 'odoo:sync-quotations';

    protected $description = 'Bring quotations accepted or declined on the Odoo portal back to their requests.';

    public function handle(SyncOdooQuotations $sync): int
    {
        $result = $sync->handle();
        $this->line(sprintf('checked=%d approved=%d rejected=%d', $result['checked'], $result['approved'], $result['rejected']));

        return self::SUCCESS;
    }
}
