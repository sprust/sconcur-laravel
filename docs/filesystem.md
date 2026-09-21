English | [Русский](filesystem.ru.md)

# Filesystem (`sconcur_local` disk and the `File` facade)

SConcur's Files feature, wired into Laravel in two places:

- the `sconcur_local` disk driver — `Storage::disk(...)` on a local directory;
- the `files` binding — the `File` facade, switched on by a flag.

A file operation on the feature runs inside the extension while the calling coroutine is
suspended, so the worker's other requests go on while a disk answers. What the feature
itself does is in the library's `vendor/sconcur/sconcur/docs/files.md`.

## Table of contents

- [When the feature is used](#when-the-feature-is-used)
- [The `sconcur_local` disk](#the-sconcur_local-disk)
- [The `File` facade](#the-file-facade)
- [Limits](#limits)

## When the feature is used

The gain is where the bytes never cross into PHP — a copy, a hash, an upload stored from
its temporary file — and where a slow disk would otherwise hold the whole worker. On a
small file with a warm page cache the native call is faster, and a call outside a
coroutine has nothing to yield to. So both places follow one rule:

- inside a coroutine, the operations listed below go to the feature;
- outside a coroutine (boot, artisan, the tests' own process) every call is the native one;
- a failure on the feature hands the call to the native implementation, which answers it
  the way it always has: the `false`, the warning Laravel turns into an `ErrorException`,
  the Flysystem exception with the native message. A failing operation is done twice; in
  exchange no caller ever sees a `SConcur\Exceptions\Files\*` exception.

Two failures are not handed over: `FileStoppedException` (the coroutine is being unwound)
and `FileTimeoutException` (a deadline the application set, see `timeout_ms` below).

`tests/Feature/Filesystem/` runs every covered call through the native implementation and
through the package's one on the same tree, inside a coroutine and outside it, and requires
the same answer, the same exception and the same files with the same permissions.

## The `sconcur_local` disk

```php
// config/filesystems.php
'disks' => [
    'uploads' => [
        'driver'     => 'sconcur_local',
        'root'       => storage_path('app/uploads'),
        'visibility' => 'private',
        'lock'       => 0,
        'throw'      => false,
        'timeout_ms' => 0,
    ],
],
```

The entry is a `local` disk's entry — `root`, `visibility`, `permissions`,
`directory_visibility`, `links`, `lock`, `url`, `throw`, `report` — plus `timeout_ms`, the
deadline of one call; `0` is none, as natively. The disk is the framework's
`LocalFilesystemAdapter`, so `path()`, `url()`, `putFile()` and the rest are the ones a
`local` disk has. Only the adapter underneath differs:
`SConcur\Laravel\Filesystem\SconcurLocalFilesystemAdapter`.

| Operation | On the feature |
|---|---|
| `get`, `read` | `Files::read`, with no size limit, as `file_get_contents` has none |
| `put`, `writeStream` | only with `'lock' => 0`, see below; a stream of a local file read from its start is copied without crossing into PHP — which is what `putFile()` of an upload does |
| `copy` | `Files::copy`; the visibility is kept as the `local` disk keeps it |
| `move` | `Files::move` |
| `delete`, `deleteDirectory` | `Files::delete`, `Files::removeDirectory` |
| `exists`, `fileExists`, `directoryExists`, `size`, `lastModified`, `getVisibility` | one `Files::stat` |
| `checksum` | `Files::hashFile` for `md5`, `sha1`, `sha256`, `sha512`; any other algorithm is native |

Native on this disk too: `readStream` (its contract is a PHP resource), the listings
(`files`, `allFiles`, `directories` — a feature listing carries no permissions, and every
entry needs its visibility), `makeDirectory`, `setVisibility` and `mimeType`.

A `local` disk writes with `LOCK_EX` unless `lock` says otherwise, and the feature takes no
locks. So under the default lock `put` and `writeStream` stay native and the disk writes
exactly as `local` does; `'lock' => 0` puts them on the feature, again exactly as a `local`
disk with `'lock' => 0` writes — in place, without a lock.

A `writeStream` from anything other than a local file read from its start (`php://temp`, a
socket, a stream already read from) is written in chunks as it is read. Once it has
started reading it cannot hand the call over — the stream cannot be read twice — so a
failure there is an `UnableToWriteFile` of its own.

Refused when the disk is built, with a `RuntimeException`, rather than ignored:

| Key | Why |
|---|---|
| `serve` | the framework registers the route that serves a disk for the `local` driver only |
| `read-only`, `prefix` | their adapters live in optional Flysystem packages this one does not require |

## The `File` facade

```dotenv
SCONCUR_FILESYSTEM_FILES=true
SCONCUR_FILESYSTEM_TIMEOUT_MS=0
```

Off by default. The framework resolves `files` on every request — the view finder, the
Blade compiler, the translator — so replacing it is the application's decision. With the
flag on, `files` is `SConcur\Laravel\Filesystem\Filesystem`, a subclass of the framework's
own, and only these calls change:

| Method | On the feature |
|---|---|
| `File::copy` | `Files::copy` |
| `File::move` | `Files::move` |
| `File::hash` | `Files::hashFile` for `md5`, `sha1`, `sha256`, `sha512`; any other algorithm is native |
| `File::replace` | `Files::writeAtomic`, with the permissions the native method gives |

A path with a stream wrapper (`s3://`, `phar://`) and a copy onto the same file stay native.

Native on purpose: the metadata calls (`exists`, `isFile`, `lastModified`, `size`), which
the framework makes on every request and a boundary crossing would only slow down;
everything with a lock (`get`, `put`, `append` with `$lock`, `sharedGet`); `getRequire` and
`requireOnce`, which are `include`; `link`, `glob`, `isReadable`, `isWritable`; the
Finder-based listings, whose contract is Symfony's `SplFileInfo`; `hasSameHash`, which
hashes with `xxh128`, an algorithm the feature does not have.

Code that builds `new Filesystem()` itself rather than resolving `files` gets the
framework's class, as before.

## Limits

- No file locks: the feature has no `flock`.
- The listings of the disk stay native.
- A `sconcur_local` disk cannot `serve` and takes no `read-only` or `prefix`.
- `timeout_ms` is a deadline on the caller's wait, not on the system call: a thread already
  inside `read(2)` stays there until the kernel returns.
