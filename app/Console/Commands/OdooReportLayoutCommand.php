<?php

namespace App\Console\Commands;

use App\Services\OdooClient;
use Illuminate\Console\Command;

/**
 * Writes the quotation and invoice print layout into Odoo. Downloads no longer touch it,
 * so what this writes stays until it is run again.
 */
class OdooReportLayoutCommand extends Command
{
    protected $signature = 'odoo:report-layout';

    protected $description = 'Write the quotation and invoice print layout (cover, paper, line flow, opening letter) into Odoo';

    public function handle(OdooClient $odoo): int
    {
        if (! $odoo->configured()) {
            $this->error('Odoo is not configured.');

            return self::FAILURE;
        }

        $failed = false;
        foreach ($odoo->installReportLayout() as $step => $error) {
            if ($error === null) {
                $this->line("{$step}: ok");

                continue;
            }
            $failed = true;
            $this->error("{$step}: {$error}");
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
