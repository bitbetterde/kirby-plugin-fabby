<?php

namespace Fabby\Rag;

/**
 * Splits an extracted document into overlapping pieces small enough to embed.
 *
 * Sizes are in CHARACTERS, not tokens: tokenising would mean a Composer
 * dependency, and the plugin cannot take one (see the autoloader note in
 * index.php). ~1200 characters of German is roughly 300-400 tokens,
 * far inside text-embedding-3-*'s 8191-token limit, so the imprecision costs
 * nothing here.
 *
 * Everything is mb_* with an explicit UTF-8 encoding. substr() on German text
 * splits multibyte sequences, and the OpenAI API rejects invalid UTF-8 with a
 * 400 — a failure mode that only shows up on content with umlauts in it.
 */
final class Chunker
{
    /**
     * How far from the target size a paragraph or sentence boundary may sit
     * and still be preferred over a hard cut.
     */
    private const BOUNDARY_WINDOW_MIN = 0.6;
    private const BOUNDARY_WINDOW_MAX = 1.15;

    public function __construct(
        private readonly int $targetChars = 1200,
        private readonly int $overlapChars = 200,
        private readonly int $maxChunks = 200,
    ) {
    }

    /**
     * @return string[] Each chunk prefixed with the page title, ready to embed.
     */
    public function chunk(ExtractedDocument $doc): array
    {
        $text = $this->normalize($doc->text);

        if ($text === '') {
            return [];
        }

        $target = max(1, $this->targetChars);

        // A step of zero would never advance the cursor. Clamping the overlap
        // below the target guarantees step >= 1 no matter what the Panel says.
        $overlap = max(0, min($this->overlapChars, $target - 1));

        $chunks = [];
        $offset = 0;
        $length = mb_strlen($text, 'UTF-8');

        while ($offset < $length && count($chunks) < $this->maxChunks) {
            $remaining = $length - $offset;

            if ($remaining <= $target) {
                $piece = mb_substr($text, $offset, $remaining, 'UTF-8');
                $offset = $length;
            } else {
                $take = $this->boundaryWithin($text, $offset, $target, $length);
                $piece = mb_substr($text, $offset, $take, 'UTF-8');
                $offset += max(1, $take - $overlap);
            }

            $piece = trim($piece);

            if ($piece !== '') {
                $chunks[] = $this->withTitle($doc->title, $piece);
            }
        }

        return $chunks;
    }

    /**
     * Finds how many characters to take so the chunk ends on a paragraph or
     * sentence boundary, falling back to a hard cut at the target size.
     *
     * Without the fallback a single 6000-character paragraph would produce one
     * oversized chunk; without the search, chunks routinely end mid-sentence,
     * which measurably degrades retrieval.
     */
    private function boundaryWithin(string $text, int $offset, int $target, int $length): int
    {
        $windowStart = (int) ($target * self::BOUNDARY_WINDOW_MIN);
        $windowEnd = min((int) ($target * self::BOUNDARY_WINDOW_MAX), $length - $offset);

        if ($windowEnd <= $windowStart) {
            return $target;
        }

        $window = mb_substr($text, $offset + $windowStart, $windowEnd - $windowStart, 'UTF-8');

        // Paragraph break first — it is the strongest semantic boundary.
        $paragraph = mb_strrpos($window, "\n\n", 0, 'UTF-8');

        if ($paragraph !== false) {
            return $windowStart + $paragraph + 2;
        }

        // Then the last sentence end in the window.
        if (preg_match_all('/(?<=[.!?])\s/u', $window, $m, PREG_OFFSET_CAPTURE) > 0) {
            $last = end($m[0]);
            // PREG_OFFSET_CAPTURE reports BYTES; convert to characters.
            $charOffset = mb_strlen(substr($window, 0, $last[1]), 'UTF-8');

            return $windowStart + $charOffset + 1;
        }

        return $target;
    }

    /**
     * Chunk 4 of a page may contain no page-identifying words at all, so the
     * title goes in front of every chunk before embedding. It is the cheapest
     * known retrieval improvement and costs about ten tokens.
     */
    private function withTitle(string $title, string $piece): string
    {
        $title = trim($title);

        if ($title === '' || mb_strpos($piece, $title, 0, 'UTF-8') === 0) {
            return $piece;
        }

        return $title . "\n\n" . $piece;
    }

    private function normalize(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = preg_replace('/[ \t]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/ *\n */u', "\n", $text) ?? $text;
        $text = preg_replace('/\n{3,}/u', "\n\n", $text) ?? $text;

        return trim($text);
    }
}
