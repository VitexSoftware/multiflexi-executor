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

/**
 * Execute jobs in one-shot Docker containers.
 *
 * Builds: docker run --rm --env-file <tmp> [--network=…] --entrypoint <cmd> <ociimage> <params>
 *
 * Host configuration (see docs):
 *   - Package multiflexi-executor-docker (adds multiflexi user to the docker group)
 *   - Docker Engine installed and running
 *   - Optional MULTIFLEXI_DOCKER_NETWORK, MULTIFLEXI_DOCKER_PULL in multiflexi.env
 */
class Docker extends Native implements \MultiFlexi\executor
{
    private ?string $writtenEnvFile = null;

    public static function name(): string
    {
        return _('Docker');
    }

    public static function description(): string
    {
        return _('Execute jobs in container using Docker');
    }

    /**
     * Can this Executor execute given application ?
     *
     * @param Application $app
     */
    public static function usableForApp($app): bool
    {
        return empty($app->getDataValue('ociimage')) === false;
    }

    /**
     * Skip host file-path env vars — paths are meaningless inside the container.
     */
    public function setJob(\MultiFlexi\Job $job): void
    {
        \MultiFlexi\CommonExecutor::setJob($job);

        $fileStore = new \MultiFlexi\FileStore();
        $jobFiles = $fileStore->extractFilesForJob($this->job);

        foreach ($jobFiles as $file) {
            $this->addStatusMessage(sprintf(
                'Skipping file-path env var %s for Docker execution (host path not available in container)',
                $file->getKey(),
            ), 'warning');
        }
    }

    public function executable()
    {
        return 'docker';
    }

    /**
     * Build `docker run …` arguments (without the leading `docker` binary).
     */
    public function cmdparams()
    {
        $envFile = $this->writeEnvFile();
        $image = (string) ($this->job->getApplication()->getDataValue('ociimage') ?? '');
        $entrypoint = (string) ($this->job->getApplication()->getDataValue('executable') ?? '');
        $params = trim((string) $this->job->getCmdParams());

        $parts = [
            'run',
            '--rm',
            '--env-file',
            escapeshellarg($envFile),
        ];

        $network = $this->dockerNetwork();

        if ($network !== '') {
            $parts[] = '--network='.escapeshellarg($network);
        }

        if ($entrypoint !== '') {
            $parts[] = '--entrypoint';
            $parts[] = escapeshellarg($entrypoint);
        }

        $parts[] = escapeshellarg($image);

        if ($params !== '') {
            $parts[] = $params;
        }

        return implode(' ', $parts);
    }

    public function commandline(): string
    {
        $stored = $this->getDataValue('commandline');

        if (\is_string($stored) && $stored !== '') {
            return $stored;
        }

        return $this->executable().' '.$this->cmdparams();
    }

    public function launchJob(): void
    {
        if (\MultiFlexi\Application::doesBinaryExist('docker') === false) {
            $this->addStatusMessage('docker binary is not available in PATH', 'error');

            return;
        }

        $image = (string) ($this->job->getApplication()->getDataValue('ociimage') ?? '');

        if ($image === '') {
            $this->addStatusMessage('Application ociimage is empty; Docker executor requires a container image', 'error');

            return;
        }

        if ($this->shouldPullImage()) {
            $this->pullImage($image);
        }

        parent::launchJob();
        $this->cleanupEnvFile();
    }

    /**
     * Temporary env-file path for `docker run --env-file`.
     */
    public function envFile(): string
    {
        return sys_get_temp_dir().'/multiflexi-docker-'.$this->job->getMyKey().'.env';
    }

    public static function logo(): string
    {
        return 'data:image/svg+xml;base64,'.base64_encode(
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 640 512">'
            .'<path fill="#2496ED" d="M349.9 236.3h-66.1v-59.4h66.1v59.4zm72.8-80h-66.1v59.4h66.1v-59.4zm72.8 80h-66.1v-59.4h66.1v59.4zm-218.4-80H211v59.4h66.1v-59.4zm-72.8 0H138.2v59.4h66.1v-59.4zm0 80H138.2v59.4h66.1v-59.4zm72.8 0H211v59.4h66.1v-59.4zM0 242.5c0 16.2 13.1 29.4 29.4 29.4h33.4v46.3c0 53.5 43.4 96.9 96.9 96.9h292.5c53.5 0 96.9-43.4 96.9-96.9v-46.3h32.6c16.2 0 29.4-13.1 29.4-29.4V182c0-16.2-13.1-29.4-29.4-29.4H29.4C13.1 152.6 0 165.8 0 182v60.5z"/>'
            .'</svg>'
        );
    }

    /**
     * Docker network name from MULTIFLEXI_DOCKER_NETWORK (empty = Docker default).
     */
    private function dockerNetwork(): string
    {
        return (string) (getenv('MULTIFLEXI_DOCKER_NETWORK') ?: ($this->environment?->getEnvArray()['MULTIFLEXI_DOCKER_NETWORK'] ?? ''));
    }

    /**
     * When MULTIFLEXI_DOCKER_PULL is always/true/1, pull the image before run.
     */
    private function shouldPullImage(): bool
    {
        $raw = strtolower((string) (getenv('MULTIFLEXI_DOCKER_PULL') ?: ($this->environment?->getEnvArray()['MULTIFLEXI_DOCKER_PULL'] ?? '')));

        return \in_array($raw, ['1', 'true', 'yes', 'always'], true);
    }

    private function pullImage(string $image): void
    {
        $this->addStatusMessage('Docker pull: '.$image, 'info');
        $process = \Symfony\Component\Process\Process::fromShellCommandline(
            'docker pull '.escapeshellarg($image),
            null,
            null,
            null,
            600,
        );

        try {
            $process->run();
        } catch (\Exception $exc) {
            $this->addStatusMessage('docker pull exception: '.$exc->getMessage(), 'warning');

            return;
        }

        if ($process->getExitCode() !== 0) {
            $this->addStatusMessage('docker pull failed for '.$image.': '.$process->getErrorOutput(), 'warning');
        }
    }

    private function writeEnvFile(): string
    {
        $path = $this->envFile();
        $lines = [];

        foreach ($this->environment->getEnvArray() as $key => $value) {
            $safe = str_replace(["\0", "\n", "\r"], '', (string) $value);
            $lines[] = $key.'='.$safe;
        }

        file_put_contents($path, implode("\n", $lines).($lines === [] ? '' : "\n"));
        $this->writtenEnvFile = $path;

        return $path;
    }

    private function cleanupEnvFile(): void
    {
        $path = $this->writtenEnvFile ?? $this->envFile();

        if (\is_string($path) && $path !== '' && is_file($path)) {
            @unlink($path);
        }

        $this->writtenEnvFile = null;
    }
}
