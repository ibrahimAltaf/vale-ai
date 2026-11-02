{assign var=mp_nav value='orders'}
{assign var=mp_title value='Orders'}
{include file=$smarty.const._PS_MODULE_DIR_|cat:'marketplacecore/views/templates/admin/nav.tpl'}
<p class="mp-note" style="margin-bottom:16px">Each marketplace order is split by vendor. A vendor workspace shows only that vendor’s shipment.</p>
<article class="mp-card">
  <div class="mp-table-wrap">
    <table class="mp-table">
      <thead><tr><th>Parent</th><th>Shipment</th><th>Customer</th><th>Payment</th><th>Shop order</th><th>Vendor</th><th>Products</th><th>Shipping</th><th>Total</th><th>Status</th><th>Lines</th></tr></thead>
      <tbody>
        {foreach from=$mp_orders item=order}
          <tr>
            <td><strong>#{$order.parent|escape:'html':'UTF-8'}</strong></td>
            <td>#{$order.reference|escape:'html':'UTF-8'}</td>
            <td>{$order.buyer|escape:'html':'UTF-8'}</td>
            <td>{$order.payment|escape:'html':'UTF-8'}</td>
            <td>{$order.shop|escape:'html':'UTF-8'}</td>
            <td>{$order.vendor|escape:'html':'UTF-8'}</td>
            <td>{$order.products|escape:'html':'UTF-8'}</td>
            <td>{$order.shipping|escape:'html':'UTF-8'}</td>
            <td><strong>{$order.total|escape:'html':'UTF-8'}</strong></td>
            <td>{$order.badge|escape:'html':'UTF-8'}</td>
            <td class="mp-note">{foreach from=$order.lines item=line}{$line|escape:'html':'UTF-8'}<br>{/foreach}</td>
          </tr>
        {foreachelse}
          <tr><td colspan="11" class="mp-note">No orders yet.</td></tr>
        {/foreach}
      </tbody>
    </table>
  </div>
</article>
{include file=$smarty.const._PS_MODULE_DIR_|cat:'marketplacecore/views/templates/admin/shell-close.tpl'}
