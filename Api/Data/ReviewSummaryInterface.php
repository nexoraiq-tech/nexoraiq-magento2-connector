<?php
declare(strict_types=1);

namespace NexorAIQ\Connector\Api\Data;

interface ReviewSummaryInterface
{
    /**
     * @return float|null
     */
    public function getRatingSummary();

    /**
     * @param float|null $ratingSummary
     * @return $this
     */
    public function setRatingSummary($ratingSummary);

    /**
     * @return int|null
     */
    public function getReviewsCount();

    /**
     * @param int|null $reviewsCount
     * @return $this
     */
    public function setReviewsCount($reviewsCount);
}
