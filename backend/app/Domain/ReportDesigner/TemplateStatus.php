<?php

namespace App\Domain\ReportDesigner;

/**
 * Lifecycle of a report template. DRAFT and PUBLISHED templates keep a working draft
 * version; ARCHIVED templates cannot be used for new exports (they can be published
 * again, which returns them to PUBLISHED).
 *
 * Version rows reuse the same codes: a version is 'draft' while editable and 'published'
 * once frozen (TemplateStatus::DRAFT / TemplateStatus::PUBLISHED).
 */
final class TemplateStatus
{
    public const DRAFT = 'draft';

    public const PUBLISHED = 'published';

    public const ARCHIVED = 'archived';

    public const ALL = [self::DRAFT, self::PUBLISHED, self::ARCHIVED];
}
