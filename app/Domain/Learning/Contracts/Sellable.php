<?php

namespace App\Domain\Learning\Contracts;

/**
 * Something the learning domain sells (a course, a bundle, a library resource). The money domain's Purchasable is
 * adapted from this shape by the integration provider, so the learning domain never depends on money's classes.
 */
interface Sellable
{
    /** Stable type key: course, bundle or resource. */
    public function sellableType(): string;

    public function sellableId(): int;

    public function priceMinor(): int;

    public function currency(): string;

    public function sellerId(): ?int;

    public function sellableDescription(): string;
}
