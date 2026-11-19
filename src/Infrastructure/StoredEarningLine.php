<?php

declare(strict_types=1);

namespace Alcor\Payroll\Infrastructure;

use Alcor\Payroll\Domain\EarningLine;

final readonly class StoredEarningLine
{
    public function __construct(public EarningLine $line, public int $revision)
    {
    }
}