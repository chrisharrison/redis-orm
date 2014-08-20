<?php

namespace Tystr\RedisOrm\Criteria;

use Doctrine\Common\Collections\Collection;

/**
 * @author Tyler Stroud <tyler@tylerstroud.com>
 */
interface CriteriaInterface
{
    /**
     * @return array|Restriction[]
     */
    public function getRestrictions();

    /**
     * @param Collection $restrictions
     */
    public function setRestrictions(Collection $restrictions);

    /**
     * @param Restriction $restriction
     */
    public function addRestriction(Restriction $restriction);

    /**
     * @param Restriction $restriction
     */
    public function removeRestriction(Restriction $restriction);

    /**
     * @param Restriction $restriction
     * @return bool
     */
    public function hasRestriction(Restriction $restriction);

    /**
     * @return string
     */
    public function __toString();
}