<?php

declare(strict_types=1);

namespace PdfOcr;

/**
 * Pulls typed values out of the recognised text and normalises them, so the
 * JSON carries "2025-03-12" and 1234.56 next to whatever the page actually said.
 *
 * Patterns are tuned for Spanish administrative and technical documents
 * (NIF/CIF, IBAN, European number format, "12 de marzo de 2025").
 */
final class EntityExtractor
{
    private const MONTHS_ES = [
        'enero' => 1, 'febrero' => 2, 'marzo' => 3, 'abril' => 4, 'mayo' => 5, 'junio' => 6,
        'julio' => 7, 'agosto' => 8, 'septiembre' => 9, 'setiembre' => 9, 'octubre' => 10,
        'noviembre' => 11, 'diciembre' => 12,
    ];

    /**
     * @return list<array{type:string,page:int,raw:string,value:string|float|null,valid:?bool}>
     */
    public function extract(string $text, int $page): array
    {
        $found = [];

        foreach ($this->matches('/[\w.+-]+@[\w-]+\.[\w.-]{2,}/u', $text) as $raw) {
            $found[] = $this->entity('email', $page, $raw, strtolower($raw));
        }

        foreach ($this->matches('#\bhttps?://[^\s<>"\')]+#u', $text) as $raw) {
            $found[] = $this->entity('url', $page, $raw, rtrim($raw, '.,;:'));
        }

        // Nine digits, however they are grouped: 956 22 33 44, 956223344, +34 956 223 344.
        foreach ($this->matches('/(?<![\w.-])(?:\+?34[ .-]?)?\d(?:[ .-]?\d){8}(?![\w-])/u', $text) as $raw) {
            $digits = preg_replace('/\D/', '', $raw) ?? '';
            if (strlen($digits) < 9 || strlen($digits) > 11) {
                continue;
            }
            $found[] = $this->entity('phone', $page, $raw, $digits);
        }

        foreach ($this->matches('/\b[A-Z]{2}\d{2}[ ]?(?:[A-Z0-9]{4}[ ]?){3,7}[A-Z0-9]{1,4}\b/u', $text) as $raw) {
            $compact = strtoupper((string) preg_replace('/\s+/', '', $raw));
            if (strlen($compact) < 15 || strlen($compact) > 34) {
                continue;
            }
            $found[] = $this->entity('iban', $page, $raw, $compact, $this->validIban($compact));
        }

        foreach ($this->matches('/\b\d{8}[ -]?[A-Za-z]\b/u', $text) as $raw) {
            $compact = strtoupper((string) preg_replace('/[\s-]/', '', $raw));
            $found[] = $this->entity('nif', $page, $raw, $compact, $this->validNif($compact));
        }

        foreach ($this->matches('/\b[ABCDEFGHJNPQRSUVW][ -]?\d{7}[ -]?[0-9A-Ja-j]\b/u', $text) as $raw) {
            $compact = strtoupper((string) preg_replace('/[\s-]/', '', $raw));
            $found[] = $this->entity('cif', $page, $raw, $compact, null);
        }

        foreach ($this->matches('/\b[XYZxyz][ -]?\d{7}[ -]?[A-Za-z]\b/u', $text) as $raw) {
            $compact = strtoupper((string) preg_replace('/[\s-]/', '', $raw));
            $found[] = $this->entity('nie', $page, $raw, $compact, $this->validNie($compact));
        }

        foreach ($this->dates($text, $page) as $entity) {
            $found[] = $entity;
        }

        foreach ($this->amounts($text, $page) as $entity) {
            $found[] = $entity;
        }

        foreach ($this->matches('/\b\d{1,3}(?:[.,]\d{1,2})?\s?%/u', $text) as $raw) {
            $value = (float) str_replace(',', '.', (string) preg_replace('/[^\d.,]/', '', $raw));
            $found[] = $this->entity('percentage', $page, $raw, $value);
        }

        return $this->dedupe($found);
    }

    /** @return list<array<string,mixed>> */
    private function dates(string $text, int $page): array
    {
        $out = [];

        // 12/03/2025, 12-03-25, 12.03.2025
        foreach ($this->matches('/\b(\d{1,2})[\/.\-](\d{1,2})[\/.\-](\d{2,4})\b/u', $text) as $raw) {
            preg_match('/\b(\d{1,2})[\/.\-](\d{1,2})[\/.\-](\d{2,4})\b/u', $raw, $m);
            $out[] = $this->entity('date', $page, $raw, $this->isoDate((int) $m[3], (int) $m[2], (int) $m[1]));
        }

        // 2025-03-12
        foreach ($this->matches('/\b(\d{4})-(\d{2})-(\d{2})\b/u', $text) as $raw) {
            preg_match('/\b(\d{4})-(\d{2})-(\d{2})\b/u', $raw, $m);
            $out[] = $this->entity('date', $page, $raw, $this->isoDate((int) $m[1], (int) $m[2], (int) $m[3]));
        }

        // 12 de marzo de 2025
        $pattern = '/\b(\d{1,2})\s+de\s+(' . implode('|', array_keys(self::MONTHS_ES)) . ')\s+de\s+(\d{4})\b/iu';
        foreach ($this->matches($pattern, $text) as $raw) {
            preg_match($pattern, $raw, $m);
            $month = self::MONTHS_ES[mb_strtolower($m[2], 'UTF-8')] ?? 0;
            $out[] = $this->entity('date', $page, $raw, $this->isoDate((int) $m[3], $month, (int) $m[1]));
        }

        return $out;
    }

