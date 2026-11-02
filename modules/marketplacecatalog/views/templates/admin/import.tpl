{assign var=mp_nav value='imports'}
{assign var=mp_title value='CSV imports'}
{include file=$smarty.const._PS_MODULE_DIR_|cat:'marketplacecore/views/templates/admin/nav.tpl'}
<div class="mp-split">
  <article class="mp-card">
    <div class="mp-card-body">
      <h2>Upload offers</h2>
      <p class="mp-note">A row updates an offer when the vendor and SKU, or the vendor and EAN, already match. Ambiguous catalog matches are rejected. Unknown products become new master products.</p>
      <p><a href="{$sample|escape:'html':'UTF-8'}">Download the sample CSV</a></p>
      {foreach from=$errors item=message}<p class="mp-pill mp-pill-warn">{$message|escape:'html':'UTF-8'}</p>{/foreach}
      <form method="post" enctype="multipart/form-data">
        <div data-mp-drop style="margin:16px 0;border:1px dashed var(--mp-line);border-radius:16px;padding:24px;text-align:center">
          <p data-mp-file class="mp-note">Drop a CSV here, or choose a file.</p>
          <input class="mp-input" type="file" name="csv" accept=".csv,text/csv" required>
        </div>
        <button class="mp-btn" type="submit" name="submitMarketplaceImport" value="1">Import offers</button>
      </form>
    </div>
  </article>
  <div>
    {if $report}
      {assign var=ambiguous value=0}
      {assign var=other value=0}
      {foreach from=$report.messages item=message}
        {if $message|strpos:'Ambiguous' !== false}{assign var=ambiguous value=$ambiguous+1}{else}{assign var=other value=$other+1}{/if}
      {/foreach}
      <div class="mp-metrics" style="grid-template-columns:repeat(3,minmax(0,1fr))">
        <article class="mp-card mp-metric"><div class="label">Rows</div><strong>{$report.total|intval}</strong></article>
        <article class="mp-card mp-metric"><div class="label">Updated</div><strong>{$report.updated|intval}</strong></article>
        <article class="mp-card mp-metric"><div class="label">Created</div><strong>{$report.created|intval}</strong></article>
        <article class="mp-card mp-metric"><div class="label">Ambiguous</div><strong>{$ambiguous|intval}</strong></article>
        <article class="mp-card mp-metric"><div class="label">Errors</div><strong>{$other|intval}</strong></article>
      </div>
      {if $report.messages}
        <article class="mp-card" style="margin-top:16px"><div class="mp-card-body"><h2>Rejected rows</h2><ul>{foreach from=$report.messages item=message}<li>{$message|escape:'html':'UTF-8'}</li>{/foreach}</ul></div></article>
      {/if}
    {/if}
    <article class="mp-card" style="margin-top:16px">
      <div class="mp-card-head"><h2>Import jobs</h2></div>
      <div class="mp-table-wrap">
        <table class="mp-table">
          <thead><tr><th>ID</th><th>Vendor</th><th>File</th><th>Status</th><th>Created</th><th>Updated</th><th>Failed</th></tr></thead>
          <tbody>
            {foreach from=$jobs item=job}
              <tr>
                <td>{$job.id_marketplace_import_job|intval}</td>
                <td>{$job.vendor_name|escape:'html':'UTF-8'}</td>
                <td>{$job.filename|escape:'html':'UTF-8'}</td>
                <td><span class="mp-pill">{$job.status|escape:'html':'UTF-8'}</span></td>
                <td>{$job.rows_created|intval}</td>
                <td>{$job.rows_updated|intval}</td>
                <td>{$job.rows_failed|intval}</td>
              </tr>
            {foreachelse}
              <tr><td colspan="7" class="mp-note">No imports yet.</td></tr>
            {/foreach}
          </tbody>
        </table>
      </div>
    </article>
  </div>
</div>
{include file=$smarty.const._PS_MODULE_DIR_|cat:'marketplacecore/views/templates/admin/shell-close.tpl'}
