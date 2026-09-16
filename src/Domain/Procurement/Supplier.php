<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Domain\Procurement;

use Nandan108\Attrecord\Attribute\Column;
use Nandan108\Attrecord\Attribute\ForeignKey;
use Nandan108\Attrecord\Attribute\LockTier;
use Nandan108\Attrecord\Attribute\Relation;
use Nandan108\Attrecord\Attribute\Table;
use Nandan108\Attrecord\Attribute\UniqueKey;
use Nandan108\Attrecord\Caster\EnumCaster;
use Nandan108\Attrecord\Enum\ColumnType;
use Nandan108\Attrecord\Enum\ForeignKeyAction;
use Nandan108\Attrecord\Enum\RelationType;
use Nandan108\Attrecord\Exception\RecordValidationException;
use Nandan108\Attrecord\Record;
use Nandan108\InvFlux\Identity\ActorRecord;

/**
 * A supplier — a first-class commercial entity **and** a registered actor
 * (`actor_type = 'supplier'`, carried by {@see $actor_id}).
 *
 * Supplier identity on a stock movement is carried by the PO ref on the ledger,
 * not by the location dimension; the `sup` location stays a single coarse
 * supplier-side bucket. Supplier SKUs/barcodes are `subject_identifiers` scoped
 * via `scope_actor_id` = this supplier's actor. The commercial relationship to
 * products lives on {@see SupplierProduct}.
 *
 * Surrogate `id` is `INT UNSIGNED`.
 *
 * @api
 *
 * @psalm-suppress PossiblyUnusedProperty Properties are hydrated by attrecord from row data.
 */
#[Table(name: 'invflux_suppliers')]
#[ForeignKey(
    column: 'terms_lineage_id',
    references: TermsLineage::class,
    onDelete: ForeignKeyAction::Restrict,
    onUpdate: ForeignKeyAction::Restrict,
)]
#[LockTier(30)]
final class Supplier extends Record
{
    #[Column(ColumnType::IntUnsigned, autoIncrement: true)]
    public ?int $id = null;

    /** The supplier's registered actor (`actor_type = 'supplier'`). One actor per supplier. */
    #[Column(ColumnType::IntUnsigned)]
    #[UniqueKey('uq_supplier_actor')]
    public int $actor_id = 0;

    #[Column(ColumnType::VarChar, length: 190)]
    public string $name = '';

    /**
     * Short internal label for a long/unwieldy legal {@see $name} — used wherever
     * horizontal space is tight (badges, the workbench supplier columns, side-by-side
     * cost-comparison column headings). Nullable; {@see displayName()} falls back to the
     * full name when unset. Not unique — a display aid, not an identifier.
     */
    #[Column(ColumnType::VarChar, length: 32, nullable: true)]
    public ?string $nickname = null;

    /**
     * Short internal supplier reference (e.g. "ACME-01") — your code for this supplier in your own
     * books / accounting / ERP, and a handle for search + PO documents. **Unique when set**
     * (nullable: most suppliers have none, and the index allows unlimited NULLs); case-insensitive
     * per the column collation. Enforced now while the table is empty so a future feature that keys
     * on it (accounting match, CSV import upsert) never needs a dedup migration.
     */
    #[Column(ColumnType::VarChar, length: 32, nullable: true)]
    #[UniqueKey('uq_supplier_code')]
    public ?string $code = null;

    /**
     * The supplier's registration number with their tax authority — for PO documents and
     * supplier-invoice reconciliation. Free text, because the *scheme* varies by jurisdiction: a VAT
     * number in the EU, a GSTIN in India, an INN in Russia, a CNPJ in Brazil, an EIN in the US.
     * Documents name it from {@see TaxJurisdiction::idScheme()} on the supplier's country rather
     * than assuming one scheme.
     *
     * One field, so a jurisdiction that issues several at once (Brazil's CNPJ beside its Inscrição
     * Estadual, Russia's INN beside its KPP) has to run them together here.
     */
    #[Column(ColumnType::VarChar, length: 32, nullable: true)]
    public ?string $tax_number = null;

