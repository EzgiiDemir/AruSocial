<?php

namespace App\Filament\Resources\RoleGrants\Pages;

use App\Filament\Resources\RoleGrants\RoleGrantResource;
use App\Services\AuditLogger;
use App\Services\GranularPermissions;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CreateRoleGrant extends CreateRecord
{
    protected static string $resource = RoleGrantResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        if (($data['role'] ?? null) === GranularPermissions::SUPER_ROLE
            && ! GranularPermissions::legacyAllows(auth()->user(), 'users.manage')) {
            throw ValidationException::withMessages(['role' => __('panel.roles.cannot_assign_super')]);
        }
        $data['id'] = (string) Str::uuid();
        $data['assigned_by'] = auth()->user()?->email ?? 'system';

        return $data;
    }

    protected function afterCreate(): void
    {
        AuditLogger::logAsCurrentUser('create', 'role_grant', $this->record->user?->email.' / '.$this->record->role);
    }
}
