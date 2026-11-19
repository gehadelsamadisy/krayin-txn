<?php

namespace Webkul\Admin\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Webkul\Admin\Support\Automation\MessageScheduler;

/**
 * Fires one scheduled follow-up (txn_scheduled_messages row) at its exact
 * time. Dispatched delayed to due_at on the "database" connection, queue
 * "txn-schedule" (krayin-queue worker). Carries the due time it was made
 * for: a row cancelled, sent or moved since then makes this job do nothing.
 */
class SendScheduledMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public function __construct(public int $rowId, public string $dueAt) {}

    public function handle(MessageScheduler $scheduler): void
    {
        $scheduler->fire($this->rowId, $this->dueAt);
    }
}
