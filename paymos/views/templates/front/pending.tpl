{*
 * Paymos pending / processing page.
 *
 * @author    Paymos
 * @copyright Paymos
 * @license   https://opensource.org/licenses/MIT MIT License
 *}
{extends file='page.tpl'}

{block name='page_content'}
  <section class="paymos-pending">
    {if isset($paymos_failed) && $paymos_failed}
      <h1>{l s='We could not start your payment' mod='paymos'}</h1>
      <p>{l s='Something went wrong while opening the Paymos checkout, so this order was not charged. Please return to checkout and try again, or choose another payment method.' mod='paymos'}</p>
    {else}
      <h1>{l s='Your payment is being processed' mod='paymos'}</h1>
      <p>{l s='We are confirming your Paymos payment on-chain. This page does not need to stay open — your order will update automatically as soon as the payment is confirmed.' mod='paymos'}</p>
    {/if}
    {if isset($paymos_order_reference) && $paymos_order_reference}
      <p>{l s='Order reference:' mod='paymos'} <strong>{$paymos_order_reference|escape:'html':'UTF-8'}</strong></p>
    {/if}
    {if isset($paymos_resume_url) && $paymos_resume_url}
      <p>{l s='Did you close the payment page too soon? You can finish paying this order securely.' mod='paymos'}</p>
      <p><a class="btn btn-primary" href="{$paymos_resume_url|escape:'html':'UTF-8'}">{l s='Continue payment' mod='paymos'}</a></p>
      <p><a class="btn btn-secondary" href="{$paymos_history_url|escape:'html':'UTF-8'}">{l s='View my orders' mod='paymos'}</a></p>
    {else}
      <p><a class="btn btn-primary" href="{$paymos_history_url|escape:'html':'UTF-8'}">{l s='View my orders' mod='paymos'}</a></p>
    {/if}
  </section>
{/block}
