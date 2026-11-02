<section class="mp-pdp mx-auto mt-6 max-w-[1440px] px-4 pb-16 text-ink sm:px-6 lg:px-8" style="font-family:Manrope,Inter,sans-serif">
  <div class="grid grid-cols-1 items-start gap-8 lg:grid-cols-[minmax(0,.9fr)_minmax(0,1.1fr)] lg:gap-12">
    <div class="overflow-hidden rounded-promo border border-line bg-[#f3f0ea]">
      {if $mp_product.image}<img class="aspect-square w-full object-contain p-8 sm:p-12" src="{$mp_product.image|escape:'html':'UTF-8'}" alt="{$mp_product.name|escape:'html':'UTF-8'}">{/if}
    </div>
    <div class="lg:pt-2">
      <div class="flex flex-wrap items-center gap-2">
        <p class="text-xs font-semibold uppercase tracking-[0.16em] text-accent">{$mp_product.brand|escape:'html':'UTF-8'}</p>
        {if $mp_product.category}<span class="rounded-full bg-[#f3f0ea] px-2.5 py-1 text-[11px] font-semibold text-muted">{$mp_product.category|escape:'html':'UTF-8'}</span>{/if}
      </div>
      <h1 class="mt-3 text-3xl font-semibold tracking-tight sm:text-4xl">{$mp_product.name|escape:'html':'UTF-8'}</h1>
      <p class="mt-2 text-sm text-muted">SKU {$mp_product.sku|escape:'html':'UTF-8'} · EAN {$mp_product.ean|escape:'html':'UTF-8'}</p>
      {if $mp_product.description}<p class="mt-4 max-w-xl text-sm leading-7 text-ink/80">{$mp_product.description|escape:'html':'UTF-8'}</p>{/if}
      {if $mp_product.offer_count > 0}
      <p class="mt-4 text-sm">{if $mp_product.available_units > 0}<span class="font-semibold text-emerald">In stock</span> <span class="text-muted">· {$mp_product.available_units|intval} units across vendors</span>{else}<span class="font-semibold text-amber-700">Currently unavailable</span>{/if}</p>
      <p class="mt-6 text-sm text-muted">From</p>
      <p class="text-4xl font-semibold tracking-tight">{$mp_product.starting|escape:'html':'UTF-8'}</p>
      <p class="mt-3 text-sm text-muted">Delivered prices below are for <span class="font-semibold text-ink">{$mp_postcode|escape:'html':'UTF-8'}</span>.</p>
      <form class="mt-6 flex flex-wrap items-center gap-2" method="post" action="{$mp_cart_url|escape:'html':'UTF-8'}">
        <input type="hidden" name="token" value="{$mp_token|escape:'html':'UTF-8'}">
        <input type="hidden" name="postcode" value="{$mp_postcode|escape:'html':'UTF-8'}">
        <input type="hidden" name="sku" value="{$mp_product.sku|escape:'html':'UTF-8'}">
        <label class="sr-only" for="mp-pdp-qty">Quantity</label>
        <input id="mp-pdp-qty" class="h-12 w-16 rounded-btn border border-line bg-white px-3 text-sm" name="quantity" type="number" min="1" value="1">
        <button class="h-12 rounded-btn bg-navy px-5 text-sm font-semibold text-white hover:bg-accent" type="submit" name="submitAdd" value="1">Add to cart</button>
      </form>
      {else}
      <p class="mt-6 max-w-xl text-sm leading-7 text-muted">This item is not sold by a marketplace vendor. Price, stock and delivery stay on the products in the catalog.</p>
      <a class="mt-6 inline-flex h-12 items-center rounded-btn bg-navy px-5 text-sm font-semibold text-white hover:bg-accent" href="{$mp_catalog_url|escape:'html':'UTF-8'}">Browse the catalog</a>
      {/if}
    </div>
  </div>
  {if $mp_product.offer_count > 0}
  <div class="mt-10 rounded-card border border-line bg-white">
    <div class="border-b border-line px-5 py-4">
      <h2 class="text-lg font-semibold">Vendor offers</h2>
      <p class="text-sm text-muted">Same product. The highlighted offer is the lowest price after shipping.</p>
    </div>
    {foreach from=$mp_product.offers item=offer}
      <article style="display:flex;align-items:flex-start;justify-content:space-between;gap:16px;padding:16px 20px;border-top:1px solid #e7e3dc;{if $offer.cheapest_delivered}background:#f4f2fb{/if}">
        <div>
          <p style="margin:0;font-weight:700">{$offer.vendor_name|escape:'html':'UTF-8'}</p>
          <p style="margin:4px 0 0;font-size:12px;color:#78716c">{$offer.stock|intval} in stock · unit {$offer.net|escape:'html':'UTF-8'} · shipping {$offer.shipping|escape:'html':'UTF-8'}</p>
        </div>
        <div style="text-align:right">
          <p style="margin:0;font-size:18px;font-weight:700">{$offer.delivered|escape:'html':'UTF-8'}</p>
          {if $offer.cheapest_delivered}<p style="margin:4px 0 0;font-size:12px;font-weight:700;color:#0f766e">Best delivered</p>{elseif $offer.cheapest_unit}<p style="margin:4px 0 0;font-size:12px;color:#78716c">Lowest unit</p>{/if}
        </div>
      </article>
    {/foreach}
  </div>
  {/if}
</section>
