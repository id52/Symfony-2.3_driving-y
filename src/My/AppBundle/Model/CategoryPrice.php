<?php

namespace My\AppBundle\Model;

/**
 * CategoryPrice
 */
abstract class CategoryPrice
{
    /**
     * @var boolean
     */
    protected $active;

    /**
     * @var integer
     */
    protected $base;

    /**
     * @var integer
     */
    protected $teor;

    /**
     * @var integer
     */
    protected $offline_1;

    /**
     * @var integer
     */
    protected $offline_2;

    /**
     * @var integer
     */
    protected $online_onetime;

    /**
     * @var integer
     */
    protected $online_1;

    /**
     * @var integer
     */
    protected $online_2;

    /**
     * @var \My\AppBundle\Entity\Category
     */
    protected $category;

    /**
     * @var \My\AppBundle\Entity\Region
     */
    protected $region;


    /**
     * Set active
     *
     * @param boolean $active
     * @return CategoryPrice
     */
    public function setActive($active)
    {
        $this->active = $active;

        return $this;
    }

    /**
     * Get active
     *
     * @return boolean 
     */
    public function getActive()
    {
        return $this->active;
    }

    /**
     * Set base
     *
     * @param integer $base
     * @return CategoryPrice
     */
    public function setBase($base)
    {
        $this->base = $base;

        return $this;
    }

    /**
     * Get base
     *
     * @return integer 
     */
    public function getBase()
    {
        return $this->base;
    }

    /**
     * Set teor
     *
     * @param integer $teor
     * @return CategoryPrice
     */
    public function setTeor($teor)
    {
        $this->teor = $teor;

        return $this;
    }

    /**
     * Get teor
     *
     * @return integer 
     */
    public function getTeor()
    {
        return $this->teor;
    }

    /**
     * Set offline_1
     *
     * @param integer $offline1
     * @return CategoryPrice
     */
    public function setOffline1($offline1)
    {
        $this->offline_1 = $offline1;

        return $this;
    }

    /**
     * Get offline_1
     *
     * @return integer 
     */
    public function getOffline1()
    {
        return $this->offline_1;
    }

    /**
     * Set offline_2
     *
     * @param integer $offline2
     * @return CategoryPrice
     */
    public function setOffline2($offline2)
    {
        $this->offline_2 = $offline2;

        return $this;
    }

    /**
     * Get offline_2
     *
     * @return integer 
     */
    public function getOffline2()
    {
        return $this->offline_2;
    }

    /**
     * Set online_onetime
     *
     * @param integer $onlineOnetime
     * @return CategoryPrice
     */
    public function setOnlineOnetime($onlineOnetime)
    {
        $this->online_onetime = $onlineOnetime;

        return $this;
    }

    /**
     * Get online_onetime
     *
     * @return integer 
     */
    public function getOnlineOnetime()
    {
        return $this->online_onetime;
    }

    /**
     * Set online_1
     *
     * @param integer $online1
     * @return CategoryPrice
     */
    public function setOnline1($online1)
    {
        $this->online_1 = $online1;

        return $this;
    }

    /**
     * Get online_1
     *
     * @return integer 
     */
    public function getOnline1()
    {
        return $this->online_1;
    }

    /**
     * Set online_2
     *
     * @param integer $online2
     * @return CategoryPrice
     */
    public function setOnline2($online2)
    {
        $this->online_2 = $online2;

        return $this;
    }

    /**
     * Get online_2
     *
     * @return integer 
     */
    public function getOnline2()
    {
        return $this->online_2;
    }

    /**
     * Set category
     *
     * @param \My\AppBundle\Entity\Category $category
     * @return CategoryPrice
     */
    public function setCategory(\My\AppBundle\Entity\Category $category)
    {
        $this->category = $category;

        return $this;
    }

    /**
     * Get category
     *
     * @return \My\AppBundle\Entity\Category 
     */
    public function getCategory()
    {
        return $this->category;
    }

    /**
     * Set region
     *
     * @param \My\AppBundle\Entity\Region $region
     * @return CategoryPrice
     */
    public function setRegion(\My\AppBundle\Entity\Region $region)
    {
        $this->region = $region;

        return $this;
    }

    /**
     * Get region
     *
     * @return \My\AppBundle\Entity\Region 
     */
    public function getRegion()
    {
        return $this->region;
    }
}
