<?php

namespace App\Services;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;

class EmailReplyContent
{
    private const REPLY_HEADER = '/(?:^|\n)[\t ]*(?:On[\t ]+[^\n]*(?:\n[^\n]+){0,3}?wrote:|[-_]{2,}[\t ]*Original Message[\t ]*[-_]{2,}|From:[^\n]+\n(?:Sent|Date):[^\n]+\nTo:[^\n]+\n(?:Cc:[^\n]+\n)?Subject:[^\n]+)[\t ]*(?:\n|$)/iu';

    public function text(string $body): string
    {
        $body = str_replace(["\r\n", "\r"], "\n", $body);
        if (preg_match(self::REPLY_HEADER, $body, $match, PREG_OFFSET_CAPTURE)) {
            $body = substr($body, 0, $match[0][1]);
        }

        return trim(preg_replace('/^[\t ]*>[^\n]*(?:\n|$)/m', '', $body));
    }

    public function html(string $html): string
    {
        $document = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        try {
            $document->loadHTML('<?xml encoding="UTF-8"><html><body>'.mb_substr($html, 0, 500000).'</body></html>', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        $body = $document->getElementsByTagName('body')->item(0);
        $this->removeHistory($body);
        $result = '';
        foreach ($body->childNodes as $child) {
            $result .= $document->saveHTML($child);
        }

        return $result;
    }

    private function removeHistory(DOMNode $parent): void
    {
        foreach (iterator_to_array($parent->childNodes) as $node) {
            if (! $node->parentNode) {
                continue;
            }
            if ($node instanceof DOMElement) {
                $classes = preg_split('/\s+/', strtolower($node->getAttribute('class')));
                if (array_intersect($classes, ['gmail_quote', 'yahoo_quoted']) || (strtolower($node->tagName) === 'blockquote' && strtolower($node->getAttribute('type')) === 'cite')) {
                    $parent->removeChild($node);

                    continue;
                }
                $text = trim(preg_replace('/\s+/u', ' ', $node->textContent));
                if (strtolower($node->getAttribute('id')) === 'divrplyfwdmsg'
                    || in_array('moz-cite-prefix', $classes, true)
                    || preg_match('/^On\s+.{1,700}?wrote:\s*$/iu', $text)) {
                    $this->removeFollowing($node);

                    return;
                }
                $this->removeHistory($node);
            } elseif ($node instanceof DOMText && preg_match(self::REPLY_HEADER, str_replace("\r", '', $node->textContent), $match, PREG_OFFSET_CAPTURE)) {
                $prefix = substr(str_replace("\r", '', $node->textContent), 0, $match[0][1]);
                $parent->insertBefore($node->ownerDocument->createTextNode($prefix), $node);
                $this->removeFollowing($node);

                return;
            }
        }
    }

    private function removeFollowing(DOMNode $node): void
    {
        $removeNode = true;
        while ($node->parentNode) {
            $parent = $node->parentNode;
            while ($node->nextSibling) {
                $parent->removeChild($node->nextSibling);
            }
            if ($removeNode) {
                $parent->removeChild($node);
                $removeNode = false;
            }
            if (strtolower($parent->nodeName) === 'body') {
                return;
            }
            $node = $parent;
        }
    }
}
