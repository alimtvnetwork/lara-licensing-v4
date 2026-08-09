<?php

declare(strict_types=1);

namespace App\Providers;

use App\Contracts\BR\SecretsProviderContract;
use App\Models\PersonalAccessToken;
use App\Services\BR\ConfigSecretsProvider;
use App\Services\RuntimeConfigService;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Plan 16 step 58. Bind RuntimeConfigService to the configured
        // on-disk `version.json` path so controllers/tests share one instance.
        $this->app->singleton(RuntimeConfigService::class, static function ($app): RuntimeConfigService {
            $path = (string) config('lara.runtime_config_path');

            return new RuntimeConfigService($path);
        });

        // Plan 14 step 8. Bind the BR SecretsProviderContract to the default
        // config-backed implementation. Prod supplies KEK material via env,
        // tests stub `Config::set('br.kek_material', ...)`. See spec 26 §09
        // "Key Hierarchy" and INV-BR-EK-2/EK-5.
        $this->app->singleton(SecretsProviderContract::class, ConfigSecretsProvider::class);
    }


    public function boot(): void
    {
        // Plan 06 step 43 (login substrate). Route Sanctum to the
        // Root-connected token model so tokens live alongside Users.
        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);
    }
}
