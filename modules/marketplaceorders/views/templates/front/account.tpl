{extends file='page.tpl'}
{block name='page_content'}
<div class="bg-canvas text-ink" style="font-family:Inter,Manrope,sans-serif">
  <div class="mx-auto max-w-[1100px] px-6 py-10 lg:px-8">
    <p class="text-xs font-semibold uppercase tracking-[0.16em] text-accent">Account</p>
    <h1 class="mt-2 text-3xl font-semibold tracking-tight">{if $mp_customer}{$mp_customer.firstname|escape:'html':'UTF-8'} {$mp_customer.lastname|escape:'html':'UTF-8'}{else}Sign in{/if}</h1>
    {foreach from=$mp_errors item=error}<p class="mt-4 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700">{$error|escape:'html':'UTF-8'}</p>{/foreach}
    {if $mp_notice}<p class="mt-4 rounded-lg bg-emerald-50 px-3 py-2 text-sm text-emerald-800">{$mp_notice|escape:'html':'UTF-8'}</p>{/if}
    {if $mp_reset_link}<p class="mt-3 break-all rounded-lg border border-line bg-white px-3 py-2 text-sm"><a class="text-accent" href="{$mp_reset_link|escape:'html':'UTF-8'}">{$mp_reset_link|escape:'html':'UTF-8'}</a></p>{/if}

    {if $mp_reset_token}
      <form class="mt-8 max-w-md space-y-3 rounded-card border border-line bg-white p-5" method="post">
        <input type="hidden" name="token" value="{$mp_token|escape:'html':'UTF-8'}">
        <input type="hidden" name="id_customer" value="{$mp_reset_id|intval}">
        <input type="hidden" name="reset_token" value="{$mp_reset_token|escape:'html':'UTF-8'}">
        <h2 class="text-lg font-semibold">Choose a new password</h2>
        <label class="block text-sm font-semibold">New password<input class="mt-1 h-11 w-full rounded-btn border border-line px-3 text-sm" type="password" name="password" required></label>
        <label class="block text-sm font-semibold">Confirm password<input class="mt-1 h-11 w-full rounded-btn border border-line px-3 text-sm" type="password" name="password_confirm" required></label>
        <button class="h-11 w-full rounded-btn bg-navy text-sm font-semibold text-white" type="submit" name="submitReset" value="1">Update password</button>
      </form>
    {elseif $mp_customer}
      <p class="mt-2 text-sm text-muted">{$mp_customer.email|escape:'html':'UTF-8'}</p>
      <form class="mt-4" method="post">
        <button class="h-11 rounded-btn border border-line px-4 text-sm font-semibold" type="submit" name="submitLogout" value="1">Sign out</button>
      </form>
      <h2 class="mb-4 mt-10 text-xl font-semibold">Orders</h2>
      <div class="grid grid-cols-1 gap-4">
        {foreach from=$mp_orders item=order}
          <article class="rounded-card border border-line bg-white p-5">
            <div class="flex flex-wrap items-baseline justify-between gap-3">
              <a class="text-lg font-semibold text-ink" href="{$order.url|escape:'html':'UTF-8'}">#{$order.reference|escape:'html':'UTF-8'}</a>
              <p class="text-lg font-semibold">{$order.total|escape:'html':'UTF-8'}</p>
            </div>
            <p class="mt-2 text-sm text-muted">{$order.date|escape:'html':'UTF-8'} · {$order.postcode|escape:'html':'UTF-8'} · {$order.payment|escape:'html':'UTF-8'} · {$order.status|escape:'html':'UTF-8'}</p>
          </article>
        {foreachelse}
          <p class="text-sm text-muted">No marketplace orders yet. <a class="font-semibold text-accent" href="{$mp_cart_url|escape:'html':'UTF-8'}">Open Smart Cart</a></p>
        {/foreach}
      </div>
    {else}
      <div class="mt-8 grid grid-cols-1 gap-6 lg:grid-cols-2">
        <form class="space-y-3 rounded-card border border-line bg-white p-5" method="post">
          <input type="hidden" name="token" value="{$mp_token|escape:'html':'UTF-8'}">
          <h2 class="text-lg font-semibold">Sign in</h2>
          <label class="block text-sm font-semibold">Email<input class="mt-1 h-11 w-full rounded-btn border border-line px-3 text-sm" type="email" name="email" required></label>
          <label class="block text-sm font-semibold">Password<input class="mt-1 h-11 w-full rounded-btn border border-line px-3 text-sm" type="password" name="password" required></label>
          <button class="h-11 w-full rounded-btn bg-navy text-sm font-semibold text-white" type="submit" name="submitLogin" value="1">Sign in</button>
        </form>
        <form class="space-y-3 rounded-card border border-line bg-white p-5" method="post">
          <input type="hidden" name="token" value="{$mp_token|escape:'html':'UTF-8'}">
          <h2 class="text-lg font-semibold">Create an account</h2>
          <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
            <label class="block text-sm font-semibold">First name<input class="mt-1 h-11 w-full rounded-btn border border-line px-3 text-sm" type="text" name="firstname" required></label>
            <label class="block text-sm font-semibold">Last name<input class="mt-1 h-11 w-full rounded-btn border border-line px-3 text-sm" type="text" name="lastname" required></label>
          </div>
          <label class="block text-sm font-semibold">Email<input class="mt-1 h-11 w-full rounded-btn border border-line px-3 text-sm" type="email" name="email" required></label>
          <label class="block text-sm font-semibold">Password<input class="mt-1 h-11 w-full rounded-btn border border-line px-3 text-sm" type="password" name="password" required></label>
          <button class="h-11 w-full rounded-btn border border-line text-sm font-semibold" type="submit" name="submitRegister" value="1">Create account</button>
        </form>
      </div>
      <form class="mt-6 max-w-md space-y-3" method="post">
        <input type="hidden" name="token" value="{$mp_token|escape:'html':'UTF-8'}">
        <h2 class="text-lg font-semibold">Forgot password</h2>
        <label class="block text-sm font-semibold">Email<input class="mt-1 h-11 w-full rounded-btn border border-line bg-white px-3 text-sm" type="email" name="email" required></label>
        <button class="h-11 rounded-btn bg-navy px-4 text-sm font-semibold text-white" type="submit" name="submitForgot" value="1">Send reset link</button>
      </form>
    {/if}
  </div>
</div>
{/block}
