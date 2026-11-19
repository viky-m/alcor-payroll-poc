<?php

declare(strict_types=1);

namespace Alcor\Payroll\Infrastructure;

use Alcor\Payroll\Domain\EarningLine;
use Alcor\Payroll\Domain\ValueObject\Adjustment;
use Alcor\Payroll\Domain\ValueObject\Money;
use DateTimeImmutable;
use InvalidArgumentException;
use JsonException;
use RuntimeException;
use stdClass;
use Throwable;

final class FileEarningLineRepository
{
    private const DATE_FORMAT = 'Y-m-d\TH:i:s.uP';

    public function __construct(private readonly string $directory)
    {
        if (!is_dir($directory)) {
            throw new RuntimeException('Storage directory does not exist.');
        }
    }

    public function load(string $id): ?StoredEarningLine
    {
        return $this->withLock($id, LOCK_SH, fn (): ?StoredEarningLine => $this->read($id));
    }

    public function save(string $id, EarningLine $line, ?int $expectedRevision): int
    {
        return $this->withLock($id, LOCK_EX, function () use ($id, $line, $expectedRevision): int {
            $stored = $this->read($id);
            if (($stored === null && $expectedRevision !== null)
                || ($stored !== null && $stored->revision !== $expectedRevision)) {
                throw new RuntimeException('Earning line revision conflict.');
            }

            if ($stored !== null) {
                $old = $stored->line->adjustments();
                $new = $line->adjustments();
                if (count($new) < count($old)
                    || ($old !== [] && $stored->line->systemValue()->cents() !== $line->systemValue()->cents())) {
                    throw new RuntimeException('Saved history and its frozen base cannot be changed.');
                }
                foreach ($old as $index => $adjustment) {
                    if ($this->adjustmentData($adjustment) !== $this->adjustmentData($new[$index])) {
                        throw new RuntimeException('Saved adjustments cannot be changed.');
                    }
                }
            }

            $line->currentValue();
            if ($stored !== null && $stored->revision === PHP_INT_MAX) {
                throw new RuntimeException('Revision overflow.');
            }
            $revision = ($stored->revision ?? 0) + 1;
            try {
                $json = json_encode([
                    'version' => 1,
                    'revision' => $revision,
                    'system_cents' => $line->systemValue()->cents(),
                    'adjustments' => array_map($this->adjustmentData(...), $line->adjustments()),
                ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
            } catch (JsonException $exception) {
                throw new RuntimeException('Cannot encode earning line.', 0, $exception);
            }

            $this->writeAtomically($id, $json);
            return $revision;
        });
    }

    /** @template T
     *  @param int<0, 7> $mode
     *  @param callable(): T $operation
     *  @return T
     */
    private function withLock(string $id, int $mode, callable $operation): mixed
    {
        if (!preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9_-]{0,127}\z/D', $id)) {
            throw new InvalidArgumentException('Invalid earning line identifier.');
        }
        $lock = @fopen($this->directory . '/' . $id . '.lock', 'c');
        if ($lock === false) {
            throw new RuntimeException('Cannot open storage lock.');
        }
        try {
            if (!flock($lock, $mode)) {
                throw new RuntimeException('Cannot acquire storage lock.');
            }
            try {
                return $operation();
            } finally {
                flock($lock, LOCK_UN);
            }
        } finally {
            fclose($lock);
        }
    }

    private function read(string $id): ?StoredEarningLine
    {
        $path = $this->directory . '/' . $id . '.json';
        if (!file_exists($path) && !is_link($path)) {
            return null;
        }
        $json = @file_get_contents($path);
        if ($json === false) {
            throw new RuntimeException('Cannot read earning line.');
        }

        try {
            $data = json_decode($json, false, 512, JSON_THROW_ON_ERROR);
            if (!$data instanceof stdClass || ($data->version ?? null) !== 1
                || !is_int($data->revision ?? null) || $data->revision < 1
                || !is_int($data->system_cents ?? null)
                || !is_array($data->adjustments ?? null) || !array_is_list($data->adjustments)) {
                throw new RuntimeException('Invalid earning line schema.');
            }
            $line = new EarningLine(new Money($data->system_cents));
            foreach ($data->adjustments as $item) {
                if (!$item instanceof stdClass || !is_int($item->amount_cents ?? null)
                    || !is_string($item->comment ?? null) || !is_string($item->occurred_at ?? null)) {
                    throw new RuntimeException('Invalid adjustment schema.');
                }
                $date = DateTimeImmutable::createFromFormat('!' . self::DATE_FORMAT, $item->occurred_at);
                if ($date === false || $date->format(self::DATE_FORMAT) !== $item->occurred_at) {
                    throw new RuntimeException('Invalid adjustment timestamp.');
                }
                $line->addAdjustment(new Adjustment(new Money($item->amount_cents), $item->comment, $date));
            }
            $line->currentValue();
            return new StoredEarningLine($line, $data->revision);
        } catch (Throwable $exception) {
            throw new RuntimeException('Invalid or corrupt earning line.', 0, $exception);
        }
    }

    /** @return array{amount_cents: int, comment: string, occurred_at: string} */
    private function adjustmentData(Adjustment $adjustment): array
    {
        return [
            'amount_cents' => $adjustment->amount()->cents(),
            'comment' => $adjustment->comment(),
            'occurred_at' => $adjustment->occurredAt()->format(self::DATE_FORMAT),
        ];
    }

    private function writeAtomically(string $id, string $json): void
    {
        $temporary = $this->directory . '/.' . $id . '.' . bin2hex(random_bytes(16)) . '.tmp';
        $file = @fopen($temporary, 'x');
        if ($file === false) {
            throw new RuntimeException('Cannot create temporary storage file.');
        }
        try {
            $written = @fwrite($file, $json);
            if ($written !== strlen($json) || !@fflush($file)) {
                throw new RuntimeException('Cannot write earning line.');
            }
            if (!@fclose($file)) {
                $file = null;
                throw new RuntimeException('Cannot close temporary storage file.');
            }
            $file = null;
            if (!@rename($temporary, $this->directory . '/' . $id . '.json')) {
                throw new RuntimeException('Cannot replace earning line file.');
            }
        } finally {
            if (is_resource($file)) {
                fclose($file);
            }
            if (file_exists($temporary)) {
                @unlink($temporary);
            }
        }
    }
}