<?php

declare(strict_types=1);

namespace CharityCashbook\Charity;

use CharityCashbook\Core\DatabaseInterface;
use CharityCashbook\Core\Exceptions\ValidationException;

/**
 * Year-end close: once a financial year is closed, nothing dated in it may change.
 *
 * charities.books_locked_until holds the last day of the most recent closed year.
 */
final class BooksLock
{
    /**
     * The charity's lock date, or null if no year has been closed
     */
    public static function lockedUntil(DatabaseInterface $db, int $charityId): ?string
    {
        $value = $db->queryValue("SELECT books_locked_until FROM charities WHERE id = ?", [$charityId]);

        return $value ? (string) $value : null;
    }

    /**
     * Whether a date falls in a closed year
     */
    public static function isLocked(?string $lockedUntil, ?string $date): bool
    {
        return $lockedUntil !== null && $date !== null && $date !== '' && $date <= $lockedUntil;
    }

    /**
     * Throw if any of the dates falls in a closed year
     */
    public static function assertUnlocked(?string $lockedUntil, ?string ...$dates): void
    {
        foreach ($dates as $date) {
            if (self::isLocked($lockedUntil, $date)) {
                throw new ValidationException([
                    'locked' => "The books are closed up to $lockedUntil, so nothing dated on or before then can be changed. "
                        . 'An administrator can reopen the year in Settings if a correction is needed.',
                ]);
            }
        }
    }
}
