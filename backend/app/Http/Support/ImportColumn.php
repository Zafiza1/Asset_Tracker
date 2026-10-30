<?php

namespace App\Http\Support;

/** One column of a spreadsheet import template (see SpreadsheetImport). */
final readonly class ImportColumn
{
    public const TEXT = 'text';

    public const DATE = 'date';

    public const NUMBER = 'number';

    /**
     * @param  string|null  $list  key of a dropdown list passed to SpreadsheetImport::template()
     * @param  bool  $strictList  false: values outside the list are allowed (with a warning), e.g. a parent on an earlier row
     */
    public function __construct(
        public string $key,
        public string $label,
        public bool $required = false,
        public string $hint = '',
        public ?string $list = null,
        public bool $strictList = true,
        public string $type = self::TEXT,
        public int $width = 24,
    ) {}
}
