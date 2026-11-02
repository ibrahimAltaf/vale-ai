<a class="group relative block aspect-[3/4] overflow-hidden rounded-[18px]" href="{$href|escape:'html':'UTF-8'}">
  <img class="h-full w-full object-cover transition duration-500 group-hover:scale-105" src="/modules/marketplacecore/views/img/{$image|escape:'html':'UTF-8'}" alt="{$label|escape:'html':'UTF-8'}">
  <span class="absolute inset-0 bg-gradient-to-t from-navy/75 via-navy/10 to-transparent"></span>
  <span class="absolute inset-x-0 bottom-0 flex items-center justify-between p-4 text-white">
    <span class="text-lg font-semibold">{$label|escape:'html':'UTF-8'}</span>
    <span class="grid h-9 w-9 place-items-center rounded-full bg-white/15 transition group-hover:bg-white group-hover:text-navy"><i data-lucide="arrow-up-right" class="h-4 w-4"></i></span>
  </span>
</a>
