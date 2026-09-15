<?php

namespace App\Services;

use App\Models\Message;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;
use Illuminate\Support\Str;

class EmailContent
{
    public const IMAGE_TYPES = ['image/png', 'image/jpeg', 'image/gif', 'image/webp'];

    private const TAGS = ['p', 'div', 'span', 'br', 'strong', 'b', 'em', 'i', 'u', 's', 'del', 'blockquote', 'ul', 'ol', 'li', 'table', 'thead', 'tbody', 'tfoot', 'tr', 'td', 'th', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'pre', 'code', 'hr', 'a', 'img'];

    private const DROP = ['script', 'style', 'iframe', 'object', 'embed', 'form', 'input', 'button', 'textarea', 'select', 'svg', 'math', 'noscript', 'template', 'head', 'meta', 'link', 'base'];

    public function render(Message $message, ?string $translatedBody = null, string $format = 'text'): string
    {
        if ($message->kind !== 'inbound') {
            return Message::renderBody($translatedBody ?? $message->body, $message->kind);
        }

        if ($translatedBody !== null) {
            $translatedBody = $this->withoutTrackingLabels($translatedBody, $message);
        }
        $html = $translatedBody === null ? $message->email_html : ($format === 'html' ? $translatedBody : null);
        if (! $html) {
            $html = Str::markdown($this->text($message, $translatedBody), ['html_input' => 'escape', 'allow_unsafe_links' => false, 'renderer' => ['soft_break' => '<br />']]);
        }

        return $this->sanitize(app(EmailReplyContent::class)->html($html), $message);
    }

    public function sanitize(string $html, Message $message): string
    {
        $document = $this->document($html);
        $output = new DOMDocument('1.0', 'UTF-8');
        $root = $output->appendChild($output->createElement('div'));
        foreach ($document->getElementsByTagName('body') as $body) {
            foreach ($body->childNodes as $child) {
                $this->copy($child, $root, $message);
            }
        }

        $result = '';
        foreach ($root->childNodes as $child) {
            $result .= $output->saveHTML($child);
        }

        return $result;
    }

    public function text(Message $message, ?string $body = null): string
    {
        $body ??= $message->body;
        if ($message->kind !== 'inbound') {
            return $body;
        }

        return app(EmailReplyContent::class)->text($this->withoutTrackingLabels($body, $message));
    }

    /** @return list<int> */
    public function inlineAttachmentIndexes(Message $message): array
    {
        if ($message->kind !== 'inbound' || ! $message->email_html) {
            return [];
        }
        $indexes = [];
        foreach ($this->document($message->email_html)->getElementsByTagName('img') as $image) {
            $source = trim($image->getAttribute('src') ?: $image->getAttribute('data-email-src'));
            $path = $this->imagePath($source, $message);
            if ($path && preg_match('~/([0-9]+)/inline$~', $path, $match)) {
                $indexes[] = (int) $match[1];
            }
        }

        return array_values(array_unique($indexes));
    }

    public function withoutTrackingLabels(string $body, Message $message): string
    {
        if ($message->kind !== 'inbound' || ! $message->email_html) {
            return $body;
        }
        foreach ($this->document($message->email_html)->getElementsByTagName('img') as $image) {
            $label = trim($image->getAttribute('alt'));
            if ($label !== '' && $this->trackingPixel($image)) {
                $body = str_replace('['.$label.']', '', $body);
            }
        }

        return $body;
    }

