<?php

namespace LaravelConfigDoctor;

use Illuminate\Support\Str;

class ConfigAuditor
{
    public function audit(string $basePath, ?string $environment = null): array
    {
        $usage = $this->envUsage($basePath);
        $env = $this->envFile($basePath, $environment);
        $findings = [];

        foreach ($usage as $item) {
            if (!array_key_exists($item['key'], $env)) {
                $severity = $item['has_default'] ? 'warning' : 'error';
                $findings[] = new ConfigFinding('missing-env', $severity,
                    "Environment variable {$item['key']} is used but missing" . ($item['has_default'] ? ' (a default exists).' : '.'),
                    $item['file'], $item['line'], ['key' => $item['key']]);
            }
            if (!$item['has_default']) {
                $findings[] = new ConfigFinding('env-without-default', 'warning',
                    "env('{$item['key']}') has no explicit default; config:cache can expose an unsafe value.",
                    $item['file'], $item['line'], ['key' => $item['key']]);
            }
            if (!$item['in_config']) {
                $findings[] = new ConfigFinding('env-outside-config', 'error',
                    "env('{$item['key']}') is used outside a configuration file.",
                    $item['file'], $item['line'], ['key' => $item['key']]);
            }
            if ($item['has_default'] && $item['quoted_default'] && (in_array(strtolower(trim($item['default'])), ['true', 'false', 'yes', 'no', 'on', 'off'], true) || is_numeric($item['default']))) {
                $findings[] = new ConfigFinding('boolean-cast', 'warning',
                    "env('{$item['key']}') has a quoted boolean or numeric default; Laravel will receive a string, not a native value.",
                    $item['file'], $item['line'], ['key' => $item['key']]);
            }
        }

        $used = array_unique(array_column($usage, 'key'));
        foreach (array_keys($env) as $key) {
            if (!in_array($key, $used, true) && !Str::startsWith($key, 'APP_')) {
                $findings[] = new ConfigFinding('unused-env', 'notice', "{$key} is present in the environment but is not referenced by scanned PHP files.", null, null, ['key' => $key]);
            }
        }

        foreach ($this->driverChecks() as $check) {
            if ($check['unsafe']) {
                $findings[] = new ConfigFinding('unsafe-driver', 'warning', $check['message'], null, null, $check);
            }
        }

        return $findings;
    }

    public function envUsage(string $basePath): array
    {
        $files = $this->phpFiles($basePath);
        $usage = [];
        foreach ($files as $file) {
            $contents = file_get_contents($file);
            preg_match_all("/\\benv\\s*\\(\\s*['\"]([A-Z][A-Z0-9_]+)['\"](?:\\s*,\\s*([^\\)]*))?\\)/", $contents, $matches, PREG_OFFSET_CAPTURE);
            foreach ($matches[1] as $index => [$key, $offset]) {
                $line = substr_count(substr($contents, 0, $offset), "\n") + 1;
                $default = trim($matches[2][$index][0] ?? '');
                $relative = str_replace($basePath . DIRECTORY_SEPARATOR, '', $file);
                $usage[] = [
                    'key' => $key, 'file' => $relative, 'line' => $line,
                    'in_config' => Str::startsWith(str_replace('\\', '/', $relative), 'config/'),
                    'has_default' => $default !== '', 'default' => trim($default, " \t\n\r\0\x0B'\""),
                    'quoted_default' => $default !== '' && in_array($default[0], ["'", '"'], true),
                ];
            }
        }
        return $usage;
    }

    public function configurationDiff(string $basePath): array
    {
        $cache = $basePath . DIRECTORY_SEPARATOR . 'bootstrap/cache/config.php';
        if (!is_file($cache)) return [new ConfigFinding('config-cache-missing', 'notice', 'No bootstrap/cache/config.php was found.')];
        $cached = require $cache;
        $current = function_exists('config') ? config()->all() : [];
        return $this->diffArrays($cached, $current);
    }

