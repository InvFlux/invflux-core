<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Domain\Label;

use Nandan108\Attrecord\Attribute\Column;
use Nandan108\Attrecord\Attribute\Index;
use Nandan108\Attrecord\Attribute\LockTier;
use Nandan108\Attrecord\Attribute\Table;
use Nandan108\Attrecord\Attribute\UniqueKey;
use Nandan108\Attrecord\Enum\ColumnType;
use Nandan108\Attrecord\Exception\RecordValidationException;
use Nandan108\Attrecord\Record;

/**
 * One merchant-supplied translation of one label, in one locale.
 *
 * **Merchant data, not a catalogue string.** The admin language is a *per-user* WordPress
 * setting, so a shop whose floor staff read different languages shows everything the merchant
 * named — a tag, a location, a reason code — in whichever language the person who created it
 * happened to be using. Gettext cannot help: these strings are typed after the build, by the
 * merchant, and never reach a `.po` file. This table is where the merchant answers for them,
 * and nothing here is ever extracted or shipped.
 *
 * **One table for every labelled entity**, not a per-locale column on each record. `entity_type`
 * is an open string, so an add-on's own labelled entity needs no schema change; one indexed read
 * resolves a whole list; and the capability check that guards a write stays in one dispatcher
 * instead of being re-implemented on each entity's update path.
 *
 * **The record keeps its own `name` as the default** — a translation never replaces it. The
 * stored text is both the fallback and, for a built-in the merchant has not renamed, the gettext
 * key its code owner translates. {@see LabelTranslationRepository} states the resolution order.
 *
 * **Polymorphic by key, like {@see \Nandan108\InvFlux\Domain\Annotation\Annotation}** — and for
 * the same reason there is no foreign key: `entity_key` holds an int id or a string code
 * depending on the entity. Entities that retire rather than delete (a tag's `archived_at`) keep
 * their translations; a hard delete takes its rows with it, in the same transaction.
 *
 * @api
 *
 * @psalm-suppress PossiblyUnusedProperty Properties are hydrated by attrecord from row data.
 */
#[Table(name: 'invflux_label_translations')]
#[LockTier(49)]
#[UniqueKey('uniq_label', columns: ['entity_type', 'entity_key', 'field', 'locale'])]
#[Index('idx_type_locale', columns: ['entity_type', 'locale'])]
final class LabelTranslation extends Record
{
    /** The order/purchase-order/supplier tag vocabulary — phase one's only consumer. */
    public const ENTITY_TAG = 'tag';

    /** The display label itself — the field every labelled entity has. */
    public const FIELD_NAME = 'name';

    /** A one-line gloss, where the entity carries one. */
    public const FIELD_DESCRIPTION = 'description';

    #[Column(ColumnType::BigIntUnsigned, autoIncrement: true)]
    public ?int $id = null;

    /**
     * Which kind of entity this label belongs to ({@see self::ENTITY_TAG}, `location`,
     * `adjustment_reason`, …). An open string: an add-on registers its own rather than
     * extending an enum, which is what keeps a new labelled entity off the schema.
     */
    #[Column(ColumnType::VarChar, length: 32)]
    public string $entity_type = '';

    /**
     * The entity's own key as a string — an integer id for a tag, a code for a dimension value.
     * Stringly-typed on purpose: one column serves both, exactly as the caller stores it.
     */
    #[Column(ColumnType::VarChar, length: 64)]
    public string $entity_key = '';

    /** Which of the entity's labels this translates ({@see self::FIELD_NAME}). */
    #[Column(ColumnType::VarChar, length: 32)]
    public string $field = self::FIELD_NAME;

    /**
     * A WordPress locale (`fr_FR`, `de_CH`, `ca`) — the form `determine_locale()` returns, so a
     * lookup is an equality test and never a parse. {@see LocaleFallback} owns the one place
     * that relates two of them.
     */
    #[Column(ColumnType::VarChar, length: 20)]
    public string $locale = '';

    /**
     * The merchant's text for this label in this locale.
     *
     * Sized as a label, not as prose, and indexable for the same reason: finding a tag by a name
     * in any language is an ordinary column lookup here. Widening it is a converging change if a
     * future `field` genuinely needs more.
     */
    #[Column(ColumnType::VarChar, length: 255)]
    public string $text = '';

    /**
     * The text this translation was written against, as it read at the time — advisory only.
     *
     * A translation outlives the thing it translates. Rename a tag from "Cadeau" to "Cadeau
     * client" and its German row still applies, silently, now describing something else; move a
     * shipped column header from "Total" to "On hand" and a merchant's override sits over a label
     * that shifted underneath it. Neither is detectable without recording what was on screen when
     * the translation was typed, so this records it.
     *
     * **Never consulted when resolving.** A stale translation still renders: falling back to the
     * default because the source moved would replace a slightly-wrong label with a
     * definitely-foreign one. It is the editor that reads this, to ask whether the translation
     * still says the right thing.
     *
     * Null where the source is genuinely unknown — a row migrated from an older store, or written
     * by an importer that had nothing to record.
     */
    #[Column(ColumnType::VarChar, length: 255, nullable: true)]
    public ?string $source_text = null;

    #[Column(ColumnType::DateTime, precision: 6)]
    public ?\DateTimeImmutable $created_at = null;

    #[Column(ColumnType::DateTime, precision: 6, nullable: true)]
    public ?\DateTimeImmutable $updated_at = null;

    #[\Override]
    public function beforeSave(): void
    {
        $now = new \DateTimeImmutable();
        if (null === $this->created_at) {
            $this->created_at = $now;
        }
        $this->updated_at = $now;
    }

    #[\Override]
    public function validate(): void
    {
        foreach (['entity_type' => $this->entity_type, 'entity_key' => $this->entity_key, 'field' => $this->field, 'locale' => $this->locale] as $field => $value) {
            if ('' === trim($value)) {
                throw new RecordValidationException(
                    \sprintf('LabelTranslation.%s must not be empty.', $field),
                    ['field' => $field],
                );
            }
        }
        // An empty translation is a deletion, never a stored blank: a row saying "this label is
        // the empty string" would render a nameless tag, where absence correctly falls through to
        // the next step of the resolution order. The repository drops empties on write.
        if ('' === trim($this->text)) {
            throw new RecordValidationException(
                'LabelTranslation.text must not be empty — remove the row instead.',
                ['field' => 'text'],
            );
        }
        if (mb_strlen($this->text) > 255) {
            throw new RecordValidationException(
                \sprintf('LabelTranslation.text is %d characters; the maximum is 255.', mb_strlen($this->text)),
                ['field' => 'text'],
            );
        }
    }
}
