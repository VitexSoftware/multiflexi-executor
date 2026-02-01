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

$options = getopt('r:j:o::e::', ['runtemplate::job::output::environment::']);
Shared::init(
    ['DB_CONNECTION', 'DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD'],
    \array_key_exists('environment', $options) ? $options['environment'] : (\array_key_exists('e', $options) ? $options['e'] : '../.env'),
);
$destination = \array_key_exists('o', $options) ? $options['o'] : (\array_key_exists('output', $options) ? $options['output'] : Shared::cfg('RESULT_FILE', 'php://stdout'));

$runtempateId = (int) (\array_key_exists('r', $options) ? $options['r'] : (\array_key_exists('runtemplate', $options) ? $options['runtemplate'] : Shared::cfg('RUNTEMPLATE_ID', 0)));
$jobId = (int) (\array_key_exists('j', $options) ? $options['j'] : (\array_key_exists('job', $options) ? $options['job'] : Shared::cfg('JOB_ID', 0)));

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

// Mode 2: Create and execute new job from RunTemplate ID
if ($runtempateId > 0) {
    $runTemplater = new \MultiFlexi\RunTemplate($runtempateId);

    if (Shared::cfg('APP_DEBUG')) {
        $runTemplater->logBanner('RunTemplate #'.$runtempateId, $runTemplater->getRecordName());
    }

    if (!$runTemplater->getMyKey()) {
        fwrite(fopen('php://stderr', 'wb'), sprintf('RunTemplate #%d not found'.\PHP_EOL, $runtempateId));

        exit(1);
    }

    $jobber = new Job();
    $jobber->prepareJob($runTemplater, new ConfigFields('empty'), new \DateTime(), 'Native', 'CommandLine');
    $jobber->performJob();

    echo $jobber->executor->getOutput();

    if ($jobber->executor->getErrorOutput()) {
        fwrite(fopen('php://stderr', 'wb'), $jobber->executor->getErrorOutput().\PHP_EOL);
    }

    exit($jobber->executor->getExitCode());
}

fwrite(fopen('php://stderr', 'wb'), 'Specify either runtemplate ID (-r) or job ID (-j) to run'.\PHP_EOL);

exit(1);
