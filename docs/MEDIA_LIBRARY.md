# Media Library — §5, 14 September 2026

## What the audit found

```
media_items rows : 30
files on disk    : 1533

Untracked files  : 1505  (4.6 MB)  — bytes with no row
Broken rows      : 2                — a row whose file is gone
Unreferenced     : 28               — a row with a file that nothing uses
```

Fifty files for every row. That is what a year of testing uploads looks
like: nothing in the app could show them, nobody could delete them from a
screen, and they only ever grew.

Three different problems live under the word "orphan" and they need
different answers, so `MediaAudit` counts them separately:

- **Untracked files** — invisible to the app and unmanageable from it.
- **Broken rows** — *worse*, because the app does show them: a post renders
  a frame that will never load.
- **Unreferenced rows** — a file nothing uses. The only one of the three
  that is a judgement call rather than a defect.

## What was done

```
php artisan media:audit                 # report, changes nothing
php artisan media:purge-orphans         # dry run
php artisan media:purge-orphans --force # act
```

Result: **1505 untracked files moved to quarantine, 2 broken rows
soft-deleted, 0 untracked files remaining.**

The purge **moves rather than deletes**. The whole premise of "untracked" is
that nothing in the database knows what those bytes are — which means
nothing in the database can tell you whether one of them mattered. They go
to `quarantine/<timestamp>/` on the same disk, recoverable with `mv`, and
somebody deletes that folder once they are satisfied. `--delete` removes
them outright for anyone who wants it.

Finding and destroying are two commands on purpose. One command that did
both would invite the second decision to be made by accident.

## The Media Library in the panel

A listing under **Content → Media**, with no create page and no edit form,
deliberately: media arrives by being uploaded with a post or a place. A row
typed in by hand would name a file that does not exist, and editing the
path of an existing row breaks every post pointing at it.

| §5 asked for | How |
|---|---|
| Single and bulk selection | Standard table selection + bulk action group |
| Previewing | Thumbnail on every row, plus a preview modal showing the image, who uploaded it, and **what is using it** |
| Soft deletion | `media_items` now soft-deletes; trashed filter to find them |
| Restoration | Row action and bulk action |
| Permanent deletion | Row action, described below |
| Detection of unused or orphaned files | "Unused only" and "File is missing" filters, plus `media:audit` |
| Related content before deletion | The delete dialog names the items using the file, by count and by name |
| Audit logging | Every delete, restore and purge through `AuditLogger` |

### Permanent deletion

Three gates, because this is the one action here that destroys bytes
nothing can bring back:

1. **Only on an already-deleted row.** Purging is always the second
   decision, never the first.
2. **Restricted to `users.manage`** — the same bar as roles and secrets.
   Content editors can delete (recoverable) but not destroy.
3. **A checkbox inside the confirmation dialog**, not just an "are you
   sure" prompt, which is dismissed reflexively. The dialog also names what
   is using the file.

## A bug this introduced, and the fix

Adding soft deletes to `media_items` broke the delete endpoints without
failing anything obvious: they deleted the **file** and then soft-deleted
the **row**. Restore would have handed back a record whose file was gone —
precisely the "broken row" defect the audit exists to report.

The API now soft-deletes the row and leaves the bytes alone. The file goes
when somebody purges deliberately, through the panel action or
`media:purge-orphans`. The trade-off is that deleted media keeps occupying
disk until it is purged, which is the cost of being able to restore it.

## The honest remainder

- **`used_in` is only as good as what writes it.** The delete warning names
  what is using a file by reading that column; anything that references
  media without recording it there will not appear in the warning. 28 of
  the 30 rows have it empty, which is either accurate or a gap in what
  writes it — worth checking against real production data before trusting
  "not used anywhere" as a reason to purge.
- **The quarantine folder is still on the same disk.** 4.6 MB here, but on
  a real deployment it should be moved off or deleted once checked.
- **Nothing runs `media:audit` on a schedule.** It is a command someone has
  to think to run.
