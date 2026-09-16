<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Exceptions;

/**
 * A change to a set of purchase terms that cannot be made as asked.
 *
 * Each refusal carries a {@see reason()} a caller can branch on — to say it in the merchant's own
 * language, or to answer with the right status — since the message is written for a log, not for a
 * screen.
 *
 * @api
 */
final class TermsException extends InvFluxException
{
    public const NOT_FOUND = 'not_found';
    public const REMOVED = 'removed';
    public const EMPTY_BODY = 'empty_body';
    public const EMPTY_NAME = 'empty_name';
    public const NAME_TOO_LONG = 'name_too_long';
    public const COPY_UNCHANGED = 'copy_unchanged';
    public const STALE_EDIT = 'stale_edit';
    public const STILL_SELECTED = 'still_selected';
    public const ISSUED = 'issued';
    public const NAME_TAKEN = 'name_taken';

    private string $reason = '';

    private string $takenName = '';

    /** @var array{suppliers: list<int>, store: bool} */
    private array $selections = ['suppliers' => [], 'store' => false];

    private int $ordersIssued = 0;

    public function reason(): string
    {
        return $this->reason;
    }

    /**
     * Where a set that could not be removed is still chosen — empty unless {@see reason()} is
     * {@see self::STILL_SELECTED}.
     *
     * @return array{suppliers: list<int>, store: bool}
     */
    public function selections(): array
    {
        return $this->selections;
    }

    /** How many orders went out under a set that could not be deleted — 0 unless {@see self::ISSUED}. */
    public function ordersIssued(): int
    {
        return $this->ordersIssued;
    }

    /** The name another set already carries — empty unless {@see reason()} is {@see self::NAME_TAKEN}. */
    public function takenName(): string
    {
        return $this->takenName;
    }

    /** Another set — archived ones included — already carries this name. */
    public static function nameTaken(string $name): self
    {
        $e = self::because(self::NAME_TAKEN, sprintf('Another terms set is already named "%s".', $name));
        $e->takenName = $name;

        return $e;
    }

    /**
     * The set is still a supplier's choice or the store's, so taking it away would silently change
     * what every order inheriting it prints, without anyone having chosen the terms they fall back to.
     *
     * @param list<int> $suppliers
     */
    public static function stillSelected(int $id, array $suppliers, bool $store): self
    {
        $e = self::because(self::STILL_SELECTED, sprintf(
            'Terms set %d is still chosen by %d supplier(s)%s.',
            $id,
            \count($suppliers),
            $store ? ' and the store setting' : '',
        ));
        $e->selections = ['suppliers' => $suppliers, 'store' => $store];

        return $e;
    }

    /**
     * Orders went out under the set, which is the only bridge from their terms back to a name: it can
     * be archived, never deleted.
     */
    public static function issued(int $id, int $orders): self
    {
        $e = self::because(self::ISSUED, sprintf('Terms set %d was issued on %d order(s), so it can be archived but not deleted.', $id, $orders));
        $e->ordersIssued = $orders;

        return $e;
    }

    public static function lineageNotFound(int $id): self
    {
        return self::because(self::NOT_FOUND, sprintf('No terms set %d.', $id));
    }

    public static function removed(int $id): self
    {
        return self::because(self::REMOVED, sprintf('Terms set %d has been removed and can no longer be edited.', $id));
    }

    public static function emptyBody(): self
    {
        return self::because(self::EMPTY_BODY, 'Terms need some text.');
    }

    public static function emptyName(): self
    {
        return self::because(self::EMPTY_NAME, 'A terms set needs a name.');
    }

    public static function nameTooLong(int $max): self
    {
        return self::because(self::NAME_TOO_LONG, sprintf('A terms set name is at most %d characters.', $max));
    }

    /**
     * An unedited copy shares its source's interned text, so if that text was ever issued the copy is
     * born frozen — archived rather than deletable, on terms nobody has shipped under.
     */
    public static function copyUnchanged(): self
    {
        return self::because(self::COPY_UNCHANGED, 'A copy must differ from the terms it copies before it is saved.');
    }

    /** Someone else saved the set while this edit was open; saving over them would lose their change. */
    public static function staleEdit(int $editedFrom, int $current): self
    {
        return self::because(self::STALE_EDIT, sprintf('Edited from version %d, but the set is now at version %d.', $editedFrom, $current));
    }

    private static function because(string $reason, string $message): self
    {
        $e = new self($message);
        $e->reason = $reason;

        return $e;
    }
}
