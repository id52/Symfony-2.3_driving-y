<?php

namespace My\AppBundle\Entity;

use My\AppBundle\Model\Offer as OfferModel;

class Offer extends OfferModel
{
    protected $is_public = false;

    public function getImageId()
    {
        return $this->getImage() ? $this->getImage()->getId() : null;
    }

    public function setImageId($imageId)
    {
    }

    public function getPrice($at, $region_id, $category_id)
    {
        if (!is_null($at) && $region_id && $category_id) {
            $prices = $this->getPrices();
            foreach ($prices as $price) { /** @var $price \My\AppBundle\Entity\OfferPrice */
                if ($at == $price->getAt()
                    && $region_id == $price->getRegion()->getId()
                    && $category_id == $price->getCategory()->getId()
                ) {
                    return $price->getPrice();
                }
            }
        }
        return 0;
    }
}
