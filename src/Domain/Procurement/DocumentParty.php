<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Domain\Procurement;

use Nandan108\Attrecord\Attribute\Column;
use Nandan108\Attrecord\Attribute\CreatedAt;
use Nandan108\Attrecord\Attribute\LockTier;
use Nandan108\Attrecord\Attribute\Table;
use Nandan108\Attrecord\Enum\ColumnType;
use Nandan108\Attrecord\Immutable;
use Nandan108\Attrecord\Record;

/**
 * A party **as a document stated it** — name, address, tax identifier, contact — frozen at the
 * moment the document was issued, and shared by every document that stated the same facts.
 *
 * **Why not point documents at the supplier record.** A purchase order is a record of an agreement,
 * not a live view of two address books. Read live, an issued order silently rewrites itself when we
 * move premises, change our tax registration, or the supplier corrects their address — and nothing
 * on either copy says it changed. Every serious ERP freezes a copy at issue: Business Central copies
 * the fields onto the document header, SAP mints a document-specific address number. This is the
 * SAP shape, with one improvement SAP's pre-posting editability forbade it: **the rows are shared.**
 *
 * **Identity is the content — literally: the digest *is* the primary key.** Three consequences, all
 * load-bearing:
 *
 * - **Merging two installations is a union.** A sequential key would have to be renumbered to fold
 *   one install's history into another's, dragging every referencing FK with it. Content-addressed
 *   rows coincide by construction: both installs computed the same key for the same party, so the
 *   merge has nothing to rewrite.
 * - **Dedup is free.** A thousand orders to one supplier at one address share one row; the supplier
 *   moving produces a new row, and the old orders keep pointing at the old one. Interning is an
 *   insert-or-ignore followed by a read-back.
 * - **Immutability is structural, not a convention.** {@see Immutable} refuses every UPDATE at
 *   runtime, but keying by content means an "edit" would also break the row's own identity — there
 *   is simply no coherent way to change a row that many documents share.
 *
 *   **Deleting is a different question, and the marker deliberately permits it.** A row nothing
 *   points at asserts nothing, so removing it breaks no promise the table makes — and it is not
 *   even lossy: content-addressing means re-interning the same facts reproduces the *same* key.
 *   That is why this implements {@see Immutable} rather than {@see \Nandan108\Attrecord\AppendOnly},
 *   which forbids DELETE as well and would put a reaper out of reach of the record layer entirely.
 *   No reaper runs today; what the marker settles is that one *may*.
 *
 * **Extending the fact set does not disturb the keys.** The digest hashes a sparse, self-describing
 * map rather than a positional list, so a fact added later contributes nothing to the key of a party
 * that does not carry one ({@see self::digest()}). Without that, every future column would re-key the
 * whole table at once and break the cross-installation coincidence the first bullet rests on.
 *
 * **A digest is not a proof, and refusing is not an option.** Two different parties whose facts
 * happen to collide would otherwise silently become one row — a document printing someone else's
 * address. At a million rows the odds are about one in 37 million, and merging installations only
 * adds to the population.
 *
 * So interning *verifies* the row it reads back ({@see self::sameFactsAs()}) rather than trusting
 * the key — but a verification that merely threw would leave the merchant permanently unable to
 * issue that order, because the digest is deterministic and retrying reproduces the collision
 * exactly. Instead the newcomer is **re-keyed**: {@see self::rekey()} folds an attempt number into
 * the digest, so the loser of a collision lands on its own key and both parties keep their own
 * facts. The key stays a hash of the content; it is simply the content plus how many parties got
 * there first.
 *
 * Which attempt a row lands on depends on what the table already held, so a colliding row's key is
 * *not* reproducible across installations — the one case where merging has to resolve by content
 * and remap. That is a merge-tool concern and, at these odds, one it will essentially never meet.
 *
 * The hash lives in a **signed** BIGINT holding the raw 64 bits. MySQL's `BIGINT UNSIGNED` would
 * read back as a string for any value above 2^63 (PHP's int is signed), and the round-trip through
 * attrecord's int cast would mangle it; the signed column carries the identical 64 bits with no
 * overflow anywhere.
 *
 * **Facts, not presentation.** Address components, never a formatted block: the formatter is
 * per-country and its country names are localized, so a block rendered while the admin ran in French
 * would pin "Suisse" onto an order later printed in German. The renderer lays these out in the
 * document's own language, exactly as it does for live data.
 *
 * **No provenance on the row, on purpose.** A shared row cannot say "whose" — the same facts may be
 * a supplier on one document, a customer on another, a channel order's ship-to on a third — so
 * provenance belongs to the document that points here (`po.supplier_id`, the order's channel), never
 * to the party. That is also what lets this table serve every document InvFlux issues or ingests:
 * purchase orders today; delivery notes, supplier returns, and the ship-to / bill-to that arrive in a
 * marketplace order's payload — which WooCommerce never holds — as those land.
 *
 * @api
 *
 * @psalm-suppress PossiblyUnusedProperty Properties are hydrated by attrecord from row data.
 */
