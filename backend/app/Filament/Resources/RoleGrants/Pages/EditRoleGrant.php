<?php

namespace App\Filament\Resources\RoleGrants\Pages;

use App\Filament\Resources\RoleGrants\RoleGrantResource;
use App\Services\AuditLogger;
use App\Services\GranularPermissions;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Validation\ValidationException;

class EditRoleGrant extends EditRecord
{
    protected static string $resource = RoleGrantResource::class;

    protected function getHeaderActions(): array
    {
        return [ViewAction::make(), DeleteAction::make()];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        if (($data['role'] ?? null) === GranularPermissions::SUPER_ROLE
            && ! GranularPermissions::legacyAllows(auth()->user(), 'users.manage')) {
            throw ValidationException::withMessages(['role' => __('panel.roles.cannot_assign_super')]);
        }

        return $data;
    }

    protected function afterSave(): void
    {
        AuditLogger::logAsCurrentUser('update', 'role_grant', $this->record->user?->email.' / '.$this->record->role);
    }
}
