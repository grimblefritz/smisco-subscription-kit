<?php

declare(strict_types=1);

namespace Smisco\SubscriptionKit\Tests\Support;

use Smisco\SubscriptionKit\AuditLogger;

final class RecordingAuditLogger implements AuditLogger
{
    /**
     * @var list<array{
     *     adminUserId: int,
     *     action: string,
     *     targetTable: ?string,
     *     targetId: ?int,
     *     from: ?array,
     *     to: ?array,
     *     note: ?string,
     * }>
     */
    public array $rows = [];

    public function log(
        int $adminUserId,
        string $action,
        ?string $targetTable,
        ?int $targetId,
        ?array $from,
        ?array $to,
        ?string $note
    ): void {
        $this->rows[] = [
            'adminUserId' => $adminUserId,
            'action'      => $action,
            'targetTable' => $targetTable,
            'targetId'    => $targetId,
            'from'        => $from,
            'to'          => $to,
            'note'        => $note,
        ];
    }
}
