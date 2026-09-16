<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Schema;

use Nandan108\InvFlux\Exceptions\ConfigurationException;

/**
 * Describe one dimension in a declarative slot-space schema.
 *
 * @api
 */
final class DimensionDefinition
{
    /** @var list<non-empty-string>|null */
    private ?array $resolvedValuesAsList = null;

    /** @var list<non-empty-string>|null */
    private ?array $resolvedActiveValuesAsList = null;

    /** @var array<non-empty-string, DimensionValueDefinition>|null */
    private ?array $resolvedValuesByCode = null;

    /** @var array<non-empty-string, DimensionValueDefinition>|null */
    private ?array $resolvedActiveValuesByCode = null;

    public readonly bool $hasHierarchy;

    /**
     * Build one dimension definition.
     *
     * @param list<DimensionValueDefinition> $values
     * @param array<string, mixed>           $metadata
     */
    public function __construct(
        public readonly string $name,
        public readonly int $position,
        public readonly array $values,
        public readonly string $defaultValue,
        public readonly DimensionKind $kind = DimensionKind::Partition,
        public readonly CollapseBehavior $collapseBehavior = CollapseBehavior::Aggregate,
        public readonly ?string $collapseTargetValue = null,
        public readonly bool $active = true,
        public readonly array $metadata = [],
        public readonly bool $isSharedRef = false,
        public readonly ?DimensionValueSelector $valueSelector = null,
    ) {
        if ('' === $name) {
            throw new ConfigurationException('Dimension name must be a non-empty string.', 'empty_dimension_name');
        }

        if ($this->position < 0) {
            throw new ConfigurationException('Dimension position must be >= 0.', 'negative_dimension_position');
        }

        if ($this->isSharedRef) {
            $this->hasHierarchy = false;

            return;
        }

        if ([] === $this->values) {
            throw new ConfigurationException('Dimension must define at least one value.', 'empty_dimension_values');
        }

        $declaredCodes = $this->allValuesAsList();
        if (count($declaredCodes) !== count(array_unique($declaredCodes))) {
            throw new ConfigurationException(
                'Dimension value codes must be unique within one dimension.',
                'duplicate_dimension_values',
                ['dimensionName' => $this->name],
            );
        }

        if (!in_array($this->defaultValue, $declaredCodes, true)) {
            throw new ConfigurationException(sprintf(
                'Dimension default value "%s" must be one of the declared value codes.',
                $this->defaultValue,
            ), 'invalid_dimension_default', ['defaultValue' => $this->defaultValue]);
        }

        $activeValues = $this->valuesAsList();
        if ([] === $activeValues) {
            throw new ConfigurationException(
                'Dimension must define at least one active value.',
                'empty_active_dimension_values',
                ['dimensionName' => $this->name],
            );
        }

        if (!in_array($this->defaultValue, $activeValues, true)) {
            throw new ConfigurationException(
                sprintf('Dimension default value "%s" must be active.', $this->defaultValue),
                'inactive_dimension_default',
                ['defaultValue' => $this->defaultValue],
            );
        }

        if (CollapseBehavior::MapToValue === $this->collapseBehavior && null === $this->collapseTargetValue) {
            throw new ConfigurationException(
                'collapseTargetValue is required for map_to_value dimensions.',
                'missing_collapse_target_value',
            );
        }

        if (
            CollapseBehavior::MapToValue === $this->collapseBehavior
            && !in_array($this->collapseTargetValue, $declaredCodes, true)
        ) {
            throw new ConfigurationException(
                sprintf(
                    'collapseTargetValue "%s" must be one of the declared dimension values.',
                    $this->collapseTargetValue,
                ),
                'invalid_collapse_target_value',
                ['collapseTargetValue' => $this->collapseTargetValue],
            );
        }

        foreach ($this->values as $value) {
            if (null !== $value->removalTargetCode && !in_array($value->removalTargetCode, $declaredCodes, true)) {
                throw new ConfigurationException(
                    sprintf(
                        'Dimension value removalTargetCode "%s" must be one of the declared dimension value codes.',
                        $value->removalTargetCode,
                    ),
                    'invalid_value_removal_target',
                    ['code' => $value->code, 'removalTargetCode' => $value->removalTargetCode],
                );
            }

            if ($value->code === $value->removalTargetCode) {
                throw new ConfigurationException(
                    sprintf('Dimension value code "%s" cannot map to itself on removal.', $value->code),
                    'self_mapped_value_removal_target',
                    ['code' => $value->code],
                );
            }
        }

        $this->hasHierarchy = [] !== array_filter(
            $this->values,
            static fn (DimensionValueDefinition $v): bool => null !== $v->parentCode,
        );
    }

