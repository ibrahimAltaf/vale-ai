{assign var=mp_nav value='vendors'}
{assign var=mp_title value='Vendors'}
{include file=$smarty.const._PS_MODULE_DIR_|cat:'marketplacecore/views/templates/admin/nav.tpl'}
{foreach from=$confirmations item=message}<p class="mp-pill mp-pill-ok" style="margin-bottom:12px">{$message|escape:'html':'UTF-8'}</p>{/foreach}
{foreach from=$errors item=message}<p class="mp-pill mp-pill-warn" style="margin-bottom:12px">{$message|escape:'html':'UTF-8'}</p>{/foreach}
<div class="mp-split">
  {foreach from=$vendors item=vendor}
    <form method="post" class="mp-card">
      <div class="mp-card-body">
        <div style="display:flex;align-items:center;gap:12px">
          <span class="mp-avatar">{$vendor.name|truncate:1:''|escape:'html':'UTF-8'}</span>
          <div>
            <h2>{$vendor.name|escape:'html':'UTF-8'}</h2>
            <p class="mp-note">{$vendor.code|escape:'html':'UTF-8'} · {$vendor.email|escape:'html':'UTF-8'}</p>
          </div>
          <span class="mp-pill {if $vendor.status == 'active'}mp-pill-ok{else}mp-pill-warn{/if}" style="margin-left:auto">{$vendor.status|escape:'html':'UTF-8'}</span>
        </div>
        <p class="mp-note">{$vendor.description|escape:'html':'UTF-8'}</p>
        <p class="mp-note">{$vendor.shipping|escape:'html':'UTF-8'}</p>
        <input type="hidden" name="id_marketplace_vendor" value="{$vendor.id|intval}">
        <div style="display:grid;grid-template-columns:1fr 1fr auto;gap:12px;align-items:end;margin-top:16px">
          <label class="mp-note">Status
            <select class="mp-input" name="status">
              <option value="active"{if $vendor.status == 'active'} selected{/if}>Active</option>
              <option value="suspended"{if $vendor.status == 'suspended'} selected{/if}>Suspended</option>
            </select>
          </label>
          <label class="mp-note">Commission %
            <input class="mp-input" name="commission" value="{$vendor.commission|escape:'html':'UTF-8'}">
          </label>
          <button class="mp-btn" type="submit" name="submitVendorUpdate" value="1">Save</button>
        </div>
      </div>
    </form>
  {/foreach}
</div>
{include file=$smarty.const._PS_MODULE_DIR_|cat:'marketplacecore/views/templates/admin/shell-close.tpl'}