    private function document(string $html): DOMDocument
    {
        $document = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        try {
            $document->loadHTML('<?xml encoding="UTF-8"><html><body>'.mb_substr($html, 0, 500000).'</body></html>', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        return $document;
    }

    private function copy(DOMNode $node, DOMNode $parent, Message $message): void
    {
        $document = $parent->ownerDocument;
        if ($node instanceof DOMText) {
            $parent->appendChild($document->createTextNode($node->textContent));

            return;
        }
        if (! $node instanceof DOMElement) {
            return;
        }
        $tag = strtolower($node->tagName);
        if (in_array($tag, self::DROP, true)) {
            return;
        }
        if (! in_array($tag, self::TAGS, true)) {
            foreach ($node->childNodes as $child) {
                $this->copy($child, $parent, $message);
            }

            return;
        }
        $element = $document->createElement($tag);
        if ($tag === 'img') {
            if ($this->trackingPixel($node)) {
                return;
            }
            $source = trim($node->getAttribute('src') ?: $node->getAttribute('data-email-src'));
            $local = $this->imagePath($source, $message);
            if ($local) {
                $element->setAttribute('src', $local);
            } elseif ($this->webUrl($source)) {
                $element->setAttribute('data-email-src', $source);
            } else {
                return;
            }
            $element->setAttribute('alt', mb_substr($node->getAttribute('alt') ?: 'Email image', 0, 200));
            if ($node->hasAttribute('title')) {
                $element->setAttribute('title', mb_substr($node->getAttribute('title'), 0, 200));
            }
            $element->setAttribute('loading', 'lazy');
            $element->setAttribute('referrerpolicy', 'no-referrer');
            $width = $node->getAttribute('width');
            if (ctype_digit($width) && (int) $width > 2) {
                $element->setAttribute('width', (string) min((int) $width, 1600));
            }
        }
        if ($tag === 'a') {
            $href = trim($node->getAttribute('href'));
            if ($this->webUrl($href) || (str_starts_with($href, 'mailto:') && filter_var(substr($href, 7), FILTER_VALIDATE_EMAIL))) {
                $element->setAttribute('href', $href);
                $element->setAttribute('target', '_blank');
                $element->setAttribute('rel', 'noopener noreferrer');
            }
        }
        if (in_array($node->getAttribute('dir'), ['ltr', 'rtl', 'auto'], true)) {
            $element->setAttribute('dir', $node->getAttribute('dir'));
        }
        if (in_array($tag, ['td', 'th'], true)) {
            foreach (['colspan', 'rowspan'] as $attribute) {
                $value = $node->getAttribute($attribute);
                if (ctype_digit($value) && (int) $value >= 1 && (int) $value <= 100) {
                    $element->setAttribute($attribute, $value);
                }
            }
        }
        $styles = [];
        foreach (explode(';', $node->getAttribute('style')) as $declaration) {
            [$property, $value] = array_pad(explode(':', $declaration, 2), 2, '');
            $property = strtolower(trim($property));
            $value = strtolower(trim($value));
            $pattern = match ($property) {
                'text-align' => '/^(left|right|center|justify|start|end)$/',
                'font-weight' => '/^(normal|bold|[1-9]00)$/',
                'font-style' => '/^(normal|italic)$/',
                'text-decoration' => '/^(none|underline|line-through)$/',
                'color', 'background-color' => '/^(#[a-f0-9]{3,8}|[a-z]{1,20}|rgb\(\s*\d{1,3}\s*,\s*\d{1,3}\s*,\s*\d{1,3}\s*\))$/',
                default => null,
            };
            if ($pattern && preg_match($pattern, $value)) {
                $styles[$property] = $property.':'.$value;
            }
        }
        if ($styles !== []) {
            $element->setAttribute('style', implode(';', $styles));
        }
        $parent->appendChild($element);
        foreach ($node->childNodes as $child) {
            $this->copy($child, $element, $message);
        }
    }

    private function webUrl(string $url): bool
    {
        return preg_match('~^https?://~i', $url) && ! preg_match('/[\x00-\x20\x7f]/', $url) && filter_var($url, FILTER_VALIDATE_URL);
    }

    private function trackingPixel(DOMElement $image): bool
    {
        foreach (['width', 'height'] as $attribute) {
            $value = $image->getAttribute($attribute);
            if ($value !== '' && is_numeric($value) && (float) $value <= 2) {
                return true;
            }
        }

        return (bool) preg_match('/(?:^|;)\s*(?:(?:width|height)\s*:\s*[012](?:px)?(?:\s*;|\s*$)|display\s*:\s*none)/i', $image->getAttribute('style'));
    }

    private function imagePath(string $source, Message $message): ?string
    {
        foreach ($message->attachments ?? [] as $index => $file) {
            if (! in_array($file['mime'] ?? '', self::IMAGE_TYPES, true)) {
                continue;
            }
            $path = '/api/v1/attachments/'.$message->id.'/'.$index.'/inline';
            $cid = trim(rawurldecode(substr($source, 4)), '<> ');
            if ($source === $path || (str_starts_with(strtolower($source), 'cid:') && $cid !== '' && $cid === ($file['content_id'] ?? null))) {
                return $path;
            }
        }

        return null;
    }
}
