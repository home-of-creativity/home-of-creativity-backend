<?php

namespace App\Providers;

use App\Contracts\WhatsAppMessenger;
use App\Models\ServiceRequest;
use App\Services\OdooClient;
use App\Services\OdooLeadLog;
use App\Services\WhatsAppCloudClient;
use App\Services\WhatsAppWebClient;
use App\Support\ClientReachability;
use App\Support\ResolveServiceRequest;
use App\Support\StagingGuard;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(OdooClient::class);
        $this->app->singleton(OdooLeadLog::class);
        $this->app->scoped(ClientReachability::class);
        $this->app->singleton(WhatsAppMessenger::class, function (): WhatsAppMessenger {
            if (config('services.whatsapp.transport') === 'web') {
                return new WhatsAppWebClient;
            }

            return new WhatsAppCloudClient;
        });
    }

    public function boot(): void
    {
        StagingGuard::assertDatabase(
            (string) $this->app->environment(),
            config('database.connections.'.config('database.default').'.database'),
        );

        Route::bind('service_request', function (string $value): ServiceRequest {
            return app(ResolveServiceRequest::class)->byReference($value);
        });
    }
}
