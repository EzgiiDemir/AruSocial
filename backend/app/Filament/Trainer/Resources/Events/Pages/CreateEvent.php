<?php

namespace App\Filament\Trainer\Resources\Events\Pages;

use App\Filament\Concerns\MintsPrefixedId;
use App\Filament\Trainer\Resources\Events\EventResource;
use App\Services\AuditLogger;
use Filament\Resources\Pages\CreateRecord;

class CreateEvent extends CreateRecord
{
    use MintsPrefixedId;

    protected static string $resource = EventResource::class;

    protected function idPrefix(): string
    {
        return 'event';
    }

    /**
     * Ownership is stamped from the signed-in staff profile, never from the
     * form. A trainer who could choose the responsible staff member could
     * file an event under somebody else's department, which is exactly what
     * the scoping exists to prevent.
     *
     * New events also start in review rather than published, mirroring the
     * JSON API: a department head submits, an admin approves.
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data = $this->mintId($data);

        $data['responsible_staff_id'] = EventResource::staffProfile()?->id;
        $data['workflow_status'] = 'pending';

        return $data;
    }

    protected function afterCreate(): void
    {
        AuditLogger::logAsCurrentUser('create', 'event', $this->record->title);
    }
}
