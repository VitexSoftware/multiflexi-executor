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
 * Tests for the Docker executor.
 */
class DockerTest extends TestCase
{
    public function testUsableForAppWithImage(): void
    {
        $app = $this->createMock(\MultiFlexi\Application::class);
        $app->method('getDataValue')
            ->with('ociimage')
            ->willReturn('docker.io/library/hello-world:latest');

        $this->assertTrue(\MultiFlexi\Executor\Docker::usableForApp($app));
    }

    public function testUsableForAppWithoutImage(): void
    {
        $app = $this->createMock(\MultiFlexi\Application::class);
        $app->method('getDataValue')
            ->with('ociimage')
            ->willReturn('');

        $this->assertFalse(\MultiFlexi\Executor\Docker::usableForApp($app));
    }

    public function testNameDescriptionLogo(): void
    {
        $this->assertNotEmpty(\MultiFlexi\Executor\Docker::name());
        $this->assertNotEmpty(\MultiFlexi\Executor\Docker::description());
        $logo = \MultiFlexi\Executor\Docker::logo();
        $this->assertStringStartsWith('data:image/svg+xml;base64,', $logo);
        $decoded = base64_decode(substr($logo, \strlen('data:image/svg+xml;base64,')), true);
        $this->assertIsString($decoded);
        $this->assertStringContainsString('<svg', $decoded);
    }

    public function testExecutableIsDocker(): void
    {
        $executor = $this->makeExecutor();
        $this->assertSame('docker', $executor->executable());
    }

    public function testCmdparamsContainsRunRmEnvFileAndImage(): void
    {
        $executor = $this->makeExecutor();
        $params = $executor->cmdparams();

        $this->assertStringContainsString('run', $params);
        $this->assertStringContainsString('--rm', $params);
        $this->assertStringContainsString('--env-file', $params);
        $this->assertStringContainsString('--entrypoint', $params);
        $this->assertStringContainsString('docker.io/library/hello-world:latest', $params);
        $this->assertStringContainsString('/usr/bin/probe', $params);
    }

    public function testCmdparamsIncludesNetworkWhenConfigured(): void
    {
        putenv('MULTIFLEXI_DOCKER_NETWORK=multiflexi_net');

        try {
            $executor = $this->makeExecutor();
            $params = $executor->cmdparams();
            $this->assertStringContainsString('--network=', $params);
            $this->assertStringContainsString('multiflexi_net', $params);
        } finally {
            putenv('MULTIFLEXI_DOCKER_NETWORK');
        }
    }

    public function testCommandlineStartsWithDocker(): void
    {
        $executor = $this->makeExecutor();
        $line = $executor->commandline();
        $this->assertStringStartsWith('docker ', $line);
        $this->assertStringContainsString('--rm', $line);
    }

    public function testEnvFilePathUsesJobId(): void
    {
        $executor = $this->makeExecutor();
        $path = $executor->envFile();
        $this->assertStringContainsString('multiflexi-docker-42.env', $path);
    }

    private function makeExecutor(): \MultiFlexi\Executor\Docker
    {
        $app = $this->createMock(\MultiFlexi\Application::class);
        $app->method('getDataValue')->willReturnCallback(static fn (string $key) => match ($key) {
            'ociimage' => 'docker.io/library/hello-world:latest',
            'executable' => '/usr/bin/probe',
            'name' => 'Hello',
            default => null,
        });

        $job = $this->createMock(\MultiFlexi\Job::class);
        $job->method('getApplication')->willReturn($app);
        $job->method('getMyKey')->willReturn(42);
        $job->method('getCmdParams')->willReturn('--verbose');
        $job->method('getEnvironment')->willReturn(new \MultiFlexi\ConfigFields('test'));

        $ref = new \ReflectionClass(\MultiFlexi\Executor\Docker::class);
        $executor = $ref->newInstanceWithoutConstructor();

        $jobProp = $ref->getProperty('job');
        $jobProp->setAccessible(true);
        $jobProp->setValue($executor, $job);

        $env = new \MultiFlexi\ConfigFields('test');
        $envProp = $ref->getProperty('environment');
        $envProp->setAccessible(true);
        $envProp->setValue($executor, $env);

        return $executor;
    }
}
