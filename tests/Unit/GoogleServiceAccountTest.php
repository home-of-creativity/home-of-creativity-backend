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
}
