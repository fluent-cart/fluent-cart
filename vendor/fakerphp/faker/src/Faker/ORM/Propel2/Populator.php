<?php

namespace FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\Faker\ORM\Propel2;

use FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\Propel\Runtime\Propel;
use FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\Propel\Runtime\ServiceContainer\ServiceContainerInterface;
/**
 * Service class for populating a database using the Propel ORM.
 * A Populator can populate several tables using ActiveRecord classes.
 */
class Populator
{
    protected $generator;
    protected $entities = [];
    protected $quantities = [];
    public function __construct(\FluentCart\FluentCart\FluentCart\FluentCart\Faker\Generator $generator)
    {
        $this->generator = $generator;
    }
    /**
     * Add an order for the generation of $number records for $entity.
     *
     * @param mixed $entity A Propel ActiveRecord classname, or a \Faker\ORM\Propel2\EntityPopulator instance
     * @param int   $number The number of entities to populate
     */
    public function addEntity($entity, $number, $customColumnFormatters = [], $customModifiers = [])
    {
        if (!$entity instanceof \FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\Faker\ORM\Propel2\EntityPopulator) {
            $entity = new \FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\Faker\ORM\Propel2\EntityPopulator($entity);
        }
        $entity->setColumnFormatters($entity->guessColumnFormatters($this->generator));
        if ($customColumnFormatters) {
            $entity->mergeColumnFormattersWith($customColumnFormatters);
        }
        $entity->setModifiers($entity->guessModifiers($this->generator));
        if ($customModifiers) {
            $entity->mergeModifiersWith($customModifiers);
        }
        $class = $entity->getClass();
        $this->entities[$class] = $entity;
        $this->quantities[$class] = $number;
    }
    /**
     * Populate the database using all the Entity classes previously added.
     *
     * @param PropelPDO $con A Propel connection object
     *
     * @return array A list of the inserted PKs
     */
    public function execute($con = null)
    {
        if (null === $con) {
            $con = $this->getConnection();
        }
        $isInstancePoolingEnabled = \FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\Propel\Runtime\Propel::isInstancePoolingEnabled();
        \FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\Propel\Runtime\Propel::disableInstancePooling();
        $insertedEntities = [];
        $con->beginTransaction();
        foreach ($this->quantities as $class => $number) {
            for ($i = 0; $i < $number; ++$i) {
                $insertedEntities[$class][] = $this->entities[$class]->execute($con, $insertedEntities);
            }
        }
        $con->commit();
        if ($isInstancePoolingEnabled) {
            \FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\Propel\Runtime\Propel::enableInstancePooling();
        }
        return $insertedEntities;
    }
    protected function getConnection()
    {
        // use the first connection available
        $class = key($this->entities);
        if (!$class) {
            throw new \RuntimeException('No class found from entities. Did you add entities to the Populator ?');
        }
        $peer = $class::TABLE_MAP;
        return \FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\Propel\Runtime\Propel::getConnection($peer::DATABASE_NAME, \FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\Propel\Runtime\ServiceContainer\ServiceContainerInterface::CONNECTION_WRITE);
    }
}