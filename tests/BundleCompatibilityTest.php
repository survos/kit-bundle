<?php

declare(strict_types=1);

namespace Survos\Kit\Tests;

use PHPUnit\Framework\TestCase;
use Survos\Kit\AbstractSurvosBundle;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;

final class BundleCompatibilityTest extends TestCase
{
    public function testBundleProvidesItsExtensionAndPackagePath(): void
    {
        $bundle = new CompatibilityBundle();

        self::assertSame('CompatibilityBundle', $bundle->getName());
        self::assertSame(\dirname(__DIR__), $bundle->getPath());
        self::assertInstanceOf(ExtensionInterface::class, $bundle->getContainerExtension());
        self::assertSame('compatibility', $bundle->getContainerExtension()->getAlias());
    }
}

final class CompatibilityBundle extends AbstractSurvosBundle {}
