<?php

namespace App\Domain\Safety\Support;

use JsonSerializable;

/**
 * The gate's answer: allowed or not, with structured reasons ({code, message}) a UI can show and a test can
 * assert, plus requirements an allowed action must still meet (for example 'guardian_approval:booking').
 */
final class Decision implements JsonSerializable
{
    /**
     * @param  list<array{code: string, message: string}>  $reasons
     * @param  list<string>  $requirements
     */
    private function __construct(
        public readonly bool $allowed,
        public readonly array $reasons = [],
        public readonly array $requirements = [],
    ) {}

    public static function allow(): self
    {
        return new self(true);
    }

    public static function deny(string $code, string $message): self
    {
        return new self(false, [['code' => $code, 'message' => $message]]);
    }

    /** Fold several checks: denied if any is denied (all denial reasons kept), requirements merged. */
    public static function combine(self ...$decisions): self
    {
        $denials = array_values(array_filter($decisions, fn (self $d) => ! $d->allowed));

        if ($denials !== []) {
            return new self(false, array_merge(...array_map(fn (self $d) => $d->reasons, $denials)));
        }

        $requirements = array_values(array_unique(array_merge([], ...array_map(fn (self $d) => $d->requirements, $decisions))));

        return new self(true, [], $requirements);
    }

    public function requiring(string $requirement, string $message): self
    {
        if (! $this->allowed) {
            return $this;
        }

        return new self(true, [...$this->reasons, ['code' => 'requires_'.strtok($requirement, ':'), 'message' => $message]],
            array_values(array_unique([...$this->requirements, $requirement])));
    }

    public function denied(): bool
    {
        return ! $this->allowed;
    }

    public function requires(string $requirement): bool
    {
        return in_array($requirement, $this->requirements, true);
    }

    /** @return list<string> */
    public function codes(): array
    {
        return array_column($this->reasons, 'code');
    }

    public function hasReason(string $code): bool
    {
        return in_array($code, $this->codes(), true);
    }

    public function message(): string
    {
        return $this->reasons[0]['message'] ?? ($this->allowed ? 'Allowed.' : 'Not allowed.');
    }

    /** Throw a 403 carrying the reasons when denied. */
    public function authorize(): self
    {
        if ($this->denied()) {
            throw new SafeguardingDenied($this);
        }

        return $this;
    }

    public function jsonSerialize(): array
    {
        return ['allowed' => $this->allowed, 'reasons' => $this->reasons, 'requirements' => $this->requirements];
    }
}
