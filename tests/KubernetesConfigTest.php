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
 * Tests for the Kubernetes executor's kubernetesConfig() derivation logic.
 *
 * These tests validate that the executor correctly reconstructs k8s config
 * from existing DB fields (helmchart, name, artifacts) without needing
 * a dedicated kubernetes JSON column.
 */
class KubernetesConfigTest extends TestCase
{
    /**
     * Test that kubernetesConfig returns empty array when no helmchart is set.
     */
    public function testKubernetesConfigEmptyWhenNoHelmChart(): void
    {
        $config = $this->invokeKubernetesConfig('', 'TestApp', '');
        $this->assertSame([], $config);
    }

    /**
     * Test that kubernetesConfig returns valid helm config when helmchart is set.
     */
    public function testKubernetesConfigWithHelmChart(): void
    {
        $config = $this->invokeKubernetesConfig(
            'oci://ghcr.io/example/chart',
            'My Test App',
            'report.json',
        );

        $this->assertArrayHasKey('helm', $config);
        $this->assertTrue($config['helm']['enabled']);
        $this->assertSame('oci://ghcr.io/example/chart', $config['helm']['chart']);
        $this->assertSame('my-test-app', $config['helm']['releaseName']);
        $this->assertSame('multiflexi', $config['helm']['namespace']);
        $this->assertTrue($config['helm']['upgradeInstall']);
        $this->assertTrue($config['helm']['wait']);
        $this->assertFalse($config['helm']['atomic']);
        $this->assertSame(300, $config['helm']['timeoutSeconds']);
    }

    /**
     * Test that releaseName is derived as DNS-1123 safe from app name.
     */
    public function testReleaseNameDnsSafe(): void
    {
        $config = $this->invokeKubernetesConfig(
            'oci://example/chart',
            'MultiFlexi Probe v2.0!',
            '',
        );

        $this->assertSame('multiflexi-probe-v2-0', $config['helm']['releaseName']);
    }

    /**
     * Test that artifact config is derived from comma-separated paths.
     */
    public function testArtifactConfigFromPaths(): void
    {
        $config = $this->invokeKubernetesConfig(
            'oci://example/chart',
            'App',
            'report.json,output.csv',
        );

        $this->assertArrayHasKey('artifacts', $config);
        $this->assertTrue($config['artifacts']['enabled']);
        $this->assertSame('report.json', $config['artifacts']['outputPath']);
        $this->assertFalse($config['artifacts']['keepPodOnFailure']);
    }

    /**
     * Test that artifacts are disabled when no artifact paths exist.
     */
    public function testArtifactsDisabledWhenEmpty(): void
    {
        $config = $this->invokeKubernetesConfig(
            'oci://example/chart',
            'App',
            '',
        );

        $this->assertFalse($config['artifacts']['enabled']);
        $this->assertSame('', $config['artifacts']['outputPath']);
    }

    /**
     * Test that empty app name falls back to 'mf-app'.
     */
    public function testFallbackReleaseName(): void
    {
        $config = $this->invokeKubernetesConfig(
            'oci://example/chart',
            '',
            '',
        );

        $this->assertSame('mf-app', $config['helm']['releaseName']);
    }

    /**
     * Test static method usableForApp returns true when ociimage is set.
     */
    public function testUsableForAppWithImage(): void
    {
        $app = $this->createMock(\MultiFlexi\Application::class);
        $app->method('getDataValue')
            ->with('ociimage')
            ->willReturn('docker.io/example/image:latest');

        $result = \MultiFlexi\Executor\Kubernetes::usableForApp($app);
        $this->assertTrue($result);
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

        $result = \MultiFlexi\Executor\Kubernetes::usableForApp($app);
        $this->assertFalse($result);
    }

    /**
     * Invoke the private kubernetesConfig() method with mocked Application data.
     *
     * @return array<string, mixed>
     */
    private function invokeKubernetesConfig(string $helmChart, string $appName, string $artifacts): array
    {
        $app = $this->createMock(\MultiFlexi\Application::class);
        $app->method('getDataValue')
            ->willReturnCallback(static function (string $key) use ($helmChart, $appName, $artifacts) {
                return match ($key) {
                    'helmchart' => $helmChart,
                    'name' => $appName,
                    'artifacts' => $artifacts,
                    default => null,
                };
            });

        $job = $this->createMock(\MultiFlexi\Job::class);
        $job->method('getApplication')->willReturn($app);
        $job->method('getMyKey')->willReturn(1);
        $job->method('getEnvironment')->willReturn(new \MultiFlexi\ConfigFields('test'));

        // Create a Kubernetes instance via reflection to avoid constructor side effects
        $ref = new \ReflectionClass(\MultiFlexi\Executor\Kubernetes::class);
        $executor = $ref->newInstanceWithoutConstructor();

        // Set required properties
        $jobProp = $ref->getProperty('job');
        $jobProp->setAccessible(true);
        $jobProp->setValue($executor, $job);

        $envProp = $ref->getProperty('environment');
        $envProp->setAccessible(true);
        $envProp->setValue($executor, new \MultiFlexi\ConfigFields('test'));

        // Invoke private method
        $method = $ref->getMethod('kubernetesConfig');
        $method->setAccessible(true);

        return $method->invoke($executor);
    }
}
