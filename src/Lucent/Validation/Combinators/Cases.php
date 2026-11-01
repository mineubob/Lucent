<?php

declare(strict_types=1);

namespace Lucent\Validation\Combinators;

use Closure;
use Lucent\Validation\Condition;
use Lucent\Validation\Concerns\RecordsConstraintFailure;
use Lucent\Validation\Constraint;
use Lucent\Validation\FieldContext;
use Override;

/**
 * A conditional constraint that switches between validation branches based on
 * a field's value.
 *
 * Reads as: "when the anchor field's value is X, these constraints must hold."
 * Branches are evaluated in declaration order and the **first match wins**;
 * its constraint is applied and the remaining branches are skipped.
 *
 * Three entry modes:
 *
 * - **Own-value mode** via `Cases::own($matcher, $constraint)`: matches the
 *   field's **own** value and constrains the field itself — the type-switch
 *   shape, e.g. `Cases::own(new Str(), new Length(max: 100))->case(new
 *   Integer(), new Range(min: 0))`.
 * - **Sibling mode** via `Cases::is($matcher, $constraint)`: compares the anchor
 *   field's **own** value and constrains its SIBLING fields. Typically composed
 *   after a normalizing constraint (e.g. `All::of(new Enum(PaymentType::class),
 *   Cases::is(...))`), so the matched value is the normalized enum instance.
 * - **Targeted mode** via `Cases::on('type')`: evaluates the named field.
 *   With the reference-ordering scheduler ({@see \Lucent\Validation\Combinators\Shape}),
 *   the referenced field is validated and normalized first regardless of
 *   declaration order.
 *
 * Which context the branch constrains depends on the mode:
 *
 * - own-value mode: the field itself (errors land at the field's own path)
 * - sibling mode: the enclosing object's context (errors land at the
 *   sibling fields the branch declares)
 * - targeted mode: the declaring field itself (`card.number` when the
 *   `Cases` sits on `card`)
 *
 * When no branch matches, a "does not match any case" error is recorded at
 * the anchor field's path; call {@see passOnNoMatch()} to allow partial
 * branch coverage. If the anchor field already failed validation (e.g. its
 * `Enum` check), or is absent, branch matching is skipped entirely and the
 * constraint passes — a broken or missing discriminator should not pile a
 * second, confusing error on top of the real one.
 *
 * ```php
 * Cases::is(PaymentType::Card, Shape::object([
 *     'card_number' => new Required(),
 * ]))->case(PaymentType::Paypal, Shape::object([
 *     'paypal_email' => new Required(),
 * ]));
 * ```
 */
final class Cases extends Constraint
{
    use RecordsConstraintFailure;

    /**
     * The compiled branches, in declaration order.
     *
     * @var list<array{0: Condition, 1: Constraint}>
     */
    private readonly array $branches;

    /**
     * Whether a no-match passes instead of recording an error.
     */
    private readonly bool $passOnNoMatch;

    /**
     * The targeted field, or null for own-value/sibling mode.
     */
    private readonly string|null $target;

    /**
     * Whether branches constrain the field's own value (own-value mode).
     *
     * @var bool
     */
    private readonly bool $own;

    /**
     * Internal constructor. Use the static entry points.
     *
     * All parameters after `$target` are optional and passed by name at call
     * sites, so each clone keeps only the state it is changing explicit and
     * the defaults stay visible.
     *
     * @param string|null $target The targeted field name, or null for own-value/sibling mode.
     * @param list<array{0: Condition, 1: Constraint}> $branches Compiled branches.
     * @param bool $passOnNoMatch Whether a no-match passes silently.
     * @param bool $own Whether the branches constrain the field's own value.
     */
    private function __construct(?string $target, array $branches = [], bool $passOnNoMatch = false, bool $own = false)
    {
        $this->target = $target;
        $this->branches = $branches;
        $this->passOnNoMatch = $passOnNoMatch;
        $this->own = $own;
    }

    /**
     * Start a targeted-mode Cases evaluating a named field.
     *
     * @param string $field The field whose value drives the branch selection.
     * @return self A new empty targeted instance; add branches with ->case().
     */
    public static function on(string $field): self
    {
        if ($field === '') {
            throw new \InvalidArgumentException('Cases::on() requires a non-empty field name.');
        }

        return new self($field);
    }

