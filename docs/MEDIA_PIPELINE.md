# §4 — Server-side media processing

15 September 2026.

What happens to a photo between a student pressing Publish and it
appearing in someone else's feed, what the limits are and why, and — the
part that matters most for planning — **which parts of §4 could not be
built, and what unblocks them**.

## The constraint, stated first

This server has **neither GD nor Imagick**, and no `exif` extension:

```
gd         NO
imagick    NO
exif       NO
fileinfo   yes
```

Nothing can decode, rotate, resize or re-encode an image. That rules out
three things §4 asked for, and no amount of code works around it:

| Asked for | State | Needs |
|---|---|---|
| EXIF orientation correction | **Not built** | GD or Imagick |
| Derivatives (feed / story / thumbnail) | **Not built** | GD or Imagick |
| WebP/AVIF output with fallback | **Not built** | GD or Imagick |
| Transparent PNG flattening | **Not built** | GD or Imagick |
| Avoiding repeated JPEG encoding | **Partly** — see below | — |
| Decompression-bomb protection | **Built** | — |
| Excessive pixel dimensions | **Built** | — |
| Format from bytes, never extension/MIME | **Built** | — |
| Collision-resistant filenames | **Built** | — |
| Orphaned upload cleanup | **Built** | — |

Installing GD is one line in `php.ini` (`extension=gd`) and a restart. The
four blocked items become ordinary work after that.

## What is built

### The upload gate, in order

An upload is refused as early as it can be, so nothing expensive runs on
something that was never going to be accepted.

1. **Video is refused by name.** Video left the product on 14 September
   2026. This runs before validation so an older client gets
   `VIDEO_NOT_SUPPORTED` rather than a generic "unsupported type" that
   explains nothing. It reads the sniffed MIME, so renaming an `.mp4` does
   not route around it.

2. **Type allowlist.** `mimetypes:` reads the file's actual content, not
   the client's `Content-Type` or the extension. JPEG, PNG, WebP, HEIC.

3. **Byte size.** 12 MB for images.

4. **Pixel dimensions.** New. See below.

5. **Magic bytes.** `UploadMagic` checks the real signature and answers
   `INVALID_FILE_CONTENTS` for a file that claims to be an image and is
   not. This is deliberately left as the answer for unreadable files — it
   is more specific than a validation error and clients are already
   written against it.

6. **Moderation.** Text and image, before the row is publishable.

### Decompression bombs

The check that byte size cannot make. A single-colour PNG at 50,000 ×
50,000 pixels is a few kilobytes on disk — under every size limit — and
roughly ten gigabytes once decoded. The file really is small; only the
header says otherwise.

`ImageDimensions` reads that header with `getimagesize()`, which is part
of PHP itself and works without GD. The cost is a few bytes read, not the
allocation being avoided.

| Limit | Value | Why |
|---|---|---|
| `MAX_PIXELS` | 50,000,000 | Past any real camera (a 2026 flagship is 12–50 MP, and the picker downscales to 2560px wide long before this). At 4 bytes per pixel this caps a decode near 200 MB. |
| `MAX_SIDE` | 20,000 | A 1 × 60,000 strip is only 60,000 pixels and still breaks every layout it is drawn in. |

Both are refused with a message naming the picture's actual size, because
the person reading it needs to know what to do about their photo, not
which constant they exceeded.

### Filenames

Laravel's `store()` generates a random 40-character name and ignores the
client's entirely. The original name is kept in `media_items.file_name`
for display only and never used as a path — so a file called
`../../.env` is a label, not a traversal.

### Serving

Media is on a **private disk** and reachable only through
`/api/v1/media/{id}/file`, which enforces the moderation status on every
request. It is deliberately not on the `public` disk: that is published
verbatim by the `public/storage` symlink, which would let anyone with a
URL walk straight past moderation. `MediaStorageExposureTest` locks this
down, including a guard that fails if any test fakes the wrong disk.

### Orphan cleanup

`media:purge-orphans` moves untracked files to `quarantine/<timestamp>/`
rather than deleting them, and `media:restore-quarantine` puts them back.
Moving rather than deleting is the point: "untracked" means nothing in the
database knows what those bytes are, which also means nothing in the
database can prove they did not matter.

### Re-encoding

Partly addressed, from the other end. The picker was uploading at quality
82 and 1600px, which is a second JPEG encode on top of the camera's own
and visibly soft on a story. It is now 92 at 2560px — one encode, at a
size worth keeping. Without GD the server cannot re-encode at all, which
means it also cannot re-encode *twice*; the stored file is the uploaded
file.

## What the client does, and why that is not enough

The composer decodes each picked photo to read its real pixel size, warns
when a picture is under 600px on either side, and refuses a file that
cannot be decoded at all. That is for the author's benefit — telling them
before the post is public rather than after.

None of it is a security control. It runs on a device the app does not
control, and the server repeats every check that matters.

## Carousel storage

`feed_post_media` holds one row per picture: `media_url`, `sort_order`,
`width`, `height`, `aspect_ratio`, `style_json`, `alt_text`. Width and
height are recorded so the feed knows the shape before the bytes arrive —
otherwise every card resizes as its image loads and the list jumps under
the reader's thumb.

`media_type` exists and is always `'image'`. It is there so that adding
video later is a value change rather than a migration; nothing decodes
video today.

`feed_posts.image_url` is unchanged and still carries the first picture,
so every post written before carousels, and every client build already
installed, behaves exactly as it did.

## Framing is metadata, never a transformation

The composer used to centre-crop every post photo to 4:5 the instant it
was picked — permanently, before the author had seen it, discarding the
rest of the picture with no way back.

That is gone. The photo is uploaded whole and `style_json` says how to
draw it. `MediaFraming::sanitize()` decides what that may say: an
unrecognised value is dropped rather than guessed at, every number is
clamped to what a renderer can draw, and NaN and infinity are refused
outright — they survive `json_decode`, and they pass any naive range check
because every comparison against NaN is false.

## Reproducing

```bash
cd backend
php artisan test --filter=ImageDimensionsTest   # the bomb limits
php artisan test --filter=MediaFramingTest      # what framing may say
php artisan test --filter=MediaSafetyTest       # the upload gate
php artisan test --filter=PostCarouselTest      # carousel storage
php -r "var_dump(extension_loaded('gd'));"      # whether §4 is still blocked
```
