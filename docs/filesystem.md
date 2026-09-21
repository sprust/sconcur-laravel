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
- [Differences from the native code](#differences-from-the-native-code)
- [Limits](#limits)

## When the feature is used

The gain is where the bytes never cross into PHP — a copy, a hash — and where a slow disk
would otherwise hold the whole worker. On a small file with a warm page cache the native
call is faster, and a call outside a coroutine has nothing to yield to. So both places
follow one rule:

- in a coroutine the extension drives and may still wait in, the operations listed below
  go to the feature;
- everywhere else the call is the native one: outside a coroutine (boot, artisan), in a
  fiber another library started, in a coroutine being unwound — a `finally` block run by
  `WaitGroup::stop()` or a shutdown, where a suspension would never be resumed — and in a
  process with `open_basedir` set, which PHP enforces and the feature does not see;
- a failure on the feature hands the call to the native implementation, which answers it
  the way it always has: the `false`, the warning Laravel turns into an `ErrorException`,
  the Flysystem exception with the native message. A failing operation is done twice.

Three failures are not handed over and reach the caller as they are:
`FileStoppedException` (the extension stopped the operation), `FileTimeoutException` (a
deadline the application set, see `timeout_ms` below) and `InvalidFileArgumentException` (a
call the feature refuses as malformed — a bug, not a condition of the disk).

What the feature would do differently stays native as well, decided inside a coroutine
only, at a `stat` or two per call:

- anything that is not a regular file: a directory as the source of a copy (the feature
  would truncate the destination before it failed), a directory, a FIFO or a device where
  a write or a copy lands, a FIFO or a `/proc` entry to read or hash (the feature reads by
  the size, and those report none);
- a copy onto the same file — by path, through a symlink or a hard link — which the native
  code refuses, where the feature would truncate the file it is about to read.

`tests/Feature/Filesystem/` runs every covered call through the native implementation and
through the package's one on the same tree, inside a coroutine and outside it, and requires
the same answer, the same exception and the same files with the same permissions.
`StatCacheTest` checks that PHP's own stat functions see each change at once.
`FeaturePathTest` checks the other half: that inside a coroutine the package's call makes
none of the native file operations that do the work (open, rename, unlink, mkdir, rmdir,
opendir) and the native call makes, so the feature did it.

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
| `get`, `read` | `Files::read`, with no size limit, as `file_get_contents` has none; at its peak the file is held twice, in the extension and in PHP |
| `put`, `writeStream` | only with `'lock' => 0`, see below |
| `copy` | `Files::copy`; the visibility is kept as the `local` disk keeps it |
| `delete`, `deleteDirectory` | `Files::delete`, `Files::removeDirectory`; a symlink stays native — the native code decides by what it points at, the feature by the link |
| `fileExists`, `directoryExists`, `size`, `lastModified`, `getVisibility` | one `Files::stat` each; `exists` asks `fileExists` and then `directoryExists`; a `lastModified` before 1970 is native |
| `checksum` | `Files::hashFile` for `md5`, `sha1`, `sha256`, `sha512`; any other algorithm is native |

Native on this disk too: `move` (within one filesystem it is one `rename(2)` with nothing
to gain, and across filesystems `rename()` copies through the target path — into the file
a symlink there points at, with the source's mode — where the feature would replace the
target), `readStream` (its contract is a PHP resource), the listings (`files`, `allFiles`,
`directories` — a feature listing carries no permissions, and every entry needs its
visibility), `makeDirectory`, `setVisibility` and `mimeType`.

A `local` disk writes with `LOCK_EX` unless `lock` says otherwise, and the feature takes no
locks. So under the default lock `put` and `writeStream` stay native and the disk writes
exactly as `local` does; `'lock' => 0` puts them on the feature, again exactly as a `local`
disk with `'lock' => 0` writes — in place, without a lock. Operations on the feature from
different coroutines of one worker run side by side, so two of them on one file race the
way two processes on a `local` disk race: a write without a lock can interleave with
another, and a read can meet a file truncated for a write. The protection is the one a
multi-process application needs anyway — `lock`, an atomic write, a lock of its own.

`writeStream` reads the stream in chunks and hands each to a writer of the feature, so a
stream filter applies as it does natively and the stream is left at its end. Reading stops
at the first read that gives nothing, as `file_put_contents()` stops — at the end of the
stream, and at once on a non-blocking one with no data waiting. The bytes are
read rather than copied from the file behind the stream, because nothing in a stream's
metadata says a filter is attached. Opening the writer can still fail over to the native
code; once reading has started it cannot — the stream cannot be read twice. A stream that
fails to read ends as natively: what was read stays written, and the call raises
`UnableToWriteFile`. A failure of the feature there raises `UnableToWriteFile` with the
feature's message.

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
| `File::hash` | `Files::hashFile` for `md5`, `sha1`, `sha256`, `sha512`; any other algorithm is native |
| `File::replace` | `Files::writeAtomic`, with the permissions the native method gives; contents other than a string (a resource, an array) and a mode other than plain permission bits (setuid/setgid/sticky, type bits from `fileperms()`, a string) stay native |

A path may be a Stringable object (`SplFileInfo`, `UploadedFile`), as natively. A path with
a stream wrapper (`s3://`, `phar://`) stays native.

Native on purpose: `move`, for the reasons given for the disk; the metadata calls (`exists`, `isFile`, `lastModified`, `size`), which
the framework makes on every request and a boundary crossing would only slow down;
everything with a lock (`get`, `put`, `append` with `$lock`, `sharedGet`); `getRequire` and
`requireOnce`, which are `include`; `link`, `glob`, `isReadable`, `isWritable`; the
Finder-based listings, whose contract is Symfony's `SplFileInfo`; `hasSameHash`, which
hashes with `xxh128`, an algorithm the feature does not have.

Code that builds `new Filesystem()` itself rather than resolving `files` gets the
framework's class, as before.

## Differences from the native code

What stays different, and why:

- PHP's stat cache. PHP answers `file_exists()`, `is_file()`, `filesize()` and the rest from
  the last `stat()` it made, and the feature changes the disk past PHP. So after every
  change on the feature the cache is cleared (`clearstatcache(true)`) — also after a write
  or a copy, where the native call leaves it as it was, so the package never answers from a
  stale entry where the native code would.
- An operation can be cut short. A native call runs to its end; one on the feature ends
  with the library's `FlowStoppedException` when its coroutine is unwound under it
  (`WaitGroup::stop()`, a worker stopping), and with `FileTimeoutException` past a
  `timeout_ms` above zero. A call made once the unwinding has begun runs natively.
- A failure is repeated natively from where the feature left the disk. The error the caller
  gets is the native one, but the first attempt may have done part of the work — a
  `deleteDirectory` that removed some entries before it failed — so what is left can
  differ from a run that was native throughout.
- Operations from different coroutines of one worker run side by side on one file, as the
  operations of different processes do — see the disk's `lock` above.
- The same-file check and the operation are two steps. A path swapped for a link to the
  source between them would have the copy truncate the source; closing that window needs
  the library to compare the open files.
- `File::replace` flushes the new file to the disk before renaming it (`writeAtomic`), which
  the native method does not: the same result, more durable and slower.

## Limits

- No file locks: the feature has no `flock`.
- The listings of the disk stay native.
- A `sconcur_local` disk cannot `serve` and takes no `read-only` or `prefix`.
- `timeout_ms` is a deadline on the caller's wait, not on the system call: a thread already
  inside `read(2)` stays there until the kernel returns.
