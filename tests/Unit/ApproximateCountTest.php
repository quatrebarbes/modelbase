<?php

namespace Quatrebarbes\Modelbase\Tests\Unit;

use Quatrebarbes\Modelbase\Support\ApproximateCount;
use Quatrebarbes\Modelbase\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class ApproximateCountTest extends TestCase
{
    /**
     * @return array<string, array{0: int, 1: string}>
     */
    public static function counts(): array
    {
        return [
            'zero' => [0, '0'],
            'below the first threshold' => [842, '842'],
            'just below the first threshold' => [999, '999'],
            'exactly the first threshold' => [1_000, '1K'],
            'thousands, one decimal' => [1_500, '1.5K'],
            'thousands, no trailing decimal' => [42_000, '42K'],
            'just below the second threshold, no rounding above the bucket' => [999_999, '999.9K'],
            'exactly the second threshold' => [1_000_000, '1G'],
            'millions' => [3_200_000, '3.2G'],
            'just below the third threshold, no rounding above the bucket' => [999_999_999, '999.9G'],
            'exactly the third threshold' => [1_000_000_000, '1T'],
            'billions, one decimal' => [3_200_000_000, '3.2T'],
        ];
    }

    #[DataProvider('counts')]
    public function test_it_formats_a_count_per_the_ex_312_thresholds(int $count, string $expected): void
    {
        $this->assertSame($expected, ApproximateCount::format($count));
    }
}