    /** General company contact (info@ / switchboard). Per-person contacts live on {@see SupplierContact}. */
    #[Column(ColumnType::VarChar, length: 190, nullable: true)]
    public ?string $email = null;

    #[Column(ColumnType::VarChar, length: 64, nullable: true)]
    public ?string $phone = null;

    /** Public website URL. */
    #[Column(ColumnType::VarChar, length: 255, nullable: true)]
    public ?string $website = null;

    /** Supplier ordering-portal URL — a destination for the submit-to-supplier flow. */
    #[Column(ColumnType::VarChar, length: 255, nullable: true)]
    public ?string $ordering_url = null;

    // --- Postal address (WC-shaped: alpha-2 country, free-text state) — for PO documents. ---

    /** The street line, or only the street's *name* when {@see $building_number} is set. */
    #[Column(ColumnType::VarChar, length: 190, nullable: true)]
    public ?string $address_1 = null;

    /**
     * The house number as its own value — ISO 20022's `BldgNb`.
     *
     * A supplier is a **creditor**, and a payment instruction increasingly has to state its address
     * in the structured form rather than as free-text lines. That is why this exists here and not on
     * a customer's address: WooCommerce owns those and offers no such field, while this form is ours.
     *
     * Optional, and expected to stay empty for a good share of suppliers — PO boxes, named buildings
     * and rural addresses genuinely have no house number, so a form that *required* this would be
     * unfillable for roughly one address in ten.
     */
    #[Column(ColumnType::VarChar, length: 16, nullable: true)]
    public ?string $building_number = null;

    #[Column(ColumnType::VarChar, length: 190, nullable: true)]
    public ?string $address_2 = null;

    #[Column(ColumnType::VarChar, length: 100, nullable: true)]
    public ?string $city = null;

    #[Column(ColumnType::VarChar, length: 100, nullable: true)]
    public ?string $state = null;

    #[Column(ColumnType::VarChar, length: 20, nullable: true)]
    public ?string $postcode = null;

    /** ISO-3166-1 alpha-2 country code. */
    #[Column(ColumnType::VarChar, length: 2, nullable: true)]
    public ?string $country = null;

    /** ISO-4217 currency code; PO/cost defaults pull from this. */
    #[Column(ColumnType::VarChar, length: 3, nullable: true)]
    public ?string $default_currency = null;

    #[Column(ColumnType::VarChar, length: 64, nullable: true)]
    public ?string $payment_terms = null;

    #[Column(ColumnType::SmallIntUnsigned, nullable: true)]
    public ?int $default_lead_time_days = null;

    /**
     * Decimal places shown/entered for this supplier's unit costs — a display/input precision,
     * not storage (costs are always stored DECIMAL(10,4)). Defaults to 2 (the usual currency
     * minor unit); raise to 3–4 for suppliers whose per-unit costs are fractional (components,
     * bulk goods) or for 3-decimal currencies.
     */
    #[Column(ColumnType::SmallIntUnsigned, default: 2)]
    public int $cost_decimals = 2;

    // --- Pro-tier commercial governance (UI gated by LicenseGate; stored at all tiers). ---

    /**
     * Default purchase VAT/GST rate as a percentage (e.g. "20.00"), pre-filling new POs — the root of
     * the `line ?? po ?? supplier` rate cascade. Whether the tax is *recoverable* (net inventory
     * cost) or capitalizes into cost is a property of the buyer, so it is one install-wide setting
     * and never a supplier field.
     */
    #[Column(ColumnType::Decimal, precision: 5, scale: 2, nullable: true)]
    public ?string $default_tax_rate = null;

