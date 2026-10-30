<?php

namespace App\Http\Support;

use App\Domain\Shared\Exceptions\ApiException;
use App\Domain\Shared\Masters\MasterStatus;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

/**
 * Bulk import of master/asset data from an .xlsx made from the downloadable template: a "Data"
 * sheet whose header row holds the column labels (or keys), plus instruction and dropdown-list
 * sheets. The import is all-or-nothing: every row is attempted so all errors are reported at
 * once, and nothing is saved if any row fails.
 */
final class SpreadsheetImport
{
    public const MAX_ROWS = 1000;

    /** Dropdown items read "CODE — Name"; only the code before the separator is used. */
    public const SEPARATOR = ' — ';

    private const DATA_SHEET = 'Data';

    private const LIST_SHEET = 'Daftar Pilihan';

    private const COLOR_REQUIRED = '1D4ED8';

    private const COLOR_OPTIONAL = '475569';

    private const COLOR_LIST_CELL = 'EFF6FF';

    private const COLOR_BORDER = 'CBD5E1';

    /** @return list<mixed> */
    public static function fileRules(): array
    {
        return ['required', 'file', 'extensions:xlsx', 'max:5120'];
    }

    /**
     * Rows of the uploaded file keyed by column, with their spreadsheet row number. Blank rows
     * are skipped; cell values are trimmed strings (dates as Y-m-d) or null when empty.
     *
     * @param  list<ImportColumn>  $columns
     * @return list<array{row: int, data: array<string, string|null>}>
     */
    public static function read(Request $request, array $columns): array
    {
        $request->validate(['file' => self::fileRules()]);
        /** @var UploadedFile $file */
        $file = $request->file('file');

        try {
            $reader = IOFactory::createReader('Xlsx');
            $reader->setReadEmptyCells(false);
            $book = $reader->load($file->getRealPath());
        } catch (Throwable) {
            throw ApiException::unprocessable('INVALID_FILE', 'File tidak dapat dibaca. Pastikan file berformat Excel (.xlsx).');
        }
        $sheet = $book->getSheetByName(self::DATA_SHEET) ?? $book->getSheet(0);

        $lastColumn = Coordinate::columnIndexFromString($sheet->getHighestDataColumn());
        $header = [];
        for ($c = 1; $c <= $lastColumn; $c++) {
            $header[$c] = self::cell($sheet->getCell([$c, 1]));
        }
        $positions = self::positions($header, $columns);

        $rows = [];
        for ($r = 2, $last = $sheet->getHighestDataRow(); $r <= $last; $r++) {
            $data = array_map(fn (int $c) => self::cell($sheet->getCell([$c, $r])), $positions);
            if (array_filter($data, fn ($v) => $v !== null) === []) {
                continue;
            }
            if (count($rows) >= self::MAX_ROWS) {
                throw ApiException::unprocessable('IMPORT_TOO_LARGE', 'Maksimal '.self::MAX_ROWS.' baris per import.');
            }
            $rows[] = ['row' => $r, 'data' => $data];
        }
        $book->disconnectWorksheets();

        if ($rows === []) {
            throw ApiException::unprocessable('IMPORT_EMPTY', 'File tidak berisi data. Isi data mulai baris ke-2 pada sheet "Data".');
        }

        return $rows;
    }

    /**
     * Imports each row inside its own savepoint, collecting row errors; throws IMPORT_FAILED
     * (details.errors = ["Baris n: …"]) and rolls everything back if any row failed.
     *
     * @param  list<array{row: int, data: array<string, string|null>}>  $rows
     * @param  callable(array<string, string|null>): mixed  $import
     * @return int number of imported rows
     */
    public static function run(array $rows, callable $import): int
    {
        return DB::transaction(function () use ($rows, $import) {
            $errors = [];
            foreach ($rows as ['row' => $number, 'data' => $data]) {
                try {
                    DB::transaction(fn () => $import($data));
                } catch (ApiException|ValidationException $e) {
                    $errors[] = "Baris {$number}: ".self::describe($e);
                }
            }
            if ($errors !== []) {
                throw ApiException::unprocessable('IMPORT_FAILED',
                    'Tidak ada data yang diimpor karena '.count($errors).' baris bermasalah. Perbaiki lalu upload ulang.', ['errors' => $errors]);
            }

            return count($rows);
        });
    }

