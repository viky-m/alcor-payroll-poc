<?php

declare(strict_types=1);

namespace Alcor\Payroll\Tests\Unit\Domain;

use Alcor\Payroll\Domain\EarningLine;
use Alcor\Payroll\Domain\ValueObject\Adjustment;
use Alcor\Payroll\Domain\ValueObject\Money;
use PHPUnit\Framework\TestCase;

final class EarningLineTest extends TestCase
{
    public function test_it_starts_with_the_system_calculated_value(): void
    {
        $line = new EarningLine(new Money(100_000));

        self::assertSame(100_000, $line->systemValue()->cents());
        self::assertSame(100_000, $line->currentValue()->cents());
    }

    public function test_it_accepts_system_recalculations_before_manual_adjustments(): void
    {
        $line = new EarningLine(new Money(100_000));

        $line->recalculate(new Money(105_000));
        $line->recalculate(new Money(107_250));

        self::assertSame(107_250, $line->systemValue()->cents());
        self::assertSame(107_250, $line->currentValue()->cents());
    }

    public function test_manual_adjustments_freeze_the_system_value_and_accumulate(): void
    {
        $line = new EarningLine(new Money(100_000));
        $line->recalculate(new Money(105_000));
        $line->addAdjustment($this->adjustment(-4_555, 'Benefit reversal'));

        $line->recalculate(new Money(200_000));
        $line->addAdjustment($this->adjustment(10_010, 'Late overtime bonus'));

        self::assertSame(105_000, $line->systemValue()->cents());
        self::assertSame(110_455, $line->currentValue()->cents());
        self::assertCount(2, $line->adjustments());
    }

    public function test_it_reproduces_the_complete_pdf_adjustment_history(): void
    {
        $line = new EarningLine(new Money(100_000));
        $line->recalculate(new Money(105_000));
        $line->addAdjustment($this->adjustment(-4_555, 'Employee declined dental benefit; reversing deduction'));
        $line->recalculate(new Money(120_000));
        $line->addAdjustment($this->adjustment(10_010, 'Late correction: missed approved overtime bonus'));
        $line->addAdjustment($this->adjustment(-10, 'Minor rounding adjustment'));
        $line->addAdjustment($this->adjustment(-20, 'Second minor rounding adjustment'));
        $line->addAdjustment($this->adjustment(20, 'Correcting mistake in adjustment #4'));

        self::assertSame(105_000, $line->systemValue()->cents());
        self::assertSame(110_445, $line->currentValue()->cents());
        self::assertSame([-4_555, 10_010, -10, -20, 20], array_map(
            static fn (Adjustment $adjustment): int => $adjustment->amount()->cents(),
            $line->adjustments(),
        ));
        self::assertCount(5, $line->adjustments());
    }

    public function test_modifying_the_returned_history_does_not_change_the_line(): void
    {
        $line = new EarningLine(new Money(100_000));
        $adjustment = $this->adjustment(100, 'Correction');
        $line->addAdjustment($adjustment);

        $history = $line->adjustments();
        $history[] = $this->adjustment(200, 'External append');
        $history[0] = $this->adjustment(300, 'External replacement');

        self::assertSame([$adjustment], $line->adjustments());
        self::assertSame(100_100, $line->currentValue()->cents());
    }

    private function adjustment(int $cents, string $comment): Adjustment
    {
        return new Adjustment(new Money($cents), $comment, new \DateTimeImmutable('2026-01-01T00:00:00+00:00'));
    }
}