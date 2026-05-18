<?php

declare(strict_types=1);

namespace App\Modernizers\V2\Rector;

use PhpParser\Node;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\Property;
use Rector\Privatization\NodeManipulator\VisibilityManipulator;
use Rector\Rector\AbstractRector;
use Symplify\RuleDocGenerator\ValueObject\CodeSample\CodeSample;
use Symplify\RuleDocGenerator\ValueObject\RuleDefinition;

/**
 * Normalizes Sloth Model and Taxonomy registration properties to public static without type.
 *
 * ## Background
 *
 * In Sloth v1, registration properties ($names, $options, $labels etc.) could
 * be declared as protected instance properties, because registration was driven
 * by an instance. In Sloth v2, the ModelRegistrar and TaxonomyRegistrar access
 * these properties via static access (`$modelClass::$names`), so they must be
 * `public static`.
 *
 * Additionally, all registration properties must remain untyped. Sloth v2
 * declares them without types in the base class to avoid PHP 8.4 fatal errors
 * caused by Corcel's untyped parent properties. Any type declaration in a
 * child class would cause:
 *
 * > Cannot redeclare property Page::$options with type because
 * > Sloth\Model\Model::$options is not typed
 *
 * ## What this rule does
 *
 * For each known registration property in a class extending `*Model` or `*Taxonomy`:
 *
 * 1. Removes the type declaration (if any)
 * 2. Makes the property `public` (if not already)
 * 3. Makes the property `static` (if not already)
 *
 * Properties that are already `public static` without a type are left untouched.
 * Default values are never modified.
 *
 * ## Example
 *
 * ```php
 * // Before — protected instance property with type (v1 style)
 * class NewsModel extends \Sloth\Model\Model
 * {
 *     protected array $names   = ['singular' => 'News', 'plural' => 'News'];
 *     protected array $options = ['public' => true];
 * }
 *
 * // After — public static without type (v2 style)
 * class NewsModel extends \Sloth\Model\Model
 * {
 *     public static $names   = ['singular' => 'News', 'plural' => 'News'];
 *     public static $options = ['public' => true];
 * }
 * ```
 *
 * @see https://folivoro.com/docs/upgrade/
 * @see \Sloth\Model\Model
 * @see \Sloth\Model\Taxonomy
 */
final class NormalizeSlothRegistrationPropertiesRector extends AbstractRector
{
    /**
     * Registration properties on Sloth\Model\Model that must be public static untyped.
     *
     * These are read via static access by ModelRegistrar:
     * `$modelClass::$names`, `$modelClass::$options` etc.
     *
     * @var list<string>
     */
    private const MODEL_PROPERTIES = [
        'layotter',
        'options',
        'names',
        'labels',
        'icon',
        'register',
        'postType',
        'postTypes',
    ];

    /**
     * Registration properties on Sloth\Model\Taxonomy that must be public static untyped.
     *
     * These are read via static access by TaxonomyRegistrar:
     * `$taxonomyClass::$names`, `$taxonomyClass::$postTypes` etc.
     *
     * @var list<string>
     */
    private const TAXONOMY_PROPERTIES = [
        'postTypes',
        'unique',
        'options',
        'names',
        'labels',
        'register',
    ];

    public function __construct(
        private readonly VisibilityManipulator $visibilityManipulator,
    ) {}

    public function getRuleDefinition(): RuleDefinition
    {
        return new RuleDefinition(
            'Normalize Sloth registration properties to public static without type',
            [
                new CodeSample(
                    <<<'CODE_SAMPLE'
class NewsModel extends \Sloth\Model\Model
{
    protected array $names   = ['singular' => 'News', 'plural' => 'News'];
    protected array $options = ['public' => true];
}
CODE_SAMPLE,
                    <<<'CODE_SAMPLE'
class NewsModel extends \Sloth\Model\Model
{
    public static $names   = ['singular' => 'News', 'plural' => 'News'];
    public static $options = ['public' => true];
}
CODE_SAMPLE
                ),
            ]
        );
    }

    /**
     * @return array<class-string<Node>>
     */
    public function getNodeTypes(): array
    {
        return [Class_::class];
    }

    /**
     * Normalize registration properties to public static without type.
     *
     * Returns the modified node if any changes were made, or null if the
     * class does not extend Model/Taxonomy or all properties are already
     * correctly declared.
     *
     * @param  Class_     $node
     * @return Class_|null
     */
    public function refactor(Node $node): ?Node
    {
        if ($node->extends === null) {
            return null;
        }

        $extendsName = $node->extends->toLowerString();
        $isModel     = str_ends_with($extendsName, 'model');
        $isTaxonomy  = str_ends_with($extendsName, 'taxonomy');

        if (!$isModel && !$isTaxonomy) {
            return null;
        }

        $validProperties = $isModel ? self::MODEL_PROPERTIES : self::TAXONOMY_PROPERTIES;
        $hasChanged      = false;

        foreach ($node->stmts as $stmt) {
            if (!$stmt instanceof Property) {
                continue;
            }

            $propertyName = $this->getName($stmt);

            if ($propertyName === null || !in_array($propertyName, $validProperties, true)) {
                continue;
            }

            if ($stmt->type !== null) {
                $stmt->type = null;
                $hasChanged = true;
            }

            if (!$stmt->isPublic()) {
                $this->visibilityManipulator->makePublic($stmt);
                $hasChanged = true;
            }

            if (!$stmt->isStatic()) {
                $this->visibilityManipulator->makeStatic($stmt);
                $hasChanged = true;
            }
        }

        return $hasChanged ? $node : null;
    }
}
