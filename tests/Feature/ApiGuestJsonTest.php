<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ApiGuestJsonTest extends TestCase
{
    /**
     * @return array<string, array{0: array<string, string>}>
     */
    public static function acceptHeaders(): array
    {
        return [
            'no accept' => [[]],
            'any type' => [['Accept' => '*/*']],
            'json' => [['Accept' => 'application/json']],
        ];
    }

    /**
     * @param  array<string, string>  $headers
     */
    #[DataProvider('acceptHeaders')]
    public function test_an_admin_route_without_a_token_returns_401_json(array $headers): void
    {
        foreach (['/api/admin/live', '/api/admin/clients'] as $uri) {
            $response = $this->get($uri, $headers);

            $response->assertStatus(401);
            $this->assertStringStartsWith('application/json', (string) $response->headers->get('Content-Type'));
            $response->assertJson(['message' => 'Unauthenticated.']);
        }
    }
}
