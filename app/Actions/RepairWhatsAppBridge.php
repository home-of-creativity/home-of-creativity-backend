<?php

namespace App\Actions;

use App\Services\DevAlert;
use App\Services\WhatsAppWebClient;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Puts the WhatsApp Web bridge back when the socket died.
 * A scan code is left for a person. The session files are never deleted here.
 */
class RepairWhatsAppBridge
{
    public function __construct(
        private WhatsAppWebClient $whatsApp,
        private DevAlert $alert,
    ) {}

    /**
     * Ask n8n to repair. When that webhook is empty, repair here.
     *
     * @param  array{connected: bool, qr: string|null, phone: string|null, reachable: bool}  $status
     * @return array{fixed: bool, action: string}
     */
    public function handOff(array $status): array
    {
        $url = trim((string) config('services.n8n.bridge_repair_url'));
        if ($url === '') {
            return $this->repair();
        }

        try {
            $response = Http::timeout(25)
                ->connectTimeout(5)
                ->acceptJson()
                ->withHeaders(['X-N8N-Secret' => (string) config('services.n8n.webhook_secret')])
                ->post($url, [
                    'reachable' => $status['reachable'],
                    'connected' => $status['connected'],
                    'needs_scan' => $status['qr'] !== null,
                ]);
            $action = $response->json('action');

            return [
                'fixed' => $response->json('fixed') === true,
                'action' => is_string($action) && $action !== '' ? $action : 'n8n',
            ];
        } catch (\Throwable $exception) {
            Log::warning('n8n could not repair the WhatsApp bridge.', ['error' => $exception->getMessage()]);

            return $this->repair();
        }
    }

    /**
     * @return array{fixed: bool, action: string}
     */
    public function repair(): array
    {
        $status = $this->whatsApp->status();
        if ($status['reachable'] && $status['connected'] && $status['qr'] === null) {
            return ['fixed' => true, 'action' => 'already_up'];
        }
        if ($status['qr'] !== null) {
            return ['fixed' => false, 'action' => 'needs_scan'];
        }
        if ($status['reachable'] && $this->whatsApp->restartProcess()) {
            $this->alert->bridgeRepaired();

            return ['fixed' => true, 'action' => 'process_restart'];
        }
        if ($this->restartContainer()) {
            $this->alert->bridgeRepaired();

            return ['fixed' => true, 'action' => 'container_restart'];
        }

        return ['fixed' => false, 'action' => 'container_down'];
    }

    private function restartContainer(): bool
    {
        $name = trim((string) config('services.whatsapp.web_container'));
        if ($name === '' || preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,120}$/', $name) !== 1) {
            return false;
        }
        if (! is_file('/var/run/docker.sock')) {
            return false;
        }

        $socket = @stream_socket_client('unix:///var/run/docker.sock', $errno, $error, 3);
        if ($socket === false) {
            Log::warning('WhatsApp bridge container restart failed.', ['error' => $error]);

            return false;
        }

        $path = '/v1.41/containers/'.rawurlencode($name).'/restart?t=10';
        fwrite($socket, "POST {$path} HTTP/1.0\r\nHost: localhost\r\nContent-Length: 0\r\n\r\n");
        $raw = (string) stream_get_contents($socket);
        fclose($socket);

        return str_contains($raw, ' 204 ') || str_contains($raw, ' 200 ');
    }
}