    /**
     * Start an own-value-mode Cases: match the field's own value and
     * constrain the field itself.
     *
     * The type-switch shape — the field's input type decides which rules
     * apply to it. Constraint matchers make type checks natural:
     *
     * ```php
     * 'amount' => Cases::own(new Str(), new Length(max: 100))
     *     ->case(new Integer(), new Range(min: 0)),
     * ```
     *
     * @param mixed $matcher Scalar, list of scalars, enum case, or Constraint.
     * @param Constraint $constraint The constraint applied to the field when the matcher matches.
     * @return self A new own-value-mode instance with the first branch applied.
     */
    public static function own(mixed $matcher, Constraint $constraint): self
    {
        return (new self(null, own: true))->case($matcher, $constraint);
    }

    /**
     * Start a self-mode Cases targeting the anchor field's own value.
     *
     * Named `is` because PHP cannot route static-looking calls of an instance
     * method name through {@see __callStatic()} — a declared public instance
     * method makes `Cases::case(...)` a hard error — so the self-mode entry
     * gets its own static name: read it as "the anchor field IS this value".
     *
     * ```php
     * 'type' => All::of(
     *     new Enum(PaymentType::class),
     *     Cases::is(PaymentType::Card, $cardShape)->case(PaymentType::Paypal, $paypalShape),
     * ),
     * ```
     *
     * @param mixed $matcher Scalar, list of scalars, enum case, or Constraint.
     * @param Constraint $constraint The constraint applied when the matcher matches.
     * @return self A new self-mode instance with the first branch applied.
     */
    public static function is(mixed $matcher, Constraint $constraint): self
    {
        return (new self(null))->case($matcher, $constraint);
    }

    /**
     * Append a branch, returning a new immutable instance.
     *
     * @param mixed $matcher Scalar, list of scalars, enum case, or Constraint.
     * @param Constraint $constraint The constraint applied when the matcher matches.
     * @return self A new instance with the branch appended; the receiver is unmodified.
     */
    public function case(mixed $matcher, Constraint $constraint): self
    {
        $branches = $this->branches;
        $branches[] = [Condition::of($matcher), $constraint];

        return new self($this->target, branches: $branches, passOnNoMatch: $this->passOnNoMatch, own: $this->own);
    }

    /**
     * Allow a no-match to pass without recording an error.
     *
     * By default, a value that matches no branch records a "does not match
     * any case" error — the author declared all valid branches, so silence
     * would hide a forgotten one. Call this when intentionally covering only
     * some values.
     *
     * @return self A new instance with the behavior flipped; the receiver is unmodified.
     */
    public function passOnNoMatch(): self
    {
        return new self($this->target, branches: $this->branches, passOnNoMatch: true, own: $this->own);
    }

