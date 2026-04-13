<?php
namespace Navne\BulkIndex;

class Config {
	public static function batch_size(): int {
		return defined( "NAVNE_BULK_BATCH_SIZE" ) ? (int) NAVNE_BULK_BATCH_SIZE : 5;
	}

	public static function batch_interval(): int {
		return defined( "NAVNE_BULK_BATCH_INTERVAL" ) ? (int) NAVNE_BULK_BATCH_INTERVAL : 60;
	}

	public static function avg_cost_per_article(): float {
		return defined( "NAVNE_AVG_COST_PER_ARTICLE" ) ? (float) NAVNE_AVG_COST_PER_ARTICLE : 0.002;
	}

	public static function history_limit(): int {
		return defined( "NAVNE_BULK_HISTORY_LIMIT" ) ? (int) NAVNE_BULK_HISTORY_LIMIT : 10;
	}

	public static function max_error_len(): int {
		return defined( "NAVNE_BULK_MAX_ERROR_LEN" ) ? (int) NAVNE_BULK_MAX_ERROR_LEN : 500;
	}
}