    /**
     * Which tax regime applies when buying from this supplier — decides the rate that reaches the
     * order and what the document must state (see {@see SupplierTaxTreatment}). Defaults to the
     * ordinary domestic case, so a merchant who never sells cross-border need not think about it.
     */
    #[Column(ColumnType::Enum, default: SupplierTaxTreatment::Standard)]
    #[EnumCaster(SupplierTaxTreatment::class)]
    public SupplierTaxTreatment $tax_treatment = SupplierTaxTreatment::Standard;

    /**
     * Whether this supplier quotes **tax-inclusive** prices — an input mode for the line-cost field,
     * never a property of the stored value. A gross entry is converted down on save, so `unit_cost`
     * always means the same thing; storing gross-plus-a-flag would oblige the WAC, COGS, the receipt
     * snapshot and every report to apply the flag identically forever.
     */
    #[Column(ColumnType::Bool, default: false)]
    public bool $quotes_include_tax = false;

    /**
     * **Our** account number with this supplier — their customer reference for us, which their
     * accounts-receivable clerk quotes. Distinct from {@see $code}, which is *our* code for *them*;
     * a purchase order usefully carries both, pointing in opposite directions.
     */
    #[Column(ColumnType::VarChar, length: 64, nullable: true)]
    public ?string $account_number = null;

    /**
     * Locale the supplier-facing documents are rendered in (a WP locale such as `de_DE`); null falls
     * back to the store-wide default. Document language is a property of the business partner in
     * every ERP that models it at all — a Geneva merchant ordering from a German supplier wants that
     * order in German, whatever language their own admin is in.
     */
    #[Column(ColumnType::VarChar, length: 12, nullable: true)]
    public ?string $document_language = null;

    /**
     * The set of purchase terms this supplier's orders carry — the middle rung of
     * `order ?? supplier ?? store`; null means the store's set applies.
     *
     * A reference, not text: the terms live in their own table, edited and versioned in one place, so
     * a supplier on a set follows that set as it evolves, and a negotiated variation is a set of its
     * own rather than a paragraph copied here. Removing a set still chosen here is refused.
     */
    #[Column(ColumnType::BigIntUnsigned, nullable: true)]
    public ?int $terms_lineage_id = null;

    /** WP user id of the internal buyer / account manager responsible for this supplier. */
    #[Column(ColumnType::IntUnsigned, nullable: true)]
    public ?int $assigned_to = null;

    // No cancellation-policy text here: {@see $terms_lineage_id} is the one place supplier-level
    // terms are chosen, and it is the rung the purchase-order document actually prints. A second
    // free-text policy field only invites typing into the one that never reaches the supplier.
    //
    // Nor a cancellation *window*. A number of days is the parameter of an automation — chase an
    // unacknowledged order, then propose a cancel — so it is declared here when that workflow
    // exists to read it, not before.

    /**
     * Reserved for merchant-defined custom fields (raw JSON). Field-definition +
     * management is a Procurement Portal power-feature; the column is reserved now
     * so the schema doesn't churn later.
     */
    #[Column(ColumnType::Json, nullable: true)]
    public ?string $metadata_json = null;

    /** ENUM value list derived from the caster's enum. */
    #[Column(ColumnType::Enum, default: SupplierStatus::Active)]
    #[EnumCaster(SupplierStatus::class)]
    public SupplierStatus $status = SupplierStatus::Active;

    #[Column(ColumnType::DateTime, precision: 6)]
    public ?\DateTimeImmutable $created_at = null;

    #[Column(ColumnType::DateTime, precision: 6)]
    public ?\DateTimeImmutable $updated_at = null;

    /**
     * The supplier's facts **as they stand now** — legal name, address, tax number, contact — held
     * as a {@see DocumentParty} content hash rather than as columns here.
     *
     * Those are facts a document *states*, and a document must keep stating what it stated: a
     * purchase order issued last year says the name and address the supplier had last year, whoever
     * they have become since. That is why they live in an interned, immutable row and this record
     * only points at the current one. Editing the supplier's stated facts interns a new party and
     * repoints; the old row survives for as long as any document references it.
     *
     * What stays on this record is what is **ours** and changes at will — `nickname`, `code`, the
     * commercial terms. No document prints those as the party's name.
     *
     * Nullable: a supplier can exist before it has been given any stated facts, and the backfill
     * that populates this has to be re-runnable.
     */
    #[Column(ColumnType::BigInt, nullable: true)]
    public ?int $current_party_id = null;

