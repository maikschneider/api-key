<?php

declare(strict_types=1);

namespace MaikSchneider\ApiKey\Tests\Unit\Key;

use MaikSchneider\ApiKey\Key\KeyFormat;
use MaikSchneider\ApiKey\Key\KeyGenerator;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class KeyGeneratorTest extends UnitTestCase
{
    #[Test]
    public function generatedKeysAreWellFormedAndUnique(): void
    {
        $subject = new KeyGenerator();
        $keys = [];
        for ($i = 0; $i < 200; $i++) {
            $key = $subject->generate();
            self::assertTrue(KeyFormat::isKey($key), $key);
            $keys[] = $key;
        }

        self::assertCount(200, array_unique($keys));
    }
}
