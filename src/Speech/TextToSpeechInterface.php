<?php

namespace Fabby\Speech;

interface TextToSpeechInterface
{
    /**
     * @return string|null Raw PCM bytes (16-bit LE, mono, 24kHz) on
     *   success, matching the format Unity's decoder and the React
     *   client's src/audio/pcm.ts expect. Null on failure.
     */
    public function synthesize(string $text): ?string;
}
