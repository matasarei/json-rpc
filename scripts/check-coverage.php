<?php

/**
 * Fails when the Clover report does not cover every line of the source.
 *
 * Usage: php scripts/check-coverage.php [report] [threshold]
 */

declare(strict_types=1);

$report = $argv[1] ?? 'build/clover.xml';
$threshold = (float) ($argv[2] ?? 100);

if (!is_file($report)) {
    fwrite(STDERR, sprintf('Coverage report not found: %s%s', $report, PHP_EOL));

    exit(1);
}

$xml = simplexml_load_file($report);

if ($xml === false) {
    fwrite(STDERR, sprintf('Coverage report cannot be read: %s%s', $report, PHP_EOL));

    exit(1);
}

$statements = 0;
$covered = 0;
$uncovered = [];

foreach ($xml->xpath('//file') ?? [] as $file) {
    $name = (string) $file['name'];

    foreach ($file->line as $line) {
        $statements++;

        if ((int) $line['count'] > 0) {
            $covered++;

            continue;
        }

        $uncovered[] = sprintf('%s:%d', $name, (int) $line['num']);
    }
}

if ($statements === 0) {
    fwrite(STDERR, 'The coverage report is empty' . PHP_EOL);

    exit(1);
}

$percentage = $covered / $statements * 100;

printf('Coverage: %.2f%% (%d/%d lines)%s', $percentage, $covered, $statements, PHP_EOL);

if ($uncovered !== []) {
    fwrite(STDERR, 'Uncovered lines:' . PHP_EOL);

    foreach ($uncovered as $line) {
        fwrite(STDERR, '  ' . $line . PHP_EOL);
    }
}

if ($percentage + 0.005 < $threshold) {
    fwrite(STDERR, sprintf('Coverage is below the required %.2f%%%s', $threshold, PHP_EOL));

    exit(1);
}

exit(0);
