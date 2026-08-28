<?php

declare(strict_types=1);

namespace App\Services;

class ClientConfigurationService
{
    private const DEFINITIONS = [
        'branding' => [
            'column' => 'branding_json',
            'title' => 'Branding',
            'keys' => [
                'icon' => ['label' => 'Icon URL', 'type' => 'image_url', 'placeholder' => 'https://example.com/icon.svg'],
                'logo' => ['label' => 'Logo URL', 'type' => 'image_url', 'placeholder' => 'https://example.com/logo.svg'],
            ],
        ],
        'settings' => [
            'column' => 'settings_json',
            'title' => 'Settings',
            'keys' => [
                'account_deletion_url' => ['label' => 'Account deletion URL', 'type' => 'absolute_url', 'placeholder' => 'https://example.com/account/delete'],
                'response_types' => ['label' => 'Response types', 'type' => 'list', 'placeholder' => 'code'],
            ],
        ],
    ];

    public function groups(array $client): array
    {
        $groups = [];

        foreach (self::DEFINITIONS as $groupKey => $definition) {
            $values = $this->decode((string) ($client[$definition['column']] ?? ''));
            $entries = [];

            foreach ($values as $key => $value) {
                $entries[] = [
                    'key' => (string) $key,
                    'label' => (string) ($definition['keys'][$key]['label'] ?? $key),
                    'value' => $this->displayValue($value),
                ];
            }

            $available = [];

            foreach ($definition['keys'] as $key => $keyDefinition) {
                if (!array_key_exists($key, $values)) {
                    $available[] = [
                        'key' => $key,
                        'label' => $keyDefinition['label'],
                        'input_type' => in_array($keyDefinition['type'], ['image_url', 'absolute_url'], true) ? 'url' : 'text',
                        'placeholder' => $keyDefinition['placeholder'],
                    ];
                }
            }

            $groups[] = [
                'key' => $groupKey,
                'title' => $definition['title'],
                'entries' => $entries,
                'available' => $available,
            ];
        }

        return $groups;
    }

    public function add(string $currentJson, string $group, string $key, string $value): ?string
    {
        $definition = self::DEFINITIONS[$group]['keys'][$key] ?? null;

        if (!is_array($definition)) {
            return null;
        }

        $values = $this->decode($currentJson);

        if (array_key_exists($key, $values)) {
            return null;
        }

        $normalized = $this->normalizeValue($value, (string) $definition['type']);

        if ($normalized === null) {
            return null;
        }

        $values[$key] = $normalized;

        return json_encode($values, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: null;
    }

    public function remove(string $currentJson, string $group, string $key): ?string
    {
        if (!isset(self::DEFINITIONS[$group]) || $key === '') {
            return null;
        }

        $values = $this->decode($currentJson);
        unset($values[$key]);

        return json_encode($values, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';
    }

    public function column(string $group): ?string
    {
        return isset(self::DEFINITIONS[$group]) ? (string) self::DEFINITIONS[$group]['column'] : null;
    }

    private function normalizeValue(string $value, string $type): string|array|null
    {
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        if ($type === 'list') {
            $items = preg_split('/[\s,]+/', $value) ?: [];
            $items = array_values(array_unique(array_filter(array_map('trim', $items))));

            return $items === [] ? null : $items;
        }

        if ($type === 'image_url' && str_starts_with($value, '/') && !str_starts_with($value, '//')) {
            return $value;
        }

        $parts = parse_url($value);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));

        return in_array($scheme, ['http', 'https'], true) && filter_var($value, FILTER_VALIDATE_URL)
            ? $value
            : null;
    }

    private function decode(string $json): array
    {
        if (trim($json) === '') {
            return [];
        }

        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function displayValue(mixed $value): string
    {
        if (is_array($value)) {
            return implode(', ', array_map('strval', $value));
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return (string) $value;
    }
}
