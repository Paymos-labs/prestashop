<?php

declare(strict_types=1);

use PaymosPrestaShop\PendingPage;

// BUG-190: when the invoice replacement was blocked (BUG-166) validation.php
// sent the buyer to the pending page, which said "Your payment is being
// processed … confirming on-chain" and could offer "Continue payment" on the
// old, still payable invoice — whose amount no longer matches the order. The
// order state cannot tell this case apart: OrderMapper also moves an order to
// PAYMOS_OS_MANUAL_REVIEW when a completed payment fails the amount guard. So
// validation.php passes an explicit flag, and the page gets a third branch.

const PRESTASHOP_TEST_PENDING_STATE = 11;
const PRESTASHOP_TEST_ERROR_STATE = 8;
const PRESTASHOP_TEST_REVIEW_STATE = 14;

const PRESTASHOP_REVIEW_HEADING = 'This order needs review';
const PRESTASHOP_REVIEW_MESSAGE = 'The store needs to review this order before payment can continue. Please contact the store.';

function prestashop_open_invoice()
{
    return array('payment_url' => 'https://pay.paymos.test/inv_old', 'status' => 'awaiting_payment');
}

function prestashop_pending_view($stateId, $replacementBlocked, $invoice)
{
    return PendingPage::decide(
        $stateId,
        PRESTASHOP_TEST_ERROR_STATE,
        PRESTASHOP_TEST_REVIEW_STATE,
        $replacementBlocked,
        $invoice
    );
}

function test_prestashop_pending_page_blocked_replacement_asks_to_contact_the_store_and_offers_no_payment()
{
    // The old invoice is still payable — exactly the one that must not be offered.
    $page = prestashop_pending_view(PRESTASHOP_TEST_REVIEW_STATE, true, prestashop_open_invoice());

    assertSameValue(PendingPage::VIEW_REVIEW, $page['view'], 'a blocked replacement gets the review branch.');
    assertSameValue('', $page['resume_url'], 'no "Continue payment" link to the old invoice.');

    // Whatever state the order reached by the time the page loads, the flag
    // still says what the checkout just did.
    $page = prestashop_pending_view(PRESTASHOP_TEST_PENDING_STATE, true, prestashop_open_invoice());
    assertSameValue(PendingPage::VIEW_REVIEW, $page['view'], 'the flag decides, not the state alone.');
    assertSameValue('', $page['resume_url'], 'still no payment link.');
}

function test_prestashop_pending_page_manual_review_state_alone_keeps_the_processing_text_but_offers_no_payment()
{
    // OrderMapper's post-payment amount mismatch: same state, no flag.
    $page = prestashop_pending_view(PRESTASHOP_TEST_REVIEW_STATE, false, prestashop_open_invoice());

    assertSameValue(PendingPage::VIEW_PROCESSING, $page['view'], 'without the flag the state alone does not switch the text.');
    assertSameValue('', $page['resume_url'], 'an order under manual review never offers to pay.');
}

function test_prestashop_pending_page_open_invoice_can_still_be_resumed()
{
    $page = prestashop_pending_view(PRESTASHOP_TEST_PENDING_STATE, false, prestashop_open_invoice());

    assertSameValue(PendingPage::VIEW_PROCESSING, $page['view'], 'an ordinary pending order reads "being processed".');
    assertSameValue('https://pay.paymos.test/inv_old', $page['resume_url'], 'the buyer who closed the checkout too soon can finish paying.');

    foreach (array('paid', 'paid_over', 'underpaid', 'expired', 'cancelled') as $status) {
        $page = prestashop_pending_view(PRESTASHOP_TEST_PENDING_STATE, false, array('payment_url' => 'https://pay.paymos.test/inv_old', 'status' => $status));
        assertSameValue('', $page['resume_url'], $status . ': a final invoice is not offered.');
    }

    $page = prestashop_pending_view(PRESTASHOP_TEST_PENDING_STATE, false, null);
    assertSameValue('', $page['resume_url'], 'no stored invoice, no link.');
}

function test_prestashop_pending_page_failed_handoff_keeps_the_failure_text()
{
    $page = prestashop_pending_view(PRESTASHOP_TEST_ERROR_STATE, false, prestashop_open_invoice());

    assertSameValue(PendingPage::VIEW_FAILED, $page['view'], 'a failed hand-off keeps its own text.');
    assertSameValue('', $page['resume_url'], 'and offers no link.');
}

