<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Consultation diagnosis / treatment image attachments.
 *
 * The schema gained two nullable columns on `consultations` -
 * diagnosis_image and treatment_image (varchar 255) - each holding the
 * FILE NAME of at most one upload; the bytes live under
 * storage/app/private/consultations/ on the private local disk.
 *
 * They deliberately do NOT live under public/: these are private medical
 * images, so they are served only through the authorized
 * /consultations/{id}/attachments/{type} route (staff, or the owning
 * patient) which streams the file with response()->file() - never a
 * publicly accessible URL.
 *
 * The name is `{diagnosis|treatment}_{random40}.{ext}` where the extension
 * is content-sniffed by guessExtension() after the upload validation
 * whitelisted the file - never taken from client input - so a stored name
 * can only ever be one this class wrote. The strict shape check in
 * usable() is what makes a stale row (or a hand-edited one) harmless:
 * anything else resolves to null and is never touched.
 */
class ConsultationImages
{
    /** Storage directory on the private local disk, relative to its root. */
    public const DIRECTORY = 'consultations';

    /** Extensions upload validation accepts (content-sniffed, never client). */
    public const EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp'];

    /** Upload ceiling in kilobytes (5 MB), mirrored by the client hint. */
    public const MAX_KILOBYTES = 5120;

    /**
     * Store one validated upload and return the file name for the column.
     *
     * Copy rather than rename: a rename carries the upload's own open
     * handle onto the target, and on Windows a later unlink of that name
     * then sits in delete-pending until every handle closes - making every
     * later write to the same name fail with Permission denied (the same
     * rationale as ProfilePhoto::store).
     */
    public static function store(UploadedFile $file, string $kind): string
    {
        $extension = strtolower((string) $file->guessExtension());
        if (! in_array($extension, self::EXTENSIONS, true)) {
            $extension = 'jpg';
        }

        $filename = $kind.'_'.Str::random(40).'.'.$extension;
        $target = self::diskPath($filename);

        File::ensureDirectoryExists(dirname($target), 0755, true);
        copy($file->getPathname(), $target);

        return $filename;
    }

    /**
     * Absolute path of a stored attachment, or null when it is not one of
     * ours or the file no longer exists on disk.
     */
    public static function path(?string $filename): ?string
    {
        if (! self::usable($filename)) {
            return null;
        }

        $path = self::diskPath($filename);

        return is_file($path) ? $path : null;
    }

    /** Delete a stored attachment; anything that is not ours is ignored. */
    public static function delete(?string $filename): void
    {
        $path = self::path($filename);

        if ($path !== null) {
            @unlink($path);
        }
    }

    /** Absolute path of a usable file name on the private local disk. */
    private static function diskPath(string $filename): string
    {
        return Storage::disk('local')->path(self::DIRECTORY.'/'.$filename);
    }

    /** An attachment name is usable only when it is a `{kind}_{40}.{ext}` name we wrote. */
    private static function usable(?string $filename): bool
    {
        return is_string($filename)
            && preg_match('/^(diagnosis|treatment)_[A-Za-z0-9]{40}\.[a-z0-9]{2,5}$/', $filename) === 1;
    }
}