#[Table(name: 'invflux_document_parties', primaryKey: 'content_hash')]
#[LockTier(27)]
final class DocumentParty extends Record implements Immutable
{
    /**
     * The 64-bit content digest, and the table's **primary key** — there is no surrogate id.
     *
     * A sequential key would have to be *renumbered* to merge one InvFlux installation into
     * another, dragging every FK with it. A content-addressed one makes the merge a union: two
     * installations that ever recorded the same party computed the same key for it, so the rows
     * coincide by construction and nothing has to be rewritten. That is the reason this table has no
     * `id`; the dedup within one install is the same property seen from closer up.
     *
     * Minted by {@see self::of()} before the insert, never by the database. See the class doc for
     * why the column is signed.
     */
    #[Column(ColumnType::BigInt)]
    public int $content_hash = 0;

    #[Column(ColumnType::VarChar, length: 255)]
    public string $name = '';

    /**
     * The person's name in parts, when the party *is* a person rather than an organisation.
     *
     * A supplier states one name — its own — so these stay null there and cost its digest nothing.
     * A customer states two, because the host holds two, and a document that can only print
     * "Jane Smith" cannot address "Ms Smith" or sort by surname. Keeping {@see $name} alongside is
     * not redundancy: it is what a document prints, and it is what an organisation has instead.
     */
    #[Column(ColumnType::VarChar, length: 128, nullable: true)]
    public ?string $first_name = null;

    #[Column(ColumnType::VarChar, length: 128, nullable: true)]
    public ?string $last_name = null;

    /**
     * The company line on a personal address — an employer, a reception desk, a freight forwarder.
     *
     * Distinct from {@see $name} for an organisation party, where the company *is* the name. Here it
     * is a second line the document prints under a person's, and losing it delivers a parcel to a
     * building that does not know who it is for.
     */
    #[Column(ColumnType::VarChar, length: 255, nullable: true)]
    public ?string $company = null;

    /**
     * The street line — **but only the street's name when {@see $building_number} is set.**.
     *
     * That flag is the discriminator, and it exists because the two forms cannot be told apart by
     * looking: `Bahnhofstrasse 12` and `Bahnhofstrasse` are both valid values here. A party captured
     * from a form with one address box carries the whole line and no number; one captured from a form
     * with separate boxes carries the parts. Read it through {@see self::streetLine()} rather than
     * directly, or a party of the second kind prints its street without its number — plausible,
     * deliverable-looking, and wrong.
     */
    #[Column(ColumnType::VarChar, length: 255, nullable: true)]
    public ?string $address_1 = null;

    /**
     * The house number, when a form captured it separately — ISO 20022's `BldgNb`, which the
     * structured address form a bank increasingly requires needs as its own value.
     *
     * **Captured, never parsed.** Splitting a free-text line is guesswork that gets the answer wrong
     * often enough to matter, and here a wrong answer is not a bad label but a different primary key.
     * So this stays null for every party whose source could not offer it — WooCommerce has no such
     * field and will not grow one, so customer parties simply do not carry this, and cost their keys
     * nothing for the lack of it.
     *
     * Where it sits on the printed line is the destination country's business, not this column's:
     * see {@see StreetLine}.
     */
    #[Column(ColumnType::VarChar, length: 16, nullable: true)]
    public ?string $building_number = null;

