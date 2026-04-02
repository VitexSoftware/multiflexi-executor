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
 * Description of Podman.
 */
class Podman extends Native implements \MultiFlexi\executor
{
    use \Ease\Logger\Logging;
    public const PULLCOMMAND = 'podman pull docker.io/vitexsoftware/debian:bookworm';

    public static function name(): string
    {
        return _('Podman');
    }

    public static function description(): string
    {
        return _('Execute jobs in container using Podman');
    }

    public function pullImage(): void
    {
    }

    public function launchContainer(): void
    {
    }

    public function updateContainer(): void
    {
    }

    public function deployApp(): void
    {
    }

    public function runApp(): void
    {
    }

    public function storeLogs(): void
    {
    }

    public function stopContainer(): void
    {
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

    public static function logo(): string
    {
        return 'data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcm'
        .'cvMjAwMC9zdmciIHZpZXdCb3g9IjAgMCAxMjggMTI4Ij48cGF0aCBmaWxsPSIjM2M2ZWI'
                .'0IiBkPSJNOTMuMTg4IDU5LjgwM2MuMTQ4LjkyNy4zIDEuODU0LjQ4MiAyLjc2'
                .'aDguOTU1di0yLjc2em0xLjQ4NCA2Ljg5NmMuMTg1LjYzNC4zODEgMS4yNjUuN'
                .'Tk4IDEuODkzLjUyLjI1OSAxLjAyNy41NDggMS41MTcuODdoMTEuMzUydi0yLj'
                .'c2M3ptLTE4LjQzMSAxLjI4MmMtLjQyNi4xNTUtLjg0My4zMzYtMS4yNTIuNTMz'
                .'di4wMDRjLjQxLS4xOTguODI1LS4zODIgMS4yNTItLjUzN3ptLTQxLjI0OCAzN'
                .'C41N3YyLjc2SDY4LjE1di0yLjc2em0xOS4zMSA2Ljg5NnYyLjc1Mkg4MS42di'
                .'0yLjc1MnoiLz48cGF0aCBmaWxsPSIjY2NjIiBkPSJNNjEuMTE0IDE2LjI2Yy0'
                .'0LjkxIDAtOS43MjEuNTY3LTEyLjU4NiAxLjcwNy03Ljg5IDIuODg4LTEzLjk1'
                .'IDEwLjc3NS0xNS43NDYgMjAuMzMtMi4zNjUgMTEuMTg2LTIuMjI0IDE5Ljk0M'
                .'S00LjU3MiAyOC4xNzhhMTMuNTg5IDEzLjU4OSAwIDAgMSAzLjUyOS0xLjk0Yz'
                .'EuODM0LS43MjYgNC45MDktMS4wOSA4LjA0OS0xLjA5IDMuMTQgMCA2LjM1LjM'
                .'2NSA4LjQzMSAxLjA5IDQuNTcxIDEuNjcgOC4xNzcgNS45NiA5LjY3IDExLjI4'
                .'NGg5LjcxOWMxLjk5MS0zLjY4MSA1LjAzLTYuNTIzIDguNjMzLTcuODM4IDMuO'
                .'TE5LTEuNTU0IDEzLjE2NS0xLjU1IDE3LjYxNyAwIC40ODMuMTc2Ljk1Mi4zOS'
                .'AxLjQxNC42MTktMy4wNjItOC44NjYtMi42NzMtMTguMTUxLTUuMjQyLTMwLjM'
                .'wMy0xLjc5NS05LjU1NS03Ljg1Ny0xNy40NDItMTUuNzQ2LTIwLjMzLTMuMjU0'
                .'LTEuMTM2LTguMjYxLTEuNzA2LTEzLjE3LTEuNzA3WiIvPjxwYXRoIGZpbGw9I'
                .'iNlN2U4ZTkiIGQ9Ik00NS4yNzUgNDkuNzg3YTMuMzQ0IDMuNTIzIDAgMCAwLT'
                .'MuMzQgMy41MjMgMy4zNDQgMy41MjMgMCAwIDAgMy4zNCAzLjUyMyAzLjM0NCA'
                .'zLjUyMyAwIDAgMCAzLjM0MS0zLjUyMyAzLjM0NCAzLjUyMyAwIDAgMC0zLjM0'
                .'LTMuNTIzem0zMy43OSAwYTMuMzQ0IDMuNTIzIDAgMCAwLTMuMzQgMy41MjMgM'
                .'y4zNDQgMy41MjMgMCAwIDAgMy4zNCAzLjUyMyAzLjM0NCAzLjUyMyAwIDAgMC'
                .'AzLjM1LTMuNTIzIDMuMzQ0IDMuNTIzIDAgMCAwLTMuMzUtMy41MjN6Ii8+...';
    }

    /**
     * @see https://docker-php.readthedocs.io/en/latest/cookbook/container-run/
     */
    public function cmdparams()
    {
        file_put_contents($this->envFile(), $this->job->envFile());

        return 'run --env-file '.$this->envFile().' '.$this->job->application->getDataValue('ociimage');
    }
}
