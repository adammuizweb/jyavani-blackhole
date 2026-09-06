<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';

$eventId = (string)($_GET['event_id'] ?? '');
$detail = jbh_event_detail($pdo, $eventId);
if ($detail === null) {
    adiwira_render_404();
    return;
}
$event = $detail['event'];
?>
<section class="jbh-admin">
  <header class="jbh-head"><div><a class="jbh-back" href="<?=jbh_h($jbhBase)?>">&larr; <?=jbh_h(jbh_t('Archive'))?></a><h1><?=jbh_h(jbh_t(ucfirst((string)$event['operation'])) . ' ' . jbh_t(ucfirst((string)$event['resource'])))?></h1><p class="jbh-mono"><?=jbh_h($eventId)?></p></div><span class="jbh-phase"><?=jbh_h(jbh_t(ucfirst((string)$event['phase'])))?></span></header>
  <div class="jbh-meta"><div><span><?=jbh_h(jbh_t('Occurred'))?></span><strong><?=jbh_h((string)$event['occurred_at'])?></strong></div><div><span><?=jbh_h(jbh_t('Actor'))?></span><strong><?=jbh_h($event['actor_id'] === null ? jbh_t('System') : '#' . (int)$event['actor_id'])?></strong></div><div><span><?=jbh_h(jbh_t('Source'))?></span><strong><?=jbh_h((string)$event['source'])?></strong></div><div><span><?=jbh_h(jbh_t('Bulk'))?></span><strong><?=jbh_h((int)$event['bulk'] === 1 ? jbh_t('Yes') : jbh_t('No'))?></strong></div></div>
  <div class="jbh-notice is-muted"><strong><?=jbh_h(jbh_t('Restore is not available yet.'))?></strong> <?=jbh_h(jbh_t('Core 2.3.106 does not provide a validated post-purge import API. Archived data and bytes are retained for a future safe restore workflow.'))?></div>
  <?php foreach ($detail['items'] as $item): ?>
    <article class="jbh-card"><h2><?=jbh_h(jbh_t('Item #%d', (int)$item['item_id']))?></h2><div class="jbh-json-grid"><div><h3><?=jbh_h(jbh_t('Before snapshot'))?></h3><pre><?=jbh_h(jbh_pretty_json($item['before_json']))?></pre></div><div><h3><?=jbh_h(jbh_t('After snapshot'))?></h3><pre><?=jbh_h(jbh_pretty_json($item['after_json']))?></pre></div></div>
    <?php $itemArtifacts = array_filter($detail['artifacts'], static fn(array $artifact): bool => (int)$artifact['item_id'] === (int)$item['item_id']); if ($itemArtifacts !== []): ?><h3><?=jbh_h(jbh_t('Artifacts'))?></h3><div class="jbh-artifacts"><?php foreach ($itemArtifacts as $artifact): ?><div><span class="jbh-pill"><?=jbh_h((string)$artifact['archive_status'])?></span><strong><?=jbh_h((string)$artifact['role'])?></strong><small><?=jbh_h($artifact['archive_relative_path'] ?: jbh_t('No archived bytes'))?></small><?php if ($artifact['archive_sha256']): ?><code><?=jbh_h((string)$artifact['archive_sha256'])?></code><small><?=number_format((int)$artifact['archive_size'])?> bytes</small><?php endif; ?></div><?php endforeach; ?></div><?php endif; ?>
    </article>
  <?php endforeach; ?>
  <div class="jbh-card"><h2><?=jbh_h(jbh_t('Event result'))?></h2><div class="jbh-json-grid"><div><h3><?=jbh_h(jbh_t('Result'))?></h3><pre><?=jbh_h(jbh_pretty_json($event['result_json']))?></pre></div><div><h3><?=jbh_h(jbh_t('Warnings'))?></h3><pre><?=jbh_h(jbh_pretty_json($event['warnings_json']))?></pre></div></div></div>
</section>
