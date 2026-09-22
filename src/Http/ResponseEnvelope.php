<?php

namespace Fabby\Http;

/**
 * Builds the exact {status, message, result, id, action} shape Unity's
 * compiled C# (FabbyAudio.ReciveAudioResponse, JsonUtility.FromJson) and
 * the React client (src/api/types.ts FabbyResponse) both depend on. Do not
 * change these keys or their types without a coordinated change on both
 * of those consumers.
 */
final class ResponseEnvelope
{
    public static function success(string $action, string $id, string $result): array
    {
        return [
            'status' => 'success',
            'message' => '',
            'result' => $result,
            'id' => $id,
            'action' => $action,
        ];
    }

    public static function error(string $action, string $id, string $message): array
    {
        return [
            'status' => 'error',
            'message' => $message,
            'result' => '',
            'id' => $id,
            'action' => $action,
        ];
    }
}