    #[Column(ColumnType::VarChar, length: 255, nullable: true)]
    public ?string $address_2 = null;

    #[Column(ColumnType::VarChar, length: 100, nullable: true)]
    public ?string $city = null;

    #[Column(ColumnType::VarChar, length: 20, nullable: true)]
    public ?string $postcode = null;

    /** State / region code as the host platform keys it (`GE`, `CA`); never a display name. */
    #[Column(ColumnType::VarChar, length: 100, nullable: true)]
    public ?string $state = null;

    /** ISO-3166 alpha-2 country code; the renderer resolves the localized name. */
    #[Column(ColumnType::VarChar, length: 8, nullable: true)]
    public ?string $country = null;

    /**
     * The party's registration number with its tax authority — load-bearing on a reverse-charge
     * order, where both sides' must appear. The scheme it belongs to is read from {@see $country}
     * when a document names it, never assumed: a VAT number in the EU, a GSTIN in India, a CNPJ in
     * Brazil.
     */
    #[Column(ColumnType::VarChar, length: 64, nullable: true)]
    public ?string $tax_number = null;

    #[Column(ColumnType::VarChar, length: 255, nullable: true)]
    public ?string $contact_name = null;

    #[Column(ColumnType::VarChar, length: 255, nullable: true)]
    public ?string $contact_email = null;

    #[Column(ColumnType::VarChar, length: 64, nullable: true)]
    public ?string $contact_phone = null;

    /** When this combination of facts was first interned — never when a document last pointed at it. */
    #[Column(ColumnType::DateTime, precision: 6)]
    #[CreatedAt]
    public ?\DateTimeImmutable $created_at = null;

    /**
     * Build a party from facts, with its identity computed. The only sanctioned constructor: a
     * record whose hash does not match its columns would be un-findable by content and would defeat
     * the dedup, so the hash is never set by hand.
     *
     * @param array{
     *     address_1?: ?string, building_number?: ?string, address_2?: ?string, city?: ?string,
     *     state?: ?string, postcode?: ?string, country?: ?string,
     * } $address
     */
    public static function of(
        string $name,
        array $address,
        ?string $taxNumber = null,
        ?string $contactName = null,
        ?string $contactEmail = null,
        ?string $contactPhone = null,
        ?string $firstName = null,
        ?string $lastName = null,
        ?string $company = null,
    ): self {
        $party = new self();
        $party->name = trim($name);
        $party->first_name = self::clean($firstName);
        $party->last_name = self::clean($lastName);
        $party->company = self::clean($company);
        $party->address_1 = self::clean($address['address_1'] ?? null);
        $party->building_number = self::clean($address['building_number'] ?? null);
        $party->address_2 = self::clean($address['address_2'] ?? null);
        $party->city = self::clean($address['city'] ?? null);
        $party->postcode = self::clean($address['postcode'] ?? null);
        $party->state = self::clean($address['state'] ?? null);
        $party->country = self::clean($address['country'] ?? null);
        $party->tax_number = self::clean($taxNumber);
        $party->contact_name = self::clean($contactName);
        $party->contact_email = self::clean($contactEmail);
        $party->contact_phone = self::clean($contactPhone);
        $party->content_hash = self::digest($party);

        return $party;
    }

