<?php declare(strict_types=1);

namespace Shopware\Api\Entity\Dbal;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use Shopware\Api\Entity\EntityDefinition;
use Shopware\Api\Entity\Field\AssociationInterface;
use Shopware\Api\Entity\Field\ManyToManyAssociationField;
use Shopware\Api\Entity\Field\ManyToOneAssociationField;
use Shopware\Api\Entity\Field\OneToManyAssociationField;
use Shopware\Api\Entity\FieldCollection;
use Shopware\Api\Entity\Write\Flag\CascadeDelete;
use Shopware\Api\Entity\Write\Flag\RestrictDelete;
use Shopware\Context\Struct\ShopContext;
use Shopware\Defaults;

class EntityForeignKeyResolver
{
    /**
     * @var Connection
     */
    private $connection;

    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
    }

    /**
     * Returns an associated nested array which contains all affected restrictions of the provided ids.
     * Example:
     *  [
     *      [
     *          'pk' => '43c6baad-7561-40d8-aabb-bca533a8284f'
     *          restrictions => [
     *              'Shopware\Api\Shop\Definition\ShopDefinition' => [
     *                  '1ffd7ea9-58c6-4355-8256-927aae8efb07',
     *                  '1ffd7ea9-58c6-4355-8256-927aae8efb07'
     *              ]
     *          ]
     *      ]
     *  ]
     *
     * @param EntityDefinition|string $definition
     * @param array                   $ids
     *
     * @throws \RuntimeException
     *
     * @return array
     */
    public function getAffectedDeleteRestrictions(string $definition, array $ids, ShopContext $context): array
    {
        return $this->fetch($definition, $ids, RestrictDelete::class, $context);
    }

    /**
     * Returns an associated nested array which contains all affected cascaded delete entities.
     * Example:
     *  [
     *      [
     *          'pk' => '43c6baad-7561-40d8-aabb-bca533a8284f'
     *          restrictions => [
     *              'Shopware\Api\Shop\Definition\ShopDefinition' => [
     *                  '1ffd7ea9-58c6-4355-8256-927aae8efb07',
     *                  '1ffd7ea9-58c6-4355-8256-927aae8efb07'
     *              ]
     *          ]
     *      ]
     *  ]
     *
     * @param EntityDefinition|string $definition
     * @param array                   $ids
     *
     * @throws \RuntimeException
     *
     * @return array
     */
    public function getAffectedDeletes(string $definition, array $ids, ShopContext $context): array
    {
        return $this->fetch($definition, $ids, CascadeDelete::class, $context);
    }

    /**
     * @param EntityDefinition|string $definition
     * @param array                   $ids
     *
     * @throws \RuntimeException
     *
     * @return array
     */
    private function fetch(string $definition, array $ids, string $class, ShopContext $context): array
    {
        if ($context->getVersionId() !== Defaults::LIVE_VERSION) {
            return [];
        }

        if (!$definition::getFields()->has('id')) {
            return [];
        }

        $query = new QueryBuilder($this->connection);

        $root = $definition::getEntityName();
        $rootAlias = EntityDefinitionQueryHelper::escape($definition::getEntityName());

        $query->from(EntityDefinitionQueryHelper::escape($definition::getEntityName()), $rootAlias);
        $query->addSelect($rootAlias . '.id as root');

        $cascades = $definition::getFields()->filterByFlag($class);

        $this->joinCascades($definition, $cascades, $root, $query, $class, $context);

        $this->addWhere($ids, $rootAlias, $query);

        $query->setParameter('version', Uuid::fromString($context->getVersionId())->getBytes());
        $query->setParameter('liveVersion', Uuid::fromString(Defaults::LIVE_VERSION)->getBytes());

        $result = $query->execute()->fetchAll(\PDO::FETCH_GROUP | \PDO::FETCH_UNIQUE);

        return $this->extractValues($definition, $result, $root);
    }

    private function joinCascades(string $definition, FieldCollection $cascades, string $root, QueryBuilder $query, string $class, ShopContext $context): void
    {
        foreach ($cascades as $cascade) {
            $alias = $root . '.' . $cascade->getPropertyName();

            if ($cascade instanceof OneToManyAssociationField) {
                EntityDefinitionQueryHelper::joinOneToMany($definition, $root, $cascade, $query, $context);

                $query->addSelect(
                    'GROUP_CONCAT(HEX(' .
                    EntityDefinitionQueryHelper::escape($alias) . '.id)' .
                    ' SEPARATOR \'||\')  as ' . EntityDefinitionQueryHelper::escape($alias)
                );
            }

            if ($cascade instanceof ManyToManyAssociationField) {
                $mappingAlias = $root . '.' . $cascade->getPropertyName() . '.mapping';

                EntityDefinitionQueryHelper::joinManyToMany($definition, $root, $cascade, $query, $context);

                $query->addSelect(
                    'GROUP_CONCAT(HEX(' .
                    EntityDefinitionQueryHelper::escape($mappingAlias) . '.' . $cascade->getMappingReferenceColumn() .
                    ') SEPARATOR \'||\')  as ' . EntityDefinitionQueryHelper::escape($alias)
                );
                continue;
            }

            if ($cascade instanceof ManyToOneAssociationField) {
                EntityDefinitionQueryHelper::joinManyToOne($definition, $root, $cascade, $query, $context);

                $query->addSelect(
                    'GROUP_CONCAT(HEX(' .
                    EntityDefinitionQueryHelper::escape($alias) . '.id)' .
                    ' SEPARATOR \'||\')  as ' . EntityDefinitionQueryHelper::escape($alias)
                );
            }

            //avoid infinite recursive call
            if ($cascade->getReferenceClass() === $definition) {
                continue;
            }
            $nested = $cascade->getReferenceClass()::getFields()->filterByFlag($class);

            $this->joinCascades($cascade->getReferenceClass(), $nested, $alias, $query, $class, $context);
        }
    }

    private function addWhere(array $ids, string $rootAlias, QueryBuilder $query): void
    {
        $counter = 1;
        $group = null;
        foreach ($ids as $pk) {
            $part = [];
            $group = array_keys($pk);
            foreach ($pk as $key => $value) {
                $param = 'param' . $counter;

                $part[] = sprintf(
                    '%s.%s = :%s',
                    $rootAlias,
                    EntityDefinitionQueryHelper::escape($key),
                    $param
                );

                $query->setParameter($param, Uuid::fromString($value)->getBytes());
                ++$counter;
            }
            $query->orWhere(implode(' AND ', $part));
        }

        foreach ($group as $column) {
            $query->addGroupBy($rootAlias . '.' . EntityDefinitionQueryHelper::escape($column));
        }
    }

    private function extractValues(string $definition, array $result, string $root): array
    {
        $mapped = [];

        foreach ($result as $pk => $row) {
            $pk = Uuid::fromBytes($pk)->toString();

            $restrictions = [];

            foreach ($row as $key => $value) {
                $value = array_filter(explode('||', (string) $value));
                if (empty($value)) {
                    continue;
                }

                $value = array_map(
                    function ($id) {
                        return Uuid::fromString($id)->toString();
                    },
                    $value
                );

                $field = EntityDefinitionQueryHelper::getField($key, $definition, $root);

                if (!$field) {
                    throw new \RuntimeException(sprintf('Field by key %s not found', $key));
                }

                /** @var AssociationInterface $field */
                if ($field instanceof ManyToManyAssociationField) {
                    $class = $field->getMappingDefinition();

                    if (!array_key_exists($class, $restrictions)) {
                        $restrictions[$class] = [];
                    }

                    $sourceProperty = $class::getFields()->getByStorageName(
                        $field->getMappingLocalColumn()
                    );
                    $targetProperty = $class::getFields()->getByStorageName(
                        $field->getMappingReferenceColumn()
                    );

                    foreach ($value as $nested) {
                        $restrictions[$class][] = [
                            $sourceProperty->getPropertyName() => $pk,
                            $targetProperty->getPropertyName() => $nested,
                        ];
                    }

                    continue;
                }

                $class = $field->getReferenceClass();

                if (!array_key_exists($class, $restrictions)) {
                    $restrictions[$class] = [];
                }

                $restrictions[$class] = array_merge_recursive($restrictions[$class], $value);
            }

            if (empty($restrictions)) {
                continue;
            }
            $mapped[] = ['pk' => $pk, 'restrictions' => $restrictions];
        }

        return array_values($mapped);
    }
}