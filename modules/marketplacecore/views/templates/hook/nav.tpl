<header data-mp-nav class="sticky top-0 z-40 border-b border-line bg-[#fbfaf7]/90 backdrop-blur" style="font-family:Manrope,Inter,sans-serif">
  <div class="mx-auto flex h-[76px] max-w-[1440px] items-center gap-3 px-4 sm:gap-5 sm:px-6 lg:px-8">
    <a class="flex shrink-0 items-center gap-2 text-[15px] font-bold tracking-tight text-ink" href="{$mp_home_url|escape:'html':'UTF-8'}"><i data-lucide="store" class="h-5 w-5 text-accent"></i> Prista Market</a>
    <form class="relative min-w-0 flex-1" method="get" action="{$mp_catalog_url|escape:'html':'UTF-8'}">
      <label class="sr-only" for="mp-q">Search by product, SKU, EAN or brand</label>
      <i data-lucide="search" class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted"></i>
      <input id="mp-q" class="h-11 w-full rounded-btn border border-line bg-[#f3f0ea] pl-10 pr-3 text-sm outline-none transition focus:border-accent focus:bg-white focus:ring-4 focus:ring-accent/10" type="search" name="q" placeholder="Search product, SKU, EAN or brand" value="{$mp_query|default:''|escape:'html':'UTF-8'}">
    </form>
    <button class="grid h-11 w-11 place-items-center rounded-btn border border-line bg-white lg:hidden" type="button" data-mp-menu aria-label="Open menu"><i data-lucide="menu" class="h-5 w-5"></i></button>
    <nav class="mp-nav-links absolute left-0 right-0 top-full z-40 hidden flex-col gap-1 border-b border-line bg-[#fbfaf7] px-4 py-3 shadow-card lg:static lg:flex lg:w-auto lg:flex-row lg:items-center lg:gap-1 lg:border-0 lg:bg-transparent lg:p-0 lg:shadow-none" aria-label="Marketplace">
      <a class="rounded-btn px-3 py-2 text-sm font-semibold text-ink/80 hover:bg-white hover:text-ink" href="{$mp_catalog_url|escape:'html':'UTF-8'}">Catalog</a>
      <a class="rounded-btn px-3 py-2 text-sm font-semibold text-ink/80 hover:bg-white hover:text-ink" href="{$mp_collections_url|escape:'html':'UTF-8'}">Collections</a>
      <a class="rounded-btn px-3 py-2 text-sm font-semibold text-ink/80 hover:bg-white hover:text-ink" href="{$mp_vendor_url|escape:'html':'UTF-8'}">Vendors</a>
      <a class="rounded-btn px-3 py-2 text-sm font-semibold text-ink/80 hover:bg-white hover:text-ink" href="{$mp_account_url|escape:'html':'UTF-8'}">Account</a>
      <a class="relative inline-flex h-11 items-center gap-2 rounded-btn bg-navy px-4 text-sm font-semibold text-white hover:bg-accent" href="{$mp_cart_url|escape:'html':'UTF-8'}"><i data-lucide="shopping-bag" class="h-4 w-4"></i> Cart{if $mp_cart_count > 0}<span class="inline-flex h-5 min-w-5 items-center justify-center rounded-full bg-white px-1 text-[11px] font-bold leading-none text-navy">{$mp_cart_count|intval}</span>{/if}</a>
    </nav>
  </div>
</header>
