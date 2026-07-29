<?php

namespace LaravelConfigDoctor\Commands;

use Illuminate\Console\Command;
use LaravelConfigDoctor\ConfigAuditor;

class ConfigDiffCommand extends Command
{
    protected $signature = 'config:diff {--from= : First environment suffix, such as local} {--to= : Second environment suffix, such as production} {--json : Print machine-readable JSON}';
    protected $description = 'Compare the loaded configuration with the cached configuration.';

    public function handle(ConfigAuditor $auditor): int
    {
        $findings = $this->option('from') && $this->option('to')
            ? $auditor->environmentDiff(base_path(), $this->option('from'), $this->option('to'))
            : $auditor->configurationDiff(base_path());
        if ($this->option('json')) $this->line(json_encode(array_map(fn ($f) => $f->toArray(), $findings), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        else foreach ($findings as $finding) $this->line("[{$finding->severity}] {$finding->message}");
        return collect($findings)->contains(fn ($f) => $f->severity === 'error') ? self::FAILURE : self::SUCCESS;
    }
}
