<?php

namespace App\Console\Commands;

use App\Services\OdooClient;
use Illuminate\Console\Command;
use Throwable;

/**
 * Sets the document numbers in Odoo once: quotations stay QN, confirmed orders become S,
 * the proforma prints PF, and invoices take the INV prefix (earlier ones renamed with
 * their numbers, the next continuing the count). Posted invoices get their issued PDF so
 * they print as invoices, «دفع فوري» falls due on the invoice date, and the invoice print
 * drops the payment communication line. New confirmations are renamed as they happen
 * (`confirmSaleOrder` and `odoo:sync-quotations`).
 */
class OdooDocumentNumbersCommand extends Command
{
    protected $signature = 'odoo:document-numbers {--dry-run : Show what would change and write nothing}';

    protected $description = 'Quotation QN, sales order S, proforma PF, invoice INV in Odoo';

    public function handle(OdooClient $odoo): int
    {
        if (! $odoo->configured()) {
            $this->error('Odoo is not configured.');

            return self::FAILURE;
        }
        $dry = (bool) $this->option('dry-run');
        $failed = false;

        try {
            if (! $dry) {
                $odoo->installDocumentNumberView();
            }
            $this->line('print: proforma '.OdooClient::PROFORMA_PREFIX.', confirmed order titled «رقم أمر البيع»'.($dry ? ' (dry run)' : ''));
        } catch (Throwable $exception) {
            $failed = true;
            $this->error('print: '.$exception->getMessage());
        }

        try {
            $journal = $odoo->useInvoicePrefix($dry);
            $this->line("invoices: journal code {$journal['from']} → {$journal['to']}".($dry ? ' (dry run)' : ''));
            foreach ($odoo->renameInvoicesFromCode($journal['from'], $dry) as $row) {
                $this->line("invoice {$row['from']} → {$row['to']}".($dry ? ' (dry run)' : ''));
            }
            $issued = $odoo->issueMissingInvoiceDocuments($dry);
            $this->line('invoices issued as invoices, not proforma: '.count($issued).($dry ? ' (dry run)' : ''));
        } catch (Throwable $exception) {
            $failed = true;
            $this->error('invoices: '.$exception->getMessage());
        }

        try {
            $terms = $odoo->makeImmediateTermsImmediate($dry);
            $this->line('immediate payment: due on the invoice date for '.count($terms['invoices']).' open invoices'.($dry ? ' (dry run)' : ''));
            if (! $dry) {
                $odoo->installInvoicePrintView();
            }
            $this->line('invoice print: no payment communication line'.($dry ? ' (dry run)' : ''));
        } catch (Throwable $exception) {
            $failed = true;
            $this->error('invoice print: '.$exception->getMessage());
        }

        try {
            $renamed = $odoo->renameConfirmedOrders(null, $dry);
            foreach ($renamed as $row) {
                $this->line("order {$row['from']} → {$row['to']}".($dry ? ' (dry run)' : ''));
            }
            $this->line('orders renamed: '.count($renamed));
        } catch (Throwable $exception) {
            $failed = true;
            $this->error('orders: '.$exception->getMessage());
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
