<?php

declare(strict_types=1);

namespace Lucent\Console\Support;

/**
 * Model filtering for the sync commands.
 *
 * Patterns are matched against fully-qualified class names; exclude wins.
 * Following the PHPUnit-style convention: a pattern that starts with an
 * alphanumeric character, or that is not a valid regular expression, is
 * treated as a case-insensitive literal substring match — so `--filter=User`
 * works without regex escaping. Any other pattern (e.g. slash-delimited
 * `/^App\\Models\\User$/`) is used verbatim as a regular expression.
 *
 * Surrounding quotes are stripped — the CLI passes the pattern verbatim,
 * and a shell-quoted pattern ('/Foo/') would fail preg_match silently.
 */
final class ModelFilter
{
    private readonly string|null $filter;
    private readonly string|null $exclude;

    /**
     * @param string|null $filter Include pattern (null = no filter)
     * @param string|null $exclude Exclude pattern (null = no filter)
     * @throws \InvalidArgumentException When a pattern is empty
     */
    public function __construct(string|null $filter, string|null $exclude)
    {
        $this->filter = $filter === null ? null : trim($filter, '\'"');
        $this->exclude = $exclude === null ? null : trim($exclude, '\'"');

        foreach (['filter' => $this->filter, 'exclude' => $this->exclude] as $name => $value) {
            if ($value === '') {
                throw new \InvalidArgumentException("Invalid {$name}: value must not be empty.");
            }
        }
    }

    /**
     * Split models into active and excluded buckets (exclude wins).
     *
     * @param list<class-string> $models
     * @return array{active: list<class-string>, excluded: list<class-string>}
     */
    public function apply(array $models): array
    {
        $active = [];
        $excluded = [];

        foreach ($models as $model) {
            if ($this->exclude !== null && self::matches($model, $this->exclude)) {
                $excluded[] = $model;
                continue;
            }

            if ($this->filter !== null && !self::matches($model, $this->filter)) {
                $excluded[] = $model;
                continue;
            }

            $active[] = $model;
        }

        return ['active' => $active, 'excluded' => $excluded];
    }

    /**
     * Match a class name against a filter pattern.
     *
     * A pattern that starts with an alphanumeric character, or that is not
     * a valid regular expression, is treated as a literal substring match
     * (case-insensitive) — so backslashes in fully-qualified class names
     * (e.g. App\Models\Rsvp) are treated literally rather than as invalid
     * regex escapes. Any other pattern is used verbatim as a regex.
     */
    public static function matches(string $class, string $pattern): bool
    {
        if (preg_match('/[a-zA-Z0-9]/', substr($pattern, 0, 1)) === 1 || @preg_match($pattern, '') === false) {
            // Literal substring mode.
            $pattern = sprintf('{%s}i', preg_quote($pattern, '/'));
        }

        return @preg_match($pattern, $class) === 1;
    }
}
