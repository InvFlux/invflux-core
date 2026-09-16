<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Settings;

/**
 * The advice a {@see SettingDefinition} offers about the value a merchant currently has.
 *
 * Produced by the definition's advisory closure, which is handed the **effective value**
 * and returns one of these or `null`. Because the question is asked of the value rather
 * than of the setting, saving a different value clears the advice through the ordinary
 * read path, with no per-setting code and nothing to invalidate.
 *
 * The message is built inside that closure, at request time, which is what makes it
 * translatable: the settings catalog is assembled early enough that a translation call in
 * its factory would fire before the text domain is loaded.
 *
 * @api
 */
final class SettingAdvisory
{
    /**
     * @param AdvisoryKind     $kind     which message this is — a value advised against, or a
     *                                   decision nobody has made yet; consumers word the two differently
     * @param string           $message  merchant-facing, already translated by the caller
     * @param AdvisorySeverity $severity whether it asks for a decision (counted) or only informs one
     *                                   (shown, never counted); an undecided entry is always a decision
     */
    public function __construct(
        public readonly AdvisoryKind $kind,
        public readonly string $message,
        public readonly AdvisorySeverity $severity = AdvisorySeverity::Decision,
    ) {
        if ('' === trim($message)) {
            throw new \InvalidArgumentException(
                'SettingAdvisory.message must be non-empty — an advisory with nothing to say '
                .'still occupies a place on the page, and in the count if it asks for a decision.',
            );
        }

        if (AdvisoryKind::Undecided === $kind && AdvisorySeverity::Decision !== $severity) {
            throw new \InvalidArgumentException(
                'An undecided SettingAdvisory must be a decision — it exists to be answered, and a '
                .'question that is never counted never prompts anyone.',
            );
        }
    }
}
