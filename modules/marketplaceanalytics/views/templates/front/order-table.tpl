<table class="mp-table">
  <thead><tr><th>Parent</th><th>Vendor order</th><th>Vendor</th><th>Products</th><th>Shipping</th><th>Total</th><th>Lines</th></tr></thead>
  <tbody>
    {foreach from=$mp_orders item=order}
      <tr>
        <td data-label="Parent">#{$order.parent|escape:'html':'UTF-8'}</td>
        <td data-label="Vendor order">#{$order.reference|escape:'html':'UTF-8'}</td>
        <td data-label="Vendor">{$order.vendor|escape:'html':'UTF-8'}</td>
        <td data-label="Products">{$order.products|escape:'html':'UTF-8'}</td>
        <td data-label="Shipping">{$order.shipping|escape:'html':'UTF-8'}</td>
        <td data-label="Total">{$order.total|escape:'html':'UTF-8'}</td>
        <td data-label="Lines">{foreach from=$order.lines item=line}{$line|escape:'html':'UTF-8'}<br>{/foreach}</td>
      </tr>
    {foreachelse}
      <tr><td colspan="7">No orders yet.</td></tr>
    {/foreach}
  </tbody>
</table>
