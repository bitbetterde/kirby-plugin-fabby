<?php

namespace Fabby\Speech;

/**
 * Ported from fabby.php::audio_to_text. Unlike the original, the temp
 * file is always deleted in a finally block — the original left the
 * tempnam()'d .webm file on disk forever (a known, confirmed leak).
 */
final class OpenAiWhisperStt implements SpeechToTextInterface
{
    public function __construct(
        private readonly string $apiKey,
        private readonly string $baseUrl = 'https://api.openai.com/v1'
    ) {
    }

    public function transcribe(string $webmBytes): ?string
    {
        $tmpName = tempnam(sys_get_temp_dir(), 'fabby_audio_');
        $tmpPath = $tmpName . '.webm';
        rename($tmpName, $tmpPath);

        try {
            file_put_contents($tmpPath, $webmBytes);

            $uploadFile = new \CURLFile($tmpPath, 'audio/webm', basename($tmpPath));

            $ch = curl_init($this->baseUrl . '/audio/transcriptions');
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, [
                'model' => 'whisper-1',
                'language' => 'de',
                'prompt' => 'Antworte auf Deutsch.',
                'file' => $uploadFile,
            ]);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Authorization: Bearer ' . $this->apiKey,
            ]);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
            curl_setopt($ch, CURLOPT_TIMEOUT, 120);

            $response = curl_exec($ch);
            $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            unset($ch);

            if ($curlError !== '' || $statusCode !== 200 || !$response) {
                return null;
            }

            $decoded = json_decode($response, true);

            return $decoded['text'] ?? null;
        } finally {
            if (file_exists($tmpPath)) {
                unlink($tmpPath);
            }
        }
    }
}
