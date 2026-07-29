<?php

namespace LaravelConfigDoctor\Tests;

use LaravelConfigDoctor\ConfigAuditor;
use PHPUnit\Framework\TestCase;

final class ConfigAuditorTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'config-doctor-' . bin2hex(random_bytes(4));
        mkdir($this->path . '/config', 0777, true);
        mkdir($this->path . '/app', 0777, true);
        file_put_contents($this->path . '/.env', "APP_KEY=testing\nDATABASE_URL=secret\nUNUSED=value\n");
        file_put_contents($this->path . '/config/app.php', "<?php return ['url' => env('APP_URL', 'http://localhost'), 'db' => env('DATABASE_URL')];");
        file_put_contents($this->path . '/app/Example.php', "<?php \$value = env('OUTSIDE_CONFIG');");
    }

    protected function tearDown(): void
    {
        foreach (glob($this->path . '/*') ?: [] as $file) {
            if (is_dir($file)) foreach (glob($file . '/*') ?: [] as $nested) @unlink($nested);
            @rmdir($file); @unlink($file);
        }
        @rmdir($this->path);
    }

    public function test_it_finds_unsafe_environment_usage(): void
    {
        $findings = (new ConfigAuditor)->audit($this->path);
        $codes = array_map(fn ($finding) => $finding->code, $findings);
        self::assertContains('env-outside-config', $codes);
        self::assertContains('missing-env', $codes);
        self::assertContains('unused-env', $codes);
    }

    public function test_it_generates_an_example_file(): void
    {
        $path = (new ConfigAuditor)->generateExample($this->path);
        self::assertFileExists($path);
        self::assertStringContainsString('DATABASE_URL=', file_get_contents($path));
        self::assertStringNotContainsString('secret', file_get_contents($path));
    }
}
