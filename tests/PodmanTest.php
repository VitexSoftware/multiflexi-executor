<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Tests for the Podman executor.
 */
class PodmanTest extends TestCase
{
    public function testLogoIsValidBase64Svg(): void
    {
        $logo = \MultiFlexi\Executor\Podman::logo();
        $this->assertStringStartsWith('data:image/svg+xml;base64,', $logo);
        $decoded = base64_decode(substr($logo, \strlen('data:image/svg+xml;base64,')), true);
        $this->assertIsString($decoded);
        $this->assertStringContainsString('<svg', $decoded);
    }
}