    /**
     * Validate the anchor field and apply the first matching branch.
     *
     * @param FieldContext $ctx The context of the anchor field.
     * @return bool True when a branch matched and its constraint passed (or a
     *         no-match was allowed, or the skip guard applied); false otherwise.
     */
    #[Override]
    public function validate(FieldContext $ctx): bool
    {
        if ($this->branches === []) {
            throw new \LogicException('Cases must have at least one branch before validation.');
        }

        // Self-targeting (`Cases::on($ownField)`) is rejected: branching on
        // the field's own value is self-mode's job — All::of(new Enum(...),
        // Cases::is(...)) on the field itself. Allowing both spellings would
        // mean two ways to do the same thing with different error semantics.
        if ($this->target !== null && $ctx->field === $this->target) {
            throw new \LogicException(
                "Cases::on('{$this->target}') cannot target its own field; "
                . 'compose Cases::is(...) on the field instead.',
            );
        }

        $anchor = $this->anchorContext($ctx);

        // Skip guard: if the anchor field already failed validation (e.g. its
        // Enum membership check), branch matching would produce a spurious
        // "does not match any case" on top of the real error. A broken
        // discriminator should not trigger branch logic.
        if ($ctx->result->hasError($anchor->field)) {
            return true;
        }

        // An absent anchor has nothing to switch on: passing silently leaves
        // the discriminator's own Required/Enum constraints to report it.
        if (!$anchor->present && !$ctx->result->hasValue($anchor->field)) {
            return true;
        }

        // In targeted mode an absent DECLARING field has nothing to constrain
        // either: running the branch shape against null would report a
        // spurious "must be an object" for a field the payload never
        // provided. The field's own constraints report its absence.
        if ($this->target !== null && !$ctx->present && !$ctx->result->hasValue($ctx->field)) {
            return true;
        }

        foreach ($this->branches as [$matcher, $constraint]) {
            if (!$matcher->matches($anchor)) {
                continue;
            }

            // Which context the branch constrains depends on the entry mode.
            // Own-value mode (Cases::own(...)): the match and the branch both
            // target the field itself — the type-switch shape.
            // Sibling mode (declared ON the anchor, usually inside All::of(Enum,
            // Cases::is(...))): the branch declares the anchor's SIBLING
            // fields, so it runs against the enclosing object's context.
            // Targeted mode (declared on another field, e.g.
            // 'card' => Cases::on('type')->case(...)): the branch declares
            // the DECLARING field's sub-shape, so it runs against the
            // declaring field's own context.
            $branchCtx = $this->own || $this->target !== null ? $ctx : ($ctx->parent() ?? $ctx);

            $passed = $this->recordConstraintFailure($constraint, $branchCtx);

            if (!$passed) {
                // The branch constraint already recorded its specific errors
                // (e.g. `card_number is required`); flag the context so this
                // constraint's own message resolves to null and the container
                // does not add a redundant error under the Cases field's path.
                $ctx->childFailed = true;
            }

            return $passed;
        }

        if ($this->passOnNoMatch) {
            return true;
        }

        // No branch matched. In targeted mode the error is recorded here at
        // the ANCHOR's path (e.g. `type`), where the value it describes
        // lives. In own-value and sibling mode the anchor IS the declaring
        // field, so the container records the error under its own path —
        // childFailed is left false so the default message resolves,
        // mirroring Shape.
        if ($this->target !== null) {
            $ctx->childFailed = true;

            $message = $this->message($anchor);

            if ($message !== null) {
                $anchor->result->addError($anchor->field, $message);
            }

            return false;
        }

        return false;
    }

    /**
     * Declare the field this Cases reads, for scheduler ordering.
     *
     * Targeted mode knows its anchor statically; self-mode depends on the
     * field it is attached to (already validated in the same pass slot) and
     * declares nothing.
     *
     * @return list<string> The referenced sibling field names.
     */
    #[Override]
    public function referencedFields(): array
    {
        return $this->target === null ? [] : [$this->target];
    }

    /**
     * @return string|Closure(FieldContext): ?string|null The default error message.
     */
    #[Override]
    protected function defaultMessage(): string|Closure|null
    {
        // A matched branch that failed flags childFailed, suppressing this
        // message via Constraint::message() — the branch's specific errors
        // stand, mirroring Shape. In self-mode no-match the flag stays false
        // so the container records this message.
        return fn(FieldContext $ctx) => "The {$this->anchorName($ctx)} value does not match any case.";
    }

    /**
     * Derive the context the matchers evaluate against.
     *
     * In self-mode the context IS the anchor field. In targeted mode the
     * matchers must see the named field's value, so a sibling context for
     * that field is derived — its value prefers a normalized value already
     * stored in the result and falls back to the raw body, mirroring the
     * cross-field getter's semantics.
     *
     * @param FieldContext $ctx The context Cases is validated against.
     * @return FieldContext The anchor field's context.
     */
    private function anchorContext(FieldContext $ctx): FieldContext
    {
        if ($this->target === null) {
            return $ctx;
        }

        return $ctx->sibling($this->target);
    }

    /**
     * The dotted path of the anchor field.
     *
     * @param FieldContext $ctx The context Cases is validated against.
     * @return string The anchor's dotted path.
     */
    private function anchorPath(FieldContext $ctx): string
    {
        if ($this->target === null) {
            return $ctx->field;
        }

        // Bare names resolve relative to the current field's parent — the
        // same sibling resolution the cross-field getter applies.
        $dot = strrpos($ctx->field, '.');

        if ($dot === false || str_contains($this->target, '.')) {
            return $this->target;
        }

        return substr($ctx->field, 0, $dot) . '.' . $this->target;
    }

    /**
     * The field name used in the no-match error message.
     *
     * @param FieldContext $ctx The context Cases is validated against.
     * @return string The anchor field name or path.
     */
    private function anchorName(FieldContext $ctx): string
    {
        return $this->anchorPath($ctx);
    }
}
