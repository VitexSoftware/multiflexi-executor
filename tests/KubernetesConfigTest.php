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
 * Tests for the Kubernetes executor.
 */
class KubernetesConfigTest extends TestCase
{
    public function testKubernetesConfigEmptyHelmWhenNoHelmChart(): void
    {
        $config = $this->invokeKubernetesConfig('', 'TestApp', '');
        $this->assertArrayNotHasKey('helm', $config);
        $this->assertFalse($config['artifacts']['enabled']);
        $this->assertSame([], $config['artifacts']['paths']);
    }

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

    public function testReleaseNameDnsSafe(): void
    {
        $config = $this->invokeKubernetesConfig(
            'oci://example/chart',
            'MultiFlexi Probe v2.0!',
            '',
        );

        $this->assertSame('multiflexi-probe-v2-0', $config['helm']['releaseName']);
    }

    public function testReleaseNameTruncatedTo63Chars(): void
    {
        $long = str_repeat('a', 80);
        $config = $this->invokeKubernetesConfig('oci://example/chart', $long, '');
        $this->assertSame(63, \strlen($config['helm']['releaseName']));
        $this->assertSame(str_repeat('a', 63), $config['helm']['releaseName']);
    }

    public function testArtifactConfigStoresAllPaths(): void
    {
        $config = $this->invokeKubernetesConfig(
            'oci://example/chart',
            'App',
            'report.json,output.csv, /tmp/extra.log ',
        );

        $this->assertTrue($config['artifacts']['enabled']);
        $this->assertSame(['report.json', 'output.csv', '/tmp/extra.log'], $config['artifacts']['paths']);
        $this->assertFalse($config['artifacts']['keepPodOnFailure']);
    }

    public function testArtifactsDisabledWhenEmpty(): void
    {
        $config = $this->invokeKubernetesConfig(
            'oci://example/chart',
            'App',
            '',
        );

        $this->assertFalse($config['artifacts']['enabled']);
        $this->assertSame([], $config['artifacts']['paths']);
    }

    public function testArtifactsWithoutHelmChart(): void
    {
        $config = $this->invokeKubernetesConfig('', 'App', 'a.json,b.json');
        $this->assertArrayNotHasKey('helm', $config);
        $this->assertTrue($config['artifacts']['enabled']);
        $this->assertSame(['a.json', 'b.json'], $config['artifacts']['paths']);
    }

    public function testFallbackReleaseName(): void
    {
        $config = $this->invokeKubernetesConfig(
            'oci://example/chart',
            '',
            '',
        );

        $this->assertSame('mf-app', $config['helm']['releaseName']);
    }

    public function testUsableForAppWithImage(): void
    {
        $app = $this->createMock(\MultiFlexi\Application::class);
        $app->method('getDataValue')
            ->with('ociimage')
            ->willReturn('docker.io/example/image:latest');

        $this->assertTrue(\MultiFlexi\Executor\Kubernetes::usableForApp($app));
    }

    public function testUsableForAppWithoutImage(): void
    {
        $app = $this->createMock(\MultiFlexi\Application::class);
        $app->method('getDataValue')
            ->with('ociimage')
            ->willReturn('');

        $this->assertFalse(\MultiFlexi\Executor\Kubernetes::usableForApp($app));
    }

    public function testNameDescriptionLogo(): void
    {
        $this->assertNotEmpty(\MultiFlexi\Executor\Kubernetes::name());
        $this->assertNotEmpty(\MultiFlexi\Executor\Kubernetes::description());
        $logo = \MultiFlexi\Executor\Kubernetes::logo();
        $this->assertStringStartsWith('data:image/svg+xml;base64,', $logo);
        $decoded = base64_decode(substr($logo, \strlen('data:image/svg+xml;base64,')), true);
        $this->assertIsString($decoded);
        $this->assertStringContainsString('<svg', $decoded);
    }

