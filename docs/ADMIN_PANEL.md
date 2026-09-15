# The Admin and Trainer panels

Two Filament panels, deliberately separate:

| | Path | Who | Resources discovered from |
|---|---|---|---|
| Admin | `/admin` | `viewAdmin` | `app/Filament/Resources` |
| Trainer | `/trainer` | `manageOwnDepartment` | `app/Filament/Trainer/Resources` |

They are separate panels rather than one panel with hidden sections, so a
trainer's session never reaches an admin URL. A resource that forgets its own
authorisation check is still not exposed to them, because it is not in their
panel's discovery path.

Who may open which panel is decided in one place — `User::canAccessPanel()`,
which defers to `GranularPermissions`, the same service the JSON API uses.
There is no second rule written for the panel.

## Adding a content resource

Campus content resources share their behaviour through
`App\Filament\Concerns\ManagesCampusContent`. A resource using it declares one
thing and gets the rest:

```php
class SportResource extends Resource
{
    use ManagesCampusContent;

    protected static ?string $model = Sport::class;

    public static function permissionKey(): string
    {
        return 'sports.manage';
    }
}
```

That supplies:

- **Authorisation** — view, create, edit, delete, restore and purge all resolve
  through `GranularPermissions::allows()` against the declared key, so a role
  change is made once and the panel follows.
- **Soft delete and restore** — the trashed filter, the row-level Restore and
  the "delete permanently" action, plus route binding that can resolve a
  deleted record (without it, Restore links to a 404).
- **Bulk actions** — delete, restore, purge and CSV/XLSX export.
- **Audit logging** — every delete, restore and purge is written to
  `admin_audit_log` through `AuditLogger`, attributed to the signed-in account.

Wire the three helpers into the table:

```php
->filters([...own filters, ...SportResource::contentFilters()])
->recordActions(SportResource::contentRecordActions())
->toolbarActions(SportResource::contentToolbarActions())
```

The Create page uses `MintsPrefixedId`, because the campus tables have
non-incrementing string keys that the database will not generate:

```php
class CreateSport extends CreateRecord
{
    use MintsPrefixedId;

    protected function idPrefix(): string
    {
        return 'sport';
    }
}
```

### Two things that bite

**`NOT NULL DEFAULT ''` columns.** A column default only applies when the
column is left out of the INSERT. An empty Filament field sends an explicit
`null`, which the constraint rejects. Those fields need:

```php
->dehydrateStateUsing(fn (?string $state): string => $state ?? '')
```

**Derived columns.** `places.photos` is an integer count and `places.rating` is
the average of student reviews. Neither belongs in a form — a field over a
derived value is a way to write a number that disagrees with the thing it
describes.

### No infolist

Resources deliberately do not declare `infolist()`. The View page then falls
back to the form rendered read-only — one definition instead of two that can
disagree about which fields a record has.

## Soft deletes: what is and is not covered

`2026_09_14_120000_add_soft_deletes_to_managed_content` adds `deleted_at` to
the twenty tables the panels manage. Three exclusions are on purpose:

- **Ledger tables** (audit log, moderation events, strikes, revisions) stay
  hard-deletable or immutable. A row you can hide is not a record.
- **Junction and per-user rows** (likes, follows, views, tokens) are excluded.
  Nobody restores a like, and `deleted_at` there costs an index on hot reads.
- **`users`.** Deleting an account is supposed to erase the person's posts,
  stories, club memberships and onboarding progress, and that erasure is done
  by `ON DELETE CASCADE`. A soft delete never issues a DELETE, so none of those
  cascades would fire: the account would vanish from the admin listing while
  the student's content stayed on the feed. Reversible lockout is what the
  ban/enforcement path is for. `SoftDeleteRestoreTest` holds this line.

A soft-deleted parent keeps its children — a club keeps its members, a venue
keeps its menus — so that restoring gives back something that works rather
than an empty shell. What has to hold instead is that nothing reaches them
while the parent is deleted, which means listings must scope through the
parent. `ClubMemberController::index()` is the worked example.

## Tests

- `AdminPanelResourcesTest` walks the panel's own registry, so every resource
  registered later is covered the day it lands: soft-deletable model, a real
  permission key, a list page that renders, a trashed filter, and the
  authorisation matrix.
- `AdminResourceCrudTest` presses the buttons — create, edit, delete, restore,
  purge — on every resource. Both bugs found while building these were
  save-time, and neither shows up until the button is pressed.
