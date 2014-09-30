<?php

namespace Tystr\RedisOrm\Repository;

use Doctrine\Common\Annotations\Annotation;
use Predis\Client;
use Doctrine\Common\Annotations\AnnotationReader;
use ReflectionClass;
use ReflectionProperty;
use DateTime;
use Tystr\RedisOrm\Annotations\Date;
use Tystr\RedisOrm\Annotations\Index;
use Tystr\RedisOrm\Annotations\SortedIndex;
use Tystr\RedisOrm\Criteria\Criteria;
use Tystr\RedisOrm\Criteria\CriteriaInterface;
use Tystr\RedisOrm\Criteria\EqualToInterface;
use Tystr\RedisOrm\Criteria\GreaterThanInterface;
use Tystr\RedisOrm\Criteria\GreaterThanXDaysAgoInterface;
use Tystr\RedisOrm\Criteria\LessThanInterface;
use Tystr\RedisOrm\Criteria\LessThanXDaysAgoInterface;
use Tystr\RedisOrm\DataTransformer\DataTypes;
use Tystr\RedisOrm\DataTransformer\TimestampToDatetimeTransformer;
use Tystr\RedisOrm\Exception\InvalidArgumentException;
use Tystr\RedisOrm\Exception\InvalidCriteriaException;
use Tystr\RedisOrm\Exception\InvalidRestrictionValue;
use Tystr\RedisOrm\Hydrator\ObjectHydrator;
use Tystr\RedisOrm\Hydrator\ObjectHydratorInterface;
use Tystr\RedisOrm\KeyNamingStrategy\KeyNamingStrategyInterface;
use Tystr\RedisOrm\Metadata\AnnotationMetadataLoader;
use Tystr\RedisOrm\Metadata\Metadata;
use Tystr\RedisOrm\Metadata\MetadataRegistry;
use Tystr\RedisOrm\Query\ZRangeByScore;

/**
 * @author Tyler Stroud <tyler@tylerstroud.com>
 */
class ObjectRepository
{
    /**
     * @var string
     */
    protected $className;

    /**
     * @var Client
     */
    protected $redis;

    /**
     * @var KeyNamingStrategyInterface
     */
    protected $keyNamingStrategy;

    /**
     * @var string
     */
    protected $prefix;

    /**
     * @var ObjectHydratorInterface
     */
    protected $hydrator;

    /**
     * @param Client                     $redis
     * @param KeyNamingStrategyInterface $keyNamingStrategy
     * @param string                     $className
     * @param ObjectHydratorInterface    $objectHydrator
     */
    public function __construct(
        Client $redis,
        KeyNamingStrategyInterface $keyNamingStrategy,
        $className,
        ObjectHydratorInterface $objectHydrator = null
    ) {
        $this->redis = $redis;
        $this->keyNamingStrategy = $keyNamingStrategy;
        $this->className = $className;
        $this->hydrator = $objectHydrator ?: new ObjectHydrator();
    }

    /**
     * @param object $object
     */
    public function save($object)
    {
        if (!is_object($object)) {
            throw new InvalidArgumentException(
                sprintf(
                    'You must pass an object to Tystr\RedisOrm\Repository\PredisRepository::save(), %s given.',
                    gettype($object)
                )
            );
        }

        $metadata = $this->getMetadataFor($this->className);
        $key = $this->keyNamingStrategy->getKeyName(array($metadata->getPrefix(), $this->getIdForClass($object, $metadata)));
        $originalData = $this->redis->hgetall($key);
        $this->redis->hmset(
            $key,
            $newData = $this->hydrator->toArray($object, $metadata)
        );
        $this->handleProperties($object, $metadata, $originalData, $newData);
    }

    /**
     * @param mixed $id
     * @return object
     */
    public function find($id)
    {
        $metadata = $this->getMetadataFor($this->className);
        $key = $this->keyNamingStrategy->getKeyName(array($metadata->getPrefix(), $id));
        $data = $this->redis->hgetall($key);
        if (empty($data)) {
            return null;
        }

        return $this->hydrator->hydrate($this->newObject(), $data, $metadata);
    }

    /**
     * @param CriteriaInterface $criteria
     */
    public function count(CriteriaInterface $criteria)
    {
        return $this->findIdsBy($criteria, true);
    }

