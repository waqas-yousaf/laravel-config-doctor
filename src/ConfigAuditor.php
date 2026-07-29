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

        // Check for missing local environment variables that exist in .env.example
        $exampleEnv = $this->envFile($basePath, 'example');
        foreach (array_keys($exampleEnv) as $key) {
            if (!array_key_exists($key, $env)) {
                $findings[] = new ConfigFinding('missing-env-from-example', 'warning',
                    "Environment variable {$key} is defined in .env.example but missing from your .env file.",
                    '.env.example', null, ['key' => $key]);
            }
        }

        // Audit .env.example for committed secrets
        foreach ($exampleEnv as $key => $value) {
            if ($value !== '' && preg_match('/pass|secret|token|key|password|dsn|credential|api|auth|private|signature/i', $key)) {
                if (!preg_match('/^(?:your[_-].*|placeholder.*|enter[_-].*|xxxx.*|my[_-].*|null|true|false|127\.0\.0\.1|localhost|root|mysql|postgres|sqlite|redis|smtp|mailpit|log|dummy|example|test|changeme|todo)?$/i', $value)) {
                    $findings[] = new ConfigFinding('committed-secret', 'error',
                        "Environment variable {$key} in .env.example contains a secret-looking value: '{$value}'. Make sure it is a placeholder.",
                        '.env.example', null, ['key' => $key, 'value' => $value]);
                }
            }
        }

        $used = array_unique(array_column($usage, 'key'));
        $ignoreUnused = $this->getConfig('ignore_unused', ['APP_KEY', 'APP_ENV', 'APP_DEBUG', 'APP_URL', 'APP_NAME']);
        foreach (array_keys($env) as $key) {
            if (!in_array($key, $used, true) && !in_array($key, $ignoreUnused, true) && !Str::startsWith($key, 'APP_')) {
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
        $helpers = $this->getConfig('helpers', ['env', 'Env::get']);
        
        $helperPatterns = array_map(fn($h) => preg_quote($h, '/'), $helpers);
        $pattern = '/\b(' . implode('|', $helperPatterns) . ')\s*\(/';

        foreach ($files as $file) {
            $contents = file_get_contents($file);
            if (!preg_match_all($pattern, $contents, $matches, PREG_OFFSET_CAPTURE)) {
                continue;
            }

            foreach ($matches[0] as [$matchStr, $startOffset]) {
                $parenStartOffset = $startOffset + strlen($matchStr);
                $parenEndOffset = $this->findMatchingParenthesis($contents, $parenStartOffset);
                if ($parenEndOffset === null) {
                    continue;
                }

                $argumentsStr = substr($contents, $parenStartOffset, $parenEndOffset - $parenStartOffset);
                $parsed = $this->parseArguments($argumentsStr);
                if (!$parsed) {
                    continue;
                }

                $key = $parsed['key'];
                $default = $parsed['default'];

                $line = substr_count(substr($contents, 0, $startOffset), "\n") + 1;
                $relative = str_replace($basePath . DIRECTORY_SEPARATOR, '', $file);

                $usage[] = [
                    'key' => $key,
                    'file' => $relative,
                    'line' => $line,
                    'in_config' => Str::startsWith(str_replace('\\', '/', $relative), 'config/'),
                    'has_default' => $default !== null,
                    'default' => $default !== null ? trim($default, " \t\n\r\0\x0B'\"") : '',
                    'quoted_default' => $default !== null && in_array(substr(trim($default), 0, 1), ["'", '"'], true),
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

    private function findMatchingParenthesis(string $content, int $startOffset): ?int
    {
        $length = strlen($content);
        $depth = 1;
        $inSingleQuote = false;
        $inDoubleQuote = false;
        $escaped = false;

        for ($i = $startOffset; $i < $length; $i++) {
            $char = $content[$i];

            if ($escaped) {
                $escaped = false;
                continue;
            }

            if ($char === '\\') {
                $escaped = true;
                continue;
            }

            if ($char === "'" && !$inDoubleQuote) {
                $inSingleQuote = !$inSingleQuote;
                continue;
            }

            if ($char === '"' && !$inSingleQuote) {
                $inDoubleQuote = !$inDoubleQuote;
                continue;
            }

            if ($inSingleQuote || $inDoubleQuote) {
                continue;
            }

            if ($char === '(') {
                $depth++;
            } elseif ($char === ')') {
                $depth--;
                if ($depth === 0) {
                    return $i;
                }
            }
        }

        return null;
    }

    private function parseArguments(string $argsStr): ?array
    {
        $length = strlen($argsStr);
        $inSingleQuote = false;
        $inDoubleQuote = false;
        $escaped = false;
        $parenDepth = 0;
        $bracketDepth = 0;
        $braceDepth = 0;
        $commaIndex = null;

        for ($i = 0; $i < $length; $i++) {
            $char = $argsStr[$i];

            if ($escaped) {
                $escaped = false;
                continue;
            }

            if ($char === '\\') {
                $escaped = true;
                continue;
            }

            if ($char === "'" && !$inDoubleQuote) {
                $inSingleQuote = !$inSingleQuote;
                continue;
            }

            if ($char === '"' && !$inSingleQuote) {
                $inDoubleQuote = !$inDoubleQuote;
                continue;
            }

            if ($inSingleQuote || $inDoubleQuote) {
                continue;
            }

            if ($char === '(') {
                $parenDepth++;
            } elseif ($char === ')') {
                $parenDepth--;
            } elseif ($char === '[') {
                $bracketDepth++;
            } elseif ($char === ']') {
                $bracketDepth--;
            } elseif ($char === '{') {
                $braceDepth++;
            } elseif ($char === '}') {
                $braceDepth--;
            } elseif ($char === ',' && $parenDepth === 0 && $bracketDepth === 0 && $braceDepth === 0) {
                $commaIndex = $i;
                break;
            }
        }

        if ($commaIndex === null) {
            $keyStr = trim($argsStr);
            $defaultStr = null;
        } else {
            $keyStr = trim(substr($argsStr, 0, $commaIndex));
            $defaultStr = trim(substr($argsStr, $commaIndex + 1));
        }

        if (preg_match('/^[\'"]([a-zA-Z0-9_\-\.]+)[\'"]$/', $keyStr, $matches)) {
            return [
                'key' => $matches[1],
                'default' => $defaultStr,
            ];
        }

        return null;
    }

    private function envFile(string $basePath, ?string $environment): array
    {
        $suffix = $environment ? '.' . $environment : '';
        $path = $basePath . DIRECTORY_SEPARATOR . '.env' . $suffix;
        if (!is_file($path)) return [];
        $values = [];
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) continue;
            
            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);
            
            if ($value !== '') {
                $firstChar = $value[0];
                if ($firstChar === '"' || $firstChar === "'") {
                    $length = strlen($value);
                    $closingQuoteIndex = null;
                    $escaped = false;
                    for ($i = 1; $i < $length; $i++) {
                        if ($escaped) {
                            $escaped = false;
                            continue;
                        }
                        if ($value[$i] === '\\') {
                            $escaped = true;
                            continue;
                        }
                        if ($value[$i] === $firstChar) {
                            $closingQuoteIndex = $i;
                            break;
                        }
                    }
                    if ($closingQuoteIndex !== null) {
                        $value = substr($value, 1, $closingQuoteIndex - 1);
                    } else {
                        $value = trim($value, $firstChar);
                    }
                } else {
                    if (str_contains($value, '#')) {
                        $parts = explode('#', $value, 2);
                        $value = trim($parts[0]);
                    }
                }
            }
            
            $values[$key] = $value;
        }
        return $values;
    }

    private function phpFiles(string $basePath): array
    {
        $files = [];
        $dirs = $this->getConfig('scan_dirs', ['app', 'bootstrap', 'config', 'routes', 'database', 'resources']);
        $extensions = $this->getConfig('extensions', ['php']);
        
        foreach ($dirs as $dir) {
            $path = $basePath . DIRECTORY_SEPARATOR . $dir;
            if (!is_dir($path)) continue;
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if ($file->isFile() && in_array($file->getExtension(), $extensions, true)) {
                    $files[] = $file->getPathname();
                }
            }
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

    private function getConfig(string $key, mixed $default = null): mixed
    {
        if (function_exists('config') && function_exists('app') && app()->bound('config')) {
            return config('config-doctor.' . $key, $default);
        }
        return $default;
    }
}
