<?php

declare(strict_types=1);

namespace Alcor\Payroll\Tests\Unit\Domain\ValueObject;

use Alcor\Payroll\Domain\Exception\MoneyOverflowException;
use Alcor\Payroll\Domain\ValueObject\Money;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MoneyTest extends TestCase
{
    public function test_it_stores_the_exact_amount_in_cents(): void
    {
        $money = new Money(105_000);

        self::assertSame(105_000, $money->cents());
    }

    #[DataProvider('additionExamples')]
    public function test_it_adds_positive_and_negative_amounts(int $left, int $right, int $expected): void
    {
        $result = (new Money($left))->add(new Money($right));

        self::assertSame($expected, $result->cents());
    }

    public function test_it_calculates_the_pdf_example_without_losing_cents(): void
    {
        $result = (new Money(105_000))
            ->add(new Money(-4_555))
            ->add(new Money(10_010))
            ->add(new Money(-10))
            ->add(new Money(-20))
            ->add(new Money(20));

        self::assertSame(110_445, $result->cents());
    }

    public function test_it_throws_when_addition_overflows_an_integer(): void
    {
        $this->expectException(MoneyOverflowException::class);

        (new Money(PHP_INT_MAX))->add(new Money(1));
    }

    /** @return iterable<string, array{int, int, int}> */
    public static function additionExamples(): iterable
    {
        yield 'positive values' => [1_000, 55, 1_055];
        yield 'negative adjustment' => [105_000, -4_555, 100_445];
        yield 'negative total' => [-50, -25, -75];
        yield 'subtracting a negative amount' => [-10, 20, 10];
    }
}