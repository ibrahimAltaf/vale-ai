{extends file='page.tpl'}
{block name='page_content'}
<div class="bg-canvas text-ink" style="font-family:Inter,Manrope,sans-serif">
  <div class="mx-auto max-w-md px-6 py-12">
    <p class="text-xs font-semibold uppercase tracking-[0.16em] text-accent">Vendor</p>
    <h1 class="mt-2 text-3xl font-semibold tracking-tight">Reset password</h1>
    {foreach from=$mp_errors item=error}<p class="mt-4 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700">{$error|escape:'html':'UTF-8'}</p>{/foreach}
    {if $mp_notice}<p class="mt-4 rounded-lg bg-emerald-50 px-3 py-2 text-sm text-emerald-800">{$mp_notice|escape:'html':'UTF-8'}</p>{/if}
    {if $mp_reset_link}<p class="mt-3 break-all rounded-lg border border-line bg-white px-3 py-2 text-sm"><a class="text-accent" href="{$mp_reset_link|escape:'html':'UTF-8'}">{$mp_reset_link|escape:'html':'UTF-8'}</a></p>{/if}
    {if $mp_reset_token}
      <form class="mt-6 space-y-3 rounded-card border border-line bg-white p-5" method="post">
        <input type="hidden" name="token" value="{$mp_token|escape:'html':'UTF-8'}">
        <input type="hidden" name="reset_token" value="{$mp_reset_token|escape:'html':'UTF-8'}">
        <label class="block text-sm font-semibold">New password<input class="mt-1 h-11 w-full rounded-btn border border-line px-3 text-sm" type="password" name="password" required></label>
        <label class="block text-sm font-semibold">Confirm password<input class="mt-1 h-11 w-full rounded-btn border border-line px-3 text-sm" type="password" name="password_confirm" required></label>
        <button class="h-11 w-full rounded-btn bg-navy text-sm font-semibold text-white" type="submit" name="submitVendorReset" value="1">Update password</button>
      </form>
    {else}
      <form class="mt-6 space-y-3 rounded-card border border-line bg-white p-5" method="post">
        <input type="hidden" name="token" value="{$mp_token|escape:'html':'UTF-8'}">
        <label class="block text-sm font-semibold">Vendor email<input class="mt-1 h-11 w-full rounded-btn border border-line px-3 text-sm" type="email" name="email" required></label>
        <button class="h-11 w-full rounded-btn bg-navy text-sm font-semibold text-white" type="submit" name="submitVendorForgot" value="1">Send reset link</button>
      </form>
    {/if}
    <a class="mt-6 inline-flex text-sm font-semibold text-accent" href="{$mp_vendor_url|escape:'html':'UTF-8'}">Back to vendor sign in</a>
  </div>
</div>
{/block}
