<?php

namespace LaravelConfigDoctor;

use Illuminate\Support\ServiceProvider;
use LaravelConfigDoctor\Commands\ConfigDiffCommand;
use LaravelConfigDoctor\Commands\ConfigDoctorCommand;

class ConfigDoctorServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/config-doctor.php', 'config-doctor');
        $this->app->singleton(ConfigAuditor::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/config-doctor.php' => config_path('config-doctor.php'),
            ], 'config-doctor-config');

            $this->commands([ConfigDoctorCommand::class, ConfigDiffCommand::class]);
        }
    }
}
