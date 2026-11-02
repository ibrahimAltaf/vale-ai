{literal}
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Vendor workspace</title>
  <link rel="stylesheet" href="/modules/marketplacecore/views/css/theme.css">
  <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@500;600;700&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
  <script src="https://cdn.tailwindcss.com"></script>
  <script>
    tailwind.config = {theme:{extend:{fontFamily:{sans:["Manrope","Inter","ui-sans-serif"]},colors:{ink:"#1c1917",navy:"#1c2434",accent:"#312e81",canvas:"#f6f3ee",line:"#e7e3dc",muted:"#78716c",emerald:"#0f766e"},borderRadius:{card:"16px",btn:"12px",promo:"24px"},boxShadow:{card:"0 18px 40px -28px rgba(28,25,23,.45)"}}}};
  </script>
  <script src="https://unpkg.com/lucide@0.460.0/dist/umd/lucide.min.js"></script>
  <script src="https://unpkg.com/react@18.3.1/umd/react.production.min.js"></script>
  <script src="https://unpkg.com/react-dom@18.3.1/umd/react-dom.production.min.js"></script>
  <script src="https://unpkg.com/prop-types@15.8.1/prop-types.min.js"></script>
  <script src="https://unpkg.com/recharts@2.15.0/umd/Recharts.js"></script>
  <script src="/modules/marketplacecore/views/js/charts.js?v=3"></script>
  <script src="/modules/marketplacecore/views/js/marketplace.js"></script>
  <style>
    html, body { margin: 0; background: #f6f3ee; color: #1c1917; }
    details:not([open]) > :not(summary) { display: none !important; }
  </style>
</head>
<body class="bg-canvas text-ink">
{/literal}
{if !$mp_dashboard}
<div class="grid min-h-screen grid-cols-1 lg:grid-cols-2">
  <section class="relative hidden overflow-hidden bg-navy text-white lg:flex lg:flex-col lg:justify-between">
    <img class="absolute inset-0 h-full w-full object-cover opacity-50" src="/modules/marketplacecore/views/img/login-still.jpg" alt="">
    <div class="absolute inset-0 bg-gradient-to-t from-navy via-navy/75 to-navy/30"></div>
    <div class="relative p-10">
      <p class="flex items-center gap-2 text-lg font-bold"><i data-lucide="store"></i> Prista Market</p>
    </div>
    <div class="relative max-w-md p-10">
      <h1 class="text-4xl font-semibold tracking-tight">Your offers, priced the way buyers receive them.</h1>
      <ul class="mt-6 space-y-3 text-sm text-white/80">
        <li>See which of your prices are the market low.</li>
        <li>Stock alerts stay next to the orders you ship.</li>
        <li>Commission is calculated on product sales, not shipping.</li>
      </ul>
    </div>
  </section>
  <section class="flex items-center justify-center px-5 py-12">
    <div class="w-full max-w-md rounded-promo border border-line bg-white p-8 shadow-card">
      <p class="text-xs font-semibold uppercase tracking-[0.16em] text-accent lg:hidden">Prista Market</p>
      <h1 class="mt-2 text-2xl font-semibold tracking-tight">Vendor sign in</h1>
      <p class="mt-2 text-sm text-muted">Use the workspace for the shop you fulfil.</p>
      {foreach from=$mp_errors item=error}<p class="mt-4 rounded-btn bg-red-50 px-3 py-2 text-sm text-red-700">{$error|escape:'html':'UTF-8'}</p>{/foreach}
      <form class="mt-6 space-y-4" method="post" autocomplete="off">
        <input type="hidden" name="token" value="{$mp_token|escape:'html':'UTF-8'}">
        <label class="block text-sm font-semibold">Email
          <input class="mt-1 h-12 w-full rounded-btn border border-line px-3 text-sm font-medium" type="email" name="email" required>
        </label>
        <label class="block text-sm font-semibold">Password
          <span class="mt-1 flex gap-2">
            <input id="mp-vendor-password" class="h-12 w-full rounded-btn border border-line px-3 text-sm font-medium" type="password" name="password" required>
            <button class="h-12 shrink-0 rounded-btn border border-line px-3 text-sm font-semibold" type="button" data-mp-reveal="mp-vendor-password" aria-pressed="false">Show</button>
          </span>
        </label>
        <button class="h-12 w-full rounded-btn bg-navy text-sm font-semibold text-white hover:bg-accent" type="submit" name="submitVendorLogin" value="1">Sign in</button>
      </form>
      <a class="mt-4 inline-flex text-sm font-semibold text-accent" href="{$mp_reset_url|escape:'html':'UTF-8'}">Forgot password</a>
      <details class="mt-6">
        <summary class="cursor-pointer text-xs text-muted">Development access</summary>
        <ul class="mt-3 space-y-1 text-xs text-muted">
          {foreach from=$mp_demo_vendors item=vendor}
            <li>{$vendor.name|escape:'html':'UTF-8'} · {$vendor.email|escape:'html':'UTF-8'} · {$vendor.password|escape:'html':'UTF-8'}</li>
          {/foreach}
        </ul>
      </details>
    </div>
  </section>
</div>
{else}
<div class="mp-app" data-mp-shell>
  <aside class="mp-side">
    <p class="mp-brand"><i data-lucide="store"></i><span>Prista</span></p>
    <p class="mp-kicker">Operate</p>
    <a class="mp-navlink is-active" href="#overview"><i data-lucide="layout-dashboard"></i><span>Overview</span></a>
    <a class="mp-navlink" href="#orders"><i data-lucide="shopping-bag"></i><span>Orders</span></a>
    <a class="mp-navlink" href="#offers"><i data-lucide="tag"></i><span>Offers</span></a>
    <a class="mp-navlink" href="#inventory"><i data-lucide="boxes"></i><span>Inventory</span></a>
    <p class="mp-kicker">Finance</p>
    <a class="mp-navlink" href="#commissions"><i data-lucide="percent"></i><span>Commissions</span></a>
    <a class="mp-navlink" href="#settlements"><i data-lucide="wallet"></i><span>Settlements</span></a>
    <a class="mp-navlink" href="#analytics"><i data-lucide="chart-line"></i><span>Analytics</span></a>
    <p class="mp-kicker">Account</p>
    <a class="mp-navlink" href="#imports"><i data-lucide="file-spreadsheet"></i><span>Offer file</span></a>
    <a class="mp-navlink" href="#settings"><i data-lucide="settings"></i><span>Settings</span></a>
  </aside>
  <div class="mp-main">
    <header class="mp-top">
      <button class="mp-iconbtn lg-only-mobile" type="button" data-mp-drawer aria-label="Open menu"><i data-lucide="menu"></i></button>
      <button class="mp-iconbtn" type="button" data-mp-collapse aria-label="Collapse sidebar"><i data-lucide="panel-left"></i></button>
      <div>
        <h1>Overview</h1>
        <p>{$mp_dashboard.vendor.name|escape:'html':'UTF-8'} · recorded trade only</p>
      </div>
      <form class="mp-search" onsubmit="return false">
        <i data-lucide="search"></i>
        <input id="mp-table-search" class="mp-input" placeholder="Search orders and offers">
      </form>
      <a class="mp-iconbtn" href="#inventory" aria-label="Stock alerts"><i data-lucide="bell"></i></a>
      <div class="mp-avatar">{$mp_dashboard.vendor.name|truncate:1:''|escape:'html':'UTF-8'}</div>
    </header>
    <div class="mp-body">
      <div class="mp-metrics" id="overview">
        <article class="mp-card mp-metric"><div class="label"><span class="icon"><i data-lucide="euro"></i></span>GMV</div><strong>{$mp_dashboard.summary.gmv|escape:'html':'UTF-8'}</strong><span>{$mp_dashboard.trends.gmv.percent|escape:'html':'UTF-8'} · {$mp_dashboard.trends.gmv.text|escape:'html':'UTF-8'}</span></article>
        <article class="mp-card mp-metric"><div class="label"><span class="icon"><i data-lucide="shopping-bag"></i></span>Orders</div><strong>{$mp_dashboard.summary.orders|intval}</strong><span>{$mp_dashboard.trends.orders.percent|escape:'html':'UTF-8'} · {$mp_dashboard.trends.orders.text|escape:'html':'UTF-8'}</span></article>
        <article class="mp-card mp-metric"><div class="label"><span class="icon"><i data-lucide="percent"></i></span>Commission</div><strong>{$mp_dashboard.summary.commission|escape:'html':'UTF-8'}</strong><span>{$mp_dashboard.trends.commission.percent|escape:'html':'UTF-8'} · {$mp_dashboard.trends.commission.text|escape:'html':'UTF-8'}</span></article>
        <article class="mp-card mp-metric"><div class="label"><span class="icon"><i data-lucide="wallet"></i></span>Net revenue</div><strong>{$mp_dashboard.summary.net|escape:'html':'UTF-8'}</strong><span>{$mp_dashboard.trends.net.percent|escape:'html':'UTF-8'} · {$mp_dashboard.trends.net.text|escape:'html':'UTF-8'}</span></article>
        <article class="mp-card mp-metric"><div class="label"><span class="icon"><i data-lucide="triangle-alert"></i></span>Stock alerts</div><strong>{$mp_dashboard.summary.alerts|intval}</strong><span>Offers at or below the stock threshold</span></article>
      </div>

      <div class="mp-split" id="analytics" style="margin-top:16px">
        <article class="mp-card"><div class="mp-card-head"><div><h2>GMV</h2><p class="mp-note">Recorded days only</p></div></div><div class="mp-card-body"><div id="mp-vendor-gmv" class="mp-chart"></div></div></article>
        <article class="mp-card"><div class="mp-card-head"><h2>Orders</h2></div><div class="mp-card-body"><div id="mp-vendor-orders" class="mp-chart"></div></div></article>
      </div>

      <div class="mp-split" style="margin-top:16px">
        <article class="mp-card" id="orders">
          <div class="mp-card-head"><h2>Recent orders</h2></div>
          <div class="mp-table-wrap">
            <table class="mp-table">
              <thead><tr><th>Order</th><th>Delivery</th><th>Items</th><th>Total</th><th>Status</th><th>Date</th></tr></thead>
              <tbody>
                {foreach from=$mp_dashboard.orders item=order}
                  <tr data-mp-row="{$order.reference|escape:'html':'UTF-8'} {$order.items|escape:'html':'UTF-8'}">
                    <td><strong>{$order.reference|escape:'html':'UTF-8'}</strong><div class="mp-note">{$order.parent|escape:'html':'UTF-8'}</div></td>
                    <td>{$order.buyer|escape:'html':'UTF-8'}</td>
                    <td>{$order.items|escape:'html':'UTF-8'}</td>
                    <td><strong>{$order.total|escape:'html':'UTF-8'}</strong></td>
                    <td><span class="mp-pill mp-pill-ok">{$order.badge|escape:'html':'UTF-8'}</span></td>
                    <td class="mp-note">{$order.date|escape:'html':'UTF-8'}</td>
                  </tr>
                {foreachelse}
                  <tr><td colspan="6" class="mp-note">No orders yet.</td></tr>
                {/foreach}
              </tbody>
            </table>
          </div>
        </article>
        <article class="mp-card" id="inventory">
          <div class="mp-card-head"><h2>Inventory alerts</h2></div>
          <div class="mp-card-body">
            {assign var=alerts value=0}
            {foreach from=$mp_dashboard.offers item=offer}
              {if $offer.low_stock}
                {assign var=alerts value=$alerts+1}
                <div style="padding:10px 0;border-bottom:1px solid var(--mp-line)">
                  <strong>{$offer.sku|escape:'html':'UTF-8'}</strong>
                  <div class="mp-note">{$offer.available|intval} available · {$offer.title|escape:'html':'UTF-8'}</div>
                </div>
              {/if}
            {/foreach}
            {if $alerts == 0}<p class="mp-note">No low-stock offers.</p>{/if}
          </div>
        </article>
      </div>

      <article class="mp-card" id="offers" style="margin-top:16px">
        <div class="mp-card-head">
          <div><h2>Competitive pricing</h2><p class="mp-note">Your net price against the lowest live offer.</p></div>
          <select class="mp-input" data-mp-position style="width:180px">
            <option value="">All positions</option>
            <option value="Lowest">Lowest</option>
            <option value="Above market">Above market</option>
          </select>
        </div>
        <div class="mp-table-wrap">
          <table class="mp-table">
            <thead><tr><th>Product</th><th>Your price</th><th>Best market</th><th>Difference</th><th>Position</th><th></th></tr></thead>
            <tbody>
              {foreach from=$mp_dashboard.offers item=offer}
                <tr data-mp-row="{$offer.title|escape:'html':'UTF-8'} {$offer.sku|escape:'html':'UTF-8'}" data-mp-offer data-mp-position="{$offer.position|escape:'html':'UTF-8'}">
                  <td><div style="display:flex;gap:12px;align-items:center">{if $offer.image}<img class="mp-thumb" src="{$offer.image|escape:'html':'UTF-8'}" alt="">{/if}<div><strong>{$offer.title|escape:'html':'UTF-8'}</strong><div class="mp-note">{$offer.sku|escape:'html':'UTF-8'}</div></div></div></td>
                  <td>{$offer.price|escape:'html':'UTF-8'}</td>
                  <td>{$offer.market_low|escape:'html':'UTF-8'}</td>
                  <td>{$offer.gap_label|escape:'html':'UTF-8'}</td>
                  <td>{if $offer.is_lowest}<span class="mp-pill mp-pill-ok">Lowest</span>{else}<span class="mp-pill mp-pill-warn">Above market</span>{/if}</td>
                  <td><a class="mp-btn" href="{$offer.url|escape:'html':'UTF-8'}">Review offer</a></td>
                </tr>
              {/foreach}
            </tbody>
          </table>
        </div>
      </article>

      <div class="mp-split" style="margin-top:16px">
        <article class="mp-card" id="commissions">
          <div class="mp-card-head"><div><h2>Commissions</h2><p class="mp-note">Taken from product sales. Shipping is excluded.</p></div><strong style="font-size:28px">{$mp_dashboard.summary.commission|escape:'html':'UTF-8'}</strong></div>
          <div class="mp-table-wrap">
            <table class="mp-table">
              <thead><tr><th>Shipment</th><th>Products</th><th>Commission</th><th>Your net</th></tr></thead>
              <tbody>
                {foreach from=$mp_dashboard.orders item=order}
                  <tr>
                    <td><strong>{$order.reference|escape:'html':'UTF-8'}</strong></td>
                    <td>{$order.products|escape:'html':'UTF-8'}</td>
                    <td>{$order.commission|escape:'html':'UTF-8'}</td>
                    <td><strong>{$order.net|escape:'html':'UTF-8'}</strong></td>
                  </tr>
                {foreachelse}
                  <tr><td colspan="4" class="mp-note">No commission yet.</td></tr>
                {/foreach}
              </tbody>
            </table>
          </div>
        </article>
        <article class="mp-card" id="settlements">
          <div class="mp-card-body">
            <p class="mp-note">Unsettled balance</p>
            <p style="margin:8px 0 0;font-size:40px;font-weight:700;letter-spacing:-.04em">{$mp_dashboard.summary.net|escape:'html':'UTF-8'}</p>
            <p class="mp-note">Net of product sales after commission. This is the balance recorded so far, not a paid settlement.</p>
          </div>
        </article>
      </div>

      <article class="mp-card" id="imports" style="margin-top:16px">
        <div class="mp-card-body" style="display:flex;justify-content:space-between;gap:16px;flex-wrap:wrap;align-items:center">
          <div>
            <h2>Offer file</h2>
            <p class="mp-note">The prices above are the live offers on your account. A CSV import is run from marketplace admin and matches your vendor code plus SKU or EAN.</p>
          </div>
          <a class="mp-btn mp-btn-ghost" href="#offers">Review offers</a>
        </div>
      </article>
      <article class="mp-card" id="settings" style="margin-top:16px">
        <div class="mp-card-body">
          <h2>Settings</h2>
          <p class="mp-note">Commission {$mp_dashboard.vendor.commission_percent|escape:'html':'UTF-8'}%. Account {$mp_dashboard.vendor.status|escape:'html':'UTF-8'}.</p>
          <p class="mp-note">{if $mp_dashboard.vendor.code == 'A'}Berlin roastery. Breakfast staples and household lines, packed for nationwide delivery.{elseif $mp_dashboard.vendor.code == 'B'}Hamburg grocer. Pasta, rice, coffee and home goods from one warehouse.{elseif $mp_dashboard.vendor.code == 'C'}Regional pantry. Delivery only inside postcodes 10, 20 and 75.{else}{$mp_dashboard.vendor.description|escape:'html':'UTF-8'}{/if}</p>
          <form method="post" style="margin-top:12px"><button class="mp-btn mp-btn-ghost" name="submitVendorLogout" value="1" type="submit">Sign out</button></form>
        </div>
      </article>
    </div>
  </div>
</div>
<script>
var mpLabels = {$mp_dashboard.sales.labels|json_encode nofilter};
var mpGmv = {$mp_dashboard.sales.gmv|json_encode nofilter};
var mpOrders = {$mp_dashboard.sales.orders|json_encode nofilter};
</script>
{literal}
<script>
document.addEventListener('DOMContentLoaded', function () {
  if (window.mpMountChart) {
    window.mpMountChart('mp-vendor-gmv', mpLabels, mpGmv);
    window.mpMountChart('mp-vendor-orders', mpLabels, mpOrders);
  }
  var search = document.getElementById('mp-table-search');
  var position = document.querySelector('[data-mp-position]');
  var filterRows = function () {
    var query = search ? search.value.toLowerCase() : '';
    var pos = position ? position.value : '';
    document.querySelectorAll('[data-mp-row]').forEach(function (row) {
      var textOk = row.getAttribute('data-mp-row').toLowerCase().indexOf(query) !== -1;
      var posOk = !pos || !row.hasAttribute('data-mp-offer') || row.getAttribute('data-mp-position') === pos;
      row.style.display = textOk && posOk ? '' : 'none';
    });
  };
  if (search) search.addEventListener('input', filterRows);
  if (position) position.addEventListener('change', filterRows);
  if (window.lucide) window.lucide.createIcons();
});
</script>
{/literal}
{/if}
{literal}
<script>
document.addEventListener('DOMContentLoaded', function () {
  if (window.lucide) window.lucide.createIcons();
});
</script>
</body>
</html>
{/literal}
