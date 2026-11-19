<?php

declare(strict_types=1);

namespace Alcor\Payroll\Domain\ValueObject;

use Alcor\Payroll\Domain\Exception\MoneyOverflowException;

final readonly class Money
{
    public function __construct(private int $cents)
    {
    }

    public function cents(): int
    {
        return $this->cents;
    }

    public function add(self $amount): self
    {
        $otherCents = $amount->cents;

        if (
            ($otherCents > 0 && $this->cents > PHP_INT_MAX - $otherCents)
            || ($otherCents < 0 && $this->cents < PHP_INT_MIN - $otherCents)
        ) {
            throw new MoneyOverflowException('Adding these amounts exceeds the integer range.');
        }

        return new self($this->cents + $otherCents);
    }
}
