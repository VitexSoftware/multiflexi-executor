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
 * Execute jobs using Kubernetes (kubectl).
 */
class Kubernetes extends Native implements \MultiFlexi\executor
{
    private ?Process $process = null;
    private ?string $podName = null;
    private ?string $kubeconfig = null;

    public function storeLogs(): void
    {
    }

    public static function description(): string
    {
        return _('Execute jobs in container using Kubernetes');
    }

    public static function logo(): string
    {
        return '';
    }

    public static function name(): string
    {
        return _('Kubernetes');
    }

    /**
     * Can this Executor execute given application ?
     *
     * @param Application $app
     */
    public static function usableForApp($app): bool
    {
        return empty($app->getDataValue('ociimage')) === false; // Container Image must be present
    }

    /**
     * Launch a command and stream output (re-uses Symfony Process handling).
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
     * Launch job in Kubernetes using `kubectl run --restart=Never --attach`.
     * Uses ~/.kube/config or the KUBECONFIG env var.
     */
    public function launchJob(): void
    {
        if (\MultiFlexi\Application::doesBinaryExist('kubectl') === false) {
            $this->addStatusMessage('kubectl binary is not available in PATH', 'error');

            return;
        }

        $this->job->setEnvironment($this->environment);

        $kubernetes = $this->kubernetesConfig();
        $helmConfig = \is_array($kubernetes['helm'] ?? null) ? $kubernetes['helm'] : [];
        $artifactConfig = \is_array($kubernetes['artifacts'] ?? null) ? $kubernetes['artifacts'] : [];

        $image = $this->job->getApplication()->getDataValue('ociimage');
        $cmd = $this->executable();
        $params = $this->cmdparams();

        $podName = 'mf-job-'.($this->job->getMyKey() ?? time()).'-'.substr(md5((string) random_int(1, \PHP_INT_MAX)), 0, 6);
        $this->podName = $podName;

        $home = getenv('HOME') ?: '/root';
        $kubeconfig = getenv('KUBECONFIG') ?: $home.'/.kube/config';
        $this->kubeconfig = $kubeconfig;

        // ensure application is deployed in cluster; if not and helm configured, deploy it
        if ($this->isDeployed($kubernetes, $namespace, $helmConfig) === false) {
            if ($this->shouldRunHelm($helmConfig)) {
                if ($this->runHelmPreDeploy($helmConfig, $kubeconfig) === false) {
                    $this->addStatusMessage('Helm pre-deployment failed, Kubernetes job was not launched', 'error');

                    return;
                }
            } else {
                $this->addStatusMessage('Application is not deployed in cluster and Helm deployment is not configured', 'error');

                return;
            }
        }

        $envFlags = [];

        foreach ($this->environment->getEnvArray() as $k => $v) {
            $envFlags[] = '--env='.escapeshellarg($k.'='.$v);
        }

        $envString = $envFlags ? implode(' ', $envFlags) : '';
        $namespace = $this->helmNamespace($helmConfig);
        $namespaceFlag = $namespace ? '--namespace='.escapeshellarg($namespace) : '';
        $artifactEnabled = $this->artifactEnabled($artifactConfig);
        $rmFlag = $artifactEnabled ? '' : '--rm';

        $command = sprintf(
            'kubectl --kubeconfig=%s run %s --restart=Never --image=%s %s %s --attach %s -- %s %s',
            escapeshellarg($kubeconfig),
            escapeshellarg($podName),
            escapeshellarg($image),
            $namespaceFlag,
            $envString,
            $rmFlag,
            escapeshellarg($cmd),
            $params,
        );

        $this->setDataValue('commandline', $command);
        $this->addStatusMessage('Kubernetes job launch: '.$podName);

        $exit = $this->launch(trim($command));

        if ($artifactEnabled) {
            $this->collectArtifacts($artifactConfig, $namespace, $podName);
            $this->cleanupPod($namespace, $podName, (bool) ($artifactConfig['keepPodOnFailure'] ?? false), (int) ($exit ?? 1));
        }

        $this->addStatusMessage('Kubernetes job finished: '.($this->process?->getExitCodeText() ?? 'n/a'));
    }

