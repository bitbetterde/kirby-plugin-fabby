<?php

/** Run with: php tests/prompt.php. No Kirby installation or API calls needed. */
require dirname(__DIR__) . '/src/Chat/PromptBuilder.php';

use Fabby\Chat\PromptBuilder;

$builder = new PromptBuilder();
$cases = [
    ['2026-10-01', '01.10.2026', 'Oktober 2026'],
    ['2026-10-15', '15.10.2026', 'Oktober 2026'],
    ['2026-10-31', '31.10.2026', 'Oktober 2026'],
    ['2026-01-01', '01.01.2026', 'Januar 2026'],
    ['2026-01-15', '15.01.2026', 'Januar 2026'],
    ['2026-12-31', '31.12.2026', 'Dezember 2026'],
    ['2028-02-29', '29.02.2028', 'Februar 2028'],
];

foreach ($cases as [$today, $cutoff, $month]) {
    $base = 'Custom persona and editorial instructions.';
    $prompt = $builder->build($base, new DateTimeImmutable($today, new DateTimeZone('UTC')));
    $assert = static function (bool $condition, string $message) use ($today): void {
        if (!$condition) {
            throw new RuntimeException($today . ': ' . $message);
        }
    };
    $assert(str_starts_with($prompt, $base), 'Editorial prompt was changed');
    $assert(str_contains($prompt, 'NACH dem heutigen Datum (' . $cutoff . ')'), 'Upcoming cutoff must use the full current date');
    $assert(str_contains($prompt, 'vor oder am ' . $cutoff), 'Today and earlier dates must remain excluded');
    $assert(str_contains($prompt, 'Events nach dem ' . $cutoff . ' sind gültig, auch wenn sie noch im ' . $month), 'Later events in the current month must be allowed');
    $assert(str_contains($prompt, '(Tag, Monat und Jahr), nicht nur den Monat'), 'Comparison must account for days and year boundaries');
    $assert(!str_contains($prompt, 'sind VORBEI'), 'Prompt must not declare whole months past');
    $assert(!str_contains($prompt, 'Events ab '), 'Eligibility must not start at a later month');
}

echo 'All ' . count($cases) . " prompt date scenarios passed.\n";
