<?php

namespace Fabby\Speech;

/**
 * Ported from fabby.php::callElevenLabs / text_to_audio. Returns raw PCM
 * bytes (output_format=pcm_24000) — the caller (Fabby\Chat\FabbyService)
 * is responsible for rawurlencode()-ing this for transport, never base64,
 * to match src/audio/pcm.ts's manual percent-decoder on the client.
 */
final class ElevenLabsTts implements TextToSpeechInterface
{
    public function __construct(
        private readonly string $apiKey,
        private readonly string $voiceId,
        private readonly string $model,
        private readonly array $voiceSettings,
        private readonly string $languageCode = 'de',
        private readonly string $baseUrl = 'https://api.elevenlabs.io/v1'
    ) {
    }

    public function synthesize(string $text): ?string
    {
        $data = [
            'text' => $text,
            'model_id' => $this->model,
            'voice_id' => $this->voiceId,
            'voice_settings' => $this->voiceSettings,
        ];

        // Ohne diesen Hinweis raet das Modell die Sprache aus dem Text. Bei
        // kurzen, zahlenlastigen Passagen ("16:00 - 20:00 Uhr") ist das wenig
        // Kontext, und die Aussprache kippt ins Englische.
        //
        // ACHTUNG: Nur die v2.5-Modelle (flash/turbo) und v3 nehmen den
        // Parameter an — `eleven_multilingual_v2` unterstuetzt ihn
        // ausdruecklich NICHT. Bei einem Wechsel des Modells in den
        // Einstellungen wird er von der API stillschweigend ignoriert.
        if ($this->languageCode !== '') {
            $data['language_code'] = $this->languageCode;
        }

        $url = $this->baseUrl . '/text-to-speech/' . $this->voiceId
            . '?output_format=pcm_24000&inactivity_timeout=120';

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data, JSON_UNESCAPED_UNICODE));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'xi-api-key: ' . $this->apiKey,
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_TIMEOUT, 120);

        $response = curl_exec($ch);
        $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        unset($ch);

        if ($curlError || $statusCode !== 200 || !$response) {
            return null;
        }

        return $response;
    }
}
