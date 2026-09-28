<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Domain\Label;

/**
 * Reads and writes the merchant's own translations of the labels they typed.
 *
 * **Resolution order** for one label, in the locale asked for — the viewer's by default, a
 * document's own language when a supplier-facing export asks for it:
 *
 *  1. the **merchant's translation** for that locale, falling back along the language
 *     ({@see LocaleFallback});
 *  2. else, for a **built-in** label whose stored text still equals the English it was seeded
 *     with, its **code owner's gettext translation** — matching the seeded string, and not a
 *     built-in flag alone, is what stops a merchant's rename from being undone by the
 *     translation of the text it replaced;
 *  3. else the **stored text**: the record's own `name`, in whatever language they typed it.
 *
 * Only step 1 lives here. Steps 2 and 3 need gettext and the record, so they belong to the host
 * edge that resolves a label for a surface.
 *
 * **Every read is set-based.** Rendering a list of N labels costs one query, never N — the rule
 * that keeps a translated list from being slower than an untranslated one.
 *
 * @api
 */
interface LabelTranslationRepository
{
    /**
     * Every translation of one field, for a set of entities of one type.
     *
     * Keyed by entity key — as `array-key`, not `string`, because PHP normalises a numeric-string
     * array key to an integer: a tag whose key is `"7"` comes back under `7`. Callers look the key
     * up with the same string they passed and PHP normalises that too, so this matters only to the
     * declared type, never to a lookup.
     *
     * @param list<string> $keys entity keys; an empty list reads nothing
     *
     * @return array<array-key, array<string, string>> entity key → locale → text, absent where an
     *                                                 entity has no translation at all
     */
    public function forEntities(string $entityType, array $keys, string $field = LabelTranslation::FIELD_NAME): array;

    /**
     * Every translation of one field across a whole entity type.
     *
     * For the entity whose *entire* set is rendered at once — a grid's column headers — where
     * naming the keys would mean enumerating a registry to ask about it. Bounded by that type's
     * own cardinality, which is why it is safe to ask for all of it.
     *
     * @return array<array-key, array<string, string>> entity key → locale → text
     */
    public function forType(string $entityType, string $field = LabelTranslation::FIELD_NAME): array;

    /**
     * Every translation of every field of one entity — what the editor opens on.
     *
     * Whole records rather than their text, because the editor needs what resolution does not:
     * {@see LabelTranslation::$source_text}, to ask whether a translation still describes the
     * label it was written against.
     *
     * @return array<string, array<string, LabelTranslation>> field → locale → row
     */
    public function forEntity(string $entityType, string $entityKey): array;

    /**
     * Make one field's translations exactly `$byLocale`, adding, updating and removing as needed.
     *
     * Replacing the set rather than merging into it is what lets the editor delete a translation
     * by clearing its box: an absent locale means "no translation", which falls through to the
     * next step of the resolution order. An empty or whitespace-only text is treated as absent
     * for the same reason, so a blanked box never stores a nameless label.
     *
     * `$sourceText` is what the label read as when these translations were written — recorded on
     * every row the call writes, so a later rename can be spotted. Pass null only when the caller
     * genuinely cannot know it.
     *
     * @param array<string, string> $byLocale locale → text
     */
    public function replace(string $entityType, string $entityKey, string $field, array $byLocale, ?string $sourceText = null): void;

    /**
     * Make one **locale's** translations of a whole entity type exactly `$byEntity` — the
     * transpose of {@see replace()}, and the write that matches {@see forType()}.
     *
     * One surface edits one entity in every language (a tag's name); another edits every entity in
     * one language (a grid's headers, renamed by someone working in their own). Expressing the
     * second as a loop over the first would be a query per column, which is the rule against
     * looping over database calls — so it is its own set-based write.
     *
     * **Absence is not deletion.** `$byEntity` carries `null` for a key the caller is clearing and
     * omits the keys it has no opinion about, because a client posts the entities *it* knows of and
     * a set-replacement that read silence as "cleared" deleted the translations of everything the
     * client had not heard of. That happened: one column rename removed a merchant's translation of
     * an unrelated column, and nothing reported it — a deleted row and an intact one render the
     * same shipped label.
     *
     * So exactly three intents, and they stay distinguishable all the way down:
     *   - key ⇒ text  — set it;
     *   - key ⇒ null  — remove it (a blank box, or a label reset to the shipped name);
     *   - key absent  — leave whatever is there alone.
     *
     * @param array<string, ?string> $byEntity    entity key → text, or null to remove; absent keys
     *                                            are untouched
     * @param array<string, ?string> $sourceByKey entity key → what that label read as when written
     */
    public function replaceLocale(
        string $entityType,
        string $locale,
        string $field,
        array $byEntity,
        array $sourceByKey = [],
    ): void;

    /**
     * Drop every translation of one entity — for a **hard** delete only, in the deleting
     * transaction.
     *
     * An entity that retires rather than deletes (a tag's `archived_at`) keeps its translations:
     * it stays resolvable by id forever so historical records render, and it renders in the
     * reader's language when it does.
     */
    public function purge(string $entityType, string $entityKey): void;
}