    public function environmentDiff(string $basePath, string $from, string $to): array
    {
        $left = $this->envFile($basePath, $from);
        $right = $this->envFile($basePath, $to);
        $findings = [];
        foreach (array_unique(array_merge(array_keys($left), array_keys($right))) as $key) {
            if (!array_key_exists($key, $left) || !array_key_exists($key, $right)) {
                $findings[] = new ConfigFinding('environment-diff', 'warning', "{$key} exists in only one environment file.", null, null, ['key' => $key]);
            } elseif ($left[$key] !== $right[$key]) {
                $findings[] = new ConfigFinding('environment-diff', 'notice', "{$key} differs between .env.{$from} and .env.{$to}.", null, null, ['key' => $key, 'from' => $this->redact($key, $left[$key]), 'to' => $this->redact($key, $right[$key])]);
            }
        }
        return $findings;
    }

    public function generateExample(string $basePath): string
    {
        $keys = array_unique(array_column($this->envUsage($basePath), 'key'));
        sort($keys);
        $path = $basePath . DIRECTORY_SEPARATOR . '.env.example';
        file_put_contents($path, implode('', array_map(static fn (string $key) => $key . "=\n", $keys)));
        return $path;
    }

    private function diffArrays(array $left, array $right, string $prefix = ''): array
    {
        $out = [];
        foreach (array_unique(array_merge(array_keys($left), array_keys($right))) as $key) {
            $path = $prefix === '' ? (string) $key : $prefix . '.' . $key;
            if (!array_key_exists($key, $left) || !array_key_exists($key, $right)) {
                $out[] = new ConfigFinding('config-diff', 'error', "Configuration key {$path} exists in only one snapshot.", null, null, ['key' => $path]); continue;
            }
            if (is_array($left[$key]) && is_array($right[$key])) $out = array_merge($out, $this->diffArrays($left[$key], $right[$key], $path));
            elseif ($left[$key] !== $right[$key]) $out[] = new ConfigFinding('config-diff', 'warning', "Configuration key {$path} differs between cached and current values.", null, null, ['key' => $path, 'cached' => $this->redact($path, $left[$key]), 'current' => $this->redact($path, $right[$key])]);
        }
        return $out;
    }

    private function envFile(string $basePath, ?string $environment): array
    {
        $path = $basePath . DIRECTORY_SEPARATOR . '.env' . ($environment ? '.' . $environment : '');
        if (!is_file($path)) return [];
        $values = [];
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            if (str_starts_with(trim($line), '#') || !str_contains($line, '=')) continue;
            [$key, $value] = explode('=', $line, 2);
            $values[trim($key)] = trim($value, " \t\n\r\0\x0B'\"");
        }
        return $values;
    }

    private function phpFiles(string $basePath): array
    {
        $files = [];
        foreach (['app', 'bootstrap', 'config', 'routes', 'database', 'resources'] as $dir) {
            $path = $basePath . DIRECTORY_SEPARATOR . $dir;
            if (!is_dir($path)) continue;
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) if ($file->isFile() && $file->getExtension() === 'php') $files[] = $file->getPathname();
        }
        return $files;
    }

    private function driverChecks(): array
    {
        if (!function_exists('config') || !function_exists('app') || !app()->bound('config')) return [];
        return [
            ['unsafe' => config('queue.default') === 'sync', 'message' => 'Queue driver is sync; production jobs will run inside the request.', 'driver' => 'queue'],
            ['unsafe' => config('cache.default') === 'array', 'message' => 'Cache driver is array; values will not persist between requests.', 'driver' => 'cache'],
            ['unsafe' => config('mail.default') === 'log', 'message' => 'Mail driver is log; messages will not be delivered.', 'driver' => 'mail'],
            ['unsafe' => config('filesystems.default') === 'local' && app()->environment('production'), 'message' => 'Filesystem driver is local in production; verify this is intentional.', 'driver' => 'filesystem'],
        ];
    }

    private function redact(string $key, mixed $value): mixed
    {
        return preg_match('/pass|secret|token|key|password|dsn|credential/i', $key) ? '[REDACTED]' : $value;
    }
}