    /**
     * This supplier's facts in the shape a {@see DocumentParty} is minted from — address as
     * components, never a formatted block.
     *
     * **One definition, deliberately.** Two places need it and they must agree exactly or the
     * content hash diverges: the document assembler, which interns the party a purchase order
     * freezes, and the backfill that gives an existing supplier its {@see $current_party_id}. A
     * second copy would drift silently, because a drifted mapping does not fail — it mints a
     * *different* row that looks perfectly valid, and the dedup the whole table rests on quietly
     * stops happening.
     *
     * `$contact` is the supplier contact a document would address, when one has been chosen; its
     * details take precedence over the supplier's own, which are the fallback. Passing null yields
     * the supplier-level contact details alone.
     *
     * Address components are cast to string rather than passed as null so that a supplier with no
     * address and one with empty-string fields hash alike — they state the same thing.
     *
     * @return array{
     *     name: string,
     *     address: array{address_1: string, building_number: string, address_2: string, city: string, postcode: string, state: string, country: string},
     *     taxNumber: ?string, contactName: ?string, contactEmail: ?string, contactPhone: ?string,
     * }
     */
    public function partyFacts(?SupplierContact $contact = null): array
    {
        return [
            'name'    => $this->name,
            'address' => [
                'address_1'       => (string) $this->address_1,
                'building_number' => (string) $this->building_number,
                'address_2'       => (string) $this->address_2,
                'city'            => (string) $this->city,
                'postcode'        => (string) $this->postcode,
                'state'           => (string) $this->state,
                'country'         => (string) $this->country,
            ],
            'taxNumber'    => $this->tax_number,
            'contactName'  => $contact?->name,
            'contactEmail' => $contact?->email ?? $this->email,
            'contactPhone' => $contact?->phone ?? $this->phone,
        ];
    }

    #[Relation(
        RelationType::ManyToOne,
        class: ActorRecord::class,
        foreignKey: 'actor_id',
        onDelete: ForeignKeyAction::Restrict,
    )]
    public ?ActorRecord $actor = null;

    /**
     * `RESTRICT`, like every other pointer at a party: a row a supplier still points at is not an
     * orphan and the engine must refuse to reap it, atomically and without a check-then-act gap.
     */
    #[Relation(
        RelationType::ManyToOne,
        class: DocumentParty::class,
        foreignKey: 'current_party_id',
        onDelete: ForeignKeyAction::Restrict,
    )]
    public ?DocumentParty $currentParty = null;

    #[\Override]
    public function beforeSave(): void
    {
        $now = new \DateTimeImmutable();
        $this->updated_at = $now;
        if (null === $this->created_at) {
            $this->created_at = $now;
        }
    }

    #[\Override]
    public function validate(): void
    {
        // Note: actor_id is assigned by the repository's create flow (it mints the
        // supplier actor first), so it is intentionally NOT validated here — at
        // construction time it is still 0. The NOT NULL column + FK to invflux_actors
        // enforce a valid actor at persistence time.
        if ('' === trim($this->name)) {
            throw new RecordValidationException(
                'Supplier.name must be a non-empty string.',
                ['field' => 'name'],
            );
        }
        // $status is enum-typed (EnumCaster) — the type system guarantees a valid SupplierStatus.
    }

    /**
     * The label to render: the {@see $nickname} when set, otherwise the full {@see $name}.
     * The one place the nickname-fallback rule lives, so every surface (comparison
     * headings, badges, PO printouts) resolves the display label identically.
     */
    public function displayName(): string
    {
        $nickname = trim((string) $this->nickname);

        return '' !== $nickname ? $nickname : $this->name;
    }
}
