<?php

namespace App\Services;

class FlexMessageBuilder
{
    /**
     * Render a Flex template by interpolating {{var}} placeholders against the variables map.
     * Returns the parsed Flex payload (associative array).
     */
    public function render(string $jsonTemplate, array $variables): array
    {
        $rendered = $this->interpolate($jsonTemplate, $variables);
        $decoded = json_decode($rendered, true, 512, JSON_THROW_ON_ERROR);

        // Sanitize: LINE silently drops Flex Messages that contain uri actions with
        // empty string — replace any empty uri with a safe fallback.
        $this->sanitizeUriActions($decoded);

        return $decoded;
    }

    /**
     * Build the full MOPH Alert request payload — array of messages (a leading text + the flex bubble).
     */
    public function buildPayload(array $renderedFlex, string $textMessage, string $altText): array
    {
        // Ensure altText is set on the flex message
        $flex = $renderedFlex;
        $flex['type'] = 'flex';
        $flex['altText'] = $renderedFlex['altText'] ?? $altText;

        return [
            'messages' => [
                ['type' => 'text', 'text' => $textMessage],
                $flex,
            ],
        ];
    }

    /**
     * Recursively walk the Flex JSON tree and replace any empty, localhost, or
     * non-public `uri` inside an action node with a safe URL so LINE does not
     * silently discard the message.
     *
     * LINE rejects Flex Messages whose button/action uri:
     *  - is empty string
     *  - points to localhost / 127.0.0.1 (not publicly reachable)
     *  - is missing the scheme
     */
    private function sanitizeUriActions(array &$node): void
    {
        foreach ($node as $key => &$value) {
            if (is_array($value)) {
                if (isset($value['type']) && $value['type'] === 'uri') {
                    $uri = $value['uri'] ?? '';
                    if ($this->isUnreachableUri($uri)) {
                        $value['uri'] = 'https://moph.go.th'; // safe public fallback
                    }
                }
                $this->sanitizeUriActions($value);
            }
        }
    }

    /**
     * Return true when the URI cannot be reached by LINE's servers.
     */
    private function isUnreachableUri(string $uri): bool
    {
        if ($uri === '') return true;

        $host = parse_url($uri, PHP_URL_HOST) ?? '';

        // localhost, loopback, and private/link-local addresses
        return in_array($host, ['localhost', '127.0.0.1', '::1'], true)
            || str_starts_with($host, '192.168.')
            || str_starts_with($host, '10.')
            || str_starts_with($host, '172.')
            || $host === '';
    }

    private function interpolate(string $template, array $vars): string
    {
        return preg_replace_callback('/\{\{\s*([a-z0-9_.]+)\s*\}\}/i', function ($m) use ($vars) {
            $key = $m[1];
            $value = $this->getNested($vars, $key);
            if ($value === null) return '';
            // Escape for JSON string context: quotes, backslashes, control chars
            return $this->jsonEscape((string) $value);
        }, $template);
    }

    private function getNested(array $arr, string $dottedKey): mixed
    {
        $segments = explode('.', $dottedKey);
        $cur = $arr;
        foreach ($segments as $seg) {
            if (! is_array($cur) || ! array_key_exists($seg, $cur)) return null;
            $cur = $cur[$seg];
        }

        return $cur;
    }

    private function jsonEscape(string $value): string
    {
        // json_encode then strip surrounding quotes — handles escaping properly
        return trim(json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), '"');
    }
}
