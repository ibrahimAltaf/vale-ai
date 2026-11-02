{assign var=mp_nav value='overview'}
{assign var=mp_title value='Overview'}
{include file=$smarty.const._PS_MODULE_DIR_|cat:'marketplacecore/views/templates/admin/nav.tpl'}
<div class="mp-metrics">
  <article class="mp-card mp-metric"><div class="label"><span class="icon"><i data-lucide="euro"></i></span>GMV</div><strong>{$summary.gmv|escape:'html':'UTF-8'}</strong><span>{$trends.gmv.percent|escape:'html':'UTF-8'} · {$trends.gmv.text|escape:'html':'UTF-8'}</span></article>
  <article class="mp-card mp-metric"><div class="label"><span class="icon"><i data-lucide="shopping-bag"></i></span>Orders</div><strong>{$summary.orders|intval}</strong><span>{$trends.orders.percent|escape:'html':'UTF-8'} · {$trends.orders.text|escape:'html':'UTF-8'}</span></article>
  <article class="mp-card mp-metric" id="commissions"><div class="label"><span class="icon"><i data-lucide="percent"></i></span>Commission</div><strong>{$summary.commission|escape:'html':'UTF-8'}</strong><span>{$trends.commission.percent|escape:'html':'UTF-8'} · {$trends.commission.text|escape:'html':'UTF-8'}</span></article>
  <article class="mp-card mp-metric"><div class="label"><span class="icon"><i data-lucide="users"></i></span>Active vendors</div><strong>{$summary.vendors|intval}</strong><span>Accounts able to sell</span></article>
  <article class="mp-card mp-metric"><div class="label"><span class="icon"><i data-lucide="tag"></i></span>Live offers</div><strong>{$summary.offers|intval}</strong><span>{$summary.products|intval} master products</span></article>
</div>
<div class="mp-split" id="analytics" style="margin-top:16px">
  <article class="mp-card"><div class="mp-card-head"><div><h2>GMV</h2><p class="mp-note">Recorded days only</p></div></div><div class="mp-card-body"><div id="mp-admin-gmv" class="mp-chart"></div></div></article>
  <article class="mp-card"><div class="mp-card-head"><h2>Commission by day</h2></div><div class="mp-card-body"><div id="mp-admin-commission" class="mp-chart"></div></div></article>
</div>
<div class="mp-split" style="margin-top:16px">
  <article class="mp-card"><div class="mp-card-head"><h2>Orders by vendor</h2></div><div class="mp-card-body"><div id="mp-admin-vendors" class="mp-chart"></div></div></article>
  <article class="mp-card"><div class="mp-card-head"><h2>Marketplace performance</h2></div><div class="mp-card-body"><div id="mp-admin-gmv-vendor" class="mp-chart"></div></div></article>
</div>
<article class="mp-card" style="margin-top:16px">
  <div class="mp-card-head"><h2>Recent orders</h2></div>
  <div class="mp-table-wrap">
    <table class="mp-table">
      <thead><tr><th>Order</th><th>Delivery</th><th>Vendor</th><th>Items</th><th>Total</th><th>Status</th><th>Date</th></tr></thead>
      <tbody>
        {foreach from=$orders item=order}
          <tr>
            <td><strong>{$order.reference|escape:'html':'UTF-8'}</strong><div class="mp-note">{$order.parent|escape:'html':'UTF-8'}</div></td>
            <td>{$order.buyer|escape:'html':'UTF-8'}</td>
            <td><span class="mp-avatar" style="display:inline-grid;margin-right:8px">{$order.code|truncate:1:''|escape:'html':'UTF-8'}</span>{$order.vendor|escape:'html':'UTF-8'}</td>
            <td class="mp-note">{$order.items|escape:'html':'UTF-8'}</td>
            <td><strong>{$order.total|escape:'html':'UTF-8'}</strong></td>
            <td><span class="mp-pill mp-pill-ok">{$order.badge|escape:'html':'UTF-8'}</span></td>
            <td class="mp-note">{$order.date|escape:'html':'UTF-8'}</td>
          </tr>
        {foreachelse}
          <tr><td colspan="7" class="mp-note">No orders yet.</td></tr>
        {/foreach}
      </tbody>
    </table>
  </div>