    public function testHelmNamespaceHelper(): void
    {
        $method = new \ReflectionMethod(\MultiFlexi\Executor\Kubernetes::class, 'helmNamespace');
        $method->setAccessible(true);

        $this->assertSame('multiflexi', $method->invoke(null, ['namespace' => 'multiflexi']));
        $this->assertNull($method->invoke(null, []));
        $this->assertNull($method->invoke(null, ['namespace' => '']));
    }

    public function testShouldRunHelmHelper(): void
    {
        $method = new \ReflectionMethod(\MultiFlexi\Executor\Kubernetes::class, 'shouldRunHelm');
        $method->setAccessible(true);

        $this->assertTrue($method->invoke(null, ['enabled' => true]));
        $this->assertFalse($method->invoke(null, []));
        $this->assertFalse($method->invoke(null, ['enabled' => false]));
    }

    public function testHelmSetValueHelper(): void
    {
        $method = new \ReflectionMethod(\MultiFlexi\Executor\Kubernetes::class, 'helmSetValue');
        $method->setAccessible(true);

        $this->assertSame('true', $method->invoke(null, true));
        $this->assertSame('false', $method->invoke(null, false));
        $this->assertSame('42', $method->invoke(null, 42));
        $this->assertSame('{"a":1}', $method->invoke(null, ['a' => 1]));
    }

    public function testArtifactPathsBackwardCompatibleOutputPath(): void
    {
        $method = new \ReflectionMethod(\MultiFlexi\Executor\Kubernetes::class, 'artifactPaths');
        $method->setAccessible(true);

        $this->assertSame(['legacy.json'], $method->invoke(null, ['outputPath' => 'legacy.json']));
        $this->assertSame(['a.json', 'b.json'], $method->invoke(null, ['paths' => ['a.json', 'b.json']]));
        $this->assertSame([], $method->invoke(null, []));
    }

    public function testContainerArtifactPathUsesBasenameUnderTmp(): void
    {
        $method = new \ReflectionMethod(\MultiFlexi\Executor\Kubernetes::class, 'matchesArtifactPattern');
        $method->setAccessible(true);

        $this->assertTrue($method->invoke(null, 'env_report.json', ['env_report.json']));
        $this->assertTrue($method->invoke(null, 'env_report.json', ['.*\\.json']));
        $this->assertTrue($method->invoke(null, 'out.csv', ['/tmp/out.csv']));
        $this->assertFalse($method->invoke(null, 'other.txt', ['env_report.json']));
    }

    public function testRemapHostTempPathsRestoresOriginals(): void
    {
        $executor = $this->makeExecutor('', 'App', 'env_report.json');
        $hostTmp = $this->hostMultiflexiTmp();
        $env = new \MultiFlexi\ConfigFields('test');

        $tmpField = new \MultiFlexi\ConfigField('MULTIFLEXI_TMP', 'string', 'MULTIFLEXI_TMP', '');
        $tmpField->setValue($hostTmp);
        $env->addField($tmpField);

        $outField = new \MultiFlexi\ConfigField('OUTPUT_JSON', 'string', 'OUTPUT_JSON', '');
        $outField->setValue($hostTmp.'/env_report.json');
        $env->addField($outField);

        $ref = new \ReflectionClass($executor);
        $envProp = $ref->getProperty('environment');
        $envProp->setAccessible(true);
        $envProp->setValue($executor, $env);

        $remap = $ref->getMethod('remapHostTempPathsForContainer');
        $remap->setAccessible(true);
        $remap->invoke($executor);

        $this->assertSame('/tmp', $env->getFieldByCode('MULTIFLEXI_TMP')->getValue());
        $this->assertSame('/tmp/env_report.json', $env->getFieldByCode('OUTPUT_JSON')->getValue());

        $restore = $ref->getMethod('restoreRemappedHostPaths');
        $restore->setAccessible(true);
        $restore->invoke($executor);

        $this->assertSame($hostTmp, $env->getFieldByCode('MULTIFLEXI_TMP')->getValue());
        $this->assertSame($hostTmp.'/env_report.json', $env->getFieldByCode('OUTPUT_JSON')->getValue());
    }

