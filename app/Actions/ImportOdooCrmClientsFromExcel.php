<?php

namespace App\Actions;

use App\Services\OdooClient;
use App\Support\XlsxReader;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class ImportOdooCrmClientsFromExcel
{
    /** @var array<string, int|null> */
    private array $stageIds = [];

    public function __construct(
        private OdooClient $odoo,
        private XlsxReader $xlsxReader,
        private ImportOdooCrmClients $importOdooCrmClients,
    ) {}

    /**
     * @return array{
     *     parsed: int,
     *     skipped: int,
     *     created_in_odoo: int,
     *     duplicates: int,
     *     failed: int,
     *     imported: int,
     *     created: int,
     *     updated: int,
     *     crm_leads: int,
     *     partners: int
     * }
     */
    public function handle(string $path): array
    {
        if (! $this->odoo->configured()) {
            throw new RuntimeException('Odoo is not configured.');
        }

        $rows = $this->xlsxReader->rows($path);
        if ($rows === []) {
            throw new RuntimeException('The Excel file is empty.');
        }

        $headers = array_map(static fn (string $value): string => trim($value), array_shift($rows));
        $columns = $this->resolveColumns($headers);

        $parsed = 0;
        $skipped = 0;
        $createdInOdoo = 0;
        $duplicates = 0;
        $failed = 0;

        foreach ($rows as $row) {
            $record = $this->mapRow($columns, $row);
            if ($record === null) {
                $skipped++;

                continue;
            }

            $parsed++;

            try {
                if ($this->odoo->findCrmLead($record['name'], $record['partner_name']) !== null) {
                    $duplicates++;

                    continue;
                }

                $this->odoo->createCrmLead($record);
                $createdInOdoo++;
            } catch (\Throwable $exception) {
                $failed++;
                Log::warning('Odoo CRM Excel import skipped a row.', [
                    'name' => $record['name'],
                    'partner_name' => $record['partner_name'],
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        $sync = $this->importOdooCrmClients->handle(max($createdInOdoo + $duplicates, 200));

        return [
            'parsed' => $parsed,
            'skipped' => $skipped,
            'created_in_odoo' => $createdInOdoo,
            'duplicates' => $duplicates,
            'failed' => $failed,
            'imported' => $sync['imported'],
            'created' => $sync['created'],
            'updated' => $sync['updated'],
            'crm_leads' => $sync['crm_leads'],
            'partners' => $sync['partners'],
        ];
    }

    /**
     * @param  list<string>  $headers
     * @return array{name: int, stage: int|null, contact_name: int|null, partner_name: int|null}
     */
    private function resolveColumns(array $headers): array
    {
        $lookup = static function (array $candidates) use ($headers): ?int {
            foreach ($candidates as $candidate) {
                $index = array_search($candidate, $headers, true);
                if ($index !== false) {
                    return (int) $index;
                }
            }

            return null;
        };

        $nameIndex = $lookup(['الفرصة', 'name', 'Opportunity']);
        if ($nameIndex === null) {
            throw new RuntimeException('The Excel file is missing the required "الفرصة" column.');
        }

        return [
            'name' => $nameIndex,
            'stage' => $lookup(['المرحلة', 'stage_id', 'Stage']),
            'contact_name' => $lookup(['اسم جهة الاتصال', 'contact_name', 'Contact Name']),
            'partner_name' => $lookup(['اسم الشركة', 'partner_name', 'Company Name']),
        ];
    }

    /**
     * @param  array{name: int, stage: int|null, contact_name: int|null, partner_name: int|null}  $columns
     * @param  list<string>  $row
     * @return array{name: string, contact_name: string|null, partner_name: string|null, stage_id: int|null}|null
     */
    private function mapRow(array $columns, array $row): ?array
    {
        $name = trim((string) ($row[$columns['name']] ?? ''));
        $stage = $columns['stage'] !== null ? trim((string) ($row[$columns['stage']] ?? '')) : '';
        $contactName = $columns['contact_name'] !== null
            ? trim((string) ($row[$columns['contact_name']] ?? ''))
            : '';
        $partnerName = $columns['partner_name'] !== null
            ? trim((string) ($row[$columns['partner_name']] ?? ''))
            : '';

        if ($name === '') {
            return null;
        }

        if ($this->looksLikeStageGroupRow($name, $stage)) {
            return null;
        }

        if ($partnerName === '' && $contactName !== '') {
            $partnerName = $name;
        }

        return [
            'name' => $name,
            'contact_name' => $contactName !== '' ? $contactName : null,
            'partner_name' => $partnerName !== '' ? $partnerName : null,
            'stage_id' => $this->resolveStageId($stage),
        ];
    }

    private function looksLikeStageGroupRow(string $name, string $stage): bool
    {
        if ($stage !== '' && preg_match('/\(\d+\)\s*$/u', $stage) === 1 && $name === '') {
            return true;
        }

        $normalizedStage = $this->normalizeStageName($stage);

        return $normalizedStage !== '' && $name === $normalizedStage;
    }

    private function resolveStageId(?string $stageName): ?int
    {
        $normalized = $this->normalizeStageName($stageName);
        if ($normalized === '') {
            return null;
        }

        if (array_key_exists($normalized, $this->stageIds)) {
            return $this->stageIds[$normalized];
        }

        $this->stageIds[$normalized] = $this->odoo->findCrmStageId($normalized);

        return $this->stageIds[$normalized];
    }

    private function normalizeStageName(?string $stageName): string
    {
        $value = trim((string) $stageName);
        if ($value === '') {
            return '';
        }

        $value = preg_replace('/\s*\(\d+\)\s*$/u', '', $value) ?? $value;
        $value = str_replace('المحتلمون', 'المحتملون', $value);
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return trim($value);
    }
}
