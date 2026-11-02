{extends file='page.tpl'}
{block name='page_content'}
<div class="bg-canvas text-ink" style="font-family:Inter,Manrope,sans-serif">
  <div class="mx-auto max-w-[1100px] px-6 py-10 lg:px-8">
    <p class="text-xs font-semibold uppercase tracking-[0.16em] text-accent">Checkout</p>
    <h1 class="mt-2 text-3xl font-semibold tracking-tight">Payment</h1>
    <p class="mt-2 max-w-2xl text-sm text-muted">The allocation is priced again at payment. Card details are checked here and only the last four digits are kept. Bank transfer records the order as awaiting payment.</p>
    {foreach from=$mp_errors item=error}<p class="mt-4 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700">{$error|escape:'html':'UTF-8'}</p>{/foreach}
    {if $mp_notice}<p class="mt-4 rounded-lg bg-emerald-50 px-3 py-2 text-sm text-emerald-800">{$mp_notice|escape:'html':'UTF-8'}</p>{/if}
    {if $mp_reset_link}<p class="mt-3 break-all rounded-lg border border-line bg-white px-3 py-2 text-sm"><a class="text-accent" href="{$mp_reset_link|escape:'html':'UTF-8'}">{$mp_reset_link|escape:'html':'UTF-8'}</a></p>{/if}

    <div class="mt-8 grid grid-cols-1 gap-6 lg:grid-cols-2">
      <section class="rounded-card border border-line bg-white p-5">
        <h2 class="text-lg font-semibold">This order</h2>
        {if !$mp_result}
          <p class="mt-3 text-sm text-muted">The cart is empty.</p>
          <a class="mt-4 inline-flex h-11 items-center rounded-btn bg-navy px-4 text-sm font-semibold text-white" href="{$mp_cart_url|escape:'html':'UTF-8'}">Return to Smart Cart</a>
        {elseif isset($mp_result.error)}
          <p class="mt-3 text-sm text-amber-800">{$mp_result.error|escape:'html':'UTF-8'}</p>
        {else}
          <p class="mt-2 text-sm text-muted">Delivery postcode {$mp_postcode|escape:'html':'UTF-8'}</p>
          {foreach from=$mp_result.vendors item=vendor}
            <article class="mt-4 rounded-btn border border-line p-3">
              <p class="text-sm font-semibold">{$vendor.name|escape:'html':'UTF-8'}</p>
              {foreach from=$vendor.lines item=line}
                <p class="mt-1 text-sm text-muted">{$line.product|escape:'html':'UTF-8'} × {$line.quantity|intval}</p>
              {/foreach}
              <p class="mt-2 text-sm">Products {$vendor.products|escape:'html':'UTF-8'} · Shipping {$vendor.shipping|escape:'html':'UTF-8'}</p>
            </article>
          {/foreach}
          <p class="mt-4 text-sm text-muted">Products {$mp_result.products|escape:'html':'UTF-8'} · Shipping {$mp_result.shipping|escape:'html':'UTF-8'}</p>
          <p class="mt-1 text-3xl font-semibold">{$mp_result.total|escape:'html':'UTF-8'}</p>
          {if $mp_result.status != 'OPTIMAL_VERIFIED'}<p class="mt-2 text-sm text-muted">{$mp_result.explanation|escape:'html':'UTF-8'}</p>{/if}
        {/if}
      </section>

      <section class="rounded-card border border-line bg-white p-5">
        {if !$mp_customer}
          <h2 class="text-lg font-semibold">Sign in to pay</h2>
          <form class="mt-4 space-y-3" method="post">
            <input type="hidden" name="token" value="{$mp_token|escape:'html':'UTF-8'}">
            <label class="block text-sm font-semibold">Email<input class="mt-1 h-11 w-full rounded-btn border border-line px-3 text-sm" type="email" name="email" required></label>
            <label class="block text-sm font-semibold">Password<input class="mt-1 h-11 w-full rounded-btn border border-line px-3 text-sm" type="password" name="password" required></label>
            <button class="h-11 w-full rounded-btn bg-navy text-sm font-semibold text-white" type="submit" name="submitLogin" value="1">Sign in</button>
          </form>
          <form class="mt-6 space-y-3 border-t border-line pt-4" method="post">
            <input type="hidden" name="token" value="{$mp_token|escape:'html':'UTF-8'}">
            <p class="text-sm font-semibold">Create an account</p>
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
              <label class="block text-sm font-semibold">First name<input class="mt-1 h-11 w-full rounded-btn border border-line px-3 text-sm" type="text" name="firstname" required></label>
              <label class="block text-sm font-semibold">Last name<input class="mt-1 h-11 w-full rounded-btn border border-line px-3 text-sm" type="text" name="lastname" required></label>
            </div>
            <label class="block text-sm font-semibold">Email<input class="mt-1 h-11 w-full rounded-btn border border-line px-3 text-sm" type="email" name="email" required></label>
            <label class="block text-sm font-semibold">Password<input class="mt-1 h-11 w-full rounded-btn border border-line px-3 text-sm" type="password" name="password" required></label>
            <button class="h-11 w-full rounded-btn border border-line text-sm font-semibold" type="submit" name="submitRegister" value="1">Create account</button>
          </form>
          <form class="mt-4" method="post">
            <input type="hidden" name="token" value="{$mp_token|escape:'html':'UTF-8'}">
            <label class="block text-sm font-semibold">Forgot password<input class="mt-1 h-11 w-full rounded-btn border border-line px-3 text-sm" type="email" name="email" required></label>
            <button class="mt-3 h-11 rounded-btn px-4 text-sm font-semibold text-accent" type="submit" name="submitForgot" value="1">Email a reset link</button>
          </form>
        {elseif !$mp_result || !$mp_result.can_confirm}
          <h2 class="text-lg font-semibold">Signed in as {$mp_customer.firstname|escape:'html':'UTF-8'}</h2>
          <p class="mt-2 text-sm text-muted">This cart cannot be paid until every line can be allocated.</p>
          <a class="mt-4 inline-flex text-sm font-semibold text-accent" href="{$mp_cart_url|escape:'html':'UTF-8'}">Back to Smart Cart</a>
        {else}
          <h2 class="text-lg font-semibold">Pay as {$mp_customer.firstname|escape:'html':'UTF-8'} {$mp_customer.lastname|escape:'html':'UTF-8'}</h2>
          <p class="mt-1 text-sm text-muted">{$mp_customer.email|escape:'html':'UTF-8'}</p>
          <form class="mt-5 space-y-3" method="post" autocomplete="off">
            <input type="hidden" name="token" value="{$mp_token|escape:'html':'UTF-8'}">
            <input type="hidden" name="payment_method" value="card">
            <p class="text-sm font-semibold">Card</p>
            <label class="block text-sm font-semibold">Name on card<input class="mt-1 h-11 w-full rounded-btn border border-line px-3 text-sm" type="text" name="card_holder" autocomplete="cc-name"></label>
            <label class="block text-sm font-semibold">Card number<input class="mt-1 h-11 w-full rounded-btn border border-line px-3 text-sm" type="text" name="card_number" inputmode="numeric" autocomplete="cc-number" placeholder="•••• •••• •••• ••••"></label>
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
              <label class="block text-sm font-semibold">Expiry<input class="mt-1 h-11 w-full rounded-btn border border-line px-3 text-sm" type="text" name="card_expiry" placeholder="MM/YY" autocomplete="cc-exp"></label>
              <label class="block text-sm font-semibold">Security code<input class="mt-1 h-11 w-full rounded-btn border border-line px-3 text-sm" type="password" name="card_cvc" inputmode="numeric" autocomplete="cc-csc"></label>
            </div>
            <button class="h-12 w-full rounded-btn bg-navy text-sm font-semibold text-white" type="submit" name="submitPay" value="1">Pay {$mp_result.total|escape:'html':'UTF-8'}</button>
          </form>
          <form class="mt-4 border-t border-line pt-4" method="post">
            <input type="hidden" name="token" value="{$mp_token|escape:'html':'UTF-8'}">
            <input type="hidden" name="payment_method" value="bankwire">
            <button class="h-12 w-full rounded-btn border border-line text-sm font-semibold" type="submit" name="submitPay" value="1">Place as bank transfer</button>
            <p class="mt-2 text-xs text-muted">The order is saved as awaiting payment. No card number is sent.</p>
          </form>
        {/if}
      </section>
    </div>
  </div>
</div>
{/block}
