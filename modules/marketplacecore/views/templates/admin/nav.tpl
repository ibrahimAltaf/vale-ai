<link rel="stylesheet" href="{$smarty.const._MODULE_DIR_}marketplacecore/views/css/theme.css">
<link href="https://fonts.googleapis.com/css2?family=Manrope:wght@500;600;700&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
<script src="https://unpkg.com/lucide@0.460.0/dist/umd/lucide.min.js"></script>
<script src="https://unpkg.com/react@18.3.1/umd/react.production.min.js"></script>
<script src="https://unpkg.com/react-dom@18.3.1/umd/react-dom.production.min.js"></script>
<script src="https://unpkg.com/prop-types@15.8.1/prop-types.min.js"></script>
<script src="https://unpkg.com/recharts@2.15.0/umd/Recharts.js"></script>
<script src="{$smarty.const._MODULE_DIR_}marketplacecore/views/js/charts.js?v=3"></script>
<script src="{$smarty.const._MODULE_DIR_}marketplacecore/views/js/marketplace.js"></script>
<style>
#nav-sidebar,#header,.header-toolbar,.main-header,#nav-sidebar-toggle,#header_infos,h1.page-title,.page-bar,.page-breadcrumb{ldelim}display:none!important{rdelim}
body:has(#mp-admin) #main,body:has(#mp-admin) #content,body:has(#mp-admin) #content.bootstrap{ldelim}margin:0!important;padding:0!important;max-width:none!important;width:100%!important;background:#f6f3ee{rdelim}
</style>
<div id="mp-admin" data-mp-shell>
  <aside class="mp-side">
    <a class="mp-brand" href="{$link->getAdminLink('AdminMarketplaceDashboard')|escape:'html':'UTF-8'}"><i data-lucide="store"></i><span>Prista</span></a>
    <p class="mp-kicker">Operate</p>
    <a class="mp-navlink{if $mp_nav == 'overview'} is-active{/if}" href="{$link->getAdminLink('AdminMarketplaceDashboard')|escape:'html':'UTF-8'}"><i data-lucide="layout-dashboard"></i><span>Overview</span></a>
    <a class="mp-navlink{if $mp_nav == 'orders'} is-active{/if}" href="{$link->getAdminLink('AdminMarketplaceOrders')|escape:'html':'UTF-8'}"><i data-lucide="shopping-bag"></i><span>Orders</span></a>
    <a class="mp-navlink" href="{$link->getModuleLink('marketplacecatalog','catalog')|escape:'html':'UTF-8'}" target="_blank" rel="noopener"><i data-lucide="package"></i><span>Catalog</span></a>
    <a class="mp-navlink{if $mp_nav == 'offers' || $mp_nav == 'imports'} is-active{/if}" href="{$link->getAdminLink('AdminMarketplaceImport')|escape:'html':'UTF-8'}"><i data-lucide="tag"></i><span>Offers</span></a>
    <a class="mp-navlink{if $mp_nav == 'vendors'} is-active{/if}" href="{$link->getAdminLink('AdminMarketplaceVendors')|escape:'html':'UTF-8'}"><i data-lucide="users"></i><span>Vendors</span></a>
    <p class="mp-kicker">Finance</p>
    <a class="mp-navlink" href="{$link->getAdminLink('AdminMarketplaceDashboard')|escape:'html':'UTF-8'}#commissions"><i data-lucide="percent"></i><span>Commissions</span></a>
    <a class="mp-navlink" href="{$link->getAdminLink('AdminMarketplaceDashboard')|escape:'html':'UTF-8'}#settlements"><i data-lucide="wallet"></i><span>Settlements</span></a>
    <a class="mp-navlink" href="{$link->getAdminLink('AdminMarketplaceDashboard')|escape:'html':'UTF-8'}#analytics"><i data-lucide="chart-line"></i><span>Analytics</span></a>
    <p class="mp-kicker">Platform</p>
    <a class="mp-navlink{if $mp_nav == 'collections'} is-active{/if}" href="{$link->getAdminLink('AdminMarketplaceCollections')|escape:'html':'UTF-8'}"><i data-lucide="folders"></i><span>Collections</span></a>
    <a class="mp-navlink" href="{$link->getAdminLink('AdminMarketplaceDashboard')|escape:'html':'UTF-8'}#settings"><i data-lucide="settings"></i><span>Settings</span></a>
  </aside>
  <div class="mp-main">
    <header class="mp-top">
      <button class="mp-iconbtn" type="button" data-mp-drawer aria-label="Open menu"><i data-lucide="menu"></i></button>
      <button class="mp-iconbtn" type="button" data-mp-collapse aria-label="Collapse sidebar"><i data-lucide="panel-left"></i></button>
      <div>
        <h1>{$mp_title|escape:'html':'UTF-8'}</h1>
        <p>Marketplace administration</p>
      </div>
      <div class="mp-avatar" style="margin-left:auto">M</div>
    </header>
    <div class="mp-body">
