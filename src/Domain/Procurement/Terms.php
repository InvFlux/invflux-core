<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Domain\Procurement;

use Nandan108\Attrecord\Attribute\Column;
use Nandan108\Attrecord\Attribute\CreatedAt;
use Nandan108\Attrecord\Attribute\LockTier;
use Nandan108\Attrecord\Attribute\Table;
use Nandan108\Attrecord\Attribute\UniqueKey;
use Nandan108\Attrecord\Enum\ColumnType;
use Nandan108\Attrecord\Enum\GeneratedColumnMode;
use Nandan108\Attrecord\Immutable;
use Nandan108\Attrecord\Record;

/**
 * Terms and conditions **as a document stated them** — the exact artefact a supplier received,
 * interned once and shared by every order issued under it.
 *
 * **Why this table exists.** Terms were resolved when a document was built, reading the order's own
 * text, else the supplier's standing terms, else the store's. Read live, editing a supplier's terms
 * next month silently re-prints an already-sent order under terms its supplier never saw. Freezing a
 * copy onto each order fixes that and leaves two things: the same paragraph copied onto every order,
 * and nothing stopping a later process from editing what was supposedly frozen.
 *
 * **Identity is the content, and the engine computes it.** {@see $content_hash} is a generated
 * column, so no application code — ours or anyone's — can state it. Combined with the referencing
 * foreign key declared `ON UPDATE RESTRICT`, that makes immutability **structural**: editing the body
 * of a referenced row recomputes the hash, which is an implied parent-key update, which RESTRICT
 * refuses. A process reaching the database directly, around this layer entirely, is refused by the
 * same rule. {@see Immutable} states the promise; the schema is what keeps it.
 *
 * **What is hashed is what PRINTS, never the body alone.** {@see $public_ref} is inside the digest
 * because it appears on the supplier's copy: two lineages with identical bodies but different
 * printed references are different artefacts and must not collapse into one row. That is also why
 * the column is in the expression from this table's first migration, though nothing here populates
 * it — see the note on {@see $content_hash}.
 *
 * **The key is 64 bits, and a collision moves the newcomer rather than refusing it** — the same shape
 * as {@see DocumentParty}. A generated column cannot be written, but it is computed from the row, so
 * {@see $attempt} steers it: interning writes the text again at its next attempt when different
 * terms already hold the key. The width is sized to the volume — a merchant's terms run to hundreds
 * of rows over decades — and not to tampering, because what refuses an edit is the `RESTRICT` key,
 * never the width of the hash.
 *
 * **Orphans accumulate by design.** Editing terms nothing has shipped under mints a new row and
 * repoints the label; the abandoned row stays. No reaper runs, matching {@see DocumentParty} — and
 * unlike that table, a *referenced* row here cannot be removed at all, since the foreign key holds
 * it. {@see Immutable} rather than `AppendOnly` for the same reason it does: a row nothing points at
 * asserts nothing, so a future reaper is a decision this marker leaves open.
 *
 * @api
 *
 * @psalm-suppress PossiblyUnusedProperty Properties are hydrated by attrecord from row data.
 */
#[Table(name: 'invflux_terms')]
#[UniqueKey(name: 'uq_terms_content', columns: ['content_hash'])]
#[UniqueKey(name: 'uq_terms_public_ref', columns: ['public_ref'])]
#[LockTier(42)]
final class Terms extends Record implements Immutable
{
    #[Column(ColumnType::BigIntUnsigned, autoIncrement: true)]
    public ?int $id = null;

    /**
     * The terms as Markdown **source**, which is the artefact of record.
     *
     * Formatting and font carry no legal weight; the text is what binds. So the source is what is
     * stored, hashed and preserved, and every rendering — the printed page, the spreadsheet cell,
     * the editor's preview — is derived from it. That dissolves rather than mitigates the worry that
     * a hash proves the source and not the pixels: the pixels were never the thing.
     *
     * **Stored already normalised.** A prettify pass runs before the row is written, so trailing
     * whitespace cannot mint a second version of identical terms. It runs in application code and
     * never inside the digest expression, which stays dumb — it hashes the bytes it was given. A
     * normaliser inside the expression would become part of the identity function and therefore
     * unchangeable forever, with every stored hash shifting the day anybody improved it.
     */
    #[Column(ColumnType::Text)]
    public string $body = '';

    /**
     * The reference this edition is printed under, when the artefact carries one — free text,
     * because merchants arrive with document references they already use.
     *
     * **A printed reference is not the administrative name.** {@see TermsLineage::$name} is internal
     * and renameable precisely because orders reference content rather than names. This appears on
     * the supplier's copy, so it must be unique across every edition ever issued, never recycled,
     * and fixed once assigned — which the unique key enforces.
     *
     * Nothing in the base platform sets this today. It is here from the first migration anyway; see
     * {@see $content_hash} for why that is not optional.
     */
    #[Column(ColumnType::VarChar, length: 128, nullable: true)]
    public ?string $public_ref = null;

