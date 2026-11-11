<?php
$plugin = $GLOBALS['myturn_plugin'] ?? null;
get_header();
?>
<main class="myturn-page">
    <iframe class="myturn-embed" src="<?php echo esc_url($plugin ? $plugin->currentEmbedUrl() : ''); ?>" title="<?php echo esc_attr__('MyTurn catalogue', 'myturn'); ?>" height="2000"></iframe>
</main>
<?php get_footer(); ?>