    /**
     * Check whether application is already deployed in the cluster.
     * If Helm is enabled, use `helm status <release>`; otherwise check k8s deployment.
     *
     * @param array<string,mixed> $kubernetes
     * @param array<string,mixed> $helmConfig
     */
    private function isDeployed(array $kubernetes, ?string $namespace, array $helmConfig): bool
    {
        // prefer helm check when helm is configured
        if ($this->shouldRunHelm($helmConfig)) {
            $releaseName = (string) ($helmConfig['releaseName'] ?? '');

            if ($releaseName === '') {
                return false;
            }

            $helmCmd = sprintf('helm --kubeconfig=%s status %s', escapeshellarg($this->kubeconfig ?? ''), escapeshellarg($releaseName));

            return ($this->launch(trim($helmCmd)) ?? 1) === 0;
        }

        // fallback to checking deployment existence
        $deployment = (string) ($kubernetes['deployment'] ?? $kubernetes['deploymentName'] ?? $this->job->getApplication()->getDataValue('name'));

        if ($deployment === '') {
            return false;
        }

        $kubectlCmd = sprintf('kubectl --kubeconfig=%s %s get deployment %s', escapeshellarg($this->kubeconfig ?? ''), $namespace ? '--namespace='.escapeshellarg($namespace) : '', escapeshellarg($deployment));

        return ($this->launch(trim($kubectlCmd)) ?? 1) === 0;
    }

    /**
     * @return array<string,mixed>
     */
    private function kubernetesConfig(): array
    {
        $config = $this->job->getApplication()->getDataValue('kubernetes');

        if (\is_array($config)) {
            return $config;
        }

        if (\is_object($config)) {
            return (array) $config;
        }

        if (\is_string($config) && $config !== '') {
            $decoded = json_decode($config, true);

            if (\is_array($decoded)) {
                return $decoded;
            }
        }

        return [];
    }

    /**
     * @param array<string,mixed> $helmConfig
     */
    private function shouldRunHelm(array $helmConfig): bool
    {
        return (bool) ($helmConfig['enabled'] ?? false);
    }

    /**
     * @param array<string,mixed> $helmConfig
     */
    private function runHelmPreDeploy(array $helmConfig, string $kubeconfig): bool
    {
        if (\MultiFlexi\Application::doesBinaryExist('helm') === false) {
            $this->addStatusMessage('Helm binary is not available in PATH', 'error');

            return false;
        }

        $releaseName = (string) ($helmConfig['releaseName'] ?? '');
        $chart = (string) ($helmConfig['chart'] ?? '');

        if ($releaseName === '' || $chart === '') {
            $this->addStatusMessage('Helm is enabled but releaseName/chart is missing', 'error');

            return false;
        }

        $helmArgs = [
            'helm',
        ];

        if ((bool) ($helmConfig['upgradeInstall'] ?? true)) {
            $helmArgs[] = 'upgrade';
            $helmArgs[] = '--install';
            $helmArgs[] = $releaseName;
            $helmArgs[] = $chart;
        } else {
            $helmArgs[] = 'install';
            $helmArgs[] = $releaseName;
            $helmArgs[] = $chart;
        }

        $namespace = $this->helmNamespace($helmConfig);

        if ($namespace !== null && $namespace !== '') {
            $helmArgs[] = '--namespace';
            $helmArgs[] = $namespace;
            $helmArgs[] = '--create-namespace';
        }

        if (isset($helmConfig['timeoutSeconds'])) {
            $helmArgs[] = '--timeout';
            $helmArgs[] = (string) ((int) $helmConfig['timeoutSeconds']).'s';
        }

        if ((bool) ($helmConfig['wait'] ?? true)) {
            $helmArgs[] = '--wait';
        }

        if ((bool) ($helmConfig['atomic'] ?? false)) {
            $helmArgs[] = '--atomic';
        }

        if (\is_array($helmConfig['valuesFiles'] ?? null)) {
            foreach ($helmConfig['valuesFiles'] as $valuesFile) {
                $helmArgs[] = '--values';
                $helmArgs[] = (string) $valuesFile;
            }
        }

        if (\is_array($helmConfig['set'] ?? null)) {
            foreach ($helmConfig['set'] as $key => $value) {
                $helmArgs[] = '--set';
                $helmArgs[] = (string) $key.'='.$this->helmSetValue($value);
            }
        }

        $helmCommand = 'KUBECONFIG='.escapeshellarg($kubeconfig).' '.implode(' ', array_map('escapeshellarg', $helmArgs));
        $this->addStatusMessage('Kubernetes Helm pre-deployment: '.$releaseName, 'warning');

        return ($this->launch($helmCommand) ?? 1) === 0;
    }