    /**
     * Which key this text occupies: null for the one its content alone implies, and 1 upward only
     * where different terms already held that key.
     *
     * Inside the digest and dropped from it while null, so an ordinary row hashes exactly its content
     * and a moved row lands somewhere unrelated rather than on a neighbouring key. Chosen by
     * {@see TermsRepository::internTerms()} and by nothing else: which attempt a text lands on
     * depends on what the table already held, so no caller can know it in advance.
     */
    #[Column(ColumnType::TinyIntUnsigned, nullable: true)]
    public ?int $attempt = null;

    /**
     * The content digest, computed by the database and writable by nobody.
     *
     * **Four things about this column are load-bearing and easy to get wrong later:**
     *
     * 1. **It is not the primary key.** MariaDB refuses `PRIMARY KEY` on a generated column
     *    (error 1903) while MySQL 8 accepts it silently, so a table keyed that way passes on one
     *    engine and fails on the other. A surrogate key with the hash under a `UNIQUE` index behaves
     *    identically everywhere, and a foreign key may reference any unique index.
     * 2. **{@see $public_ref} and {@see $attempt} are inside the expression.** The reference because
     *    it is part of what a supplier receives, so two editions carrying different references are
     *    different artefacts and must not collapse into one row — here from the start because it
     *    belongs to the artefact, though the sparse form in (3) would admit it later. The attempt
     *    because it is how a collision moves: see that property.
     * 3. **The fields are hashed as a sparse JSON object, and the key order is a rule.** Joining
     *    values with a separator is ambiguous — a body ending where a reference begins
     *    re-partitions into a different pair that concatenates identically — while JSON escapes its
     *    own contents, so no value can be mistaken for the structure around it.
     *
     *    Two properties follow, and both were measured on MariaDB 11.8 and MySQL 8.0.46 rather than
     *    assumed, because the engines implement JSON differently:
     *
     *    - **Keys must be written in length-then-lexical order** (`body` before `public_ref`).
     *      MySQL normalises an object into that order; MariaDB preserves the order it was written
     *      in. Writing them pre-sorted is what makes the two agree — and getting it wrong would
     *      diverge *silently*, per engine, so {@see \Nandan108\InvFlux\Tests\Domain\TermsDigestTest}
     *      asserts the ordering directly.
     *    - **A null field is removed rather than serialised**, which is what lets this table grow.
     *      A field added later contributes nothing to a row that does not carry one, so existing
     *      digests survive the change and every foreign key with them — including a key that sorts
     *      into the *middle* of the existing ones. Without that, adding any column would rebuild a
     *      STORED generated column, recompute every digest, and break every pointer at once.
     * 4. **The key is the first 64 bits of SHA-256, read as a signed big-endian integer.**
     *    `CONV(…, 16, -10)` is that two's-complement reading — the value PHP's `unpack('J')` gives
     *    for the same eight bytes — and signed so the key fits a PHP integer. Measured identical on
     *    MariaDB 11.8, MySQL 8.0 and MySQL 8.4. xxh3, which {@see DocumentParty} uses, has no SQL
     *    function on the engines this supports.
     */
    #[Column(
        ColumnType::BigInt,
        generatedAs: "CAST(CONV(LEFT(SHA2(JSON_REMOVE(JSON_OBJECT('body', `body`, 'attempt', `attempt`, 'public_ref', `public_ref`), IF(`attempt` IS NULL, '$.attempt', '$.__present'), IF(`public_ref` IS NULL, '$.public_ref', '$.__present')), 256), 16), 16, -10) AS SIGNED)",
        generatedMode: GeneratedColumnMode::Stored,
    )]
    public int $content_hash = 0;

    /**
     * The fields inside {@see $content_hash}'s digest, in the order the expression must write them.
     *
     * Kept as data so the ordering rule can be asserted rather than trusted: a new field appended
     * here in the wrong position produces digests that differ between MariaDB and MySQL, which no
     * single-engine test could see.
     */
    public const DIGEST_FIELDS = ['body', 'attempt', 'public_ref'];

    /**
     * How many keys colliding terms may try before the situation is treated as corruption rather
     * than bad luck. A first 64-bit collision among a merchant's few hundred texts is not a real
     * prospect; a run of eight is not a scenario worth designing for beyond failing loudly.
     */
    public const MAX_KEY_ATTEMPTS = 8;

    /** When this text was first interned — never when an order last pointed at it. */
    #[Column(ColumnType::DateTime, precision: 6)]
    #[CreatedAt]
    public ?\DateTimeImmutable $created_at = null;
}
