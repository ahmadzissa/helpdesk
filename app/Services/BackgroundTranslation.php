<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class BackgroundTranslation
{
    private const PROTECTED = '~(```[\s\S]*?```|`[^`\r\n]*`|!\[[^\]]*\]\([^)\r\n]+\)|https?://[^\s<>"\x27)]+|/api/v1/(?:inline-images|canned-images)/[a-f0-9-]{36}|[\w.+-]+@[\w.-]+\.[A-Za-z]{2,}|\r?\n[\t ]*|\*\*|__|[\[\]])~iu';

    public function detect(string $text): string
    {
        $sample = mb_substr(trim(preg_replace(self::PROTECTED, ' ', $text)), 0, 4000);
        if ($sample === '') {
            throw new RuntimeException('No customer text is available for language detection. Select the customer language in the ticket.');
        }
        $response = $this->request('/detect', ['q' => [$sample]]);

        return $this->language($response['data']['detections'][0][0]['language'] ?? null);
    }

    /** @return array{text: string, source: string} */
    public function translate(string $text, string $target): array
    {
        $target = $this->language($target);
        $parts = preg_split(self::PROTECTED, $text, -1, PREG_SPLIT_DELIM_CAPTURE);
        $segments = [];
        foreach ($parts as $index => $part) {
            if ($index % 2 === 1 || trim($part) === '') {
                $segments[] = ['text' => $part, 'literal' => true];

                continue;
            }
            foreach (mb_str_split($part, 4000) as $chunk) {
                $segments[] = ['text' => $chunk, 'literal' => false];
            }
        }
        $batches = [];
        $batch = [];
        $length = 0;
        foreach ($segments as $index => $segment) {
            if ($segment['literal'] || trim($segment['text']) === '') {
                continue;
            }
            $size = mb_strlen($segment['text']);
            if ($batch && ($length + $size > 5000 || count($batch) === 128)) {
                $batches[] = $batch;
                $batch = [];
                $length = 0;
            }
            $batch[$index] = $segment['text'];
            $length += $size;
        }
        if ($batch) {
            $batches[] = $batch;
        }
        $sources = [];
        foreach ($batches as $batch) {
            $response = $this->request('', ['q' => array_values($batch), 'target' => $target, 'format' => 'text']);
            $translations = $response['data']['translations'] ?? null;
            if (! is_array($translations) || count($translations) !== count($batch)) {
                throw new RuntimeException('Google returned an incomplete translation.');
            }
            foreach (array_keys($batch) as $offset => $index) {
                $translated = $translations[$offset]['translatedText'] ?? null;
                if (! is_string($translated) || trim($translated) === '') {
                    throw new RuntimeException('Google returned an empty translation.');
                }
                $source = $this->language($translations[$offset]['detectedSourceLanguage'] ?? null);
                $sources[$source] = ($sources[$source] ?? 0) + mb_strlen($batch[$index]);
                preg_match('/^\s*/u', $batch[$index], $leading);
                preg_match('/\s*$/u', $batch[$index], $trailing);
                $segments[$index]['text'] = $leading[0].trim(html_entity_decode($translated, ENT_QUOTES | ENT_HTML5, 'UTF-8')).$trailing[0];
            }
        }
        arsort($sources);
        $source = array_key_first($sources) ?? $target;

        return ['text' => strcasecmp($source, $target) === 0 ? $text : implode('', array_column($segments, 'text')), 'source' => $source];
    }

    private function language(mixed $language): string
    {
        if (! is_string($language) || ! preg_match('/^[a-z]{2,3}(-[A-Za-z0-9]{2,8}){0,2}$/', $language)) {
            throw new RuntimeException('Google did not identify a valid language. Select the customer language and try again.');
        }

        return ['iw' => 'he', 'tl' => 'fil'][$language] ?? $language;
    }

    private function request(string $path, array $payload): array
    {
        $key = app(TranslationPolicy::class)->serverKey();
        if ($key === '') {
            throw new RuntimeException('Configure a Google server translation key in Settings → Translation.');
        }
        $response = Http::connectTimeout(10)->timeout(30)->withHeaders(['X-Goog-Api-Key' => $key])
            ->post('https://translation.googleapis.com/language/translate/v2'.$path, $payload);
        if (! $response->successful()) {
            throw new RuntimeException(match ($response->status()) {
                401, 403 => 'Google rejected the server translation key. Check API access and server restrictions in Settings → Translation.',
                429 => 'Google translation quota reached. Background translation will retry automatically.',
                default => 'Google translation is unavailable. Background translation will retry automatically.',
            });
        }
        $data = $response->json();
        if (! is_array($data)) {
            throw new RuntimeException('Google returned an invalid translation response.');
        }

        return $data;
    }
}
