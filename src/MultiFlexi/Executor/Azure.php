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

/**
 * Execute jobs using Azure Container Instances (ACI).
 *
 * Uses the `az container` CLI to create one-shot container groups.
 * Requires the Azure CLI (`az`) to be installed and authenticated.
 *
 * Configuration is derived from environment variables:
 *   AZURE_RESOURCE_GROUP  — Azure resource group (required)
 *   AZURE_LOCATION        — Azure region (default: westeurope)
 *   AZURE_CPU             — CPU cores per container (default: 1)
 *   AZURE_MEMORY          — Memory in GB per container (default: 1.5)
 */
class Azure extends Native implements \MultiFlexi\executor
{
    private ?Process $process = null;
    private ?string $containerName = null;

    /**
     * Store container logs after job completion via `az container logs`.
     */
    public function storeLogs(): void
    {
        if ($this->containerName === null) {
            return;
        }

        $resourceGroup = $this->azureResourceGroup();

        if ($resourceGroup === '') {
            return;
        }

        $logsCmd = sprintf(
            'az container logs --resource-group %s --name %s',
            escapeshellarg($resourceGroup),
            escapeshellarg($this->containerName),
        );

        $logsProcess = Process::fromShellCommandline($logsCmd, null, null, null, 60);

        try {
            $logsProcess->run();

            if ($logsProcess->isSuccessful()) {
                $this->addOutput($logsProcess->getOutput(), 'success');
            } else {
                $this->addStatusMessage('Failed to fetch container logs: '.$logsProcess->getErrorOutput(), 'warning');
            }
        } catch (\Exception $exc) {
            $this->addStatusMessage('Exception fetching container logs: '.$exc->getMessage(), 'warning');
        }
    }

    public static function description(): string
    {
        return _('Execute jobs in Azure Container Instances');
    }

