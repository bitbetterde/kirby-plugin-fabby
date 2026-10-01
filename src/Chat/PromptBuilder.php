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
        $block .= "- Zeige NUR Events die NACH dem heutigen Datum ({$currentDate}) liegen\n";
        $block .= "- Events vor oder am {$currentDate} dürfen nicht als kommende Events aufgelistet werden\n";
        $block .= "- Events nach dem {$currentDate} sind gültig, auch wenn sie noch im "
            . "{$currentMonthName} {$currentYear} stattfinden\n";
        $block .= "- Vergleiche das vollständige Veranstaltungsdatum (Tag, Monat und Jahr), nicht nur den Monat\n";
        $block .= "- Liste KEINE vergangenen Events auf!\n";

        return $basePrompt . $block;
    }
}
