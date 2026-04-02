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

namespace MultiFlexi\Executor;

use Symfony\Component\Process\Process;

class Fake extends \MultiFlexi\CommonExecutor implements \MultiFlexi\executor
{
    private string $commandline;
    private \MultiFlexi\ConfigFields $jobFiles;

    public function __construct(\MultiFlexi\Job &$job)
    {
        parent::__construct($job);

        foreach (getenv() as $key => $value) {
            $this->environment->addField(new \MultiFlexi\ConfigField($key, 'string', $key, '', '', $value));
        }
    }

    public static function name(): string
    {
        return _('Null');
    }

    public static function description(): string
    {
        return _('Skip real job Execution. Return random exit codes.');
    }

    public function setJob(\MultiFlexi\Job $job): void
    {
        parent::setJob($job);
        $this->jobFiles = (new \MultiFlexi\FileStore())->extractFilesForJob($this->job);
        $this->environment->addFields($this->jobFiles);
    }

    public function executable()
    {
        return $this->job->application->getDataValue('executable');
    }

    public function cmdparams()
    {
        return $this->job->getCmdParams();
    }

    public function commandline(): string
    {
        $this->job->setEnvironment($this->environment);

        return $this->executable().' '.$this->cmdparams();
    }

    public function launch($command): ?int
    {
        $this->addStatusMessage(sprintf(_('Not launching: %s'), $command), 'debug');
        sleep(\Ease\Functions::randomNumber(0, 20));

        foreach ($this->jobFiles as $file) {
            @unlink($file['value']);
        }

        return \Ease\Functions::randomNumber(0, 255);
    }

    public function launchJob(): void
    {
        $this->commandline = $this->commandline();
        $this->setDataValue('commandline', $this->commandline);
        $this->addStatusMessage('Job launch: '.$this->job->application->getDataValue('name').'@'.$this->job->company->getDataValue('name').' : '.$this->job->runTemplate->getRecordName().' Runtemplate: #'.$this->job->runTemplate->getMyKey());
        $this->launch($this->commandline);
        $this->addStatusMessage('Job launch finished: '.$this->executable().'@'.$this->job->application->getDataValue('name').' '.$this->process->getExitCodeText());
    }

    public function runJob(): void
    {
        $this->launchInBackground($this->commandline());
    }

    public function storeLogs(): void
    {
    }

    public static function usableForApp($app): bool
    {
        return \is_object($app);
    }

    public static function logo(): string
    {
        return 'images/openclipart/pd-assyrian-king-remix.svg';
    }

    public function getErrorOutput(): string
    {
        return $this->process->getErrorOutput();
    }

    public function getExitCode(): int
    {
        return $this->process->getExitCode();
    }

    public function getOutput(): string
    {
        return $this->process->getOutput();
    }

    private function launchInBackground(string $command): void
    {
        $process = Process::fromShellCommandline($command);
        $process->start();

        $process->wait(static function ($type, $buffer): void {
            if (Process::ERR === $type) {
                echo 'ERR > '.$buffer;
            } else {
                echo 'OUT > '.$buffer;
            }
        });
    }
}
