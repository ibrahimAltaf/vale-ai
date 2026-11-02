<div class="mp-home bg-canvas text-ink" style="font-family:Manrope,Inter,sans-serif">
  <section class="border-b border-line bg-[radial-gradient(circle_at_top_left,#efeafd_0%,#f6f3ee_42%,#f3efe8_100%)]">
    <div class="mx-auto grid max-w-[1440px] grid-cols-1 items-center gap-10 px-5 py-12 sm:px-6 lg:grid-cols-[1.05fr_.95fr] lg:px-8 lg:py-16">
      <div class="mp-in">
        <p class="text-xs font-semibold uppercase tracking-[0.18em] text-accent">Delivered price</p>
        <h1 class="mt-4 max-w-xl text-4xl font-semibold tracking-[-0.04em] text-ink sm:text-5xl lg:text-[3.4rem] lg:leading-[1.02]">The price that matters is the one at the door.</h1>
        <p class="mt-5 max-w-lg text-base leading-7 text-muted sm:text-lg">Prista compares every vendor offer with shipping for your postcode, then keeps a split only when that checkout is actually cheaper.</p>
        <div class="mt-8 flex flex-wrap gap-3">
          <a class="inline-flex h-12 items-center rounded-btn bg-navy px-5 text-sm font-semibold text-white hover:bg-accent" href="{$mp_catalog_url|escape:'html':'UTF-8'}">Shop the catalog</a>
          <a class="inline-flex h-12 items-center rounded-btn border border-line bg-white px-5 text-sm font-semibold text-ink hover:border-accent" href="{$mp_cart_url|escape:'html':'UTF-8'}?basket=breakfast-shipping">Price a breakfast basket</a>
        </div>
        <div class="mt-8 grid max-w-lg grid-cols-1 gap-3 text-sm sm:grid-cols-3">
          <p class="rounded-card bg-white/80 px-3 py-3"><span class="block font-semibold">Checked splits</span><span class="text-muted">Only after every option is priced</span></p>
          <p class="rounded-card bg-white/80 px-3 py-3"><span class="block font-semibold">Shipping in</span><span class="text-muted">Thresholds sit inside the total</span></p>
          <p class="rounded-card bg-white/80 px-3 py-3"><span class="block font-semibold">Vendor stock</span><span class="text-muted">Each seller ships their own line</span></p>
        </div>
      </div>
      <div class="relative mp-in mp-in-2">
        <div class="overflow-hidden rounded-promo border border-white/70 bg-[#f3f0ea] shadow-card">
          <img class="aspect-[5/4] w-full object-cover" src="/modules/marketplacecore/views/img/promo-breakfast.jpg" alt="Coffee, muesli and honey arranged for breakfast">
        </div>
        <div class="absolute -left-2 bottom-6 w-[min(100%,280px)] space-y-2 sm:left-4">
          {foreach $mp_products as $product}
            {if $product.sku == 'COFFEE-1KG'}
              {foreach $product.offers as $offer}
                <div class="rounded-card border bg-white/95 px-3 py-2 shadow-card backdrop-blur {if $offer.cheapest_delivered}border-accent{else}border-white{/if}">
                  <div class="flex items-center justify-between gap-3">
                    <div>
                      <p class="text-sm font-semibold">{$offer.vendor_name|escape:'html':'UTF-8'}</p>
                      <p class="text-xs text-muted">{$offer.stock|intval} ready</p>
                    </div>
                    <p class="text-sm font-semibold">{if $offer.cheapest_delivered}Best {$offer.delivered|escape:'html':'UTF-8'}{else}{$offer.net|escape:'html':'UTF-8'}{/if}</p>
                  </div>
                </div>
              {/foreach}
            {/if}
          {/foreach}
        </div>
      </div>
    </div>
  </section>

  <section class="mx-auto max-w-[1440px] px-5 py-12 sm:px-6 lg:px-8">
    <div class="mb-5 flex items-end justify-between">
      <div>
        <p class="text-xs font-semibold uppercase tracking-[0.16em] text-accent">This week</p>
        <h2 class="mt-2 text-2xl font-semibold tracking-tight sm:text-[1.7rem]">Promotions from the floor</h2>
      </div>
    </div>
    <div class="relative" data-mp-embla data-mp-loop data-mp-autoplay>
      <div class="overflow-hidden rounded-promo" data-mp-viewport>
        <div class="flex">
          <div class="min-w-0 flex-[0_0_100%]">
            <a class="relative block min-h-[420px] sm:min-h-[500px]" href="{$mp_catalog_url|escape:'html':'UTF-8'}">
              <img class="absolute inset-0 h-full w-full object-cover" src="/modules/marketplacecore/views/img/promo-grocery.jpg" alt="">
              <span class="absolute inset-0 bg-gradient-to-r from-navy/80 via-navy/35 to-transparent"></span>
              <span class="relative flex h-full min-h-[420px] max-w-xl flex-col justify-end p-8 text-white sm:min-h-[500px] sm:p-12">
                <span class="text-3xl font-semibold tracking-tight sm:text-5xl">Grocery essentials, priced after delivery.</span>
                <span class="mt-3 max-w-md text-sm leading-6 text-white/80 sm:text-base">Pasta, rice, oil and chocolate from the vendors who can actually reach your postcode.</span>
                <span class="mt-6 inline-flex h-11 w-fit items-center rounded-btn bg-white px-4 text-sm font-semibold text-navy">Shop pantry</span>
              </span>
            </a>
          </div>
          <div class="min-w-0 flex-[0_0_100%]">
            <a class="relative block min-h-[420px] sm:min-h-[500px]" href="{$mp_cart_url|escape:'html':'UTF-8'}?basket=breakfast-shipping">
              <img class="absolute inset-0 h-full w-full object-cover" src="/modules/marketplacecore/views/img/promo-breakfast.jpg" alt="">
              <span class="absolute inset-0 bg-gradient-to-r from-navy/75 via-navy/30 to-transparent"></span>
              <span class="relative flex h-full min-h-[420px] max-w-xl flex-col justify-end p-8 text-white sm:min-h-[500px] sm:p-12">
                <span class="text-3xl font-semibold tracking-tight sm:text-5xl">Coffee and breakfast, one shipment.</span>
                <span class="mt-3 max-w-md text-sm leading-6 text-white/80 sm:text-base">The breakfast basket shows when one vendor beats two cheaper unit prices.</span>
                <span class="mt-6 inline-flex h-11 w-fit items-center rounded-btn bg-white px-4 text-sm font-semibold text-navy">Open the basket</span>
              </span>
            </a>
          </div>
          <div class="min-w-0 flex-[0_0_100%]">
            <a class="relative block min-h-[420px] sm:min-h-[500px]" href="{$mp_catalog_url|escape:'html':'UTF-8'}?q=towel">
              <img class="absolute inset-0 h-full w-full object-cover" src="/modules/marketplacecore/views/img/promo-home.jpg" alt="">
              <span class="absolute inset-0 bg-gradient-to-r from-navy/75 via-navy/25 to-transparent"></span>
              <span class="relative flex h-full min-h-[420px] max-w-xl flex-col justify-end p-8 text-white sm:min-h-[500px] sm:p-12">
                <span class="text-3xl font-semibold tracking-tight sm:text-5xl">Home and pantry, same comparison.</span>
                <span class="mt-3 max-w-md text-sm leading-6 text-white/80 sm:text-base">Towels, soap and kitchen pieces sit beside the grocery, with their own vendor offers.</span>
                <span class="mt-6 inline-flex h-11 w-fit items-center rounded-btn bg-white px-4 text-sm font-semibold text-navy">Browse home</span>
              </span>
            </a>
          </div>
          <div class="min-w-0 flex-[0_0_100%]">
            <a class="relative block min-h-[420px] sm:min-h-[500px]" href="{$mp_vendor_url|escape:'html':'UTF-8'}">
              <img class="absolute inset-0 h-full w-full object-cover" src="/modules/marketplacecore/views/img/promo-vendors.jpg" alt="">
              <span class="absolute inset-0 bg-gradient-to-r from-navy/80 via-navy/35 to-transparent"></span>
              <span class="relative flex h-full min-h-[420px] max-w-xl flex-col justify-end p-8 text-white sm:min-h-[500px] sm:p-12">
                <span class="text-3xl font-semibold tracking-tight sm:text-5xl">Independent vendors, one checkout.</span>
                <span class="mt-3 max-w-md text-sm leading-6 text-white/80 sm:text-base">Alpine Roast, Harbor Bean and Northwind Pantry keep their own price, stock and parcel.</span>
                <span class="mt-6 inline-flex h-11 w-fit items-center rounded-btn bg-white px-4 text-sm font-semibold text-navy">Vendor workspace</span>
              </span>
            </a>
          </div>
        </div>
      </div>
      <button class="absolute left-4 top-1/2 grid h-11 w-11 -translate-y-1/2 place-items-center rounded-full bg-white/90 text-ink shadow-card" type="button" data-mp-prev aria-label="Previous promotion"><i data-lucide="chevron-left" class="h-5 w-5"></i></button>
      <button class="absolute right-4 top-1/2 grid h-11 w-11 -translate-y-1/2 place-items-center rounded-full bg-white/90 text-ink shadow-card" type="button" data-mp-next aria-label="Next promotion"><i data-lucide="chevron-right" class="h-5 w-5"></i></button>
      <div class="absolute bottom-5 left-8 flex gap-2 sm:left-12">
        <button class="h-2.5 w-2.5 rounded-full bg-white/50 aria-[current=true]:w-6 aria-[current=true]:bg-white" type="button" data-mp-dot aria-label="Slide 1"></button>
        <button class="h-2.5 w-2.5 rounded-full bg-white/50 aria-[current=true]:w-6 aria-[current=true]:bg-white" type="button" data-mp-dot aria-label="Slide 2"></button>
        <button class="h-2.5 w-2.5 rounded-full bg-white/50 aria-[current=true]:w-6 aria-[current=true]:bg-white" type="button" data-mp-dot aria-label="Slide 3"></button>
        <button class="h-2.5 w-2.5 rounded-full bg-white/50 aria-[current=true]:w-6 aria-[current=true]:bg-white" type="button" data-mp-dot aria-label="Slide 4"></button>
      </div>
    </div>
  </section>

  <section class="mx-auto max-w-[1440px] px-5 pb-4 sm:px-6 lg:px-8">
    <div class="mb-5 flex items-end justify-between gap-4">
      <div>
        <p class="text-xs font-semibold uppercase tracking-[0.16em] text-accent">Aisles</p>
        <h2 class="mt-2 text-2xl font-semibold tracking-tight">Shop by category</h2>
      </div>
    </div>
    <div data-mp-embla data-mp-drag>
      <div class="overflow-hidden" data-mp-viewport>
        <div class="flex">
          <div class="min-w-0 flex-[0_0_78%] pr-4 sm:flex-[0_0_46%] lg:flex-[0_0_22%]">{include file='module:marketplacecore/views/templates/hook/category-card.tpl' href=$mp_catalog_url|cat:'?q=coffee' image='coffee.jpg' label='Coffee'}</div>
          <div class="min-w-0 flex-[0_0_78%] pr-4 sm:flex-[0_0_46%] lg:flex-[0_0_22%]">{include file='module:marketplacecore/views/templates/hook/category-card.tpl' href=$mp_collections_url image='muesli.jpg' label='Breakfast'}</div>
          <div class="min-w-0 flex-[0_0_78%] pr-4 sm:flex-[0_0_46%] lg:flex-[0_0_22%]">{include file='module:marketplacecore/views/templates/hook/category-card.tpl' href=$mp_catalog_url|cat:'?q=rice' image='rice.jpg' label='Pantry'}</div>
          <div class="min-w-0 flex-[0_0_78%] pr-4 sm:flex-[0_0_46%] lg:flex-[0_0_22%]">{include file='module:marketplacecore/views/templates/hook/category-card.tpl' href=$mp_catalog_url|cat:'?q=towel' image='towel.jpg' label='Home'}</div>
          <div class="min-w-0 flex-[0_0_78%] pr-4 sm:flex-[0_0_46%] lg:flex-[0_0_22%]">{include file='module:marketplacecore/views/templates/hook/category-card.tpl' href=$mp_catalog_url|cat:'?q=cream' image='cream.jpg' label='Personal care'}</div>
          <div class="min-w-0 flex-[0_0_78%] pr-4 sm:flex-[0_0_46%] lg:flex-[0_0_22%]">{include file='module:marketplacecore/views/templates/hook/category-card.tpl' href=$mp_catalog_url|cat:'?q=pasta' image='pasta.jpg' label='Pasta'}</div>
          <div class="min-w-0 flex-[0_0_78%] pr-4 sm:flex-[0_0_46%] lg:flex-[0_0_22%]">{include file='module:marketplacecore/views/templates/hook/category-card.tpl' href=$mp_catalog_url|cat:'?q=tea' image='tea.jpg' label='Drinks'}</div>
          <div class="min-w-0 flex-[0_0_78%] pr-4 sm:flex-[0_0_46%] lg:flex-[0_0_22%]">{include file='module:marketplacecore/views/templates/hook/category-card.tpl' href=$mp_collections_url image='honey.jpg' label='Gifts'}</div>
        </div>
      </div>
    </div>
  </section>

  <section class="mx-auto max-w-[1440px] px-5 py-14 sm:px-6 lg:px-8">
    <div class="mb-6 flex items-end justify-between">
      <div>
        <p class="text-xs font-semibold uppercase tracking-[0.16em] text-accent">Catalog</p>
        <h2 class="mt-2 text-2xl font-semibold tracking-tight">In the market now</h2>
      </div>
      <a class="text-sm font-semibold text-accent" href="{$mp_catalog_url|escape:'html':'UTF-8'}">View all</a>
    </div>
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 md:grid-cols-3 xl:grid-cols-4">
      {foreach $mp_products as $product}
        {include file='module:marketplacecore/views/templates/hook/product-card.tpl' product=$product}
      {/foreach}
    </div>
  </section>

  <section class="border-y border-line bg-white">
    <div class="mx-auto max-w-[1440px] px-5 py-14 sm:px-6 lg:px-8">
      <p class="text-xs font-semibold uppercase tracking-[0.16em] text-accent">Smart Cart</p>
      <h2 class="mt-2 max-w-2xl text-3xl font-semibold tracking-tight">A lower unit price can still be the dearer delivery.</h2>
      <div class="mt-8 grid grid-cols-1 gap-3 md:grid-cols-[1fr_auto_1fr_auto_1fr] md:items-center">
        <div class="rounded-card border border-line bg-canvas p-5"><p class="text-xs font-semibold uppercase tracking-wide text-muted">Separate vendors</p><p class="mt-3 text-4xl font-semibold tracking-tight">€55</p><p class="mt-2 text-sm text-muted">Muesli and honey from the two cheapest unit prices, plus both shipping fees.</p></div>
        <div class="hidden justify-center text-muted md:flex"><i data-lucide="arrow-right"></i></div>
        <div class="rounded-card border border-accent/30 bg-[#f4f2fb] p-5"><p class="text-xs font-semibold uppercase tracking-wide text-accent">Optimized basket</p><p class="mt-3 text-4xl font-semibold tracking-tight">€52</p><p class="mt-2 text-sm text-muted">One roaster ships both lines and clears the free-shipping threshold.</p></div>
        <div class="hidden justify-center text-muted md:flex"><i data-lucide="arrow-right"></i></div>
        <div class="rounded-card border border-emerald/20 bg-[#f3faf7] p-5"><p class="text-xs font-semibold uppercase tracking-wide text-emerald">You keep</p><p class="mt-3 text-4xl font-semibold tracking-tight text-emerald">€3</p><p class="mt-2 text-sm text-muted">The saving is the delivered total, not the shelf price.</p></div>
      </div>
      <a class="mt-8 inline-flex h-12 items-center rounded-btn bg-navy px-5 text-sm font-semibold text-white hover:bg-accent" href="{$mp_cart_url|escape:'html':'UTF-8'}?basket=breakfast-shipping">Open this basket</a>
    </div>
  </section>

  <section class="mx-auto max-w-[1440px] px-5 py-14 sm:px-6 lg:px-8">
    <div class="mb-6">
      <p class="text-xs font-semibold uppercase tracking-[0.16em] text-accent">Sellers</p>
      <h2 class="mt-2 text-2xl font-semibold tracking-tight">Vendors shipping today</h2>
    </div>
    <div class="grid grid-cols-1 gap-4 lg:grid-cols-3">
      {foreach $mp_vendors as $vendor}
        {assign var=count value=0}
        {foreach $mp_products as $product}{foreach $product.offers as $offer}{if $offer.vendor_code == $vendor.code}{assign var=count value=$count+1}{/if}{/foreach}{/foreach}
        <article class="flex flex-col rounded-card border border-line bg-white p-5 shadow-sm">
          <div class="flex items-center gap-4">
            <div class="grid h-14 w-14 place-items-center rounded-2xl bg-navy text-lg font-semibold text-white">{$vendor.name|truncate:1:''|escape:'html':'UTF-8'}</div>
            <div>
              <p class="text-lg font-semibold">{$vendor.name|escape:'html':'UTF-8'}</p>
              <p class="text-sm text-muted">{$count|intval} offers · {if $vendor.status == 'active'}<span class="text-emerald">Accepting orders</span>{else}{$vendor.status|escape:'html':'UTF-8'}{/if}</p>
            </div>
          </div>
          <p class="mt-4 text-sm leading-6 text-ink/80">{if $vendor.code == 'A'}Berlin roastery. Breakfast staples and household lines, packed for nationwide delivery.{elseif $vendor.code == 'B'}Hamburg grocer. Pasta, rice, coffee and home goods from one warehouse.{elseif $vendor.code == 'C'}Regional pantry. Delivery only where their postcode rule allows.{else}{$vendor.description|escape:'html':'UTF-8'}{/if}</p>
          <p class="mt-3 text-sm text-muted">Shipping €{math equation="x/100" x=$vendor.base_cents format="%.2f"} · free from €{math equation="x/100" x=$vendor.free_threshold_cents format="%.2f"}{if $vendor.postcode_prefixes} · postcodes {$vendor.postcode_prefixes|escape:'html':'UTF-8'}{else} · nationwide{/if}</p>
          {if $vendor.extra_shipping}<p class="mt-1 text-sm text-muted">{$vendor.extra_shipping|escape:'html':'UTF-8'}</p>{/if}
          <a class="mt-5 inline-flex h-11 items-center justify-center rounded-btn border border-line text-sm font-semibold text-ink hover:border-accent" href="{$mp_catalog_url|escape:'html':'UTF-8'}">View offers</a>
        </article>
      {/foreach}
    </div>
  </section>

  <section class="border-t border-line bg-white">
    <div class="mx-auto grid max-w-[1440px] grid-cols-1 gap-4 px-5 py-12 sm:px-6 md:grid-cols-3 lg:px-8">
      <article class="rounded-card border border-line bg-canvas p-5"><span class="grid h-11 w-11 place-items-center rounded-2xl bg-white text-accent"><i data-lucide="shield-check"></i></span><h3 class="mt-4 text-lg font-semibold">Checked, not guessed</h3><p class="mt-2 text-sm leading-6 text-muted">A result is marked optimal only after every feasible split has a price.</p></article>
      <article class="rounded-card border border-line bg-canvas p-5"><span class="grid h-11 w-11 place-items-center rounded-2xl bg-white text-accent"><i data-lucide="truck"></i></span><h3 class="mt-4 text-lg font-semibold">Shipping in the total</h3><p class="mt-2 text-sm leading-6 text-muted">Free-shipping thresholds and postcode rules sit inside the comparison.</p></article>
      <article class="rounded-card border border-line bg-canvas p-5"><span class="grid h-11 w-11 place-items-center rounded-2xl bg-white text-accent"><i data-lucide="package"></i></span><h3 class="mt-4 text-lg font-semibold">Each vendor ships their own</h3><p class="mt-2 text-sm leading-6 text-muted">Price, stock and the parcel stay with the vendor who accepted the line.</p></article>
    </div>
  </section>
</div>