    public function testResolveNamespacePrefersEnv(): void
    {
        $executor = $this->makeExecutor('oci://chart', 'App', '');
        putenv('MULTIFLEXI_K8S_NAMESPACE=from-env');

        try {
            $method = new \ReflectionMethod($executor, 'resolveNamespace');
            $method->setAccessible(true);
            $this->assertSame('from-env', $method->invoke($executor, ['namespace' => 'multiflexi']));
        } finally {
            putenv('MULTIFLEXI_K8S_NAMESPACE');
        }
    }

    public function testResolveNamespaceFallsBackToHelm(): void
    {
        $executor = $this->makeExecutor('oci://chart', 'App', '');
        putenv('MULTIFLEXI_K8S_NAMESPACE');

        $method = new \ReflectionMethod($executor, 'resolveNamespace');
        $method->setAccessible(true);
        $this->assertSame('multiflexi', $method->invoke($executor, ['namespace' => 'multiflexi']));
        $this->assertNull($method->invoke($executor, []));
    }

    public function testJobOutputSurvivesProcessOverwrite(): void
    {
        $executor = $this->makeExecutor('oci://chart', 'App', '');
        $ref = new \ReflectionClass($executor);

        $stdout = $ref->getProperty('jobStdout');
        $stdout->setAccessible(true);
        $stdout->setValue($executor, "job-out\n");

        $stderr = $ref->getProperty('jobStderr');
        $stderr->setAccessible(true);
        $stderr->setValue($executor, "job-err\n");

        $exit = $ref->getProperty('jobExitCode');
        $exit->setAccessible(true);
        $exit->setValue($executor, 0);

        $this->assertSame("job-out\n", $executor->getOutput());
        $this->assertSame("job-err\n", $executor->getErrorOutput());
        $this->assertSame(0, $executor->getExitCode());
        $this->assertNotEmpty($executor->meaning());
    }

    public function testCommandlineContainsImageAndRestartNever(): void
    {
        $executor = $this->makeExecutor('', 'App', '');
        $line = $executor->commandline();
        $this->assertStringContainsString('kubectl', $line);
        $this->assertStringContainsString('--restart=Never', $line);
        $this->assertStringContainsString('docker.io/example/image:latest', $line);
    }

    /**
     * @return array<string, mixed>
     */
    private function invokeKubernetesConfig(string $helmChart, string $appName, string $artifacts): array
    {
        $executor = $this->makeExecutor($helmChart, $appName, $artifacts);
        $method = new \ReflectionMethod($executor, 'kubernetesConfig');
        $method->setAccessible(true);

        return $method->invoke($executor);
    }

    private function hostMultiflexiTmp(): string
    {
        if (method_exists(\MultiFlexi\Defaults::class, 'init')) {
            \MultiFlexi\Defaults::init();
        } else {
            new \MultiFlexi\Defaults();
        }

        return rtrim(\MultiFlexi\Defaults::$MULTIFLEXI_TMP, '/');
    }

    private function makeExecutor(string $helmChart, string $appName, string $artifacts): \MultiFlexi\Executor\Kubernetes
    {
        $app = $this->createMock(\MultiFlexi\Application::class);
        $app->method('getMyKey')->willReturn(0);
        $app->method('getDataValue')
            ->willReturnCallback(static function (string $key) use ($helmChart, $appName, $artifacts) {
                return match ($key) {
                    'helmchart' => $helmChart,
                    'name' => $appName,
                    'artifacts' => $artifacts,
                    'ociimage' => 'docker.io/example/image:latest',
                    'executable' => '/usr/bin/test',
                    default => null,
                };
            });

        $job = $this->createMock(\MultiFlexi\Job::class);
        $job->method('getApplication')->willReturn($app);
        $job->method('getMyKey')->willReturn(1);
        $job->method('getEnvironment')->willReturn(new \MultiFlexi\ConfigFields('test'));

        $ref = new \ReflectionClass(\MultiFlexi\Executor\Kubernetes::class);
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
