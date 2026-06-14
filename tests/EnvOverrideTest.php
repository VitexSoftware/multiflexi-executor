<?php

declare(strict_types=1);

/**
 * This file is part of the MultiFlexi package
 *
 * https://multiflexi.eu/
 *
 * (c) Vítězslav Dvořák <http://vitexsoftware.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use PHPUnit\Framework\TestCase;

/**
 * EnvOverrideTest.
 *
 * Tests the -E KEY=VALUE and --env-json flag parsing added to src/executor.php.
 * Tests invoke the script as a subprocess from the src/ directory (which is where
 * the relative require_once '../vendor/autoload.php' resolves correctly).
 *
 * No running DB or MultiFlexi instance is required: flag-parsing errors are
 * emitted to stderr and abort the script (exit 1) before any database access.
 *
 * Note: when neither -j nor -r is given, the script also exits 1 and emits usage
 * text to stderr. Tests distinguish flag-parsing errors from usage-display exit
 * by checking for specific error message prefixes ("-E:" and "--env-json:").
 */
class EnvOverrideTest extends TestCase
{
    /** Absolute path to the src/ directory. */
    private string $srcDir;

    protected function setUp(): void
    {
        $this->srcDir = \dirname(__DIR__).'/src';
        $this->assertDirectoryExists($this->srcDir, 'src/ directory must exist');
        $this->assertFileExists($this->srcDir.'/executor.php', 'executor.php must exist');
    }

    /**
     * Run executor.php with the given argument list (cwd = src/) and
     * return [exitCode, stdout, stderr].
     *
     * @param list<string> $args Raw argument strings (will be shell-escaped).
     *
     * @return array{int, string, string}
     */
    private function runExecutor(array $args): array
    {
        $escapedArgs = implode(' ', array_map('escapeshellarg', $args));
        // Run from src/ so that '../vendor/autoload.php' resolves correctly.
        $cmd = 'php '.escapeshellarg($this->srcDir.'/executor.php').' '.$escapedArgs;

        $descriptors = [
            0 => ['pipe', 'r'],  // stdin
            1 => ['pipe', 'w'],  // stdout
            2 => ['pipe', 'w'],  // stderr
        ];

        $process = proc_open($cmd, $descriptors, $pipes, $this->srcDir);
        $this->assertNotFalse($process, 'proc_open must succeed');

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        return [$exitCode, (string) $stdout, (string) $stderr];
    }

    /**
     * --env-json with a valid JSON object must not emit the "--env-json: invalid JSON"
     * error prefix to stderr. The script may still exit 1 (no -j/-r given) and show
     * usage, but the env flag itself must be silently accepted.
     */
    public function testEnvJsonValidJsonObjectDoesNotProduceInvalidJsonError(): void
    {
        [$exitCode, , $stderr] = $this->runExecutor(['--env-json={"FOO":"bar","BAZ":"123"}']);

        $this->assertStringNotContainsString(
            '--env-json: invalid JSON',
            $stderr,
            'Valid JSON object must not produce an "--env-json: invalid JSON" error; stderr: '.$stderr,
        );
    }

    /**
     * --env-json with invalid JSON (syntactically broken) must exit 1 and emit
     * "--env-json: invalid JSON" to stderr.
     */
    public function testEnvJsonInvalidJsonExitsOneWithMessage(): void
    {
        [$exitCode, , $stderr] = $this->runExecutor(['--env-json=this is not json']);

        $this->assertSame(1, $exitCode, 'Exit code must be 1 for syntactically invalid JSON');
        $this->assertStringContainsString(
            '--env-json: invalid JSON',
            $stderr,
            'stderr must contain "--env-json: invalid JSON"; got: '.$stderr,
        );
    }

    /**
     * --env-json with a JSON literal string (not an object) must exit 1 with
     * the invalid-JSON message, because the implementation requires an array/object.
     */
    public function testEnvJsonJsonStringLiteralExitsOneWithMessage(): void
    {
        [$exitCode, , $stderr] = $this->runExecutor(['--env-json="just a string"']);

        $this->assertSame(1, $exitCode, 'Exit code must be 1 when JSON value is a bare string');
        $this->assertStringContainsString(
            '--env-json: invalid JSON',
            $stderr,
            'stderr must contain "--env-json: invalid JSON" for a bare string; got: '.$stderr,
        );
    }

    /**
     * -E without a '=' separator must exit 1 and emit "-E: expected KEY=VALUE" to stderr.
     */
    public function testEFlagMissingEqualsExitsOneWithMessage(): void
    {
        [$exitCode, , $stderr] = $this->runExecutor(['-E', 'NOKEYVALUE']);

        $this->assertSame(1, $exitCode, 'Exit code must be 1 when -E value has no =');
        $this->assertStringContainsString(
            '-E: expected KEY=VALUE',
            $stderr,
            'stderr must contain "-E: expected KEY=VALUE"; got: '.$stderr,
        );
    }

    /**
     * -E with '=' as the first character (empty key) must exit 1 and emit
     * "-E: expected KEY=VALUE" to stderr.
     */
    public function testEFlagEmptyKeyExitsOneWithMessage(): void
    {
        [$exitCode, , $stderr] = $this->runExecutor(['-E', '=value']);

        $this->assertSame(1, $exitCode, 'Exit code must be 1 when -E has an empty key');
        $this->assertStringContainsString(
            '-E: expected KEY=VALUE',
            $stderr,
            'stderr must contain "-E: expected KEY=VALUE"; got: '.$stderr,
        );
    }

    /**
     * -E KEY=VALUE with a valid "KEY=VALUE" format must not emit the
     * "-E: expected KEY=VALUE" error to stderr. The script exits 1 (no -j/-r)
     * and shows usage, but the flag itself is accepted.
     */
    public function testEFlagValidFormatDoesNotProduceFormatError(): void
    {
        [, , $stderr] = $this->runExecutor(['-E', 'FOO=bar']);

        $this->assertStringNotContainsString(
            '-E: expected KEY=VALUE',
            $stderr,
            'Valid -E KEY=VALUE must not produce a "-E: expected KEY=VALUE" error; stderr: '.$stderr,
        );
    }

    /**
     * -E KEY=VALUE=EXTRA (value containing '=') must be accepted: only the first
     * '=' is the key/value separator; the remainder is the value.
     */
    public function testEFlagValueContainingEqualsIsAccepted(): void
    {
        [, , $stderr] = $this->runExecutor(['-E', 'URL=https://example.com/path?a=1']);

        $this->assertStringNotContainsString(
            '-E: expected KEY=VALUE',
            $stderr,
            '-E with value containing = must not produce a format error; stderr: '.$stderr,
        );
    }

    /**
     * Backward compatibility: when no -E / --env-json flags are provided the script
     * must not emit any env-override error messages. Any other stderr output
     * (e.g. usage text when no mode flag is given) is acceptable.
     */
    public function testNoOverrideFlagsDoNotCauseEnvParsingError(): void
    {
        [, , $stderr] = $this->runExecutor([]);

        $this->assertStringNotContainsString(
            '--env-json: invalid JSON',
            $stderr,
            'Absent --env-json flag must not produce an invalid JSON error',
        );
        $this->assertStringNotContainsString(
            '-E: expected KEY=VALUE',
            $stderr,
            'Absent -E flag must not produce a KEY=VALUE format error',
        );
    }
}
