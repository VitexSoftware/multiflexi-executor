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

namespace MultiFlexi;

use Ease\Shared;

require_once '../vendor/autoload.php';

$options = getopt('r:j:o::e::t::E:', ['runtemplate::', 'job::', 'output::', 'environment::', 'timeout::', 'env-json:']);
Shared::init(
    ['DB_CONNECTION', 'DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD'],
    \array_key_exists('environment', $options) ? $options['environment'] : (\array_key_exists('e', $options) ? $options['e'] : '../.env'),
);
$destination = \array_key_exists('o', $options) ? $options['o'] : (\array_key_exists('output', $options) ? $options['output'] : Shared::cfg('RESULT_FILE', 'php://stdout'));

$runtempateId = (int) (\array_key_exists('r', $options) ? $options['r'] : (\array_key_exists('runtemplate', $options) ? $options['runtemplate'] : Shared::cfg('RUNTEMPLATE_ID', 0)));
$jobId = (int) (\array_key_exists('j', $options) ? $options['j'] : (\array_key_exists('job', $options) ? $options['job'] : Shared::cfg('JOB_ID', 0)));

// Maximum seconds to wait for the daemon to execute a queued RunTemplate job.
// 0 means wait indefinitely. Configurable via RUNTEMPLATE_WAIT_TIMEOUT.
$waitTimeout = (int) (\array_key_exists('t', $options) ? $options['t'] : (\array_key_exists('timeout', $options) ? $options['timeout'] : Shared::cfg('RUNTEMPLATE_WAIT_TIMEOUT', 300)));

// Build env-override ConfigFields from -E KEY=VALUE and/or --env-json='{"KEY":"VAL"}'.
// -E entries take precedence over --env-json keys when both are given.
// Values are never logged; only key names are emitted at debug level.
$override = new ConfigFields('cli-override');

if (\array_key_exists('env-json', $options)) {
    $rawJson = (string) $options['env-json'];
    $decoded = json_decode($rawJson, true);

    if (!\is_array($decoded)) {
        fwrite(fopen('php://stderr', 'wb'), '--env-json: invalid JSON — '.json_last_error_msg().\PHP_EOL);

        exit(1);
    }

    foreach ($decoded as $envKey => $envVal) {
        $field = new ConfigField((string) $envKey, 'string', '', '', '', (string) $envVal);
        $field->setSource('cli');
        $override->addField($field);

        if (Shared::cfg('APP_DEBUG')) {
            // Log key name only — never the value.
            fwrite(fopen('php://stderr', 'wb'), '[debug] env-override key (from --env-json): '.$envKey.\PHP_EOL);
        }
    }
}

// -E entries are collected as an array (flag is repeatable); each must be "KEY=VALUE".
// These override any same-named keys already added from --env-json.
$eEntries = \array_key_exists('E', $options) ? (array) $options['E'] : [];

foreach ($eEntries as $entry) {
    $eqPos = strpos((string) $entry, '=');

    if ($eqPos === false || $eqPos === 0) {
        fwrite(fopen('php://stderr', 'wb'), '-E: expected KEY=VALUE, got: '.((string) $entry).\PHP_EOL);

        exit(1);
    }

    $envKey = substr((string) $entry, 0, $eqPos);
    $envVal = substr((string) $entry, $eqPos + 1);
    $field = new ConfigField($envKey, 'string', '', '', '', $envVal);
    $field->setSource('cli');
    $override->addField($field); // addField replaces any existing field with the same code

    if (Shared::cfg('APP_DEBUG')) {
        // Log key name only — never the value.
        fwrite(fopen('php://stderr', 'wb'), '[debug] env-override key (from -E): '.$envKey.\PHP_EOL);
    }
}

$loggers = ['syslog', '\MultiFlexi\LogToSQL'];

if (Shared::cfg('ZABBIX_SERVER') && Shared::cfg('ZABBIX_HOST') && class_exists('\MultiFlexi\LogToZabbix')) {
    $loggers[] = '\MultiFlexi\LogToZabbix';
}

if (strtolower(Shared::cfg('APP_DEBUG', 'false')) === 'true') {
    $loggers[] = 'console';
}

\define('EASE_LOGGER', implode('|', $loggers));
\define('APP_NAME', 'MultiFlexi executor');
new \MultiFlexi\Defaults();
Shared::user(new \MultiFlexi\UnixUser());

