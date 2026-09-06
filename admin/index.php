<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';

$filters = [
    'resource' => in_array((string)($_GET['resource'] ?? ''), ['media', 'file'], true) ? (string)$_GET['resource'] : '',
    'operation' => in_array((string)($_GET['operation'] ?? ''), ['trash', 'restore', 'purge'], true) ? (string)$_GET['operation'] : '',
    'archive_status' => in_array((string)($_GET['archive_status'] ?? ''), ['complete', 'pending', 'not_applicable', 'missing', 'unmanaged'], true) ? (string)$_GET['archive_status'] : '',
    'event_id' => preg_match('/\A[a-f0-9]{64}\z/D', (string)($_GET['event_id'] ?? '')) === 1 ? (string)$_GET['event_id'] : '',
    'item_id' => max(0, (int)($_GET['item_id'] ?? 0)),
];
$result = jbh_event_page($pdo, $filters, max(1, (int)($_GET['p'] ?? 1)));
$pageUrl = static function (int $page) use ($jbhBase, $filters): string {
    $query = array_filter($filters, static fn(mixed $value): bool => $value !== '' && $value !== 0);
    if ($page > 1) $query['p'] = $page;
    return $jbhBase . ($query === [] ? '' : '&' . http_build_query($query));
};
?>
<section class="jbh-admin">
  <header class="jbh-head">
    <div><span><?=jbh_h(jbh_t('Lifecycle archive'))?></span><h1><?=jbh_h(jbh_t('Blackhole Archive'))?></h1><p><?=jbh_h(jbh_t('Verified Media and File snapshots captured before Core lifecycle mutations.'))?></p></div>
    <div class="jbh-stat"><strong><?=(int)$result['total']?></strong><span><?=jbh_h(jbh_t('events'))?></span></div>
  </header>
  <div class="jbh-notice"><strong><?=jbh_h(jbh_t('Fail-closed protection is active.'))?></strong> <?=jbh_h(jbh_t('Permanent purge is blocked when a required artifact cannot be archived.'))?></div>
  <form method="get" action="<?=jbh_h(rtrim((string)ADMIN_BASE_PATH, '/') . '/')?>" class="jbh-filters">
    <input type="hidden" name="page" value="admin/tools/jyavani-blackhole">
    <label><span><?=jbh_h(jbh_t('Resource'))?></span><select name="resource"><option value=""><?=jbh_h(jbh_t('All resources'))?></option><option value="media" <?=$filters['resource']==='media'?'selected':''?>><?=jbh_h(jbh_t('Media'))?></option><option value="file" <?=$filters['resource']==='file'?'selected':''?>><?=jbh_h(jbh_t('File'))?></option></select></label>
    <label><span><?=jbh_h(jbh_t('Operation'))?></span><select name="operation"><option value=""><?=jbh_h(jbh_t('All operations'))?></option><?php foreach (['trash','restore','purge'] as $operation): ?><option value="<?=$operation?>" <?=$filters['operation']===$operation?'selected':''?>><?=jbh_h(jbh_t(ucfirst($operation)))?></option><?php endforeach; ?></select></label>
    <label><span><?=jbh_h(jbh_t('Archive state'))?></span><select name="archive_status"><option value=""><?=jbh_h(jbh_t('All states'))?></option><?php foreach (['complete','pending','not_applicable','missing','unmanaged'] as $state): ?><option value="<?=$state?>" <?=$filters['archive_status']===$state?'selected':''?>><?=jbh_h(jbh_t(str_replace('_',' ',ucfirst($state))))?></option><?php endforeach; ?></select></label>
    <label><span><?=jbh_h(jbh_t('Item ID'))?></span><input type="number" min="1" name="item_id" value="<?=$filters['item_id'] > 0 ? (int)$filters['item_id'] : ''?>"></label>
    <button class="adam-button" type="submit"><?=jbh_h(jbh_t('Filter'))?></button>
  </form>
  <?php if ($result['rows'] === []): ?>
    <div class="jbh-empty"><h2><?=jbh_h(jbh_t('No archived events'))?></h2><p><?=jbh_h(jbh_t('Lifecycle events will appear after Media or File mutations.'))?></p></div>
  <?php else: ?>
    <div class="jbh-table"><table><thead><tr><th><?=jbh_h(jbh_t('Occurred'))?></th><th><?=jbh_h(jbh_t('Resource'))?></th><th><?=jbh_h(jbh_t('Operation'))?></th><th><?=jbh_h(jbh_t('Items'))?></th><th><?=jbh_h(jbh_t('Artifacts'))?></th><th><?=jbh_h(jbh_t('Actor'))?></th></tr></thead><tbody>
      <?php foreach ($result['rows'] as $row): ?><tr>
        <td><a href="<?=jbh_h($jbhBase . '/detail&event_id=' . rawurlencode((string)$row['event_id']))?>"><strong><?=jbh_h((string)$row['occurred_at'])?></strong><small><?=jbh_h(substr((string)$row['event_id'],0,12))?>...</small></a></td>
        <td><span class="jbh-pill"><?=jbh_h(jbh_t(ucfirst((string)$row['resource'])))?></span></td><td><?=jbh_h(jbh_t(ucfirst((string)$row['operation'])))?></td><td><?=(int)$row['item_count']?></td>
        <td><strong><?=(int)$row['archived_count']?></strong> <?=jbh_h(jbh_t('verified'))?><?php if ((int)$row['unavailable_count'] > 0): ?><small><?=(int)$row['unavailable_count']?> <?=jbh_h(jbh_t('unavailable'))?></small><?php endif; ?></td>
        <td><?=jbh_h($row['actor_id'] === null ? jbh_t('System') : '#' . (int)$row['actor_id'])?></td>
      </tr><?php endforeach; ?>
    </tbody></table></div>
    <?php if ((int)$result['pages'] > 1): ?><nav class="jbh-pages" aria-label="<?=jbh_h(jbh_t('Archive pages'))?>"><?php if ((int)$result['page'] > 1): ?><a class="adam-button ghost" href="<?=jbh_h($pageUrl((int)$result['page']-1))?>"><?=jbh_h(jbh_t('Previous'))?></a><?php endif; ?><span><?=jbh_h(jbh_t('Page %d of %d', (int)$result['page'], (int)$result['pages']))?></span><?php if ((int)$result['page'] < (int)$result['pages']): ?><a class="adam-button ghost" href="<?=jbh_h($pageUrl((int)$result['page']+1))?>"><?=jbh_h(jbh_t('Next'))?></a><?php endif; ?></nav><?php endif; ?>
  <?php endif; ?>
</section>
