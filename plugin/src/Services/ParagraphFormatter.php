<?php

declare(strict_types=1);

namespace AdrielPartners\WpAudioBuddy\Services;

if (! defined('ABSPATH')) {
    exit;
}

final class ParagraphFormatter
{
    private const ABBREVIATIONS = [
        'dr','mr','mrs','ms','jr','sr','st','ave','blvd','dept',
        'est','govt','inc','ltd','co','corp','vs','etc',
        'vol','no','ex','approx','gen','maj','capt','lt','sgt',
        'col','prof','rev','hon','sen','rep','gov','att',
        'jan','feb','mar','apr','jun','jul','aug','sep','oct','nov','dec',
    ];

    private const TRANSITIONS = [
        'now','so','alright','okay','first','finally','next','lastly',
        'meanwhile','however','therefore','nevertheless','furthermore',
        'additionally','moreover','moving on','and so','but','because',
        'the thing is','another thing','the question','what about',
    ];

    public function format(string $text, int $sp = 4): string
    {
        $text = trim($text);
        if ($text === '') return '';
        $sentences = $this->split($text);
        if (count($sentences) <= 1) return $text;
        return $this->paragraphs($sentences, max(2, $sp));
    }

    private function split(string $text): array
    {
        $text = preg_replace('/\R+/', ' ', $text);
        $text = preg_replace('/\h+/', ' ', $text);
        $text = trim($text);
        $text = $this->protect($text);
        $raw = preg_split('/(?<=[.!?])\s+(?=[A-Z0-9])/', $text);
        if (!is_array($raw)) return [$text];
        $result = [];
        foreach ($raw as $s) {
            $s = trim($this->unprotect($s));
            if ($s !== '') $result[] = $s;
        }
        return $result;
    }

    private function protect(string $t): string
    {
        foreach (self::ABBREVIATIONS as $a) {
            $t = preg_replace('/\b' . $a . '\./i', '@ABR' . strtoupper($a) . '@', $t);
        }
        return $t;
    }

    private function unprotect(string $t): string
    {
        foreach (self::ABBREVIATIONS as $a) {
            $t = str_replace('@ABR' . strtoupper($a) . '@', $a . '.', $t);
        }
        return $t;
    }

    private function paragraphs(array $sentences, int $size): string
    {
        $pars = []; $buf = []; $n = 0;
        foreach ($sentences as $s) {
            $buf[] = $s; $n++;
            foreach (self::TRANSITIONS as $t) {
                if ($n > 1 && stripos($s, $t) === 0) {
                    $last = array_pop($buf);
                    if ($buf) $pars[] = implode(' ', $buf);
                    $buf = [$last]; $n = 1;
                    break;
                }
            }
            if ($n >= $size && $buf) {
                $pars[] = implode(' ', $buf);
                $buf = []; $n = 0;
            }
        }
        if ($buf) $pars[] = implode(' ', $buf);
        return implode("\n\n", $pars);
    }
}
