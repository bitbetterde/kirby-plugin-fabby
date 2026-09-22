<?php

namespace Fabby\Tools;

interface ToolExecutorInterface
{
    /**
     * Runs the tool's side effect (e.g. a web search) and returns a plain
     * text result the model can consume as tool output.
     *
     * @param array $arguments Decoded tool-call arguments from the model.
     * @param array $config Panel-configured settings for this tool row
     *   (e.g. ['domains' => ['https://example.org/', ...]]).
     */
    public function execute(array $arguments, array $config): string;
}
