<?php

namespace App\Services\FileSecurity;

use Illuminate\Validation\ValidationException;

/** Seek-based OLE metadata admission before the installed Office parser. */
class OleContainerInspector
{
    public function inspect(string $path, string $extension): void
    {
        $stream = fopen($path, 'rb');
        if (! is_resource($stream)) {
            $this->reject();
        }
        try {
            $header = $this->read($stream, 0, 512);
            $shift = unpack('v', substr($header, 30, 2))[1];
            if (substr($header, 0, 8) !== "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1"
                || substr($header, 28, 2) !== "\xFE\xFF" || ! in_array($shift, [9, 12], true)) {
                $this->reject();
            }
            $sectorBytes = 1 << $shift;
            $size = filesize($path);
            if ($size % $sectorBytes !== 0) {
                $this->reject();
            }
            $sectors = intdiv($size, $sectorBytes) - 1;
            $fatCount = $this->uint($header, 44);
            if ($sectors < 1 || $fatCount < 1 || $fatCount > $sectors) {
                $this->reject();
            }
            $fat = [];
            foreach (unpack('V*', substr($header, 76, 436)) as $sector) {
                if ($sector !== 0xFFFFFFFF) {
                    $fat[] = $sector;
                }
            }
            $difat = $this->uint($header, 68);
            $difatCount = $this->uint($header, 72);
            $visited = [];
            for ($index = 0; $index < $difatCount; $index++) {
                if ($difat >= $sectors || isset($visited[$difat]) || $index >= $sectors) {
                    $this->reject();
                }
                $visited[$difat] = true;
                $block = $this->read($stream, ($difat + 1) * $sectorBytes, $sectorBytes);
                foreach (unpack('V*', substr($block, 0, -4)) as $sector) {
                    if ($sector !== 0xFFFFFFFF) {
                        $fat[] = $sector;
                    }
                }
                $difat = $this->uint($block, $sectorBytes - 4);
            }
            if (count($fat) !== $fatCount || count(array_unique($fat)) !== $fatCount) {
                $this->reject();
            }
            foreach ($fat as $sector) {
                if ($sector >= $sectors) {
                    $this->reject();
                }
            }
            $directory = $this->uint($header, 48);
            $visited = [];
            $found = false;
            $workbook = null;
            $root = null;
            while ($directory !== 0xFFFFFFFE) {
                if ($directory >= $sectors || isset($visited[$directory]) || count($visited) >= $sectors) {
                    $this->reject();
                }
                $visited[$directory] = true;
                $block = $this->read($stream, ($directory + 1) * $sectorBytes, $sectorBytes);
                for ($offset = 0; $offset < $sectorBytes; $offset += 128) {
                    $entry = substr($block, $offset, 128);
                    if (ord($entry[66]) === 5) {
                        if ($this->uint($entry, 124) !== 0) {
                            $this->reject();
                        }
                        $root = ['start' => $this->uint($entry, 116), 'size' => $this->uint($entry, 120)];
                    }
                    if (ord($entry[66]) !== 2) {
                        continue;
                    }
                    $length = unpack('v', substr($entry, 64, 2))[1];
                    if ($length < 2 || $length > 64 || $length % 2 !== 0) {
                        $this->reject();
                    }
                    $name = mb_convert_encoding(substr($entry, 0, $length - 2), 'UTF-8', 'UTF-16LE');
                    if (in_array($name, $extension === 'xls' ? ['Workbook', 'Book'] : ['WordDocument'], true)) {
                        if ($this->uint($entry, 124) !== 0 || $this->uint($entry, 120) > $size || $this->uint($entry, 120) < 1) {
                            $this->reject();
                        }
                        $found = true;
                        $workbook = ['start' => $this->uint($entry, 116), 'size' => $this->uint($entry, 120)];
                    }
                }
                $fatIndex = intdiv($directory * 4, $sectorBytes);
                if (! isset($fat[$fatIndex])) {
                    $this->reject();
                }
                $directory = $this->uint($this->read($stream, ($fat[$fatIndex] + 1) * $sectorBytes + ($directory * 4 % $sectorBytes), 4), 0);
            }
            if (! $found) {
                $this->reject();
            }
            if ($workbook['size'] >= 4096) {
                $this->chain($stream, $workbook['start'], (int) ceil($workbook['size'] / $sectorBytes), $fat, $sectorBytes, $sectors);
            } else {
                if (! $root || $root['size'] > $size) {
                    $this->reject();
                }
                $this->chain($stream, $root['start'], (int) ceil($root['size'] / $sectorBytes), $fat, $sectorBytes, $sectors);
                $miniCount = $this->uint($header, 64);
                if ($miniCount < 1 || $miniCount > $sectors) {
                    $this->reject();
                }
                $miniFat = $this->chain($stream, $this->uint($header, 60), $miniCount, $fat, $sectorBytes, $sectors);
                $this->chain($stream, $workbook['start'], (int) ceil($workbook['size'] / 64), $miniFat, $sectorBytes, (int) ceil($root['size'] / 64));
            }
        } finally {
            fclose($stream);
        }
    }

    private function chain($stream, int $sector, int $expected, array $fat, int $sectorBytes, int $available): array
    {
        if ($expected < 1 || $expected > $available) {
            $this->reject();
        }
        $chain = [];
        $seen = [];
        for ($index = 0; $index < $expected; $index++) {
            if ($sector >= $available || isset($seen[$sector])) {
                $this->reject();
            }
            $seen[$sector] = true;
            $chain[] = $sector;
            $table = intdiv($sector * 4, $sectorBytes);
            if (! isset($fat[$table])) {
                $this->reject();
            }
            $sector = $this->uint($this->read($stream, ($fat[$table] + 1) * $sectorBytes + $sector * 4 % $sectorBytes, 4), 0);
        }
        if ($sector !== 0xFFFFFFFE) {
            $this->reject();
        }

        return $chain;
    }

    private function read($stream, int $offset, int $length): string
    {
        if (fseek($stream, $offset) !== 0) {
            $this->reject();
        }
        $bytes = fread($stream, $length);
        if (! is_string($bytes) || strlen($bytes) !== $length) {
            $this->reject();
        }

        return $bytes;
    }

    private function uint(string $bytes, int $offset): int
    {
        return unpack('V', substr($bytes, $offset, 4))[1];
    }

    private function reject(): never
    {
        throw ValidationException::withMessages(['file' => __('file_security.errors.office')]);
    }
}
