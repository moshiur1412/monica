<?php

namespace App\Domains\Contact\Import\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

class CsvParser
{
    public function parse(string $filePath): Collection
    {
        $content = Storage::disk('local')->get($filePath);

        if ($content === null) {
            throw new \RuntimeException("Cannot read file: {$filePath}");
        }

        return $this->parseContent($content);
    }

    public function countRows(string $filePath): int
    {
        return $this->parse($filePath)->count();
    }

    public function parseContent(string $content): Collection
    {
        $rows = collect();
        $lines = explode("\n", str_replace(["\r\n", "\r"], "\n", $content));
        $lines = array_filter($lines, fn ($line) => trim($line) !== '');

        if (empty($lines)) {
            throw new \RuntimeException('Empty CSV file');
        }

        $headers = str_getcsv(array_shift($lines));
        $headers = array_map('trim', $headers);

        foreach ($lines as $line) {
            $row = str_getcsv($line);
            $data = [];
            foreach ($headers as $index => $header) {
                $data[$header] = isset($row[$index]) ? trim($row[$index]) : '';
            }
            $rows->push($data);
        }

        return $rows;
    }

    public function normalizeRow(array $row): array
    {
        if (! empty($row['name'])) {
            $parts = explode(' ', $row['name'], 2);
            $row['first_name'] = $parts[0];
            $row['last_name'] = $parts[1] ?? '';
        }

        return [
            'first_name' => $row['first_name'] ?? '',
            'last_name' => $row['last_name'] ?? '',
            'email' => $row['email'] ?? '',
            'phone' => $row['phone'] ?? '',
        ];
    }

    public function validateRow(array $row): array
    {
        $errors = [];

        if (empty($row['first_name']) && empty($row['name'])) {
            $errors[] = 'Missing required field: name';
        }

        if (! empty($row['email']) && ! filter_var($row['email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = "Invalid email: '{$row['email']}'";
        }

        return $errors;
    }
}
