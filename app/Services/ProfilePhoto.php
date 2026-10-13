<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;

/**
 * Feature 5 - profile pictures on the merged Account Settings page.
 *
 * The migrated schema already carries `profiles.photo` (varchar 255, NULL by
 * default and filled by no live row), so pictures need NO schema change: the
 * column stores only the FILE NAME and the bytes live under
 * public/assets/images/profiles/{id}.{ext}.
 *
 * They live under the web root on purpose - this deployment has no
 * public/storage symlink, so a Storage disk would build URLs that 404, while
 * public/assets is already served (and already holds the brand images).
 *
 * The name is derived from the OWNER'S id plus an extension the server
 * guessed from the file content after the `image` validation rule whitelisted
 * it - never from client input. An upload can therefore only ever overwrite
 * its own owner's picture and can never introduce an executable extension.
 */
class ProfilePhoto
{
    /** Picture directory, relative to public/. */
    public const DIRECTORY = 'assets/images/profiles';

    /** Extensions upload validation accepts (content-sniffed, never client). */
    public const EXTENSIONS = ['jpg', 'jpeg', 'png'];

    /** Upload ceiling in kilobytes (2 MB). */
    public const MAX_KILOBYTES = 2048;

    /** Smallest picture that still makes a usable avatar, per side. */
    public const MIN_DIMENSION = 64;

    /** Largest picture accepted per side - guards decompression bombs. */
    public const MAX_DIMENSION = 6000;

    /**
     * Public URL of a stored picture, or '' when there is no usable one.
     *
     * Callers compare against '' so a missing picture falls back to the
     * initials/placeholder avatar instead of a broken <img>.
     */
    public static function url(?string $filename): string
    {
        if (! self::usable($filename)) {
            return '';
        }

        return asset(self::DIRECTORY.'/'.$filename);
    }

    /**
     * Absolute path of a stored picture, or null when it is not one of ours.
     *
     * The strict `id.ext` shape is what makes a stale row (or a hand-edited
     * one) harmless: anything else resolves to null and is never touched.
     */
    public static function path(?string $filename): ?string
    {
        return self::usable($filename) ? public_path(self::DIRECTORY.'/'.$filename) : null;
    }

    /**
     * Store the upload under its owner's id and return the new file name.
     *
     * Any previous picture is overwritten by construction - the name never
     * varies beyond the extension - so a re-upload cannot strand orphans.
     */
    public static function store(UploadedFile $file, int $userId): string
    {
        $extension = strtolower((string) $file->guessExtension());
        if (! in_array($extension, self::EXTENSIONS, true)) {
            $extension = 'jpg';
        }

        $filename = $userId.'.'.$extension;
        $target = public_path(self::DIRECTORY.'/'.$filename);

        File::ensureDirectoryExists(dirname($target), 0755, true);

        // Copy rather than rename: a rename carries the upload's own open
        // handle onto the target, and on Windows a later unlink of that
        // name then sits in delete-pending until every handle closes -
        // making every later write to the same name fail with Permission
        // denied (deterministically hit by the feature tests, and possible
        // for any long-lived worker in production). The temp source is
        // cleaned up by PHP for real uploads and by the GC for test fakes.
        copy($file->getPathname(), $target);

        return $filename;
    }

    /** Delete a stored picture; anything that is not ours is ignored. */
    public static function delete(?string $filename): void
    {
        $path = self::path($filename);

        if ($path !== null && is_file($path)) {
            @unlink($path);
        }
    }

    /** A picture is usable only when it is a bare `{id}.{ext}` name we wrote. */
    private static function usable(?string $filename): bool
    {
        return is_string($filename) && preg_match('/^\d+\.[a-z0-9]{2,5}$/', $filename) === 1;
    }
}
