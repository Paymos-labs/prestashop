{*
 * Paymos admin config-status panel — read-only diagnostics.
 *
 * @author    Paymos
 * @copyright Paymos
 * @license   https://opensource.org/licenses/MIT MIT License
 *}
<div class="panel">
  <div class="panel-heading">
    <i class="icon-shield"></i> {l s='Paymos connection' mod='paymos'}
  </div>
  <div class="panel-body">
    {if $paymos_has_generated}
      <p>
        <span class="badge badge-success">{l s='Connected securely' mod='paymos'}</span>
      </p>
      <table class="table">
        <tbody>
          <tr>
            <th>{l s='Active mode' mod='paymos'}</th>
            <td>{$paymos_mode|escape:'html':'UTF-8'}</td>
          </tr>
          {if $paymos_masked_key}
          <tr>
            <th>{l s='API key' mod='paymos'}</th>
            <td><code>{$paymos_masked_key|escape:'html':'UTF-8'}</code></td>
          </tr>
          {/if}
          {if $paymos_project_id}
          <tr>
            <th>{l s='Project' mod='paymos'}</th>
            <td><code>{$paymos_project_id|escape:'html':'UTF-8'}</code></td>
          </tr>
          {/if}
          <tr>
            <th>{l s='Webhook URL' mod='paymos'}</th>
            <td><code>{$paymos_callback_url|escape:'html':'UTF-8'}</code></td>
          </tr>
          {if $paymos_reconcile_url}
          <tr>
            <th>{l s='Reconcile cron URL' mod='paymos'}</th>
            <td><code>{$paymos_reconcile_url|escape:'html':'UTF-8'}</code></td>
          </tr>
          {/if}
        </tbody>
      </table>
      <p class="help-block">{l s='Paymos registered this Webhook URL automatically for the selected project. The Reconcile cron URL is an optional safety net for missed callbacks — schedule it (e.g. hourly) from your host.' mod='paymos'}</p>
    {else}
      <p>
        <span class="badge badge-warning">{l s='Not connected' mod='paymos'}</span>
      </p>
      <p class="help-block">{l s='Open the intended project in Paymos, then click Connect Paymos above and approve this store. Sandbox and Live credentials are delivered once, encrypted in this installation, and never entered manually.' mod='paymos'}</p>
    {/if}
  </div>
</div>
