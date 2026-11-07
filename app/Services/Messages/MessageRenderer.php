<?php

namespace App\Services\Messages;

class MessageRenderer
{
    /** @return array{text: string, missing: list<string>} */
    public static function render(string $body, array $values): array
    {
        $missing = [];
        $text = preg_replace_callback('/\{([^{}]+)\}/u', function ($m) use ($values, &$missing) {
            if (array_key_exists($m[1], $values) && $values[$m[1]] !== null) {
                return (string) $values[$m[1]];
            }
            $missing[] = $m[1];

            return $m[0];
        }, $body);

        return ['text' => \App\Support\WhatsAppText::clean($text), 'missing' => array_values(array_unique($missing))];
    }
}
