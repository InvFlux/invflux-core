<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Settings;

/**
 * How strongly an advisory weighs — whether it asks for a decision, or only informs one.
 *
 * Independent of {@see AdvisoryKind}, which says *which* message an advisory is. A value can be
 * advised against strongly enough that the merchant should act ({@see self::Decision}), or only
 * enough that they should know the cost of a choice they may well be right to make
 * ({@see self::Note}).
 *
 * The distinction exists because a count of settings wanting attention is only worth showing if
 * the merchant can drive it to zero. An informed choice with a cost — finishing a job sooner at the
 * price of storefront responsiveness, say — is not something to clear, so counting it would keep a
 * checklist open forever over a decision made correctly.
 *
 * One pairing is refused: an undecided entry is always a decision. It exists to be answered, and a
 * question that never counts would never prompt anyone.
 *
 * @api
 */
enum AdvisorySeverity: string
{
    /**
     * Wants the merchant to decide or change something. Counted wherever settings needing a
     * decision are counted, and listed by the filter that shows them.
     */
    case Decision = 'decision';

    /**
     * Informs a choice without asking for one. Shown on its setting, never counted, and never listed
     * by the needs-a-decision filter.
     */
    case Note = 'note';
}
