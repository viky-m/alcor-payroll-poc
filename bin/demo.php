<?php

declare(strict_types=1);

use Alcor\Payroll\Domain\EarningLine;
use Alcor\Payroll\Domain\ValueObject\Adjustment;
use Alcor\Payroll\Domain\ValueObject\Money;
use Alcor\Payroll\Infrastructure\FileEarningLineRepository;

require __DIR__ . '/../vendor/autoload.php';

function formatMoney(Money $money): string
{
    $digits = (string) $money->cents();
    $negative = str_starts_with($digits, '-');
    if ($negative) {
        $digits = substr($digits, 1);
    }
    $digits = str_pad($digits, 3, '0', STR_PAD_LEFT);
    $dollars = substr($digits, 0, -2);
    $dollars = preg_replace('/\B(?=(\d{3})+(?!\d))/', ',', $dollars);
    return ($negative ? '-' : '') . '$' . $dollars . '.' . substr($digits, -2);
}

try {
    $directory = $argv[1] ?? __DIR__ . '/../var';
    if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
        throw new RuntimeException('Cannot create storage directory.');
    }
    $repository = new FileEarningLineRepository($directory);
    $id = 'demo-' . bin2hex(random_bytes(16));
    $line = new EarningLine(new Money(100_000));
    $revision = $repository->save($id, $line, null);
    printf("Line: %s\n1. System calculation: %s\n", $id, formatMoney($line->currentValue()));

    $line->recalculate(new Money(105_000));
    $revision = $repository->save($id, $line, $revision);
    printf("2. System recalculation: %s\n", formatMoney($line->currentValue()));

    $steps = [
        [3, -4_555, 'Employee declined dental benefit; reversing deduction'],
        [5, 10_010, 'Late correction: missed approved overtime bonus'],
        [6, -10, 'Minor rounding adjustment'],
        [7, -20, 'Second minor rounding adjustment'],
        [8, 20, 'Correcting mistake in adjustment #4'],
    ];
    foreach ($steps as [$step, $cents, $comment]) {
        $line->addAdjustment(new Adjustment(new Money($cents), $comment, new DateTimeImmutable('now', new DateTimeZone('UTC'))));
        $revision = $repository->save($id, $line, $revision);
        printf("%d. Manual adjustment %s: %s\n", $step, formatMoney(new Money($cents)), formatMoney($line->currentValue()));
        if ($step === 3) {
            $line->recalculate(new Money(120_000));
            printf("4. System recalculation ignored: %s\n", formatMoney($line->currentValue()));
        }
    }

    $stored = $repository->load($id);
    if ($stored === null) {
        throw new RuntimeException('Saved earning line was not found.');
    }
    printf("\nReloaded history (revision %d):\nSystem value: %s\n", $stored->revision, formatMoney($stored->line->systemValue()));
    foreach ($stored->line->adjustments() as $index => $adjustment) {
        printf("%d. %s | %s | %s\n", $index + 1, formatMoney($adjustment->amount()), $adjustment->comment(), $adjustment->occurredAt()->format('Y-m-d H:i:s.u P'));
    }
    printf("Current value: %s\n", formatMoney($stored->line->currentValue()));
} catch (Throwable $exception) {
    fwrite(STDERR, 'Demo failed: ' . $exception->getMessage() . "\n");
    exit(1);
}