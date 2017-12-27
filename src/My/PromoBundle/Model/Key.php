<?php

namespace My\PromoBundle\Model;

/**
 * Key
 */
abstract class Key
{
    /**
     * @var integer
     */
    protected $id;

    /**
     * @var string
     */
    protected $_key;

    /**
     * @var array
     */
    protected $activations_info;

    /**
     * @var \DateTime
     */
    protected $created;

    /**
     * @var \My\PromoBundle\Entity\Campaign
     */
    protected $campaign;


    /**
     * Get id
     *
     * @return integer 
     */
    public function getId()
    {
        return $this->id;
    }

    /**
     * Set _key
     *
     * @param string $key
     * @return Key
     */
    public function setKey($key)
    {
        $this->_key = $key;

        return $this;
    }

    /**
     * Get _key
     *
     * @return string 
     */
    public function getKey()
    {
        return $this->_key;
    }

    /**
     * Set activations_info
     *
     * @param array $activationsInfo
     * @return Key
     */
    public function setActivationsInfo($activationsInfo)
    {
        $this->activations_info = $activationsInfo;

        return $this;
    }

    /**
     * Get activations_info
     *
     * @return array 
     */
    public function getActivationsInfo()
    {
        return $this->activations_info;
    }

    /**
     * Set created
     *
     * @param \DateTime $created
     * @return Key
     */
    public function setCreated($created)
    {
        $this->created = $created;

        return $this;
    }

    /**
     * Get created
     *
     * @return \DateTime 
     */
    public function getCreated()
    {
        return $this->created;
    }

    /**
     * Set campaign
     *
     * @param \My\PromoBundle\Entity\Campaign $campaign
     * @return Key
     */
    public function setCampaign(\My\PromoBundle\Entity\Campaign $campaign = null)
    {
        $this->campaign = $campaign;

        return $this;
    }

    /**
     * Get campaign
     *
     * @return \My\PromoBundle\Entity\Campaign 
     */
    public function getCampaign()
    {
        return $this->campaign;
    }
}