    /**
     * The template: a "Data" sheet with styled headers, input hints and dropdowns, a
     * "Petunjuk" sheet explaining every column, and the dropdown items.
     *
     * @param  list<ImportColumn>  $columns
     * @param  array<string, list<string>>  $lists  dropdown key => items (shown as the list's heading on the list sheet)
     * @param  list<string>  $notes  extra instructions for this template
     */
    public static function template(string $filename, string $title, array $columns, array $lists = [], array $notes = []): BinaryFileResponse
    {
        $book = new Spreadsheet;
        $book->getProperties()->setCreator('Asset Tracker')->setTitle($title);
        $book->getDefaultStyle()->getFont()->setName('Calibri')->setSize(11);

        $data = $book->getActiveSheet()->setTitle(self::DATA_SHEET);
        $ranges = $lists === [] ? [] : self::listSheet($book->createSheet(), $lists);
        self::dataSheet($data, $columns, $ranges);
        self::instructionSheet($book->createSheet(1), $title, $columns, $notes);
        $book->setActiveSheetIndex(0);

        $path = tempnam(sys_get_temp_dir(), 'tpl');
        (new Xlsx($book))->save($path);
        $book->disconnectWorksheets();

        return response()->download($path, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend();
    }

    /**
     * Column labels as validation attribute names, so messages read "Nama Aset wajib diisi".
     *
     * @param  list<ImportColumn>  $columns
     * @return array<string, string>
     */
    public static function attributes(array $columns): array
    {
        return array_column(array_map(fn (ImportColumn $c) => [$c->key, $c->label], $columns), 1, 0);
    }

    /**
     * Indonesian messages for the rules used by imports (the app locale has no translations).
     *
     * @return array<string, string>
     */
    public static function messages(): array
    {
        return [
            'required' => ':attribute wajib diisi.',
            'string' => ':attribute harus berupa teks.',
            'max' => ':attribute maksimal :max karakter.',
            'max.numeric' => ':attribute maksimal :max.',
            'min.numeric' => ':attribute minimal :min.',
            'numeric' => ':attribute harus berupa angka (tanpa titik/koma pemisah ribuan).',
            'date_format' => ':attribute harus berupa tanggal dengan format YYYY-MM-DD.',
            'before_or_equal' => ':attribute tidak boleh melebihi hari ini.',
            'in' => ':attribute tidak valid; pilih dari daftar.',
            'regex' => ':attribute hanya boleh berisi huruf, angka, titik, garis bawah, dan tanda hubung.',
            'unique' => ':attribute ":input" sudah dipakai.',
        ];
    }

    /** Codes are stored uppercase (see MasterRequests::normalizeCode). */
    public static function code(?string $value): ?string
    {
        return $value === null ? null : strtoupper($value);
    }

    /**
     * Id of the record with this code (or dropdown item "CODE — Name") visible in the current
     * tenant; null for an empty value.
     *
     * @param  Builder<Model>  $query
     */
    public static function idByCode(Builder $query, ?string $value, string $label): ?string
    {
        if ($value === null) {
            return null;
        }
        $code = trim(explode(self::SEPARATOR, $value)[0]);
        $id = $query->where('code', strtoupper($code))->value('id');

        return $id ?? throw ApiException::unprocessable('INVALID_REFERENCE', "{$label} dengan kode '{$code}' tidak ditemukan.");
    }

    /**
     * Dropdown items "CODE — Name" of active records.
     *
     * @param  Builder<Model>  $query
     * @return list<string>
     */
    public static function codeList(Builder $query): array
    {
        return $query->where('status', MasterStatus::ACTIVE)->orderBy('code')->limit(5000)->get(['code', 'name'])
            ->map(fn (Model $m) => $m->getAttribute('code').self::SEPARATOR.$m->getAttribute('name'))->all();
    }

    /**
     * @param  list<ImportColumn>  $columns
     * @param  array<string, string>  $ranges  list key => absolute range on the list sheet
     */
    private static function dataSheet(Worksheet $sheet, array $columns, array $ranges): void
    {
        $lastRow = self::MAX_ROWS + 1;
        foreach ($columns as $i => $column) {
            $letter = Coordinate::stringFromColumnIndex($i + 1);
            $body = "{$letter}2:{$letter}{$lastRow}";

            $sheet->setCellValue("{$letter}1", $column->label.($column->required ? ' *' : ''));
            $sheet->getStyle("{$letter}1")->getFill()->setFillType(Fill::FILL_SOLID)
                ->getStartColor()->setRGB($column->required ? self::COLOR_REQUIRED : self::COLOR_OPTIONAL);
            $sheet->getColumnDimension($letter)->setWidth($column->width);

            $validation = self::validation($column, $ranges[$column->list] ?? null);
            if ($validation !== null) {
                $sheet->setDataValidation($body, $validation);
            }
            if ($column->list !== null && isset($ranges[$column->list])) {
                $sheet->getStyle($body)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(self::COLOR_LIST_CELL);
            }
            match ($column->type) {
                ImportColumn::DATE => $sheet->getStyle($body)->getNumberFormat()->setFormatCode('yyyy-mm-dd'),
                ImportColumn::NUMBER => $sheet->getStyle($body)->getNumberFormat()->setFormatCode('#,##0'),
                default => $sheet->getStyle($body)->getNumberFormat()->setFormatCode('@'),
            };
        }

        $lastColumn = Coordinate::stringFromColumnIndex(count($columns));
        $sheet->getStyle("A1:{$lastColumn}1")->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
        ]);
        $sheet->getStyle("A1:{$lastColumn}{$lastRow}")->getBorders()->getAllBorders()
            ->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB(self::COLOR_BORDER);
        $sheet->getRowDimension(1)->setRowHeight(32);
        $sheet->freezePane('A2');
        $sheet->setSelectedCell('A2');
    }

    private static function validation(ImportColumn $column, ?string $range): ?DataValidation
    {
        $validation = (new DataValidation)
            ->setAllowBlank(! $column->required)
            ->setShowInputMessage(true)
            ->setPromptTitle(mb_substr($column->label, 0, 32))
            ->setPrompt(mb_substr($column->hint, 0, 255))
            ->setShowErrorMessage(true)
            ->setErrorStyle(DataValidation::STYLE_STOP)
            ->setErrorTitle('Nilai tidak valid');

        if ($column->list !== null && $range !== null) {
            return $validation->setType(DataValidation::TYPE_LIST)->setShowDropDown(true)->setFormula1($range)
                ->setErrorStyle($column->strictList ? DataValidation::STYLE_STOP : DataValidation::STYLE_INFORMATION)
                ->setError($column->strictList ? 'Pilih salah satu nilai dari daftar.' : 'Nilai tidak ada di daftar. Pastikan nilai ini ada di baris sebelumnya.');
        }

        return match ($column->type) {
            ImportColumn::DATE => $validation->setType(DataValidation::TYPE_DATE)->setOperator(DataValidation::OPERATOR_BETWEEN)
                ->setFormula1('DATE(2000,1,1)')->setFormula2('TODAY()')
                ->setError('Isi tanggal antara 2000-01-01 dan hari ini, format YYYY-MM-DD.'),
            ImportColumn::NUMBER => $validation->setType(DataValidation::TYPE_DECIMAL)->setOperator(DataValidation::OPERATOR_GREATERTHANOREQUAL)
                ->setFormula1('0')->setError('Isi angka 0 atau lebih, tanpa titik/koma pemisah ribuan.'),
            // Text columns only get the input hint.
            default => $column->hint === '' ? null : $validation->setShowErrorMessage(false),
        };
    }

    /**
     * Writes each dropdown list in its own column.
     *
     * @param  array<string, list<string>>  $lists
     * @return array<string, string> list key => absolute range, for data validation
     */
    private static function listSheet(Worksheet $sheet, array $lists): array
    {
        $sheet->setTitle(self::LIST_SHEET);
        $ranges = [];
        $c = 1;
        foreach ($lists as $name => $items) {
            $letter = Coordinate::stringFromColumnIndex($c++);
            $sheet->setCellValue("{$letter}1", $name);
            $sheet->getColumnDimension($letter)->setWidth(40);
            foreach ($items as $r => $item) {
                $sheet->setCellValueExplicit($letter.($r + 2), $item, DataType::TYPE_STRING);
            }
            if ($items !== []) {
                $ranges[$name] = "'".self::LIST_SHEET."'!\${$letter}\$2:\${$letter}\$".(count($items) + 1);
            }
        }
        $last = Coordinate::stringFromColumnIndex(count($lists));
        $sheet->getStyle("A1:{$last}1")->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => self::COLOR_OPTIONAL]],
        ]);
        $sheet->freezePane('A2');

        return $ranges;
    }

    /**
     * @param  list<ImportColumn>  $columns
     * @param  list<string>  $notes
     */
    private static function instructionSheet(Worksheet $sheet, string $title, array $columns, array $notes): void
    {
        $sheet->setTitle('Petunjuk');
        $sheet->setShowGridlines(false);
        $sheet->getColumnDimension('A')->setWidth(28);
        $sheet->getColumnDimension('B')->setWidth(10);
        $sheet->getColumnDimension('C')->setWidth(90);

        $sheet->setCellValue('A1', $title);
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(16)->getColor()->setRGB(self::COLOR_REQUIRED);

        $steps = [
            'Isi data pada sheet "Data", mulai baris ke-2. Satu baris = satu data.',
            'Jangan mengubah, menghapus, atau memindah baris judul (baris 1).',
            'Kolom berwarna biru dan bertanda * wajib diisi; kolom abu-abu boleh dikosongkan.',
            'Sel berlatar biru muda memiliki dropdown: klik sel lalu pilih dari daftar (sumbernya ada di sheet "'.self::LIST_SHEET.'").',
            'Klik sebuah sel untuk melihat petunjuk singkat kolom tersebut.',
            ...$notes,
            'Jika ada baris yang salah, tidak ada data yang disimpan. Perbaiki baris yang disebutkan lalu upload ulang file yang sama.',
            'Maksimal '.self::MAX_ROWS.' baris dan ukuran file 5MB per import.',
        ];
        $row = 3;
        $sheet->setCellValue("A{$row}", 'Cara pengisian');
        $sheet->getStyle("A{$row}")->getFont()->setBold(true)->setSize(12);
        foreach ($steps as $i => $step) {
            $row++;
            $sheet->setCellValue("A{$row}", ($i + 1).'. '.$step);
            $sheet->mergeCells("A{$row}:C{$row}");
        }

        $row += 2;
        $sheet->setCellValue("A{$row}", 'Penjelasan kolom');
        $sheet->getStyle("A{$row}")->getFont()->setBold(true)->setSize(12);
        $row++;
        $headerRow = $row;
        $sheet->fromArray(['Kolom', 'Wajib', 'Keterangan'], null, "A{$row}");
        foreach ($columns as $column) {
            $row++;
            $sheet->fromArray([$column->label, $column->required ? 'Ya' : 'Tidak', $column->hint], null, "A{$row}");
            if ($column->required) {
                $sheet->getStyle("A{$row}:B{$row}")->getFont()->setBold(true);
            }
        }
        $sheet->getStyle("A{$headerRow}:C{$headerRow}")->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => self::COLOR_REQUIRED]],
        ]);
        $sheet->getStyle("A{$headerRow}:C{$row}")->applyFromArray([
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => self::COLOR_BORDER]]],
            'alignment' => ['vertical' => Alignment::VERTICAL_TOP, 'wrapText' => true],
        ]);
        $sheet->getStyle("B{$headerRow}:B{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    }

    private static function cell(Cell $cell): ?string
    {
        try {
            $value = $cell->getCalculatedValue();
        } catch (Throwable) {
            $value = $cell->getValue();
        }
        if ((is_int($value) || is_float($value)) && Date::isDateTime($cell)) {
            return Date::excelToDateTimeObject($value)->format('Y-m-d');
        }
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d');
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        // Whole numbers read back as floats (e.g. 15000000.0) are written without the fraction.
        if (is_float($value) && floor($value) === $value && abs($value) < 1e15) {
            return (string) (int) $value;
        }
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /**
     * Index of each expected column in the header row, matched by label (with or without the
     * required mark) or key, in any order; extra columns are ignored.
     *
     * @param  array<int, string|null>  $header
     * @param  list<ImportColumn>  $columns
     * @return array<string, int>
     */
    private static function positions(array $header, array $columns): array
    {
        $normalize = fn (?string $h) => str_replace(' ', '_', strtolower(trim(rtrim((string) $h, " *"))));
        $keys = array_map($normalize, $header);
        $positions = [];
        foreach ($columns as $column) {
            $index = array_search($normalize($column->label), $keys, true);
            if ($index === false) {
                $index = array_search($column->key, $keys, true);
            }
            if ($index !== false) {
                $positions[$column->key] = $index;
            }
        }
        $missing = array_filter($columns, fn (ImportColumn $c) => ! isset($positions[$c->key]));
        if ($missing !== []) {
            throw ApiException::unprocessable('INVALID_TEMPLATE', 'Format file tidak sesuai template. Kolom tidak ditemukan: '
                .implode(', ', array_map(fn (ImportColumn $c) => $c->label, $missing)).'. Gunakan template terbaru.');
        }

        return $positions;
    }

    private static function describe(ApiException|ValidationException $e): string
    {
        if ($e instanceof ValidationException) {
            // The first message per column is enough (an invalid date also fails "before today").
            return implode(' ', array_map(fn (array $messages) => $messages[0], $e->errors()));
        }
        $fields = Arr::flatten($e->details['fields'] ?? []);

        return $fields === [] ? $e->getMessage() : implode(' ', $fields);
    }
}