    /**
     * @param mixed $value
     */
    private function helmSetValue($value): string
    {
        if (\is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (\is_scalar($value)) {
            return (string) $value;
        }

        return json_encode($value) ?: '';
    }

    /**
     * @param array<string,mixed> $helmConfig
     */
    private function helmNamespace(array $helmConfig): ?string
    {
        $namespace = (string) ($helmConfig['namespace'] ?? '');

        return $namespace !== '' ? $namespace : null;
    }

    /**
     * @param array<string,mixed> $artifactConfig
     */
    private function artifactEnabled(array $artifactConfig): bool
    {
        return (bool) ($artifactConfig['enabled'] ?? false);
    }

    /**
     * @param array<string,mixed> $artifactConfig
     */
    private function collectArtifacts(array $artifactConfig, ?string $namespace, string $podName): void
    {
        $path = (string) ($artifactConfig['outputPath'] ?? '');

        if ($path === '') {
            $this->addStatusMessage('Artifact extraction enabled, but outputPath is empty', 'warning');

            return;
        }

        $artifactDir = sys_get_temp_dir().'/multiflexi-artifacts/'.($this->job->getMyKey() ?? time());

        if (is_dir($artifactDir) === false) {
            mkdir($artifactDir, 0775, true);
        }

        $targetFile = $artifactDir.'/'.basename($path);

        $copyCmd = sprintf(
            'kubectl --kubeconfig=%s %s cp %s %s',
            escapeshellarg($this->kubeconfig ?? ''),
            $namespace ? '--namespace='.escapeshellarg($namespace) : '',
            escapeshellarg($podName.':'.$path),
            escapeshellarg($targetFile),
        );

        $copyExit = $this->launch(trim($copyCmd));

        if ($copyExit === 0) {
            $this->addStatusMessage('Artifact copied to '.$targetFile, 'success');

            // store artifact in DB for the job
            try {
                $field = (string) ($artifactConfig['field'] ?? 'artifact');
                $fileStore = new \MultiFlexi\FileStore();

                if ($fileStore->storeFileForJob($field, $targetFile, basename($path), $this->job) === true) {
                    $this->addStatusMessage('Artifact stored in file store for job', 'success');
                } else {
                    $this->addStatusMessage('Failed to store artifact into file store', 'warning');
                }
            } catch (\Exception $exc) {
                $this->addStatusMessage('Exception storing artifact: '.$exc->getMessage(), 'warning');
            }
        } else {
            $this->addStatusMessage('Artifact copy failed for '.$path, 'warning');
        }
    }

    private function cleanupPod(?string $namespace, string $podName, bool $keepPodOnFailure, int $exitCode): void
    {
        if ($keepPodOnFailure && $exitCode !== 0) {
            $this->addStatusMessage('Pod '.$podName.' kept for troubleshooting', 'warning');

            return;
        }

        $deleteCmd = sprintf(
            'kubectl --kubeconfig=%s %s delete pod %s --ignore-not-found=true',
            escapeshellarg($this->kubeconfig ?? ''),
            $namespace ? '--namespace='.escapeshellarg($namespace) : '',
            escapeshellarg($podName),
        );

        $this->launch(trim($deleteCmd));
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
}
