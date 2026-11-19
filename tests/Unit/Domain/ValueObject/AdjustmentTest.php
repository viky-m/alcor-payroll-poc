<?php

declare(strict_types=1);

namespace Alcor\Payroll\Tests\Unit\Domain\ValueObject;

use Alcor\Payroll\Domain\Exception\InvalidAdjustmentException;
use Alcor\Payroll\Domain\ValueObject\Adjustment;
use Alcor\Payroll\Domain\ValueObject\Money;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AdjustmentTest extends TestCase
{
    #[DataProvider('validAmounts')]
    public function test_it_preserves_a_nonzero_amount_comment_and_timestamp(int $cents): void
    {
        $occurredAt = new DateTimeImmutable('2026-01-01T12:30:00+00:00');
        $adjustment = new Adjustment(new Money($cents), '  Payroll correction  ', $occurredAt);

        self::assertSame($cents, $adjustment->amount()->cents());
        self::assertSame('Payroll correction', $adjustment->comment());
        self::assertSame($occurredAt, $adjustment->occurredAt());
    }

    public function test_it_rejects_a_zero_amount(): void
    {
        $this->expectException(InvalidAdjustmentException::class);

        new Adjustment(new Money(0), 'Not a valid correction', new DateTimeImmutable());
    }

    public function test_it_rejects_a_blank_comment(): void
    {
        $this->expectException(InvalidAdjustmentException::class);

        new Adjustment(new Money(100), " \t\n ", new DateTimeImmutable());
    }

    /** @return iterable<string, array{int}> */
    public static function validAmounts(): iterable
    {
        yield 'positive adjustment' => [100];
        yield 'negative adjustment' => [-100];
    }
}