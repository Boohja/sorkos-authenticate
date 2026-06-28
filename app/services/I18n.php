<?php

declare(strict_types=1);

namespace App\Services;

use Base;

class I18n
{
    private const SUPPORTED = ['en', 'de'];

    private Base $f3;
    private string $language;

    public function __construct(Base $f3, string $language)
    {
        $this->f3 = $f3;
        $this->language = in_array($language, self::SUPPORTED, true) ? $language : 'en';
    }

    public static function fromRequest(Base $f3, ?array $client = null): self
    {
        $allowed = $client ? ClientService::csvToList((string) $client['allowed_languages']) : self::SUPPORTED;
        $default = $client && in_array((string) $client['default_language'], $allowed, true)
            ? (string) $client['default_language']
            : 'en';

        $requested = self::normalize((string) ($f3->get('GET.lang') ?? ''));

        if ($requested !== '' && in_array($requested, $allowed, true)) {
            $f3->set('LANGUAGE', $requested);
            return new self($f3, $requested);
        }

        $browser = self::normalize((string) ($f3->get('HEADERS.Accept-Language') ?? ''));

        if ($browser !== '' && in_array($browser, $allowed, true)) {
            $f3->set('LANGUAGE', $browser);
            return new self($f3, $browser);
        }

        $f3->set('LANGUAGE', $default);
        return new self($f3, $default);
    }

    public function language(): string
    {
        return $this->language;
    }

    public function t(string $key, array $placeholders = []): string
    {
        $text = (string) ($this->f3->exists($key) ? $this->f3->get($key) : $key);

        foreach ($placeholders as $name => $value) {
            $text = str_replace('{' . $name . '}', (string) $value, $text);
        }

        foreach (array_values($placeholders) as $index => $value) {
            $text = str_replace('{' . $index . '}', (string) $value, $text);
        }

        return $text;
    }

    private static function normalize(string $language): string
    {
        if ($language === '') {
            return '';
        }

        $first = strtolower(trim(explode(',', $language)[0]));
        return substr(str_replace('_', '-', $first), 0, 2);
    }
}
