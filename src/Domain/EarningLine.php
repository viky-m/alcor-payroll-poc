<?php

declare(strict_types=1);

namespace Alcor\Payroll\Domain;

use Alcor\Payroll\Domain\ValueObject\Adjustment;
use Alcor\Payroll\Domain\ValueObject\Money;

final class EarningLine
{
    /** @var list<Adjustment> */
    private array $adjustments = [];

    public function __construct(private Money $systemValue)
    {
    }

    public function recalculate(Money $value): void
    {
        if ($this->adjustments !== []) {
            return;
        }

        $this->systemValue = $value;
    }

    public function currentValue(): Money
    {
        $value = $this->systemValue;

        foreach ($this->adjustments as $adjustment) {
            $value = $value->add($adjustment->amount());
        }

        return $value;
    }

    public function systemValue(): Money
    {
        return $this->systemValue;
    }

    public function addAdjustment(Adjustment $adjustment): void
    {
        $this->adjustments[] = $adjustment;
    }

    /** @return list<Adjustment> */
    public function adjustments(): array
    {
        return $this->adjustments;
    }
}