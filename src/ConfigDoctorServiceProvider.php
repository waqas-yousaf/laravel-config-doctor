<?php

namespace LaravelConfigDoctor;

use Illuminate\Support\ServiceProvider;
use LaravelConfigDoctor\Commands\ConfigDiffCommand;
use LaravelConfigDoctor\Commands\ConfigDoctorCommand;

class ConfigDoctorServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ConfigAuditor::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([ConfigDoctorCommand::class, ConfigDiffCommand::class]);
        }
    }
}