    /**
     * Rebuild a party from the facts {@see self::facts()} produced, at **attempt 0** — the key its
     * content alone implies, regardless of which key the row it came from happens to occupy.
     *
     * This is the round-trip a merge depends on. An incoming row may sit at a re-keyed slot in the
     * installation it came from; interning it here has to start from its content, not from that
     * slot, or the attempt numbering would compound across merges. Rebuilding through this method
     * makes that automatic — and it is the same call an assembler uses to turn live facts into a
     * party, so both paths compute keys identically by construction.
     *
     * @param array{
     *     name?: string,
     *     address?: array{address_1?: ?string, building_number?: ?string, address_2?: ?string, city?: ?string, state?: ?string, postcode?: ?string, country?: ?string},
     *     taxNumber?: ?string, contactName?: ?string, contactEmail?: ?string, contactPhone?: ?string,
     *     firstName?: ?string, lastName?: ?string, company?: ?string,
     * } $facts
     */
    public static function fromFacts(array $facts): self
    {
        return self::of(
            name: $facts['name'] ?? '',
            address: $facts['address'] ?? [],
            taxNumber: $facts['taxNumber'] ?? null,
            contactName: $facts['contactName'] ?? null,
            contactEmail: $facts['contactEmail'] ?? null,
            contactPhone: $facts['contactPhone'] ?? null,
            firstName: $facts['firstName'] ?? null,
            lastName: $facts['lastName'] ?? null,
            company: $facts['company'] ?? null,
        );
    }

    /**
     * The facts back in the shape a document assembler consumes — address as components, keyed the
     * way the host's address formatter expects — so a stored party and a live read render through
     * the same code. Round-trips through {@see self::fromFacts()}.
     *
     * @return array{
     *     name: string,
     *     address: array{address_1: ?string, building_number: ?string, address_2: ?string, city: ?string, state: ?string, postcode: ?string, country: ?string},
     *     taxNumber: ?string, contactName: ?string, contactEmail: ?string, contactPhone: ?string,
     *     firstName: ?string, lastName: ?string, company: ?string,
     * }
     */
    public function facts(): array
    {
        return [
            'name'    => $this->name,
            'address' => [
                'address_1'       => $this->address_1,
                'building_number' => $this->building_number,
                'address_2'       => $this->address_2,
                'city'            => $this->city,
                'state'           => $this->state,
                'postcode'        => $this->postcode,
                'country'         => $this->country,
            ],
            'taxNumber'    => $this->tax_number,
            'contactName'  => $this->contact_name,
            'contactEmail' => $this->contact_email,
            'contactPhone' => $this->contact_phone,
            'firstName'    => $this->first_name,
            'lastName'     => $this->last_name,
            'company'      => $this->company,
        ];
    }

