<?php

declare(strict_types=1);

namespace App\Utils;

class Tokenizer
{
    /**
     * Normalize text: lowercase, remove punctuation, collapse whitespace
     */
    public function normalize(string $text): string
    {
        // Convert to lowercase
        $text = mb_strtolower($text, 'UTF-8');

        // Remove punctuation (keep alphanumeric and spaces)
        $text = preg_replace('/[^\p{L}\p{N}\s]/u', '', $text);

        // Collapse whitespace
        $text = preg_replace('/\s+/', ' ', $text);

        return trim($text);
    }

    /**
     * Generate edge n-grams (prefix only) from text
     * min=3, max=6, cap=6 tokens per record
     */
    public function edgeNgrams(string $text, int $min = 3, int $max = 6, int $cap = 6): array
    {
        $normalized = $this->normalize($text);
        $tokens = [];
        $length = mb_strlen($normalized, 'UTF-8');

        if ($length < $min) {
            return [];
        }

        // Generate prefixes from min to max length
        for ($i = $min; $i <= min($max, $length); $i++) {
            $token = mb_substr($normalized, 0, $i, 'UTF-8');
            if (mb_strlen($token, 'UTF-8') >= $min) {
                $tokens[] = $token;
            }

            // Cap at max tokens
            if (count($tokens) >= $cap) {
                break;
            }
        }

        return array_unique($tokens);
    }

    /**
     * Generate tokens from domains (JSON array)
     */
    public function tokenizeDomains(array $domains): array
    {
        $allTokens = [];

        foreach ($domains as $domain) {
            // Remove protocol and www
            $domain = preg_replace('/^https?:\/\/(www\.)?/', '', $domain);
            $domain = preg_replace('/\/.*$/', '', $domain); // Remove path

            $tokens = $this->edgeNgrams($domain, 3, 6, 6);
            $allTokens = array_merge($allTokens, $tokens);
        }

        return array_unique($allTokens);
    }
}

