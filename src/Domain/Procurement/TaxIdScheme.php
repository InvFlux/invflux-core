<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Domain\Procurement;

/**
 * What a party's tax identifier *is* — the scheme it was issued under, not merely its digits.
 *
 * A document has to name the identifier it prints, and no single name serves: an EU supplier reads
 * "VAT", an Indian one "GSTIN", a Brazilian one "CNPJ", and a US buyer has no such number at all —
 * federal registration there is an EIN and sales-tax exemption travels as a separate certificate.
 * Printing "VAT" over a CNPJ is not a translation problem, since a Brazilian reader of an English
 * document is still reading the wrong word.
 *
 * The set is deliberately shallow: it names schemes a purchase order might have to print, and stops
 * short of modelling their internal structure. {@see self::Generic} is the honest answer for a
 * jurisdiction not listed, and reads as "Tax ID" — vague but never wrong.
 *
 * This enum is the vocabulary half of a scheme + value pair. Today a party carries one free-text
 * number and the scheme is *derived* from its country ({@see TaxJurisdiction::idScheme()}); the
 * jurisdictions that genuinely need several identifiers at once — Brazil's CNPJ beside its Inscrição
 * Estadual, Russia's INN beside its KPP, India's per-state GSTINs — need the stored form instead.
 *
 * @api
 */
enum TaxIdScheme: string
{
    /** Value-added-tax registration, the EU/UK pattern and the closest thing to a default worldwide. */
    case Vat = 'vat';

    /** Goods-and-services-tax registration: Australia's, New Zealand's, Singapore's, Malaysia's. */
    case Gst = 'gst';

    /** India: 15 characters, one **per state** a business operates in, with its PAN embedded. */
    case Gstin = 'gstin';

    /** Australia: Business Number, quoted on every tax invoice. */
    case Abn = 'abn';

    /** Canada: Business Number plus its `RT` program suffix — `123456789RT0001`. */
    case Bn = 'bn';

    /** United States: federal Employer Identification Number. Sales tax is registered per state, separately. */
    case Ein = 'ein';

    /** Russia: Taxpayer Identification Number, printed beside the KPP that identifies the branch. */
    case Inn = 'inn';

    /** Brazil: the federal register of legal entities, printed beside a state Inscrição Estadual. */
    case Cnpj = 'cnpj';

    /** Switzerland: the enterprise identification number, `CHE-123.456.789` with a tax-status suffix. */
    case Uid = 'uid';

    /** Mexico: Registro Federal de Contribuyentes. */
    case Rfc = 'rfc';

    /** Gulf states and Egypt: Tax Registration Number. */
    case Trn = 'trn';

    /** China: the 18-character Unified Social Credit Code, as printed on a fapiao. */
    case Uscc = 'uscc';

    /** Japan: the qualified-invoice registration number — `T` plus the 13-digit corporate number. */
    case JapaneseRegistration = 'jp_registration';

    /** Jurisdiction unknown or unlisted: the identifier is printed, named only as a tax ID. */
    case Generic = 'generic';

    /**
     * The scheme's own acronym, where it *is* one and is written the same way in every language —
     * `CNPJ` stays `CNPJ` on a Portuguese and on an English document alike.
     *
     * Null marks the schemes whose name is a translatable word rather than an acronym, and whose
     * label the presentation layer must therefore produce itself: "VAT" is `TVA` / `MwSt` / `BTW`,
     * "Tax ID" likewise, and Japan's is `登録番号` written out.
     */
    public function acronym(): ?string
    {
        return match ($this) {
            self::Vat, self::Generic, self::JapaneseRegistration => null,
            self::Gst                                            => 'GST',
            self::Gstin                                          => 'GSTIN',
            self::Abn                                            => 'ABN',
            self::Bn                                             => 'BN',
            self::Ein                                            => 'EIN',
            self::Inn                                            => 'INN',
            self::Cnpj                                           => 'CNPJ',
            self::Uid                                            => 'UID',
            self::Rfc                                            => 'RFC',
            self::Trn                                            => 'TRN',
            self::Uscc                                           => 'USCC',
        };
    }
}
