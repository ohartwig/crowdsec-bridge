<?php

declare(strict_types=1);

namespace Koh\CrowdsecBridge\Domain\ValueObject;

enum DecisionWriteAction: string
{
    case Add = 'add';
    case Delete = 'delete';
    case WriteDenied = 'write_denied';
    case DeleteDenied = 'delete_denied';
    case CsrfDenied = 'csrf_denied';
    case DuplicateSubmit = 'duplicate_submit';

    public function toSysLogType(): string
    {
        return match ($this) {
            self::Add => 'crowdsec_bridge:decision_add',
            self::Delete => 'crowdsec_bridge:decision_delete',
            self::WriteDenied => 'crowdsec_bridge:write_denied',
            self::DeleteDenied => 'crowdsec_bridge:delete_denied',
            self::CsrfDenied => 'crowdsec_bridge:csrf_denied',
            self::DuplicateSubmit => 'crowdsec_bridge:duplicate_submit',
        };
    }
}