    /**
     * The content digest: every identity-bearing column, through {@see PartyFactNormalizer} and then
     * a 64-bit hash. `xxh3` is not cryptographic and need not be — this is an identity for dedup, not
     * a tamper seal — and it is fast and stable across PHP builds. Big-endian unpack so the integer
     * is the same on every host; the sign bit is just the top bit of the digest.
     *
     * **The values are hashed in a comparable form, not as stated**, so one party written in two
     * hands reaches one row. The columns keep the merchant's spelling; only this computation folds.
     * That projection is therefore part of the identity function: changing a rule in it re-keys
     * every party whose values are not already canonical, which is a migration and not a refactor —
     * see {@see PartyFactNormalizer::VERSION}.
     *
     * **The canonical form is sparse and self-describing**, not a positional list: each fact is
     * hashed under its own name, absent facts are omitted entirely, and the keys are sorted so the
     * order they happen to be written in below cannot matter. That is what makes the *set* of facts
     * extensible. A positional list gives every fact a slot, so appending one shifts the digest of
     * every row ever computed — including parties whose facts never changed, and including the same
     * party on two installations that upgraded at different times, which is exactly the coincidence
     * this table exists to guarantee. With a sparse form, a fact a party does not carry contributes
     * nothing: rows that lack it keep their keys forever, and the rows that gain a value re-key as
     * an ordinary *content* change — the same one a corrected address already produces, which mints
     * a new row and leaves issued documents pointing at the old one.
     *
     * This table is meant to outgrow purchase orders (customers, delivery notes, the ship-to and
     * bill-to arriving in a marketplace payload), so more facts *will* be added. Each must be able
     * to arrive without disturbing the rows that predate it.
     *
     * `$attempt` is 0 for the ordinary key and only ever non-zero to step a colliding party onto a
     * free one. It is hashed in rather than added to the result, so each attempt lands somewhere
     * unrelated instead of walking into whatever occupies the next slot; and it is omitted at 0 so
     * the ordinary key is the content's alone.
     */
    private static function digest(self $party, int $attempt = 0): int
    {
        $facts = PartyFactNormalizer::project($party->identityFacts());

        // `@` cannot begin a column name, so an attempt can never be read as a fact.
        if (0 !== $attempt) {
            $facts['@attempt'] = (string) $attempt;
        }
        ksort($facts);

        $canonical = json_encode($facts, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

        /** @var array{1: int} $unpacked */
        $unpacked = unpack('J', hash('xxh3', $canonical, true));

        return $unpacked[1];
    }

    /**
     * How many distinct keys a colliding party may try before the situation is declared impossible
     * rather than merely improbable. A first collision is about a one-in-37-million event at a
     * million rows; a run of eight is not a scenario worth designing for beyond failing loudly.
     */
    public const MAX_KEY_ATTEMPTS = 8;

    /**
     * Move this party to the key for its n-th attempt, used when attempt n-1 turned out to be
     * occupied by a *different* party. The facts are untouched — only the key changes — so the row
     * still describes exactly what it did before, and a reader that has the key finds it directly.
     */
    public function rekey(int $attempt): void
    {
        $this->content_hash = self::digest($this, $attempt);
    }

    /**
     * The street as a document prints it, in the destination country's word order.
     *
     * **Read this rather than `address_1`.** For a party captured with separate street and number
     * boxes, `address_1` holds only the street's name, so reading it directly prints an address that
     * looks complete and is missing its house number. This is the one call that handles both shapes,
     * and it is a rendering concern deliberately kept out of the digest — see {@see StreetLine}.
     */
    public function streetLine(): string
    {
        return StreetLine::compose($this->address_1, $this->building_number, $this->country);
    }

    /**
     * Every column the key is computed from, in one place.
     *
     * {@see digest()} and {@see sameFactsAs()} must agree on this set exactly. They did not once: the
     * customer facts arrived on the digest and not on the comparison, which left three columns
     * outside the collision check — two parties differing only in `first_name` would have been
     * declared identical and made to share a row, which is precisely the outcome the check exists to
     * prevent. One list, read by both, is what stops that recurring.
     *
     * @return array<string, ?string>
     */
    private function identityFacts(): array
    {
        return [
            'name'            => $this->name,
            'address_1'       => $this->address_1,
            'building_number' => $this->building_number,
            'address_2'       => $this->address_2,
            'city'            => $this->city,
            'postcode'        => $this->postcode,
            'state'           => $this->state,
            'country'         => $this->country,
            'tax_number'      => $this->tax_number,
            'contact_name'    => $this->contact_name,
            'contact_email'   => $this->contact_email,
            'contact_phone'   => $this->contact_phone,
            'first_name'      => $this->first_name,
            'last_name'       => $this->last_name,
            'company'         => $this->company,
        ];
    }

    /**
     * Whether two parties state the same facts — the check that makes trusting a 64-bit digest safe.
     * Compares the stored columns rather than re-hashing, so it catches a genuine collision instead
     * of confirming the arithmetic that produced it.
     *
     * **Compared through {@see PartyFactNormalizer}, on the same projection the digest uses.** Two
     * parties that differ only in case or accents are the *intended* outcome of the fold, not a
     * collision; comparing raw would call them different, re-key the newcomer onto its own row, and
     * undo the dedup the projection exists to produce.
     */
    public function sameFactsAs(self $other): bool
    {
        return PartyFactNormalizer::project($this->identityFacts())
            === PartyFactNormalizer::project($other->identityFacts());
    }

    /** Trim, and treat the empty string as absent — so "" and null intern to the same row. */
    private static function clean(?string $value): ?string
    {
        if (null === $value) {
            return null;
        }
        $value = trim($value);

        return '' === $value ? null : $value;
    }
}