// Mode 1: Execute existing job by Job ID
if ($jobId > 0) {
    $jobber = new Job($jobId);

    if (Shared::cfg('APP_DEBUG')) {
        $jobber->logBanner(' Job #'.$jobId);
    }

    if (!$jobber->getMyKey()) {
        fwrite(fopen('php://stderr', 'wb'), sprintf('Job #%d not found'.\PHP_EOL, $jobId));

        exit(1);
    }

    if (Shared::cfg('APP_DEBUG')) {
        $jobber->addStatusMessage(sprintf('Executing existing job #%d', $jobId));
    }

    $jobber->performJob();

    echo $jobber->executor->getOutput();

    if ($jobber->executor->getErrorOutput()) {
        fwrite(fopen('php://stderr', 'wb'), $jobber->executor->getErrorOutput().\PHP_EOL);
    }

    exit($jobber->executor->getExitCode());
}

// Mode 2: Schedule a new job from RunTemplate ID for "now" and wait for the
// daemon to execute it, then report its stdout, stderr and exit code.
if ($runtempateId > 0) {
    $runTemplater = new \MultiFlexi\RunTemplate($runtempateId);

    if (Shared::cfg('APP_DEBUG')) {
        $runTemplater->logBanner('RunTemplate #'.$runtempateId, $runTemplater->getRecordName());
    }

    if (!$runTemplater->getMyKey()) {
        fwrite(fopen('php://stderr', 'wb'), sprintf('RunTemplate #%d not found'.\PHP_EOL, $runtempateId));

        exit(1);
    }

    // Create the job and queue it for immediate execution. prepareJob() already
    // schedules the run via scheduleJobRun(); the running daemon picks it up so
    // it goes through the regular parallelism / per-runtemplate guards.
    // Any env overrides supplied via -E or --env-json are forwarded to the job.
    $jobber = new Job();
    $jobber->prepareJob($runTemplater, $override, new \DateTime(), 'Native', 'adhoc');
    $jobId = $jobber->getMyKey();

    // Wait for the daemon to finish the job. Completion is signalled by the
    // job's "end" column being populated (set together with exitcode/stdout/
    // stderr when the job ends).
    $startedWaiting = time();
    $pollInterval = max(1, (int) Shared::cfg('RUNTEMPLATE_WAIT_POLL', 2));

    do {
        sleep($pollInterval);
        $check = new Job($jobId); // constructor reloads a fresh row from SQL
        $finished = $check->getDataValue('end') !== null;

        if (Shared::cfg('APP_DEBUG') && !$finished) {
            $jobber->addStatusMessage(sprintf('Waiting for job #%d to finish…', $jobId), 'debug');
        }

        if (!$finished && $waitTimeout > 0 && (time() - $startedWaiting) >= $waitTimeout) {
            fwrite(fopen('php://stderr', 'wb'), sprintf('Timed out after %ds waiting for job #%d (is the daemon running?)'.\PHP_EOL, $waitTimeout, $jobId));

            exit(124); // GNU timeout(1) convention
        }
    } while (!$finished);

    // stdout/stderr are stored addslashes()-escaped; restore them for output.
    $stdout = stripslashes((string) $check->getDataValue('stdout'));
    $stderr = stripslashes((string) $check->getDataValue('stderr'));

    if ($destination === 'php://stdout') {
        echo $stdout;
    } else {
        file_put_contents($destination, $stdout);
    }

    if ($stderr !== '') {
        fwrite(fopen('php://stderr', 'wb'), $stderr.\PHP_EOL);
    }

    exit((int) $check->getDataValue('exitcode'));
}

fwrite(fopen('php://stderr', 'wb'), <<<'USAGE'
Usage:
  multiflexi-executor -j JOB_ID [-e ENV_FILE] [-o OUTPUT_FILE]
  multiflexi-executor -r RUNTEMPLATE_ID [-t SECONDS] [-e ENV_FILE] [-o OUTPUT_FILE]
                      [-E KEY=VALUE ...] [--env-json='{"KEY":"VAL"}']

Options:
  -j, --job=JOB_ID          Execute an existing job by its database ID (inline).
  -r, --runtemplate=ID      Create and queue a new job from a RunTemplate, then
                            wait for the daemon to run it.
  -t, --timeout=SECONDS     Max seconds to wait for -r mode (0 = unlimited,
                            default: RUNTEMPLATE_WAIT_TIMEOUT or 300).
  -o, --output=FILE         Write captured job stdout to FILE (default: php://stdout
                            or RESULT_FILE).
  -e, --environment=FILE    Path to the .env configuration file (default: ../.env).
  -E KEY=VALUE              Inject an env override into the job (repeatable).
                            Takes precedence over --env-json for the same key.
  --env-json='{"K":"V"}'    Inject env overrides from a JSON object string.
                            Must be valid JSON; exits 1 on parse error.

USAGE
);

exit(1);
