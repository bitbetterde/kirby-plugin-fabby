<?php

namespace Fabby\Speech;

interface SpeechToTextInterface
{
    /**
     * @param string $webmBytes Raw audio/webm bytes (already stripped of
     *   the `data:audio/webm;base64,` prefix and base64-decoded by the
     *   caller).
     * @return string|null Transcript on success, null on failure.
     */
    public function transcribe(string $webmBytes): ?string;
}
