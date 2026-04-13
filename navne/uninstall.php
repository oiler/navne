<?php
// uninstall.php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

require_once __DIR__ . '/vendor/autoload.php';

\Navne\Storage\SuggestionsTable::drop();
\Navne\BulkIndex\RunsRepository::drop_table();
\Navne\BulkIndex\RunItemsRepository::drop_table();
