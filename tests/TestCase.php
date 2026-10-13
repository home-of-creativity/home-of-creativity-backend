<?php

namespace Tests;

use App\Actions\ApproveQuotation;
use App\Models\ServiceRequest;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\Concerns\FakesOdooDocuments;

abstract class TestCase extends BaseTestCase
{
    use FakesOdooDocuments;

    protected function refreshApplication(): void
    {
        parent::refreshApplication();

        $driver = (string) config('database.default');
        if (in_array($driver, ['mysql', 'mariadb', 'pgsql', 'sqlsrv'], true)) {
            throw new \RuntimeException(
                "Tests refused to run against {$driver}. phpunit.xml must force sqlite :memory: so local data is not wiped."
            );
        }
    }

    /**
     * A client's approval waits for an admin. Tests that follow the request past it confirm
     * the approval the way an admin does from the dashboard or the admin bot.
     */
    protected function confirmClientApproval(ServiceRequest|string $request): ServiceRequest
    {
        $model = $request instanceof ServiceRequest
            ? $request->fresh()
            : ServiceRequest::query()->where('number', $request)->first();
        if ($model === null) {
            throw new \RuntimeException('No request to confirm.');
        }

        return app(ApproveQuotation::class)->confirm($model, 'admin');
    }
}
