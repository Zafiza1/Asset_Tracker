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
     * @param  bool  $readOnly  shown for reference in an edit sheet (greyed out); not read back on upload
     * @param  bool  $hidden  technical column of an edit sheet (e.g. a version); read back when present, never required
     * @param  bool  $readBack  with $readOnly: still greyed out and never saved, but read back on upload so it can be
     *                          checked (e.g. an asset's last-changed time, to reject rows edited by someone else)
     * @param  bool  $identifier  visible key of an edit sheet (e.g. the asset number): greyed out like a read-only
     *                            column, but read back to find each row's record (see SpreadsheetImport::existing())
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
        public bool $readOnly = false,
        public bool $hidden = false,
        public bool $identifier = false,
        public bool $readBack = false,
    ) {}
}
