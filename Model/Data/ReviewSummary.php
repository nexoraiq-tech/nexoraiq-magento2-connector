<?php
declare(strict_types=1);

namespace NexorAIQ\Connector\Model\Data;

use Magento\Framework\DataObject;
use NexorAIQ\Connector\Api\Data\ReviewSummaryInterface;

class ReviewSummary extends DataObject implements ReviewSummaryInterface
{
    public function getRatingSummary()
    {
        return $this->getData('rating_summary');
    }

    public function setRatingSummary($ratingSummary)
    {
        return $this->setData('rating_summary', $ratingSummary);
    }

    public function getReviewsCount()
    {
        return $this->getData('reviews_count');
    }

    public function setReviewsCount($reviewsCount)
    {
        return $this->setData('reviews_count', $reviewsCount);
    }
}