- `SoftDeleteRestoreTest` holds the line between what is soft-deleted and what
  is not.
- `FilamentPanelAccessTest` holds the separation between the two panels.

## One design system, two panels

Everything about how the panels look and behave lives in
`Providers\Filament\CampusPanel` and is applied to both: the ARUCAD logo,
the colour roles, the sidebar groups and their order, the collapsible
sidebar, the content width, the locale middleware. A panel option added
there reaches both panels; an option added to one provider is a deliberate
difference and reads like one.

**The accent colour is the only intentional difference.** Admin is amber,
Trainer is teal, because someone holding both roles needs to tell at a
glance which one they are in before they change something.

`PanelParityTest` compares the two panels' actual configuration — logo,
navigation groups, layout settings — rather than reading the providers, so
a divergence is caught wherever it was introduced.

### The sidebar

Four groups, declared once, in a deliberate order:

| Group | Holds | State |
|---|---|---|
| Campus | Places, events, clubs, sports, services, food, shuttle | Open |
| People | Career opportunities, staff | Collapsed |
| Content | Pages and published text | Collapsed |
| Settings | Translations and anything app-wide | Collapsed |

Filament hides a group with no visible items, and every resource's
`canViewAny()` runs through `GranularPermissions` — so the menu adapts to
the signed-in account with no per-role menu code. A trainer does not see
groups they cannot use because there is nothing in them for them.

### The dashboard

The default Filament dashboard was `AccountWidget` + `FilamentInfoWidget`:
one repeated the name already in the corner, the other advertised the
framework to university staff. Both are gone. What replaces them is ordered
by what someone opening the panel needs to know, in that order:

1. **Needs attention** — held cases, open appeals, unresolved reports, each
   coloured by *whether the 24-hour promise is still being kept* rather than
   by how large the number is. A queue of forty reviewed the same day is
   fine; one item sitting four days is not.
2. **Quick actions** — shortcuts to the create screens people actually
   reach for, filtered through each resource's own `canCreate()`, so the
   list adapts rather than offering buttons that error on click.
3. **On campus now** — four numbers, not fourteen. Each was chosen because
   it can be *wrong* in a way staff would want to catch: an event that
   should be live and is not, content sitting deleted that nobody restored.

The Trainer dashboard has the same two widget shapes in the same places,
scoped to the signed-in department head. Same design, narrower content.

### The Trainer panel's resources

It had none — a shell with a login. It now manages its own events, using
the **Admin panel's event form, imported rather than copied**, so the two
cannot drift into looking different.

What differs is the query. `ScopedToOwnDepartment` restricts every read,
every route binding and every action to rows the signed-in staff member
owns, on the same `responsible_staff_id` column the JSON API scopes on.
Row-level scoping in the query, not a per-action check: a permission check
answers "may this person edit events" — yes — and would let them edit
somebody else's. A trainer without an active staff profile sees *nothing*,
not everything; that failure direction is the point.

New trainer events start as `pending`, matching the JSON API: a department
head submits, an admin approves. Ownership is stamped from the signed-in
profile, never from the form.

## Verified, not assumed

`AdminPanelResourcesTest` checks the whole management-features list against
every registered resource's real table — searchable columns, filters, bulk
delete/restore/export, and row-level view/edit/delete/restore/purge. A
resource can use the shared trait and still forget to wire its helpers into
the table, at which point the buttons are simply absent.

| Capability | Where |
|---|---|
| Listing, search, filter | Every resource table, asserted |
| Preview / view | `view` page on every resource, asserted |
| Edit | `edit` page, asserted |
| Soft delete + restore | Trait + trashed filter, asserted |
| Export | `ExportBulkAction`, asserted |
| Bulk actions | Delete, restore, purge, export, asserted |
| Role and permission control | `GranularPermissions`, asserted per role |
| Audit logging | `AuditLogger` on create/update/delete/restore/purge, asserted |

## Still to build

The migration covers twenty tables. Eight have a resource: places, clubs,
sports, services, food venues, shuttle routes, career opportunities and
events. Twelve do not yet: academic years, onboarding steps, staff profiles,
directory entries, quests, achievement definitions, workshop equipment, admin
pages, surveys, application questions, event participation types and daily
food menus. Each follows the pattern above.

The Trainer panel has its provider and its access rules but no resources of
its own yet — a department head still manages events through
`/api/v1/trainer/events`.
