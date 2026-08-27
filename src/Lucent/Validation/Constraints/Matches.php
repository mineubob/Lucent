<?php

declare(strict_types=1);

namespace Lucent\Validation\Constraints;

use Closure;
use Lucent\Validation\Constraint;
use Lucent\Validation\FieldContext;
use Override;

/**
 * Validates a value against a regular expression pattern.
 *
 * Provides static factories for common patterns such as phone numbers,
 * passwords, and HEX colors.
 *
 * **ReDoS warning:** the pattern is applied to attacker-controlled input via
 * {@see preg_match()}. A pattern with catastrophic backtracking (e.g.
 * `/(a+)+$/`) applied to a long adversarial string can consume significant
 * CPU. Patterns must therefore be **developer-controlled and pre-validated** —
 * never derived from user input. The built-in factories are safe. For
 * untrusted patterns, use {@see safePattern()}, which rejects patterns
 * containing nested quantifiers.
 */
final class Matches extends Constraint
{
    /**
     * Create a constraint matching an international phone number (E.164).
     *
     * @return self A new Matches instance.
     */
    public static function mobile(): self
    {
        return (new self('/^\+?[1-9]\d{1,14}$/'))   // E.164
            ->withMessage('Phone number must be in a valid international format.');
    }

    /**
     * Create a constraint matching a strong password.
     *
     * Requires at least one lowercase letter, one uppercase letter, and a
     * minimum length of 8 characters.
     *
     * @return self A new Matches instance.
     */
    public static function password(): self
    {
        return (new self('/^(?=.*[a-z])(?=.*[A-Z]).{8,}$/'))
            ->withMessage('Password must contain at least one lowercase letter, one uppercase letter, and be at least 8 characters long.');
    }

    /**
     * Create a constraint matching a string of letters only.
     *
     * @return self A new Matches instance.
     */
    public static function alpha(): self
    {
        return (new self('/^[a-zA-Z]+$/'))
            ->withMessage('Must contain only letters.');
    }

    /**
     * Create a constraint matching a string of letters and numbers only.
     *
     * @return self A new Matches instance.
     */
    public static function alphanumeric(): self
    {
        return (new self('/^[a-zA-Z0-9]+$/'))
            ->withMessage('Must contain only letters and numbers.');
    }

    /**
     * Create a constraint matching a HEX color code.
     *
     * Accepts 3- or 6-digit codes with an optional leading `#`.
     *
     * @return self A new Matches instance.
     */
    public static function hexColor(): self
    {
        return (new self('/^#?([a-fA-F0-9]{6}|[a-fA-F0-9]{3})$/'))
            ->withMessage('Must be a valid HEX color code (e.g., #FFF or #FFFFFF).');
    }

    /**
     * Create a regex-based match constraint.
     *
     * @param string $pattern The PCRE pattern to match against.
     * @param int $flags Optional `preg_match` flags.
     */
    public function __construct(private readonly string $pattern, private readonly int $flags = 0) {}

    /**
     * Create a regex-based match constraint from a ReDoS-safe pattern.
     *
     * Rejects patterns containing nested quantifiers (e.g. `(a+)+`), which
     * are the classic source of catastrophic backtracking, and verifies the
     * pattern compiles. Use this when the pattern may come from an untrusted
     * source (config, user input) rather than a hard-coded developer constant.
     *
     * PHP has no built-in ReDoS detector, so this is a best-effort guard: it
     * catches the most common catastrophic-backtracking shape (a quantified
     * group containing a quantifier) and rejects patterns that fail to
     * compile (via {@see preg_last_error()}). It cannot prove a pattern is
     * safe, so patterns should still be reviewed.
     *
     * @param string $pattern The PCRE pattern to match against.
     * @param int $flags Optional `preg_match` flags.
     * @return self A new Matches instance.
     * @throws \InvalidArgumentException If the pattern contains a nested quantifier or does not compile.
     */
    public static function safePattern(string $pattern, int $flags = 0): self
    {
        if (preg_match('/\([^()]*[+*{][^()]*\)[+*?]/', $pattern)) {
            throw new \InvalidArgumentException('Pattern contains a nested quantifier and is not ReDoS-safe.');
        }

        // Verify the pattern compiles. The subject string is arbitrary — it only
        // exists to trigger compilation. A compile failure sets a PREG_*_ERROR
        // regardless of the subject, and a valid pattern against a single
        // character can never hit a backtrack/recursion limit, so 'x' is safe
        // and unambiguous. preg_last_error() distinguishes a compile error
        // from a legitimate no-match.
        @preg_match($pattern, 'x');
        if (preg_last_error() !== PREG_NO_ERROR) {
            throw new \InvalidArgumentException('Pattern is not a valid PCRE pattern.');
        }

        return new self($pattern, $flags);
    }

    /**
     * @return string|Closure(FieldContext): string The default error message.
     */
    #[Override]
    protected function defaultMessage(): string|Closure|null
    {
        return fn(FieldContext $ctx) => "The {$ctx->field} field didn't match the expected pattern.";
    }

    /**
     * Validate that the value matches the configured regex pattern.
     *
     * @param FieldContext $ctx The context of the field being validated.
     * @return bool True if the value matches the pattern, false otherwise.
     */
    #[Override]
    public function validate(FieldContext $ctx): bool
    {
        return is_string($ctx->value) && preg_match($this->pattern, $ctx->value, flags: $this->flags);
    }
}
