<?php
declare(strict_types=1);

namespace App\Services\Csv;

final class CsvAnalyzer
{
    /** @return array{delimiter:string,headers:list<string>,rows:int,types:array<string,string>,candidates:array<string,bool>,errors:list<string>,warnings:list<string>} */
    public function analyze(string $path, ?string $chosenDelimiter = null): array
    {
        $delimiter = $chosenDelimiter ?: $this->detectDelimiter($path);
        if (!in_array($delimiter, [',', ';', "\t", '|'], true)) {
            return ['delimiter' => ',', 'headers' => [], 'rows' => 0, 'types' => [], 'candidates' => [], 'errors' => ['Delimitador no permitido.'], 'warnings' => []];
        }
        $handle = fopen($path, 'rb');
        if ($handle === false) throw new \RuntimeException('No se pudo abrir el CSV.');
        $errors = []; $warnings = []; $rows = 0;
        $header = fgetcsv($handle, 0, $delimiter, '"', '\\');
        if ($header === false || $header === [null]) {
            fclose($handle);
            return ['delimiter' => $delimiter, 'headers' => [], 'rows' => 0, 'types' => [], 'candidates' => [], 'errors' => ['El CSV no contiene una cabecera válida.'], 'warnings' => []];
        }
        $headers = array_map(static fn ($value): string => trim((string) $value), $header);
        $headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', $headers[0]) ?? $headers[0];
        foreach ($headers as $name) if (preg_match('//u', $name) !== 1) $errors[] = 'La cabecera no está codificada como UTF-8 válido.';
        if (in_array('', $headers, true)) $errors[] = 'La cabecera contiene un nombre de columna vacío.';
        if (count(array_unique($headers, SORT_STRING)) !== count($headers)) $errors[] = 'La cabecera contiene nombres de columna duplicados.';
        $samples = array_fill_keys($headers, []);
        $candidates = [];
        foreach ($headers as $name) if (preg_match('/^(id|uuid|codigo|c[oó]digo|code)$/iu', $name) === 1) $candidates[$name] = true;
        $uniquePath = null; $uniqueDb = null; $uniqueInsert = null;
        if ($candidates !== []) {
            $uniquePath = tempnam(sys_get_temp_dir(), 'csv-keys-');
            if ($uniquePath !== false) {
                $uniqueDb = new \PDO('sqlite:' . $uniquePath, null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
                $uniqueDb->exec('CREATE TABLE keys_seen (candidate TEXT NOT NULL, value_hash TEXT NOT NULL, PRIMARY KEY(candidate, value_hash))');
                $uniqueInsert = $uniqueDb->prepare('INSERT INTO keys_seen (candidate, value_hash) VALUES (?, ?)');
            }
        }
        while (($row = fgetcsv($handle, 0, $delimiter, '"', '\\')) !== false) {
            if ($row === [null]) continue;
            $rows++;
            if (count($row) !== count($headers)) {
                $errors[] = "La fila {$rows} tiene " . count($row) . ' campos; se esperaban ' . count($headers) . '.';
                if (count($errors) > 25) { $errors[] = 'Se han ocultado errores adicionales de estructura.'; break; }
                continue;
            }
            foreach ($row as $value) if (preg_match('//u', (string) $value) !== 1) { $errors[] = "La fila {$rows} contiene texto que no es UTF-8 válido."; break 2; }
            foreach ($headers as $index => $name) {
                if (count($samples[$name]) < 1500) $samples[$name][] = $row[$index];
            }
            if ($uniqueDb !== null) foreach (array_keys($candidates) as $candidate) {
                $index = array_search($candidate, $headers, true); $value = trim((string) $row[$index]);
                if ($value === '') { $candidates[$candidate] = false; continue; }
                try { $uniqueInsert->execute([$candidate, hash('sha256', $value)]); }
                catch (\PDOException) { $candidates[$candidate] = false; }
            }
        }
        if (!feof($handle)) $errors[] = 'No se pudo leer el CSV completo.';
        fclose($handle);
        $uniqueDb = null; if ($uniquePath !== null) @unlink($uniquePath);
        if ($rows === 0) $warnings[] = 'El CSV no contiene filas de datos.';
        $types = [];
        foreach ($samples as $name => $values) $types[$name] = TypeDetector::infer($values);
        return compact('delimiter', 'headers', 'rows', 'types', 'candidates', 'errors', 'warnings');
    }

    private function detectDelimiter(string $path): string
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) return ',';
        $line = fgets($handle, 65536) ?: '';
        fclose($handle);
        $line = preg_replace('/^\xEF\xBB\xBF/', '', $line) ?? $line;
        $best = ','; $bestCount = 0;
        foreach ([',', ';', "\t", '|'] as $delimiter) {
            $count = count(str_getcsv($line, $delimiter, '"', '\\'));
            if ($count > $bestCount) { $best = $delimiter; $bestCount = $count; }
        }
        return $best;
    }
}
