{extends file='page.tpl'}
{block name='page_content'}
<div class="bg-canvas text-ink" style="font-family:Manrope,Inter,sans-serif">
  <div class="max-w-[1440px] mx-auto px-6 lg:px-8 py-8">
    {foreach from=$mp_errors item=error}<p class="mb-3 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700">{$error|escape:'html':'UTF-8'}</p>{/foreach}
    {if $mp_notice}<p class="mb-3 text-sm text-muted">{$mp_notice|escape:'html':'UTF-8'}</p>{/if}
    <div class="mb-6">
      <p class="text-xs font-semibold uppercase tracking-[0.16em] text-accent">Checkout</p>
      <h1 class="text-2xl font-semibold tracking-tight sm:text-[28px]">Smart Cart</h1>
    </div>
    <div class="grid grid-cols-1 gap-6 items-start xl:grid-cols-12">
      <section class="col-span-1 rounded-card border border-line bg-white xl:col-span-5">
        <div class="flex items-center justify-between border-b border-line px-5 py-4">
          <h2 class="text-lg font-semibold">Cart</h2>
          <span class="text-sm text-muted">{$mp_cart.lines|count} lines</span>
        </div>
        <div class="divide-y divide-line">
          {foreach $mp_cart.lines as $line}
            <article class="grid grid-cols-[72px_1fr] gap-4 px-5 py-4">
              {if $line.image}<img class="h-[72px] w-[72px] rounded-lg object-cover" src="{$line.image|escape:'html':'UTF-8'}" alt="">{/if}
              <div>
                <p class="text-xs font-medium uppercase tracking-wide text-muted">{$line.brand|escape:'html':'UTF-8'}</p>
                <p class="font-semibold">{$line.name|escape:'html':'UTF-8'}</p>
                <p class="text-xs text-muted">{$line.sku|escape:'html':'UTF-8'} · from {$line.from|escape:'html':'UTF-8'}</p>
                <form method="post" class="mt-2 flex items-center gap-2">
                  <input type="hidden" name="token" value="{$mp_token|escape:'html':'UTF-8'}">
                  <input type="hidden" name="postcode" value="{$mp_postcode|escape:'html':'UTF-8'}">
                  <input type="hidden" name="id_product" value="{$line.id_product|intval}">
                  <div class="inline-flex items-center overflow-hidden rounded-lg border border-line bg-white">
                    <button class="px-2.5 py-1 text-muted hover:bg-canvas" type="button" data-mp-step="-1" aria-label="Decrease quantity">−</button>
                    <input class="w-12 border-0 border-x border-line py-1 text-center text-sm" name="quantity" type="number" min="1" value="{$line.quantity|intval}">
                    <button class="px-2.5 py-1 text-muted hover:bg-canvas" type="button" data-mp-step="1" aria-label="Increase quantity">+</button>
                  </div>
                  <button class="rounded-lg border border-line px-2 py-1 text-sm" type="submit" name="submitQty" value="1">Update</button>
                  <button class="rounded-lg p-1 text-muted hover:text-red-700" type="submit" name="submitRemove" value="1" aria-label="Remove"><i data-lucide="trash-2" class="h-4 w-4"></i></button>
                </form>
              </div>
            </article>
          {foreachelse}
            <p class="px-5 py-8 text-sm text-muted">Your cart is empty. Add a product below.</p>
          {/foreach}
        </div>
        <details class="border-t border-line px-5 py-3">
          <summary class="cursor-pointer text-sm text-muted">Saved baskets</summary>
          <form method="post" class="mt-3 flex flex-wrap items-center gap-2">
            <input type="hidden" name="token" value="{$mp_token|escape:'html':'UTF-8'}">
            <input type="hidden" name="postcode" value="{$mp_postcode|escape:'html':'UTF-8'}">
            <label class="sr-only" for="mp-scenario">Saved baskets</label>
            <select id="mp-scenario" name="scenario" class="rounded-lg border border-line bg-white px-3 py-2 text-sm">
              {foreach from=$mp_scenarios key=key item=label}
                <option value="{$key|escape:'html':'UTF-8'}">{$label|escape:'html':'UTF-8'}</option>
              {/foreach}
            </select>
            <button class="rounded-lg border border-line px-3 py-2 text-sm font-semibold text-ink hover:bg-canvas" type="submit" name="submitPreset" value="1">Load</button>
          </form>
        </details>
        <form method="post" class="flex flex-wrap items-center gap-2 border-t border-line px-5 py-4">
          <input type="hidden" name="token" value="{$mp_token|escape:'html':'UTF-8'}">
          <input type="hidden" name="postcode" value="{$mp_postcode|escape:'html':'UTF-8'}">
          <select class="rounded-lg border border-line px-2 py-2 text-sm" name="sku">{foreach $mp_products as $product}<option value="{$product.sku|escape:'html':'UTF-8'}">{$product.name|escape:'html':'UTF-8'}</option>{/foreach}</select>
          <input class="w-16 rounded-lg border border-line px-2 py-2 text-sm" name="quantity" type="number" min="1" value="1">
          <button class="rounded-lg bg-navy hover:bg-accent px-3 py-2 text-sm font-semibold text-white" type="submit" name="submitAdd" value="1">Add</button>
        </form>
      </section>

      <section class="col-span-1 rounded-card border border-line bg-white xl:col-span-4" id="mp-allocation">
        <div class="flex items-center gap-2 border-b border-line px-5 py-4">
          <i data-lucide="arrow-right" class="h-4 w-4 text-accent"></i>
          <h2 class="text-lg font-semibold">Vendor allocation</h2>
        </div>
        <div class="px-5 py-4" id="mp-result">{if $mp_result_html}{$mp_result_html nofilter}{else}<p class="text-sm text-muted">Optimize the cart to see which vendor ships each unit.</p>{/if}</div>
      </section>

      <aside class="col-span-1 xl:col-span-3 xl:sticky xl:top-4">
        <div class="rounded-card border border-line bg-white shadow-card max-xl:sticky max-xl:bottom-3 max-xl:z-20">
          <div class="border-b border-line px-5 py-4">
            <h2 class="text-lg font-semibold">Smart Cart result</h2>
          </div>
          <form method="post" class="flex gap-2 px-5 pt-4">
            <input type="hidden" name="token" value="{$mp_token|escape:'html':'UTF-8'}">
            <input class="w-full rounded-lg border border-line px-3 py-2 text-sm" name="postcode" value="{$mp_postcode|escape:'html':'UTF-8'}" aria-label="Postcode">
            <button class="rounded-lg border border-line px-3 py-2 text-sm" type="submit" name="submitPostcode" value="1">Apply</button>
          </form>
          <div class="px-5 py-4" id="mp-summary"><p class="text-sm text-muted">Totals appear after optimization.</p></div>
          <form method="post" id="mp-optimize" class="px-5 pb-5">
            <input type="hidden" name="token" value="{$mp_token|escape:'html':'UTF-8'}">
            <input type="hidden" name="postcode" value="{$mp_postcode|escape:'html':'UTF-8'}">
            <button class="w-full rounded-lg bg-navy hover:bg-accent px-4 py-3 text-base font-semibold text-white" type="submit" name="submitOptimize" value="1" data-mp-optimize="1">Optimize Cart</button>
          </form>
        </div>
      </aside>
    </div>
    <div class="mt-8 grid grid-cols-1 gap-4 border-t border-line pt-6 text-sm text-muted md:grid-cols-3">
      <p><span class="font-semibold text-ink">Delivered total.</span> Each offer includes that vendor’s shipping for your postcode.</p>
      <p><span class="font-semibold text-ink">Split only when it pays.</span> Quantity is divided across vendors when one of them cannot cover it, or when the split is cheaper.</p>
      <p><span class="font-semibold text-ink">Stock is held.</span> Confirming the allocation reserves the units with each vendor.</p>
    </div>
  </div>
</div>
<script src="/modules/marketplaceoptimizer/views/js/cart.js?v=6"></script>
{/block}