    /**
     * @param CriteriaInterface $criteria
     * @return array|object[]
     */
    public function findBy(CriteriaInterface $criteria)
    {
        $ids = $this->findIdsBy($criteria);
        $results = array();
        foreach ($ids as $id) {
            $results[] = $this->find($id);
        }

        return $results;
    }

    /**
     * @param CriteriaInterface $criteria
     * @throws InvalidCriteriaException
     * @return array
     */
    public function findIdsBy(CriteriaInterface $criteria, $countOnly = false)
    {
        $keys = array();
        $rangeQueries = array();
        $restrictions = $criteria->getRestrictions();
        if ($restrictions->count() == 0) {
            throw new InvalidCriteriaException('Criteria must have at least 1 restriction, found 0.');
        }

        foreach ($restrictions as $restriction) {
            if ($restriction instanceof EqualToInterface) {
                $keys[] = $this->keyNamingStrategy->getKeyName(array($restriction->getKey(), $restriction->getValue()));
            } elseif ($restriction instanceof LessThanInterface) {
                $key = $restriction->getKey();
                $query = isset($rangeQueries[$key]) ? $rangeQueries[$key] : new ZRangeByScore($key);
                $query->setMax($restriction->getValue());
                $rangeQueries[$key] = $query;
            } elseif ($restriction instanceof GreaterThanInterface) {
                $key = $restriction->getKey();
                $query = isset($rangeQueries[$key]) ? $rangeQueries[$key] : new ZRangeByScore($key);
                $query->setMin($restriction->getValue());
                $rangeQueries[$key] = $query;
            } elseif ($restriction instanceof LessThanXDaysAgoInterface) {
                $key = $restriction->getKey();
                $query = isset($rangeQueries[$key]) ? $rangeQueries[$key] : new ZRangeByScore($key);
                $value = strtotime($restriction->getValue());
                if (false === $value) {
                   throw new InvalidRestrictionValue(
                       sprintf('The value "%s" is not a valid format. Must be similar to "5 days ago" or "1 month 15 days ago".', $restriction->getValue())
                   );
               }
                $query->setMin($value);
                $rangeQueries[$key] = $query;
            } elseif ($restriction instanceof GreaterThanXDaysAgoInterface) {
                $key = $restriction->getKey();
                $query = isset($rangeQueries[$key]) ? $rangeQueries[$key] : new ZRangeByScore($key);
                $value = strtotime($restriction->getValue());
                if (false === $value) {
                    throw new InvalidRestrictionValue(
                        sprintf('The value "%s" is not a valid format. Must be similar to "5 days ago" or "1 month 15 days ago".', $restriction->getValue())
                    );
                }
                $query->setMax($value);
                $rangeQueries[$key] = $query;
            } else {
                throw new \InvalidArgumentException(
                    sprintf(
                        'Either the given restriction is of an invalid type, or the restriction type "%s" has not been implemented.',
                        get_class($restriction)
                    )
                );
            }
        }

        if (count($rangeQueries) == 0) {
            return call_user_func_array(array($this->redis, 'sinter'), array($keys));
        }

        $tmpKey = 'redis-orm:cache:'.md5(time().$criteria->__toString());
        $keys = array_merge($keys, array_keys($rangeQueries));
        array_unshift($keys, $tmpKey, count($keys));
        call_user_func_array(array($this->redis, 'zinterstore'), $keys);

        $this->handleRangeQueries($rangeQueries, $tmpKey);
        $this->redis->expire($tmpKey, 1200);

        if ($countOnly) {
            return $this->redis->zcard($tmpKey);
        }

        return $this->redis->zrange($tmpKey, 0, -1);
    }

    /**
     * @param array  $rangeQueries
     * @param string $key
     * @return int
     */
    protected function handleRangeQueries(array $rangeQueries, $key)
    {
        $totalRemoved = 0;
        foreach ($rangeQueries as $rangeQuery) {
            if (!$rangeQuery instanceof ZRangeByScore) {
                throw new \InvalidArgumentException(
                    sprintf(
                        'Range queries must be instances of "Tystr\RedisOrm\Query\ZRangeByScore", "%s" given.',
                        get_class($rangeQuery)
                    )
                );
            }

            $min = $rangeQuery->getMin();
            if ($min != '-inf') {
                $totalRemoved += $this->redis->zremrangebyscore($key, '-inf', $min);
            }

            $max = $rangeQuery->getMax();
            if ($max != '+inf') {
                $totalRemoved += $this->redis->zremrangebyscore($key, $max, '+inf');
            }
        }

        return $totalRemoved;
    }

