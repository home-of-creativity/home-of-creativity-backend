<?php

namespace Tests\Unit;

use App\Services\GoogleServiceAccount;
use Tests\TestCase;

class GoogleServiceAccountTest extends TestCase
{
    public function test_configured_reads_base64_service_account_json(): void
    {
        $json = json_encode([
            'client_email' => 'sa@test.iam.gserviceaccount.com',
            'private_key' => "-----BEGIN PRIVATE KEY-----\nX\n-----END PRIVATE KEY-----\n",
        ], JSON_THROW_ON_ERROR);

        config(['services.google.credentials_json' => base64_encode($json)]);

        $this->assertTrue(app(GoogleServiceAccount::class)->configured());
    }

    public function test_configured_reads_quoted_file_path(): void
    {
        $path = storage_path('app/google-sa-test.json');
        file_put_contents($path, json_encode([
            'client_email' => 'sa@test.iam.gserviceaccount.com',
            'private_key' => "-----BEGIN PRIVATE KEY-----\nX\n-----END PRIVATE KEY-----\n",
        ], JSON_THROW_ON_ERROR));

        config(['services.google.credentials_json' => '"'.$path.'"']);

        try {
            $this->assertTrue(app(GoogleServiceAccount::class)->configured());
        } finally {
            @unlink($path);
        }
    }

    public function test_configuration_error_explains_missing_file(): void
    {
        config(['services.google.credentials_json' => storage_path('app/missing-google-sa.json')]);

        $this->assertStringContainsString(
            'file was not found',
            (string) app(GoogleServiceAccount::class)->configurationError(),
        );
    }
}
