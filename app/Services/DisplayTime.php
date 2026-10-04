<?php

namespace App\Services;

use Illuminate\Support\Carbon;

/**
 * The single boundary between stored time and displayed time.
 *
 * Storage stays UTC: `config('app.timezone')` is UTC, every column holds UTC, and all comparison
 * and scheduling logic runs in UTC. What changes at the edges is interpretation. A member of
 * staff typing "15:00" into a form means 15:00 where they are, so the value is read in the
 * configured display timezone and converted to UTC before it is stored or compared; the reverse
 * conversion happens on the way out.
 *
 * Nothing here adds or subtracts a fixed offset. Conversion goes through the timezone database,
 * so a region that later adopts daylight saving keeps working without code changes.
 */
class DisplayTime
{
    /** The timezone staff type and read times in. Pakistan does not observe daylight saving. */
    public static function zone(): string
    {
        return (string) config('lms.display_timezone', 'Asia/Karachi');
    }

    /** Short name for the configured zone, e.g. "PKT". Shown beside every rendered time. */
    public static function abbreviation(): string
    {
        return Carbon::now(self::zone())->format('T');
    }

    /**
     * Read a value typed by a person in the display timezone and return it as UTC for storage.
     *
     * Use this before validating relative rules too: comparing a local wall-clock string against
     * a UTC "now" is exactly the mistake this class exists to prevent.
     */
    public static function toUtc(string $value): Carbon
    {
        return Carbon::parse($value, self::zone())->utc();
    }

    /** The same conversion rendered for a database column. */
    public static function toUtcString(string $value): string
    {
        return self::toUtc($value)->format('Y-m-d H:i:s');
    }

    /**
     * Convert the named inputs from the display timezone to UTC before validation runs.
     *
     * This has to happen first. Rules such as `after:now` and `after:opens_at` resolve their
     * comparison in the application timezone, so a local wall-clock string would be measured
     * against a UTC instant and a window opening in one hour could be rejected as being in the
     * past. Values that cannot be parsed are left untouched for the `date` rule to reject.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public static function normalize(array $input, string ...$keys): array
    {
        foreach ($keys as $key) {
            $value = $input[$key] ?? null;
            if (! is_string($value) || trim($value) === '') {
                continue;
            }
            try {
                $input[$key] = self::toUtcString($value);
            } catch (\Throwable) {
                // Malformed input stays as it was so validation reports it, not this converter.
            }
        }

        return $input;
    }

    /** A stored UTC value rendered for a datetime-local input, in the display timezone. */
    public static function forInput(Carbon|string|null $value): string
    {
        return $value === null ? '' : self::local($value)->format('Y-m-d\TH:i');
    }

    /** A stored UTC value rendered for reading, with its timezone named. */
    public static function format(Carbon|string|null $value, string $format = 'M j, Y · H:i'): string
    {
        return $value === null ? '—' : self::local($value)->format($format).' '.self::abbreviation();
    }

    /** Date only, where a time of day would be noise. */
    public static function formatDate(Carbon|string|null $value): string
    {
        return $value === null ? '—' : self::local($value)->format('M j, Y');
    }

    /** Interpret a stored value as UTC and move it into the display timezone. */
    private static function local(Carbon|string $value): Carbon
    {
        $time = $value instanceof Carbon ? $value->copy() : Carbon::parse($value, 'UTC');

        return $time->setTimezone(self::zone());
    }
}