    public static function logo(): string
    {
        return 'data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iMTUwIiBoZWlnaHQ9IjE1MCIgdmlld0JveD0iMCAwIDk2IDk2IiB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciPgogICAgPGRlZnM+CiAgICAgICAgPGxpbmVhckdyYWRpZW50IGlkPSJlMzk5YzE5Zi1iNjhmLTQyOWQtYjE3Ni0xOGMyMTE3ZmY3M2MiIHgxPSItMTAzMi4xNzIiIHgyPSItMTA1OS4yMTMiIHkxPSIxNDUuMzEyIiB5Mj0iNjUuNDI2IiBncmFkaWVudFRyYW5zZm9ybT0ibWF0cml4KDEgMCAwIC0xIDEwNzUgMTU4KSIgZ3JhZGllbnRVbml0cz0idXNlclNwYWNlT25Vc2UiPgogICAgICAgICAgICA8c3RvcCBvZmZzZXQ9IjAiIHN0b3AtY29sb3I9IiMxMTRhOGIiLz4KICAgICAgICAgICAgPHN0b3Agb2Zmc2V0PSIxIiBzdG9wLWNvbG9yPSIjMDY2OWJjIi8+CiAgICAgICAgPC9saW5lYXJHcmFkaWVudD4KICAgICAgICA8bGluZWFyR3JhZGllbnQgaWQ9ImFjMmE2ZmMyLWNhNDgtNDMyNy05YTNjLWQ0ZGNjMzI1NmUxNSIgeDE9Ii0xMDIzLjcyNSIgeDI9Ii0xMDI5Ljk4IiB5MT0iMTA4LjA4MyIgeTI9IjEwNS45NjgiIGdyYWRpZW50VHJhbnNmb3JtPSJtYXRyaXgoMSAwIDAgLTEgMTA3NSAxNTgpIiBncmFkaWVudFVuaXRzPSJ1c2VyU3BhY2VPblVzZSI+CiAgICAgICAgICAgIDxzdG9wIG9mZnNldD0iMCIgc3RvcC1vcGFjaXR5PSIuMyIvPgogICAgICAgICAgICA8c3RvcCBvZmZzZXQ9Ii4wNzEiIHN0b3Atb3BhY2l0eT0iLjIiLz4KICAgICAgICAgICAgPHN0b3Agb2Zmc2V0PSIuMzIxIiBzdG9wLW9wYWNpdHk9Ii4xIi8+CiAgICAgICAgICAgIDxzdG9wIG9mZnNldD0iLjYyMyIgc3RvcC1vcGFjaXR5PSIuMDUiLz4KICAgICAgICAgICAgPHN0b3Agb2Zmc2V0PSIxIiBzdG9wLW9wYWNpdHk9IjAiLz4KICAgICAgICA8L2xpbmVhckdyYWRpZW50PgogICAgICAgIDxsaW5lYXJHcmFkaWVudCBpZD0iYTdmZWU5NzAtYTc4NC00YmIxLWFmOGQtNjNkMThlNWY3ZGI5IiB4MT0iLTEwMjcuMTY1IiB4Mj0iLTk5Ny40ODIiIHkxPSIxNDcuNjQyIiB5Mj0iNjguNTYxIiBncmFkaWVudFRyYW5zZm9ybT0ibWF0cml4KDEgMCAwIC0xIDEwNzUgMTU4KSIgZ3JhZGllbnRVbml0cz0idXNlclNwYWNlT25Vc2UiPgogICAgICAgICAgICA8c3RvcCBvZmZzZXQ9IjAiIHN0b3AtY29sb3I9IiMzY2NiZjQiLz4KICAgICAgICAgICAgPHN0b3Agb2Zmc2V0PSIxIiBzdG9wLWNvbG9yPSIjMjg5MmRmIi8+CiAgICAgICAgPC9saW5lYXJHcmFkaWVudD4KICAgIDwvZGVmcz4KICAgIDxwYXRoIGZpbGw9InVybCgjZTM5OWMxOWYtYjY4Zi00MjlkLWIxNzYtMThjMjExN2ZmNzNjKSIgZD0iTTMzLjMzOCA2LjU0NGgyNi4wMzhsLTI3LjAzIDgwLjA4N2E0LjE1MiA0LjE1MiAwIDAgMS0zLjkzMyAyLjgyNEg4LjE0OWE0LjE0NSA0LjE0NSAwIDAgMS0zLjkyOC01LjQ3TDI5LjQwNCA5LjM2OGE0LjE1MiA0LjE1MiAwIDAgMSAzLjkzNC0yLjgyNXoiLz4KICAgIDxwYXRoIGZpbGw9IiMwMDc4ZDQiIGQ9Ik03MS4xNzUgNjAuMjYxaC00MS4yOWExLjkxMSAxLjkxMSAwIDAgMC0xLjMwNSAzLjMwOWwyNi41MzIgMjQuNzY0YTQuMTcxIDQuMTcxIDAgMCAwIDIuODQ2IDEuMTIxaDIzLjM4eiIvPgogICAgPHBhdGggZmlsbD0idXJsKCNhYzJhNmZjMi1jYTQ4LTQzMjctOWEzYy1kNGRjYzMyNTZlMTUpIiBkPSJNMzMuMzM4IDYuNTQ0YTQuMTE4IDQuMTE4IDAgMCAwLTMuOTQzIDIuODc5TDQuMjUyIDgzLjkxN2E0LjE0IDQuMTQgMCAwIDAgMy45MDggNS41MzhoMjAuNzg3YTQuNDQzIDQuNDQzIDAgMCAwIDMuNDEtMi45bDUuMDE0LTE0Ljc3NyAxNy45MSAxNi43MDVhNC4yMzcgNC4yMzcgMCAwIDAgMi42NjYuOTcySDgxLjI0TDcxLjAyNCA2MC4yNjFsLTI5Ljc4MS4wMDdMNTkuNDcgNi41NDR6Ii8+CiAgICA8cGF0aCBmaWxsPSJ1cmwoI2E3ZmVlOTcwLWE3ODQtNGJiMS1hZjhkLTYzZDE4ZTVmN2RiOSkiIGQ9Ik02Ni41OTUgOS4zNjRhNC4xNDUgNC4xNDUgMCAwIDAtMy45MjgtMi44MkgzMy42NDhhNC4xNDYgNC4xNDYgMCAwIDEgMy45MjggMi44MmwyNS4xODQgNzQuNjJhNC4xNDYgNC4xNDYgMCAwIDEtMy45MjggNS40NzJoMjkuMDJhNC4xNDYgNC4xNDYgMCAwIDAgMy45MjctNS40NzJ6Ii8+CjxzY3JpcHQgeG1sbnM9IiIvPjwvc3ZnPg==';
    }