    /**
     * @param string $className
     * @return Metadata
     */
    protected function getMetadataFor($className)
    {
        $metadataRegistry = new MetadataRegistry();

        return $metadataRegistry->getMetadataFor($className);
    }

    /**
     * @param object   $object
     * @param Metadata $metadata
     * @param array    $originalData
     * @param array    $newData
     */
    protected function handleProperties($object, Metadata $metadata, array $originalData, array $newData)
    {
        $reflClass = new ReflectionClass($object);
        foreach ($metadata->getIndexes() as $propertyName => $keyName) {
            $this->handleIndex($reflClass, $object, $propertyName, $keyName, $metadata, $originalData);
        }

        foreach ($metadata->getSortedIndexes() as $propertyName => $keyName) {
            $this->handleSortedIndex($reflClass, $object, $propertyName, $keyName, $metadata, $newData);
        }
    }

    /**
     * @param ReflectionClass $reflClass
     * @param object          $object
     * @param string          $propertyName
     * @param Metadata        $metadata
     * @param array           $originalData
     */
    protected function handleIndex(ReflectionClass $reflClass, $object, $propertyName, $keyName, Metadata $metadata, array $originalData)
    {
        $property = $reflClass->getProperty($propertyName);
        $property->setAccessible(true);
        $value = $property->getValue($object);
        $mapping = $metadata->getPropertyMapping($propertyName);
        if (DataTypes::HASH == $mapping['type']) {
            foreach ($value as $key => $val) {
                if ((null === $val && isset($originalData[$mapping['name'].':'.$key])) ||
                    (isset($originalData[$mapping['name'].':'.$key]) &&  $originalData[$mapping['name'].':'.$key] != $val)
                ) {
                    $this->redis->srem(
                        $this->keyNamingStrategy->getKeyName(array($key, $originalData[$mapping['name'].':'.$key])),
                        $this->getIdForClass($object, $metadata)
                    );
                }
                $this->redis->sadd(
                    $this->keyNamingStrategy->getKeyName(array($key, $val)),
                    $this->getIdForClass($object, $metadata)
                );
            }

            return;
        }
        if (null === $value && isset($originalData[$keyName])) {
            $key = $this->keyNamingStrategy->getKeyName(array($keyName, $originalData[$keyName]));
            $this->redis->srem(
                $key,
                $this->getIdForClass($object, $metadata)
            );
        }
        $key = $this->keyNamingStrategy->getKeyName(array($keyName, $value));
        $this->redis->sadd($key, $this->getIdForClass($object, $metadata));
    }

    /**
     * @param ReflectionClass $reflClass
     * @param object          $object
     * @param string          $propertyName
     * @param Metadata        $metadata
     * @param array           $newData
     */
    protected function handleSortedIndex(ReflectionClass $reflClass, $object, $propertyName, $keyName, Metadata $metadata, array $newData)
    {
        $property = $reflClass->getProperty($propertyName);
        $property->setAccessible(true);
        $mapping = $metadata->getPropertyMapping($propertyName);

        if (!isset($newData[$mapping['name']]) || null === $newData[$mapping['name']]) {
            $this->redis->zrem($this->keyNamingStrategy->getKeyName(array($keyName, $newData[$mapping['name']])), $this->getIdForClass($object, $metadata));

            return;
        }

        $this->redis->zadd(
            $this->keyNamingStrategy->getKeyName(array($keyName)),
            $newData[$mapping['name']],
            $this->getIdForClass($object, $metadata)
        );
    }

    /**
     * @param ReflectionClass $reflClass
     * @param Metadata        $metadata
     * @return string|int
     */
    protected function getIdForClass($object, Metadata $metadata)
    {
        $getter = 'get'.ucfirst(strtolower($metadata->getId()));
        if (!method_exists($object, $getter)) {
            throw new \RuntimeException(
                sprintf(
                    'The class "%s" must have a "%s" method for accessing the property mapped as the id field (%s)',
                    get_class($object),
                    $getter,
                    $metadata->getId()
                )
            );
        }

        return $object->$getter();
    }

    /**
     * @return object
     */
    protected function newObject()
    {
        if (version_compare(PHP_VERSION, '5.4') >= 0) {
            $reflClass = new ReflectionClass($this->className);

            return $reflClass->newInstanceWithoutConstructor();
        }

        return unserialize(sprintf('O:%d:"%s":0:{}', strlen($this->className), $this->className));
    }
}
