{extends file='page.tpl'}
{block name='page_content'}
<div class="bg-canvas text-ink" data-mp-catalog data-query="{$mp_query|escape:'html':'UTF-8'}" style="font-family:Manrope,Inter,sans-serif">
  <div class="mx-auto max-w-[1440px] px-4 py-8 sm:px-6 lg:px-8">
    <p class="text-xs font-semibold uppercase tracking-[0.16em] text-accent">Catalog</p>
    <h1 class="mt-2 text-3xl font-semibold tracking-tight">The market, priced by who delivers it</h1>
    <p class="mt-2 max-w-2xl text-muted">Every product keeps one identity. Each vendor offer has its own price, stock and shipping.</p>
    <div class="mt-8 grid grid-cols-1 items-start gap-8 lg:grid-cols-[260px_minmax(0,1fr)]">
      <aside>
        <button class="mb-3 h-11 w-full rounded-btn border border-line bg-white text-sm font-semibold lg:hidden" type="button" data-mp-filters-toggle>Filters</button>
        <div class="hidden rounded-card border border-line bg-white p-4 lg:sticky lg:top-24 lg:block" data-mp-filters>
          <form class="mb-4 border-b border-line pb-4" method="get" action="{$link->getModuleLink('marketplacecatalog', 'catalog')|escape:'html':'UTF-8'}">
            <label class="block text-xs font-semibold uppercase tracking-wide text-muted" for="mp-postcode">Deliver to</label>
            <div class="mt-2 flex gap-2">
              <input id="mp-postcode" class="h-11 w-full rounded-btn border border-line px-3 text-sm" name="postcode" value="{$mp_postcode|escape:'html':'UTF-8'}">
              <button class="h-11 rounded-btn bg-navy px-3 text-sm font-semibold text-white" type="submit">Apply</button>
            </div>
          </form>
          <div class="space-y-3 text-sm">
            <label class="block font-medium">Search
              <input class="mt-1 h-11 w-full rounded-btn border border-line px-3" data-mp-filter="q" value="{$mp_query|escape:'html':'UTF-8'}" placeholder="Product, SKU, EAN, brand">
            </label>
            <label class="block font-medium">Category
              <select class="mt-1 h-11 w-full rounded-btn border border-line px-3" data-mp-filter="category">
                <option value="">All</option>
                {assign var=seen value=[]}
                {foreach from=$mp_products item=product}
                  {if $product.category && !isset($seen[$product.category])}
                    <option value="{$product.category|escape:'html':'UTF-8'}">{$product.category|escape:'html':'UTF-8'}</option>
                    {append var=seen value=1 index=$product.category}
                  {/if}
                {/foreach}
              </select>
            </label>
            <label class="block font-medium">Brand
              <select class="mt-1 h-11 w-full rounded-btn border border-line px-3" data-mp-filter="brand">
                <option value="">All</option>
                {assign var=brands value=[]}
                {foreach from=$mp_products item=product}
                  {if $product.brand && !isset($brands[$product.brand])}
                    <option value="{$product.brand|escape:'html':'UTF-8'}">{$product.brand|escape:'html':'UTF-8'}</option>
                    {append var=brands value=1 index=$product.brand}
                  {/if}
                {/foreach}
              </select>
            </label>
            <label class="block font-medium">Vendor
              <select class="mt-1 h-11 w-full rounded-btn border border-line px-3" data-mp-filter="vendor">
                <option value="">All</option>
                {assign var=vendors value=[]}
                {foreach from=$mp_products item=product}
                  {foreach from=$product.offers item=offer}
                    {if !isset($vendors[$offer.vendor_name])}
                      <option value="{$offer.vendor_name|escape:'html':'UTF-8'}">{$offer.vendor_name|escape:'html':'UTF-8'}</option>
                      {append var=vendors value=1 index=$offer.vendor_name}
                    {/if}
                  {/foreach}
                {/foreach}
              </select>
            </label>
            <label class="block font-medium">Availability
              <select class="mt-1 h-11 w-full rounded-btn border border-line px-3" data-mp-filter="availability">
                <option value="">All</option>
                <option value="in">In stock</option>
                <option value="low">Low stock</option>
              </select>
            </label>
            <label class="block font-medium">Price
              <select class="mt-1 h-11 w-full rounded-btn border border-line px-3" data-mp-filter="price">
                <option value="">All</option>
                <option value="under15">Under €15</option>
                <option value="mid">€15 – €30</option>
                <option value="over30">Over €30</option>
              </select>
            </label>
          </div>
        </div>
      </aside>
      <div>
        <div class="grid grid-cols-1 items-stretch gap-4 sm:grid-cols-2 md:grid-cols-3 xl:grid-cols-4">
          {foreach from=$mp_products item=product}
            {include file='module:marketplacecore/views/templates/hook/product-card.tpl' product=$product}
          {/foreach}
        </div>
        <p class="mt-8 rounded-card border border-dashed border-line bg-white px-5 py-10 text-center text-sm text-muted" data-mp-empty hidden>Nothing in the market matches those filters.</p>
      </div>
    </div>
  </div>
</div>
{/block}