    /**
     * Return the declared values as a plain ordered list.
     *
     * @return list<non-empty-string>
     */
    public function valuesAsList(): array
    {
        if (null !== $this->resolvedActiveValuesAsList) {
            return $this->resolvedActiveValuesAsList;
        }

        $values = array_values(array_map(
            static fn (DimensionValueDefinition $value): string => $value->code,
            array_filter(
                $this->values,
                static fn (DimensionValueDefinition $value): bool => $value->active,
            ),
        ));

        /** @var list<non-empty-string> $values */
        return $this->resolvedActiveValuesAsList = $values;
    }

    /**
     * Return all declared values, including inactive legacy values.
     *
     * @return list<non-empty-string>
     */
    public function allValuesAsList(): array
    {
        if (null !== $this->resolvedValuesAsList) {
            return $this->resolvedValuesAsList;
        }

        $values = array_map(
            static fn (DimensionValueDefinition $value): string => $value->code,
            $this->values,
        );

        /** @var list<non-empty-string> $values */
        return $this->resolvedValuesAsList = $values;
    }

    /**
     * Return declared values keyed by code, or one value when a code is supplied.
     *
     * @psalm-return ($code is null ? array<non-empty-string, DimensionValueDefinition> : ?DimensionValueDefinition)
     */
    public function valuesByCode(?string $code = null, bool $includeInactive = false): array | DimensionValueDefinition | null
    {
        $values = $includeInactive ? $this->allValuesByCode() : $this->activeValuesByCode();

        if (null === $code) {
            return $values;
        }

        return $values[$code] ?? null;
    }

    /**
     * Build a dimension definition whose values are loaded from storage at bootstrap time.
     *
     * The valueSelector controls which subset of the dimension hierarchy this layer addresses:
     * root-only, a named structural level (e.g. 'warehouse'), or leaf/addressable values only.
     */
    public static function sharedRef(
        string $name,
        int $position,
        DimensionValueSelector $valueSelector,
    ): self {
        return new self(
            name: $name,
            position: $position,
            values: [],
            defaultValue: '',
            isSharedRef: true,
            valueSelector: $valueSelector,
        );
    }

    /**
     * Return a resolved copy of this sharedRef dimension with concrete values loaded from storage.
     *
     * @param list<DimensionValueDefinition> $values
     */
    public function withResolvedValues(array $values, string $defaultValue): self
    {
        return new self(
            name: $this->name,
            position: $this->position,
            values: $values,
            defaultValue: $defaultValue,
            kind: $this->kind,
            collapseBehavior: $this->collapseBehavior,
            collapseTargetValue: $this->collapseTargetValue,
            active: $this->active,
            metadata: $this->metadata,
            isSharedRef: false,
            valueSelector: $this->valueSelector,
        );
    }

    /**
     * Build one dimension definition from a simple value list.
     *
     * @param list<non-empty-string> $values
     * @param array<string, mixed>   $metadata
     */
    public static function define(
        string $name,
        array $values,
        int $position = 0,
        ?string $defaultValue = null,
        DimensionKind $kind = DimensionKind::Partition,
        CollapseBehavior $collapseBehavior = CollapseBehavior::Aggregate,
        ?string $collapseTargetValue = null,
        bool $active = true,
        array $metadata = [],
    ): self {
        $defaultValue ??= $values[0] ?? null;

        return new self(
            $name,
            $position,
            array_map(
                static fn (string $value): DimensionValueDefinition => new DimensionValueDefinition($value),
                $values,
            ),
            $defaultValue ?? throw new ConfigurationException('Dimension must define a default value.', 'missing_dimension_default'),
            $kind,
            $collapseBehavior,
            $collapseTargetValue,
            $active,
            $metadata,
        );
    }

    /**
     * @return array<non-empty-string, DimensionValueDefinition>
     */
    private function activeValuesByCode(): array
    {
        if (null !== $this->resolvedActiveValuesByCode) {
            return $this->resolvedActiveValuesByCode;
        }

        $values = [];
        foreach ($this->values as $value) {
            if ($value->active) {
                $values[$value->code] = $value;
            }
        }

        /** @var array<non-empty-string, DimensionValueDefinition> $values */
        return $this->resolvedActiveValuesByCode = $values;
    }

    /**
     * @return array<non-empty-string, DimensionValueDefinition>
     */
    private function allValuesByCode(): array
    {
        if (null !== $this->resolvedValuesByCode) {
            return $this->resolvedValuesByCode;
        }

        $values = [];
        foreach ($this->values as $value) {
            $values[$value->code] = $value;
        }

        /** @var array<non-empty-string, DimensionValueDefinition> $values */
        return $this->resolvedValuesByCode = $values;
    }
}
