<?php

namespace App\Domain\ReportDesigner;

/** Lifecycle of a report export job (report_exports.status). */
final class ExportStatus
{
    public const PENDING = 'pending';

    public const PROCESSING = 'processing';

    public const COMPLETED = 'completed';

    public const FAILED = 'failed';

    public const EXPIRED = 'expired';

    public const ALL = [self::PENDING, self::PROCESSING, self::COMPLETED, self::FAILED, self::EXPIRED];
}
