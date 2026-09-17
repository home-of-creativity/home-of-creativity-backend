<?php

namespace App\Console\Commands;

use App\Actions\PurgeOdooCrmCustomers;
use Illuminate\Console\Command;
use Throwable;

class PurgeOdooCrmCommand extends Command
{
    protected $signature = 'odoo:purge-crm {--force : Skip the confirmation prompt}';

    protected $description = 'Delete all Odoo CRM leads and customer partners, then keep Telegram as the intake stage.';

    public function handle(PurgeOdooCrmCustomers $purgeOdooCrmCustomers): int
    {
        if (! $this->option('force') && ! $this->confirm('Delete every CRM lead and customer partner in Odoo?')) {
            return self::SUCCESS;
        }

        try {
            $result = $purgeOdooCrmCustomers->handle();
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info('Odoo CRM customers purged.');
        $this->line('Leads deleted: '.$result['leads']);
        $this->line('Partners deleted: '.$result['partners']);
        $this->line('Local Odoo ids cleared: '.$result['local_clients']);

        return self::SUCCESS;
    }
}
