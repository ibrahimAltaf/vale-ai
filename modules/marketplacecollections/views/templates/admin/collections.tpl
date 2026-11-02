{assign var=mp_nav value='collections'}
{assign var=mp_title value='Collections'}
{include file=$smarty.const._PS_MODULE_DIR_|cat:'marketplacecore/views/templates/admin/nav.tpl'}
<p class="mp-note" style="margin-bottom:16px">A collection is on the shop floor only while it is enabled and today’s date sits inside its window.</p>
<div class="mp-split">
  {foreach from=$collections item=collection}
    <article class="mp-card">
      <div class="mp-card-body">
        <div style="display:flex;justify-content:space-between;gap:12px;align-items:center">
          <h2>{$collection.name|escape:'html':'UTF-8'}</h2>
          {if $collection.status == 'LIVE'}<span class="mp-pill mp-pill-ok">On the floor</span>
          {elseif $collection.status == 'SCHEDULED'}<span class="mp-pill mp-pill-warn">Scheduled</span>
          {else}<span class="mp-pill">Closed</span>{/if}
        </div>
        <p class="mp-note">{if $collection.starts == 'Open ended' && $collection.ends == 'Open ended'}Always available{else}{if $collection.starts != 'Open ended'}{$collection.starts|date_format:'%e %b %Y'|escape:'html':'UTF-8'}{else}Open start{/if} – {if $collection.ends != 'Open ended'}{$collection.ends|date_format:'%e %b %Y'|escape:'html':'UTF-8'}{else}Open end{/if}{/if}</p>
        <ul style="margin:12px 0 0;padding-left:18px">
          {foreach from=$collection.products item=product}<li>{$product.name|escape:'html':'UTF-8'}</li>{/foreach}
        </ul>
      </div>
    </article>
  {/foreach}
</div>
{include file=$smarty.const._PS_MODULE_DIR_|cat:'marketplacecore/views/templates/admin/shell-close.tpl'}
