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

    /**
     * Store pod logs after job completion via `kubectl logs`.
     */
    public function storeLogs(): void
    {
        if ($this->podName === null || $this->kubeconfig === null) {
            return;
        }

        $kubernetes = $this->kubernetesConfig();
        $namespace = self::helmNamespace(\is_array($kubernetes['helm'] ?? null) ? $kubernetes['helm'] : []);
        $namespaceFlag = $namespace ? '--namespace='.escapeshellarg($namespace) : '';

        $logsCmd = sprintf(
            'kubectl --kubeconfig=%s %s logs %s --tail=1000',
            escapeshellarg($this->kubeconfig),
            $namespaceFlag,
            escapeshellarg($this->podName),
        );

        $logsProcess = Process::fromShellCommandline(trim($logsCmd), null, null, null, 60);

        try {
            $logsProcess->run();

            if ($logsProcess->isSuccessful()) {
                $this->addOutput($logsProcess->getOutput(), 'success');
            } else {
                $this->addStatusMessage('Failed to fetch pod logs: '.$logsProcess->getErrorOutput(), 'warning');
            }
        } catch (\Exception $exc) {
            $this->addStatusMessage('Exception fetching pod logs: '.$exc->getMessage(), 'warning');
        }
    }

    public static function description(): string
    {
        return _('Execute jobs in container using Kubernetes');
    }

    public static function logo(): string
    {
        return 'data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZpZXdCb3g9IjAgMCAyNTguNTIgMjUyLjUzIj48ZGVmcz48c3R5bGU+LmNscy0xe2ZpbGw6IzMyNmNlNX08L3N0eWxlPjwvZGVmcz48cGF0aCBjbGFzcz0iY2xzLTEiIGQ9Ik0xMjkuMjUgMGMtNC4wOSAwLTguMTEgMS41LTExLjI1IDQuMjVMOS4wNCA5NS40MmMtNi4wOCA1LjMzLTguMjYgMTMuNy01LjQ1IDIwLjk1bDQxLjc1IDEwNy44OWMzIDcuNzggMTAuMzkgMTIuOTkgMTguNjMgMTMuMTJsMTE0LjkzIDEuOTNjOC4yOC0uMDYgMTUuNjktNS4yNSAxOC43My0xMy4wN2w0Mi40NC0xMDkuMTRjMi42Ny03LjE5LjQ4LTE1LjQzLTUuNDctMjAuNTlMDEyNi40NiA0LjI1Yy0zLjA3LTIuODMtNy4xMi00LjI1LTExLjIxLTQuMjV6Ii8+PHBhdGggZD0iTTEyOS4yOCAyOC44OWExMDMuMTUgMTAzLjE1IDAgMCAwLTUuMjYuMTcgNC41OSA0LjU5IDAgMCAwLTMuNDQgMS45OCA0LjUxIDQuNTEgMCAwIDAtLjYzIDMuMDVjLjA5IDEuMjcuMiAyLjgxLjI3IDQuMDUuMDQgMi40OC0uMDkgNC4zMi0uMTkgNi43OS0uMTIgMS41Ny0uNzcgMi40LTEuNDMgMy40OGwtLjM1LjU4Yy0uNzMgMS4yMy0xLjU2IDIuNDItMS40NCAzLjk5LjA0LjUzLjIzIDEuMDguNTIgMS41NmwuMDcuMTFjMi40NCA0LjY3IDUuNTkgOS4yNCA5LjQ2IDEzLjQxbC45OS0uOTEgMS40Ny0xLjQ3LjYzLS42N2MzLjU0LTMuMTUgNy4yLTYuMDIgMTEuMTctOC41LjQ2LS40Mi42NC0xLjA2LjcyLTEuOGwuMDYtLjc3Yy4wNS0xLjQzLjA4LTIuODcuMTgtNC4yOC4xNS0yLjQ0LjU4LTQuMjMuOS02LjYuMi0xLjIxLjQzLTIuNTguNjQtMy44NmE0LjU3IDQuNTcgMCAwIDAtLjU2LTMuMTUgNC41OCA0LjU4IDAgMCAwLTIuNTUtMi4wM2MtMy42Mi0xLjIyLTcuMzctMi4xMy0xMS4yLTIuNzQtLjE3LS4wMy0uMzQtLjA5LS41MS0uMTF6bS0xMC45NiAzMi41OGE4My4yOCA4My4yOCAwIDAgMC0yMC4xNiAxMC4xbC40Mi43Mi42MiAxLjAxLjM4LjU2YzIuMDQgMy45NCAzLjg3IDguMDMgNS4zOCAxMi4yOS4yNy41NC4wOSAxLjIxLS4xMiAxLjkybC0uMjQuNzNjLS40MiAxLjM2LS44NyAyLjctMS4yNCA0LjA4LTEuMjQgMi4xMy0yLjcgMy42NS00LjIzIDUuMjRsLTEuMDIgMS4wN2MtLjk3Ljk2LTEuOTUgMS42NC0yLjg3IDIuMDlsLS4xLjA0Yy0uNjMuMjktMS4yMS42My0xLjYxIDEuMTJhNjIuODIgNjIuODIgMCAwIDAtMi4yNSAxNi4xMmMuNzEuMTcgMS40LjM2IDIuMTQuNDcgMS45NS40IDMuOTguOCA1LjguOTVoLjAzbC45MS4xN2M0LjI4LjQgOC42NS41NyAxMi44NC0uMjIuNDktLjE2Ljk4LS41NCAxLjQ0LTEuMWwuNTItLjU4YzEuMDItMS4wOCAyLjA0LTIuMTQgMy4xLTMuMTQgMS44MS0xLjk0IDMuNjEtMi45MSA1LjUyLTMuOTRsMS4yMi0uNjZhNi4xMiA2LjEyIDAgMCAxIDMuMy0uNzRjNS4yNi41MSAxMC42NSAxLjcgMTUuOTUgMy42NmwuOTItLjI5YTk0LjQ4IDk0LjQ4IDAgMCAwIDEuOTMtNS40NCA4Mi44IDgyLjggMCAwIDAtMy42Mi0xNS4yNSA2LjMgNi4zIDAgMCAxLTIuMjYtMSA4OS43MiA4OS43MiAwIDAgMC0xLjA5LS44MyA4MS40MiA4MS40MiAwIDAgMS00Ljc1LTQuNjhjLTEuMy0xLjMxLTIuMjQtMi43My0zLjI0LTQuMjFsLS41OC0uODVjLS40MS0uNjQtLjYxLTEuMzYtLjU4LTIuMTIuMTQtMy44MS40LTcuNDggMS4wMi0xMS4wNmwtLjcyLS42LTEuMzUtMS4wOS0uNjYtLjQ4YTgxLjcgODEuNyAwIDAgMC0xMC4xOS02LjA1em0yMi44NC42OGMuNTUgMy4zMi43NCA2LjcuNjMgMTAuMDIuMTguOTMuNzcgMS43NyAxLjQxIDIuNjggMS42NyAyLjQgMy42NSA0LjcgNi4xMiA2LjQxLjQ4LjM2IDEuMTIuNDggMS44MS4zMyA0LjE3LTEuNTIgOC41LTIuNCAxMi44OS0yLjcxIDQuOS0uMzQgOS42MS4wNiAxNC4wMi44IDIuMi44NyA0LjM3IDEuOTIgNi40MiAzLjE0YTgzLjM0IDgzLjM0IDAgMCAwLTIuOTMtMTUuMTMgODMuMTcgODMuMTcgMCAwIDAtNDAuMzctNS41NHpNNzguMiA4MC41NmE4My4zMyA4My4zMyAwIDAgMC0xNy40OCAyMy45OGw1LjAzIDMuMjguOTIuNThjMy43NyAyLjgyIDcuMjcgNS44OSAxMC40MiA5LjIxLjE0LjE1LjI2LjMzLjM1LjU1LjI2LjYxLjI3IDEuMy4wMyAyLjA0bC0uMTMuNjljLS4yNyAxLjQtLjU2IDIuODItLjc5IDQuMjQtLjUxIDIuNC0uMzEgNC4zOS0uMDcgNi41MWwuMTQgMS40OGMuMTMgMS4wNy0uMDIgMi4wMi0uNDQgMi44NGwtLjA1LjFjLS4zLjU5LS41NCAxLjI0LS42IDEuOTNhODMuMjkgODMuMjkgMCAwIDAgMi44NSAxNi4xNmMuNjUtLjIxIDEuMjktLjQ2IDEuOTItLjcgMS43NC0uNzIgMy41NC0xLjQ1IDUuMTMtMi4wNmguMDNsLjg4LS4zM2M0LjEzLTEuMjggOC4zNy0yLjMyIDEyLjctMi4yNy41MS4wMSAxLjA2LjI0IDEuNTYuNjVsLjU4LjQ2YzEuMSAxLjAyIDIuMTkgMi4wNiAzLjI0IDMuMTUgMS44MiAxLjY5IDIuODkgMy41MiA0LjAyIDUuNDNsLjcyIDEuMTljLjMyLjU1LjggLjk0IDEuNDQgMS4xMiA1LjA2IDEuNDggMTAuMzcgMi4yNSAxNS43NCAyLjM0bC4xNS0uOTZjLjItMS4yLjQtMi40NC42NS0zLjU1LjQ0LTMuMy45NC02LjcgMS44LTkuOTUuMTEtLjU0LjUzLTEuMDQgMS4xLTEuNDlsLjY0LS40NmMxLjI0LS44NiAyLjQ4LTEuNjkgMy43Ni0yLjQ2IDIuMTYtMS40NCA0LjEzLTIuMTIgNi4yMy0yLjgzbDEuMzktLjQ4Yy45Mi0uMjggMS44LS4yMiAyLjYxLjE3bC4xLjA1Yy42My4yOSAxLjI4LjUzIDEuOTguNjdhODMuMjYgODMuMjYgMCAwIDAgNS4xOS0xNS40MmwtLjYyLS4yMy0xLjYtLjU0LS42NS0uMjFjLTQuMjctMS4zOS04LjI2LTMuMDgtMTEuODYtNS4yMS0uMjctLjE2LS41MS0uNDctLjctLjlsLS4zLS42MWMtLjYzLTEuMjktMS4yNS0yLjU5LTEuODQtMy45Mi0xLjA1LTIuMTgtMS4zOS00LjA5LTEuNzQtNi4xM2wtLjI0LTEuNDdjLS4wOS0xLjA4LjE5LTIuMDIuNzgtMi44bC4wNy0uMWMuNDEtLjU0LjcxLTEuMTYuOS0xLjgyYTgyLjM0IDgyLjM0IDAgMCAwLTkuODYtMTMuMTljLS40OC40MS0uOTMuODctMS40IDEuMjktMS4yNiAxLjI2LTIuNTggMi41Ny0zLjg3IDMuNTctMy4yNSAyLjktNi43NCA1LjUzLTEwLjQ0IDcuODQtLjU0LjMtMS4xNy4zMy0xLjg1LjFsLS43NC0uMjRjLTEuMzYtLjQzLTIuNzEtLjg4LTQuMDgtMS4yNi0yLjQtLjgyLTQuMTUtMS45NC02LjAyLTMuMTVsLTEuMTctLjc3Yy0uNjQtLjQxLTEuMDEtMS4wMS0xLjEzLTEuOHptOTcuOTMuOWE4My41MyA4My41MyAwIDAgMC05Ljg5IDEzLjM1Yy4yNi41NC4yOSAxLjE0LjA2IDEuODMtLjU2IDIuNTItLjc2IDUuMS0uNjEgNy42NC40MiAzLjUyIDEuMTcgNi45OCAyLjIzIDEwLjI3LjIuNTguNjIgMS4wNyAxLjI0IDEuNDIgMy44NiAyLjI3IDcuNTcgNC4wNCAxMS4xIDUuMzIuOTMuMjQgMS44NS41NSAyLjgyLjczYTgzLjM0IDgzLjM0IDAgMCAwLTUuMjItMTUuMzIgODMuMjMgODMuMjMgMCAwIDAtMS43My0yNS4yNHpNNTguOTcgMTE2LjE2YTgzLjIzIDgzLjIzIDAgMCAwLS41NCAzLjIgODMuMzggODMuMzggMCAwIDAgOS4yNyAyMy41OSA4My44IDgzLjggMCAwIDAgNS41MyA3Ljg2Yy4xNi0uNjcuNTMtMS4yNyAxLjEyLTEuNjkgMi4xLTEuNzEgNC4zNC0zLjIyIDYuNjMtNC40NyAzLjE2LTEuODIgNi4zMi0zLjEyIDkuMzktNC4zOS44OS0uMzUgMS44MS0uMzUgMi43MS4wMiAzLjM0IDEuNzcgNi45IDMuMSAxMC41OSAzLjg3LjkuMTUgMS43OS4zOCAyLjcuNTMtNS4xNy0uMTQtMTAuMjktLjkxLTE1LjE5LTIuMjYtLjk2LS4yOC0xLjctLjg3LTIuMTctMS42NWwtLjcyLTEuMTljLTEuMTMtMS45MS0yLjItMy43NC00LjAyLTUuNDMtMS4wNS0xLjA5LTIuMTQtMi4xMy0zLjI0LTMuMTVsLS41OC0uNDZjLS41LS40MS0xLjA1LS42NC0xLjU2LS42NS00LjMzLS4wNS04LjU3Ljk5LTEyLjcgMi4yN2wtLjg4LjMzaC0uMDNjLTEuNTkuNjEtMy4zOSAxLjM0LTUuMTMgMi4wNi0uNjEuMjUtMS4yNC40Ny0xLjg4LjZ6bTE0NS44NyA3LjM3YTgzLjE2IDgzLjE2IDAgMCAxLTMuNzQgMTYuMTggODMuNDYgODMuNDYgMCAwIDEtOS43IDIwLjIyYy42MS4yNiAxLjE1LjcgMS41IDEuMzQgMS41MiAyLjMxIDIuOCA0Ljc4IDMuNzYgNy4zOCAxLjM5IDMuNDkgMi4xIDYuOTggMi44MSAxMC40MS4yMi45My4xIDEuODQtLjMyIDIuNjgtMS42MSAyLjk5LTIuOCA2LjE5LTMuNTMgOS41MS0uMTQuODUtLjM3IDEuNy0uNTIgMi41NiA1Ljc5LTkuMTQgOS41My0xOS42MSAxMC43Ny0zMC42NWE4My4yNSA4My4yNSAwIDAgMCAxLjI0LTE0LjQ1Yy0uMDcuMS0uMTUuMi0uMjMuMjlsLS4wNC4wMy0xIDEuMjYtLjQ1LjQ4Yy0yLjE4IDIuODktNC42NCA1LjU4LTcuNDEgOC4wMi0uNDguMzEtMS4xLjM3LTEuODIuMTVsLS43NC0uMjljLTEuMzctLjQ3LTIuNzMtLjkyLTQuMS0xLjI5LTIuMy0uNzEtNC4yNC0uNjQtNi4zMi0uNTlsLTEuNDguMDRjLTEuMDcuMDctMi4wMi0uMjItMi44NC0uODdsLS4xLS4wN2MtLjU0LS4zOC0xLjE2LS42NS0xLjgyLS43OHpNOTIuNTIgMTY0YTYuMDcgNi4wNyAwIDAgMS0yLjk5IDEuMTVjLTIuMzYgMS40My00LjU2IDMuMS02LjQ4IDUuMDItMi42NyAyLjUtNC45IDUuMi03LjAzIDcuOTItLjYxLjc2LTEuMjkgMS4xMy0yLjAzIDEuMTZsLS4xMS0uMDFjLS42OCAwLTEuMzcuMS0yLjA1LjMxYTgyLjg4IDgyLjg4IDAgMCAwIDkuMjMgMTMuOTMgODMuMzIgODMuMzIgMCAwIDAgMjAuNDUgMTYuNDNjLS4xLS43MS0uMTYtMS40My0uMjEtMi4xMy0uMDgtMS45OS0uMTktNC4wMi0uMjMtNS44NC0uMDctNC41Ny4zLTkuMjUgMS40My0xMy41OWE2LjEgNi4xIDAgMCAxLTEuNjctMi4xNWMtMy4wMy0zLjk0LTUuNDYtOC4yNS03LjI2LTEyLjc4LS4yNC0uODQtLjU2LTEuNjktLjgtMi41NWwtLjEzLS40NC0uMTItLjQzem02OS45NyA0LjY4Yy0uMjEuMjgtLjUzLjUyLS45Ny42OS0yLjcuOTMtNS40IDEuODItNy45OSAyLjQ2LTMuNjEgMS4wNi03LjIxIDEuNTctMTAuNzEgMi4wMi0uOTUuMTItMS44MS0uMDctMi41NC0uNTgtMy43OC0xLjk2LTcuMzYtNC4zNS0xMC42Ni03LjIyLS43LS42My0xLjQ3LTEuMTgtMi4yLTEuOC0uOTMgNC4xMy0xLjMgOC40Ny0xLjA1IDEyLjc3LjA2IDIuMDUuMTcgNC4xMy4yNSA2LjE2LjAzIDEuMDQtLjI3IDEuOTgtLjg3IDIuOGwtLjA3LjFjLS40MS41NC0uNzQgMS4xNS0uOTMgMS44MWE4My4xOCA4My4xOCAwIDAgMCAyOS4yOSA4LjUgODMuMDggODMuMDggMCAwIDAgMTIuMTEtNC44NmMtLjUzLS4zOC0xLS44NC0xLjQtMS4zOC0xLjI0LTEuMjktMi41NC0yLjY0LTMuNjMtMy43OS0yLjkzLTIuOTgtNS42My01LjIzLTguMTktNy43NS0uNy0uNjQtMS4wNi0xLjQ5LTEuMDctMi40NS0uMDktMy44MS41My03LjUzIDEuNTItMTEuMDMuMTUtLjYuMzUtMS4yMy41My0xLjgyem0tMjkuMTUgMy4xNWMtLjQuMDMtLjguMTMtMS4xNC4zLS44OS40NS0xLjc2Ljk4LTIuNjIgMS41NC0yLjM0IDEuNTMtNC41NCAzLjItNi41NiA1LjAyLTEuMjcgMS4xMy0yLjE1IDIuMzMtMy4wNiAzLjU5bC0uNTkuODJjLS40OC42My0uNjcgMS4zOS0uNTMgMi4xOC41NSAzLjEzLjc1IDYuMzQuNjMgOS41My0uMDggMi4wMy0uMTkgNC4xLS4yNiA2LjEyLS4wNS42NC4xNyAxLjI0LjYzIDEuNzRsLjA4LjA4Yy40NS40Mi44My45MSAxLjExIDEuNDYgMy4wNyAyLjgzIDYuMyA1LjMyIDkuMTggOC4xMyAyLjIzIDIuMDIgNC4yIDQuMzEgNi4yMSA2LjU4IDEuMTcgMS4zNSAyLjI3IDIuMjMgMy44NyAzLjA1LjQxLjIyLjguMzQgMS4xNi4zN2wuMDgtLjAyYTgzIDgzIDAgMCAwIDEyLjE0LTM3LjA4Yy0uNjcuMTYtMS4zOS4xLTIuMTItLjItMi42MS0uOTUtNS4xMi0yLjEyLTcuNDktMy41LTMuMjctMS44Ni02LjI1LTMuOTUtOS4xOC01Ljk4LS44Ni0uNi0xLjMzLTEuMzYtMS40LTIuMjhsLS4wOC0uMTNjLS4xOC0uNjktLjI5LTEuNDItLjI5LTIuMTggMC0uMjktLjA5LS41NC0uMjgtLjA0eiIgZmlsbD0iI2ZmZiIvPjwvc3ZnPg==';
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
     * Override setJob to handle file-type config fields for k8s context.
     * File-path environment variables from the executor host are meaningless inside a pod,
     * so we skip extracting files and only keep non-file environment fields.
     */
    public function setJob(\MultiFlexi\Job $job): void
    {
        // Call grandparent (CommonExecutor::setJob) directly, skipping Native's file extraction
        \MultiFlexi\CommonExecutor::setJob($job);

        // Add file-type fields as empty values so the app knows they exist but won't reference host paths
        $fileStore = new \MultiFlexi\FileStore();
        $jobFiles = $fileStore->extractFilesForJob($this->job);

        foreach ($jobFiles as $file) {
            $this->addStatusMessage(sprintf(
                'Skipping file-path env var %s for Kubernetes execution (host path not available in pod)',
                $file->getKey(),
            ), 'warning');
        }
    }

    /**
     * Return the kubectl command line used (or to be used) for this job.
     */
    public function commandline(): string
    {
        $stored = $this->getDataValue('commandline');

        if (\is_string($stored) && $stored !== '') {
            return $stored;
        }

        // Build the expected command before launchJob() sets it
        $kubernetes = $this->kubernetesConfig();
        $helmConfig = \is_array($kubernetes['helm'] ?? null) ? $kubernetes['helm'] : [];
        $image = $this->job->getApplication()->getDataValue('ociimage');

        $home = getenv('HOME') ?: '/root';
        $kubeconfig = getenv('KUBECONFIG') ?: $home.'/.kube/config';
        $namespace = self::helmNamespace($helmConfig);

        return sprintf(
            'kubectl --kubeconfig=%s run mf-job-* --restart=Never --image=%s %s -- %s %s',
            escapeshellarg($kubeconfig),
            escapeshellarg((string) $image),
            $namespace ? '--namespace='.escapeshellarg($namespace) : '',
            escapeshellarg($this->executable()),
            $this->cmdparams(),
        );
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
            if (self::shouldRunHelm($helmConfig)) {
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
        $namespace = self::helmNamespace($helmConfig);
        $namespaceFlag = $namespace ? '--namespace='.escapeshellarg($namespace) : '';
        $artifactEnabled = self::artifactEnabled($artifactConfig);
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
     * Check whether application is already deployed in the cluster.
     * If Helm is enabled, use `helm status <release>`; otherwise check k8s deployment.
     *
     * @param array<string, mixed> $kubernetes
     * @param array<string, mixed> $helmConfig
     */
    private function isDeployed(array $kubernetes, ?string $namespace, array $helmConfig): bool
    {
        // prefer helm check when helm is configured
        if (self::shouldRunHelm($helmConfig)) {
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
     * Reconstruct kubernetes config from existing DB fields and sensible defaults.
     * No dedicated kubernetes JSON column is needed — helmchart, ociimage, and artifacts
     * columns provide all the information required.
     *
     * @return array<string, mixed>
     */
    private function kubernetesConfig(): array
    {
        $app = $this->job->getApplication();
        $helmChart = (string) ($app->getDataValue('helmchart') ?? '');

        if ($helmChart === '') {
            return [];
        }

        // Derive DNS-1123 safe release name from app name
        $appName = (string) ($app->getDataValue('name') ?? 'mf-app');
        $releaseName = strtolower(trim(preg_replace('/[^a-z0-9-]+/', '-', strtolower($appName)), '-'));

        if ($releaseName === '') {
            $releaseName = 'mf-app';
        }

        // Derive artifact config from existing artifacts column (comma-separated paths)
        $artifactsRaw = $app->getDataValue('artifacts');
        $artifactPaths = [];

        if (\is_string($artifactsRaw) && $artifactsRaw !== '') {
            $artifactPaths = array_filter(array_map('trim', explode(',', $artifactsRaw)));
        }

        return [
            'helm' => [
                'enabled' => true,
                'chart' => $helmChart,
                'releaseName' => $releaseName,
                'namespace' => 'multiflexi',
                'upgradeInstall' => true,
                'wait' => true,
                'atomic' => false,
                'timeoutSeconds' => 300,
            ],
            'artifacts' => [
                'enabled' => !empty($artifactPaths),
                'outputPath' => $artifactPaths[0] ?? '',
                'keepPodOnFailure' => false,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $helmConfig
     */
    private static function shouldRunHelm(array $helmConfig): bool
    {
        return (bool) ($helmConfig['enabled'] ?? false);
    }

    /**
     * @param array<string, mixed> $helmConfig
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

        $namespace = self::helmNamespace($helmConfig);

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
                $helmArgs[] = (string) $key.'='.self::helmSetValue($value);
            }
        }

        $helmCommand = 'KUBECONFIG='.escapeshellarg($kubeconfig).' '.implode(' ', array_map('escapeshellarg', $helmArgs));
        $this->addStatusMessage('Kubernetes Helm pre-deployment: '.$releaseName, 'warning');

        return ($this->launch($helmCommand) ?? 1) === 0;
    }

    /**
     * @param mixed $value
     */
    private static function helmSetValue($value): string
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
     * @param array<string, mixed> $helmConfig
     */
    private static function helmNamespace(array $helmConfig): ?string
    {
        $namespace = (string) ($helmConfig['namespace'] ?? '');

        return $namespace !== '' ? $namespace : null;
    }

    /**
     * @param array<string, mixed> $artifactConfig
     */
    private static function artifactEnabled(array $artifactConfig): bool
    {
        return (bool) ($artifactConfig['enabled'] ?? false);
    }

    /**
     * @param array<string, mixed> $artifactConfig
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
            mkdir($artifactDir, 0o775, true);
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
}
