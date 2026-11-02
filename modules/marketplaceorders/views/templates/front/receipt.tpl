{extends file='page.tpl'}
{block name='page_content'}
<div class="bg-canvas text-ink" style="font-family:Inter,Manrope,sans-serif">
  <div class="mx-auto max-w-[1100px] px-6 py-10 lg:px-8">
    {if !$mp_order}
      <h1 class="text-3xl font-semibold">We could not find that order</h1>
      <a class="mt-4 inline-flex rounded-lg bg-navy px-4 py-2.5 text-sm font-semibold text-white" href="{$mp_cart_url|escape:'html':'UTF-8'}">Return to Smart Cart</a>
    {else}
      <p class="text-xs font-semibold uppercase tracking-[0.16em] text-emerald-700">{if $mp_order.order_status == 'awaiting_payment'}Awaiting bank transfer{else}Order confirmed{/if}</p>
      <div class="mt-2 flex flex-wrap items-end justify-between gap-4">
        <h1 class="text-[2rem] font-semibold tracking-tight">#{$mp_order.reference|escape:'html':'UTF-8'}</h1>
        <p class="text-sm text-muted">Delivery postcode {$mp_order.postcode|escape:'html':'UTF-8'}{if $mp_order.payment} · {$mp_order.payment|escape:'html':'UTF-8'}{/if}</p>
      </div>
      <div class="mt-6 grid grid-cols-1 gap-3 sm:grid-cols-3">
        <div class="rounded-2xl border border-line bg-white p-4"><p class="text-xs uppercase tracking-wide text-muted">Products</p><p class="mt-1 text-2xl font-semibold">{$mp_order.products|escape:'html':'UTF-8'}</p></div>
        <div class="rounded-2xl border border-line bg-white p-4"><p class="text-xs uppercase tracking-wide text-muted">Shipping</p><p class="mt-1 text-2xl font-semibold">{$mp_order.shipping|escape:'html':'UTF-8'}</p></div>
        <div class="rounded-2xl border border-accent/20 bg-[#f4f2fb] p-4"><p class="text-xs uppercase tracking-wide text-accent">Total</p><p class="mt-1 text-2xl font-semibold text-ink">{$mp_order.total|escape:'html':'UTF-8'}</p></div>
      </div>
      {if $mp_shop_error}<p class="mt-4 rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-800">The marketplace order is saved. The shop order record failed: {$mp_shop_error|escape:'html':'UTF-8'}</p>{/if}
      {if $mp_mail == '0'}<p class="mt-4 text-sm text-muted">The confirmation email could not be sent from this shop. This page is the record of the order.</p>{/if}
      {if $mp_order.note}<p class="mt-4 text-sm text-muted">{$mp_order.note|escape:'html':'UTF-8'}</p>{/if}
      <h2 class="mb-4 mt-10 text-xl font-semibold">Shipments</h2>
      <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
        {foreach from=$mp_order.vendors item=vendor}
          <article class="rounded-2xl border border-line bg-white p-5">
            <div class="flex flex-wrap items-start justify-between gap-3">
              <div>
                <p class="text-xs font-semibold uppercase tracking-wide text-muted">{$vendor.vendor|escape:'html':'UTF-8'}</p>
                <h3 class="text-lg font-semibold">#{$vendor.reference|escape:'html':'UTF-8'}</h3>
                {if $vendor.shop_reference}<p class="text-xs text-muted">Shop order {$vendor.shop_reference|escape:'html':'UTF-8'}</p>{/if}
              </div>
              <span class="rounded-full bg-emerald-50 px-2.5 py-1 text-xs font-semibold text-emerald-800">Confirmed</span>
            </div>
            <ul class="mt-4 divide-y divide-line text-sm">
              {foreach from=$vendor.lines item=line}
                <li class="flex items-baseline justify-between gap-4 py-2"><span class="min-w-0">{$line.quantity|intval} × {$line.name|escape:'html':'UTF-8'}</span><span class="shrink-0 text-muted">{$line.total|escape:'html':'UTF-8'}</span></li>
              {/foreach}
            </ul>
            <p class="mt-3 text-sm text-muted">Products {$vendor.products|escape:'html':'UTF-8'} · Shipping {$vendor.shipping|escape:'html':'UTF-8'} · Shipment {$vendor.total|escape:'html':'UTF-8'}</p>
          </article>
        {/foreach}
      </div>
    {/if}
  </div>
</div>
{/block}