function test_prestashop_validation_flags_only_the_blocked_replacement()
{
    $source = (string) file_get_contents(PAYMOS_PRESTASHOP_MODULE_DIR . 'controllers/front/validation.php');

    $catchAt = strpos($source, 'catch (\\Paymos\\Plugin\\InvoiceReplacementBlockedException $e)');
    assertTrueValue($catchAt !== false, 'validation.php catches the blocked replacement.');
    $nextCatch = strpos($source, 'catch (', $catchAt + 10);
    $blockedBranch = substr($source, $catchAt, $nextCatch - $catchAt);
    assertContainsValue('$this->redirectToPending($orderId, $cart, $customer, true);', $blockedBranch, 'the blocked branch sends the flag.');

    assertSameValue(1, substr_count($source, ', true);'), 'no other redirect raises the flag.');
    assertContainsValue('\\PaymosPrestaShop\\PendingPage::REVIEW_PARAM', $source, 'the flag goes into the pending link under the shared name.');

    $pending = (string) file_get_contents(PAYMOS_PRESTASHOP_MODULE_DIR . 'controllers/front/pending.php');
    assertContainsValue('Tools::getValue(\\PaymosPrestaShop\\PendingPage::REVIEW_PARAM)', $pending, 'the pending page reads the flag.');
    assertContainsValue('\\PaymosPrestaShop\\PendingPage::decide(', $pending, 'the pending page lets PendingPage decide.');
    assertContainsValue("'paymos_review' =>", $pending, 'the template learns about the review branch.');
}

function test_prestashop_pending_template_review_branch_hides_the_payment_button()
{
    $tpl = (string) file_get_contents(PAYMOS_PRESTASHOP_MODULE_DIR . 'views/templates/front/pending.tpl');

    $blocked = new \Paymos\Plugin\InvoiceReplacementBlockedException(
        \Paymos\Plugin\InvoiceReplacementResult::blocked('inv_old', 'awaiting_payment', \Paymos\Plugin\InvoiceReplacementResult::REASON_OPEN)
    );
    assertSameValue(PRESTASHOP_REVIEW_MESSAGE, $blocked->getMessage(), 'the page quotes the SDK buyer message.');

    $reviewAt = strpos($tpl, '{if isset($paymos_review) && $paymos_review}');
    assertTrueValue($reviewAt !== false, 'the template has a review branch.');
    $processingAt = strpos($tpl, "{l s='Your payment is being processed' mod='paymos'}");
    assertTrueValue($processingAt !== false && $reviewAt < $processingAt, 'the review branch comes before the processing text.');
    $branchEnd = strpos($tpl, '{elseif', $reviewAt);
    $branch = substr($tpl, $reviewAt, $branchEnd - $reviewAt);
    assertContainsValue("{l s='" . PRESTASHOP_REVIEW_HEADING . "' mod='paymos'}", $branch, 'the review branch has its heading.');
    assertContainsValue("{l s='" . PRESTASHOP_REVIEW_MESSAGE . "' mod='paymos'}", $branch, 'the review branch shows the SDK message.');
    assertFalseValue(strpos($branch, 'on-chain') !== false, 'the review branch does not say the payment is confirming.');

    assertContainsValue("{if isset(\$paymos_resume_url) && \$paymos_resume_url && !(isset(\$paymos_review) && \$paymos_review)}", $tpl, 'the "Continue payment" button is never shown on the review branch.');
}

/**
 * @return array<string, string> PrestaShop's legacy module catalogue for one file.
 */
function prestashop_translations($file)
{
    global $_MODULE;
    $_MODULE = array();
    include PAYMOS_PRESTASHOP_MODULE_DIR . 'translations/' . $file . '.php';

    return $_MODULE;
}

function test_prestashop_review_strings_are_in_every_catalogue()
{
    $headingKey = '<{paymos}prestashop>pending_' . md5(PRESTASHOP_REVIEW_HEADING);
    $messageKey = '<{paymos}prestashop>pending_' . md5(PRESTASHOP_REVIEW_MESSAGE);

    $headings = array(
        'en' => PRESTASHOP_REVIEW_HEADING,
        'ru' => 'Заказ нужно проверить',
        'de' => 'Diese Bestellung muss geprüft werden',
        'es' => 'Hay que revisar este pedido',
        'tr' => 'Bu siparişin incelenmesi gerekiyor',
        'zh-CN' => '这笔订单需要审核',
    );

    foreach ($headings as $file => $heading) {
        $catalogue = prestashop_translations($file);
        assertSameValue($heading, isset($catalogue[$headingKey]) ? $catalogue[$headingKey] : null, $file . ': the review heading.');
        assertTrueValue(isset($catalogue[$messageKey]) && trim($catalogue[$messageKey]) !== '', $file . ': the review message.');
    }

    assertSameValue(PRESTASHOP_REVIEW_MESSAGE, prestashop_translations('en')[$messageKey], 'en: the message is the SDK text.');
}
