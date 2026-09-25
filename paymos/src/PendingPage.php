<?php

declare(strict_types=1);

namespace PaymosPrestaShop;

/**
 * What the buyer's status page (controllers/front/pending.php) shows.
 *
 * Three cases, and the order state cannot tell all of them apart:
 *   - failed: the hand-off to Paymos failed and the order is in PS_OS_ERROR;
 *   - review: validation.php refused to replace an invoice it could not prove
 *     closed (BUG-166) and says so with REVIEW_PARAM. The order is in
 *     PAYMOS_OS_MANUAL_REVIEW, but OrderMapper puts orders there too when a
 *     completed payment fails the amount guard, so the flag decides (BUG-190);
 *   - processing: everything else.
 *
 * The resume link re-offers the stored invoice to a buyer who closed the
 * Paymos checkout too soon. It is never offered on the review branch or for an
 * order under manual review: there the stored invoice may be paid already, or
 * be for an amount the order no longer has.
 */
final class PendingPage
{
    /** Query parameter validation.php adds on a blocked replacement. */
    const REVIEW_PARAM = 'paymos_review';

    const VIEW_FAILED = 'failed';
    const VIEW_REVIEW = 'review';
    const VIEW_PROCESSING = 'processing';

    /**
     * @param int                       $currentStateId      The order's current state.
     * @param int                       $errorStateId        PS_OS_ERROR.
     * @param int                       $manualReviewStateId PAYMOS_OS_MANUAL_REVIEW.
     * @param bool                      $replacementBlocked  REVIEW_PARAM was set.
     * @param array<string, mixed>|null $invoice             The stored invoice row, if any.
     * @return array{view: string, resume_url: string}
     */
    public static function decide($currentStateId, $errorStateId, $manualReviewStateId, $replacementBlocked, $invoice)
    {
        if ($replacementBlocked) {
            return array('view' => self::VIEW_REVIEW, 'resume_url' => '');
        }

        if ((int) $currentStateId === (int) $errorStateId) {
            return array('view' => self::VIEW_FAILED, 'resume_url' => '');
        }

        $resumeUrl = '';
        if ((int) $currentStateId !== (int) $manualReviewStateId
            && is_array($invoice)
            && !empty($invoice['payment_url'])
            && self::isResumable(isset($invoice['status']) ? (string) $invoice['status'] : '')) {
            $resumeUrl = (string) $invoice['payment_url'];
        }

        return array('view' => self::VIEW_PROCESSING, 'resume_url' => $resumeUrl);
    }

    /**
     * A stored invoice is resumable only while it is still open — not in any
     * final status. Once paid/underpaid/expired/cancelled, re-offering the pay
     * URL would be misleading.
     */
    private static function isResumable($status)
    {
        $final = array('paid', 'paid_over', 'underpaid', 'expired', 'cancelled');

        return $status !== '' && !in_array(strtolower(trim($status)), $final, true);
    }
}
