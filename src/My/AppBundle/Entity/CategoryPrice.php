<?php

namespace My\AppBundle\Entity;

use My\AppBundle\Model\CategoryPrice as CategoryPriceModel;

class CategoryPrice extends CategoryPriceModel
{
    protected $active = true;
    protected $base = 0;
    protected $teor = 0;
    protected $offline_1 = 0;
    protected $offline_2 = 0;
    protected $online_onetime = 0;
    protected $online_1 = 0;
    protected $online_2 = 0;

    public function getOfflineOnetime()
    {
        return $this->getOffline1() + $this->getOffline2();
    }

    public static function getSumView($sum)
    {
        return $sum > 1000 ? (floor($sum/1000).' '.sprintf('%03d', $sum%1000)) : $sum;
    }
}
