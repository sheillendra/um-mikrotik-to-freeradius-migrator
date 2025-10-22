<?php

declare(strict_types=1);

namespace Tests\Support\Helper;

// here you can define custom actions
// all public methods declared in helper class will be available in $I

class Acceptance extends \Codeception\Module
{
    /**
     * Konversi string seperti "12h35m52s" atau "35m52s" menjadi detik (int)
     */
    public function stringToSeconds(string $timeString): int
    {
        $pattern = '/(?:(\d+)h)?(?:(\d+)m)?(?:(\d+)s)?/i';
        preg_match($pattern, strtolower(trim($timeString)), $matches);

        $hours   = isset($matches[1]) && $matches[1] !== '' ? (int) $matches[1] : 0;
        $minutes = isset($matches[2]) && $matches[2] !== '' ? (int) $matches[2] : 0;
        $seconds = isset($matches[3]) && $matches[3] !== '' ? (int) $matches[3] : 0;

        return ($hours * 3600) + ($minutes * 60) + $seconds;
    }

    /**
     * Convert string seperti "3.3 GiB", "12.8 MiB", "1970.0 KiB" ke bytes.
     */
    public function convertSizeToBytes(string $sizeString): int
    {
        // Pisahkan angka dan satuan
        if (!preg_match('/([\d\.]+)\s*([KMGTP]?i?B)/i', trim($sizeString), $matches)) {
            throw new \InvalidArgumentException("Format ukuran tidak valid: $sizeString");
        }

        $value = (float) $matches[1];
        $unit = strtoupper($matches[2]);

        // Konversi berdasarkan satuan
        switch ($unit) {
            case 'B':
                $bytes = $value;
                break;
            case 'KIB':
            case 'KB':
                $bytes = $value * 1024;
                break;
            case 'MIB':
            case 'MB':
                $bytes = $value * 1024 ** 2;
                break;
            case 'GIB':
            case 'GB':
                $bytes = $value * 1024 ** 3;
                break;
            case 'TIB':
            case 'TB':
                $bytes = $value * 1024 ** 4;
                break;
            case 'PIB':
            case 'PB':
                $bytes = $value * 1024 ** 5;
                break;
            default:
                throw new \InvalidArgumentException("Satuan tidak dikenali: $unit");
        }

        return (int) $bytes;
    }
}
