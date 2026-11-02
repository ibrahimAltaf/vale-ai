{extends file='page.tpl'}
{block name='page_content'}
<div class="bg-canvas text-ink" style="font-family:Manrope,Inter,sans-serif">
  <div class="mx-auto max-w-[1440px] px-5 py-10 sm:px-6 lg:px-8">
    <p class="text-xs font-semibold uppercase tracking-[0.16em] text-accent">Collections</p>
    <h1 class="mt-2 text-4xl font-semibold tracking-tight">Seasonal aisles</h1>
    <p class="mt-3 max-w-2xl text-muted">A collection is on the shop floor only while it is enabled and today’s date sits inside its window.</p>
    <div class="mt-8 grid grid-cols-1 gap-5 md:grid-cols-2">
      {foreach from=$mp_collections item=collection}
        {assign var=photo value='oats.jpg'}
        {if $collection.name|strstr:'Breakfast'}{assign var=photo value='muesli.jpg'}
        {elseif $collection.name|strstr:'Christmas'}{assign var=photo value='chocolate.jpg'}
        {elseif $collection.name|strstr:'Aperitivo'}{assign var=photo value='oil.jpg'}
        {elseif $collection.name|strstr:'Easter'}{assign var=photo value='honey.jpg'}{/if}
        <article class="overflow-hidden rounded-promo border border-line bg-white shadow-sm">
          <div class="grid grid-cols-1 sm:grid-cols-[220px_minmax(0,1fr)]">
            <img class="h-52 w-full object-cover sm:h-full" src="/modules/marketplacecore/views/img/{$photo|escape:'html':'UTF-8'}" alt="">
            <div class="p-6">
              <div class="flex items-center justify-between gap-3">
                <h2 class="text-2xl font-semibold">{$collection.name|escape:'html':'UTF-8'}</h2>
                {if $collection.status == 'LIVE'}<span class="rounded-full bg-[#ecf7f4] px-2.5 py-1 text-xs font-semibold text-emerald">On the floor</span>
                {elseif $collection.status == 'SCHEDULED'}<span class="rounded-full bg-[#fbf3e8] px-2.5 py-1 text-xs font-semibold text-amber-800">Scheduled</span>
                {else}<span class="rounded-full bg-[#f3f0ea] px-2.5 py-1 text-xs font-semibold text-muted">Closed</span>{/if}
              </div>
              <p class="mt-2 text-sm text-muted">{if $collection.starts == 'Open ended' && $collection.ends == 'Open ended'}Always available{else}{if $collection.starts != 'Open ended'}{$collection.starts|date_format:'%e %b %Y'|escape:'html':'UTF-8'}{else}Open start{/if} – {if $collection.ends != 'Open ended'}{$collection.ends|date_format:'%e %b %Y'|escape:'html':'UTF-8'}{else}Open end{/if}{/if}</p>
              <ul class="mt-4 space-y-1 text-sm">
                {foreach from=$collection.products item=product}
                  <li>{$product.name|escape:'html':'UTF-8'}</li>
                {/foreach}
              </ul>
            </div>
          </div>
        </article>
      {/foreach}
    </div>
  </div>
</div>
{/block}
