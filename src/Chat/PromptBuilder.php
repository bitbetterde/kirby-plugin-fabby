<?php

namespace Fabby\Chat;

/**
 * Builds the effective system prompt: the Panel-editable persona/behavior
 * text plus a dynamically generated date/event-filtering block appended at
 * call time (ported from fabby.php::question_to_answer).
 *
 * Fixes a bug present in the original: the old code always appended the
 * literal string "(November)" after the computed month number regardless
 * of the actual current month. Here the month name is computed once and
 * used consistently — no hardcoded literal.
 */
final class PromptBuilder
{
    private const MONTH_NAMES_DE = [
        1 => 'Januar', 2 => 'Februar', 3 => 'März', 4 => 'April',
        5 => 'Mai', 6 => 'Juni', 7 => 'Juli', 8 => 'August',
        9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Dezember',
    ];

    /**
     * @param \DateTimeImmutable|null $now Injectable for testing; defaults
     *   to the actual current time.
     */
    public function build(string $basePrompt, ?\DateTimeImmutable $now = null): string
    {
        $now ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        $currentDate = $now->format('d.m.Y');
        $currentYear = (int) $now->format('Y');
        $currentMonth = (int) $now->format('n');
        $currentDay = (int) $now->format('j');
        $currentMonthName = self::MONTH_NAMES_DE[$currentMonth];

        $block = "\n\n=== AKTUELLES DATUM ===\n";
        $block .= "Heute ist: {$currentDate} ({$currentDay}. {$currentMonthName} {$currentYear})\n";
        $block .= "Aktueller Monat: {$currentMonth} ({$currentMonthName})\n\n";
        $block .= "WICHTIG - Event Filterung:\n";
        $block .= "- Zeige NUR Events die NACH dem heutigen Datum liegen\n";
        $block .= "- Events im Jahr {$currentYear} in den Monaten 1-{$currentMonth} "
            . "(Januar-{$currentMonthName}) sind VORBEI\n";
        $block .= "- Events im {$currentMonthName} {$currentYear} die vor dem {$currentDay}. "
            . "stattfanden sind VORBEI\n";

        $nextMonthDate = $now->modify('first day of next month');
        $nextMonthName = self::MONTH_NAMES_DE[(int) $nextMonthDate->format('n')];
        $nextMonthYear = (int) $nextMonthDate->format('Y');

        $block .= "- Events ab {$nextMonthName} {$nextMonthYear} sind gültig\n";
        $block .= "- Liste KEINE vergangenen Events auf!\n";

        return $basePrompt . $block;
    }
}
