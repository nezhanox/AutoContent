<?php

namespace App\Filament\Resources\Publications\Concerns;

use App\Models\Enums\PublicationStatus;
use Illuminate\Support\Carbon;

trait AppliesScheduledStatus
{
    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function applyScheduledStatus(array $data, ?PublicationStatus $currentStatus): array
    {
        // Once a publication has moved past Draft/Scheduled (Publishing, Published,
        // Failed), the pipeline owns its status — editing unrelated fields must not
        // silently reset it back to Draft/Scheduled.
        if ($currentStatus !== null && ! in_array($currentStatus, [PublicationStatus::Draft, PublicationStatus::Scheduled], true)) {
            return $data;
        }

        $data['status'] = (! empty($data['scheduled_at']) && Carbon::parse($data['scheduled_at'])->isFuture())
            ? PublicationStatus::Scheduled
            : PublicationStatus::Draft;

        return $data;
    }
}
