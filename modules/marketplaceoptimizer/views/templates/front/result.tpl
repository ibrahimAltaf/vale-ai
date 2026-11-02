<div>
  {if isset($mp_result.error)}
    <p class="rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-800">{$mp_result.error|escape:'html':'UTF-8'}</p>
  {else}
    {foreach from=$mp_result.vendors item=vendor name=alloc}
      {assign var=units value=0}
      {foreach from=$vendor.lines item=line}{assign var=units value=$units+$line.quantity}{/foreach}
      <article class="mp-in mb-3 rounded-card border border-line border-l-4 border-l-accent bg-[#fbfaf7] p-4" style="animation-delay:{$smarty.foreach.alloc.index * 80}ms">
        <div class="flex items-start justify-between gap-3">
          <div>
            <p class="text-xs font-semibold uppercase tracking-wide text-muted">{$vendor.code|escape:'html':'UTF-8'}</p>
            <p class="text-base font-semibold">{$vendor.name|escape:'html':'UTF-8'}</p>
          </div>
          <p class="text-sm font-medium text-muted">{$units|intval} units allocated</p>
        </div>
        {foreach from=$vendor.lines item=line}
          <p class="mt-2 text-sm text-ink">{$line.product|escape:'html':'UTF-8'} × {$line.quantity|intval}</p>
        {/foreach}
        <div class="mt-3 flex justify-between text-sm">
          <span>Product {$vendor.products|escape:'html':'UTF-8'}</span>
          <span>Shipping {$vendor.shipping|escape:'html':'UTF-8'}</span>
        </div>
      </article>
    {/foreach}
    {if $mp_result.stock_split}<p class="mb-3 text-sm text-muted">No single vendor can cover the quantity, so the allocation is split.</p>{/if}
    <div data-mp-summary="1">
      {if $mp_result.status == 'OPTIMAL_VERIFIED'}
        <span class="inline-flex rounded-full bg-[#ecf7f4] px-2.5 py-1 text-xs font-semibold text-emerald">Optimal verified</span>
      {else}
        <span class="inline-flex rounded-full bg-amber-100 px-2.5 py-1 text-xs font-semibold text-amber-800">{$mp_result.status_label|escape:'html':'UTF-8'}</span>
        <p class="mt-2 text-sm text-muted">{$mp_result.explanation|escape:'html':'UTF-8'}</p>
      {/if}
      <p class="mt-4 text-sm text-muted">Products {$mp_result.products|escape:'html':'UTF-8'}</p>
      <p class="text-sm text-muted">Shipping {$mp_result.shipping|escape:'html':'UTF-8'}</p>
      <p class="mt-3 text-xs uppercase tracking-wide text-muted">Total</p>
      <p class="text-4xl font-semibold tracking-tight">{$mp_result.total|escape:'html':'UTF-8'}</p>
      {if $mp_result.savings}<p class="mt-2 text-sm font-semibold text-green-700">Savings {$mp_result.savings|escape:'html':'UTF-8'}</p>{/if}
      {if $mp_result.can_confirm}
        <form method="post" class="mt-4">
          <input type="hidden" name="token" value="{$mp_token|escape:'html':'UTF-8'}">
          <input type="hidden" name="postcode" value="{$mp_postcode|escape:'html':'UTF-8'}">
          <button class="h-12 w-full rounded-btn bg-navy text-sm font-semibold text-white hover:bg-accent" type="submit" name="submitConfirm" value="1">Continue to payment</button>
        </form>
      {/if}
      {if $mp_result.status == 'OPTIMAL_VERIFIED'}
        <p class="mt-3 text-xs text-muted">{$mp_result.evaluated|intval} of {$mp_result.search_space|intval} feasible combinations evaluated</p>
      {/if}
    </div>
  {/if}
</div>
