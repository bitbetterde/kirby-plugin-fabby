<?php

namespace Fabby\Rag;

/**
 * Thrown when the embedding API cannot be reached or refuses a request.
 *
 * A single type so the indexer can catch embedding failures specifically and
 * keep the queued job for a later retry, rather than swallowing every
 * Throwable and losing the distinction between "try again" and "broken".
 */
final class EmbeddingException extends \RuntimeException
{
}