    /** @return list<array<string,mixed>> */
    private function amounts(string $text, int $page): array
    {
        $out = [];
        $pattern = '/(?<currency>€|EUR|USD|\$)?\s?(?<number>\d{1,3}(?:\.\d{3})*,\d{2}|\d+,\d{2}|\d{1,3}(?:,\d{3})+\.\d{2}|\d+\.\d{2})\s?(?<trailing>€|EUR|USD|\$)?/u';

        if (preg_match_all($pattern, $text, $matches, PREG_SET_ORDER) === false) {
            return $out;
        }

        foreach ($matches as $m) {
            $currency = $m['currency'] ?: ($m['trailing'] ?? '');
            if ($currency === '') {
                continue; // A bare decimal is not necessarily money.
            }

            $raw = trim($m[0]);
            $entity = $this->entity('amount', $page, $raw, $this->toFloat($m['number']));
            $entity['currency'] = match (strtoupper($currency)) {
                '€', 'EUR' => 'EUR',
                '$', 'USD' => 'USD',
                default    => strtoupper($currency),
            };
            $out[] = $entity;
        }

        return $out;
    }

    private function toFloat(string $number): float
    {
        // European: 1.234,56 -> 1234.56 | US: 1,234.56 -> 1234.56
        if (preg_match('/,\d{2}$/', $number) === 1) {
            $number = str_replace('.', '', $number);
            $number = str_replace(',', '.', $number);
        } else {
            $number = str_replace(',', '', $number);
        }

        return round((float) $number, 2);
    }

    private function isoDate(int $year, int $month, int $day): ?string
    {
        if ($year < 100) {
            $year += $year < 70 ? 2000 : 1900;
        }
        if (!checkdate($month, $day, $year)) {
            return null;
        }

        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }

    private function validNif(string $value): bool
    {
        if (preg_match('/^(\d{8})([A-Z])$/', $value, $m) !== 1) {
            return false;
        }

        return self::checkLetter((int) $m[1]) === $m[2];
    }

    private function validNie(string $value): bool
    {
        if (preg_match('/^([XYZ])(\d{7})([A-Z])$/', $value, $m) !== 1) {
            return false;
        }
        $prefix = ['X' => '0', 'Y' => '1', 'Z' => '2'][$m[1]];

        return self::checkLetter((int) ($prefix . $m[2])) === $m[3];
    }

    private static function checkLetter(int $number): string
    {
        return substr('TRWAGMYFPDXBNJZSQVHLCKE', $number % 23, 1);
    }

    private function validIban(string $iban): bool
    {
        $rearranged = substr($iban, 4) . substr($iban, 0, 4);
        $digits = '';
        foreach (str_split($rearranged) as $char) {
            $digits .= ctype_alpha($char) ? (string) (ord($char) - 55) : $char;
        }

        $remainder = 0;
        foreach (str_split($digits, 7) as $chunk) {
            $remainder = (int) (($remainder . $chunk) % 97);
        }

        return $remainder === 1;
    }

    /** @return list<string> */
    private function matches(string $pattern, string $text): array
    {
        if (preg_match_all($pattern, $text, $matches) === false) {
            return [];
        }

        return array_values(array_map('trim', $matches[0]));
    }

    /**
     * @return array{type:string,page:int,raw:string,value:string|float|null,valid:?bool}
     */
    private function entity(string $type, int $page, string $raw, string|float|null $value, ?bool $valid = null): array
    {
        return [
            'type'  => $type,
            'page'  => $page,
            'raw'   => $raw,
            'value' => $value,
            'valid' => $valid,
        ];
    }

    /**
     * @param list<array<string,mixed>> $entities
     *
     * @return list<array<string,mixed>>
     */
    private function dedupe(array $entities): array
    {
        $seen = [];
        $out = [];
        foreach ($entities as $entity) {
            $key = $entity['type'] . '|' . $entity['page'] . '|' . mb_strtolower((string) $entity['raw']);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $entity;
        }

        return $out;
    }
}
