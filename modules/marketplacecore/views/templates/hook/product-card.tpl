{assign var=low value=0}
{capture name=vlist}{foreach from=$product.offers item=offer}{$offer.vendor_name}|{if $offer.low_stock}{assign var=low value=1}{/if}{/foreach}{/capture}
<article class="group flex h-full flex-col overflow-hidden rounded-card border border-line bg-white shadow-sm transition duration-200 hover:-translate-y-1 hover:border-accent/40 hover:shadow-card" data-mp-product data-search="{$product.name|escape:'html':'UTF-8'} {$product.sku|escape:'html':'UTF-8'} {$product.ean|escape:'html':'UTF-8'} {$product.brand|escape:'html':'UTF-8'}" data-brand="{$product.brand|escape:'html':'UTF-8'}" data-category="{$product.category|escape:'html':'UTF-8'}" data-vendors="{$smarty.capture.vlist|escape:'html':'UTF-8'}" data-stock="{$product.available_units|intval}" data-low="{$low|intval}" data-price="{$product.starting_cents|intval}">
  <a class="relative block bg-[#f3f0ea]" href="{$product.url|escape:'html':'UTF-8'}">
    {if $product.image}<img class="aspect-square w-full object-contain p-6 transition duration-500 group-hover:scale-[1.04]" src="{$product.image|escape:'html':'UTF-8'}" alt="{$product.name|escape:'html':'UTF-8'}">{/if}
    <span class="absolute left-3 top-3 rounded-full bg-white/90 px-2.5 py-1 text-[11px] font-semibold text-ink shadow-sm">{if $product.available_units > 0}In stock{else}Unavailable{/if}</span>
  </a>
  <div class="flex flex-1 flex-col px-4 pb-4 pt-3">
    <p class="truncate text-[11px] font-semibold uppercase tracking-[0.14em] text-muted">{$product.brand|escape:'html':'UTF-8'}</p>
    <h3 class="mt-1 line-clamp-2 min-h-[3rem] text-[15px] font-semibold leading-6"><a class="text-ink hover:text-accent" href="{$product.url|escape:'html':'UTF-8'}">{$product.name|escape:'html':'UTF-8'}</a></h3>
    <div class="mt-auto pt-4">
      <div class="flex items-end gap-2">
        <p class="text-[1.65rem] font-semibold leading-none tracking-tight text-ink">{$product.starting|escape:'html':'UTF-8'}</p>
        {if $product.compare_at}<p class="pb-0.5 text-sm text-muted line-through">{$product.compare_at|escape:'html':'UTF-8'}</p>{/if}
      </div>
      <p class="mt-2 text-xs text-muted">{$product.offer_count|intval} vendor offers</p>
      <a class="mt-4 inline-flex h-11 w-full items-center justify-center rounded-btn bg-navy text-sm font-semibold text-white transition hover:bg-accent" href="{$product.url|escape:'html':'UTF-8'}">Compare offers</a>
    </div>
  </div>
</article>