    public static function name(): string
    {
        return _('Azure');
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
     * Override setJob to skip file-path env vars for container context.
     * Host file paths are not available inside ACI containers.
     */
    public function setJob(\MultiFlexi\Job $job): void
    {
        \MultiFlexi\CommonExecutor::setJob($job);

        $fileStore = new \MultiFlexi\FileStore();
        $jobFiles = $fileStore->extractFilesForJob($this->job);

        foreach ($jobFiles as $file) {
            $this->addStatusMessage(sprintf(
                'Skipping file-path env var %s for Azure execution (host path not available in container)',
                $file->getKey(),
            ), 'warning');
        }
    }

    /**
     * Return the az container create command used (or to be used) for this job.
     */
    public function commandline(): string
    {
        $stored = $this->getDataValue('commandline');

        if (\is_string($stored) && $stored !== '') {
            return $stored;
        }

        $image = $this->job->getApplication()->getDataValue('ociimage');
        $resourceGroup = $this->azureResourceGroup();

        return sprintf(
            'az container create --resource-group %s --name mf-job-* --image %s --restart-policy Never --command-line "%s %s"',
            escapeshellarg($resourceGroup),
            escapeshellarg((string) $image),
            escapeshellarg($this->executable()),
            $this->cmdparams(),
        );
    }

    /**
     * Launch a command and stream output.
     */
    public function launch(string $command): ?int
    {
        $this->process = Process::fromShellCommandline($command, null, $this->environment?->getEnvArray() ?? null, null, $this->timeout ?? 32767);

        try {
            $this->process->run(function ($type, $buffer): void {
                if ($this->process) {
                    $this->pid = $this->process->getPid();

                    if ($this->pid) {
                        $this->job->setPid($this->pid);
                    }
                }

                if (Process::ERR === $type) {
                    $this->addOutput($buffer, 'error');
                } else {
                    $this->addOutput($buffer, 'success');
                }
            });
        } catch (\Exception $exc) {
            $this->addStatusMessage($exc->getMessage(), 'error');
        }

        return $this->process?->getExitCode();
    }

    /**
     * Launch job in Azure Container Instances using `az container create`.
     *
     * Flow:
     * 1. Create container group with --restart-policy Never (one-shot)
     * 2. Wait for container to reach terminal state
     * 3. Collect logs
     * 4. Cleanup container group
     */
    public function launchJob(): void
    {
        if (\MultiFlexi\Application::doesBinaryExist('az') === false) {
            $this->addStatusMessage('Azure CLI (az) binary is not available in PATH', 'error');

            return;
        }

        $resourceGroup = $this->azureResourceGroup();

        if ($resourceGroup === '') {
            $this->addStatusMessage('AZURE_RESOURCE_GROUP environment variable is not set', 'error');

            return;
        }

        $this->job->setEnvironment($this->environment);

        $image = $this->job->getApplication()->getDataValue('ociimage');
        $cmd = $this->executable();
        $params = $this->cmdparams();
        $location = $this->azureLocation();
        $cpu = $this->azureCpu();
        $memory = $this->azureMemory();

        $containerName = 'mf-job-'.($this->job->getMyKey() ?? time()).'-'.substr(md5((string) random_int(1, \PHP_INT_MAX)), 0, 6);
        $this->containerName = $containerName;

        // Build environment variables flags
        $envVars = [];
        $secureEnvVars = [];

        foreach ($this->environment->getEnvArray() as $k => $v) {
            // Route password-like vars to secure environment variables
            if (str_contains(strtolower($k), strtolower('PASSWORD')) || str_contains(strtolower($k), strtolower('SECRET')) || str_contains(strtolower($k), strtolower('TOKEN')) || str_contains(strtolower($k), strtolower('KEY'))) {
                $secureEnvVars[] = $k.'='.$v;
            } else {
                $envVars[] = $k.'='.$v;
            }
        }

        $envString = '';

        if ($envVars !== []) {
            $envString .= ' --environment-variables '.implode(' ', array_map('escapeshellarg', $envVars));
        }

        if ($secureEnvVars !== []) {
            $envString .= ' --secure-environment-variables '.implode(' ', array_map('escapeshellarg', $secureEnvVars));
        }

        // Build the az container create command
        $command = sprintf(
            'az container create --resource-group %s --name %s --image %s --location %s --restart-policy Never --cpu %s --memory %s%s --command-line %s --no-wait',
            escapeshellarg($resourceGroup),
            escapeshellarg($containerName),
            escapeshellarg($image),
            escapeshellarg($location),
            escapeshellarg($cpu),
            escapeshellarg($memory),
            $envString,
            escapeshellarg($cmd.' '.$params),
        );

        $this->setDataValue('commandline', $command);
        $this->addStatusMessage('Azure ACI job launch: '.$containerName);

        // Create the container group
        $createExit = $this->launch(trim($command));

        if ($createExit !== 0) {
            $this->addStatusMessage('Failed to create Azure container group', 'error');

            return;
        }

        // Wait for container to reach terminal state
        $this->addStatusMessage('Waiting for Azure container to complete...', 'info');
        $exitCode = $this->waitForCompletion($resourceGroup, $containerName);

        // Collect logs
        $this->storeLogs();

        // Cleanup
        $this->cleanupContainer($resourceGroup, $containerName);

        $this->addStatusMessage('Azure ACI job finished with exit code: '.$exitCode);
    }

    public function getErrorOutput(): string
    {
        return $this->process?->getErrorOutput() ?? '';
    }

    public function getExitCode(): int
    {
        return $this->process?->getExitCode() ?? 0;
    }

    public function getOutput(): string
    {
        return $this->process?->getOutput() ?? '';
    }

    /**
     * Wait for Azure container to reach a terminal state (Succeeded/Failed/Stopped).
     * Polls `az container show` every 10 seconds.
     */
    private function waitForCompletion(string $resourceGroup, string $containerName): int
    {
        $maxAttempts = 360; // 1 hour max at 10s intervals
        $attempt = 0;

        while ($attempt < $maxAttempts) {
            ++$attempt;
            sleep(10);

            $showCmd = sprintf(
                'az container show --resource-group %s --name %s --query "containers[0].instanceView.currentState" --output json',
                escapeshellarg($resourceGroup),
                escapeshellarg($containerName),
            );

            $showProcess = Process::fromShellCommandline($showCmd, null, null, null, 30);
            $showProcess->run();

            if (!$showProcess->isSuccessful()) {
                $this->addStatusMessage('Failed to query container status (attempt '.$attempt.')', 'warning');

                continue;
            }

            $state = json_decode($showProcess->getOutput(), true);

            if (!\is_array($state)) {
                continue;
            }

            $stateStr = strtolower((string) ($state['state'] ?? ''));

            if (\in_array($stateStr, ['succeeded', 'failed', 'stopped', 'terminated'], true)) {
                $exitCode = (int) ($state['exitCode'] ?? 1);
                $this->addStatusMessage(sprintf('Container reached state: %s (exit code: %d)', $stateStr, $exitCode));

                return $exitCode;
            }
        }

        $this->addStatusMessage('Timed out waiting for Azure container to complete', 'error');

        return 1;
    }

    /**
     * Delete the Azure container group after job completion.
     */
    private function cleanupContainer(string $resourceGroup, string $containerName): void
    {
        $deleteCmd = sprintf(
            'az container delete --resource-group %s --name %s --yes',
            escapeshellarg($resourceGroup),
            escapeshellarg($containerName),
        );

        $this->launch(trim($deleteCmd));
    }

    /**
     * Get Azure resource group from environment.
     */
    private function azureResourceGroup(): string
    {
        return (string) (getenv('AZURE_RESOURCE_GROUP') ?: ($this->environment?->getEnvArray()['AZURE_RESOURCE_GROUP'] ?? ''));
    }

    /**
     * Get Azure location from environment.
     */
    private function azureLocation(): string
    {
        return (string) (getenv('AZURE_LOCATION') ?: ($this->environment?->getEnvArray()['AZURE_LOCATION'] ?? 'westeurope'));
    }

    /**
     * Get Azure container CPU cores from environment.
     */
    private function azureCpu(): string
    {
        return (string) (getenv('AZURE_CPU') ?: ($this->environment?->getEnvArray()['AZURE_CPU'] ?? '1'));
    }

    /**
     * Get Azure container memory (GB) from environment.
     */
    private function azureMemory(): string
    {
        return (string) (getenv('AZURE_MEMORY') ?: ($this->environment?->getEnvArray()['AZURE_MEMORY'] ?? '1.5'));
    }
}
