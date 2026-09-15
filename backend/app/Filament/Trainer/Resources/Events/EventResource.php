<?php

namespace App\Filament\Trainer\Resources\Events;

use App\Filament\Resources\Events\Schemas\EventForm;
use App\Filament\Trainer\Concerns\ScopedToOwnDepartment;
use App\Filament\Trainer\Resources\Events\Pages\CreateEvent;
use App\Filament\Trainer\Resources\Events\Pages\EditEvent;
use App\Filament\Trainer\Resources\Events\Pages\ListEvents;
use App\Filament\Trainer\Resources\Events\Tables\EventsTable;
use App\Models\Event;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * A department head's own events.
 *
 * The form is literally the admin panel's form — imported, not copied. The
 * brief is that the two panels differ in what you may do, not in how the
 * screen looks, and two copies of an event form would answer that promise
 * with "for now".
 *
 * What differs is the query: `ScopedToOwnDepartment` restricts every read,
 * every route binding and every action to rows this staff member owns.
 */
class EventResource extends Resource
{
    use ScopedToOwnDepartment;

    protected static ?string $model = Event::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static string|UnitEnum|null $navigationGroup = 'panel.groups.campus';

    protected static ?string $recordTitleAttribute = 'title';

    protected static ?int $navigationSort = 5;

    public static function ownerColumn(): string
    {
        return 'responsible_staff_id';
    }

    public static function form(Schema $schema): Schema
    {
        return EventForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return EventsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListEvents::route('/'),
            'create' => CreateEvent::route('/create'),
            'edit' => EditEvent::route('/{record}/edit'),
        ];
    }
}
