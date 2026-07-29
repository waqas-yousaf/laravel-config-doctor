<?php

namespace LaravelConfigDoctor\Commands;

use Illuminate\Console\Command;
use LaravelConfigDoctor\ConfigAuditor;

class ConfigDoctorCommand extends Command
{
    protected $signature = 'config:doctor {--env= : Environment suffix, such as staging} {--ci : Fail on errors and warnings} {--json : Print machine-readable JSON} {--generate-env-example : Generate .env.example from scanned usage}';
    protected $description = 'Audit environment usage and Laravel configuration safety.';

    public function handle(ConfigAuditor $auditor): int
    {
        if ($this->option('generate-env-example')) {
            $this->info('Generated ' . $auditor->generateExample(base_path()));
            return self::SUCCESS;
        }
        $findings = $auditor->audit(base_path(), $this->option('env'));
        if ($this->option('json')) $this->line(json_encode(array_map(fn ($f) => $f->toArray(), $findings), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        else {
            $this->info('Laravel Config Doctor');
            if (!$findings) $this->info('No configuration findings.');
            foreach ($findings as $finding) $this->line(sprintf('[%s] %s%s', strtoupper($finding->severity), $finding->message, $finding->file ? " ({$finding->file}:{$finding->line})" : ''));
            $this->line(sprintf('%d finding(s).', count($findings)));
        }
        return $this->option('ci') && collect($findings)->contains(fn ($f) => in_array($f->severity, ['error', 'warning'], true)) ? self::FAILURE : self::SUCCESS;
    }
}
