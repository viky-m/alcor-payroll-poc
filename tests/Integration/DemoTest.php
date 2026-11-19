<?php

declare(strict_types=1);

namespace Alcor\Payroll\Tests\Integration;

use DirectoryIterator;
use PHPUnit\Framework\TestCase;

final class DemoTest extends TestCase
{
    public function test_two_runs_display_the_scenario_and_preserve_previous_data(): void
    {
        $directory = sys_get_temp_dir() . '/payroll-demo-test-' . bin2hex(random_bytes(12));
        mkdir($directory);
        try {
            $firstOutput = $this->runDemo($directory);
            $firstFiles = glob($directory . '/*.json');
            self::assertIsArray($firstFiles);
            self::assertCount(1, $firstFiles);
            $firstContent = file_get_contents($firstFiles[0]);

            $secondOutput = $this->runDemo($directory);
            $allFiles = glob($directory . '/*.json');
            self::assertIsArray($allFiles);
            self::assertCount(2, $allFiles);
            self::assertSame($firstContent, file_get_contents($firstFiles[0]));

            foreach ([$firstOutput, $secondOutput] as $output) {
                foreach (['$1,000.00', '$1,050.00', '$1,004.45', '$1,104.55', '$1,104.25', '$1,104.45',
                    'Employee declined dental benefit; reversing deduction',
                    'Late correction: missed approved overtime bonus', 'Minor rounding adjustment',
                    'Second minor rounding adjustment', 'Correcting mistake in adjustment #4'] as $expected) {
                    self::assertStringContainsString($expected, $output);
                }
            }
        } finally {
            foreach (new DirectoryIterator($directory) as $file) {
                if (!$file->isDot()) {
                    unlink($file->getPathname());
                }
            }
            rmdir($directory);
        }
    }

    private function runDemo(string $directory): string
    {
        $process = proc_open([PHP_BINARY, __DIR__ . '/../../bin/demo.php', $directory], [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes);
        self::assertIsResource($process);
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), (string) $error);
        self::assertIsString($output);
        return $output;
    }
}