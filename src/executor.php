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

$options = getopt('r:j:o::e::t::', ['runtemplate::', 'job::', 'output::', 'environment::', 'timeout::']);
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
    $jobber = new Job();
    $jobber->prepareJob($runTemplater, new ConfigFields('empty'), new \DateTime(), 'Native', 'adhoc');
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

fwrite(fopen('php://stderr', 'wb'), 'Specify either runtemplate ID (-r) or job ID (-j) to run'.\PHP_EOL);

exit(1);