</article>
<article class="mp-card" style="margin-top:16px">
  <div class="mp-card-head"><h2>Vendor performance</h2></div>
  <div class="mp-table-wrap">
    <table class="mp-table">
      <thead><tr><th>Vendor</th><th>GMV</th><th>Orders</th><th>Average basket</th><th>Commission</th><th>Fulfillment</th><th>Share</th></tr></thead>
      <tbody>
        {foreach from=$performance item=row}
          <tr>
            <td><strong>{$row.name|escape:'html':'UTF-8'}</strong><div class="mp-note">{$row.code|escape:'html':'UTF-8'} · {$row.status|escape:'html':'UTF-8'}</div></td>
            <td>{$row.gmv|escape:'html':'UTF-8'}</td>
            <td>{$row.orders|intval}</td>
            <td>{$row.average|escape:'html':'UTF-8'}</td>
            <td>{$row.commission|escape:'html':'UTF-8'}</td>
            <td>{$row.fulfillment|intval}%</td>
            <td><div style="height:6px;border-radius:99px;background:#efece6"><div style="height:6px;border-radius:99px;background:#312e81;width:{$row.share|intval}%"></div></div><div class="mp-note">{$row.share|intval}%</div></td>
          </tr>
        {/foreach}
      </tbody>
    </table>
  </div>
</article>
<div class="mp-split" style="margin-top:16px">
  <article class="mp-card" id="inventory">
    <div class="mp-card-head"><h2>Import activity</h2></div>
    <div class="mp-table-wrap">
      <table class="mp-table">
        <thead><tr><th>File</th><th>Vendor</th><th>Status</th><th>Rows</th><th>When</th></tr></thead>
        <tbody>
          {foreach from=$imports item=job}
            <tr>
              <td>{$job.filename|escape:'html':'UTF-8'}</td>
              <td>{$job.vendor_name|escape:'html':'UTF-8'}</td>
              <td><span class="mp-pill">{$job.status|escape:'html':'UTF-8'}</span></td>
              <td>{$job.rows_total|intval}</td>
              <td class="mp-note">{$job.date_add|escape:'html':'UTF-8'}</td>
            </tr>
          {foreachelse}
            <tr><td colspan="5" class="mp-note">No CSV imports yet.</td></tr>
          {/foreach}
        </tbody>
      </table>
    </div>
  </article>
  <article class="mp-card" id="settlements">
    <div class="mp-card-head"><h2>Open settlements</h2></div>
    <div class="mp-table-wrap">
      <table class="mp-table">
        <thead><tr><th>Vendor</th><th>Gross</th><th>Net</th></tr></thead>
        <tbody>
          {foreach from=$settlements item=row}
            <tr>
              <td>{$row.vendor|escape:'html':'UTF-8'}<div class="mp-note">{$row.period|escape:'html':'UTF-8'} · {$row.status|escape:'html':'UTF-8'}</div></td>
              <td>{$row.gross|escape:'html':'UTF-8'}</td>
              <td><strong>{$row.net|escape:'html':'UTF-8'}</strong></td>
            </tr>
          {foreachelse}
            <tr><td colspan="3" class="mp-note">No open settlements.</td></tr>
          {/foreach}
        </tbody>
      </table>
    </div>
  </article>
</div>
<article class="mp-card" id="settings" style="margin-top:16px">
  <div class="mp-card-body" style="display:flex;justify-content:space-between;gap:16px;align-items:center;flex-wrap:wrap">
    <div>
      <h2>Settings</h2>
      <p class="mp-note">Vendor terms, offer files and collections are the pages in this workspace. Catalog refresh stays on the module screen.</p>
    </div>
    <a class="mp-btn" href="{$link->getAdminLink('AdminModules')|escape:'html':'UTF-8'}&amp;configure=marketplacecore">Open marketplace settings</a>
  </div>
</article>
<script>
var mpSalesLabels = {$sales.labels|json_encode nofilter};
var mpSalesGmv = {$sales.gmv|json_encode nofilter};
var mpCommissionLabels = {$commissions.labels|json_encode nofilter};
var mpCommissionValues = {$commissions.values|json_encode nofilter};
var mpVendorLabels = {$vendor_orders.labels|json_encode nofilter};
var mpVendorOrders = {$vendor_orders.orders|json_encode nofilter};
var mpVendorGmv = {$vendor_orders.gmv|json_encode nofilter};
</script>
{literal}
<script>
document.addEventListener('DOMContentLoaded', function () {
  if (!window.mpMountChart) return;
  window.mpMountChart('mp-admin-gmv', mpSalesLabels, mpSalesGmv);
  window.mpMountChart('mp-admin-commission', mpCommissionLabels, mpCommissionValues);
  window.mpMountChart('mp-admin-vendors', mpVendorLabels, mpVendorOrders);
  window.mpMountChart('mp-admin-gmv-vendor', mpVendorLabels, mpVendorGmv);
});
</script>
{/literal}
{include file=$smarty.const._PS_MODULE_DIR_|cat:'marketplacecore/views/templates/admin/shell-close.tpl'}
