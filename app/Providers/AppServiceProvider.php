<?php

namespace App\Providers;

use App\Models\ServiceRequest;
use App\Services\OdooClient;
use App\Support\ResolveServiceRequest;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(OdooClient::class);
    }

    public function boot(): void
    {
        Route::bind('service_request', function (string $value): ServiceRequest {
            return app(ResolveServiceRequest::class)->byReference($value);
        });
    }
}
