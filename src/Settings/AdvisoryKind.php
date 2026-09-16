<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Settings;

/**
 * What an advisory is telling the merchant about their current value.
 *
 * The two kinds clear identically and count identically — the mechanism does not
 * distinguish them. The **copy** must, because they are different messages, and a
 * consumer rendering one in the other's voice is the failure this enum exists to
 * prevent: an undecided setting written like a fault turns a setup checklist into
 * an accusation.
 *
 * @api
 */
enum AdvisoryKind: string
{
    /**
     * One answer is right and the current one has a defect. The message says what
     * breaks.
     */
    case WrongValue = 'wrong_value';

    /**
     * Either answer is fine; the current one is the host's rather than the
     * merchant's. The message presents the choice and what each answer implies —
     * and does not scold, because nothing has gone wrong yet.
     *
     * A setting of this kind carries an explicit third value meaning *asked and
     * answered*, which is its default and the only value that trips the advisory.
     * Both real answers clear it, including the one matching the host's behaviour.
     */
    case Undecided = 'undecided';
}
