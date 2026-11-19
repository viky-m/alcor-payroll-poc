<?php

declare(strict_types=1);

namespace Alcor\Payroll\Domain\ValueObject;

use Alcor\Payroll\Domain\Exception\InvalidAdjustmentException;
use DateTimeImmutable;

final readonly class Adjustment
{
    private string $comment;

    public function __construct(
        private Money $amount,
        string $comment,
        private DateTimeImmutable $occurredAt,
    ) {
        $comment = trim($comment);

        if ($amount->cents() === 0) {
            throw new InvalidAdjustmentException('An adjustment amount must not be zero.');
        }

        if ($comment === '') {
            throw new InvalidAdjustmentException('An adjustment comment must not be blank.');
        }

        $this->comment = $comment;
    }

    public function amount(): Money
    {
        return $this->amount;
    }

    public function comment(): string
    {
        return $this->comment;
    }

    public function occurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
