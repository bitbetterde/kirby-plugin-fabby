<?php

namespace Fabby\Chat;

use Fabby\Config\FabbyConfig;
use Fabby\Llm\LlmProviderFactory;
use Fabby\Speech\SpeechToTextInterface;
use Fabby\Speech\TextToSpeechInterface;
use Fabby\Tools\ToolRegistry;

/**
 * Orchestrates a single chat/TTS/STT request. Equivalent to the original
 * fabby.php class, but composed from focused collaborators instead of one
 * 508-line class: LlmProviderFactory for the model, ToolRegistry for
 * available tools, PromptBuilder for the system prompt, and injected
 * Speech services for TTS/STT.
 *
 * Owns the tool-execution loop itself (see LlmProviderInterface docblock):
 * one call, and if tool calls are returned, execute them and make exactly
 * one non-recursive follow-up call — this mirrors the original backend's
 * behavior and keeps it visible/testable here rather than hidden inside a
 * provider implementation.
 */
final class FabbyService
{
    public $error = '';

    public function __construct(
        private readonly FabbyConfig $config,
        private readonly LlmProviderFactory $providerFactory,
        private readonly ToolRegistry $tools,
        private readonly PromptBuilder $promptBuilder,
        private readonly TextToSpeechInterface $tts,
        private readonly SpeechToTextInterface $stt
    ) {
    }

    /**
     * @param string $text The already-flattened conversation history blob
     *   built client-side by src/chat/history.ts (HISTORY_PREFIX + "User:
     *   "/"You: " lines). Sent to the model as a single `user` message,
     *   matching the original's behavior — the Panel-configured system
     *   prompt's instructions assume exactly this format.
     */
    public function questionToAnswer(string $text): ?string
    {
        $text = rawurldecode($text);

        $provider = $this->providerFactory->make($this->config->provider());
        $systemPrompt = $this->promptBuilder->build($this->config->systemPrompt());
        $tools = $this->tools->enabledDefinitions();
        $options = ['model' => $this->config->model(), 'maxTokens' => $this->config->maxTokens()];

        $result = $provider->chatCompletion($systemPrompt, $text, $tools, $options);

        if (!$result->isSuccess()) {
            $this->error = $result->error;
            return null;
        }

        if ($result->hasToolCalls()) {
            $toolResults = [];

            foreach ($result->toolCalls as $call) {
                $executor = $this->tools->executorFor($call->name);
                $toolConfig = $this->tools->configFor($call->name);
                $toolResults[$call->id] = $executor
                    ? $executor->execute($call->arguments, $toolConfig)
                    : "Error: tool '{$call->name}' is not available.";
            }

            $result = $provider->continueWithToolResults(
                $systemPrompt,
                $text,
                $result->toolCalls,
                $toolResults,
                $result,
                $options
            );

            if (!$result->isSuccess()) {
                $this->error = $result->error;
                return null;
            }
        }

        if ($result->content === null) {
            $this->error = 'No content in response';
            return null;
        }

        return $result->content;
    }

    /**
     * @return string|null rawurlencode()'d raw PCM bytes — NOT base64.
     *   Matches src/audio/pcm.ts's manual percent-decoder and Unity's
     *   decoder on the client side.
     */
    public function textToAudio(string $text): ?string
    {
        if (!$this->config->ttsEnabled()) {
            $this->error = 'TTS disabled';
            return null;
        }

        $text = rawurldecode($text);
        $pcm = $this->tts->synthesize($text);

        if ($pcm === null) {
            $this->error = '11L no response';
            return null;
        }

        return rawurlencode($pcm);
    }

    public function audioToText(string $audioDataUrl): ?string
    {
        $audioDataUrl = rawurldecode($audioDataUrl);

        if (!str_starts_with($audioDataUrl, 'data:audio/webm;base64,')) {
            $this->error = 'Invalid audio payload';
            return null;
        }

        $base64 = substr($audioDataUrl, strlen('data:audio/webm;base64,'));
        $bytes = base64_decode($base64, true);

        if ($bytes === false) {
            $this->error = 'Invalid audio payload';
            return null;
        }

        $text = $this->stt->transcribe($bytes);

        if (!$text) {
            $this->error = 'AT no text';
            return null;
        }

        return $text;
    }
}
