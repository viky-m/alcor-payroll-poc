<?php

declare(strict_types=1);

namespace Alcor\Payroll\Tests\Integration;

use Alcor\Payroll\Domain\EarningLine;
use Alcor\Payroll\Domain\ValueObject\Adjustment;
use Alcor\Payroll\Domain\ValueObject\Money;
use Alcor\Payroll\Infrastructure\FileEarningLineRepository;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class FileEarningLineRepositoryTest extends TestCase
{
    private string $directory;
    private FileEarningLineRepository $repository;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/payroll-test-' . bin2hex(random_bytes(12));
        mkdir($this->directory);
        $this->repository = new FileEarningLineRepository($this->directory);
    }

    protected function tearDown(): void
    {
        chmod($this->directory, 0700);
        foreach (new \DirectoryIterator($this->directory) as $file) {
            if ($file->isDot()) {
                continue;
            }
            unlink($file->getPathname());
        }
        rmdir($this->directory);
    }

    public function test_it_saves_and_reloads_an_ordered_history_across_repository_instances(): void
    {
        self::assertNull($this->repository->load('line-1'));
        $line = new EarningLine(new Money(105_000));
        $line->addAdjustment($this->adjustment(-4_555, 'Benefit'));
        $line->addAdjustment($this->adjustment(10_010, 'Overtime'));
        self::assertSame(1, $this->repository->save('line-1', $line, null));

        $restarted = new FileEarningLineRepository($this->directory);
        $stored = $restarted->load('line-1');
        self::assertNotNull($stored);
        self::assertSame(1, $stored->revision);
        self::assertSame(110_455, $stored->line->currentValue()->cents());
        self::assertSame(['Benefit', 'Overtime'], array_map(
            static fn (Adjustment $item): string => $item->comment(),
            $stored->line->adjustments(),
        ));
        self::assertSame('2026-01-01T12:30:00.123456+02:00', $stored->line->adjustments()[0]->occurredAt()->format('Y-m-d\TH:i:s.uP'));
        $stored->line->recalculate(new Money(999));
        $stored->line->addAdjustment($this->adjustment(-10, 'Rounding'));
        self::assertSame(2, $restarted->save('line-1', $stored->line, $stored->revision));
        $again = $this->repository->load('line-1');
        self::assertNotNull($again);
        self::assertSame(105_000, $again->line->systemValue()->cents());
        self::assertSame(110_445, $again->line->currentValue()->cents());
    }

    public function test_it_accepts_a_changed_base_before_the_first_adjustment(): void
    {
        $line = new EarningLine(new Money(100));
        $this->repository->save('line-1', $line, null);
        $line->recalculate(new Money(200));
        $this->repository->save('line-1', $line, 1);
        self::assertSame(200, $this->repository->load('line-1')?->line->currentValue()->cents());
    }

    #[DataProvider('invalidHistoryChanges')]
    public function test_it_rejects_replaced_deleted_or_rebased_history(string $change): void
    {
        $original = new EarningLine(new Money(1000));
        $original->addAdjustment($this->adjustment(100, 'Original'));
        $this->repository->save('line-1', $original, null);
        $replacement = new EarningLine(new Money($change === 'base' ? 2000 : 1000));
        if ($change !== 'deleted') {
            $replacement->addAdjustment($this->adjustment(100, $change === 'modified' ? 'Modified' : 'Original'));
        }

        try {
            $this->repository->save('line-1', $replacement, 1);
            self::fail('History or frozen base was overwritten.');
        } catch (RuntimeException) {
            self::assertSame('Original', $this->repository->load('line-1')?->line->adjustments()[0]->comment());
        }
    }

    public function test_it_rejects_stale_revisions_and_duplicate_creation(): void
    {
        $this->repository->save('line-1', new EarningLine(new Money(100)), null);
        $first = $this->repository->load('line-1');
        $second = $this->repository->load('line-1');
        self::assertNotNull($first);
        self::assertNotNull($second);
        $first->line->addAdjustment($this->adjustment(20, 'First'));
        $this->repository->save('line-1', $first->line, $first->revision);
        $second->line->addAdjustment($this->adjustment(30, 'Second'));

        foreach ([1, null] as $revision) {
            try {
                $this->repository->save('line-1', $second->line, $revision);
                self::fail('A conflicting write was accepted.');
            } catch (RuntimeException) {
                self::assertSame(120, $this->repository->load('line-1')?->line->currentValue()->cents());
            }
        }
    }

    #[DataProvider('invalidIds')]
    public function test_it_rejects_invalid_identifiers(string $id): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->repository->load($id);
    }

    #[DataProvider('invalidDocuments')]
    public function test_it_reports_corrupt_or_invalid_stored_data(string $json): void
    {
        file_put_contents($this->directory . '/line-1.json', $json);
        $this->expectException(RuntimeException::class);
        $this->repository->load('line-1');
    }

    public function test_a_failed_write_leaves_the_previous_file_intact(): void
    {
        $line = new EarningLine(new Money(100));
        $this->repository->save('line-1', $line, null);
        $before = file_get_contents($this->directory . '/line-1.json');
        $line->addAdjustment($this->adjustment(10, 'Change'));
        chmod($this->directory, 0500);
        try {
            $this->repository->save('line-1', $line, 1);
            self::fail('A write in an unwritable directory succeeded.');
        } catch (RuntimeException) {
            self::assertSame($before, file_get_contents($this->directory . '/line-1.json'));
        }
    }

    /** @return iterable<string, array{string}> */
    public static function invalidHistoryChanges(): iterable
    {
        yield 'deleted' => ['deleted'];
        yield 'modified' => ['modified'];
        yield 'base changed' => ['base'];
    }

    /** @return iterable<string, array{string}> */
    public static function invalidIds(): iterable
    {
        yield 'empty' => [''];
        yield 'path traversal' => ['../line'];
        yield 'slash' => ['a/b'];
        yield 'hidden' => ['.hidden'];
    }

    /** @return iterable<string, array{string}> */
    public static function invalidDocuments(): iterable
    {
        yield 'broken json' => ['{'];
        yield 'wrong schema' => ['{"version":1,"revision":1,"system_cents":"100","adjustments":[]}'];
        yield 'history is an object' => ['{"version":1,"revision":1,"system_cents":100,"adjustments":{}}'];
        yield 'zero adjustment' => ['{"version":1,"revision":1,"system_cents":100,"adjustments":[{"amount_cents":0,"comment":"x","occurred_at":"2026-01-01T12:30:00.000000+00:00"}]}'];
        yield 'invalid timestamp' => ['{"version":1,"revision":1,"system_cents":100,"adjustments":[{"amount_cents":1,"comment":"x","occurred_at":"tomorrow"}]}'];
    }

    private function adjustment(int $cents, string $comment): Adjustment
    {
        return new Adjustment(new Money($cents), $comment, new DateTimeImmutable('2026-01-01T12:30:00.123456+02:00'));
    }
}