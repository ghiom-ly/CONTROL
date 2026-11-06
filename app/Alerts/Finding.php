<?php

namespace App\Alerts;

/** حالة واحدة تستحق تنبيهاً — fingerprint يميّزها حتى لا تتكرر */
final class Finding
{
    public function __construct(
        public readonly string $fingerprint,
        public readonly string $title,
        public readonly ?string $details = null,
        public readonly ?string $subjectType = null,
        public readonly ?int $subjectId = null,
        public readonly ?string $subjectLabel = null,
    ) {}
}
