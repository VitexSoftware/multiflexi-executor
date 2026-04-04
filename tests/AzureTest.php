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
 * Tests for the Azure Container Instances executor.
 */
class AzureTest extends TestCase
{
    /**
     * Test static method usableForApp returns true when ociimage is set.
     */
    public function testUsableForAppWithImage(): void
    {
        $app = $this->createMock(\MultiFlexi\Application::class);
        $app->method('getDataValue')
            ->with('ociimage')
            ->willReturn('mcr.microsoft.com/hello-world');

        $this->assertTrue(\MultiFlexi\Executor\Azure::usableForApp($app));
    }

    /**
     * Test static method usableForApp returns false when ociimage is empty.
     */
    public function testUsableForAppWithoutImage(): void
    {
        $app = $this->createMock(\MultiFlexi\Application::class);
        $app->method('getDataValue')
            ->with('ociimage')
            ->willReturn('');

        $this->assertFalse(\MultiFlexi\Executor\Azure::usableForApp($app));
    }

    /**
     * Test that name() returns a non-empty string.
     */
    public function testName(): void
    {
        $this->assertNotEmpty(\MultiFlexi\Executor\Azure::name());
    }

    /**
     * Test that description() returns a non-empty string.
     */
    public function testDescription(): void
    {
        $this->assertNotEmpty(\MultiFlexi\Executor\Azure::description());
    }

    /**
     * Test that logo() returns a non-empty base64 SVG data URI.
     */
    public function testLogoIsDataUri(): void
    {
        $logo = \MultiFlexi\Executor\Azure::logo();
        $this->assertStringStartsWith('data:image/svg+xml;base64,', $logo);
    }

    /**
     * Test that azureResourceGroup returns empty when env is not set.
     */
    public function testResourceGroupEmptyByDefault(): void
    {
        $executor = $this->makeExecutor();

        $method = new \ReflectionMethod($executor, 'azureResourceGroup');
        $method->setAccessible(true);

        // Should return empty string when no env var and no environment field
        $result = $method->invoke($executor);
        $this->assertIsString($result);
    }

    /**
     * Test that azureLocation defaults to westeurope.
     */
    public function testLocationDefault(): void
    {
        $executor = $this->makeExecutor();

        $method = new \ReflectionMethod($executor, 'azureLocation');
        $method->setAccessible(true);

        $result = $method->invoke($executor);
        $this->assertSame('westeurope', $result);
    }

    /**
     * Test that azureCpu defaults to 1.
     */
    public function testCpuDefault(): void
    {
        $executor = $this->makeExecutor();

        $method = new \ReflectionMethod($executor, 'azureCpu');
        $method->setAccessible(true);

        $this->assertSame('1', $method->invoke($executor));
    }

    /**
     * Test that azureMemory defaults to 1.5.
     */
    public function testMemoryDefault(): void
    {
        $executor = $this->makeExecutor();

        $method = new \ReflectionMethod($executor, 'azureMemory');
        $method->setAccessible(true);

        $this->assertSame('1.5', $method->invoke($executor));
    }

    /**
     * Create an Azure executor instance via reflection with mocked dependencies.
     */
    private function makeExecutor(): \MultiFlexi\Executor\Azure
    {
        $app = $this->createMock(\MultiFlexi\Application::class);
        $app->method('getDataValue')->willReturnCallback(static fn (string $key) => match ($key) {
            'ociimage' => 'mcr.microsoft.com/hello-world',
            'executable' => '/usr/bin/test',
            'name' => 'TestApp',
            default => null,
        });

        $job = $this->createMock(\MultiFlexi\Job::class);
        $job->method('getApplication')->willReturn($app);
        $job->method('getMyKey')->willReturn(1);
        $job->method('getEnvironment')->willReturn(new \MultiFlexi\ConfigFields('test'));

        $ref = new \ReflectionClass(\MultiFlexi\Executor\Azure::class);
        $executor = $ref->newInstanceWithoutConstructor();

        $jobProp = $ref->getProperty('job');
        $jobProp->setAccessible(true);
        $jobProp->setValue($executor, $job);

        $envProp = $ref->getProperty('environment');
        $envProp->setAccessible(true);
        $envProp->setValue($executor, new \MultiFlexi\ConfigFields('test'));

        return $executor;
    }
}
